<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Equipment;
use App\Models\Report;
use App\Models\ReportApprovalStep;
use App\Models\Template;
use App\Models\User;
use App\Services\ProtocolAuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

// Orchestrates DOCX import: parse templates via Python, fill fields, stamp POST approvals, and regenerate documents.
class DocxImportController extends Controller
{
    public static function preApproverFieldTitles(): array
    {
        $titles = [];
        foreach (self::PRE_APPROVER_FIELDS as $roleKey => $meta) {
            $titles[$roleKey] = $meta['title'];
        }

        return $titles;
    }

    /** Seven pre-approval rows, in the same order as the requalification signature tables. */
    private const PRE_APPROVER_FIELDS = [
        'prepared_by_engineering_name' => ['label' => 'PREPARED_ENGINEERING', 'title' => 'Prepared By — User Department (Engineering)', 'order' => 1],
        'checked_by_engineering_name'  => ['label' => 'ENGINEERING', 'title' => 'Checked By — Engineering', 'order' => 2],
        'checked_by_production_name'   => ['label' => 'PRODUCTION', 'title' => 'Checked By — User Department (Production)', 'order' => 3],
        'checked_by_validation_name'   => ['label' => 'VALIDATION', 'title' => 'Checked By — Validation', 'order' => 4],
        'checked_by_quality_name'      => ['label' => 'CHECKED_QA', 'title' => 'Checked By — Quality Assurance', 'order' => 5],
        'approved_by_user_name'        => ['label' => 'USER/VALIDATION', 'title' => 'Approved By — User / Validation', 'order' => 6],
        'approved_by_quality_name'     => ['label' => 'QUALITY ASSURANCE', 'title' => 'Approved By — Quality Assurance', 'order' => 7],
    ];
    /**
     * createFromProtocol() — Show standalone VIVA DOCX import form from All Protocol list.
     *
     * Skips PRE approval (document is already pre-approved in VIVA).
     *
     * @return \Illuminate\View\View
     */
    // Renders the VIVA protocol import page with documents eligible for POST-only workflow.
    public function createFromProtocol(Request $request)
    {
        $importSource = $this->normalizeImportSource(
            $request->old('import_source', $request->query('source'))
        );
        $this->assertImportSourcePermission($importSource);

        $documents = $this->getCompletedDocuments();
        $importSourceLabel = Report::importSourceLabel($importSource);
        $importSourceSectionTitle = Report::importSourceSectionTitle($importSource);

        return view('reports.docx_import.protocol_create', compact(
            'documents',
            'importSource',
            'importSourceLabel',
            'importSourceSectionTitle'
        ));
    }

    /**
     * storeFromProtocol() — Upload VIVA DOCX, parse fields, create report for POST workflow.
     *
     * 1. Validates DOC/DOCX upload and optional document mapping
     * 2. Calls Python /parse to detect locked vs editable fields
     * 3. Creates Report with stage=post, docx_import_status=pending
     * 4. Redirects to fill form for blank fields + POST approver selection
     *
     * @param  Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    // Accepts a pre-approved VIVA DOCX, parses it, creates a POST-stage report, and sends user to fill form.
    public function storeFromProtocol(Request $request)
    {
        @set_time_limit(300);
        $request->validate([
            'docx_file' => [
                'required',
                'file',
                'max:10240',
                function (string $attribute, $value, \Closure $fail) {
                    if (!$value instanceof \Illuminate\Http\UploadedFile) {
                        $fail('A valid file upload is required.');
                        return;
                    }
                    $extension = strtolower($value->getClientOriginalExtension());
                    if (!in_array($extension, ['doc', 'docx'], true)) {
                        $fail('The file must be a .doc or .docx document.');
                    }
                },
            ],
            'document_id'      => ['nullable', 'integer', 'exists:documents,id'],
            'version'          => ['nullable', 'string', 'max:255'],
            'facility_section' => ['nullable', 'string', 'max:255'],
            'equipment_name'   => ['required', 'string', 'max:255'],
            'equipment_id'     => ['required', 'string', 'max:255'],
            'protocol_document_no' => ['nullable', 'string', 'max:255'],
            'reference_protocol_no' => ['nullable', 'string', 'max:255'],
            'import_source'    => ['nullable', 'string', 'in:new_equipment_qualification,revalidation,requalification'],
        ]);

        $this->assertImportSourcePermission(
            $this->normalizeImportSource(
                $request->input('import_source', $request->query('source'))
            )
        );

        try {
            $file         = $request->file('docx_file');
            $fileContents = file_get_contents($file->getRealPath());
            // Python NLP service parses DOCX structure and returns field metadata plus a temp file id.
            $pythonUrl    = env('PYTHON_NLP_URL', 'http://127.0.0.1:8001');

            Log::info('File received: ' . $file->getClientOriginalName(), [
                'size_bytes' => strlen($fileContents),
                'extension'  => $file->getClientOriginalExtension(),
            ]);

            Log::info('Python /parse called', ['python_url' => $pythonUrl]);

            $response = Http::timeout(180)
                ->attach('file', $fileContents, $file->getClientOriginalName())
                ->post("{$pythonUrl}/parse");

            if (!$response->successful()) {
                Log::error('Python /parse failed', [
                    'status' => $response->status(),
                    'body'   => mb_substr($response->body(), 0, 500),
                ]);

                return redirect()->back()->with('error', 'Failed to parse DOCX file: ' . $response->body());
            }

            $result = $response->json();
            if (!is_array($result)) {
                return redirect()->back()->with('error', 'Parsing service returned an invalid response.');
            }

            $fileId = $result['file_id'] ?? null;
            $importSource = $this->normalizeImportSource(
                $request->input('import_source', $request->query('source'))
            );
            // Normalize lock flags and field types so Laravel and Python agree on editable vs prefilled cells.
            $fields = $this->normalizeImportFields($result['fields'] ?? []);
            // Source-specific rules (NEQ only) — revalidation/requalification path unchanged.
            $fields = $this->applySourceSpecificImportFieldRules($fields, $importSource);
            $this->logImportFieldLockSummary($fields);

            Log::info('Fields count: ' . count($fields), [
                'file_id' => $fileId,
                'import_source' => $importSource,
                'summary' => $result['summary'] ?? null,
            ]);

            $parseSummary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
            $hasDocumentBody = ((int) ($parseSummary['tables'] ?? 0)) > 0
                || ((int) ($parseSummary['paragraphs'] ?? 0)) > 0;
            if (!$fileId || (empty($fields) && ! $hasDocumentBody)) {
                return redirect()->back()->with('error', 'No fillable fields were detected in the uploaded DOCX.');
            }

            $this->mirrorDocxTemplateAfterParse(
                $fileId,
                $fileContents,
                $file->getClientOriginalExtension() ?: 'docx'
            );

            $template = $this->resolveDocxImportTemplate();
            $document = null;

            // Optional document link pre-fills facility and equipment from the master document record.
            if ($request->filled('document_id')) {
                $document = Document::with(['facility', 'equipment'])->find($request->document_id);
            }

            // Requalification and Revalidation wait for PRE approval before anyone can fill post execution details.
            $requiresPreApproval = in_array($importSource, ['requalification', 'revalidation'], true);
            if ($requiresPreApproval) {
                $hasAttachmentFields = collect($fields)->contains(
                    fn ($f) => is_array($f) && (($f['type'] ?? '') === 'attachment' || ($f['widget'] ?? '') === 'file')
                );
                $hasAttachmentParas = collect($fields)->contains(
                    fn ($f) => is_array($f) && str_contains(strtolower($f['label'] ?? ''), 'attachment-')
                );
                if ($hasAttachmentParas && ! $hasAttachmentFields) {
                    $destPath = storage_path('app/public/templates/docx/' . $fileId . '.docx');
                    if (is_file($destPath)) {
                        $pythonBin = base_path('python-service/venv/Scripts/python.exe');
                        if (! is_file($pythonBin)) {
                            $pythonBin = 'python';
                        }
                        $cliScript = base_path('python-service/cli.py');
                        $process = new Process([$pythonBin, $cliScript, 'parse', $destPath]);
                        $process->setTimeout(60);
                        $process->run();
                        $cliOutput = trim($process->getOutput());
                        $jsonStart = strpos($cliOutput, '{"status"');
                        if ($jsonStart !== false) {
                            $decoded = json_decode(substr($cliOutput, $jsonStart), true);
                            if (is_array($decoded) && ($decoded['status'] ?? '') === 'success' && ! empty($decoded['fields'])) {
                                $fields = $this->normalizeImportFields($decoded['fields']);
                                $fields = $this->applySourceSpecificImportFieldRules($fields, $importSource);
                            }
                        }
                    }
                }
            }

            $fields = $this->upgradeProtocolCoverFieldMetadata(
                is_array($fields) ? $fields : []
            );
            $identity = $this->extractProtocolIdentityFromFields($fields);
            if ($request->filled('protocol_document_no')) {
                $identity['document_number'] = trim((string) $request->input('protocol_document_no'));
            }
            if ($request->filled('reference_protocol_no')) {
                $identity['reference_protocol_no'] = trim((string) $request->input('reference_protocol_no'));
            }
            $fields = $this->ensureProtocolIdentityHeaderFields(
                $fields,
                $identity['document_number'],
                $identity['reference_protocol_no']
            );
            if ($requiresPreApproval) {
                $fields = $this->applyRequalificationPreApprovalUnlockRules($fields);
            }

            $reportData = $importSource !== '' ? ['import_source' => $importSource] : [];
            if ($identity['document_number'] !== '') {
                $reportData['document_number'] = $identity['document_number'];
            }
            if ($identity['reference_protocol_no'] !== '') {
                $reportData['reference_protocol_no'] = $identity['reference_protocol_no'];
            }

            $extractedFacility = $this->extractCoverTextValue($fields, 'facility_section');
            $extractedVersion  = $this->extractCoverTextValue($fields, 'version');

            $report = Report::create([
                'template_id'        => $template->id,
                'templatedata'       => [],
                'data'               => $reportData,
                'rendered_html'      => '',
                'attachments'        => [],
                'facility_section'   => $request->input('facility_section')
                    ?: ($document->facility->facility_name ?? null)
                    ?: ($extractedFacility !== '' ? $extractedFacility : null),
                'document_no'        => $document?->id,
                'version'            => $request->input('version')
                    ?: ($extractedVersion !== '' ? $extractedVersion : null),
                'equipment_name'     => $request->input('equipment_name')
                    ?: ($document->equipment->equipment_name ?? null),
                'equipment_id'       => $request->input('equipment_id')
                    ?: ($document->equipment->equipment_id ?? null),
                // Requal/reval: unchanged pre_setup. NEQ only: post_setup (fillable; post_pending blocks fill).
                'stage'              => $requiresPreApproval ? 'pre' : 'post',
                'status'             => $requiresPreApproval
                    ? 'pre_setup'
                    : (($importSource === 'new_equipment_qualification') ? 'post_setup' : 'post_pending'),
                'current_step_order' => 0,
                'created_by'         => auth()->id(),
                'docx_import_path'   => $fileId,
                'docx_import_fields' => $fields,
                'docx_import_status' => 'pending',
                'docx_filled_path'   => null,
            ]);

            $report->refresh();
            $report->assignDisplayCodeIfNeeded();
            $report->refresh();

            Log::info('Fields saved to report', [
                'report_id'        => $report->id,
                'saved_field_count'=> is_array($report->docx_import_fields)
                    ? count($report->docx_import_fields)
                    : 0,
            ]);

            // Warm preview cache with report-level defaults (equipment, cover fields) before redirect.
            try {
            $prefilledFields = $this->applyReportPrefills($report, $fields);
                $this->requestPreviewHtml($fileId, $pythonUrl, $prefilledFields, $report->id, $requiresPreApproval ? 'pre_approval' : 'edit');
            } catch (\Throwable $previewEx) {
                Log::warning('DOCX preview warming skipped: ' . $previewEx->getMessage());
            }

            ProtocolAuditLogger::recordImported($report, $file->getClientOriginalName());

            Log::info('Redirecting to fill form', [
                'report_id' => $report->id,
                'route'     => 'reports.docx-import.fill-form',
            ]);

            return redirect()
                ->route('reports.docx-import.fill-form', $report)
                ->with('status', $requiresPreApproval
                    ? 'Document imported. You can edit content, add/delete table rows, and select pre-approval users.'
                    : 'DOCX imported. Fill blank fields and submit for POST approval.');

        } catch (\Throwable $e) {
            Log::error('DOCX protocol import error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return redirect()->back()->with('error', 'Import failed: ' . $e->getMessage());
        }
    }

    /**
     * buildShowPreviewHtml() — Read-only DOCX table preview for report show / approval pages.
     *
     * @param  Report  $report
     * @return string HTML fragment or empty string when preview unavailable
     */
    /**
     * Regenerate filled DOCX with latest approval stamps before download.
     */
    // Rebuilds the downloadable DOCX so it includes all completed POST approval stamps.
    public function ensureLatestFilledDocxForDownload(Report $report): void
    {
        // Only completed imports with a stored Python file id can be regenerated.
        if (! $report->docx_import_path || $report->docx_import_status !== 'completed') {
            return;
        }

        $this->extendPythonExecutionTime();
        $report->loadMissing('approvalSteps');
        $report->refresh();

        $baselineFields = $report->docx_import_fields ?? [];
        if (! is_array($baselineFields) || $baselineFields === []) {
            return;
        }

        $stampedFields = $this->fieldsWithApprovalStamps($report);
        // PRE-APPROVAL Signature & Date must survive send-to-post / download regenerate.
        $stampedFields = $this->applyRequalificationPreApproverNames($report, $stampedFields);
        // Merge stamps without overwriting pre-approval baseline values locked at fill submit.
        $fields        = $this->mergeDocxImportFieldsPreservingBaseline($baselineFields, $stampedFields, $report->id);
        if ($fields === []) {
            return;
        }

        $attachments = $this->regenerateFilledDocxAttachment($report, $fields);
        if (isset($attachments['fields']) && is_array($attachments['fields'])) {
            $fields = $attachments['fields'];
        }

        // Always persist stamped PRE Signature & Date even when Python /fill fails —
        // otherwise blank cells stay forever on show/download until a later success.
        $update = [
            'docx_import_fields' => $fields,
            'rendered_html'      => null,
        ];
        if (is_array($attachments)) {
            $update['docx_filled_path'] = $attachments['docx_filled_path'] ?? $report->docx_filled_path;
            $update['attachments'] = $attachments['attachments'] ?? $report->attachments;
        }
        $report->update($update);
        // Force show-preview PDF rebuild so PRE Signature & Date appear immediately.
        $this->invalidateFilledPdfCache($report->fresh());
    }

    // Builds read-only HTML preview for report show and approval pages, reusing cached HTML when valid.
    public function buildShowPreviewHtml(Report $report): string
    {
        $this->extendPythonExecutionTime();

        $report->loadMissing('approvalSteps');
        $stampedFields = $this->fieldsWithApprovalStamps($report);
        $stampedFields = $this->applyRequalificationPreApproverNames($report, $stampedFields);

        $saved   = trim((string) ($report->rendered_html ?? ''));
        // Cached preview must look like a DOCX table render before we trust it.
        $savedOk = $saved !== '' && str_contains($saved, 'docx-table')
            && str_contains($saved, 'border:1px solid #000');

        if ($savedOk) {
            // Patch cached HTML with latest stamped field values instead of full regeneration.
            $saved = $this->injectDocxFieldValuesIntoHtml($saved, $stampedFields, $report);
            // Apply Word/fill TOC digits (toc_sdt_*) onto .toc-page spans — data-field is absent there.
            $saved = $this->injectTocPageNumbersIntoPreviewHtml($saved, $stampedFields);
            $saved = $this->patchReadonlyHeaderTitles($saved, $report, $stampedFields);
            $saved = $this->enrichReadonlyPreviewForShow($saved, $report);
            $saved = $this->applyAttachmentLinksToPreviewHtml($saved, $report);
            $saved = $this->formatEffectiveDateInPreviewHtml($saved, $report);

            return $saved;
        }

        // Fall back to live Python snapshot when cache is missing or stale.
        $snapshot = $this->generateReadonlyDocxSnapshot($report);
        if ($snapshot === '') {
            return '';
        }

        $enriched = $this->enrichReadonlyPreviewForShow($snapshot, $report);
        $enriched = $this->injectDocxFieldValuesIntoHtml($enriched, $stampedFields, $report);
        $enriched = $this->injectTocPageNumbersIntoPreviewHtml($enriched, $stampedFields);
        $enriched = $this->applyAttachmentLinksToPreviewHtml($enriched, $report);
        $enriched = $this->formatEffectiveDateInPreviewHtml($enriched, $report);

        // Persist freshly generated preview so later page loads avoid another Python round-trip.
        if (
            $report->docx_import_status === 'completed'
            && ($saved === '' || ! $savedOk)
            && $enriched !== ''
        ) {
            Report::whereKey($report->id)->update(['rendered_html' => $enriched]);
            $report->rendered_html = $enriched;
        }

        return $enriched;
    }

    /**
     * generateReadonlyDocxSnapshot() — Full readonly HTML (header + Python tables).
     */
    // Calls Python preview service and wraps result with protocol header for readonly display.
    private function generateReadonlyDocxSnapshot(Report $report): string
    {
        $fileId = $report->docx_import_path;
        if (! $fileId) {
            return '';
        }

        $fields = $report->docx_import_fields ?? [];
        // Completed reports show approval stamps; pending imports show prefilled cover values only.
        if ($report->docx_import_status === 'completed') {
            $report->loadMissing('approvalSteps');
            $fields = $this->fieldsWithApprovalStamps($report);
        } elseif ($fields !== []) {
            $fields = $this->applyReportPrefills($report, $fields);
        }
        $fields = $this->applyRequalificationPreApproverNames($report, is_array($fields) ? $fields : []);

        $pythonUrl = env('PYTHON_NLP_URL', 'http://127.0.0.1:8001');
        $html      = $this->requestPreviewHtml($fileId, $pythonUrl, $fields, $report->id, 'readonly');

        if (trim($html) === '') {
            return '';
        }

        return $this->wrapReadonlyPreviewWithHeader($report, $html, $fields);
    }

    /**
     * JSON endpoint — regenerate readonly preview HTML for approver view (fallback).
     */
    // AJAX endpoint when client-side preview needs a fresh readonly HTML snapshot.
    public function fetchPreviewHtml(Report $report)
    {
        $this->extendPythonExecutionTime();

        $fileId = $report->docx_import_path;
        if (!$fileId) {
            return response()->json(['html' => ''], 404);
        }

        $snapshot = $this->generateReadonlyDocxSnapshot($report);
        if (trim($snapshot) === '') {
            return response()->json(['html' => ''], 502);
        }

        $html = $this->enrichReadonlyPreviewForShow($snapshot, $report);
        $html = $this->applyAttachmentLinksToPreviewHtml($html, $report);

        // Cache regenerated preview on completed reports for faster subsequent page loads.
        if ($report->docx_import_status === 'completed' && $html !== '') {
            Report::whereKey($report->id)->update(['rendered_html' => $html]);
        }

        return response()->json(['html' => $html]);
    }

    /**
     * refreshDocxAfterApproval() — Stamp approver name/signature/date into DOCX fields and refresh preview.
     *
     * Called from ReportController@approve when a DOCX import report is approved.
     * Updates docx_import_fields cell values, regenerates filled DOCX (attachment index 99),
     * and refreshes rendered_html so the next approver sees prior stamps.
     *
     * @param  Report  $report
     * @param  string|null  $prefix  e.g. post_prepared_by, post_checked_by_engineering
     * @param  string  $decision  Approved or Rejected
     */
    // Called after each POST approval to stamp signer cells and refresh preview plus filled DOCX attachment.
    public function refreshDocxAfterApproval(Report $report, ?string $prefix, string $decision = 'Approved'): void
    {
        if ($prefix === null || $prefix === '') {
            return;
        }

        $fileId = $report->docx_import_path;
        // Skip until initial fill is complete and Python still holds the source template.
        if (! $fileId || $report->docx_import_status !== 'completed') {
            return;
        }

        $this->extendPythonExecutionTime();

        $report->loadMissing('approvalSteps');
        $report->refresh();

        $baselineFields = $report->docx_import_fields ?? [];
        if (! is_array($baselineFields) || $baselineFields === []) {
            return;
        }

        // Re-stamp every completed POST step so Signature & Date stays name + datetime
        // on all rows (Prepared / Checked / Approved), not only the latest click.
        $stampedFields = $this->fieldsWithApprovalStamps($report, null);
        // PRE-APPROVAL tables (Prepared/Checked/Approved By) need name + designation on every
        // selected row — fieldsWithApprovalStamps only covers POST workflow steps.
        $stampedFields = $this->applyRequalificationPreApproverNames($report, $stampedFields);
        // Baseline merge prevents POST stamps from overwriting user-filled pre-approval content.
        $fields        = $this->mergeDocxImportFieldsPreservingBaseline($baselineFields, $stampedFields, $report->id);

        $attachments = $this->regenerateFilledDocxAttachment($report, $fields);

        if (isset($attachments['fields']) && is_array($attachments['fields'])) {
            $fields = $attachments['fields'];
        }

        $pythonUrl    = env('PYTHON_NLP_URL', 'http://127.0.0.1:8001');
        $renderedHtml = (string) ($report->rendered_html ?? '');
        $readonlyBody = $this->requestPreviewHtml($fileId, $pythonUrl, $fields, $report->id, 'readonly');

        // Refresh rendered_html only when Python returns a valid readonly body.
        if (trim($readonlyBody) !== '') {
            $report->docx_import_fields = $fields;
            $snapshot     = $this->wrapReadonlyPreviewWithHeader($report, $readonlyBody, $fields);
            $renderedHtml = $this->enrichReadonlyPreviewForShow($snapshot, $report);
            $renderedHtml = $this->applyAttachmentLinksToPreviewHtml($renderedHtml, $report);
        }

        $update = [
            'docx_import_fields' => $fields,
            'rendered_html'      => $renderedHtml,
        ];

        if ($attachments !== null) {
            $update['attachments'] = $attachments['attachments'] ?? $attachments;
            $update['docx_filled_path'] = $attachments['docx_filled_path'] ?? $report->docx_filled_path;
        }

        $report->update($update);
        // Force show-preview PDF rebuild so stamps appear immediately after approve.
        $this->invalidateFilledPdfCache($report->fresh());
    }

    /**
     * fieldsWithApprovalStamps() — Merge completed POST approval stamps into field values for preview.
     *
     * @param  string|null  $onlyPrefix  When set (e.g. during approve()), stamp only that step — not all prior steps.
     * @return array<int, array<string, mixed>>
     */
    // Applies completed POST approval step data onto matching signature/name/date fields for preview and export.
    private function fieldsWithApprovalStamps(Report $report, ?string $onlyPrefix = null): array
    {
        $fields = $report->docx_import_fields ?? [];
        if (! is_array($fields) || $fields === []) {
            return [];
        }

        // Upgrade legacy metadata so stamp logic recognizes approval-page and signature rows.
        $fields = $this->upgradeSignatureColumnDisplayNames(
            $this->upgradeSignatureApproverRoles(
                $this->upgradeApprovalPageFieldMetadata($fields, $report->import_source ?? null),
                (string) ($report->docx_import_path ?? '')
            )
        );

        // Requal PRE tables 1–3 are often tagged post_* by upgradeSignatureApproverRoles.
        // Stamp + remrole them before blanking so send-to-post cannot wipe PRE Signature & Date.
        $fields = $this->applyRequalificationPreApproverNames($report, $fields);

        // Close unapproved Signature/Date only — Name stays as the selected user.
        $fields = $this->blankUnapprovedPostApprovalDocxFields($fields, $report);

        $data = $report->data ?? [];
        if (! is_array($data)) {
            $data = [];
        }

        // Pending steps: show the selected approver's name before they click Approve.
        $fields = $this->applyPendingSelectedApproverNames($fields, $report, $data);

        // Map each parsed cell location to its array index for fast signature-row updates.
        $indexByLocation = [];
        foreach ($fields as $i => $field) {
            if (! is_array($field)) {
                continue;
            }
            $loc = (string) ($field['location'] ?? '');
            if ($loc !== '') {
                $indexByLocation[$loc] = $i;
            }
        }

        // Workflow label on approval step maps to report.data key prefix for name/date/signature.
        $labelToPrefix = [
            'POST_PREPARED'      => 'post_prepared_by',
            'POST_ENGINEERING'   => 'post_checked_by_engineering',
            'POST_PRODUCTION'    => 'post_checked_by_production',
            'POST_CHECKED_QA'    => 'post_checked_by_quality',
            'POST_VALIDATION'    => 'post_checked_by_validation',
            'POST_APPROVED_USER' => 'post_approved_by_user',
            'POST_APPROVED_QA'   => 'post_approved_by_quality',
        ];

        foreach ($report->approvalSteps as $step) {
            if ($step->stage !== 'post') {
                continue;
            }
            if ($step->approved_at === null && $step->rejected_at === null) {
                continue;
            }

            $prefix = $labelToPrefix[$step->label] ?? null;
            if ($prefix === null) {
                continue;
            }

            if ($onlyPrefix !== null && $prefix !== $onlyPrefix) {
                continue;
            }

            $isRejectedStep = $step->rejected_at !== null && $step->approved_at === null;
            $name = $this->resolveApproverDisplayName($data, $prefix);
            $date = $this->formatSignatureDateTimeForDocx((string) ($data[$prefix . '_date'] ?? ''));
            if ($date === '' && $isRejectedStep) {
                $date = $this->formatSignatureDateTimeForDocx($step->rejected_at);
            } elseif ($date === '' && $step->approved_at !== null) {
                $date = $this->formatSignatureDateTimeForDocx($step->approved_at);
            }
            $sig  = $isRejectedStep
                ? 'Rejected'
                : ($name !== ''
                    ? $this->signatureLabelFromDisplayName($name)
                    : $this->signatureLabelFromDisplayName(trim((string) ($data[$prefix . '_signature'] ?? 'Approved'))));
            if ($name === '' && $date === '') {
                continue;
            }

            $roleKey = $prefix . '_name';
            // Department tables (Prepared/Checked/Approved By) use one "Signature & Date"
            // column. Prefer that path — never let 3-col approval-page stamping overwrite
            // a combined stamp with name-only.
            $usesCombinedSigDate = false;
            $approverLocations = [];
            foreach ($fields as $field) {
                if (! is_array($field)) {
                    continue;
                }
                if ((string) ($field['approver_role'] ?? '') !== $roleKey) {
                    continue;
                }
                $label = strtolower((string) ($field['label'] ?? ''));
                if (str_contains($label, 'signature') && str_contains($label, 'date')) {
                    $usesCombinedSigDate = true;
                }
                if ((string) ($field['type'] ?? '') === 'approver') {
                    $approverLocations[] = (string) ($field['location'] ?? '');
                }
            }
            if (! $usesCombinedSigDate) {
                foreach ($approverLocations as $approverLoc) {
                    if (! preg_match('/^(table_\d+_row_\d+)_col_\d+$/', $approverLoc, $am)) {
                    continue;
                }
                    $rowPrefix = $am[1] . '_col_';
                    foreach ($fields as $field) {
                        if (! is_array($field)) {
                            continue;
                        }
                        $loc = (string) ($field['location'] ?? '');
                        if (! str_starts_with($loc, $rowPrefix)) {
                            continue;
                        }
                        $label = strtolower((string) ($field['label'] ?? ''));
                        if (str_contains($label, 'signature') && str_contains($label, 'date')) {
                            $usesCombinedSigDate = true;
                            break 2;
                        }
                    }
                }
            }

            if ($usesCombinedSigDate || $approverLocations !== []) {
                foreach ($approverLocations as $approverLoc) {
                    if ($approverLoc === '') {
                        continue;
                    }
                    $approverField = $fields[$indexByLocation[$approverLoc] ?? -1] ?? null;
                    if (is_array($approverField) && $this->isPrefilledImportField($approverField)) {
                        continue;
                    }
                $this->applyApprovalStampToSignatureRow(
                    $fields,
                    $indexByLocation,
                        $approverLoc,
                    $name,
                    $date,
                    $sig,
                    $roleKey
                );
            }
                continue;
            }

            $this->applyApprovalPageStampToFields($fields, $roleKey, $name, $date);
        }

        return $fields;
    }

    /**
     * True when a field may be written during POST-approval stamping (not general pre-fill content).
     */
    // Determines whether POST approval stamping may write into this parsed field.
    private function isPostApprovalStampField(array $field): bool
    {
        if ($this->isPrefilledImportField($field)) {
            return false;
        }

        return $this->isPostApprovalWorkflowField($field);
    }

    /**
     * Post-approval workflow Name / Signature cells (ignores prefilled lock — used by merge).
     */
    private function isPostApprovalWorkflowField(array $field): bool
    {
        // PRE-APPROVAL Prepared/Checked/Approved rows must never be treated as POST stamps.
        if (! empty($field['is_pre_approver'])) {
            return false;
        }

        $type = (string) ($field['type'] ?? '');
        $role = trim((string) ($field['approver_role'] ?? ''));

        // Non-post roles (prepared_by_*, checked_by_*, …) belong to PRE-APPROVAL tables.
        if ($role !== '' && ! str_starts_with($role, 'post_')) {
            return false;
        }

        if (str_starts_with($type, 'approval_stamp_')) {
            return ($field['stamp_eligible'] ?? true) !== false;
        }

        if ($type === 'approver') {
            return $role === '' || str_starts_with($role, 'post_');
        }

        $label  = strtolower((string) ($field['label'] ?? ''));
        $widget = strtolower((string) ($field['widget'] ?? ''));

        // Department signature tables: Signature & Date cells are table_multicol and often
        // have no approver_role on the field itself (role lives on the paired Name cell).
        if (str_contains($label, 'signature') && str_contains($label, 'date')) {
            return ($field['stamp_eligible'] ?? true) !== false;
        }

        if ($role === '' || ! str_starts_with($role, 'post_')) {
            return false;
        }

        return str_contains($label, 'signature')
            || str_contains($label, 'date')
            || $widget === 'date';
    }

    /**
     * Fill-with-DOCX must not copy approval name, e-sign, signature, date, or time.
     * Only content values (DETAILS / Description / checks / etc.) are copied.
     * A stamp already stored on the source protocol is still left blank here.
     */
    private function isApprovalFieldExcludedFromDocxFill(array $field): bool
    {
        $type = strtolower((string) ($field['type'] ?? ''));
        if (str_starts_with($type, 'approval_stamp_') || $type === 'approver') {
            return true;
        }

        // PRE-APPROVAL Prepared/Checked/Approved Name rows.
        if (! empty($field['is_pre_approver'])) {
            return true;
        }

        // Any mapped approver cell (Name, Signature, Date) — keep blank for fresh e-sign.
        if (trim((string) ($field['approver_role'] ?? '')) !== '') {
            return true;
        }

        // "Checked By (Sign./Date)", "Done By Sign." etc. hold prior e-sign text.
        if ($this->isSignOffTextField($field)) {
            return true;
        }

        $label  = strtolower(trim((string) ($field['label'] ?? '')));
        $widget = strtolower((string) ($field['widget'] ?? ''));

        if ($label === '') {
            return false;
        }

        // Signature & Date / Sign./Date / Signature columns.
        if (str_contains($label, 'signature')) {
            return true;
        }
        if (preg_match('/\bsign\.?\b/', $label) === 1 && (
            str_contains($label, 'date')
            || str_contains($label, 'time')
            || str_contains($label, 'sign.')
            || preg_match('/\b(checked|done|prepared|approved)\s*by\b/', $label) === 1
        )) {
            return true;
        }
        if (preg_match('/\bsign\.?\s*\/\s*date\b/', $label) === 1) {
            return true;
        }

        // Approval-page short column headers (not "Name of Equipment").
        if (preg_match('/^(name(\s*(&|\/|,)\s*designation)?|designation|signature|date|time)$/', $label) === 1) {
            return true;
        }

        // Prepared / Checked / Approved By name or stamp cells.
        if (
            preg_match('/\b(prepared|checked|approved)\s*by\b/', $label) === 1
            && (
                str_contains($label, 'name')
                || str_contains($label, 'sign')
                || str_contains($label, 'date')
                || str_contains($label, 'designation')
                || str_contains($label, 'time')
            )
        ) {
            return true;
        }

        return in_array($widget, ['date', 'time', 'datetime', 'datetime-local'], true)
            && (
                str_contains($label, 'sign')
                || preg_match('/\b(prepared|checked|approved)\s*by\b/', $label) === 1
            );
    }

    /**
     * Calibration / due-date cells the user fills on this protocol.
     * Signature columns and the cover effective date are not included.
     */
    private function isDocxDateTimeEntryField(array $field): bool
    {
        if ($this->isApprovalFieldExcludedFromDocxFill($field) || $this->isEffectiveDateLikeField($field)) {
            return false;
        }

        $type = strtolower(trim((string) ($field['type'] ?? '')));
        $location = strtolower(trim((string) ($field['location'] ?? '')));
        // Left-side 2-col labels ("Validation done date") are headings, not pickers.
        if ($type === 'table_2col_label' || str_ends_with($location, '_col_0')) {
            return false;
        }

        $label = strtolower(trim((string) ($field['label'] ?? '')));
        if ($label !== '' && $this->isSignOffTextField($field)) {
            return false;
        }
        if ($label !== '' && str_contains($label, 'signature')) {
            return false;
        }

        // 4.0 Qualification History — Report No. is always text (never a date picker).
        if (preg_match('/^\s*report\s*no\.?\s*$/', $label) === 1) {
            return false;
        }
        if (preg_match('/\breport\s*no\.?\b/', $label) === 1) {
            // Merged parent "Report no and approval date": only col_2 is Approval Date.
            if (preg_match('/\bapproval\s*date\b/', $label) === 1) {
                if (preg_match('/_col_(\d+)$/', $location, $cm) === 1 && (int) $cm[1] !== 2) {
                    return false;
                }
            } else {
                return false;
            }
        }

        $widget = strtolower((string) ($field['widget'] ?? ''));
        if (in_array($widget, ['date', 'time', 'datetime', 'datetime-local'], true)) {
            return true;
        }

        if ($label === '' || strlen($label) > 60) {
            return false;
        }

        return (bool) preg_match('/\b(date|time)\b/', $label);
    }

    /**
     * Old-document dates stay off the fill form until this protocol's user picks one.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function blankUnselectedDateTimeValues(array $fields): array
    {
        foreach ($fields as $idx => $field) {
            if (! is_array($field) || ! $this->isDocxDateTimeEntryField($field)) {
                continue;
            }
            if (($field['date_selected'] ?? false) === true || ($field['date_selected'] ?? false) === 1 || ($field['date_selected'] ?? false) === '1') {
                // Requal/reval date columns: date-only calendar (no time picker).
                $fields[$idx]['widget'] = 'date';
                $normalized = $this->normalizeDateTimeInputValue((string) ($field['value'] ?? ''));
                $fields[$idx]['value'] = $normalized !== ''
                    ? substr($normalized, 0, 10)
                    : '';
                continue;
            }
            $kept = $this->normalizeDateTimeInputValue((string) ($field['value'] ?? ''));
            // A value written by the date-time picker is Y-m-d or Y-m-dTH:i.
            // Keep that after refresh. Template dates such as 13/01/17 stay blank.
            if ($kept !== '' && preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2})?$/', $kept) === 1) {
                $fields[$idx]['date_selected'] = true;
                $fields[$idx]['widget'] = 'date';
                $fields[$idx]['value'] = substr($kept, 0, 10);
                continue;
            }
            $fields[$idx]['value'] = '';
            if (strtolower((string) ($field['widget'] ?? '')) === '') {
                $fields[$idx]['widget'] = 'date';
            }
        }

        return $fields;
    }

    /**
     * "Checked by (Engineering) (Sign. /Date)" / "Done By Sign." is e-sign text, not a calendar.
     */
    private function isSignOffTextField(array $field): bool
    {
        $label = strtolower(trim((string) ($field['label'] ?? '')));
        if ($label === '') {
            return false;
        }
        $hasSign = str_contains($label, 'signature') || preg_match('/\bsign\.?\b/', $label) === 1;
        if (! $hasSign) {
            return false;
        }

        return str_contains($label, 'date')
            || str_contains($label, 'time')
            || str_contains($label, 'sign.')
            || preg_match('/\b(checked|done|prepared|approved)\s*by\b/', $label) === 1;
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function restoreSignOffTextFields(array $fields): array
    {
        foreach ($fields as $idx => $field) {
            if (! is_array($field) || ! $this->isSignOffTextField($field)) {
                continue;
            }
            $widget = strtolower((string) ($field['widget'] ?? ''));
            if (in_array($widget, ['date', 'time', 'datetime', 'datetime-local'], true)) {
                $fields[$idx]['widget'] = 'text';
            }
            unset($fields[$idx]['date_selected']);
        }

        return $fields;
    }

    /**
     * Field was already filled in the source DOCX at parse time — immutable forever.
     */
    // Prefilled or explicitly non-stamp-eligible cells must never be overwritten after import.
    private function isPrefilledImportField(array $field): bool
    {
        if (($field['prefilled'] ?? false) === true) {
            return true;
        }

        if (($field['stamp_eligible'] ?? true) === false) {
            return true;
        }

        return false;
    }

    /**
     * Template leftovers like a lone "S" in Signature & Date — not a real e-sign stamp.
     */
    private function isJunkSignaturePlaceholder(string $value): bool
    {
        $text = trim($value);
        if ($text === '') {
            return false;
        }
        if (mb_strlen($text) <= 1) {
            return true;
        }

        return mb_strlen($text) <= 2 && ! preg_match('/\d/', $text);
    }

    /**
     * Blank post-approval Name / Signature & Date cells until that step is approved.
     * Keeps workflow user picks in reports.data only — not in the DOCX preview.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function blankUnapprovedPostApprovalDocxFields(array $fields, Report $report): array
    {
        $approvedRoles = [];
        $report->loadMissing('approvalSteps');
        $labelToPrefix = [
            'POST_PREPARED'      => 'post_prepared_by',
            'POST_ENGINEERING'   => 'post_checked_by_engineering',
            'POST_PRODUCTION'    => 'post_checked_by_production',
            'POST_CHECKED_QA'    => 'post_checked_by_quality',
            'POST_VALIDATION'    => 'post_checked_by_validation',
            'POST_APPROVED_USER' => 'post_approved_by_user',
            'POST_APPROVED_QA'   => 'post_approved_by_quality',
        ];
        foreach ($report->approvalSteps as $step) {
            if ($step->stage !== 'post') {
                continue;
            }
            if ($step->approved_at === null && $step->rejected_at === null) {
                continue;
            }
            $prefix = $labelToPrefix[$step->label ?? ''] ?? null;
            if ($prefix !== null) {
                $approvedRoles[$prefix . '_name'] = true;
            }
        }

        $rowRoles = [];
        foreach ($fields as $field) {
            if (! is_array($field) || (string) ($field['type'] ?? '') !== 'approver') {
                continue;
            }
            $loc = (string) ($field['location'] ?? '');
            if (! preg_match('/^(table_\d+_row_\d+)_col_\d+$/', $loc, $m)) {
                continue;
            }
            $role = (string) ($field['approver_role'] ?? '');
            if ($role !== '') {
                $rowRoles[$m[1]] = $role;
            }
        }

        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }

            // Never blank PRE-APPROVAL Signature & Date (requal tables 1–3).
            if (! empty($field['is_pre_approver'])) {
                continue;
            }

            $value = trim((string) ($field['value'] ?? ''));
            $label = strtolower((string) ($field['label'] ?? ''));
            $isSigDate = (str_contains($label, 'signature') && str_contains($label, 'date'))
                || strtolower((string) ($field['widget'] ?? '')) === 'date';

            if ($isSigDate && $this->isJunkSignaturePlaceholder($value)) {
                $fields[$idx]['value'] = '';
                $fields[$idx]['locked'] = false;
                $fields[$idx]['prefilled'] = false;
                $fields[$idx]['stamp_eligible'] = true;
                $value = '';
            }

            if (! $this->isPostApprovalStampField($field) && (string) ($field['type'] ?? '') !== 'approver') {
                continue;
            }

            $role = (string) ($field['approver_role'] ?? '');
            $loc = (string) ($field['location'] ?? '');
            if ($role === '' && preg_match('/^(table_\d+_row_\d+)_col_\d+$/', $loc, $m)) {
                $role = (string) ($rowRoles[$m[1]] ?? '');
            }

            // Row name cell already remapped to a PRE role — keep sibling Signature/Date.
            if (
                $role !== ''
                && ! str_starts_with($role, 'post_')
            ) {
                continue;
            }
            if (
                preg_match('/^(table_\d+_row_\d+)_col_\d+$/', $loc, $m)
                && isset($rowRoles[$m[1]])
                && ! str_starts_with((string) $rowRoles[$m[1]], 'post_')
            ) {
                continue;
            }

            // Only blank POST workflow rows (keep true Word-prefilled stamps locked above).
            if ($role === '' || ! str_starts_with($role, 'post_')) {
                continue;
            }
            if ($this->isPrefilledImportField($field) && ! $this->isJunkSignaturePlaceholder($value)) {
                continue;
            }
            if (isset($approvedRoles[$role])) {
                continue;
            }

            $type = strtolower(trim((string) ($field['type'] ?? '')));
            // Name column must keep (or later show) the selected user — never blank it here.
            $isNameField = $type === 'approver'
                || $type === 'approval_stamp_name'
                || (
                    (str_contains($label, 'name') || $label === '')
                    && ! str_contains($label, 'signature')
                    && ! preg_match('/\bdate\b/', $label)
                );
            if ($isNameField) {
                $fields[$idx]['locked'] = true;
                $fields[$idx]['stamp_eligible'] = true;
                continue;
            }

            // Signature / Date stay empty until this step is approved.
            $fields[$idx]['value'] = '';
            $fields[$idx]['locked'] = true;
            $fields[$idx]['prefilled'] = false;
            $fields[$idx]['stamp_eligible'] = true;
        }

        return $fields;
    }

    /**
     * For POST steps not yet approved: put the selected user's display name into Name cells.
     * Signature / Date remain blank until Approve.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function applyPendingSelectedApproverNames(array $fields, Report $report, array $data): array
    {
        $approvedRoles = [];
        $report->loadMissing('approvalSteps');
        $labelToPrefix = [
            'POST_PREPARED'      => 'post_prepared_by',
            'POST_ENGINEERING'   => 'post_checked_by_engineering',
            'POST_PRODUCTION'    => 'post_checked_by_production',
            'POST_CHECKED_QA'    => 'post_checked_by_quality',
            'POST_VALIDATION'    => 'post_checked_by_validation',
            'POST_APPROVED_USER' => 'post_approved_by_user',
            'POST_APPROVED_QA'   => 'post_approved_by_quality',
        ];
        foreach ($report->approvalSteps as $step) {
            if ($step->stage !== 'post') {
                continue;
            }
            if ($step->approved_at === null && $step->rejected_at === null) {
                continue;
            }
            $prefix = $labelToPrefix[$step->label ?? ''] ?? null;
            if ($prefix !== null) {
                $approvedRoles[$prefix . '_name'] = true;
            }
        }

        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }

            $role = (string) ($field['approver_role'] ?? '');
            if ($role === '' || ! str_starts_with($role, 'post_')) {
                continue;
            }
            if (isset($approvedRoles[$role])) {
                continue;
            }

            $type = strtolower(trim((string) ($field['type'] ?? '')));
            $label = strtolower(trim((string) ($field['label'] ?? '')));
            $location = (string) ($field['location'] ?? '');
            // Keep department/role labels in col_0 (e.g. Head - Quality Assurance).
            if (str_ends_with($location, '_col_0')) {
                continue;
            }
            $isNameField = $type === 'approver'
                || $type === 'approval_stamp_name'
                || (
                    str_contains($label, 'name')
                    && ! str_contains($label, 'signature')
                    && ! preg_match('/\bdate\b/', $label)
                );
            if (! $isNameField) {
                continue;
            }

            $prefix = preg_replace('/_name$/', '', $role) ?? '';
            $displayName = $this->resolveApproverDisplayName($data, $prefix);
            if ($displayName === '') {
                continue;
            }

            $fields[$idx]['value'] = $displayName;
            $fields[$idx]['locked'] = true;
            $fields[$idx]['stamp_eligible'] = true;
            if (is_numeric($data[$role] ?? null)) {
                $fields[$idx]['user_id'] = (int) $data[$role];
            }
        }

        return $fields;
    }

    /**
     * Drop cached filled PDF so show preview rebuilds after approval stamps.
     */
    private function invalidateFilledPdfCache(Report $report): void
    {
        $relative = $report->docx_filled_path;
        if (! $relative) {
            return;
        }
        $docxPath = storage_path('app/public/' . ltrim($relative, '/'));
        $pdfPath = dirname($docxPath) . DIRECTORY_SEPARATOR . pathinfo($docxPath, PATHINFO_FILENAME) . '.pdf';
        if (is_file($pdfPath)) {
            @unlink($pdfPath);
        }
        foreach (['.layout_v1', '.layout_v2', '.layout_v3', '.layout_v4', '.layout_v5', '.layout_v6', '.layout_v7', '.layout_v8', '.layout_v9', '.layout_v10', '.layout_v11', '.layout_v12', '.layout_v13', '.layout_v14', '.layout_v15', '.layout_v16', '.layout_v17', '.layout_v18'] as $suffix) {
            $marker = $docxPath . $suffix;
            if (is_file($marker)) {
                @unlink($marker);
            }
        }
    }

    /**
     * Baseline value must not be replaced (locked, prefilled, or substantive existing content).
     */
    // Guards baseline merge: keep prefilled content and locked cells with existing values.
    private function shouldPreserveBaselineFieldValue(array $field, string $baselineValue): bool
    {
        // Lone template initials (e.g. "S") must never block approval stamps.
        if ($this->isJunkSignaturePlaceholder($baselineValue)) {
            return false;
        }

        if ($this->isPrefilledImportField($field)) {
            return true;
        }

        if ($this->isDocxFieldLocked($field) && trim($baselineValue) !== '') {
            return true;
        }

        return false;
    }

    /**
     * True when a signature/date cell may receive a stamp (empty and not parse-locked).
     */
    // Empty signature/date targets are eligible unless prefilled or locked with content.
    private function isSignatureStampTargetField(array $field): bool
    {
        if ($this->isPrefilledImportField($field) && ! $this->isJunkSignaturePlaceholder((string) ($field['value'] ?? ''))) {
            return false;
        }

        // POST Signature & Date must stay stampable so name-only can become name + datetime.
        if ($this->isPostApprovalWorkflowField($field)) {
            return true;
        }

        if ($this->isDocxFieldLocked($field) && trim((string) ($field['value'] ?? '')) !== '') {
            return false;
        }

        return $this->isPostApprovalStampField($field);
    }

    /**
     * After initial fill submit, lock every non-stamp field that already has a value so later
     * POST-approval processing cannot overwrite pre-approval content.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    // Locks all filled non-stamp fields after initial submit so POST approval cannot mutate them.
    private function finalizePreApprovalDocxFields(array $fields): array
    {
        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }
            if ($this->isPrefilledImportField($field)) {
                $fields[$idx]['locked'] = true;
                continue;
            }
            // Leave stamp-eligible cells unlocked so approvers can still sign later.
            if ($this->isPostApprovalStampField($field)) {
                continue;
            }
            if ($this->isDocxFieldLocked($field)) {
                continue;
            }
            if (trim((string) ($field['value'] ?? '')) !== '') {
                $fields[$idx]['locked'] = true;
            }
        }

        return $fields;
    }

    /**
     * Send-back entry: unlock stored DOCX fields and clear preview cache.
     */
    public function reopenDocxFieldsAfterSendBack(Report $report): void
    {
        if (! $report->docx_import_path || ! is_array($report->docx_import_fields)) {
            return;
        }

        $report->docx_import_fields = $this->unlockDocxFieldsForReturnedEdit(
            $report->docx_import_fields
        );
        if ($report->docx_import_status === 'completed') {
            $report->docx_import_status = 'pending';
        }

        // After pre-approval bake, added rows already live in the DOCX template.
        // Stale table_ops would be replayed on fill and create duplicate locked rows.
        $data = $report->data;
        if (is_string($data)) {
            $data = json_decode($data, true) ?? [];
        }
        if (! is_array($data)) {
            $data = [];
        }
        if (! empty($data['original_docx_path']) || ! empty($data['table_ops'])) {
            $data['table_ops'] = [];
            $report->data = $data;
        }

        $report->save();
        $this->flushDocxPreviewCache((string) $report->docx_import_path, $report->id);
    }

    /**
     * After send-back, reopen user-typed first-column cells that pre-approval
     * unlock rules keep locked (all *_col_0). Skips serials and "Label:" cells.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function unlockReturnedUserTypedCol0Cells(array $fields): array
    {
        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }
            $location = strtolower(trim((string) ($field['location'] ?? '')));
            if (! preg_match('/^table_\d+_row_[1-9]\d*_col_0$/', $location)) {
                continue;
            }
            $type = strtolower(trim((string) ($field['type'] ?? '')));
            if (in_array($type, ['table_sl_no', 'table_2col_label', 'approver'], true)) {
                continue;
            }
            $value = trim((string) ($field['value'] ?? ''));
            $label = trim((string) ($field['label'] ?? ''));
            if ($value === '' && $label === '') {
                continue;
            }
            // Keep classic left-column labels locked (Name of Equipment:, 1., etc.).
            if (preg_match('/:\s*$/', $value) || preg_match('/:\s*$/', $label)) {
                continue;
            }
            if (preg_match('/^\d+[\.\)]?$/', $value)) {
                continue;
            }
            if ($this->looksLikeNeqStructuralLabelField($field)) {
                continue;
            }

            $fields[$idx]['locked'] = false;
            $fields[$idx]['prefilled'] = false;
            $fields[$idx]['user_filled'] = true;
            $fields[$idx]['draft_editable'] = true;
        }

        return $fields;
    }

    /**
     * On returned resubmit, restore editable fill UX like a fresh import.
     *
     * First submit often marks typed cells as prefilled/locked. After send-back
     * those must reopen (yellow inputs) — do not keep them locked just because
     * prefilled=true. Python /preview also re-locks prefilled unless user_filled.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function unlockDocxFieldsForReturnedEdit(array $fields): array
    {
        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }

            $type = strtolower(trim((string) ($field['type'] ?? '')));
            $widget = strtolower(trim((string) ($field['widget'] ?? '')));
            $label = strtolower(trim((string) ($field['label'] ?? '')));
            $location = strtolower(trim((string) ($field['location'] ?? '')));

            if ($this->isTocField($field) || $type === 'toc_entry' || $type === 'table_2col_label') {
                $fields[$idx]['locked'] = true;

                continue;
            }

            if ($type === 'table_sl_no') {
                $fields[$idx]['locked'] = true;

                continue;
            }

            $isBlankPart = $type === 'paragraph_blank_part'
                || preg_match('/_blank_\d+$/', $location) === 1
                || preg_match('/\(\s*blank\s*\d+\s*\)\s*$/i', (string) ($field['label'] ?? '')) === 1;
            if ($isBlankPart) {
                $fields[$idx]['type'] = 'paragraph_blank_part';
                $fields[$idx]['widget'] = 'text';
                $fields[$idx]['locked'] = false;
                $fields[$idx]['prefilled'] = false;
                continue;
            }

            if (
                $this->isLockedSectionHeadingText($label)
                || str_contains($label, 'approval page')
                || str_contains($label, 'report approval')
            ) {
                $fields[$idx]['locked'] = true;

                continue;
            }

            // Clear prior e-sign stamps so the next approval cycle can stamp again.
            if (str_starts_with($type, 'approval_stamp')) {
                $fields[$idx]['locked'] = true;
                $fields[$idx]['value'] = '';
                $fields[$idx]['prefilled'] = false;
                $fields[$idx]['user_filled'] = false;

                continue;
            }

            if ($this->isPostApprovalStampField($field)) {
                $fields[$idx]['locked'] = false;
                $fields[$idx]['value'] = '';
                $fields[$idx]['prefilled'] = false;
                $fields[$idx]['draft_editable'] = true;
                $fields[$idx]['user_filled'] = true;

                continue;
            }

            $isLetterhead = $type === 'header_cell'
                || $type === 'protocol_cover'
                || str_starts_with($location, 'header_')
                || ($field['cover_field'] ?? '') !== ''
                || ($field['protocol_cover'] ?? false) === true;

            // Table column-header row (row 0) stays locked — same as fresh import.
            if (
                ! $isLetterhead
                && preg_match('/^table_\d+_row_0_col_/', $location)
            ) {
                $fields[$idx]['locked'] = true;

                continue;
            }

            // Numbered Sr. No. / Sl. No. from Word stay locked (type may already
            // be table_multicol after pre-approval unlock remaps).
            $isSlNoField = $type === 'table_sl_no'
                || preg_match('/^(sr\.?\s*no\.?|s\.?\s*no\.?|sl\.?\s*no\.?|#)\b/i', $label) === 1;
            if (
                ! $isLetterhead
                && $isSlNoField
                && preg_match('/^table_\d+_row_[1-9]\d*_col_/', $location) === 1
            ) {
                $serialVal = trim((string) ($field['value'] ?? ''));
                if ($serialVal !== '' && $this->looksLikeSlNoValue($serialVal)) {
                    $fields[$idx]['locked'] = true;
                    $fields[$idx]['prefilled'] = true;
                    $fields[$idx]['draft_editable'] = false;
                    $fields[$idx]['user_filled'] = false;

                    continue;
                }
            }

            // Original Word template columns stay locked on send-back too:
            // Description, Instrument Name, Checks, Methodology, Acceptance criteria.
            // Observation / Done by / dates stay reopenable below.
            if (
                ! $isLetterhead
                && ! $this->isExecutionFillColumn($label)
                && $this->isTemplateContentHeader($label)
                && trim((string) ($field['value'] ?? '')) !== ''
            ) {
                $fields[$idx]['locked'] = true;
                $fields[$idx]['prefilled'] = true;
                $fields[$idx]['draft_editable'] = false;

                continue;
            }

            // True Word structural labels stay locked (same as fresh NEQ import).
            if (
                ! $isLetterhead
                && $this->looksLikeNeqStructuralLabelField($field)
                && empty($field['draft_editable'])
                && empty($field['user_filled'])
                && empty($field['auto_from_name'])
            ) {
                $fields[$idx]['locked'] = true;

                continue;
            }

            // Locked Word body paragraphs stay locked unless previously marked draft/user.
            if (
                $type === 'paragraph_content'
                && empty($field['draft_editable'])
                && empty($field['user_filled'])
                && ! $isLetterhead
            ) {
                $fields[$idx]['locked'] = true;

                continue;
            }

            $isDateEntry = $this->isDocxDateTimeEntryField($field);
            if ($isDateEntry) {
            $fields[$idx]['locked'] = false;
                $fields[$idx]['draft_editable'] = true;
                $fields[$idx]['user_filled'] = true;
                $fields[$idx]['prefilled'] = false;
                $fields[$idx]['widget'] = 'datetime-local';
                // Keep user-picked calibration/done/due dates across refresh & send-back.
                // Only blank leftover template dates (e.g. 13/01/17) that were never selected here.
                $rawValue = (string) ($field['value'] ?? '');
                $kept = $this->normalizeDateTimeInputValue($rawValue);
                $alreadySelected = ($field['date_selected'] ?? false) === true
                    || ($field['date_selected'] ?? false) === 1
                    || ($field['date_selected'] ?? false) === '1';
                if ($kept !== '') {
                    $fields[$idx]['value'] = $kept;
                    $fields[$idx]['date_selected'] = true;
                } elseif ($alreadySelected && trim($rawValue) !== '') {
                    $fields[$idx]['value'] = $rawValue;
                    $fields[$idx]['date_selected'] = true;
                } else {
                    $fields[$idx]['value'] = '';
                    $fields[$idx]['date_selected'] = false;
                }

                continue;
            }

            // Fill targets (observations, signs, letterhead, approvers, radios, comments…):
            // force reopen even when a prior submit tagged them prefilled/locked.
            $fields[$idx]['locked'] = false;
            $fields[$idx]['draft_editable'] = true;
            $fields[$idx]['user_filled'] = true;
            $fields[$idx]['prefilled'] = false;
        }

        return $fields;
    }

    /**
     * Merge stamp updates into the stored field list without replacing pre-approval values.
     *
     * @param  array<int, array<string, mixed>>  $baselineFields
     * @param  array<int, array<string, mixed>>  $proposedFields
     * @return array<int, array<string, mixed>>
     */
    // Merges proposed stamp values into baseline by location while preserving locked pre-approval content.
    private function mergeDocxImportFieldsPreservingBaseline(
        array $baselineFields,
        array $proposedFields,
        ?int $reportId = null
    ): array {
        $proposedByLocation = [];
        foreach ($proposedFields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $loc = (string) ($field['location'] ?? '');
            if ($loc !== '') {
                $proposedByLocation[$loc] = $field;
            }
        }

        $merged = [];
        foreach ($baselineFields as $field) {
            if (! is_array($field)) {
                $merged[] = $field;
                continue;
            }

            $location = (string) ($field['location'] ?? '');
            if ($location === '' || ! isset($proposedByLocation[$location])) {
                $merged[] = $field;
                continue;
            }

            $proposed      = $proposedByLocation[$location];
            $baselineValue = (string) ($field['value'] ?? '');
            $proposedValue = (string) ($proposed['value'] ?? '');

            $isPostStampCell = $this->isPostApprovalWorkflowField($field)
                || $this->isPostApprovalWorkflowField($proposed);
            $isPreApproverStamp = ! empty($proposed['is_pre_approver'])
                || ! empty($field['is_pre_approver']);

            // Post-approval Name / Signature are owned by the stamp pipeline (including blanks).
            // Pre-approval Prepared/Checked/Approved rows also take stamp values so
            // "Name (email)" select labels are replaced with "Name - designation".
            if ($isPostStampCell || $isPreApproverStamp) {
                $mergedField          = array_merge($field, $proposed);
                $mergedField['value'] = $proposedValue;
                $merged[]             = $mergedField;
                continue;
            }

            // Prefilled or locked baseline values always win over proposed stamp updates.
            if ($this->shouldPreserveBaselineFieldValue($field, $baselineValue)) {
                if (
                    $baselineValue !== ''
                    && $proposedValue !== ''
                    && $baselineValue !== $proposedValue
                ) {
                    Log::warning('DOCX pre-approval field mutation blocked during POST approval', [
                        'report_id' => $reportId,
                        'location'  => $location,
                        'baseline'  => $baselineValue,
                        'proposed'  => $proposedValue,
                    ]);
                }

                $merged[] = $field;
                continue;
            }

            // Allow stamp-eligible fields to accept new approver name/signature/date values.
            if ($this->isPostApprovalStampField($field) && $proposedValue !== $baselineValue) {
                $mergedField          = array_merge($field, $proposed);
                $mergedField['value'] = $proposedValue;
                $merged[]             = $mergedField;
                continue;
            }

            // Stamp target with empty baseline: apply proposed value (e.g. first-time signature date).
            if (
                $baselineValue === ''
                && $proposedValue !== ''
                && $this->isSignatureStampTargetField($field)
            ) {
                $mergedField          = array_merge($field, $proposed);
                $mergedField['value'] = $proposedValue;
                $merged[]             = $mergedField;
                continue;
            }

            if (
                $baselineValue !== ''
                && $proposedValue !== ''
                && $baselineValue !== $proposedValue
            ) {
                Log::warning('DOCX field value conflict preserved baseline during POST approval', [
                    'report_id' => $reportId,
                    'location'  => $location,
                    'baseline'  => $baselineValue,
                    'proposed'  => $proposedValue,
                ]);
            }

            $merged[] = $field;
        }

        // Append any new stamp fields that were not present in the original baseline list.
        $mergedLocations = array_column(
            array_filter($merged, fn ($f) => is_array($f)),
            'location'
        );
        foreach ($proposedFields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $location = (string) ($field['location'] ?? '');
            if ($location !== '' && ! in_array($location, $mergedLocations, true)) {
                $merged[] = $field;
            }
        }

        return $merged;
    }

    /**
     * True when a signature-table cell should receive an approver stamp (not arbitrary row data).
     */
    // Checks whether a cell in the approver's signature row should receive the stamp values.
    private function shouldApplySignatureStampToField(
        array $field,
        string $approverLocation,
        string $roleKey,
        string $dateLocation
    ): bool {
        if (! $this->isSignatureStampTargetField($field)) {
            return false;
        }

        $location = (string) ($field['location'] ?? '');
        if ($location === $approverLocation) {
            return true;
        }
        if ($dateLocation !== '' && $location === $dateLocation) {
            return true;
        }

        $type = (string) ($field['type'] ?? '');
        if (str_starts_with($type, 'approval_stamp_')) {
            return $roleKey === '' || (string) ($field['approver_role'] ?? '') === $roleKey;
        }

        $label  = strtolower((string) ($field['label'] ?? ''));
        $widget = strtolower((string) ($field['widget'] ?? ''));
        $isSigOrDate = str_contains($label, 'signature')
            || str_contains($label, 'date')
            || $widget === 'date';

        // Signature & Date cells often have no approver_role — stamp by same-row location.
        if ($isSigOrDate && $approverLocation !== ''
            && preg_match('/^(table_\d+_row_\d+)_col_\d+$/', $approverLocation, $am)
            && str_starts_with($location, $am[1] . '_col_')
        ) {
            return true;
        }

        if ($roleKey === '' || (string) ($field['approver_role'] ?? '') !== $roleKey) {
            return false;
        }

        return $isSigOrDate;
    }

    /**
     * applyApprovalStampToSignatureRow() — Write approver name + date into one signature row.
     *
     * Name & Designation keeps the approver name; Signature shows the approver name;
     * Date shows name + datetime together.
     *
     * @param  array<string, int>  $indexByLocation
     */
    /** Canonical POST approver keys — Word table order (Checked By: Eng → Production → Validation → QA). */
    private const POST_APPROVER_FIELD_ORDER = [
        'post_prepared_by_name',
        'post_checked_by_engineering_name',
        'post_checked_by_production_name',
        'post_checked_by_validation_name',
        'post_checked_by_quality_name',
        'post_approved_by_user_name',
        'post_approved_by_quality_name',
    ];

    /** @var array<string, string> */
    private const POST_APPROVER_STEP_LABELS = [
        'post_prepared_by_name'            => 'POST_PREPARED',
        'post_checked_by_engineering_name' => 'POST_ENGINEERING',
        'post_checked_by_production_name'  => 'POST_PRODUCTION',
        'post_checked_by_validation_name'  => 'POST_VALIDATION',
        'post_checked_by_quality_name'     => 'POST_CHECKED_QA',
        'post_approved_by_user_name'       => 'POST_APPROVED_USER',
        'post_approved_by_quality_name'    => 'POST_APPROVED_QA',
    ];

    /**
     * POST approver roles that exist in this report's parsed docx_import_fields.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return list<string>
     */
    // Returns POST approver roles present in parsed fields, preserving canonical workflow order.
    private function collectPostApproverRolesFromFields(array $fields): array
    {
        $present = [];
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            // Only real user-select Name dropdowns create workflow steps.
            // Signature/Date (even if mis-typed as approver) must never require a POST user.
            $type = strtolower(trim((string) ($field['type'] ?? '')));
            $widget = strtolower(trim((string) ($field['widget'] ?? '')));
            if ($type !== 'approver' && $widget !== 'approver') {
                continue;
            }
            $label = strtolower(trim((string) ($field['label'] ?? '')));
            if (
                str_contains($label, 'signature')
                || $label === 'date'
                || preg_match('/^\s*date\b/i', (string) ($field['label'] ?? '')) === 1
            ) {
                continue;
            }
            $role = trim((string) ($field['approver_role'] ?? ''));
            if ($role !== '' && in_array($role, self::POST_APPROVER_FIELD_ORDER, true)) {
                $present[$role] = true;
            }
        }

        return array_values(array_filter(
            self::POST_APPROVER_FIELD_ORDER,
            fn (string $role) => isset($present[$role])
        ));
    }

    /**
     * Keep POST approver roles in template order — do not invent Approved By
     * when the Word table only has Performed/Checked (2 rows) or other layouts.
     *
     * @param  list<string>  $roles
     * @return list<string>
     */
    private function ensureNeqPostApprovalRoleChain(array $roles): array
    {
        // Document-native roles only (2, 3, 4, …). Preserve POST_APPROVER_FIELD_ORDER.
        return array_values(array_filter(
            self::POST_APPROVER_FIELD_ORDER,
            fn (string $role) => in_array($role, $roles, true)
        ));
    }

    /** True only for New Equipment Qualification — requal/reval must never enter NEQ-only branches. */
    private function isNeqImportSource(?string $source): bool
    {
        return strtolower(trim((string) $source)) === 'new_equipment_qualification';
    }

    /**
     * @param  list<string>  $presentRoles
     * @return array<string, list<string>>
     */
    // Builds Laravel validation rules requiring user ids for each POST approver role in the template.
    private function buildPostApproverValidationRules(array $presentRoles): array
    {
        $rules = [];
        foreach ($presentRoles as $role) {
            $rules[$role] = ['required', 'integer'];
        }

        return $rules;
    }

    /**
     * True when docx_import_fields includes APPROVAL PAGE stamp cells (Recorded/Checked/Approved By).
     *
     * @param  array<int, array<string, mixed>>  $fields
     */
    // Detects whether this template uses APPROVAL PAGE stamp cells vs legacy signature tables.
    private function reportUsesApprovalPageTable(array $fields): bool
    {
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $type = (string) ($field['type'] ?? '');
            if (str_starts_with($type, 'approval_stamp_')) {
                return true;
            }
        }

        return $this->detectApprovalPageFromLegacyFields($fields);
    }

    /**
     * Detect APPROVAL PAGE tables imported before approval_stamp_* types existed.
     *
     * @param  array<int, array<string, mixed>>  $fields
     */
    // Heuristic for older imports: three rows of Name/Signature/Date in a 3x3 table indicate approval page.
    private function detectApprovalPageFromLegacyFields(array $fields): bool
    {
        $byTable = [];

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $location = (string) ($field['location'] ?? '');
            if (! preg_match('/^table_(\d+)_row_([1-3])_col_([1-3])$/', $location, $m)) {
                continue;
            }

            $label = strtolower(trim((string) ($field['label'] ?? '')));
            if (! in_array($label, ['name', 'signature', 'date'], true)) {
                continue;
            }

            $byTable[$m[1]][$m[2]][$label] = true;
        }

        foreach ($byTable as $rows) {
            if (count($rows) < 3) {
                continue;
            }

            $completeRows = 0;
            foreach ($rows as $cols) {
                if (isset($cols['name'], $cols['signature'], $cols['date'])) {
                    $completeRows++;
                }
            }

            // At least three complete signer rows confirms an APPROVAL PAGE layout.
            if ($completeRows >= 3) {
                return true;
            }
        }

        return false;
    }

    /**
     * "Certificate No." is a typed identifier, not a file upload — fix legacy attachment typing.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    // Reclassifies mis-parsed Certificate No. attachment columns as plain text inputs.
    private function upgradeCertificateNumberTextFields(array $fields): array
    {
        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }
            $label = (string) ($field['label'] ?? '');
            $type = (string) ($field['type'] ?? '');
            $widget = strtolower(trim((string) ($field['widget'] ?? '')));

            // Annexure No. keeps the Word ANNEX_* id — never a Choose File control.
            if ($this->isAnnexureIdColumnLabel($label)) {
                $fields[$idx]['type'] = 'table_multicol';
                $fields[$idx]['widget'] = 'text';
                $val = trim((string) ($field['value'] ?? ''));
                if ($val !== '') {
                    $fields[$idx]['locked'] = true;
                    $fields[$idx]['prefilled'] = true;
                } else {
                    $fields[$idx]['locked'] = false;
                }
                continue;
            }

            if ($type !== 'attachment' && $widget !== 'file') {
                continue;
            }
            if (! $this->isCertificateNumberColumnLabel($label)) {
                continue;
            }

            $fields[$idx]['type']   = 'table_multicol';
            $fields[$idx]['widget'] = 'text';
            $fields[$idx]['locked'] = false;
            $fields[$idx]['draft_editable'] = true;
            unset($fields[$idx]['prefilled']);
        }

        return $fields;
    }

    /**
     * NEQ instrument / contact tables: NA placeholders stay fillable text.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function upgradeNeqPlaceholderTableCells(array $fields): array
    {
        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }
            $loc = (string) ($field['location'] ?? '');
            if (! preg_match('/^table_\d+_row_[1-9]\d*_col_\d+$/', $loc)) {
                continue;
            }
            $label = (string) ($field['label'] ?? '');
            if ($this->isAnnexureIdColumnLabel($label)) {
                continue;
            }
            $type = strtolower(trim((string) ($field['type'] ?? '')));
            if (in_array($type, ['attachment', 'approver', 'table_sl_no'], true)
                || str_starts_with($type, 'approval_stamp')
            ) {
                continue;
            }
            $val = (string) ($field['value'] ?? '');
            if (! $this->isPlaceholderTableValue($val) && trim($val) !== '') {
                continue;
            }
            $fields[$idx]['type'] = 'table_multicol';
            $fields[$idx]['widget'] = 'text';
            $fields[$idx]['locked'] = false;
            $fields[$idx]['draft_editable'] = true;
            unset($fields[$idx]['prefilled']);
        }

        return $fields;
    }

    /**
     * Lock col-0 serial cells (1., 2., 3.) in Main Body / Components section tables.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    // Locks auto-numbered serial cells in component verification tables so users cannot edit them.
    private function upgradeComponentSectionSerialFields(array $fields): array
    {
        $byTable = [];
        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }
            $loc = (string) ($field['location'] ?? '');
            if (! preg_match('/^table_(\d+)_row_(\d+)_col_(\d+)$/', $loc, $m)) {
                continue;
            }
            $byTable[$m[1]][(int) $m[2]][(int) $m[3]] = [
                'idx'   => $idx,
                'value' => (string) ($field['value'] ?? ''),
            ];
        }

        foreach ($byTable as $rows) {
            if (! $this->tableLooksLikeCompositeComponentBlock($rows)) {
                continue;
            }

            foreach ($this->resolveComponentSectionSerialRows($rows) as $rowNum => $serial) {
                if (! isset($rows[$rowNum][0])) {
                    continue;
                }
                $idx = $rows[$rowNum][0]['idx'];
                $fields[$idx]['type']        = 'table_sl_no';
                $fields[$idx]['widget']      = 'text';
                $fields[$idx]['locked']      = true;
                $fields[$idx]['auto_serial'] = $serial;
                $val = trim((string) ($fields[$idx]['value'] ?? ''));
                // Preserve existing sl-no text when it already looks like a valid serial number.
                if (! $this->looksLikeSlNoValue($val)) {
                    $fields[$idx]['value'] = $serial . '.';
                }
            }
        }

        return $fields;
    }

    /**
     * @param  array<int, array<int, array{idx: int, value: string}>>  $rows
     */
    // Identifies Main Body / Components tables by characteristic header and Verified By text.
    private function tableLooksLikeCompositeComponentBlock(array $rows): bool
    {
        $text = '';
        foreach ($rows as $rowCols) {
            foreach ($rowCols as $col) {
                $text .= ' ' . strtolower($col['value'] ?? '');
            }
        }
        if (! str_contains($text, 'verified by')) {
            return false;
        }

        return str_contains($text, 'main body')
            || str_contains($text, 'mechanical components')
            || str_contains($text, 'electrical components')
            || str_contains($text, 'pneumatic components');
    }

    /**
     * @param  array<int, array<int, array{idx: int, value: string}>>  $rows
     * @return array<int, int>
     */
    private function resolveComponentSectionSerialRows(array $rows): array
    {
        ksort($rows);
        $serialRows    = [];
        $inDataBlock   = false;
        $sectionSerial = 0;

        foreach ($rows as $rowNum => $cols) {
            if ($rowNum === 0) {
                continue;
            }

            $rowText = strtolower(implode(' ', array_column(array_values($cols), 'value')));

            if ($this->isVerifiedBySignRowText($rowText)) {
                $inDataBlock   = false;
                $sectionSerial = 0;
                continue;
            }

            if ($this->isComponentSectionHeaderRowText($cols)) {
                $inDataBlock   = true;
                $sectionSerial = 0;
                continue;
            }

            if (! $inDataBlock && $rowNum === 1) {
                $inDataBlock   = true;
                $sectionSerial = 0;
            }

            if ($inDataBlock) {
                $sectionSerial++;
                $serialRows[$rowNum] = $sectionSerial;
            }
        }

        return $serialRows;
    }

    // True when a table row contains the Verified By signature footer text.
    private function isVerifiedBySignRowText(string $text): bool
    {
        return str_contains($text, 'verified by');
    }

    /**
     * @param  array<int, array{idx: int, value: string}>  $cols
     */
    // True when an entire row repeats a component section header label across columns.
    private function isComponentSectionHeaderRowText(array $cols): bool
    {
        $values = array_values(array_filter(array_map(
            fn (array $c) => trim($c['value'] ?? ''),
            array_values($cols)
        )));
        if ($values === []) {
            return false;
        }
        $unique = array_unique(array_map('strtolower', $values));
        if (count($unique) !== 1) {
            return false;
        }
        $label = $unique[0];

        return str_contains($label, 'main body')
            || str_contains($label, 'mechanical components')
            || str_contains($label, 'electrical components')
            || str_contains($label, 'pneumatic components');
    }

    // Integer serials only (1. / 2)). Outline section numbers like 10.0 are not Sl. No.
    private function looksLikeSlNoValue(string $value): bool
    {
        return (bool) preg_match('/^\d+[\.\)]?$/', trim($value));
    }

    /**
     * Tag protocol cover cells (Equipment ID, Effective Date, etc.) for fill/sync.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    // Tags protocol cover cells (Equipment ID, Effective Date, etc.) with cover_field keys for sync.
    private function upgradeProtocolCoverFieldMetadata(array $fields): array
    {
        // Needles use the same a-z0-9 spacing as sanitized labels ("facility section").
        $labelToCover = [
            'reference protocol'    => 'reference_protocol_no',
            'reference document no' => 'reference_protocol_no',
            'reference document'    => 'reference_protocol_no',
            'ref document no'       => 'reference_protocol_no',
            'ref document'          => 'reference_protocol_no',
            'ref protocol'          => 'reference_protocol_no',
            'document number'       => 'document_no',
            'document no'           => 'document_no',
            'document title'        => 'document_title',
            'equipment system name' => 'equipment_name',
            'equipment name'        => 'equipment_name',
            'equipment system id'   => 'equipment_id',
            'equipment id no'       => 'equipment_id',
            'equipment id'          => 'equipment_id',
            'facility section'      => 'facility_section',
            'effective date'        => 'effective_date',
        ];

        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }
            $label = strtolower(trim((string) ($field['label'] ?? '')));
            $label = trim(preg_replace('/[^a-z0-9]+/', ' ', $label) ?? $label);
            // Collapse duplicated "Effective Date Effective Date" labels from merged DOCX cells.
            $label = preg_replace('/\b(effective date)(?:\s+\1)+\b/', '$1', $label) ?? $label;

            $resolved = null;
            if (in_array($label, ['version', 'version no'], true)) {
                $resolved = 'version';
            } else {
            foreach ($labelToCover as $needle => $key) {
                    // Word-boundary match so "equipment identification" does not become
                    // cover_field=equipment_id (which opens a description box on bullets).
                    $needlePattern = '/(?:^| )' . preg_quote($needle, '/') . '(?: |$)/';
                    if (preg_match($needlePattern, ' ' . $label . ' ') !== 1) {
                    continue;
                }
                    // Avoid tagging "Reference Document No." / body "As per Document No." as protocol Document No.
                    if (
                        $key === 'document_no'
                        && (
                            str_contains($label, 'reference')
                            || str_contains($label, 'ref ')
                            || str_contains($label, 'as per')
                        )
                    ) {
                    continue;
                }
                    $resolved = $key;
                    break;
                }
            }

            $loc = (string) ($field['location'] ?? '');
            // Cover identity sync is letterhead-only (header_* / txbx_*). Body tables
            // like PM "Equipment Name / ID" must NOT get cover_field — that cross-wired
            // Document No. edits into Equipment Name on requal fill.
            $isLetterheadLoc = str_starts_with($loc, 'header_') || str_starts_with($loc, 'txbx_');

            if ($resolved !== null && $isLetterheadLoc) {
                // Always re-assert cover_field from the label so Att6 REF DOCUMENT
                // is not left tagged as document_no from legacy HVAC mapping.
                $fields[$idx]['cover_field'] = $resolved;
                $fields[$idx]['type'] = 'protocol_cover';
                if ($resolved === 'effective_date') {
                    $fields[$idx]['widget'] = 'date';
                }
            } elseif (! $isLetterheadLoc && (! empty($field['cover_field']) || $resolved !== null)) {
                // Strip polluted cover tags from body/PM tables.
                unset($fields[$idx]['cover_field']);
            } elseif (! empty($field['cover_field']) && $isLetterheadLoc) {
                $fields[$idx]['type'] = 'protocol_cover';
            }
        }

        $fields = $this->repairAtt4InstrumentsLetterheadCoverRoles($fields);
        $fields = $this->repairAtt12TrainingLetterheadCoverRoles($fields);

        return $this->repairAtt6LetterheadCoverRoles($fields);
    }

    /**
     * Att12 Training letterhead:
     * Reference Document No. (Version No.) | value
     * Equipment/system name | value | Equipment/system ID No. | value
     * Facility / Section | value
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function repairAtt12TrainingLetterheadCoverRoles(array $fields): array
    {
        $byLoc = [];
        foreach ($fields as $idx => $field) {
            if (is_array($field) && ($field['location'] ?? '') !== '') {
                $byLoc[(string) $field['location']] = $idx;
            }
        }

        if (! isset($byLoc['header_0_row_2_col_2'], $byLoc['header_0_row_3_col_2'], $byLoc['header_0_row_4_col_2'])) {
            return $fields;
        }
        // Att6 has VERSION at row_5_col_4; Att4 has Document Title at row_3 — skip those.
        if (isset($byLoc['header_0_row_5_col_4']) || $this->isAtt4InstrumentsLetterhead($byLoc)) {
            return $fields;
        }

        $refIdx = $byLoc['header_0_row_2_col_2'];
        $refLabel = strtolower(trim((string) ($fields[$refIdx]['label'] ?? '')));
        if (! str_contains($refLabel, 'reference') && ! str_contains($refLabel, 'ref document')) {
            return $fields;
        }

        $fields[$refIdx]['cover_field'] = 'reference_protocol_no';
        $fields[$refIdx]['label'] = 'Reference Document No. (Version No.)';
        $fields[$refIdx]['type'] = 'protocol_cover';
        $fields[$refIdx]['locked'] = false;
        $fields[$refIdx]['draft_editable'] = true;

        $fields[$byLoc['header_0_row_3_col_2']]['cover_field'] = 'equipment_name';
        $fields[$byLoc['header_0_row_3_col_2']]['type'] = 'protocol_cover';
        $fields[$byLoc['header_0_row_3_col_2']]['locked'] = false;
        $fields[$byLoc['header_0_row_3_col_2']]['draft_editable'] = true;

        if (isset($byLoc['header_0_row_3_col_4'])) {
            $fields[$byLoc['header_0_row_3_col_4']]['cover_field'] = 'equipment_id';
            $fields[$byLoc['header_0_row_3_col_4']]['type'] = 'protocol_cover';
            $fields[$byLoc['header_0_row_3_col_4']]['locked'] = false;
            $fields[$byLoc['header_0_row_3_col_4']]['draft_editable'] = true;
        }

        $facIdx = $byLoc['header_0_row_4_col_2'];
        $fields[$facIdx]['cover_field'] = 'facility_section';
        $fields[$facIdx]['label'] = 'Facility / Section';
        $fields[$facIdx]['type'] = 'protocol_cover';
        $fields[$facIdx]['locked'] = false;
        $fields[$facIdx]['draft_editable'] = true;

        return $fields;
    }

    /**
     * Att4 Instruments letterhead:
     * Document No. (Version No.) | value
     * Document Title | value
     * Equipment/system name | value | Equipment/system ID No. | value
     * Facility /Section | value
     *
     * Must NOT run Att6 remap (that retags Document Title → Facility and
     * Facility → Document No., which makes Title edits overwrite Facility).
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function repairAtt4InstrumentsLetterheadCoverRoles(array $fields): array
    {
        $byLoc = [];
        foreach ($fields as $idx => $field) {
            if (is_array($field) && ($field['location'] ?? '') !== '') {
                $byLoc[(string) $field['location']] = $idx;
            }
        }

        if (! $this->isAtt4InstrumentsLetterhead($byLoc)) {
            return $fields;
        }

        $map = [
            'header_0_row_2_col_2' => ['document_no', 'Document No. (Version No.)'],
            'header_0_row_3_col_2' => ['document_title', 'Document Title'],
            'header_0_row_4_col_2' => ['equipment_name', 'Equipment / System Name'],
            'header_0_row_4_col_4' => ['equipment_id', 'Equipment / System ID No.'],
            'header_0_row_5_col_2' => ['facility_section', 'Facility / Section'],
        ];

        foreach ($map as $loc => [$cover, $label]) {
            if (! isset($byLoc[$loc])) {
                continue;
            }
            $idx = $byLoc[$loc];
            $fields[$idx]['cover_field'] = $cover;
            $fields[$idx]['label'] = $label;
            $fields[$idx]['type'] = 'protocol_cover';
            $fields[$idx]['locked'] = false;
            $fields[$idx]['draft_editable'] = true;
        }

        return $fields;
    }

    /**
     * Att4 fingerprint: Doc No at row2, title at row3, equip at row4, facility at row5,
     * and no Att6 VERSION cell at row5col4.
     *
     * @param  array<string, int>  $byLoc
     */
    private function isAtt4InstrumentsLetterhead(array $byLoc): bool
    {
        if (isset($byLoc['header_0_row_5_col_4'])) {
            return false;
        }
        if (isset($byLoc['header_0_row_3_col_4'])) {
            // Att6 has equipment name on the facility row (row3 col4).
            return false;
        }

        return isset(
            $byLoc['header_0_row_2_col_2'],
            $byLoc['header_0_row_3_col_2'],
            $byLoc['header_0_row_4_col_2'],
            $byLoc['header_0_row_5_col_2']
        );
    }

    /**
     * Att6 letterhead grid (row 3/4/5):
     * FACILITY|val|EQUIPMENT NAME|val
     * REF. DOCUMENT NO.|val|EQUIPMENT ID.|val
     * DOCUMENT No.|val|VERSION|val
     * Legacy HVAC code forced row_4_col_2 = document_no — undo that when row_5 has Document No.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function repairAtt6LetterheadCoverRoles(array $fields): array
    {
        $byLoc = [];
        foreach ($fields as $idx => $field) {
            if (is_array($field) && ($field['location'] ?? '') !== '') {
                $byLoc[(string) $field['location']] = $idx;
            }
        }

        // Never apply Att6 grid remap to Att4 Instruments (Document Title / Facility rows).
        if ($this->isAtt4InstrumentsLetterhead($byLoc)) {
            return $fields;
        }

        // Att6 requires VERSION value cell at row_5_col_4.
        if (! isset($byLoc['header_0_row_5_col_4'])) {
            return $fields;
        }

        $hasDocAtRow5 = isset($byLoc['header_0_row_5_col_2']);
        $refLoc = 'header_0_row_4_col_2';
        $verLoc = 'header_0_row_5_col_4';
        $facLoc = 'header_0_row_3_col_2';

        if ($hasDocAtRow5 && isset($byLoc[$refLoc])) {
            $idx = $byLoc[$refLoc];
            $fields[$idx]['cover_field'] = 'reference_protocol_no';
            $fields[$idx]['label'] = 'Reference Document No.';
            $fields[$idx]['type'] = 'protocol_cover';
            // Do not keep HVAC "Document Number" label on the REF cell.
        }

        if (isset($byLoc['header_0_row_5_col_2'])) {
            $idx = $byLoc['header_0_row_5_col_2'];
            $fields[$idx]['cover_field'] = 'document_no';
            $fields[$idx]['label'] = 'Document No.';
            $fields[$idx]['type'] = 'protocol_cover';
            $fields[$idx]['locked'] = false;
            // Clear value wrongly copied from REF. DOCUMENT into blank Document No.
            $refVal = isset($byLoc[$refLoc])
                ? trim((string) ($fields[$byLoc[$refLoc]]['value'] ?? ''))
                : '';
            $docVal = trim((string) ($fields[$idx]['value'] ?? ''));
            if ($docVal !== '' && $refVal !== '' && $docVal === $refVal) {
                $fields[$idx]['value'] = '';
                $fields[$idx]['prefilled'] = false;
            }
        }

        if (isset($byLoc[$verLoc])) {
            $idx = $byLoc[$verLoc];
            $fields[$idx]['cover_field'] = 'version';
            $fields[$idx]['label'] = 'Version';
            $fields[$idx]['type'] = 'protocol_cover';
            $fields[$idx]['locked'] = false;
            $fields[$idx]['draft_editable'] = true;
            $val = trim((string) ($fields[$idx]['value'] ?? ''));
            // Lone "." is a broken python-docx read of split runs "0"+"."+"2".
            if ($val === '.' || $val === '0.' || $val === '.2') {
                $fields[$idx]['value'] = '0.2';
                $fields[$idx]['prefilled'] = true;
            }
        }

        if (isset($byLoc[$facLoc])) {
            $idx = $byLoc[$facLoc];
            $fields[$idx]['cover_field'] = 'facility_section';
            $fields[$idx]['label'] = 'Facility / Section';
            $fields[$idx]['type'] = 'protocol_cover';
            $fields[$idx]['locked'] = false;
            $fields[$idx]['draft_editable'] = true;
        }

        // All Att6 letterhead value cells stay fillable (even when Word-prefilled).
        foreach (['header_0_row_3_col_4', 'header_0_row_4_col_4', $refLoc, $verLoc, 'header_0_row_5_col_2', $facLoc] as $lhLoc) {
            if (! isset($byLoc[$lhLoc])) {
                continue;
            }
            $idx = $byLoc[$lhLoc];
            $fields[$idx]['locked'] = false;
            $fields[$idx]['draft_editable'] = true;
            $fields[$idx]['type'] = 'protocol_cover';
        }

        if (isset($byLoc['header_0_row_3_col_4'])) {
            $idx = $byLoc['header_0_row_3_col_4'];
            $fields[$idx]['cover_field'] = 'equipment_name';
            $fields[$idx]['type'] = 'protocol_cover';
        }

        if (isset($byLoc['header_0_row_4_col_4'])) {
            $idx = $byLoc['header_0_row_4_col_4'];
            $fields[$idx]['cover_field'] = 'equipment_id';
            $fields[$idx]['type'] = 'protocol_cover';
        }

        return $fields;
    }

    /**
     * When Att6 VERSION was stored as "." / cleared empty, re-read letterhead
     * cover values from the Word template so fill shows "0.2" etc.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function restoreAtt6LetterheadFromWordIfNeeded(Report $report, array $fields): array
    {
        $byLoc = [];
        foreach ($fields as $idx => $field) {
            if (is_array($field) && ($field['location'] ?? '') !== '') {
                $byLoc[(string) $field['location']] = $idx;
            }
        }

        if (! isset($byLoc['header_0_row_5_col_2'], $byLoc['header_0_row_5_col_4'])) {
            return $fields;
        }

        $verIdx = $byLoc['header_0_row_5_col_4'];
        $verVal = trim((string) ($fields[$verIdx]['value'] ?? ''));
        $needsVersion = ($verVal === '' || $verVal === '.' || $verVal === '0.' || $verVal === '.2');
        if (! $needsVersion) {
            return $fields;
        }

        $fileId = trim((string) ($report->docx_import_path ?? ''));
        if ($fileId === '') {
            return $fields;
        }

        $this->ensureDocxTemplateOnDisk($report);
        $filePath = $this->getDocxTemplateAbsolutePath($fileId);
        if ($filePath === null || ! is_file($filePath)) {
            return $fields;
        }

        $freshFields = [];
        try {
            $pythonBin = base_path('python-service/venv/Scripts/python.exe');
            if (! is_file($pythonBin)) {
                $pythonBin = 'python';
            }
            $cliScript = base_path('python-service/cli.py');
            $process = new Process([$pythonBin, $cliScript, 'parse', $filePath]);
            $process->setTimeout(60);
            $process->run();
            $output = trim($process->getOutput());
            $jsonStart = strpos($output, '{"status"');
            if ($jsonStart !== false) {
                $decoded = json_decode(substr($output, $jsonStart), true);
                if (is_array($decoded) && ($decoded['status'] ?? '') === 'success') {
                    $freshFields = $this->normalizeImportFields($decoded['fields'] ?? []);
                    $freshFields = $this->upgradeProtocolCoverFieldMetadata($freshFields);
                }
            }
        } catch (\Throwable $e) {
            Log::info('Att6 letterhead Word restore skipped: ' . $e->getMessage());

            return $fields;
        }

        if ($freshFields === []) {
            return $fields;
        }

        $freshByCover = [];
        foreach ($freshFields as $ff) {
            if (! is_array($ff)) {
                continue;
            }
            $ck = (string) ($ff['cover_field'] ?? '');
            $val = trim((string) ($ff['value'] ?? ''));
            if ($ck !== '' && $val !== '' && ! isset($freshByCover[$ck])) {
                $freshByCover[$ck] = $val;
            }
        }

        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }
            $ck = (string) ($field['cover_field'] ?? '');
            if ($ck === '' || ! isset($freshByCover[$ck])) {
                continue;
            }
            $cur = trim((string) ($field['value'] ?? ''));
            $broken = ($ck === 'version' && in_array($cur, ['', '.', '0.', '.2'], true));
            $blankDoc = ($ck === 'document_no'); // never overwrite user text; only fill if we want — skip non-empty
            if ($blankDoc) {
                // Keep blank Document No. as blank even if fresh is blank.
                continue;
            }
            if ($cur === '' || $broken) {
                $fields[$idx]['value'] = $freshByCover[$ck];
                $fields[$idx]['prefilled'] = true;
                $fields[$idx]['locked'] = false;
                $fields[$idx]['draft_editable'] = true;
            }
        }

        return $fields;
    }

    /**
     * Push report / form values into protocol cover table cells.
     *
     * @param  array<string, string>  $formFields
     * @param  array<int, array<string, mixed>>  $importFields
     * @return array<string, string>
     */
    // Copies report and form values into cover table cell locations before fill or regeneration.
    private function applyProtocolCoverValues(array $formFields, array $importFields, Report $report): array
    {
        $data = $report->data ?? [];
        if (is_string($data)) {
            $data = json_decode($data, true) ?? [];
        }
        if (! is_array($data)) {
            $data = [];
        }

        $effectiveDate = (string) ($formFields['effective_date'] ?? $data['effective_date'] ?? '');
        // Resolve Effective Date from last approved step/revision/report when cover expects it but user has not supplied one yet.
        if ($effectiveDate === '' && $this->reportHasCoverField($importFields, 'effective_date')) {
            $effectiveDate = $this->resolveEffectiveDate($report, $importFields);
            $effectiveDate = $this->formatEffectiveDateForDocx($effectiveDate);
        } else {
            $effectiveDate = $this->formatEffectiveDateForDocx($effectiveDate);
        }

        $sources = [
            'equipment_name'         => (string) ($formFields['equipment_name'] ?? $report->equipment_name ?? ''),
            'equipment_id'           => (string) ($formFields['equipment_id'] ?? $report->equipment_id ?? ''),
            'facility_section'       => (string) ($formFields['facility_section'] ?? $report->facility_section ?? ''),
            'effective_date'         => $effectiveDate,
            'document_no'            => (string) (
                $formFields['protocol_document_no']
                ?? $formFields['document_number']
                ?? $data['document_number']
                ?? $this->extractCoverTextValue($importFields, 'document_no')
                ?? ''
            ),
            'document_title'         => (string) ($formFields['document_title'] ?? ''),
            'reference_protocol_no'  => (string) (
                $formFields['reference_protocol_no']
                ?? $data['reference_protocol_no']
                ?? $this->extractCoverTextValue($importFields, 'reference_protocol_no')
                ?? ''
            ),
        ];

        foreach ($importFields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $key = (string) ($field['cover_field'] ?? '');
            $loc = (string) ($field['location'] ?? '');
            if ($key === '' || $loc === '' || ($sources[$key] ?? '') === '') {
                continue;
            }
            // SOP and revision-history tables have an Effective Date column on every row.
            // Those dates are chosen per row, not copied from the protocol cover date.
            if ($key === 'effective_date' && $this->isRevisionHistoryEffectiveDateLocation($importFields, $loc)) {
                continue;
            }
            $formFields[$loc] = $sources[$key];
        }

        return $formFields;
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     */
    // Checks whether parsed fields include a given protocol cover key such as effective_date.
    private function reportHasCoverField(array $fields, string $coverField): bool
    {
        return $this->findCoverFieldLocation($fields, $coverField) !== null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     */
    // Resolves the DOCX cell location for a cover field, with special handling for Effective Date.
    private function findCoverFieldLocation(array $fields, string $coverField): ?string
    {
        if ($coverField === 'effective_date') {
            return $this->findPrimaryEffectiveDateLocation($fields);
        }

        $fallback = null;
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            if ((string) ($field['cover_field'] ?? '') !== $coverField) {
                continue;
            }
                $loc = (string) ($field['location'] ?? '');
            if ($loc === '') {
                continue;
            }
            // Att6 Document No. lives on row_5; row_4_col_2 is REF DOCUMENT.
            if ($coverField === 'document_no' && $loc === 'header_0_row_5_col_2') {
                    return $loc;
                }
            if ($coverField === 'reference_protocol_no' && $loc === 'header_0_row_4_col_2') {
                return $loc;
            }
            // Att12 Training: Reference Document No. (Version No.) value cell.
            if ($coverField === 'reference_protocol_no' && $loc === 'header_0_row_2_col_2') {
                return $loc;
            }
            if ($fallback === null) {
                $fallback = $loc;
            }
        }

        return $fallback;
    }

    /**
     * Resolve the user-facing Effective Date field (not revision-history columns).
     *
     * @param  array<int, array<string, mixed>>  $fields
     */
    // Picks the user-facing Effective Date cell, excluding revision-history grid duplicates.
    private function findPrimaryEffectiveDateLocation(array $fields): ?string
    {
        $candidates = [];
        foreach ($fields as $field) {
            if (! is_array($field) || ! $this->isEffectiveDateLikeField($field)) {
                continue;
            }
            $loc = trim((string) ($field['location'] ?? ''));
            // Skip revision-history tables that contain multiple Effective Date columns.
            if ($loc === '' || $this->isRevisionHistoryEffectiveDateLocation($fields, $loc)) {
                continue;
            }
            $candidates[] = $field;
        }

        // Prefer standalone textbox locations over table cells when both exist.
        foreach ($candidates as $field) {
            $loc = (string) ($field['location'] ?? '');
            if (str_starts_with($loc, 'txbx_')) {
                return $loc;
            }
        }

        $byTable = [];
        foreach ($candidates as $field) {
            $loc = (string) ($field['location'] ?? '');
            if (preg_match('/^table_(\d+)_/', $loc, $m)) {
                $byTable[$m[1]][] = $field;
            }
        }

        foreach ($byTable as $group) {
            if (count($group) === 1) {
                return (string) ($group[0]['location'] ?? '');
            }
        }

        // When multiple Effective Date cells share a table, use the last row as the protocol date.
        foreach ($byTable as $group) {
            if (count($group) > 1) {
                $last = end($group);
                if (is_array($last)) {
                    return (string) ($last['location'] ?? '');
                }
            }
        }

        return isset($candidates[0]) ? (string) ($candidates[0]['location'] ?? '') : null;
    }

    /**
     * Revision-history grids contain multiple "Effective Date" cells — not the protocol effective date.
     *
     * @param  array<int, array<string, mixed>>  $fields
     */
    // True when a location sits in a revision-history table with multiple Effective Date columns.
    private function isRevisionHistoryEffectiveDateLocation(array $fields, string $location): bool
    {
        if (! preg_match('/^table_(\d+)_/', $location, $m)) {
            return false;
        }

        $tableNum = $m[1];
        $count    = 0;
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $loc = (string) ($field['location'] ?? '');
            if (! str_starts_with($loc, "table_{$tableNum}_")) {
                continue;
            }
            if ($this->isEffectiveDateLikeField($field)) {
                $count++;
            }
        }

        // More than one Effective Date in the same table indicates revision history, not cover date.
        return $count > 1;
    }

    /**
     * Revision-history Effective Date cells keep their own dates and stay editable.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function unlockRevisionHistoryDateFields(array $fields): array
    {
        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }
            $loc = (string) ($field['location'] ?? '');
            if ($loc === '' || ! $this->isRevisionHistoryEffectiveDateLocation($fields, $loc)) {
                continue;
            }
            if (! $this->isEffectiveDateLikeField($field)) {
                continue;
            }
            $fields[$idx]['locked'] = false;
            $fields[$idx]['widget'] = 'date';
            unset($fields[$idx]['cover_field']);
            $fields[$idx]['value'] = $this->normalizeDateInputValue((string) ($field['value'] ?? ''));
        }

        return $fields;
    }

    /**
     * Turn 13/01/17 or 22/04/22 into yyyy-mm-dd so a date input can show and edit it.
     */
    private function normalizeDateInputValue(string $value): string
    {
        $value = trim($value);
        if ($value === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $value;
        }
        if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2}|\d{4})$/', $value, $m) !== 1) {
            return '';
        }
        $day = (int) $m[1];
        $month = (int) $m[2];
        $year = (int) $m[3];
        if ($year < 100) {
            $year += $year >= 70 ? 1900 : 2000;
        }
        if (! checkdate($month, $day, $year)) {
            return '';
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * Value for input type=datetime-local. Empty string when it is not a picker value.
     */
    private function normalizeDateTimeInputValue(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:[T ](\d{2}:\d{2}))/', $value, $m) === 1) {
            return $m[1] . 'T' . $m[2];
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $value . 'T00:00';
        }

        return '';
    }

    /**
     * Connected Accessories descriptions are template text, not fill-in cells.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function lockConnectedAccessoriesDescriptions(array $fields): array
    {
        $tables = [];
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $label = strtolower(trim((string) ($field['label'] ?? '')));
            $location = (string) ($field['location'] ?? '');
            // Header row may be table_N_row_0 or table_N_row_0_col_0 / col_1.
            if (
                str_contains($label, 'connected accessories')
                && preg_match('/^table_(\d+)_row_0(_col_\d+)?$/', $location, $m) === 1
            ) {
                $tables[$m[1]] = true;
            }
            if (
                $label === 'description'
                && preg_match('/^table_(\d+)_row_0_col_1$/', $location, $m) === 1
            ) {
                $tables[$m[1]] = true;
            }
        }
        if ($tables === []) {
            return $fields;
        }

        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }
            $location = (string) ($field['location'] ?? '');
            if (preg_match('/^table_(\d+)_row_/', $location, $m) !== 1 || ! isset($tables[$m[1]])) {
                continue;
            }
            // Whole Connected Accessories table is Word template content → locked.
            $fields[$idx]['locked'] = true;
            $fields[$idx]['prefilled'] = true;
            $fields[$idx]['draft_editable'] = false;
            $fields[$idx]['user_filled'] = false;
        }

        return $fields;
    }

    private function normalizedTableHeader(string $text): string
    {
        $text = strtolower(trim(preg_replace('/\s+/', ' ', $text) ?? ''));

        return rtrim($text, " :\t");
    }

    private function isTemplateContentHeader(string $text): bool
    {
        $text = $this->normalizedTableHeader($text);
        if ($text === '' || strlen($text) > 60) {
            return false;
        }
        // 5.0 DETAILS|DESCRIPTION, 9.1/9.2 Checks/Methodology/Acceptance,
        // 9.3 Identification / Position of valve + Acceptance criteria.
        if (in_array($text, [
            'checks',
            'methodology',
            'instrument name',
            'reason for revision',
            'description',
            'details',
        ], true)) {
            return true;
        }
        if (str_starts_with($text, 'acceptance')) {
            return true;
        }
        if (str_starts_with($text, 'identification') && ! str_contains($text, 'number')) {
            return true;
        }

        return str_contains($text, 'position of valve');
    }

    /**
     * Lock Word-prefilled protocol template columns during fill / pre-approval:
     * DESCRIPTION, Checks, Methodology, Acceptance criteria, Identification,
     * and numbered Sr. No. cells. Observation / Done by stay editable.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function lockRequalificationTemplateFilledColumns(array $fields): array
    {
        $fields = $this->lockExistingTableContent($fields);

        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }
            $type = strtolower(trim((string) ($field['type'] ?? '')));
            $location = strtolower(trim((string) ($field['location'] ?? '')));
            $label = strtolower(trim((string) ($field['label'] ?? '')));
            $value = trim((string) ($field['value'] ?? ''));

            // Table headings (row 0) locked — except 2-col key/value first data row
            // where col_1 is the fillable value (e.g. Name of Equipment | ggg).
            // Treating that row as a "header" left Equipment Name uneditable on fill.
            if (preg_match('/^table_\d+_row_0(_col_(\d+))?$/', $location, $row0m) === 1) {
                $row0Col = isset($row0m[2]) ? (int) $row0m[2] : null;
                $isTwoColValueCell = $type === 'table_2col'
                    && $row0Col === 1
                    && ! $this->isTemplateContentHeader($label)
                    && ! $this->isTemplateContentHeader($value);
                if ($isTwoColValueCell) {
                    $fields[$idx]['locked'] = false;
                    $fields[$idx]['prefilled'] = false;
                    $fields[$idx]['draft_editable'] = true;
                    $fields[$idx]['user_filled'] = true;
                    continue;
                }
                $fields[$idx]['locked'] = true;
                $fields[$idx]['prefilled'] = true;
                $fields[$idx]['draft_editable'] = false;
                continue;
            }

            $isSlNo = $type === 'table_sl_no'
                || preg_match('/^(sr\.?\s*no\.?|s\.?\s*no\.?|sl\.?\s*no\.?|#)\b/i', $label) === 1;
            if ($isSlNo && preg_match('/^table_\d+_row_[1-9]\d*_col_/', $location) === 1) {
                $serialVal = $value;
                if ($serialVal !== '' && ! $this->looksLikeSlNoValue($serialVal)) {
                    $serialVal = '';
                }
                if (
                    $serialVal === ''
                    && array_key_exists('auto_serial', $field)
                    && $field['auto_serial'] !== null
                    && $field['auto_serial'] !== ''
                ) {
                    $serialVal = ((int) $field['auto_serial']).'.';
                    $fields[$idx]['value'] = $serialVal;
                }
                // Numbered Sr. No. from Word / auto_serial → locked.
                if ($serialVal !== '' && $this->looksLikeSlNoValue($serialVal)) {
                    $fields[$idx]['locked'] = true;
                    $fields[$idx]['prefilled'] = true;
                    $fields[$idx]['draft_editable'] = false;
                    $fields[$idx]['user_filled'] = false;
                }
                continue;
            }

            // DESCRIPTION / Checks / Methodology / Acceptance / Identification
            // with a filled Word value → locked (empty cells stay fillable).
            if (
                $value !== ''
                && $value !== '—'
                && $value !== '-'
                && $this->isTemplateContentHeader($label)
            ) {
                $fields[$idx]['locked'] = true;
                $fields[$idx]['prefilled'] = true;
                $fields[$idx]['draft_editable'] = false;
                $fields[$idx]['user_filled'] = false;
            }
        }

        return $fields;
    }

    private function isFillEntryHeader(string $text): bool
    {
        $text = $this->normalizedTableHeader($text);
        if ($text === '' || strlen($text) > 80) {
            return false;
        }
        if (str_contains($text, 'observation') || str_starts_with($text, 'done by') || str_contains($text, 'identification number')) {
            return true;
        }
        if (str_contains($text, 'checked by') || str_contains($text, 'remark') || str_contains($text, 'comment')) {
            return true;
        }
        if (str_contains($text, 'instrument id') || str_contains($text, 'serial no') || str_contains($text, 'model no')) {
            return true;
        }
        if (str_contains($text, 'deviation') || str_contains($text, 'corrective action') || str_contains($text, 'preventive action')) {
            return true;
        }
        if (str_contains($text, 'result') || str_contains($text, 'finding') || str_contains($text, 'evaluation')) {
            return true;
        }

        return preg_match('/\b(sign|signature|date|time)\b/', $text) === 1;
    }

    /**
     * Checks, methodology, and acceptance text from the original table stay locked.
     * Observation, Done by, dates, and sign columns stay editable.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function lockExistingTableContent(array $fields): array
    {
        $contentCols = [];
        $entryCols = [];
        $rowValues = [];

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $location = (string) ($field['location'] ?? '');
            if (preg_match('/^table_(\d+)_row_(\d+)_col_(\d+)$/', $location, $match) !== 1) {
                continue;
            }
            $table = $match[1];
            $row = $match[2];
            $col = (int) $match[3];
            $label = (string) ($field['label'] ?? '');
            $value = (string) ($field['value'] ?? '');
            if ($this->isTemplateContentHeader($label) || $this->isTemplateContentHeader($value)) {
                $contentCols[$table][$col] = true;
            }
            if ($this->isFillEntryHeader($label) || $this->isFillEntryHeader($value)) {
                $entryCols[$table][$col] = true;
            }
            $trimmed = trim($value);
            if ($trimmed !== '') {
                $rowValues[$table][$row][] = $trimmed;
            }
        }

        $bannerRows = [];
        foreach ($rowValues as $table => $rows) {
            foreach ($rows as $row => $values) {
                $unique = array_unique($values);
                $sample = (string) reset($unique);
                if (count($values) >= 2 && count($unique) === 1 && strlen($sample) <= 80) {
                    $bannerRows[$table][$row] = true;
                }
            }
        }

        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }
            $type = (string) ($field['type'] ?? '');
            if (in_array($type, ['approver', 'attachment', 'table_radio'], true) || str_starts_with($type, 'approval_stamp')) {
                continue;
            }
            $location = (string) ($field['location'] ?? '');
            if (preg_match('/^table_(\d+)_row_(\d+)_col_(\d+)$/', $location, $match) !== 1) {
                continue;
            }
            $table = $match[1];
            $row = $match[2];
            $col = (int) $match[3];
            $value = trim((string) ($field['value'] ?? ''));
            if (isset($bannerRows[$table][$row])) {
                $fields[$idx]['locked'] = true;
                continue;
            }
            if ($this->isTemplateContentHeader($value) || $this->isFillEntryHeader($value)) {
                $fields[$idx]['locked'] = true;
                continue;
            }
            if (isset($entryCols[$table][$col]) || $this->isFillEntryHeader((string) ($field['label'] ?? ''))) {
                continue;
            }
            if (isset($contentCols[$table][$col]) && $value !== '') {
                $fields[$idx]['locked'] = true;
            }
        }

        return $fields;
    }

    /**
     * Sync Effective Date to every user-facing display location before DOCX regeneration.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    // Propagates one Effective Date value to all non-revision display cells before /fill.
    private function syncEffectiveDateDisplayFields(array $fields, string $date): array
    {
        if ($date === '') {
            return $fields;
        }

        foreach ($fields as $idx => $field) {
            if (! is_array($field) || ! $this->isEffectiveDateLikeField($field)) {
                continue;
            }

            $loc = (string) ($field['location'] ?? '');
            if ($loc === '' || $this->isRevisionHistoryEffectiveDateLocation($fields, $loc)) {
                continue;
            }

            // Fill empty or textbox Effective Date cells; leave revision-history columns untouched.
            if (trim((string) ($field['value'] ?? '')) === '' || str_starts_with($loc, 'txbx_')) {
                $fields[$idx]['value'] = $date;
            }

            // Allow Python /fill to write user-submitted effective dates into the DOCX.
            $fields[$idx]['locked'] = false;
        }

        return $fields;
    }

    /**
     * Full DOCX regeneration applies stored field values — not parse-time template locks.
     *
     * @param  array<string, mixed>  $field
     */
    // Python /fill always receives locked=false so stored values win over parse-time template locks.
    private function resolveLockedFlagForPythonFill(array $field): bool
    {
        return false;
    }

    /**
     * True when the stored value is just the column header label (TOC/label corruption).
     * Those must not be sent to /fill — the template cell already has the real text.
     */
    private function isHeaderEchoFieldValue(array $field): bool
    {
        $label = strtolower(trim(preg_replace('/\s+/u', ' ', (string) ($field['label'] ?? ''))));
        $value = strtolower(trim(preg_replace('/\s+/u', ' ', (string) ($field['value'] ?? ''))));
        if ($label === '' || $value === '') {
            return false;
        }
        if ($value === $label) {
            return true;
        }
        // e.g. "1. Sl. No."
        return (bool) preg_match('/^\d+\.?\s*' . preg_quote($label, '/') . '$/i', (string) ($field['value'] ?? ''));
    }

    /**
     * True when this field should be omitted from Python /fill (serials + header-echo noise).
     */
    private function shouldSkipFieldForPythonFill(array $field): bool
    {
        $location = (string) ($field['location'] ?? '');
        if (
            (str_contains($location, '_toc_') || str_starts_with($location, 'toc_sdt_'))
            && ! str_ends_with($location, '_title')
        ) {
            return false;
        }
        $type = strtolower(trim((string) ($field['type'] ?? '')));
        if ($type === 'table_sl_no') {
            return true;
        }
        if ($this->isLockedSectionHeadingText((string) ($field['label'] ?? ''))) {
            return true;
        }
        if ($this->isHeaderEchoFieldValue($field)) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, string>  $coverLoc
     * @return array<string, string>
     */
    // Maps form keys to cover cell locations for header prefill during fill submit.
    private function buildCoverHeaderMap(array $coverLoc): array
    {
        $map = [];
        foreach ([
            'facility_section'      => 'facility_section',
            'equipment_name'        => 'equipment_name',
            'equipment_id'          => 'equipment_id',
            'effective_date'        => 'effective_date',
            'document_title'        => 'document_title',
            'document_no'           => 'document_no',
            'protocol_document_no'  => 'document_no',
            'reference_protocol_no' => 'reference_protocol_no',
        ] as $formKey => $coverKey) {
            if (! empty($coverLoc[$coverKey])) {
                $map[$formKey] = $coverLoc[$coverKey];
            }
        }

        // Always keep synthetic header keys used by the Lupin wrap header.
        // Do NOT default Document No. onto header_0_row_4_col_2 — on Att12 that
        // cell is Facility / Section and would overwrite the wrong letterhead value.
        if (empty($map['reference_protocol_no'])) {
            $map['reference_protocol_no'] = 'header_ref_protocol';
        }

        // IOQ protocol cover uses a separate blade header row for doc no / version.
        if (($coverLoc['document_no'] ?? '') === 'header_0_row_4_col_2') {
            $map['header_doc_number'] = 'header_0_row_4_col_2';
            $map['header_version']    = 'header_0_row_4_col_4';
            $map['version']           = 'header_0_row_4_col_4';
        }

        return $map;
    }

    /**
     * Drop legacy IOQ header inputs that do not exist in this template's parsed fields.
     *
     * @param  array<string, mixed>  $formFields
     * @param  array<int, array<string, mixed>>  $importFields
     * @param  array<string, string>  $coverLoc
     * @return array<string, mixed>
     */
    // Strips header form keys that do not exist in this template's parsed field list.
    private function filterInvalidHeaderFormFields(array $formFields, array $importFields, array $coverLoc): array
    {
        $valid = array_values($coverLoc);

        foreach ($importFields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $loc = (string) ($field['location'] ?? '');
            if ($loc === '') {
                continue;
            }
            if (str_starts_with($loc, 'header_')) {
                $isCover = ($field['type'] ?? '') === 'protocol_cover'
                    || ($field['type'] ?? '') === 'header_cell'
                    || ($field['cover_field'] ?? '') !== '';
                if ($isCover) {
                    $valid[] = $loc;
                }
                continue;
            }
            $valid[] = $loc;
        }

        $valid = array_values(array_unique($valid));

        // Remove stale IOQ header inputs that were never parsed from this DOCX.
        foreach (array_keys($formFields) as $key) {
            if (! is_string($key)) {
                continue;
            }
            if (str_starts_with($key, 'header_') && ! in_array($key, $valid, true)) {
                // Keep wrap-header synthetic keys so Document Number / Reference Protocol persist.
                if (in_array($key, ['header_0_row_4_col_2', 'header_ref_protocol', 'header_0_row_2_col_4', 'header_0_row_3_col_4'], true)) {
                    continue;
                }
                unset($formFields[$key]);
            }
            if ($key === 'header_ref_protocol' && ! in_array($key, $valid, true)) {
                // Do not drop — used by report show wrap header even when not in Word.
                continue;
            }
        }

        return $formFields;
    }

    /**
     * Remove legacy IOQ header locations that were incorrectly stored on annex templates.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    // Removes spurious header_* import fields that annex templates inherited from IOQ layouts.
    private function pruneSpuriousHeaderImportFields(array $fields, array $coverLoc): array
    {
        return array_values(array_filter($fields, function ($field) use ($coverLoc) {
            if (! is_array($field)) {
                return false;
            }
            $loc = (string) ($field['location'] ?? '');
            if ($loc === '' || ! str_starts_with($loc, 'header_')) {
                return true;
            }
            if (($field['type'] ?? '') === 'protocol_cover' || ($field['type'] ?? '') === 'header_cell') {
                return true;
            }
            if (($field['cover_field'] ?? '') !== '') {
                return true;
            }

            return in_array($loc, array_values($coverLoc), true);
        }));
    }

    // Resolves document title from cover field value or persisted report data.
    private function resolveDocumentTitle(Report $report, array $fields): string
    {
        $loc = $this->findCoverFieldLocation($fields, 'document_title');
        if ($loc !== null) {
            foreach ($fields as $field) {
                if (is_array($field) && ($field['location'] ?? '') === $loc) {
                    $val = trim((string) ($field['value'] ?? ''));
                    if ($val !== '') {
                        return $val;
                    }
                }
            }
        }

        $data = $report->data ?? [];
        if (is_string($data)) {
            $data = json_decode($data, true) ?? [];
        }

        return is_array($data) ? (string) ($data['document_title'] ?? '') : '';
    }

    /**
     * Read a cover_field cell value from parsed DOCX fields.
     *
     * @param  array<int, array<string, mixed>>  $fields
     */
    private function extractCoverTextValue(array $fields, string $coverKey): string
    {
        $fallback = '';
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            if ((string) ($field['cover_field'] ?? '') !== $coverKey) {
                continue;
            }
            $val = trim((string) ($field['value'] ?? ''));
            if ($val === '') {
                continue;
            }
            $loc = (string) ($field['location'] ?? '');
            // Prefer letterhead cells — ignore body "Equipment Name / ID" columns.
            if (str_starts_with($loc, 'header_') || str_starts_with($loc, 'txbx_')) {
                return $val;
            }
            if ($fallback === '') {
                $fallback = $val;
            }
        }

        return $fallback;
    }

    /**
     * Pull Document Number / Reference Protocol text from parsed labels when cover_field is missing.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array{document_number: string, reference_protocol_no: string}
     */
    private function extractProtocolIdentityFromFields(array $fields): array
    {
        $fields = $this->upgradeProtocolCoverFieldMetadata($fields);
        $docNo = $this->extractCoverTextValue($fields, 'document_no');
        $refNo = $this->extractCoverTextValue($fields, 'reference_protocol_no');

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $label = strtolower(trim((string) ($field['label'] ?? '')));
            $label = trim(preg_replace('/[^a-z0-9]+/', ' ', $label) ?? $label);
            $value = trim((string) ($field['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            if ($refNo === '' && (
                str_contains($label, 'reference protocol')
                || str_contains($label, 'reference document')
                || str_contains($label, 'ref protocol')
            )) {
                $refNo = $value;
                continue;
            }
            if ($docNo === ''
                && (str_contains($label, 'document number') || preg_match('/\bdocument\s*no\b/', $label))
                && ! str_contains($label, 'title')
                && ! str_contains($label, 'reference')
            ) {
                $docNo = $value;
            }
        }

        return [
            'document_number'       => $docNo,
            'reference_protocol_no' => $refNo,
        ];
    }

    /**
     * Textual Document Number for fill / show (not the documents master FK).
     *
     * @param  array<int, array<string, mixed>>  $fields
     */
    private function resolveProtocolDocumentNumber(Report $report, array $fields): string
    {
        $fields = $this->upgradeProtocolCoverFieldMetadata($fields);
        $docLoc = $this->findCoverFieldLocation($fields, 'document_no');
        $fromFields = $this->extractCoverTextValue($fields, 'document_no');

        // Att6: Document No. is row_5_col_2 and is often blank in Word.
        // Never fill it from report.data / REF. DOCUMENT NO. leftovers.
        if ($docLoc === 'header_0_row_5_col_2') {
            return $fromFields;
        }

        $data = $report->data ?? [];
        if (is_string($data)) {
            $data = json_decode($data, true) ?? [];
        }
        if (! is_array($data)) {
            $data = [];
        }

        $fromData = trim((string) ($data['document_number'] ?? $data['protocol_document_no'] ?? ''));
        if ($fromData !== '') {
            return $fromData;
        }

        if ($fromFields !== '') {
            return $fromFields;
        }

        $identity = $this->extractProtocolIdentityFromFields($fields);
        if ($identity['document_number'] !== '') {
            return $identity['document_number'];
        }

        foreach (['header_0_row_5_col_2', 'header_0_row_4_col_2'] as $hdrLoc) {
            if ($hdrLoc === 'header_0_row_4_col_2') {
                foreach ($fields as $field) {
                    if (! is_array($field)) {
                        continue;
                    }
                    if ((string) ($field['location'] ?? '') !== $hdrLoc) {
                        continue;
                    }
                    if ((string) ($field['cover_field'] ?? '') === 'reference_protocol_no') {
                        continue 2;
                    }
                }
            }
            $legacy = trim($this->fieldValueByLocation($fields, $hdrLoc, ''));
        if ($legacy !== '' && ! ctype_digit($legacy)) {
            return $legacy;
            }
        }

        return '';
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     */
    private function resolveReferenceProtocolNo(Report $report, array $fields): string
    {
        $data = $report->data ?? [];
        if (is_string($data)) {
            $data = json_decode($data, true) ?? [];
        }
        if (! is_array($data)) {
            $data = [];
        }

        $fromData = trim((string) ($data['reference_protocol_no'] ?? ''));
        if ($fromData !== '') {
            return $fromData;
        }

        $fromFields = $this->extractCoverTextValue($fields, 'reference_protocol_no');
        if ($fromFields !== '') {
            return $fromFields;
        }

        $identity = $this->extractProtocolIdentityFromFields($fields);
        if ($identity['reference_protocol_no'] !== '') {
            return $identity['reference_protocol_no'];
        }

        return trim($this->fieldValueByLocation($fields, 'header_ref_protocol', ''));
    }

    /**
     * Ensure wrap-header locations exist so draft/submit persist Document Number / Reference Protocol.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function ensureProtocolIdentityHeaderFields(array $fields, string $documentNumber, string $referenceProtocolNo): array
    {
        $byLoc = [];
        foreach ($fields as $idx => $field) {
            if (is_array($field) && ($field['location'] ?? '') !== '') {
                $byLoc[(string) $field['location']] = $idx;
            }
        }

        $ensure = function (string $location, string $label, string $coverKey, string $value) use (&$fields, &$byLoc): void {
            if (isset($byLoc[$location])) {
                $idx = $byLoc[$location];
                $existingCover = (string) ($fields[$idx]['cover_field'] ?? '');
                // Never retag Att6 REF. DOCUMENT (reference_protocol_no) as document_no.
                if (
                    $existingCover !== ''
                    && $existingCover !== $coverKey
                    && in_array($existingCover, ['reference_protocol_no', 'equipment_id', 'equipment_name', 'facility_section', 'version'], true)
                ) {
                    return;
                }
                if ($value !== '') {
                $fields[$idx]['value'] = $value;
                }
                $fields[$idx]['cover_field'] = $fields[$idx]['cover_field'] ?? $coverKey;
                $fields[$idx]['locked'] = false;

                return;
            }
            $fields[] = [
                'label'       => $label,
                'location'    => $location,
                'value'       => $value,
                'locked'      => false,
                'type'        => 'protocol_cover',
                'cover_field' => $coverKey,
            ];
            $byLoc[$location] = count($fields) - 1;
        };

        // Prefer real Document No. cell (Att6 = header_0_row_5_col_2), never REF DOCUMENT.
        $docLoc = $this->findCoverFieldLocation($fields, 'document_no');
        if ($docLoc === 'header_0_row_4_col_2' && isset($byLoc['header_0_row_5_col_2'])) {
            $docLoc = 'header_0_row_5_col_2';
        }
        if (
            $docLoc === null
            || $docLoc === ''
        ) {
            if (isset($byLoc['header_0_row_5_col_2'])) {
                $docLoc = 'header_0_row_5_col_2';
            } elseif (
                isset($byLoc['header_0_row_4_col_2'])
                && (string) ($fields[$byLoc['header_0_row_4_col_2']]['cover_field'] ?? '') !== 'reference_protocol_no'
            ) {
                $docLoc = 'header_0_row_4_col_2';
            }
        }
        if (is_string($docLoc) && $docLoc !== '') {
            $ensure($docLoc, 'Document No.', 'document_no', $documentNumber);
        }

        $refLoc = $this->findCoverFieldLocation($fields, 'reference_protocol_no');
        if (! is_string($refLoc) || $refLoc === '') {
            $refLoc = isset($byLoc['header_0_row_4_col_2'])
                && (string) ($fields[$byLoc['header_0_row_4_col_2']]['cover_field'] ?? '') === 'reference_protocol_no'
                ? 'header_0_row_4_col_2'
                : 'header_ref_protocol';
        }
        $ensure($refLoc, 'Reference Document No.', 'reference_protocol_no', $referenceProtocolNo);

        return $fields;
    }

    /**
     * Merge request Document Number / Reference Protocol into report data + form field map.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $formFields
     * @param  array<int, array<string, mixed>>  $importFields
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function mergeProtocolIdentityIntoDataAndFields(
        Request $request,
        array $data,
        array $formFields,
        array $importFields
    ): array {
        $docLoc = $this->findCoverFieldLocation($importFields, 'document_no');
        $refLoc = $this->findCoverFieldLocation($importFields, 'reference_protocol_no');
        // Att12: label-only fallback when cover_field was stripped by older normalizeImportFields.
        if ($refLoc === null || $refLoc === '') {
            $refLoc = $this->findReferenceDocumentLetterheadLocation($importFields);
        }

        $docNo = trim((string) $request->input('protocol_document_no', ''));
        if ($docNo === '') {
            $docNo = trim((string) ($formFields['protocol_document_no'] ?? ''));
        }
        // Never treat Facility / Reference Document letterhead cells as Document No.
        if ($docNo === '' && is_string($docLoc) && $docLoc !== '' && ! $this->isFacilityOrReferenceLetterheadLocation($importFields, $docLoc)) {
            $docNo = trim((string) ($formFields[$docLoc] ?? ''));
        }
        if ($docNo === '' && ! $request->has('protocol_document_no')) {
            $docNo = trim((string) ($data['document_number'] ?? ''));
        }

        $refNo = trim((string) $request->input('reference_protocol_no', ''));
        if ($refNo === '') {
            $refNo = trim((string) ($formFields['reference_protocol_no'] ?? $formFields['header_ref_protocol'] ?? ''));
        }
        // Keep value typed into the letterhead "Reference Document No." cell.
        if ($refNo === '' && is_string($refLoc) && $refLoc !== '') {
            $refNo = trim((string) ($formFields[$refLoc] ?? ''));
        }
        if ($refNo === '' && ! $request->has('reference_protocol_no') && ! array_key_exists('header_ref_protocol', $formFields)) {
            $refNo = trim((string) ($data['reference_protocol_no'] ?? ''));
        }

        if ($docNo === '') {
            $docNo = $this->extractCoverTextValue($importFields, 'document_no');
        }
        if ($refNo === '') {
            $refNo = $this->extractCoverTextValue($importFields, 'reference_protocol_no');
        }

        $data['document_number'] = $docNo;
        $data['reference_protocol_no'] = $refNo;

        $formFields['protocol_document_no'] = $docNo;
        $formFields['reference_protocol_no'] = $refNo;
        $formFields['header_ref_protocol'] = $refNo;

        if (is_string($docLoc) && $docLoc !== '' && $docNo !== ''
            && ! $this->isFacilityOrReferenceLetterheadLocation($importFields, $docLoc)
        ) {
            $formFields[$docLoc] = $docNo;
        }
        if (is_string($refLoc) && $refLoc !== '') {
            // Never blank out a letterhead value the user already typed.
            if ($refNo !== '' || trim((string) ($formFields[$refLoc] ?? '')) === '') {
            $formFields[$refLoc] = $refNo;
            } elseif ($refNo === '' && trim((string) ($formFields[$refLoc] ?? '')) !== '') {
                $refNo = trim((string) $formFields[$refLoc]);
                $data['reference_protocol_no'] = $refNo;
                $formFields['reference_protocol_no'] = $refNo;
                $formFields['header_ref_protocol'] = $refNo;
            }
        }

        return [$data, $formFields];
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     */
    private function findReferenceDocumentLetterheadLocation(array $fields): ?string
    {
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $loc = (string) ($field['location'] ?? '');
            if ($loc === '' || ! str_starts_with($loc, 'header_')) {
                continue;
            }
            $label = strtolower(trim((string) ($field['label'] ?? '')));
            $label = trim(preg_replace('/[^a-z0-9]+/', ' ', $label) ?? $label);
            if (
                str_contains($label, 'reference document')
                || str_contains($label, 'ref document')
                || (str_contains($label, 'reference') && str_contains($label, 'document'))
            ) {
                return $loc;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     */
    private function isFacilityOrReferenceLetterheadLocation(array $fields, string $location): bool
    {
        foreach ($fields as $field) {
            if (! is_array($field) || (string) ($field['location'] ?? '') !== $location) {
                continue;
            }
            $cover = strtolower(trim((string) ($field['cover_field'] ?? '')));
            if (in_array($cover, ['facility_section', 'reference_protocol_no'], true)) {
                return true;
            }
            $label = strtolower(trim((string) ($field['label'] ?? '')));
            $label = trim(preg_replace('/[^a-z0-9]+/', ' ', $label) ?? $label);
            if (
                str_contains($label, 'facility')
                || str_contains($label, 'reference document')
                || str_contains($label, 'ref document')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<string, string>
     */
    // Builds cover_field key to DOCX location map for form binding.
    private function buildCoverFieldLocationMap(array $fields): array
    {
        $map = [];
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $key = (string) ($field['cover_field'] ?? '');
            $loc = (string) ($field['location'] ?? '');
            if ($key === '' || $loc === '') {
                continue;
            }
            // Prefer Att6 Document No. at row_5 over legacy HVAC row_4 (often REF DOCUMENT).
            // Do NOT steal Att4 Facility (also row_5_col_2) as Document No.
            if (! isset($map[$key])) {
                $map[$key] = $loc;
            } elseif (
                str_starts_with($loc, 'header_')
                && ! str_starts_with((string) $map[$key], 'header_')
            ) {
                // Letterhead always wins over body table cells (PM Equipment Name/ID).
                $map[$key] = $loc;
            } elseif (
                $key === 'document_no'
                && $loc === 'header_0_row_5_col_2'
                && ($map[$key] ?? '') !== 'header_0_row_2_col_2'
            ) {
                $map[$key] = $loc;
            } elseif (
                $key === 'reference_protocol_no'
                && in_array($loc, ['header_0_row_4_col_2', 'header_0_row_2_col_2'], true)
            ) {
                $map[$key] = $loc;
            } elseif (
                $key === 'document_no'
                && $loc === 'header_0_row_2_col_2'
            ) {
                // Att4 Document No. (Version No.) wins over a mis-tagged row_5 cell.
                $map[$key] = $loc;
            }
        }

        return $map;
    }

    /**
     * Resolves Effective Date for the fill form (Y-m-d):
     * - Before pre-approval is complete: blank.
     * - After pre/post approvals: date of the latest approved step.
     */
    private function resolveEffectiveDate(Report $report, array $fields): string
    {
        try {
            $stamp = $this->resolveLastApprovalDateTime($report);
            if ($stamp !== '') {
                // Form date inputs need Y-m-d; keep time only in DOCX via formatEffectiveDateForDocx path.
                try {
                    return \Carbon\Carbon::parse($stamp)->timezone('Asia/Kolkata')->format('Y-m-d');
                } catch (\Throwable $e) {
                    return $this->normalizeEffectiveDateForForm($stamp);
                }
            }

        $data = $report->data ?? [];
        if (is_string($data)) {
            $data = json_decode($data, true) ?? [];
        }
        if (is_array($data) && ! empty($data['effective_date'])) {
            return $this->normalizeEffectiveDateForForm((string) $data['effective_date']);
            }
        } catch (\Throwable $e) {
            // Ignore step query issues
        }

        return '';
    }

    /**
     * Latest approval day (post preferred, else pre) as d-m-Y for the DOCX cover Effective Date.
     */
    private function resolveLastApprovalDateTime(Report $report): string
    {
        $report->loadMissing('approvalSteps');

        $lastPost = $report->approvalSteps
            ->where('stage', 'post')
            ->filter(fn ($s) => $s->approved_at !== null)
            ->sortByDesc(fn ($s) => $s->approved_at?->getTimestamp() ?? 0)
            ->first();
        if ($lastPost && $lastPost->approved_at) {
            return $this->formatEffectiveDateForDocx(
                $lastPost->approved_at->timezone('Asia/Kolkata')->format('Y-m-d')
            );
        }

        $lastPre = $report->approvalSteps
            ->where('stage', 'pre')
            ->filter(fn ($s) => $s->approved_at !== null)
            ->sortByDesc(fn ($s) => $s->approved_at?->getTimestamp() ?? 0)
            ->first();
        if ($lastPre && $lastPre->approved_at) {
            return $this->formatEffectiveDateForDocx(
                $lastPre->approved_at->timezone('Asia/Kolkata')->format('Y-m-d')
            );
        }

        return '';
    }

    /**
     * Finds the effective date of the last COMPLETED / APPROVED revision in Revision History.
     * When drafting a new revision, the last row in the grid represents the current draft,
     * so the preceding row represents the latest historically approved revision.
     */
    private function findLatestRevisionHistoryEffectiveDate(array $fields): ?string
    {
        $revisionTables = [];
        $tableLabels = [];
            foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $loc = (string) ($field['location'] ?? '');
            if (! preg_match('/^table_(\d+)_row_(\d+)_col_(\d+)$/', $loc, $m)) {
                continue;
            }
            $t = (int) $m[1];
            $label = strtolower(trim((string) ($field['label'] ?? '')));
            $tableLabels[$t][$label] = true;
        }

        foreach ($tableLabels as $t => $lbls) {
            $hasRev = false;
            $hasEff = false;
            foreach (array_keys($lbls) as $l) {
                if (str_contains($l, 'revision') || str_contains($l, 'rev no') || str_contains($l, 'rev. no')) {
                    $hasRev = true;
                }
                if (str_contains($l, 'effective')) {
                    $hasEff = true;
                }
            }
            if ($hasRev && $hasEff) {
                $revisionTables[] = $t;
            }
        }

        foreach ($revisionTables as $tableNum) {
            $rowMap = [];
            foreach ($fields as $field) {
                if (! is_array($field)) {
                    continue;
                }
                $loc = (string) ($field['location'] ?? '');
                if (! preg_match('/^table_' . $tableNum . '_row_(\d+)_col_(\d+)$/', $loc, $m)) {
                    continue;
                }
                $r = (int) $m[1];
                $label = strtolower(trim((string) ($field['label'] ?? '')));
                if (str_contains($label, 'effective')) {
                    $val = trim((string) ($field['value'] ?? ''));
                    $rowMap[$r] = $val;
                }
            }

            if (count($rowMap) === 0) {
                continue;
            }

            ksort($rowMap);
            $maxRow = max(array_keys($rowMap));

            // The last row is the current unapproved revision being drafted.
            // Take the latest filled date from prior rows.
            $priorDates = [];
            foreach ($rowMap as $r => $val) {
                if ($r < $maxRow && $val !== '') {
                    $priorDates[$r] = $val;
                }
            }
            if (! empty($priorDates)) {
                return end($priorDates);
            }

            $all = array_filter($rowMap, fn ($v) => $v !== '');
            if (! empty($all)) {
                return end($all);
            }
        }

        return null;
    }

    private function findQualificationHistoryApprovedDate(array $fields): ?string
    {
        $dates = [];
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $val = trim((string) ($field['value'] ?? ''));
            if ($val === '') {
                continue;
            }
            if (preg_match_all('/(?:completed\s+on|approval\s+date[:\s]*)\s*(\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{2,4})/i', $val, $matches)) {
                foreach ($matches[1] as $d) {
                    $dates[] = $d;
                }
            }
        }
        if (empty($dates)) {
            return null;
        }

        return end($dates);
    }

    /** HTML date input uses Y-m-d; DOCX cover cells use d-m-Y. */
    // Converts d-m-Y from DOCX into Y-m-d for HTML date inputs.
    private function normalizeEffectiveDateForForm(string $date): string
    {
        $date = trim($date);
        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $date, $m)) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }

        return $date;
    }

    // Converts Y-m-d from form input into d-m-Y for DOCX cover cell display.
    // Effective Date is date-only (no time) per original NEQ/IOQ cover cells.
    private function formatEffectiveDateForDocx(string $date): string
    {
        $date = trim($date);
        if ($date === '') {
            return '';
        }
        // Already stamped with optional time — keep the date part only.
        if (preg_match('/^(\d{2}-\d{2}-\d{4})(?:\s+\d{1,2}:\d{2}(?:\s*(?:AM|PM))?)?$/i', $date, $m)) {
            return $m[1];
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        // Y-m-d H:i:s (or with timezone offset) → d-m-Y (date only).
        try {
            $parsed = \Carbon\Carbon::parse($date)->timezone('Asia/Kolkata');

            return $parsed->format('d-m-Y');
        } catch (\Throwable $e) {
        return $date;
        }
    }

    /**
     * Signature / approval stamps: d-m-Y H:i (24-hour, no AM/PM).
     */
    private function formatSignatureDateTimeForDocx(\Carbon\CarbonInterface|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if ($value instanceof \Carbon\CarbonInterface) {
            return $value->timezone('Asia/Kolkata')->format('d-m-Y H:i');
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            return '';
        }
        if (preg_match('/^(\d{2}-\d{2}-\d{4})\s+(\d{1,2}):(\d{2})\s*(AM|PM)$/i', $raw, $m)) {
            $hour = (int) $m[2];
            $min = $m[3];
            $ampm = strtoupper($m[4]);
            if ($ampm === 'PM' && $hour < 12) {
                $hour += 12;
            }
            if ($ampm === 'AM' && $hour === 12) {
                $hour = 0;
            }

            return sprintf('%s %02d:%s', $m[1], $hour, $min);
        }
        if (preg_match('/^\d{2}-\d{2}-\d{4}\s+\d{2}:\d{2}$/', $raw)) {
            return $raw;
        }
        try {
            return \Carbon\Carbon::parse($raw)->timezone('Asia/Kolkata')->format('d-m-Y H:i');
        } catch (\Throwable $e) {
            return $raw;
        }
    }

    /**
     * Guarantee Effective Date is present on cover fields before DOCX regeneration.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    // Ensures Effective Date exists on cover fields before Python regenerates the filled DOCX.
    private function ensureEffectiveDateCoverFieldValues(Report $report, array $fields): array
    {
        $upgraded = $this->upgradeProtocolCoverFieldMetadata($fields);

        $data = $report->data ?? [];
        if (is_string($data)) {
            $data = json_decode($data, true) ?? [];
        }

        $date = '';
        // Prefer last approver timestamp (with time) once an approval stage is complete.
        if (in_array($report->status, ['approved', 'pre_approved'], true)) {
            $date = $this->resolveLastApprovalDateTime($report);
        }
        if ($date === '' && is_array($data) && ! empty($data['effective_date'])) {
            $date = $this->formatEffectiveDateForDocx((string) $data['effective_date']);
        }
        if ($date === '') {
            $primaryLoc = $this->findPrimaryEffectiveDateLocation($upgraded);
            if ($primaryLoc !== null) {
                foreach ($fields as $field) {
                    if (is_array($field) && ($field['location'] ?? '') === $primaryLoc) {
                        $date = trim((string) ($field['value'] ?? ''));
                        break;
                    }
                }
            }
        }
        if ($date === '') {
            $resolved = $this->resolveEffectiveDate($report, $fields);
            if ($resolved !== '') {
                $date = $this->formatEffectiveDateForDocx($resolved);
            }
        }

        $fields = $this->syncEffectiveDateDisplayFields($fields, $date);

        $primaryLoc = $this->findPrimaryEffectiveDateLocation($this->upgradeProtocolCoverFieldMetadata($fields));
        if ($primaryLoc === null) {
            return $fields;
        }

        $found = false;
        foreach ($fields as $idx => $field) {
            if (! is_array($field) || ($field['location'] ?? '') !== $primaryLoc) {
                continue;
            }
            $found                   = true;
            $fields[$idx]['value']   = $date;
            $fields[$idx]['locked']  = false;
            $fields[$idx]['type']    = $fields[$idx]['type'] ?? 'protocol_cover';
            $fields[$idx]['cover_field'] = 'effective_date';
            break;
        }

        // Inject synthetic cover field when parser found a location but no field record exists.
        if (! $found) {
            $fields[] = [
                'label'       => 'Effective Date',
                'location'    => $primaryLoc,
                'value'       => $date,
                'locked'      => false,
                'type'        => 'protocol_cover',
                'widget'      => 'date',
                'cover_field' => 'effective_date',
            ];
        }

        return $fields;
    }

    /**
     * Back-fill paragraph Yes/No fields missing from reports parsed before paragraph_radio support.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    // Adds missing paragraph_radio field for legacy training templates parsed before widget support.
    private function upgradeParagraphYesNoFields(array $fields): array
    {
        foreach ($fields as $field) {
            if (
                is_array($field)
                && ($field['type'] ?? '') === 'paragraph_radio'
                && ($field['location'] ?? '') === 'para_1'
            ) {
                return $fields;
            }
        }

        $isTrainingTemplate = false;
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            if (str_contains(strtolower((string) ($field['label'] ?? '')), 'training imparted by')) {
                $isTrainingTemplate = true;
                break;
            }
        }

        if (! $isTrainingTemplate) {
            return $fields;
        }

        $fields[] = [
            'label'           => 'Training imparted',
            'value'           => '',
            'locked'          => false,
            'draft_editable'  => true,
            'type'            => 'paragraph_radio',
            'location'        => 'para_1',
            'widget'          => 'radio',
        ];

        return $fields;
    }

    /**
     * Stamped onto the document once the final pre-approver signs.
     * The last pre-approval step date becomes the protocol's Effective Date.
     */
    public function syncEffectiveDateOnPreApprovalComplete(Report $report, string $effectiveDate): void
    {
        $fields = $report->docx_import_fields;
        if (! is_array($fields)) {
            return;
        }

        $formattedDocxDate = $this->formatEffectiveDateForDocx($effectiveDate);
        $primaryLoc = $this->findPrimaryEffectiveDateLocation($this->upgradeProtocolCoverFieldMetadata($fields));

        $changed = false;
        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }
            $loc = (string) ($field['location'] ?? '');
            if ($loc === '' || $this->isRevisionHistoryEffectiveDateLocation($fields, $loc)) {
                continue;
            }
            if ($loc === $primaryLoc || $this->isEffectiveDateLikeField($field)) {
                $fields[$idx]['value'] = $formattedDocxDate;
                $changed = true;
            }
        }

        if ($changed) {
            $report->docx_import_fields = $fields;
            $report->save();
        }

        if ($report->docx_import_path) {
            $this->flushDocxPreviewCache((string) $report->docx_import_path, $report->id);
        }
    }

    /**
     * Repair missing approver_role on legacy Approved By / Checked By rows.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    // Back-fills missing approver_role on legacy Checked By / Approved By signature table rows.
    private function upgradeSignatureApproverRoles(array $fields, string $fileId = ''): array
    {
        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }
            // Prepared By / Checked By name cells stay a user dropdown on the fill form.
            if (strtolower((string) ($field['type'] ?? '')) !== 'approver') {
                continue;
            }
            $fields[$idx]['locked'] = false;
            $fields[$idx]['widget'] = 'approver';
        }

        $byTable = [];
        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }
            $loc = (string) ($field['location'] ?? '');
            if (! preg_match('/^table_(\d+)_row_(\d+)_col_(\d+)$/', $loc, $m)) {
                continue;
            }
            $byTable[$m[1]][(int) $m[2]][(int) $m[3]] = [
                'idx'   => $idx,
                'value' => (string) ($field['value'] ?? ''),
                'type'  => (string) ($field['type'] ?? ''),
                'role'  => (string) ($field['approver_role'] ?? ''),
            ];
        }

        // Department labels live in Word col_0 (often not stored as fill fields).
        $docxDeptMap = $fileId !== '' ? $this->loadSignatureTableDeptMapFromDocx($fileId) : [];

        foreach ($byTable as $tableNum => $rows) {
            $title = '';
            foreach ($rows[0] ?? [] as $col) {
                $title .= ' ' . strtolower($col['value'] ?? '');
            }
            $title = trim($title);
            $docxTitle = strtolower(trim((string) ($docxDeptMap[(string) $tableNum]['title'] ?? '')));
            if ($docxTitle !== '') {
                $title = trim($title . ' ' . $docxTitle);
            }

            $titleKind = '';
            if (str_contains($title, 'prepared by')) {
                $titleKind = 'prepared by';
            } elseif (str_contains($title, 'checked by')) {
                $titleKind = 'checked by';
            } elseif (str_contains($title, 'approved by')) {
                $titleKind = 'approved by';
            }

            // Department | Name | Signature & Date tables: role from department label.
            if ($titleKind !== '') {
                // Att14 Report Approval: Name/Designation is col_0 (not Veeva col_1).
                $nameCol = $this->resolveNeqApprovalNameCol($fields, $rows);
                $dataRowNums = [];
                foreach ($rows as $rowNum => $cols) {
                    // row 0 = title, row 1 = Name/Signature/Date headers, row 2+ = signers.
                    // Counting row 1 invents a fake 2nd Approved By (Quality) on Att14.
                    if ((int) $rowNum < 2 || ! is_array($cols)) {
                        continue;
                    }
                    if ($this->isNeqApprovalColumnHeaderRow($fields, $cols)) {
                        continue;
                    }
                    $dataRowNums[] = (int) $rowNum;
                }
                sort($dataRowNums);
                $rowCount = count($dataRowNums);
                foreach ($dataRowNums as $offset => $rowNum) {
                    $deptLabel = trim((string) ($docxDeptMap[(string) $tableNum]['rows'][(string) $rowNum] ?? ''));
                    if ($deptLabel === '') {
                        foreach ([0, 1, 2] as $deptCol) {
                            if ($deptCol === $nameCol) {
                                continue;
                            }
                            $deptCell = $rows[$rowNum][$deptCol] ?? null;
                            if (! is_array($deptCell)) {
                                continue;
                            }
                            $candidate = trim((string) ($deptCell['value'] ?? ''));
                            $candidateType = strtolower((string) ($deptCell['type'] ?? ''));
                            if ($candidate === '' || in_array($candidateType, ['approver', 'approval_stamp_name'], true)) {
                                continue;
                            }
                            if (str_contains(strtolower($candidate), 'signature')) {
                                continue;
                            }
                            $deptLabel = $candidate;
                            break;
                        }
                    }
                    if ($deptLabel === '' && isset($rows[$rowNum][0]['value'])) {
                        $deptLabel = trim((string) $rows[$rowNum][0]['value']);
                    }
                    $role = $this->neqApprovalRoleForRow($titleKind, $deptLabel, $offset, $rowCount);
                    if ($role === '') {
                        continue;
                    }
                    $this->assignApproverRoleForced($fields, $rows, $rowNum, $role, $nameCol);
                    $this->propagateApproverRoleToRowStampCells($fields, (string) $tableNum, $rowNum, $role);
                }
                continue;
            }

            $colZeroApprovers = $this->collectColZeroApproverRows($rows);
            if ($colZeroApprovers !== []) {
                // Multi-row department table: map each row to a POST approver role by position.
                if (count($colZeroApprovers) >= 3) {
                    $checkedRoles = [
                        2 => 'post_checked_by_engineering_name',
                        3 => 'post_checked_by_production_name',
                        4 => 'post_checked_by_validation_name',
                        5 => 'post_checked_by_quality_name',
                    ];
                    foreach ($checkedRoles as $rowNum => $role) {
                        $this->assignApproverRoleIfEmpty($fields, $rows, $rowNum, $role, 0);
                    }
                    continue;
                }

                // Single approver column layout: infer role from table title text.
                // One Approved By row (Att14) = User/Validation — never invent Quality.
                if (count($colZeroApprovers) === 1) {
                    $rowNum = array_key_first($colZeroApprovers);
                    $existingRole = (string) ($colZeroApprovers[$rowNum]['role'] ?? '');
                    if ($existingRole === '') {
                        if (str_contains($title, 'approved by')) {
                            $this->assignApproverRoleIfEmpty($fields, $rows, (int) $rowNum, 'post_approved_by_user_name', 0);
                        } else {
                            $this->assignApproverRoleIfEmpty($fields, $rows, (int) $rowNum, 'post_prepared_by_name', 0);
                        }
                    }
                    continue;
                }
            }
        }

        $fields = $this->demoteSignatureDateApproverWidgets($fields);

        return $this->placeCheckedByQualityBeforeValidation($fields);
    }

    /**
     * Prefer col_0 "Name/Designation" (Att14) over col_1 Veeva "Name".
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @param  array<int, array<int, array{idx?: int, value?: string, type?: string, role?: string}>>  $rows
     */
    private function resolveNeqApprovalNameCol(array $fields, array $rows): int
    {
        foreach ($rows as $rowNum => $cols) {
            if ((int) $rowNum < 1 || ! is_array($cols)) {
                continue;
            }
            foreach ([0, 1] as $colNum) {
                $cell = $cols[$colNum] ?? null;
                if (! is_array($cell) || ! isset($cell['idx'])) {
                    continue;
                }
                $f = $fields[$cell['idx']] ?? null;
                if (! is_array($f)) {
                    continue;
                }
                $label = strtolower(trim((string) (($f['label'] ?? '') ?: ($cell['value'] ?? ''))));
                if (str_contains($label, 'name') && str_contains($label, 'designation')) {
                    return $colNum;
                }
            }
        }
        foreach ($rows as $rowNum => $cols) {
            if ((int) $rowNum < 1 || ! is_array($cols)) {
                continue;
            }
            if (isset($cols[0]) && in_array(($cols[0]['type'] ?? ''), ['approver', 'approval_stamp_name'], true)) {
                return 0;
            }
            if (isset($cols[1]) && in_array(($cols[1]['type'] ?? ''), ['approver', 'approval_stamp_name'], true)) {
                return 1;
            }
        }

        return 0;
    }

    /**
     * True for the Name / Signature / Date header row inside Prepared/Checked/Approved tables.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @param  array<int, array{idx?: int, value?: string, type?: string, role?: string}|int>  $rowCols
     */
    private function isNeqApprovalColumnHeaderRow(array $fields, array $rowCols): bool
    {
        // Use cell VALUES only — data rows often inherit labels like "Name/Designation"
        // / "Signature" / "Date" from the column header, which must not look like a header.
        $blob = '';
        foreach ($rowCols as $col) {
            $value = '';
            if (is_array($col) && isset($col['idx'])) {
                $f = $fields[$col['idx']] ?? null;
                $value = is_array($f)
                    ? (string) (($f['value'] ?? '') !== '' ? $f['value'] : ($col['value'] ?? ''))
                    : (string) ($col['value'] ?? '');
            } elseif (is_int($col)) {
                $f = $fields[$col] ?? null;
                if (! is_array($f)) {
                    continue;
                }
                $value = (string) ($f['value'] ?? '');
            }
            $blob .= ' ' . strtolower(trim($value));
        }
        $blob = trim(preg_replace('/\s+/', ' ', $blob) ?? '');
        if ($blob === '') {
            return false;
        }

        return str_contains($blob, 'name')
            && (str_contains($blob, 'signature') || str_contains($blob, 'date'));
    }

    /**
     * Signature / Date cells must never be type=approver (that invents fake required roles).
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function demoteSignatureDateApproverWidgets(array $fields): array
    {
        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }
            $type = strtolower(trim((string) ($field['type'] ?? '')));
            $widget = strtolower(trim((string) ($field['widget'] ?? '')));
            if ($type !== 'approver' && $widget !== 'approver') {
                continue;
            }
            $label = strtolower(trim((string) ($field['label'] ?? '')));
            $isSignature = str_contains($label, 'signature');
            $isDate = $label === 'date' || preg_match('/^\s*date\b/i', (string) ($field['label'] ?? '')) === 1;
            if (! $isSignature && ! $isDate) {
                continue;
            }
            $fields[$idx]['type'] = $isDate ? 'approval_stamp_date' : 'approval_stamp_signature';
            $fields[$idx]['widget'] = $isDate ? 'date' : 'text';
            $fields[$idx]['locked'] = true;
            unset($fields[$idx]['draft_editable']);
        }

        // Drop Veeva-invented col_3 / header "Name" dropdowns on Att14 Prepared/Checked/Approved tables.
        $deptSigTables = $this->neqDepartmentSignatureTableNumbers($fields);
        if ($deptSigTables !== []) {
            $byTable = [];
            foreach ($fields as $idx => $field) {
                if (! is_array($field)) {
                    continue;
                }
                $loc = (string) ($field['location'] ?? '');
                if (! preg_match('/^table_(\d+)_row_(\d+)_col_(\d+)$/', $loc, $m)) {
                    continue;
                }
                if (! isset($deptSigTables[$m[1]])) {
                    continue;
                }
                $byTable[$m[1]][(int) $m[2]][(int) $m[3]] = $idx;
            }

            $drop = [];
            foreach ($byTable as $tableNum => $rows) {
                foreach ($rows as $rowNum => $cols) {
                    // Invented 4th column (Word Att14 is 3-col).
                    if (isset($cols[3])) {
                        $drop[$cols[3]] = true;
                    }
                    // Only scrub column-header row (row 1), never signer rows.
                    if ((int) $rowNum !== 1) {
                        continue;
                    }
                    foreach ($cols as $colIdx) {
                        if (! is_array($fields[$colIdx] ?? null)) {
                            continue;
                        }
                        $t = strtolower((string) ($fields[$colIdx]['type'] ?? ''));
                        $w = strtolower((string) ($fields[$colIdx]['widget'] ?? ''));
                        if ($t === 'approver' || $w === 'approver') {
                            $fields[$colIdx]['type'] = 'table_multicol';
                            $fields[$colIdx]['widget'] = 'text';
                            $fields[$colIdx]['locked'] = true;
                            unset(
                                $fields[$colIdx]['approver_role'],
                                $fields[$colIdx]['draft_editable'],
                                $fields[$colIdx]['stamp_eligible'],
                                $fields[$colIdx]['signature_date_location']
                            );
                        }
                    }
                }
            }
            if ($drop !== []) {
                $fields = array_values(array_filter(
                    $fields,
                    fn ($f, $idx) => ! isset($drop[$idx]),
                    ARRAY_FILTER_USE_BOTH
                ));
            }
        }

        return $fields;
    }

    /**
     * Read Prepared/Checked/Approved table titles + department col_0 labels from the Word file.
     *
     * @return array<string, array{title: string, rows: array<string, string>}>
     */
    private function loadSignatureTableDeptMapFromDocx(string $fileId): array
    {
        $candidates = [
            storage_path('app/public/generated_reports/' . $fileId . '_filled.docx'),
            storage_path('app/public/templates/docx/' . $fileId . '.docx'),
        ];
        $docxPath = null;
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $docxPath = $candidate;
                break;
            }
        }
        if ($docxPath === null) {
            return [];
        }

        $pythonBin = base_path('python-service/venv/Scripts/python.exe');
        if (! is_file($pythonBin)) {
            $pythonBin = 'python';
        }
        $script = <<<'PY'
import json, sys
from docx import Document
doc = Document(sys.argv[1])
out = {}
for ti, table in enumerate(doc.tables):
    if not table.rows:
        continue
    title_cells = []
    seen = set()
    for c in table.rows[0].cells:
        cid = id(c._tc)
        if cid in seen:
            continue
        seen.add(cid)
        title_cells.append((c.text or "").strip())
    title = " ".join(t for t in title_cells if t).lower()
    if not any(k in title for k in ("prepared by", "checked by", "approved by")):
        continue
    rows = {}
    for ri, row in enumerate(table.rows):
        if ri < 1:
            continue
        seen = set()
        texts = []
        for c in row.cells:
            cid = id(c._tc)
            if cid in seen:
                continue
            seen.add(cid)
            texts.append((c.text or "").strip())
        dept = texts[0] if texts else ""
        if dept:
            rows[str(ri)] = dept
    out[str(ti)] = {"title": title_cells[0] if title_cells else title, "rows": rows}
print(json.dumps(out, ensure_ascii=False))
PY;

        try {
            $process = new Process([$pythonBin, '-c', $script, $docxPath]);
            $process->setTimeout(30);
            $process->run();
            if (! $process->isSuccessful()) {
                return [];
            }
            $decoded = json_decode(trim($process->getOutput()), true);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable $e) {
            Log::warning('Failed loading signature table dept map from DOCX', [
                'file_id' => $fileId,
                'error'   => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Force-set approver_role on a Name cell (corrects legacy mis-maps).
     *
     * @param  array<int, array<int, array{idx: int, value: string, type: string, role: string}>>  $rows
     */
    private function assignApproverRoleForced(
        array &$fields,
        array $rows,
        int $rowNum,
        string $role,
        int $colNum = 1
    ): void {
        $col = $rows[$rowNum][$colNum] ?? null;
        if (! is_array($col)) {
            return;
        }
        $type = strtolower((string) ($col['type'] ?? ''));
        if ($type !== 'approver' && $type !== 'approval_stamp_name') {
            return;
        }
        $fields[$col['idx']]['approver_role'] = $role;
        $fields[$col['idx']]['type'] = 'approver';
        $fields[$col['idx']]['widget'] = 'approver';
    }

    /**
     * Copy the row's POST role onto Signature & Date / stamp cells in the same row.
     *
     * @param  array<int, array<string, mixed>>  $fields
     */
    private function propagateApproverRoleToRowStampCells(
        array &$fields,
        string $tableNum,
        int $rowNum,
        string $role
    ): void {
        $rowPrefix = 'table_' . $tableNum . '_row_' . $rowNum . '_col_';
        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }
            $loc = (string) ($field['location'] ?? '');
            if (! str_starts_with($loc, $rowPrefix)) {
                continue;
            }
            $type = strtolower((string) ($field['type'] ?? ''));
            $label = strtolower((string) ($field['label'] ?? ''));
            $isStamp = str_starts_with($type, 'approval_stamp_')
                || (str_contains($label, 'signature') && str_contains($label, 'date'))
                || ($type !== 'approver' && preg_match('/\bdate\b/', $label) === 1);
            if (! $isStamp) {
                continue;
            }
            $fields[$idx]['approver_role'] = $role;
        }
    }

    /**
     * Keep Checked By QA as checked_qa when it sits above Validation in the same table
     * (mis-tagged as approved_qa). Do not reorder Validation after QA — Word order wins:
     * Engineering → Production → Validation → Quality Assurance → Approved By.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function placeCheckedByQualityBeforeValidation(array $fields): array
    {
        $byTable = [];
        foreach ($fields as $idx => $field) {
            if (! is_array($field) || (string) ($field['type'] ?? '') !== 'approver') {
                continue;
            }
            if (! preg_match('/^table_(\d+)_row_(\d+)_col_\d+$/', (string) ($field['location'] ?? ''), $match)) {
                continue;
            }
            $byTable[$match[1]][] = [
                'idx'  => $idx,
                'row'  => (int) $match[2],
                'role' => (string) ($field['approver_role'] ?? ''),
            ];
        }

        foreach ($byTable as $cells) {
            $validationRow = null;
            foreach ($cells as $cell) {
                if ($cell['role'] === 'post_checked_by_validation_name') {
                    $validationRow = $cell['row'];
                    break;
                }
            }
            if ($validationRow === null) {
                continue;
            }
            // QA rows that appear above Validation in Checked By were mis-tagged as Approved By QA.
            foreach ($cells as $cell) {
                if ($cell['row'] >= $validationRow) {
                    continue;
                }
                if ((string) $cell['role'] !== 'post_approved_by_quality_name') {
                    continue;
                }
                $fields[$cell['idx']]['approver_role'] = 'post_checked_by_quality_name';
            }
        }

        return $fields;
    }

    /**
     * @param  array<int, array<int, array{idx: int, value: string, type: string, role: string}>>  $rows
     * @return array<int, array{idx: int, value: string, type: string, role: string}>
     */
    // Collects approver-type cells in column zero starting from row 2 of signature tables.
    private function collectColZeroApproverRows(array $rows): array
    {
        $approvers = [];
        foreach ($rows as $rowNum => $cols) {
            if ($rowNum < 2) {
                continue;
            }
            $col = $cols[0] ?? null;
            if (! is_array($col) || ($col['type'] ?? '') !== 'approver') {
                continue;
            }
            $approvers[$rowNum] = $col;
        }

        return $approvers;
    }

    /**
     * @param  array<int, array<int, array{idx: int, value: string, type: string, role: string}>>  $rows
     */
    // Sets approver_role on a table cell only when parser left it empty.
    private function assignApproverRoleIfEmpty(
        array &$fields,
        array $rows,
        int $rowNum,
        string $role,
        int $colNum = 1
    ): void {
        $col = $rows[$rowNum][$colNum] ?? null;
        if (! is_array($col) || ($col['type'] ?? '') !== 'approver') {
            return;
        }
        if (($col['role'] ?? '') !== '') {
            return;
        }
        $fields[$col['idx']]['approver_role'] = $role;
    }

    // True when a column label refers to a certificate number rather than a file upload.
    private function isCertificateNumberColumnLabel(string $label): bool
    {
        $ll = strtolower(trim($label));
        if ($ll === '') {
            return false;
        }

        return (bool) preg_match('/certificate\s*(?:no\.?|number|#)/', $ll)
            || (bool) preg_match('/(?:ref(?:erence)?\.?\s*no\.?|ref\.?)\s*(?:of\s+)?certificate/', $ll)
            || (bool) preg_match('/\bcert\.?\s*no\.?/', $ll);
    }

    private function isAnnexureIdColumnLabel(string $label): bool
    {
        $ll = strtolower(trim(preg_replace('/\s+/u', ' ', $label) ?? $label));

        return (bool) preg_match('/^(annexure|annex)\s*(no\.?|number|#)?$/', $ll);
    }

    private function isPlaceholderTableValue(string $value): bool
    {
        $t = strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));

        return in_array($t, ['na', 'n/a', 'n.a.', 'n.a', '-', '–', '—', '.', '...', 'nil', 'none'], true);
    }

    /**
     * Upgrade legacy table_multicol Name/Signature/Date cells to approval_stamp_* metadata.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    // Upgrades legacy Name/Signature/Date cells to approval_stamp_* types with approver roles.
    // NEQ uses scrub + table-scoped mapping; requal/reval keep the legacy (pre-isolation) path.
    private function upgradeApprovalPageFieldMetadata(array $fields, ?string $importSource = null): array
    {
        $roleByRow = [
            1 => 'post_prepared_by_name',
            2 => 'post_checked_by_engineering_name',
            3 => 'post_approved_by_quality_name',
        ];
        $typeByLabel = [
            'name'      => 'approval_stamp_name',
            'signature' => 'approval_stamp_signature',
            'date'      => 'approval_stamp_date',
        ];

        if (! $this->isNeqImportSource($importSource)) {
        if (! $this->detectApprovalPageFromLegacyFields($fields)) {
            return $this->upgradeApprovalPageSelectableNameRows(
                $this->repairMissingApprovalPagePerformerRows(
                    $this->repairMissingApprovalPageStampRows($fields)
                )
            );
        }

            foreach ($fields as $idx => $field) {
                if (! is_array($field)) {
                    continue;
                }
                $location = (string) ($field['location'] ?? '');
                if (! preg_match('/^table_\d+_row_(\d+)_col_(\d+)$/', $location, $m)) {
                    continue;
                }
                $row = (int) $m[1];
                $col = (int) $m[2];
                if ($row < 1 || $row > 3 || $col < 1 || $col > 3) {
                    continue;
                }
                $label = strtolower(trim((string) ($field['label'] ?? '')));
                if (! isset($typeByLabel[$label])) {
                    continue;
                }
                $fields[$idx]['type'] = $typeByLabel[$label];
                $fields[$idx]['locked'] = true;
                $fields[$idx]['approver_role'] = $roleByRow[$row] ?? null;
            }

            return $this->upgradeApprovalPageSelectableNameRows(
                $this->repairMissingApprovalPagePerformerRows(
                    $this->repairMissingApprovalPageStampRows($fields)
                )
            );
        }

        // ── NEQ-only: scrub false positives + scope stamps to real approval tables ──
        $fields = $this->scrubFalseApprovalStampFields($fields);
        $approvalTableNums = $this->approvalPageTableNumbers($fields);
        // Att14 Prepared/Checked/Approved By blocks are NOT the Veeva 3-row APPROVAL PAGE —
        // never map row 3 → post_approved_by_quality_name on those tables.
        $deptSigTables = $this->neqDepartmentSignatureTableNumbers($fields);

        if ($approvalTableNums !== [] || $this->detectApprovalPageFromLegacyFields($fields)) {
            if ($approvalTableNums === []) {
                $approvalTableNums = $this->approvalPageTableNumbersFromNameSignatureDate($fields);
            }

            foreach ($fields as $idx => $field) {
                if (! is_array($field)) {
                    continue;
                }

                $location = (string) ($field['location'] ?? '');
                if (! preg_match('/^table_(\d+)_row_(\d+)_col_(\d+)$/', $location, $m)) {
                    continue;
                }

                $tableNum = $m[1];
                if (isset($deptSigTables[$tableNum])) {
                    continue;
                }
                if ($approvalTableNums !== [] && ! isset($approvalTableNums[$tableNum])) {
                    continue;
                }

                $row = (int) $m[2];
                $col = (int) $m[3];
                if ($row < 1 || $row > 3 || $col < 1 || $col > 3) {
                    continue;
                }

                $label = strtolower(trim((string) ($field['label'] ?? '')));
                if (! isset($typeByLabel[$label])) {
                    continue;
                }

                $fields[$idx]['type'] = $typeByLabel[$label];
                $fields[$idx]['locked'] = true;
                $fields[$idx]['approver_role'] = $roleByRow[$row] ?? null;
            }
        }

        return $this->demoteSignatureDateApproverWidgets(
            $this->repairNeqReportApprovalNameDropdowns(
                $this->upgradeApprovalPageSelectableNameRows(
                    $this->repairMissingApprovalPagePerformerRows(
                        $this->repairMissingApprovalPageStampRows($fields)
                    )
                )
            )
        );
    }

    /**
     * Table indexes for Att14-style Prepared By / Checked By / Approved By blocks.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<string, true>
     */
    private function neqDepartmentSignatureTableNumbers(array $fields): array
    {
        $byTable = [];
        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }
            $location = (string) ($field['location'] ?? '');
            if (! preg_match('/^table_(\d+)_row_(\d+)_col_(\d+)$/', $location, $m)) {
                continue;
            }
            $byTable[$m[1]][(int) $m[2]][(int) $m[3]] = $idx;
        }

        $out = [];
        foreach ($byTable as $tableNum => $rows) {
            if ($this->neqApprovalTableTitle($fields, $rows) !== '') {
                $out[(string) $tableNum] = true;
            }
        }

        return $out;
    }

    /**
     * 8.0 Report Approval (Prepared/Checked/Approved By): restore Name/Designation
     * user dropdowns when an older scrub demoted them to plain text inputs (NEQ).
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function repairNeqReportApprovalNameDropdowns(array $fields): array
    {
        $byTable = [];
        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }
            $location = (string) ($field['location'] ?? '');
            if (! preg_match('/^table_(\d+)_row_(\d+)_col_(\d+)$/', $location, $m)) {
                continue;
            }
            $byTable[$m[1]][(int) $m[2]][(int) $m[3]] = $idx;
        }

        foreach ($byTable as $tableNum => $rows) {
            $title = $this->neqApprovalTableTitle($fields, $rows);
            if ($title === '') {
                continue;
            }

            ksort($rows);
            $nameCol = $this->neqApprovalNameCol($fields, $rows);
            if ($nameCol === null) {
                continue;
            }

            $dataRows = [];
            foreach ($rows as $rowNum => $cols) {
                // row 0 title, row 1 column headers — signers start at row 2.
                if ($rowNum < 2 || ! isset($cols[$nameCol])) {
                    continue;
                }
                if ($this->isNeqApprovalColumnHeaderRow($fields, $cols)) {
                    continue;
                }
                $idx = $cols[$nameCol];
                $type = strtolower(trim((string) ($fields[$idx]['type'] ?? '')));
                $label = strtolower(trim((string) ($fields[$idx]['label'] ?? '')));
                $role = (string) ($fields[$idx]['approver_role'] ?? '');
                $isName = $type === 'approver'
                    || str_starts_with($role, 'post_')
                    || $label === 'name'
                    || $label === ''
                    || (str_contains($label, 'name') && str_contains($label, 'designation'));
                if ($isName) {
                    $dataRows[] = $rowNum;
                }
            }
            if ($dataRows === []) {
                continue;
            }

            foreach ($dataRows as $offset => $rowNum) {
                $idx = $rows[$rowNum][$nameCol] ?? null;
                if ($idx === null || ! is_array($fields[$idx] ?? null)) {
                    continue;
                }
                $deptLabel = '';
                if (isset($rows[$rowNum][0]) && $nameCol !== 0) {
                    $deptLabel = strtolower(trim((string) (
                        ($fields[$rows[$rowNum][0]]['value'] ?? '')
                        ?: ($fields[$rows[$rowNum][0]]['label'] ?? '')
                    )));
                }
                $role = $this->neqApprovalRoleForRow($title, $deptLabel, $offset, count($dataRows));
                if ($role === '') {
                    continue;
                }

                $fields[$idx]['type'] = 'approver';
                $fields[$idx]['widget'] = 'approver';
                $fields[$idx]['locked'] = false;
                $fields[$idx]['approver_role'] = $role;
                $fields[$idx]['stamp_eligible'] = true;
                $fields[$idx]['draft_editable'] = true;
                unset($fields[$idx]['prefilled']);
                // Prefer explicit date col; else signature/date merged col.
                $dateCol = isset($rows[$rowNum][3]) ? 3 : (isset($rows[$rowNum][2]) ? 2 : null);
                if ($dateCol !== null) {
                    $fields[$idx]['signature_date_location'] = "table_{$tableNum}_row_{$rowNum}_col_{$dateCol}";
                }
                // Keep Signature / Date stamp cells on the same role as Name.
                foreach ($cols = ($rows[$rowNum] ?? []) as $colNum => $stampIdx) {
                    if ((int) $colNum === (int) $nameCol || ! is_array($fields[$stampIdx] ?? null)) {
                        continue;
                    }
                    $stampType = strtolower(trim((string) ($fields[$stampIdx]['type'] ?? '')));
                    if (str_starts_with($stampType, 'approval_stamp_') || str_contains(
                        strtolower((string) ($fields[$stampIdx]['label'] ?? '')),
                        'signature'
                    ) || str_contains(strtolower((string) ($fields[$stampIdx]['label'] ?? '')), 'date')) {
                        $fields[$stampIdx]['approver_role'] = $role;
                    }
                }
            }
        }

        // Legacy parser used pre-approval key approved_by_user_name on POST Approved By rows.
        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }
            if ((string) ($field['approver_role'] ?? '') !== 'approved_by_user_name') {
                continue;
            }
            $fields[$idx]['approver_role'] = 'post_approved_by_user_name';
            $fields[$idx]['type'] = 'approver';
            $fields[$idx]['widget'] = 'approver';
            $fields[$idx]['locked'] = false;
            $fields[$idx]['draft_editable'] = true;
            $fields[$idx]['stamp_eligible'] = true;
            unset($fields[$idx]['prefilled'], $fields[$idx]['is_pre_approver']);
        }

        return $fields;
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     * @param  array<int, array<int, int>>  $rows
     */
    private function neqApprovalTableTitle(array $fields, array $rows): string
    {
        foreach ($rows[0] ?? [] as $idx) {
            if (! is_array($fields[$idx] ?? null)) {
                continue;
            }
            $blob = strtolower(trim(
                (string) (($fields[$idx]['value'] ?? '') ?: ($fields[$idx]['label'] ?? ''))
            ));
            foreach (['prepared by', 'checked by', 'approved by'] as $title) {
                if (str_contains($blob, $title)) {
                    return $title;
                }
            }
        }

        // Department tables often omit row-0 headers from fillable fields — infer by size.
        $nameRows = 0;
        foreach ($rows as $rowNum => $_cols) {
            if ($rowNum >= 1) {
                $nameRows++;
            }
        }
        if ($nameRows >= 3) {
            return 'checked by';
        }
        if ($nameRows === 2) {
            return 'approved by';
        }
        if ($nameRows === 1) {
            return 'prepared by';
        }

        return '';
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     * @param  array<int, array<int, int>>  $rows
     */
    private function neqApprovalNameCol(array $fields, array $rows): ?int
    {
        foreach ([1, 0] as $col) {
            foreach ($rows as $rowNum => $cols) {
                if ($rowNum < 1 || ! isset($cols[$col])) {
                    continue;
                }
                $idx = $cols[$col];
                if (! is_array($fields[$idx] ?? null)) {
                    continue;
                }
                $type = strtolower(trim((string) ($fields[$idx]['type'] ?? '')));
                $label = strtolower(trim((string) ($fields[$idx]['label'] ?? '')));
                $role = (string) ($fields[$idx]['approver_role'] ?? '');
                if (
                    $type === 'approver'
                    || str_starts_with($role, 'post_')
                    || $role === 'approved_by_user_name'
                    || $label === 'name'
                    || (str_contains($label, 'name') && str_contains($label, 'designation'))
                ) {
                    return $col;
                }
            }
        }

        return null;
    }

    private function neqApprovalRoleForRow(string $title, string $deptLabel, int $offset, int $rowCount): string
    {
        $dept = strtolower(trim($deptLabel));
        if ($title === 'prepared by') {
            return 'post_prepared_by_name';
        }
        if ($title === 'approved by') {
            if (str_contains($dept, 'quality') || str_contains($dept, 'assurance')) {
                return 'post_approved_by_quality_name';
            }
            if (str_contains($dept, 'validation') || str_contains($dept, 'user')) {
                return 'post_approved_by_user_name';
            }

            return $offset === 0 ? 'post_approved_by_user_name' : 'post_approved_by_quality_name';
        }
        if ($title === 'checked by') {
            if (str_contains($dept, 'engineering')) {
                return 'post_checked_by_engineering_name';
            }
            if (str_contains($dept, 'production')) {
                return 'post_checked_by_production_name';
            }
            if (str_contains($dept, 'quality') || str_contains($dept, 'assurance') || $dept === 'qa') {
                return 'post_checked_by_quality_name';
            }
            if (str_contains($dept, 'validation') || str_contains($dept, 'user')) {
                return 'post_checked_by_validation_name';
            }
            // Blank Att14 rows have no department label. Top to bottom matches
            // the approval sequence: Engineering, Production, Validation, QA.
            $byOffset = [
                0 => 'post_checked_by_engineering_name',
                1 => 'post_checked_by_production_name',
                2 => 'post_checked_by_validation_name',
                3 => 'post_checked_by_quality_name',
            ];

            return $byOffset[$offset] ?? 'post_checked_by_engineering_name';
        }

        return '';
    }

    /**
     * Drop approval Name/Signature/Date fields for rows that do not exist in the Word
     * table (e.g. previously injected "Approved By" when the DOCX only has Performed + Checked).
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function pruneApprovalPageFieldsBeyondDocxRows(array $fields, ?string $fileId = null): array
    {
        if ($fileId === null || $fileId === '') {
            return $fields;
        }

        $maxDataRows = $this->docxApprovalTableMaxDataRows($fileId);
        if ($maxDataRows === []) {
            return $fields;
        }

        $kept = [];
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $location = (string) ($field['location'] ?? '');
            if (! preg_match('/^table_(\d+)_row_(\d+)_col_/', $location, $m)) {
                $kept[] = $field;
                continue;
            }
            $tableNum = $m[1];
            $rowNum = (int) $m[2];
            if (! isset($maxDataRows[$tableNum])) {
                $kept[] = $field;
                continue;
            }

            $type = (string) ($field['type'] ?? '');
            $role = (string) ($field['approver_role'] ?? '');
            $isApprovalCell = str_starts_with($type, 'approval_stamp_')
                || $type === 'approver'
                || str_starts_with($role, 'post_');
            if (! $isApprovalCell) {
                $kept[] = $field;
                continue;
            }

            // Word data rows are 1..N (header is row 0). Drop anything past the DOCX.
            if ($rowNum > $maxDataRows[$tableNum]) {
                continue;
            }
            $kept[] = $field;
        }

        return $kept;
    }

    /**
     * For Role|Name|Signature|Date tables: max data-row index present in the DOCX.
     *
     * @return array<string, int> table index => max data row (1-based)
     */
    private function docxApprovalTableMaxDataRows(string $fileId): array
    {
        $templatePath = storage_path('app/public/templates/docx/' . $fileId . '.docx');
        if (! is_file($templatePath)) {
            return [];
        }

        $pythonBin = base_path('python-service/venv/Scripts/python.exe');
        if (! is_file($pythonBin)) {
            $pythonBin = 'python';
        }

        $script = <<<'PY'
from docx import Document
import json, sys
doc = Document(sys.argv[1])
out = {}
for ti, table in enumerate(doc.tables):
    if not table.rows:
        continue
    header = " ".join(c.text.strip().lower() for c in table.rows[0].cells)
    if "role" not in header or "name" not in header or "signature" not in header:
        continue
    # data rows = total - header
    out[str(ti)] = max(0, len(table.rows) - 1)
print(json.dumps(out))
PY;

        try {
            $process = new Process([$pythonBin, '-c', $script, $templatePath]);
            $process->setTimeout(30);
            $process->run();
            if (! $process->isSuccessful()) {
                return [];
            }
            $decoded = json_decode(trim($process->getOutput()), true);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable $e) {
            Log::warning('DOCX approval row count failed: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Tables that already look like Role | Name | Signature | Date approval pages.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<string, true>
     */
    private function approvalPageTableNumbers(array $fields): array
    {
        $tables = [];
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $location = (string) ($field['location'] ?? '');
            if (! preg_match('/^table_(\d+)_/', $location, $m)) {
                continue;
            }
            $type = (string) ($field['type'] ?? '');
            $role = (string) ($field['approver_role'] ?? '');
            if (str_starts_with($type, 'approval_stamp_') || $type === 'approver' || str_starts_with($role, 'post_')) {
                $tables[$m[1]] = true;
            }
        }

        return $tables + $this->approvalPageTableNumbersFromNameSignatureDate($fields);
    }

    /**
     * Tables with at least one complete Name + Signature + Date data row.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<string, true>
     */
    private function approvalPageTableNumbersFromNameSignatureDate(array $fields): array
    {
        $byTable = [];
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $location = (string) ($field['location'] ?? '');
            if (! preg_match('/^table_(\d+)_row_([1-3])_col_([1-3])$/', $location, $m)) {
                continue;
            }
            $label = strtolower(trim((string) ($field['label'] ?? '')));
            if (! in_array($label, ['name', 'signature', 'date'], true)) {
                continue;
            }
            $byTable[$m[1]][$m[2]][$label] = true;
        }

        $tables = [];
        foreach ($byTable as $tableNum => $rows) {
            foreach ($rows as $cols) {
                if (isset($cols['name'], $cols['signature'], $cols['date'])) {
                    $tables[(string) $tableNum] = true;
                    break;
                }
            }
        }

        return $tables;
    }

    /**
     * Revision History / other Date-only columns must not stay as approval stamps.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function scrubFalseApprovalStampFields(array $fields): array
    {
        $realTables = $this->approvalPageTableNumbersFromNameSignatureDate($fields);

        // Also keep tables that already have a real Name stamp / approver Name cell.
        // Veeva Role|Name|Signature|Date uses col_1; 8.0 Report Approval (Prepared/
        // Checked/Approved By) uses col_0 "Name/ Designation".
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $location = (string) ($field['location'] ?? '');
            if (! preg_match('/^table_(\d+)_row_\d+_col_([01])$/', $location, $m)) {
                continue;
            }
            $type = (string) ($field['type'] ?? '');
            $role = (string) ($field['approver_role'] ?? '');
            $label = strtolower(trim((string) ($field['label'] ?? '')));
            $isNameLabel = $label === ''
                || $label === 'name'
                || str_contains($label, 'name') && str_contains($label, 'designation');
            if (
                ($type === 'approver' || $type === 'approval_stamp_name' || str_starts_with($role, 'post_'))
                && $isNameLabel
            ) {
                $realTables[$m[1]] = true;
            }
        }

        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }
            $location = (string) ($field['location'] ?? '');
            if (! preg_match('/^table_(\d+)_/', $location, $m)) {
                continue;
            }
            if (isset($realTables[$m[1]])) {
                continue;
            }

            $type = (string) ($field['type'] ?? '');
            $role = (string) ($field['approver_role'] ?? '');
            if (! str_starts_with($type, 'approval_stamp_') && $type !== 'approver') {
                continue;
            }

            // Never demote POST Report Approval user dropdowns (Prepared/Checked/Approved By).
            if ($type === 'approver' && str_starts_with($role, 'post_')) {
                $realTables[$m[1]] = true;
                continue;
            }

            // Lone Date / stray stamp outside a real approval table → normal editable cell.
            $fields[$idx]['type'] = 'table_multicol';
            $fields[$idx]['widget'] = str_contains(strtolower((string) ($field['label'] ?? '')), 'date')
                ? 'date'
                : 'text';
            $fields[$idx]['locked'] = false;
            unset($fields[$idx]['approver_role'], $fields[$idx]['signature_date_location']);
        }

        return $fields;
    }

    /**
     * Approval-page Checked/Approved rows: Name column is a fill-time user dropdown (like Performed By).
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function upgradeApprovalPageSelectableNameRows(array $fields): array
    {
        $selectableRoles = [
            'post_prepared_by_name',
            'post_checked_by_engineering_name',
            'post_checked_by_production_name',
            'post_checked_by_quality_name',
            'post_checked_by_validation_name',
            'post_approved_by_user_name',
            'post_approved_by_quality_name',
        ];

        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }

            $role = (string) ($field['approver_role'] ?? '');
            if (! in_array($role, $selectableRoles, true)) {
                continue;
            }

            $location = (string) ($field['location'] ?? '');
            // Veeva approval page: Name is col_1. 8.0 Report Approval blocks: Name/Designation is col_0.
            if (! preg_match('/^table_\d+_row_\d+_col_([01])$/', $location)) {
                continue;
            }

            $type  = (string) ($field['type'] ?? '');
            $label = strtolower(trim((string) ($field['label'] ?? '')));
            if ($type !== 'approval_stamp_name' && $type !== 'approver' && $type !== 'table_multicol') {
                continue;
            }
            $isNameLabel = $label === ''
                || $label === 'name'
                || (str_contains($label, 'name') && str_contains($label, 'designation'));
            if (! $isNameLabel) {
                continue;
            }

            $fields[$idx]['type']   = 'approver';
            $fields[$idx]['widget'] = 'approver';
            $fields[$idx]['locked'] = false;
            $fields[$idx]['stamp_eligible'] = true;
        }

        // Row-based fallback when stamp cells lack approver_role metadata.
        $byTable = [];
        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }
            $location = (string) ($field['location'] ?? '');
            if (! preg_match('/^table_(\d+)_row_(\d+)_col_(\d+)$/', $location, $m)) {
                continue;
            }
            $byTable[$m[1]][(int) $m[2]][(int) $m[3]] = $idx;
        }

        $roleByRow = [
            2 => 'post_checked_by_engineering_name',
            3 => 'post_approved_by_quality_name',
        ];

        foreach ($byTable as $rows) {
            if (! isset($rows[1][1])) {
                continue;
            }
            $firstName = $fields[$rows[1][1]] ?? null;
            if (! is_array($firstName) || (string) ($firstName['type'] ?? '') !== 'approver') {
                continue;
            }
            if ((string) ($firstName['approver_role'] ?? '') !== 'post_prepared_by_name') {
                continue;
            }

            foreach ($roleByRow as $rowNum => $role) {
                $idx = $rows[$rowNum][1] ?? null;
                if ($idx === null || ! is_array($fields[$idx] ?? null)) {
                    continue;
                }
                $type = (string) ($fields[$idx]['type'] ?? '');
                if ($type !== 'approval_stamp_name' && $type !== 'approver') {
                    continue;
                }
                $fields[$idx]['type']   = 'approver';
                $fields[$idx]['widget'] = 'approver';
                $fields[$idx]['locked'] = false;
                $fields[$idx]['approver_role'] = $role;
                $fields[$idx]['stamp_eligible'] = true;
            }
        }

        return $fields;
    }

    /**
     * Inject editable Performed/Recorded/Complied By dropdown on approval-page row 1.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    // Adds editable Performed By dropdown on row 1 when approval page parser skipped it.
private function repairMissingApprovalPagePerformerRows(array $fields): array
    {
        $locations = [];
        $stampTables = [];
        $deptSigTables = $this->neqDepartmentSignatureTableNumbers($fields);

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $loc = (string) ($field['location'] ?? '');
            if ($loc !== '') {
                $locations[$loc] = true;
            }
            $type = (string) ($field['type'] ?? '');
            if (! preg_match('/^table_(\d+)_row_(\d+)_col_([1-3])$/', $loc, $m)) {
                continue;
            }
            // Never inject Veeva "Performed By" into Att14 Prepared/Checked/Approved tables.
            if (isset($deptSigTables[$m[1]])) {
                continue;
            }
            if (str_starts_with($type, 'approval_stamp_')) {
            $stampTables[$m[1]][(int) $m[2]][(int) $m[3]] = true;
            } elseif ($type === 'approver' && (int) $m[3] === 1) {
                $stampTables[$m[1]][(int) $m[2]][1] = true;
            }
        }

        foreach ($stampTables as $tableNum => $rows) {
            // Need a Checked/Approved-style row or an existing Performed By row.
            if (! isset($rows[1]) && ! isset($rows[2]) && ! isset($rows[3])) {
                continue;
            }

            $performerLoc = "table_{$tableNum}_row_1_col_1";
            // Row 1 col 1 is the user-selectable performer; Signature/Date stamp after submit.
            if (isset($locations[$performerLoc])) {
                foreach ($fields as $idx => $field) {
                    if (! is_array($field) || (string) ($field['location'] ?? '') !== $performerLoc) {
                        continue;
                    }
                    $fields[$idx]['type'] = 'approver';
                    $fields[$idx]['widget'] = 'approver';
                    $fields[$idx]['locked'] = false;
                    $fields[$idx]['approver_role'] = 'post_prepared_by_name';
                    $fields[$idx]['stamp_eligible'] = true;
                    $fields[$idx]['signature_date_location'] = "table_{$tableNum}_row_1_col_3";
                }
                continue;
            }

            $fields[] = [
                'label'                   => 'Name',
                'location'                => $performerLoc,
                'value'                   => '',
                'locked'                  => false,
                'type'                    => 'approver',
                'widget'                  => 'approver',
                'approver_role'           => 'post_prepared_by_name',
                'signature_date_location' => "table_{$tableNum}_row_1_col_3",
            ];
            $locations[$performerLoc] = true;

            foreach ([2 => 'approval_stamp_signature', 3 => 'approval_stamp_date'] as $col => $stampType) {
                $stampLoc = "table_{$tableNum}_row_1_col_{$col}";
                if (isset($locations[$stampLoc])) {
                    continue;
                }
                $fields[] = [
                    'label'         => $col === 2 ? 'Signature' : 'Date',
                    'location'      => $stampLoc,
                    'value'         => '',
                    'locked'        => true,
                    'type'          => $stampType,
                    'widget'        => $col === 3 ? 'date' : 'text',
                    'approver_role' => 'post_prepared_by_name',
                ];
                $locations[$stampLoc] = true;
            }
        }

        return $fields;
    }

    /**
     * Inject missing Name/Signature/Date stamp fields for approval-page rows that were
     * skipped when the parser did not recognize a role label (e.g. Complied By, Reviewed By).
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    // Injects missing stamp fields for approval-page rows the parser skipped (e.g. Complied By).
    private function repairMissingApprovalPageStampRows(array $fields): array
    {
        $locations = [];
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $loc = (string) ($field['location'] ?? '');
            if ($loc !== '') {
                $locations[$loc] = true;
            }
        }

        $stampByTable = [];
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $type = (string) ($field['type'] ?? '');
            $location = (string) ($field['location'] ?? '');
            if (! preg_match('/^table_(\d+)_row_(\d+)_col_([1-3])$/', $location, $m)) {
                continue;
            }
            $tableNum = $m[1];
            $rowNum = (int) $m[2];
            $colNum = (int) $m[3];
            // Name cells become type=approver after dropdown upgrade — still count as stamp row.
            if (str_starts_with($type, 'approval_stamp_')) {
                $stampByTable[$tableNum][$rowNum][$colNum] = true;
            } elseif ($type === 'approver' && $colNum === 1) {
                $stampByTable[$tableNum][$rowNum][1] = true;
            }
        }

        $stampCols = [
            1 => ['type' => 'approval_stamp_name', 'label' => 'Name'],
            2 => ['type' => 'approval_stamp_signature', 'label' => 'Signature'],
            3 => ['type' => 'approval_stamp_date', 'label' => 'Date'],
        ];

        $roleByRow = [
            1 => 'post_prepared_by_name',
            2 => 'post_checked_by_engineering_name',
            3 => 'post_approved_by_quality_name',
        ];

        foreach ($stampByTable as $tableNum => $rows) {
            // Only complete Name/Signature/Date cells for rows that already exist in the
            // Word table — never invent Approved By (or any extra row) that is not there.
            $existingRows = array_keys($rows);
            if ($existingRows === []) {
                continue;
            }

            foreach ($existingRows as $row) {
                $row = (int) $row;
                if ($row < 1) {
                    continue;
                }
                if ($this->approvalPageStampRowComplete($rows, $row)) {
                    continue;
                }

                foreach ($stampCols as $col => $meta) {
                    $loc = "table_{$tableNum}_row_{$row}_col_{$col}";
                    if (isset($locations[$loc])) {
                        // Ensure role metadata exists even when the cell was already parsed.
                        foreach ($fields as $fIdx => $existing) {
                            if (! is_array($existing)) {
                                continue;
                            }
                            if ((string) ($existing['location'] ?? '') !== $loc) {
                                continue;
                            }
                            if (empty($fields[$fIdx]['approver_role'])) {
                                $fields[$fIdx]['approver_role'] = $roleByRow[$row] ?? null;
                            }
                            if ($col === 1 && ($fields[$fIdx]['type'] ?? '') === 'approver') {
                                // keep dropdown type
                            } elseif (! str_starts_with((string) ($fields[$fIdx]['type'] ?? ''), 'approval_stamp_')
                                && ($fields[$fIdx]['type'] ?? '') !== 'approver'
                            ) {
                                $fields[$fIdx]['type'] = $meta['type'];
                            }
                        }
                        continue;
                    }
                    $fields[] = [
                        'label'         => $meta['label'],
                        'location'      => $loc,
                        'value'         => '',
                        'locked'        => true,
                        'type'          => $meta['type'],
                        'approver_role' => $roleByRow[$row] ?? null,
                    ];
                    $locations[$loc] = true;
                }
            }
        }

        return $fields;
    }

    /**
     * @param  array<int, array<int, bool>>  $rows
     */
    // True when a row has all three approval-page stamp columns present.
    private function approvalPageStampRowComplete(array $rows, int $row): bool
    {
        return isset($rows[$row][1], $rows[$row][2], $rows[$row][3]);
    }

    /**
     * Write auto-captured approver name/date into APPROVAL PAGE Name/Signature/Date cells.
     *
     * @param  array<int, array<string, mixed>>  $fields
     */
    // Writes approver name, signature label, and date into APPROVAL PAGE stamp cells for one role.
    // Name = selected user; Signature = name stamp; Date = approve timestamp (separate columns).
    private function applyApprovalPageStampToFields(
        array &$fields,
        string $roleKey,
        string $name,
        string $date
    ): void {
        $signatureName = $this->signatureLabelFromDisplayName($name);

        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }
            if ((string) ($field['approver_role'] ?? '') !== $roleKey) {
                continue;
            }

            if ($this->isPrefilledImportField($field)) {
                continue;
            }

            $type = (string) ($field['type'] ?? '');
            $label = strtolower((string) ($field['label'] ?? ''));
            if ($type === 'approval_stamp_name' || $type === 'approver') {
                $fields[$idx]['value'] = $name;
            } elseif ($type === 'approval_stamp_signature'
                || (str_contains($label, 'signature') && str_contains($label, 'date'))
            ) {
                // Combined "Signature & Date" gets name + timestamp (same as QA row).
                $sig = $signatureName !== '' ? $signatureName : $name;
                if (str_contains($label, 'signature') && str_contains($label, 'date') && $date !== '') {
                    $fields[$idx]['value'] = trim($sig . '  ' . $date);
                } else {
                    $fields[$idx]['value'] = $sig;
                }
            } elseif ($type === 'approval_stamp_date') {
                $fields[$idx]['value'] = $date;
            }
        }
    }

    private function resolveApproverDisplayName(array $data, string $prefix): string
    {
        $roleKey = $prefix . '_name';
        $userId = $data[$roleKey] ?? null;
        if (is_numeric($userId)) {
            $user = User::find((int) $userId);
            if ($user) {
                return $this->formatUserOptionLabel($user);
            }
        }

        return trim((string) ($data[$prefix . '_name_text'] ?? ''));
    }

    /**
     * Stamp Signature & Date cells for every approver row that has a name value.
     *
     * @param  array<int, array<string, mixed>>  $fields
     */
    private function stampApproverSignatureRowsInFields(array &$fields): void
    {
        $indexByLocation = [];
        foreach ($fields as $i => $field) {
            if (! is_array($field)) {
                continue;
            }
            $loc = (string) ($field['location'] ?? '');
            if ($loc !== '') {
                $indexByLocation[$loc] = $i;
            }
        }

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            if ((string) ($field['type'] ?? '') !== 'approver') {
                continue;
            }

            if ($this->isPrefilledImportField($field)) {
                continue;
            }

            $name = trim((string) ($field['value'] ?? ''));
            if ($name === '') {
                continue;
            }

            $dateLoc = (string) ($field['signature_date_location'] ?? '');
            if ($dateLoc !== '' && isset($indexByLocation[$dateLoc])) {
                $dateField = $fields[$indexByLocation[$dateLoc]] ?? null;
                // Skip when paired date cell is prefilled and must remain immutable.
                if (is_array($dateField) && $this->isPrefilledImportField($dateField)) {
                    continue;
                }
            }

            $this->applyApprovalStampToSignatureRow(
                $fields,
                $indexByLocation,
                (string) ($field['location'] ?? ''),
                $name,
                '',
                $name,
                (string) ($field['approver_role'] ?? '')
            );
        }
    }

    // Writes approver name, signature label, and combined date into all cells of one signature row.
    private function applyApprovalStampToSignatureRow(
        array &$fields,
        array $indexByLocation,
        string $approverLocation,
        string $name,
        string $date,
        string $signature,
        ?string $roleKey = null
    ): void {
        if ($approverLocation === '' || ! preg_match('/^(table_\d+_row_\d+)_col_\d+$/', $approverLocation, $m)) {
            return;
        }

        $rowKey = $m[1];

        if (! isset($indexByLocation[$approverLocation])) {
            return;
        }

        $approverField = $fields[$indexByLocation[$approverLocation]] ?? null;
        $stampRoleKey = $roleKey ?? (is_array($approverField) ? (string) ($approverField['approver_role'] ?? '') : '');
        $dateLoc      = is_array($approverField) ? (string) ($approverField['signature_date_location'] ?? '') : '';

        // Keep locked/prefilled Name cells, but still stamp Signature & Date on the same row.
        $preserveName = is_array($approverField)
            && $this->shouldPreserveBaselineFieldValue(
                $approverField,
                trim((string) ($approverField['value'] ?? ''))
            );

        if ($dateLoc !== '' && isset($indexByLocation[$dateLoc])) {
            $dateField = $fields[$indexByLocation[$dateLoc]] ?? null;
            $dateVal = is_array($dateField) ? trim((string) ($dateField['value'] ?? '')) : '';
            // Junk template "S" must never block a real approval stamp.
            if (is_array($dateField) && $this->isJunkSignaturePlaceholder($dateVal)) {
                $fields[$indexByLocation[$dateLoc]]['value'] = '';
                $fields[$indexByLocation[$dateLoc]]['locked'] = false;
                $fields[$indexByLocation[$dateLoc]]['prefilled'] = false;
                $fields[$indexByLocation[$dateLoc]]['stamp_eligible'] = true;
            }
        }

        // Department/role labels (e.g. "Head - Quality Assurance") live in col_0.
        // Never stamp the approver name there when a Name & Designation col_1 exists.
        $nameWriteLocation = $approverLocation;
        if (
            str_ends_with($approverLocation, '_col_0')
            && isset($indexByLocation[$rowKey . '_col_1'])
        ) {
            $nameWriteLocation = $rowKey . '_col_1';
        }

        if ($name !== '' && ! $preserveName && isset($indexByLocation[$nameWriteLocation])) {
            $fields[$indexByLocation[$nameWriteLocation]]['value'] = $name;
        }

        $signatureName = $this->signatureLabelFromDisplayName($name);
        $combined      = trim($signatureName);
        if ($date !== '') {
            $combined = $combined !== '' ? $combined . '  ' . $date : $date;
        }

        // Update every stamp-eligible cell in the same table row (signature, date, combined columns).
        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }

            $loc = (string) ($field['location'] ?? '');
            if (! str_starts_with($loc, $rowKey . '_col_')) {
                continue;
            }

            if (! $this->shouldApplySignatureStampToField($field, $approverLocation, $stampRoleKey, $dateLoc)) {
                continue;
            }

            if ($this->isPrefilledImportField($field)) {
                continue;
            }

            // Locked partial stamps (name-only) must still accept name + datetime.
            $existingVal = trim((string) ($field['value'] ?? ''));
            if (
                $this->shouldPreserveBaselineFieldValue($field, $existingVal)
                && ! $this->isPostApprovalWorkflowField($field)
            ) {
                continue;
            }

            $label  = strtolower((string) ($field['label'] ?? ''));
            $widget = strtolower((string) ($field['widget'] ?? ''));

            // Combined Signature & Date column gets name plus timestamp on one line.
            if (str_contains($label, 'signature') && str_contains($label, 'date')) {
                if ($combined !== '') {
                    $fields[$idx]['value'] = $combined;
                    $fields[$idx]['locked'] = true;
                    $fields[$idx]['prefilled'] = false;
                    $fields[$idx]['stamp_eligible'] = true;
                }
                continue;
            }

            // Separate Date column: timestamp only (never copy "Name + date" here).
            if ($widget === 'date' || preg_match('/\bdate\b/', $label)) {
                if ($date !== '') {
                    $fields[$idx]['value'] = $date;
                }
                continue;
            }

            if (str_contains($label, 'signature') && ! str_contains($label, 'date')) {
                $sigDisplay = $signatureName !== ''
                    ? $signatureName
                    : $this->signatureLabelFromDisplayName($signature);
                if ($sigDisplay !== '') {
                    $fields[$idx]['value'] = $sigDisplay;
                }
            }
        }

        // Linked signature_date_location: prefer date-only when the target is a Date cell.
        if ($dateLoc !== '' && isset($indexByLocation[$dateLoc]) && ($date !== '' || $combined !== '')) {
            $dateIdx   = $indexByLocation[$dateLoc];
            $dateField = $fields[$dateIdx] ?? null;
            $dateExisting = is_array($dateField) ? trim((string) ($dateField['value'] ?? '')) : '';
            if (
                is_array($dateField)
                && (
                    ! $this->shouldPreserveBaselineFieldValue($dateField, $dateExisting)
                    || $this->isPostApprovalWorkflowField($dateField)
                )
            ) {
                $dateLabel = strtolower((string) ($dateField['label'] ?? ''));
                $dateType = strtolower((string) ($dateField['type'] ?? ''));
                $dateWidget = strtolower((string) ($dateField['widget'] ?? ''));
                $isDateOnly = $dateType === 'approval_stamp_date'
                    || $dateWidget === 'date'
                    || (preg_match('/\bdate\b/', $dateLabel) && ! str_contains($dateLabel, 'signature'));
                $fields[$dateIdx]['value'] = $isDateOnly
                    ? ($date !== '' ? $date : $combined)
                    : ($combined !== '' ? $combined : $date);
                $fields[$dateIdx]['locked'] = true;
                $fields[$dateIdx]['prefilled'] = false;
                $fields[$dateIdx]['stamp_eligible'] = true;
            }
        }
    }

    /**
     * Replace legacy Approved/Prepared text in Signature columns with the row approver name.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    // Replaces placeholder Approved/Prepared text in signature columns with the approver's name.
    private function upgradeSignatureColumnDisplayNames(array $fields): array
    {
        $rows = [];
        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }
            $loc = (string) ($field['location'] ?? '');
            if (! preg_match('/^(table_\d+_row_\d+)_col_(\d+)$/', $loc, $m)) {
                continue;
            }
            $rows[$m[1]][(int) $m[2]] = [
                'idx'   => $idx,
                'value' => trim((string) ($field['value'] ?? '')),
                'label' => strtolower((string) ($field['label'] ?? '')),
                'type'  => (string) ($field['type'] ?? ''),
            ];
        }

        $decisionWords = ['approved', 'prepared', 'rejected', 'send back'];

        foreach ($rows as $cols) {
            $nameCol = null;
            foreach ($cols as $colNum => $col) {
                if ($colNum === 0 || ! is_array($col)) {
                    continue;
                }
                if (($col['type'] ?? '') === 'approver') {
                    $nameCol = $col;
                    break;
                }
            }

            if (! is_array($nameCol)) {
                continue;
            }

            $nameVal = $nameCol['value'];
            if ($nameVal === '') {
                continue;
            }

            foreach ($cols as $colNum => $col) {
                if ($colNum === 0 || ! is_array($col)) {
                    continue;
                }

                $label = $col['label'];
                if (! str_contains($label, 'signature') || str_contains($label, 'date')) {
                    continue;
                }

                if ($this->shouldPreserveBaselineFieldValue(
                    $fields[$col['idx']],
                    trim((string) ($col['value'] ?? ''))
                )) {
                    continue;
                }

                $val = strtolower($col['value']);
                // Swap generic decision words left by the template with the actual approver signature label.
                if (in_array($val, $decisionWords, true)) {
                    $fields[$col['idx']]['value'] = $this->signatureLabelFromDisplayName($nameVal);
                }
            }
        }

        return $fields;
    }

    /**
     * Build TOC page map from docx_import_fields (toc_sdt_N → page digit).
     * These values come from Python/Word recompute on fill/download.
     *
     * @return array{by_index: array<int,string>, by_sec: array<string,string>, max: int}
     */
    public function tocPageMapFromFields(array $fields): array
    {
        $byIndex = [];
        $bySec = [];
        $max = 0;

        $titleByIndex = [];
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $loc = trim((string) ($field['location'] ?? ''));
            if (! str_starts_with($loc, 'toc_sdt_') || ! str_ends_with($loc, '_title')) {
                continue;
            }
            if (! preg_match('/^toc_sdt_(\d+)_title$/', $loc, $m)) {
                continue;
            }
            $titleByIndex[(int) $m[1]] = trim((string) ($field['value'] ?? ''));
        }

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $loc = trim((string) ($field['location'] ?? ''));
            if ($loc === '' || ! str_starts_with($loc, 'toc_sdt_') || str_ends_with($loc, '_title')) {
                continue;
            }
            $page = trim((string) ($field['value'] ?? ''));
            if ($page === '' || ! ctype_digit($page)) {
                continue;
            }
            if (! preg_match('/^toc_sdt_(\d+)$/', $loc, $m)) {
                continue;
            }
            $idx = (int) $m[1];
            $byIndex[$idx] = $page;
            $max = max($max, (int) $page);

            $title = $titleByIndex[$idx] ?? '';
            if ($title !== '' && preg_match('/^(\d+(?:\.\d+)*)\b/', $title, $sm)) {
                $bySec[$sm[1]] = $page;
            }
        }

        ksort($byIndex);

        return [
            'by_index' => $byIndex,
            'by_sec'   => $bySec,
            'max'      => $max,
        ];
    }

    /**
     * Stamp stored TOC page digits onto readonly preview .toc-page / inputs.
     * Matches download/fill Word+Python recompute values so show view matches the DOCX.
     */
    private function injectTocPageNumbersIntoPreviewHtml(string $html, array $fields): string
    {
        if (trim($html) === '' || $fields === []) {
            return $html;
        }

        $map = $this->tocPageMapFromFields($fields);
        if ($map['by_index'] === [] && $map['by_sec'] === []) {
            return $html;
        }

        libxml_use_internal_errors(true);

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->loadHTML(
            '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        $xpath = new \DOMXPath($doc);

        $lines = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' docx-toc-line ')]");
        if ($lines) {
            $lineIndex = 0;
            foreach ($lines as $line) {
                if (! $line instanceof \DOMElement) {
                    continue;
                }
                $pageNode = null;
                foreach ($xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' toc-page ')]", $line) as $candidate) {
                    if ($candidate instanceof \DOMElement) {
                        $pageNode = $candidate;
                        break;
                    }
                }
                if (! $pageNode) {
                    foreach ($xpath->query(".//input[contains(concat(' ', normalize-space(@class), ' '), ' docx-toc-page-input ')]", $line) as $candidate) {
                        if ($candidate instanceof \DOMElement) {
                            $pageNode = $candidate;
                            break;
                        }
                    }
                }
                // Cached edit→readonly HTML may have turned TOC inputs into plain readonly spans.
                if (! $pageNode) {
                    foreach ($xpath->query(".//span[contains(concat(' ', normalize-space(@class), ' '), ' docx-readonly-value ')]", $line) as $candidate) {
                        if (! $candidate instanceof \DOMElement) {
                            continue;
                        }
                        $txt = trim((string) $candidate->textContent);
                        if ($txt !== '' && ctype_digit($txt)) {
                            $pageNode = $candidate;
                            $class = trim((string) $candidate->getAttribute('class'));
                            if (! str_contains($class, 'toc-page')) {
                                $candidate->setAttribute('class', trim($class . ' toc-page'));
                            }
                            break;
                        }
                    }
                }
                if (! $pageNode) {
                    $lineIndex++;
                    continue;
                }

                $page = $map['by_index'][$lineIndex] ?? null;
                if ($page === null) {
                    $sec = trim((string) $line->getAttribute('data-toc-sec'));
                    if ($sec !== '' && isset($map['by_sec'][$sec])) {
                        $page = $map['by_sec'][$sec];
                    }
                }
                if ($page === null) {
                    $lineIndex++;
                    continue;
                }

                if (strtolower($pageNode->tagName) === 'input') {
                    $pageNode->setAttribute('value', $page);
                } else {
                    while ($pageNode->firstChild) {
                        $pageNode->removeChild($pageNode->firstChild);
                    }
                    $pageNode->appendChild($doc->createTextNode($page));
                }
                $pageNode->setAttribute('data-toc-source', 'fill');
                $lineIndex++;
            }
        }

        $body = $doc->getElementsByTagName('body')->item(0);
        if (! $body) {
            return $html;
        }

        $out = '';
        foreach ($body->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return $out;
    }

    /**
     * injectDocxFieldValuesIntoHtml() — Patch saved preview HTML with latest docx_import_fields values.
     *
     * Skips attachment column locations — those are rendered as download links separately.
     */
    // Patches cached preview HTML DOM nodes with latest field values from docx_import_fields.
    private function injectDocxFieldValuesIntoHtml(string $html, array $fields, ?Report $report = null): string
    {
        if (trim($html) === '' || $fields === []) {
            return $html;
        }

        $attachmentLocations = $this->collectAttachmentFieldLocations($report, $fields);

        libxml_use_internal_errors(true);

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->loadHTML(
            '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        $xpath = new \DOMXPath($doc);

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $location = trim((string) ($field['location'] ?? ''));
            $value    = trim((string) ($field['value'] ?? ''));
            if ($location === '' || $value === '') {
                continue;
            }

            // Attachment cells are rendered as download links elsewhere, not inline text.
            if (isset($attachmentLocations[$location])) {
                continue;
            }

            $escapedLoc = str_replace("'", "\\'", $location);
            $nodes      = $xpath->query("//*[@data-field='{$escapedLoc}']");
            if ($nodes) {
            foreach ($nodes as $node) {
                if (! $node instanceof \DOMElement) {
                    continue;
                }

                // Table cells get a readonly span wrapper; inline spans are updated in place.
                if ($node->tagName === 'td') {
                    $span = $doc->createElement('span');
                    $span->setAttribute('class', 'docx-readonly-value');
                    foreach (preg_split('/\r\n|\r|\n/', $value) as $line) {
                        if ($span->textContent !== '') {
                            $span->appendChild($doc->createElement('br'));
                        }
                        $span->appendChild($doc->createTextNode($line));
                    }
                    while ($node->firstChild) {
                        $node->removeChild($node->firstChild);
                    }
                    $node->appendChild($span);
                    continue;
                }

                if ($node->tagName === 'span') {
                    while ($node->firstChild) {
                        $node->removeChild($node->firstChild);
                    }
                    foreach (preg_split('/\r\n|\r|\n/', $value) as $line) {
                        if ($node->textContent !== '') {
                            $node->appendChild($doc->createElement('br'));
                        }
                        $node->appendChild($doc->createTextNode($line));
                        }
                    }
                }
            }

            // Fill/edit HTML: also patch named inputs/textareas (8.4 notes, 8.5 history, etc.).
            $nameAttr = 'fields[' . $location . ']';
            // XPath 1.0: quote with concat if value contains both quote types.
            $nameLit = str_contains($nameAttr, "'")
                ? ('concat(' . implode(', "\'", ', array_map(
                    static fn (string $p): string => "'" . $p . "'",
                    explode("'", $nameAttr)
                )) . ')')
                : ("'" . $nameAttr . "'");
            $named = $xpath->query(
                '//input[@name=' . $nameLit . ']'
                . '|//textarea[@name=' . $nameLit . ']'
                . '|//select[@name=' . $nameLit . ']'
            );
            if ($named) {
                foreach ($named as $namedNode) {
                    if (! $namedNode instanceof \DOMElement) {
                        continue;
                    }
                    $tag = strtolower($namedNode->tagName);
                    if ($tag === 'textarea') {
                        while ($namedNode->firstChild) {
                            $namedNode->removeChild($namedNode->firstChild);
                        }
                        $namedNode->appendChild($doc->createTextNode($value));
                    } elseif ($tag === 'select') {
                        foreach ($namedNode->getElementsByTagName('option') as $opt) {
                            if (! $opt instanceof \DOMElement) {
                                continue;
                            }
                            if ((string) $opt->getAttribute('value') === $value) {
                                $opt->setAttribute('selected', 'selected');
                            } else {
                                $opt->removeAttribute('selected');
                            }
                        }
                    } else {
                        $namedNode->setAttribute('value', $value);
                    }
                }
            }
        }

        $body = $doc->getElementsByTagName('body')->item(0);
        if (! $body) {
            return $html;
        }

        $out = '';
        foreach ($body->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return $out;
    }

    /**
     * regenerateFilledDocxAttachment() — Call Python /fill and update attachment index 99 on the report.
     *
     * @param  array  $fields  Current docx_import_fields with stamped values
     * @return array|null  attachments array + docx_filled_path when successful
     */
    // Calls Python /fill to produce the downloadable DOCX stored as attachment index 99.
    private function regenerateFilledDocxAttachment(Report $report, array $fields, bool $preserveTocPages = false): ?array
    {
        $fileId = $report->docx_import_path;
        if (! $fileId) {
            return null;
        }

        // NEQ TOC page numbers are typed by the user. Do not replace them on preview or approval.
        if ($this->isNeqImportSource($report->import_source ?? null)) {
            $preserveTocPages = true;
        }

        if (! $this->ensureDocxTemplateOnDisk($report)) {
            Log::warning('DOCX approval stamp: template unavailable for regeneration', [
                'report_id' => $report->id,
                'file_id'   => $fileId,
            ]);

            return null;
        }

        // Guarantee Effective Date is populated before values are sent to Python.
        $fields = $this->ensureEffectiveDateCoverFieldValues($report, $fields);

        $fieldsToPython = [];
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $location = $field['location'] ?? '';
            if ($location === '') {
                continue;
            }
            // Serial numbers / header-echo values stay in the template — do not send to /fill.
            if ($this->shouldSkipFieldForPythonFill($field)) {
                continue;
            }
            $fieldsToPython[] = [
                'location' => $location,
                'value'    => (string) ($field['value'] ?? ''),
                'locked'   => $this->resolveLockedFlagForPythonFill($field),
                'type'     => $field['type'] ?? '',
            ];
        }

        $pythonUrl = env('PYTHON_NLP_URL', 'http://127.0.0.1:8001');
        $originalFilename = $report->template
            ? basename((string) $report->template->docx_template_path)
            : 'report.docx';

        $attachmentLinks = $this->buildAttachmentLinksMap($report, []);

        try {
            // [API] POST {PYTHON_NLP_URL}/fill  (default http://127.0.0.1:8001/fill)
            // Final fill recomputes TOC pages. Word preview sets preserve_toc_pages.
            $response = Http::timeout(90)
                ->asJson()
                ->post("{$pythonUrl}/fill", [
                    'file_id'           => $fileId,
                    'original_filename' => $originalFilename,
                    'fields'            => $fieldsToPython,
                    'attachment_links'  => $this->formatAttachmentLinksForPython($attachmentLinks),
                    'equipment_id'      => in_array($report->import_source, ['requalification', 'revalidation'], true)
                        ? (string) ($report->equipment_id ?? '')
                        : '',
                    'import_source'     => (string) ($report->import_source ?? ''),
                    'preserve_toc_pages' => $preserveTocPages,
                ]);

            if (! $response->successful()) {
                Log::warning('DOCX approval stamp: Python /fill failed', ['body' => $response->body()]);

                return null;
            }

            $repairedValues = $response->json('repaired_field_values') ?? [];
            if (is_array($repairedValues) && $repairedValues !== []) {
                $fields = $this->applyRepairedFieldValues($fields, $repairedValues);
            }
            // TOC page numbers returned by /fill (from Word or LibreOffice layout step).
            $tocValues = $response->json('toc_field_values') ?? [];
            if (! is_array($tocValues) || $tocValues === []) {
                $tocValues = $this->extractTocValuesFromRepaired(
                    is_array($repairedValues) ? $repairedValues : []
                );
            }
            // Preview keeps the page numbers the user typed. Do not write auto TOC digits back.
            if (! $preserveTocPages && is_array($tocValues) && $tocValues !== []) {
                $fields = $this->applyTocFieldValues($fields, $tocValues);
            }

            $outputFilename = $response->json('output_filename');
            if (! $outputFilename) {
                return null;
            }

            $relative         = 'generated_reports/' . $outputFilename;
            $absolutePath     = storage_path('app/public/' . $relative);
            $pythonOutputPath = (string) ($response->json('output_path') ?? '');

            if (! $this->syncFilledDocxToLocalStorage($absolutePath, $pythonOutputPath)) {
                Log::warning('DOCX approval stamp: filled file not found after Python /fill', [
                    'report_id'    => $report->id,
                    'local_path'   => $absolutePath,
                    'python_path'  => $pythonOutputPath,
                ]);

                return null;
            }

            $attachment = [
                'index'         => 99,
                'name'          => 'Generated Document',
                'file_path'     => $relative,
                'stored_name'   => $outputFilename,
                'original_name' => $outputFilename,
                'mime'          => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'size'          => filesize($absolutePath),
            ];

            $attachments = $report->attachments ?? [];
            if (is_string($attachments)) {
                $attachments = json_decode($attachments, true) ?? [];
            }
            // Replace prior generated DOCX attachment rather than accumulating duplicates.
            $attachments = array_values(array_filter(
                is_array($attachments) ? $attachments : [],
                fn ($att) => ! is_array($att) || (int) ($att['index'] ?? 0) !== 99
            ));
            $attachments[] = $attachment;

            return [
                'attachments'      => array_values($attachments),
                'docx_filled_path' => $relative,
                'fields'           => $fields,
            ];
        } catch (\Throwable $e) {
            Log::error('DOCX approval stamp regenerate error: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Show DOCX upload form page.
     */
    // Renders the DOCX upload modal/page for attaching a template to an existing report.
    public function showUploadForm(Report $report)
    {
        return view('reports.docx_import.upload', compact('report'));
    }

    /**
     * Upload DOCX template file to Python /parse.
     */
    // Handles DOCX upload from report show page: parse fields and redirect to fill form.
    public function upload(Request $request, Report $report)
    {
        Log::info('DOCX import upload started', [
            'report_id'   => $report->id,
            'has_file'    => $request->hasFile('docx_file'),
            'route'       => $request->path(),
        ]);

        $request->validate([
            'docx_file' => [
                'required',
                'file',
                'max:10240',
                function (string $attribute, $value, \Closure $fail) {
                    if (!$value instanceof \Illuminate\Http\UploadedFile) {
                        $fail('A valid file upload is required.');
                        return;
                    }

                    $extension = strtolower($value->getClientOriginalExtension());
                    if (!in_array($extension, ['doc', 'docx'], true)) {
                        $fail('The file must be a .doc or .docx document.');
                    }
                },
            ],
        ]);

        try {
            $file = $request->file('docx_file');

            if (!$file || !$file->isValid()) {
                Log::warning('DOCX import upload invalid file', [
                    'report_id' => $report->id,
                    'error'     => $file ? $file->getErrorMessage() : 'missing file',
                ]);

                return redirect()->back()->with('error', 'The uploaded file is invalid. Please try again.');
            }

            $fileContents = file_get_contents($file->getRealPath());
            Log::info('DOCX import file received', [
                'report_id'     => $report->id,
                'original_name' => $file->getClientOriginalName(),
                'extension'     => $file->getClientOriginalExtension(),
                'mime'          => $file->getMimeType(),
                'size_bytes'    => strlen($fileContents),
            ]);

            // Base URL for the Python DOCX service (parse, preview, fill).
            $pythonUrl = env('PYTHON_NLP_URL', 'http://127.0.0.1:8001');

            Log::info('DOCX import calling Python /parse', [
                'report_id'  => $report->id,
                'python_url' => $pythonUrl,
            ]);

            // Send raw file bytes to Python /parse to detect fields and store the template.
            $response = Http::timeout(90)
                ->attach('file', $fileContents, $file->getClientOriginalName())
                ->post("{$pythonUrl}/parse");

            Log::info('DOCX import Python /parse response', [
                'report_id' => $report->id,
                'status'    => $response->status(),
                'successful'=> $response->successful(),
                'body_snippet' => mb_substr($response->body(), 0, 500),
            ]);

            // Parser errors surface to the user; no report state is changed.
            if (!$response->successful()) {
                Log::error('Python /parse failed: ' . $response->body());
                return redirect()->back()->with('error', 'Failed to parse DOCX file: ' . $response->body());
            }

            $result = $response->json();
            // Guard against non-JSON or malformed parser responses.
            if (!is_array($result)) {
                Log::error('DOCX import parse returned non-JSON response', [
                    'report_id' => $report->id,
                    'body'      => $response->body(),
                ]);

                return redirect()->back()->with('error', 'Parsing service returned an invalid response.');
            }

            $fileId = $result['file_id'] ?? null;
            // Normalize parser fields and log which cells are locked vs editable.
            $fields = $this->normalizeImportFields($result['fields'] ?? []);
            $this->logImportFieldLockSummary($fields, $report->id);

            Log::info('DOCX import parsed fields', [
                'report_id'   => $report->id,
                'file_id'     => $fileId,
                'field_count' => count($fields),
                'summary'     => $result['summary'] ?? null,
            ]);

            // file_id links the report to the stored template on disk.
            if (!$fileId) {
                return redirect()->back()->with('error', 'Parsing succeeded but no file_id was returned from service.');
            }

            // Zero fields means the DOCX has no fillable placeholders for this workflow.
            if (empty($fields)) {
                Log::warning('DOCX import parsed zero fields', [
                    'report_id' => $report->id,
                    'file_id'   => $fileId,
                ]);

                return redirect()->back()->with('error', 'No fillable fields were detected in the uploaded DOCX.');
            }

            $this->mirrorDocxTemplateAfterParse(
                $fileId,
                $fileContents,
                $file->getClientOriginalExtension() ?: 'docx'
            );

            $fields = $this->upgradeCommentsTextareaLocations(
                $this->upgradeTocFieldsLocked(
                    $this->upgradeParagraphYesNoFields(
                        $this->upgradeProtocolCoverFieldMetadata(
                            $this->upgradeSignatureApproverRoles(
                                $this->upgradeComponentSectionSerialFields(
                                    $this->upgradeCertificateNumberTextFields(
                                        $this->upgradeApprovalPageFieldMetadata(
                                            $fields,
                                            $report->import_source ?? null
                                    )
                                )
                                ),
                                (string) ($fileId ?? $report->docx_import_path ?? '')
                            )
                        )
                    )
                )
            );

            // NEQ only uses post_setup (fillable draft). Requal/reval upload path stays post_pending.
            $uploadStatus = ($report->import_source ?? '') === 'new_equipment_qualification'
                ? 'post_setup'
                : 'post_pending';

            $report->update([
                'docx_import_path'     => $fileId,
                'docx_import_fields'   => $fields,
                'docx_import_status'   => 'pending',
                'docx_filled_path'     => null,
                'stage'                => 'post',
                'status'               => $uploadStatus,
                'current_step_order'   => 0,
            ]);

            $report->refresh();

            Log::info('DOCX import saved to report', [
                'report_id'        => $report->id,
                'docx_import_path' => $report->docx_import_path,
                'saved_field_count'=> is_array($report->docx_import_fields)
                    ? count($report->docx_import_fields)
                    : 0,
                'docx_import_status' => $report->docx_import_status,
            ]);

            // Confirm JSON field array actually persisted (SQL Server serialization guard).
            if (!is_array($report->docx_import_fields) || count($report->docx_import_fields) === 0) {
                Log::error('DOCX import fields failed to persist on report', [
                    'report_id' => $report->id,
                    'file_id'   => $fileId,
                ]);

                return redirect()->back()->with('error', 'Fields were parsed but could not be saved to the report. Please try again.');
            }

            // Warm preview cache so fill form renders table layout immediately
            $prefilledFields = $this->applyReportPrefills($report, $fields);
            $this->requestPreviewHtml($fileId, $pythonUrl, $prefilledFields, $report->id);

            ProtocolAuditLogger::recordImported($report, $file->getClientOriginalName());

            Log::info('Redirecting to fill form', [
                'report_id' => $report->id,
                'route'     => 'reports.docx-import.fill-form',
            ]);

            return redirect()->route('reports.docx-import.fill-form', $report);

        } catch (\Throwable $e) {
            Log::error('DOCX parsing error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return redirect()->back()->with('error', 'Error connecting to the parsing service: ' . $e->getMessage());
        }
    }

    /**
     * Show form with parsed fields.
     */
    // ── showFillForm() ───────────────────────────────────
    // Displays the DOCX field fill page with table preview or flat field list.
    // Parameters: Report $report (must have docx_import_path and docx_import_fields).
    // Returns: View reports.docx_import.fill with fields, preview HTML, users, equipment data.
    // Key steps:
    // 1. Loads report template and applies equipment/document prefills to fields
    // 2. Fetches table-layout preview HTML from Python /preview (cached)
    // 3. Loads users for POST approval dropdowns (same pattern as fill_docx.blade.php)
    // 4. Passes preview mode flag so blade can render table or fallback list
    public function showFillForm(Report $report)
    {
        @set_time_limit(300);
        $report->load('template');
        \App\Http\Controllers\NotificationController::markSeenForReport(auth()->user(), (int) $report->id);

        if ($report->status === 'rejected') {
            return redirect()
                ->route('reports.show', $report)
                ->with('error', 'This protocol was rejected and cannot be filled again.');
        }

        if ((int) $report->created_by !== (int) auth()->id()) {
            return redirect()
                ->route('reports.show', $report)
                ->with('info', 'Only the document creator can edit or submit for approval. Approvers can review, approve, send back, or reject on the report page.');
        }

        if (in_array($report->status, ['pre_pending', 'post_pending'], true)) {
            return redirect()
                ->route('reports.show', $report)
                ->with('info', 'This document is currently pending approval.');
        }

        // Re-apply metadata upgrades on stored fields in case parser rules changed.
        $fields          = $report->docx_import_fields ?? [];
        $fields          = $this->upgradeCommentsTextareaLocations(
            $this->upgradeTocFieldsLocked(
                $this->upgradeParagraphYesNoFields(
                    $this->upgradeProtocolCoverFieldMetadata(
                        $this->upgradeSignatureApproverRoles(
                            $this->upgradeComponentSectionSerialFields(
                                $this->upgradeCertificateNumberTextFields(
                                    $this->upgradeApprovalPageFieldMetadata(
                                        is_array($fields) ? $fields : [],
                                        $report->import_source ?? null
                                    )
                                )
                            ),
                            (string) ($report->docx_import_path ?? '')
                        )
                    )
                )
            )
        );
        // Att6: restore VERSION "0.2" (etc.) from Word when stored value was "." / cleared.
        if (($report->import_source ?? '') === 'new_equipment_qualification') {
            $fields = $this->restoreAtt6LetterheadFromWordIfNeeded($report, $fields);
        }
        // Upgrade any paragraph with blanks that became paragraph_content back to paragraph_blank
        foreach ($fields as $idx => $f) {
            if (! is_array($f)) {
                continue;
            }
            $loc = (string) ($f['location'] ?? '');
            $lbl = strtolower((string) ($f['label'] ?? ''));
            if (str_starts_with($loc, 'para_') && str_contains($lbl, 'following test were performed')) {
                $fields[$idx]['type'] = 'paragraph_blank';
                $fields[$idx]['widget'] = 'text';
            }
        }

        $coverFieldLocations = $this->buildCoverFieldLocationMap($fields);
        // Drop header cells that duplicate cover-page fields already mapped elsewhere.
        $fields          = $this->pruneSpuriousHeaderImportFields($fields, $coverFieldLocations);
        // Pre-fill equipment, document, and date values from the report record.
        $fields          = $this->applyRequalificationPreApproverNames(
            $report,
            $this->applyReportPrefills($report, $fields)
        );
        $fields          = $this->unlockRevisionHistoryDateFields($fields);
        // NEQ-only: drop invented approval rows not present in the Word table.
        if ($this->isNeqImportSource($report->import_source ?? null) && ! empty($report->docx_import_path)) {
            $fields = $this->pruneApprovalPageFieldsBeyondDocxRows(
                $fields,
                (string) $report->docx_import_path
            );
        }
        // Re-apply NEQ-only unlock/lock rules when opening fill form (revalidation untouched).
        $fields          = $this->applySourceSpecificImportFieldRules($fields, $report->import_source);
        $fields          = $this->restoreSignOffTextFields($fields);
        // Calibration dates from an older protocol stay blank until chosen here.
        $fields          = $this->blankUnselectedDateTimeValues($fields);

        // Persist upgraded approval metadata so Performed By stays a dropdown (not a locked stamp).
        if (
            ($report->import_source ?? '') === 'new_equipment_qualification'
            && in_array($report->status, ['post_setup', 'post_returned', 'draft', 'pre_setup'], true)
        ) {
            $report->docx_import_fields = $fields;
            $report->save();
            if (! empty($report->docx_import_path)) {
                $this->flushDocxPreviewCache((string) $report->docx_import_path, $report->id);
            }
        }

        // Att10: older imports skipped "Safety Checks By (Sign./Date)" as a banner — reparse once.
        if (
            ($report->import_source ?? '') === 'new_equipment_qualification'
            && ! empty($report->docx_import_path)
            && in_array($report->status, ['post_setup', 'post_returned', 'draft', 'pre_setup'], true)
        ) {
            $hasSafetyBySignField = collect($fields)->contains(function ($f) {
                if (! is_array($f)) {
                    return false;
                }
                $label = (string) ($f['label'] ?? '');
                $value = (string) ($f['value'] ?? '');

                return preg_match('/^safety\s*(?:checks?\s*)?s?\s*by\b/i', $label) === 1
                    || preg_match('/^safety\s*(?:checks?\s*)?s?\s*by\b/i', $value) === 1;
            });
            if (! $hasSafetyBySignField) {
                $this->flushDocxPreviewCache((string) $report->docx_import_path, $report->id);
                $reparsed = $this->reparseDocxForReport($report);
                if (! empty($reparsed)) {
                    $fields = $reparsed;
                    $report->docx_import_fields = $fields;
                    $report->save();
                }
            }

            // Att14 IOQ: "(IOQ) REPORT FOR OF ……….. MACHINE" was skipped as ALL-CAPS
            // heading — reparse once so the ellipsis blank is fillable.
            $hasIoqMachineBlank = collect($fields)->contains(function ($f) {
                if (! is_array($f)) {
                    return false;
                }
                $label = strtolower((string) ($f['label'] ?? ''));
                $type = (string) ($f['type'] ?? '');
                $isBlank = in_array($type, ['paragraph_blank', 'paragraph_blank_part'], true);

                return $isBlank && (
                    str_contains($label, 'report for of')
                    || (str_contains($label, '(ioq)') && str_contains($label, 'machine'))
                );
            });
            if (! $hasIoqMachineBlank) {
                $this->flushDocxPreviewCache((string) $report->docx_import_path, $report->id);
                $existingBeforeReparse = is_array($fields) ? $fields : [];
                $reparsed = $this->reparseDocxForReport($report);
                if (! empty($reparsed)) {
                    $fields = $this->mergeDraftOnlyFieldsAfterReparse($existingBeforeReparse, $reparsed);
                    $report->docx_import_fields = $fields;
                    $report->save();
                }
            }
        }

        $isRequalOrReval = in_array(($report->import_source ?? ''), ['requalification', 'revalidation'], true);

        if ($isRequalOrReval && ! empty($report->docx_import_path)) {
            $hasAttachmentFields = collect($fields)->contains(
                fn ($f) => is_array($f) && (($f['type'] ?? '') === 'attachment' || ($f['widget'] ?? '') === 'file')
            );
            $hasAttachmentParas = collect($fields)->contains(
                fn ($f) => is_array($f) && str_contains(strtolower($f['label'] ?? ''), 'attachment-')
            );
            $hasParagraphContent = collect($fields)->contains(
                fn ($f) => is_array($f) && ($f['type'] ?? '') === 'paragraph_content'
            );
            $hasCol0Fields = collect($fields)->contains(
                fn ($f) => is_array($f) && str_ends_with((string) ($f['location'] ?? ''), '_col_0')
            );
            $hasTable0Row1 = collect($fields)->contains(
                fn ($f) => is_array($f) && str_starts_with((string) ($f['location'] ?? ''), 'table_0_row_1')
            );
            $hasTable0Row0 = collect($fields)->contains(
                fn ($f) => is_array($f) && str_starts_with((string) ($f['location'] ?? ''), 'table_0_row_0')
            );
            // Only treat mis-typed editable fields as spurious. Valid parser types like
            // section_heading must NOT force a reparse on every fill load — that wiped
            // draft-only Add Row locations and made pre-approval autosave look broken.
            $hasSpuriousHeadings = collect($fields)->contains(function ($f) {
                if (! is_array($f)) {
                    return false;
                }
                $type = strtolower(trim((string) ($f['type'] ?? '')));
                if (in_array($type, ['locked', 'section_heading', 'heading', 'toc'], true)) {
                    return false;
                }

                return $this->isLockedSectionHeadingText((string) ($f['label'] ?? ''))
                    && in_array($type, ['paragraph_blank', 'text', 'table_2col', 'paragraph_content', ''], true);
            });
            $hasFollowingTestContent = collect($fields)->contains(
                fn ($f) => is_array($f) && str_contains(strtolower((string) ($f['label'] ?? '')), 'following test were performed') && ($f['type'] ?? '') !== 'paragraph_blank'
            );

            if (($hasAttachmentParas && ! $hasAttachmentFields) || ! $hasParagraphContent || ! $hasCol0Fields || ($hasTable0Row1 && ! $hasTable0Row0) || $hasSpuriousHeadings || $hasFollowingTestContent) {
                $this->flushDocxPreviewCache((string) $report->docx_import_path, $report->id);
                $existingBeforeReparse = is_array($fields) ? $fields : [];
                $reparsed = $this->reparseDocxForReport($report);
                if (! empty($reparsed)) {
                    $fields = $this->mergeDraftOnlyFieldsAfterReparse($existingBeforeReparse, $reparsed);
                    $report->docx_import_fields = $fields;
                    $report->save();
                }
            }
        }

        // Section headings (METHODOLOGY:, REPORT APPROVAL:, etc.) must never be editable form fields.
        $fields = array_values(array_filter($fields, function ($f) {
            if (! is_array($f)) {
                return false;
            }
            $lbl = (string) ($f['label'] ?? '');
            $type = (string) ($f['type'] ?? '');
            if ($this->isLockedSectionHeadingText($lbl) && in_array($type, ['paragraph_blank', 'text', 'table_2col'], true)) {
                return false;
            }
            return true;
        }));

        $isPreApprovalMode = $isRequalOrReval
            && in_array($report->status, ['pre_setup', 'pre_returned'], true)
            && $report->stage === 'pre';

        if ($isPreApprovalMode) {
            $fields = $this->applyRequalificationPreApprovalUnlockRules($fields);
            // Send-back: reopen baked user cells (clear prefilled) and drop stale table_ops
            // so fill.blade does not insert duplicate rows already present in the Word template.
            if ($report->status === 'pre_returned') {
                $fields = $this->unlockDocxFieldsForReturnedEdit($fields);
                // Pre-approval rules lock every *_col_0; reopen user-typed left cells
                // (e.g. "cvvv", "add for testing") that were baked into the Word template.
                $fields = $this->unlockReturnedUserTypedCol0Cells($fields);
                // Re-lock Word template columns after send-back unlock (Description,
                // Instrument Name, Methodology, Acceptance, Sr. No.).
                $fields = $this->lockConnectedAccessoriesDescriptions($fields);
                $fields = $this->lockRequalificationTemplateFilledColumns($fields);
                $reportDataForOps = $report->data;
                if (is_string($reportDataForOps)) {
                    $reportDataForOps = json_decode($reportDataForOps, true) ?? [];
                }
                if (! is_array($reportDataForOps)) {
                    $reportDataForOps = [];
                }
                if (! empty($reportDataForOps['original_docx_path']) || ! empty($reportDataForOps['table_ops'])) {
                    $reportDataForOps['table_ops'] = [];
                    $report->data = $reportDataForOps;
                }
            }
            $previewMode = 'pre_approval';
            $report->docx_import_fields = $fields;
            $report->save();
            // Always refresh preview so draft paragraph / Add Row values are not served stale.
            if (! empty($report->docx_import_path)) {
                $this->flushDocxPreviewCache((string) $report->docx_import_path, $report->id);
            }
        } else {
            if ($isRequalOrReval) {
                $fields = $this->applyRequalificationPostPreApprovalLockRules($fields);
                $fields = $this->applyRequalificationPreApproverNames($report, $fields);
                $previewMode = 'requalification';
                if (! empty($report->docx_import_path)) {
                    $this->flushDocxPreviewCache((string) $report->docx_import_path, $report->id);
                }
            } elseif (($report->import_source ?? '') === 'new_equipment_qualification') {
                $fields = $this->applySourceSpecificImportFieldRules(
                    $fields,
                    'new_equipment_qualification'
                );
                $previewMode = 'edit';
                // Force fresh preview so draft values stay yellow inputs after refresh.
                if (! empty($report->docx_import_path)) {
                    $this->flushDocxPreviewCache((string) $report->docx_import_path, $report->id);
                }
            } else {
                $fields = $this->lockConnectedAccessoriesDescriptions($fields);
                $fields = $this->lockExistingTableContent($fields);
                $previewMode = 'edit';
            }

            // After NEQ/requal lock rules: reopen fields for send-back so UX matches fresh fill.
        if (in_array($report->status, ['post_returned', 'pre_returned'], true)) {
            $fields = $this->unlockDocxFieldsForReturnedEdit($fields);
                // unlock clears POST stamps; re-stamp PRE-APPROVAL Signature & Date
                // so send-back / fill open still shows completed pre-approval values.
                if ($isRequalOrReval) {
                    $fields = $this->applyRequalificationPreApproverNames($report, $fields);
                    // Re-lock Word template columns (Description / Instrument Name /
                    // Methodology / Acceptance / Sr. No.) after send-back unlock.
                    $fields = $this->applyRequalificationPostPreApprovalLockRules($fields);
                }
                if ($report->docx_import_status === 'completed') {
                    $report->docx_import_status = 'pending';
                }
            if (! empty($report->docx_import_path)) {
                $this->flushDocxPreviewCache((string) $report->docx_import_path, $report->id);
            }
        }

            $report->docx_import_fields = $fields;
            $report->save();
        }

        // Prefer Word letterhead cover values over create-form junk so JS sync
        // does not overwrite Att6 PRODUCTION / WEIGHING BALANCE / 0.2.
        $equipmentName = trim((string) ($report->equipment_name ?? ''));
        $coverEquipmentName = $this->extractCoverTextValue($fields, 'equipment_name');
        if ($coverEquipmentName !== '') {
            $equipmentName = $coverEquipmentName;
        }
        $equipmentId = trim((string) ($report->equipment_id ?? ''));
        $coverEquipmentId = $this->extractCoverTextValue($fields, 'equipment_id');
        if ($coverEquipmentId !== '') {
            $equipmentId = $coverEquipmentId;
        }
        $facilitySection = trim((string) ($report->facility_section ?? ''));
        $coverFacility = $this->extractCoverTextValue($fields, 'facility_section');
        if ($coverFacility !== '') {
            $facilitySection = $coverFacility;
        }
        $documentNo      = $report->document_no ?? '';
        $protocolDocumentNo = $this->resolveProtocolDocumentNumber($report, $fields);
        $referenceProtocolNo = $this->resolveReferenceProtocolNo($report, $fields);
        $fields = $this->ensureProtocolIdentityHeaderFields($fields, $protocolDocumentNo, $referenceProtocolNo);

        // Upgrade any paragraph with blanks that became paragraph_content back to paragraph_blank
        foreach ($fields as $idx => $f) {
            if (! is_array($f)) {
                continue;
            }
            $loc = (string) ($f['location'] ?? '');
            $lbl = strtolower((string) ($f['label'] ?? ''));
            if (str_starts_with($loc, 'para_') && str_contains($lbl, 'following test were performed')) {
                $fields[$idx]['type'] = 'paragraph_blank';
                $fields[$idx]['widget'] = 'text';
            }
        }

        // Send resolved protocol cover values into Python preview so A4 letterheads display live Document No./Equipment values.
        $previewFields = $this->applyReportPrefills($report, $fields);
        $fileId          = $report->docx_import_path;
        $docxTemplateAvailable = $this->attemptAutoRestoreDocxTemplate($report);
        $pythonUrl       = env('PYTHON_NLP_URL', 'http://127.0.0.1:8001');
        // Fetch table-layout HTML from Python /preview; empty result uses flat field list.
        $previewHtml     = $fileId ? $this->requestPreviewHtml($fileId, $pythonUrl, $previewFields, $report->id, $previewMode) : '';

        if (trim($previewHtml) === '' && $docxTemplateAvailable && $fileId) {
            $this->flushDocxPreviewCache($fileId, $report->id);
            $previewHtml = $this->requestPreviewHtml($fileId, $pythonUrl, $previewFields, $report->id, $previewMode);
        }
        // Replace attachment cells with download links when files were uploaded earlier.
        if (trim($previewHtml) !== '') {
            // Patch named inputs/textareas (esp. synthetic para_*_body notes) so draft
            // values survive even when Python fresh-parse omits those locations.
            $previewHtml = $this->injectDocxFieldValuesIntoHtml($previewHtml, $previewFields, $report);
            $previewHtml = $this->prepareFillFormAttachmentCells($previewHtml, $report);
        }
        // Interactive table only when Python returned editable inputs (same as fresh import).
        $useTablePreview = $this->previewHtmlHasInteractiveFields($previewHtml);
        // Flat list excludes TOC rows that users must not edit.
        $flatListFields  = collect($fields)
            ->filter(fn (array $field) => ! $this->isTocField($field) && ! $this->isLockedSectionHeadingText((string) ($field['label'] ?? '')))
            ->values()
            ->all();

        $documentTitleLocation = $this->findCoverFieldLocation($fields, 'document_title');
        $hasDocumentTitleField = $documentTitleLocation !== null;
        $documentTitle   = $this->resolveDocumentTitle($report, $fields);
        $effectiveDate       = $this->resolveEffectiveDate($report, $fields);
        $effectiveDateLocation = $this->findEffectiveDateFieldLocation($fields);
        $hasEffectiveDateField = $effectiveDateLocation !== null;

        $coverFieldLocations = $this->buildCoverFieldLocationMap($fields);
        // NEQ previews already include the Word section letterhead — never stack the
        // legacy HVAC summary card on top (Att11/Att14 must match the original DOCX).
        $showLegacyBladeHeader = ($coverFieldLocations['document_no'] ?? '') === 'header_0_row_4_col_2'
            && ! $this->isNeqImportSource($report->import_source ?? null);
        $equipmentList   = Equipment::query()
            ->orderBy('equipment_name')
            ->get(['id', 'equipment_name', 'equipment_id', 'facility']);
        // Active users only — users.status is tinyint (1 = active, 0 = inactive).
        $users = User::with(['roles:id,name', 'department:id,name'])
            ->select('id', 'first_name', 'last_name', 'email', 'designation', 'department_id', 'department_name')
            ->where(function ($q) {
                $q->where('status', 1)
                    ->orWhereNull('status');
            })
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(function ($user) {
                $user->display_name = $this->formatUserSelectLabel($user);
                $user->full_name = $this->formatUserNameOnly($user);
                $dept = trim((string) ($user->department_name ?? ''));
                if ($dept === '' && $user->department) {
                    $dept = trim((string) ($user->department->name ?? ''));
                }
                if ($dept === '') {
                    $dept = trim((string) ($user->designation ?? ''));
                }
                $user->department_label = $dept;

                return $user;
            });
        $documents              = $this->getCompletedDocuments();
        // Approved protocols populate the "fill from protocol" autofill dropdown.
        $approvedDocxProtocols  = $this->getApprovedDocxProtocolsForFill(
            $report->id,
            (string) $report->import_source
        )
            ->map(function (Report $protocol) {
                $protocol->fill_option_label = $this->formatApprovedProtocolOptionLabel($protocol);

                return $protocol;
            });

        // Editable subset is logged for debugging lock/prefill issues.
        $editableFields = array_values(array_filter(
            $fields,
            fn (array $field) => !$this->isDocxFieldLocked($field)
        ));

        Log::info('First editable field', [
            'field' => $editableFields[0] ?? null,
        ]);

        Log::info('DOCX showFillForm', [
            'report_id'         => $report->id,
            'file_id'           => $fileId,
            'python_url'        => $pythonUrl,
            'field_count'       => count($fields),
            'editable_count'    => count($editableFields),
            'use_table_preview' => $useTablePreview,
            'preview_html_len'  => strlen($previewHtml),
            'preview_input_count' => substr_count($previewHtml, '<input'),
            'preview_editable_cells' => substr_count($previewHtml, 'docx-cell editable'),
            'flat_list_count'   => count($flatListFields),
        ]);

        $usesApprovalPage      = $this->reportUsesApprovalPageTable($fields);
        $presentApproverRoles  = $this->collectPostApproverRolesFromFields($fields);
        if (($report->import_source ?? '') === 'new_equipment_qualification' && $usesApprovalPage) {
            $presentApproverRoles = $this->ensureNeqPostApprovalRoleChain($presentApproverRoles);
        }
        $importSource          = $report->import_source;
        $importSourceLabel     = Report::importSourceLabel($importSource);
        $importSourceSectionTitle = Report::importSourceSectionTitle($importSource);

        $reportData = $report->data ?? [];
        if (is_string($reportData)) {
            $reportData = json_decode($reportData, true) ?? [];
        }
        if (! is_array($reportData)) {
            $reportData = [];
        }
        $draftApprovers = [];
        foreach ($presentApproverRoles as $roleKey) {
            if (! empty($reportData[$roleKey]) && is_numeric($reportData[$roleKey])) {
                $draftApprovers[$roleKey] = (int) $reportData[$roleKey];
            }
        }
        $draftSavedAt = (string) ($reportData['docx_fill_draft_saved_at'] ?? '');
        $tableOps = $this->normalizeTableOpsPayload($reportData['table_ops'] ?? []);
        if (($reportData['table_ops'] ?? null) !== $tableOps) {
            $reportData['table_ops'] = $tableOps;
            $report->data = $reportData;
            $report->save();
        }
        $requalFillLocked = $this->requalificationFillIsLocked($report);
        $requalPreEditable = $requalFillLocked
            && in_array($report->status, ['pre_setup', 'pre_returned'], true);
        $draftPreApprovers = [];
        $preApprovedUserNames = [];
        foreach (array_keys(self::PRE_APPROVER_FIELDS) as $roleKey) {
            if (! empty($reportData[$roleKey]) && is_numeric($reportData[$roleKey])) {
                $draftPreApprovers[$roleKey] = (int) $reportData[$roleKey];
            }
            $preApprovedUserNames[$roleKey] = $this->preApproverNameAndDesignation($reportData, $roleKey);
        }

        $isPreApprovalMode = in_array($report->import_source, ['requalification', 'revalidation'], true)
            && in_array($report->status, ['pre_setup', 'pre_returned'], true)
            && $report->stage === 'pre';

        return view('reports.docx_import.fill', compact(
            'report',
            'fields',
            'flatListFields',
            'previewHtml',
            'useTablePreview',
            'docxTemplateAvailable',
            'usesApprovalPage',
            'presentApproverRoles',
            'importSource',
            'importSourceLabel',
            'importSourceSectionTitle',
            'equipmentName',
            'equipmentId',
            'facilitySection',
            'documentNo',
            'protocolDocumentNo',
            'referenceProtocolNo',
            'documentTitle',
            'documentTitleLocation',
            'hasDocumentTitleField',
            'effectiveDate',
            'effectiveDateLocation',
            'hasEffectiveDateField',
            'coverFieldLocations',
            'showLegacyBladeHeader',
            'equipmentList',
            'users',
            'documents',
            'approvedDocxProtocols',
            'draftApprovers',
            'draftSavedAt',
            'tableOps',
            'requalFillLocked',
            'requalPreEditable',
            'draftPreApprovers',
            'preApprovedUserNames',
            'isPreApprovalMode'
        ));
    }

    /**
     * Persist mid-fill values without generating DOCX or starting approval.
     * Keeps docx_import_status=pending so the user can resume later.
     */
    public function saveDraft(Request $request, Report $report)
    {
        if ($report->status === 'rejected') {
            return response()->json([
                'success' => false,
                'message' => 'This protocol was rejected and cannot be filled again.',
            ], 403);
        }

        $isPreApprovalDraft = in_array($report->import_source, ['requalification', 'revalidation'], true)
            && in_array($report->status, ['pre_setup', 'pre_returned'], true)
            && $report->stage === 'pre';

        if ($this->requalificationFillIsLocked($report) && ! $isPreApprovalDraft) {
            return response()->json([
                'success' => true,
                'locked'  => true,
                'message' => 'Fill stays locked until pre-approval is complete.',
            ]);
        }

        $isReturnedEdit = in_array($report->status, ['post_returned', 'pre_returned'], true);
        if ($report->docx_import_status === 'completed' && ! $isReturnedEdit) {
            return response()->json([
                'success' => false,
                'message' => 'This document is already submitted.',
            ], 422);
        }

        $request->validate([
            'document_title'   => ['nullable', 'string', 'max:255'],
            'equipment_name'   => ['nullable', 'string', 'max:255'],
            'equipment_id'     => ['nullable', 'string', 'max:255'],
            'facility_section' => ['nullable', 'string', 'max:255'],
            'effective_date'   => ['nullable', 'string', 'max:255'],
            'version'          => ['nullable', 'string', 'max:255'],
            'document_id'      => ['nullable', 'integer', 'exists:documents,id'],
            'protocol_document_no' => ['nullable', 'string', 'max:255'],
            'reference_protocol_no' => ['nullable', 'string', 'max:255'],
            'fields'           => ['nullable', 'array'],
            'post_prepared_by_name' => ['nullable', 'integer', 'exists:users,id'],
            'post_checked_by_engineering_name' => ['nullable', 'integer', 'exists:users,id'],
            'post_checked_by_production_name' => ['nullable', 'integer', 'exists:users,id'],
            'post_checked_by_quality_name' => ['nullable', 'integer', 'exists:users,id'],
            'post_checked_by_validation_name' => ['nullable', 'integer', 'exists:users,id'],
            'post_approved_by_user_name' => ['nullable', 'integer', 'exists:users,id'],
            'post_approved_by_quality_name' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        if ($request->hasFile('attachments')) {
            $request->validate([
                'attachments'   => ['array'],
                'attachments.*' => ['file', 'max:20480'],
            ]);
            foreach ($request->file('attachments', []) as $file) {
                if (! $file || ! $file->isValid()) {
                    continue;
                }
                $extension = strtolower($file->getClientOriginalExtension());
                if (! in_array($extension, ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'], true)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Upload a PDF, DOC, DOCX, JPG, or PNG file.',
                    ], 422);
                }
            }
        }

        $importFields = $this->upgradeCommentsTextareaLocations(
            $this->upgradeTocFieldsLocked(
                $this->upgradeParagraphYesNoFields(
                    $this->upgradeProtocolCoverFieldMetadata(
                        $this->upgradeSignatureApproverRoles(
                            $this->upgradeComponentSectionSerialFields(
                                $this->upgradeCertificateNumberTextFields(
                                    $this->upgradeApprovalPageFieldMetadata(
                                        is_array($report->docx_import_fields ?? null) ? $report->docx_import_fields : [],
                                        $report->import_source ?? null
                                    )
                                )
                            ),
                            (string) ($report->docx_import_path ?? '')
                        )
                    )
                )
            )
        );
        $coverLoc = $this->buildCoverFieldLocationMap($importFields);
        $importFields = $this->pruneSpuriousHeaderImportFields($importFields, $coverLoc);
        $importFields = $this->unlockRevisionHistoryDateFields($importFields);
        if ($this->isNeqImportSource($report->import_source ?? null) && ! empty($report->docx_import_path)) {
            $importFields = $this->pruneApprovalPageFieldsBeyondDocxRows(
                $importFields,
                (string) $report->docx_import_path
            );
        }

        if ($isPreApprovalDraft) {
            $importFields = $this->applyRequalificationPreApprovalUnlockRules($importFields);
        } else {
            if (in_array(($report->import_source ?? ''), ['requalification', 'revalidation'], true)) {
                $importFields = $this->applyRequalificationPostPreApprovalLockRules($importFields);
            } elseif (($report->import_source ?? '') === 'new_equipment_qualification') {
                $importFields = $this->applySourceSpecificImportFieldRules(
                    $importFields,
                    'new_equipment_qualification'
                );
            } else {
                $importFields = $this->lockConnectedAccessoriesDescriptions($importFields);
                $importFields = $this->lockExistingTableContent($importFields);
            }
        }
        if ($isReturnedEdit) {
            $importFields = $this->unlockDocxFieldsForReturnedEdit($importFields);
        }
        $importFields = $this->applyRequalificationPreApproverNames($report, $importFields);

        $formFields = $request->input('fields', []);
        if (! is_array($formFields)) {
            $formFields = [];
        }

        $formFields['equipment_name']   = (string) $request->input('equipment_name', $report->equipment_name ?? '');
        $formFields['equipment_id']     = (string) $request->input('equipment_id', $report->equipment_id ?? '');
        $formFields['facility_section'] = (string) $request->input('facility_section', $report->facility_section ?? '');
        $formFields['document_title']   = (string) $request->input(
            'document_title',
            $this->resolveDocumentTitle($report, $importFields)
        );

        if (! empty($coverLoc['document_no'])) {
            $docNoText = trim((string) $request->input('protocol_document_no', ''));
            if ($docNoText !== '') {
                $formFields[$coverLoc['document_no']] = $docNoText;
            }
        }

        $effectiveDateLocation = $this->findEffectiveDateFieldLocation($importFields);
        $hasEffectiveDateField = $effectiveDateLocation !== null;
        if ($hasEffectiveDateField) {
            $formFields['effective_date'] = (string) $request->input(
                'effective_date',
                $this->resolveEffectiveDate($report, $importFields)
            );
        } else {
            unset($formFields['effective_date']);
        }

        $formFields = $this->filterInvalidHeaderFormFields($formFields, $importFields, $coverLoc);
        $formFields = $this->remapCommentsFormSubmissions($formFields, $importFields);

        $draftDataSeed = is_array($report->data) ? $report->data : [];
        if (is_string($report->data)) {
            $draftDataSeed = json_decode($report->data, true) ?? [];
        }
        [$draftDataSeed, $formFields] = $this->mergeProtocolIdentityIntoDataAndFields(
            $request,
            is_array($draftDataSeed) ? $draftDataSeed : [],
            $formFields,
            $importFields
        );

        $approverData = $request->only([
            'post_prepared_by_name',
            'post_checked_by_engineering_name',
            'post_checked_by_production_name',
            'post_checked_by_quality_name',
            'post_checked_by_validation_name',
            'post_approved_by_user_name',
            'post_approved_by_quality_name',
        ]);

        $headerMap = $this->buildCoverHeaderMap($coverLoc);
        foreach ($headerMap as $headerKey => $location) {
            if (! isset($formFields[$headerKey]) || $formFields[$headerKey] === '') {
                continue;
            }
            $value = (string) $formFields[$headerKey];
            if ($headerKey === 'effective_date') {
                $value = $this->formatEffectiveDateForDocx($value);
            }
            $formFields[$location] = $value;
        }

        // Prefer mapping-card Document Number over document master FK for cover cells.
        $reportForCover = clone $report;
        $coverData = is_array($reportForCover->data) ? $reportForCover->data : [];
        if (is_string($reportForCover->data)) {
            $coverData = json_decode($reportForCover->data, true) ?? [];
        }
        if (! is_array($coverData)) {
            $coverData = [];
        }
        $coverData['document_number'] = (string) ($draftDataSeed['document_number'] ?? '');
        $coverData['reference_protocol_no'] = (string) ($draftDataSeed['reference_protocol_no'] ?? '');
        $reportForCover->data = $coverData;

        $formFields = $this->applyProtocolCoverValues($formFields, $importFields, $reportForCover);
        $formFields = $this->applySignatureApproverValues($formFields, $importFields, $approverData);

        // Chosen attachment files are part of the draft, same as typed cells.
        [$formFields, $uploadedAttachments] = $this->handleDocxAttachmentUploads(
            $request,
            $report,
            $formFields
        );
        $uploadedNames = [];
        foreach ($uploadedAttachments as $uploadedAttachment) {
            $uploadedLocation = (string) ($uploadedAttachment['location'] ?? '');
            if ($uploadedLocation === '') {
                continue;
            }
            $uploadedNames[$uploadedLocation] = (string) (
                $uploadedAttachment['original_name'] ?? $uploadedAttachment['name'] ?? ''
            );
        }

        $updatedFields = [];
        foreach ($importFields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $location = (string) ($field['location'] ?? '');
            if ($location === '') {
                continue;
            }

            $isLocked = $this->isDocxFieldLocked($field);
            $isQualHistoryLock = $this->isQualificationHistoryTemplateLock($field);
            if ($isQualHistoryLock) {
                $isLocked = true;
            }
            $fieldType = strtolower(trim((string) ($field['type'] ?? '')));
            $fieldWidget = strtolower(trim((string) ($field['widget'] ?? '')));
            $isChoiceField = in_array($fieldType, ['paragraph_radio', 'paragraph_checkbox', 'table_radio', 'table_checkbox'], true)
                || in_array($fieldWidget, ['radio', 'checkbox'], true);
            if ($isChoiceField && ! $isQualHistoryLock) {
                $isLocked = false;
            }
            // 8.4 P&ID notes / section body textareas must always accept draft values.
            if (
                preg_match('/_body$/', $location) === 1
                || str_contains($location, 'verification_pid')
            ) {
                $isLocked = false;
            }
            $originalValue = (string) ($field['value'] ?? '');
            $submittedValue = $originalValue;
            $isTocPageField = (
                (str_contains($location, '_toc_') || str_starts_with($location, 'toc_sdt_'))
                && ! str_ends_with($location, '_title')
            );
            if ($isTocPageField && array_key_exists($location, $formFields)) {
                // Manual TOC page digits must be stored even if a heading rule locked the row.
                $submittedValue = (string) $formFields[$location];
                $isLocked = false;
                $userFilled = true;
            }
            $userFilled = ($field['user_filled'] ?? false) === true
                || ($field['draft_editable'] ?? false) === true
                || $isChoiceField;
            $isNeqDraftFill = ($report->import_source ?? '') === 'new_equipment_qualification'
                && in_array($report->status, ['post_setup', 'post_returned', 'draft', 'pre_setup'], true);
            $isNeqDataRowFill = $isNeqDraftFill
                && preg_match('/^table_\d+_row_[1-9]\d*_col_/', $location) === 1
                && in_array($fieldType, ['table_multicol', 'table_2col', 'text', 'textarea', ''], true)
                && ! $this->isTemplateContentHeader((string) ($field['label'] ?? ''));
            if ($isQualHistoryLock) {
                // Never let draft autosave reopen Word-static Qualification History cells.
                $submittedValue = $originalValue;
                $isLocked = true;
                $userFilled = false;
            } elseif (! $isLocked && array_key_exists($location, $formFields)) {
                $submittedValue = (string) $formFields[$location];
                // Any draft-saved unlocked field must stay editable after refresh
                // (8.4 P&ID notes, 8.5 history dates, calibration cells, etc.).
                $userFilled = true;
                $isLocked = false;
            } elseif ($isPreApprovalDraft && array_key_exists($location, $formFields)) {
                // Pre-approval may still mark col_0 locked; accept typed draft values anyway.
                $submittedValue = (string) $formFields[$location];
                $isLocked = false;
                $userFilled = true;
            } elseif ($isNeqDataRowFill && array_key_exists($location, $formFields)) {
                // NEQ draft: keep URS/DQ/IOQ/Remark (and similar) editable even if a
                // prior refresh wrongly marked them prefilled/locked.
                $submittedValue = (string) $formFields[$location];
                $isLocked = false;
                $userFilled = true;
            }
            if (isset($uploadedNames[$location])) {
                $submittedValue = $uploadedNames[$location];
                $isLocked = false;
                $userFilled = true;
            } elseif (
                strtolower((string) ($field['type'] ?? '')) === 'attachment'
                && trim($submittedValue) === ''
                && trim($originalValue) !== ''
            ) {
                // A later text autosave must not wipe a file name already stored.
                $submittedValue = $originalValue;
            }

            $updatedField = $field;
            $updatedField['value'] = $isLocked ? $originalValue : $submittedValue;
            $updatedField['locked'] = $isLocked;
            if ($userFilled && ! $isLocked) {
                $updatedField['user_filled'] = true;
                $updatedField['draft_editable'] = true;
            }
            if (isset($uploadedNames[$location])) {
                $updatedField['type'] = 'attachment';
                $updatedField['widget'] = 'file';
            }
            if ($this->isSignOffTextField($field)) {
                $updatedField['widget'] = 'text';
                unset($updatedField['date_selected']);
            } elseif (
                ! $isQualHistoryLock
                && ! preg_match('/^approval\s*date$/i', trim((string) ($updatedField['value'] ?? '')))
                && ($this->isDocxDateTimeEntryField($field) || $this->isDocxDateTimeEntryField($updatedField))
            ) {
                $norm = $this->normalizeDateTimeInputValue((string) ($updatedField['value'] ?? ''));
                $picked = $norm !== '' || trim((string) ($updatedField['value'] ?? '')) !== '';
                if ($norm !== '') {
                    $updatedField['value'] = $norm;
                }
                $updatedField['date_selected'] = $picked;
                if ($picked) {
                    $updatedField['widget'] = 'datetime-local';
                    // Draft-saved dates must stay editable after refresh.
                    $updatedField['locked'] = false;
                    $updatedField['user_filled'] = true;
                    $updatedField['draft_editable'] = true;
                    $updatedField['prefilled'] = false;
                }
            }
            $updatedFields[] = $updatedField;
        }

        // Re-apply after draft merge so autosave cannot reopen the PQ template row.
        if ($isPreApprovalDraft || in_array(($report->import_source ?? ''), ['requalification', 'revalidation'], true)) {
            $updatedFields = $this->lockQualificationHistoryTemplateCells($updatedFields);
        }

        $knownLocations = array_column($updatedFields, 'location');
        foreach ($formFields as $location => $submittedValue) {
            if (! is_string($location) || in_array($location, $knownLocations, true)) {
                continue;
            }
            if (! preg_match('/^(para_|table_|header_|txbx_|toc_)/', $location)) {
                continue;
            }
            $newField = [
                'label'    => $location,
                'location' => $location,
                'value'    => (string) $submittedValue,
                'locked'   => false,
                'type'     => isset($uploadedNames[$location]) ? 'attachment' : (
                    str_contains($location, '_body') ? 'paragraph_content' : (
                        str_starts_with($location, 'txbx_') ? 'textbox_blank_part' : ''
                    )
                ),
                'widget'   => isset($uploadedNames[$location]) ? 'file' : (
                    str_contains($location, '_body') ? 'textarea' : null
                ),
                // Always mark draft-created locations so refresh/autosave keep them editable.
                'user_filled'     => true,
                'draft_editable'  => true,
                'prefilled'       => false,
            ];
            $norm = $this->normalizeDateTimeInputValue((string) $submittedValue);
            if ($norm !== '') {
                $newField['value'] = $norm;
                $newField['widget'] = 'datetime-local';
                $newField['date_selected'] = true;
            }
            $updatedFields[] = $newField;
            $knownLocations[] = $location;
        }

        $data = $report->data ?? [];
        if (is_string($data)) {
            $data = json_decode($data, true) ?? [];
        }
        if (! is_array($data)) {
            $data = [];
        }

        if ($request->filled('document_id')) {
            $data['document_id'] = $request->input('document_id');
            $report->document_no = (int) $request->input('document_id');
        }
        if ($request->filled('version')) {
            $data['version'] = $request->input('version');
            $report->version = $request->input('version');
        }
        if ($request->has('facility_section')) {
            $data['facility_section'] = (string) $request->input('facility_section', '');
            $report->facility_section = (string) $request->input('facility_section', '');
        }
        if ($request->has('equipment_name')) {
            $data['equipment_name'] = (string) $request->input('equipment_name', '');
            $report->equipment_name = (string) $request->input('equipment_name', '');
        }
        if ($request->has('equipment_id')) {
            $data['equipment_id'] = (string) $request->input('equipment_id', '');
            $report->equipment_id = (string) $request->input('equipment_id', '');
        }
        if ($request->has('document_title')) {
            $data['document_title'] = (string) $request->input('document_title', '');
        }
        if ($hasEffectiveDateField && $request->has('effective_date')) {
            $effectiveDate = trim((string) $request->input('effective_date', ''));
            $data['effective_date'] = $effectiveDate !== ''
                ? $this->formatEffectiveDateForDocx($effectiveDate)
                : '';
        }

        $data['document_number'] = (string) ($draftDataSeed['document_number'] ?? $data['document_number'] ?? '');
        $data['reference_protocol_no'] = (string) ($draftDataSeed['reference_protocol_no'] ?? $data['reference_protocol_no'] ?? '');

        $updatedFields = $this->ensureProtocolIdentityHeaderFields(
            $updatedFields,
            (string) ($data['document_number'] ?? ''),
            (string) ($data['reference_protocol_no'] ?? '')
        );

        foreach ([
            'post_prepared_by_name',
            'post_checked_by_engineering_name',
            'post_checked_by_production_name',
            'post_checked_by_quality_name',
            'post_checked_by_validation_name',
            'post_approved_by_user_name',
            'post_approved_by_quality_name',
        ] as $roleKey) {
            if ($request->filled($roleKey) && is_numeric($request->input($roleKey))) {
                $data[$roleKey] = (int) $request->input($roleKey);
            }
        }

        foreach (array_keys(self::PRE_APPROVER_FIELDS) as $roleKey) {
            if ($request->filled($roleKey) && is_numeric($request->input($roleKey))) {
                $data[$roleKey] = (int) $request->input($roleKey);
            }
        }

        $tableOps = $this->normalizeTableOpsPayload($request->input('table_ops'));
        $data['table_ops'] = $tableOps;

        $savedAt = $this->formatSignatureDateTimeForDocx(now());
        $data['docx_fill_draft_saved_at'] = $savedAt;

        $savedAttachmentPayload = [];
        if ($uploadedAttachments !== []) {
            $existingAttachments = $report->attachments ?? [];
            if (is_string($existingAttachments)) {
                $existingAttachments = json_decode($existingAttachments, true) ?? [];
            }
            if (! is_array($existingAttachments)) {
                $existingAttachments = [];
            }
            $report->attachments = $this->mergeAttachmentsByLocation(
                array_values($existingAttachments),
                $uploadedAttachments
            );
            foreach ($uploadedAttachments as $uploadedAttachment) {
                $uploadedLocation = (string) ($uploadedAttachment['location'] ?? '');
                $uploadedIndex = (int) ($uploadedAttachment['index'] ?? 0);
                if ($uploadedLocation === '' || $uploadedIndex <= 0) {
                    continue;
                }
                $savedAttachmentPayload[$uploadedLocation] = [
                    'name' => (string) ($uploadedAttachment['original_name'] ?? $uploadedAttachment['name'] ?? 'Open attachment'),
                    'url'  => route('reports.attachments.download', [
                        'report' => $report->id,
                        'index'  => $uploadedIndex,
                    ]),
                ];
            }
        }

        $report->docx_import_fields = $updatedFields;
        $report->data = $data;
        if ($report->docx_import_status !== 'completed') {
            $report->docx_import_status = 'pending';
        }

        // A slower autosave must not replace page numbers the user just typed
        // and sent with Word Preview. Higher draft_seq wins.
        $incomingSeq = (int) $request->input('draft_seq', 0);
        $seqKey = 'docx-draft-seq-' . $report->id;
        $seqLock = null;
        if ($incomingSeq > 0) {
            $seqLock = Cache::lock($seqKey . '-lock', 20);
            try {
                $seqLock->block(12);
                $currentSeq = (int) Cache::get($seqKey, 0);
                if ($currentSeq > $incomingSeq) {
                    $seqLock->release();

                    return response()->json([
                        'success'  => true,
                        'stale'    => true,
                        'message'  => 'Draft skipped because a newer edit was already saved.',
                        'saved_at' => (string) data_get($report->data, 'docx_fill_draft_saved_at', ''),
                    ]);
                }
                Cache::put($seqKey, $incomingSeq, now()->addDays(14));
            } catch (\Throwable $e) {
                Log::warning('DOCX draft sequence lock skipped', [
                    'report_id' => $report->id,
                    'error'     => $e->getMessage(),
                ]);
                $seqLock = null;
            }
        }

        try {
            $report->save();
        } finally {
            if ($seqLock) {
                try {
                    $seqLock->release();
                } catch (\Throwable $e) {
                }
            }
        }

        if (! empty($report->docx_import_path)) {
            $this->flushDocxPreviewCache((string) $report->docx_import_path, $report->id);
        }

        Log::info('DOCX fill draft saved', [
            'report_id'    => $report->id,
            'field_count'  => count($updatedFields),
            'saved_at'     => $savedAt,
        ]);

        return response()->json([
            'success'     => true,
            'message'     => 'Draft saved. You can leave and continue later.',
            'saved_at'    => $savedAt,
            'attachments' => $savedAttachmentPayload,
        ]);
    }

    /**
     * Return field values from an approved protocol for autofill on the current fill form.
     */
    // JSON endpoint: copy unlocked field values from another approved protocol by location key.
    public function fillFromApprovedProtocol(Report $report, Report $sourceReport)
    {
        if ($report->status === 'rejected') {
            return response()->json([
                'status'  => 'error',
                'message' => 'This protocol was rejected and cannot be filled again.',
            ], 403);
        }

        if ($this->requalificationFillIsLocked($report)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Fill with DOCX opens after pre-approval is complete.',
            ], 403);
        }

        // A report cannot autofill from itself.
        if ($report->id === $sourceReport->id) {
            return response()->json([
                'status'  => 'error',
                'message' => 'A protocol cannot be used to fill itself.',
            ], 422);
        }

        $currentSource = strtolower(trim((string) $report->import_source));
        $selectedSource = strtolower(trim((string) $sourceReport->import_source));
        if ($currentSource !== '' && $selectedSource !== $currentSource) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Select an approved protocol of the same type.',
            ], 422);
        }

        // Only fully approved protocols may be used as autofill sources.
        if ($sourceReport->status !== 'approved') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Selected protocol is not approved.',
            ], 422);
        }

        // Source must have a prior successful DOCX import on disk.
        if (empty($sourceReport->docx_import_path)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'No DOCX data found for this protocol.',
            ], 404);
        }

        $sourceFields = $sourceReport->docx_import_fields ?? [];
        if (! is_array($sourceFields) || $sourceFields === []) {
            return response()->json([
                'status'  => 'error',
                'message' => 'No DOCX data found for this protocol.',
            ], 404);
        }

        $currentFields = $report->docx_import_fields ?? [];
        // Current form must already have parsed fields to receive copied values.
        if (! is_array($currentFields) || $currentFields === []) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Current protocol has no DOCX fields to fill.',
            ], 422);
        }

        $payload = $this->buildFillFromProtocolPayload($currentFields, $sourceReport, $sourceFields);

        // No overlapping locations means the two protocols are incompatible for autofill.
        if ($payload['fields'] === [] && $payload['approvers'] === []) {
            return response()->json([
                'status'  => 'error',
                'message' => 'No matching field data found between the selected protocol and this form.',
            ], 404);
        }

        ProtocolAuditLogger::recordFillWithDocx($report, $sourceReport, (int) $payload['filled_count']);

        $sourceCode = trim($sourceReport->displayCode());
        if ($sourceCode === '') {
            $sourceCode = (string) $sourceReport->id;
        }

        return response()->json([
            'status'       => 'success',
            'fields'       => $payload['fields'],
            'approvers'    => $payload['approvers'],
            'filled_count' => $payload['filled_count'],
            'source_id'    => $sourceReport->id,
            'source_code'  => $sourceCode,
        ]);
    }

    /**
     * Approved DOCX-import protocols that can be used as autofill sources.
     *
     * @return \Illuminate\Support\Collection<int, Report>
     */
    private function getApprovedDocxProtocolsForFill(int $excludeReportId, string $importSource = '')
    {
        $query = Report::query()
            ->where('status', 'approved')
            ->where('docx_import_status', 'completed')
            ->whereNotNull('docx_import_path')
            ->whereNotNull('docx_import_fields')
            ->where('id', '!=', $excludeReportId);

        $importSource = strtolower(trim($importSource));
        if (array_key_exists($importSource, Report::importSourceLabels())) {
            $driver = $query->getConnection()->getDriverName();
            if ($driver === 'sqlsrv') {
                $query->whereRaw(
                    "LOWER(JSON_VALUE(CAST([data] AS nvarchar(max)), '$.import_source')) = ?",
                    [$importSource]
                );
            } else {
                $query->where('data->import_source', $importSource);
            }
        }

        return $query
            ->with(['template:id,name', 'document:id,title'])
            ->orderByDesc('id')
            ->get([
                'id',
                'document_no',
                'equipment_id',
                'equipment_name',
                'facility_section',
                'template_id',
                'version',
                'display_code',
                'display_seq',
                'data',
            ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $currentFields
     * @param  array<int, array<string, mixed>>  $sourceFields
     * @return array{fields: array<string, string>, approvers: array<string, int>, filled_count: int}
     */
    // Copy content cells only. Approval name, signature, date, and selected users stay blank.
    private function buildFillFromProtocolPayload(
        array $currentFields,
        Report $sourceReport,
        array $sourceFields
    ): array {
        $sourceByLocation = [];
        foreach ($sourceFields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $location = (string) ($field['location'] ?? '');
            if ($location === '') {
                continue;
            }
            $sourceByLocation[$location] = $field;
        }

        $matchedFields = [];
        foreach ($currentFields as $field) {
            if (! is_array($field)) {
                continue;
            }
            // Locked cells keep their VIVA values and are never copied from another protocol.
            if ($this->isDocxFieldLocked($field)) {
                continue;
            }
            // Approval name, signature, and date stay empty so this protocol
            // can be signed fresh. Attachment uploads stay empty too.
            if ($this->isApprovalFieldExcludedFromDocxFill($field)) {
                continue;
            }
            if (strtolower((string) ($field['type'] ?? '')) === 'attachment') {
                continue;
            }
            // Date and time are chosen on this protocol. Do not copy them from the old file.
            if ($this->isDocxDateTimeEntryField($field)) {
                continue;
            }

            $location = (string) ($field['location'] ?? '');
            $sourceField = $sourceByLocation[$location] ?? null;
            if ($location === '' || ! is_array($sourceField)) {
                continue;
            }
            if ($this->isApprovalFieldExcludedFromDocxFill($sourceField)) {
                continue;
            }
            if ($this->isDocxDateTimeEntryField($sourceField)) {
                continue;
            }

            $value = trim((string) ($sourceField['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            // Drop leftover e-sign / stamp text even if the column label was ambiguous.
            if ($this->looksLikeDocxApprovalStampValue($value, $field) || $this->looksLikeDocxApprovalStampValue($value, $sourceField)) {
                continue;
            }

            $matchedFields[$location] = $value;
        }

        return [
            'fields'       => $matchedFields,
            'approvers'    => [],
            'filled_count' => count($matchedFields),
        ];
    }

    /**
     * Detect stamped e-sign / approval text that must not transfer via Fill with DOCX.
     */
    private function looksLikeDocxApprovalStampValue(string $value, array $field): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        $label = strtolower(trim((string) ($field['label'] ?? '')));
        // "Name - Designation" style stamps in approval/name columns.
        if (
            preg_match('/^.+\s+-\s+.+$/u', $value) === 1
            && (
                $label === 'name'
                || str_contains($label, 'name') && str_contains($label, 'designation')
                || preg_match('/\b(prepared|checked|approved)\s*by\b/', $label) === 1
                || trim((string) ($field['approver_role'] ?? '')) !== ''
                || ! empty($field['is_pre_approver'])
            )
        ) {
            return true;
        }

        // Datetime stamps: 21-11-2026 11:03:44 / 2026-11-21 11:03
        if (preg_match('/\b\d{1,4}[-\/]\d{1,2}[-\/]\d{1,4}(?:\s+\d{1,2}:\d{2}(?::\d{2})?)?\b/', $value) === 1) {
            if (
                str_contains($label, 'sign')
                || str_contains($label, 'date')
                || str_contains($label, 'time')
                || preg_match('/\b(prepared|checked|approved|done)\s*by\b/', $label) === 1
                || trim((string) ($field['approver_role'] ?? '')) !== ''
            ) {
                return true;
            }
        }

        return false;
    }

    /** Dropdown label: list code (REV10 / NEQ1) / Equipment ID / Equipment Name / Facility */
    private function formatApprovedProtocolOptionLabel(Report $report): string
    {
        $code = trim($report->displayCode());
        if ($code === '') {
            $code = (string) $report->id;
        }

        $parts = array_values(array_filter([
            $code,
            trim((string) ($report->equipment_id ?? '')),
            trim((string) ($report->equipment_name ?? '')),
            trim((string) ($report->facility_section ?? '')),
        ], fn (string $part) => $part !== ''));

        return $parts !== [] ? implode(' / ', $parts) : $code;
    }

    /**
     * Requalification and Revalidation stay locked until every PRE approver has approved.
     * Reports already in the POST stage keep the previous fill behaviour.
     */
    private function requalificationFillIsLocked(Report $report): bool
    {
        if (! in_array($report->import_source, ['requalification', 'revalidation'], true)) {
            return false;
        }

        if (in_array($report->status, ['pre_approved', 'post_pending', 'post_returned', 'approved'], true)) {
            return false;
        }

        if ($report->stage === 'post') {
            return false;
        }

        return true;
    }

    private function submitRequalificationPreApproval(Request $request, Report $report)
    {
        if (! in_array($report->import_source, ['requalification', 'revalidation'], true) || ! $this->requalificationFillIsLocked($report)) {
            return back()->with('error', 'Pre-approval has already been completed for this document.');
        }

        if (! in_array($report->status, ['pre_setup', 'pre_returned'], true)) {
            return back()->with('error', 'Pre-approval is already in progress.');
        }

        abort_unless((int) $report->created_by === (int) auth()->id(), 403);

        $rules = [];
        foreach (array_keys(self::PRE_APPROVER_FIELDS) as $roleKey) {
            $rules[$roleKey] = ['required', 'integer', 'exists:users,id'];
        }
        $request->validate($rules);

        $data = $report->data ?? [];
        if (is_string($data)) {
            $data = json_decode($data, true) ?? [];
        }
        if (! is_array($data)) {
            $data = [];
        }
        $data['import_source'] = $report->import_source;
        foreach (array_keys(self::PRE_APPROVER_FIELDS) as $roleKey) {
            $data[$roleKey] = (int) $request->input($roleKey);
        }

        $formFields = $request->input('fields', []);
        if (! is_array($formFields)) {
            $formFields = [];
        }
        $tableOps = $this->normalizeTableOpsPayload($request->input('table_ops'));

        // Apply updated field values
        $storedFields = $report->docx_import_fields ?? [];
        if (! is_array($storedFields)) {
            $storedFields = [];
        }
        $storedFields = $this->upgradeProtocolCoverFieldMetadata($storedFields);

        if ($request->filled('equipment_name')) {
            $report->equipment_name = trim((string) $request->input('equipment_name'));
            $data['equipment_name'] = $report->equipment_name;
            $formFields['equipment_name'] = $report->equipment_name;
        }
        if ($request->filled('equipment_id')) {
            $report->equipment_id = trim((string) $request->input('equipment_id'));
            $data['equipment_id'] = $report->equipment_id;
            $formFields['equipment_id'] = $report->equipment_id;
        }
        if ($request->has('facility_section')) {
            $report->facility_section = trim((string) $request->input('facility_section', ''));
            $data['facility_section'] = $report->facility_section;
            $formFields['facility_section'] = $report->facility_section;
        }
        if ($request->filled('version')) {
            $report->version = trim((string) $request->input('version'));
            $data['version'] = $report->version;
        }
        if ($request->has('document_title')) {
            $data['document_title'] = trim((string) $request->input('document_title', ''));
            $formFields['document_title'] = $data['document_title'];
        }

        [$data, $formFields] = $this->mergeProtocolIdentityIntoDataAndFields(
            $request,
            $data,
            $formFields,
            $storedFields
        );
        $storedFields = $this->ensureProtocolIdentityHeaderFields(
            $storedFields,
            (string) ($data['document_number'] ?? ''),
            (string) ($data['reference_protocol_no'] ?? '')
        );
        $data['table_ops'] = $tableOps;
        $report->data = $data;
        $formFields = $this->applyProtocolCoverValues($formFields, $storedFields, $report);
        $storedFields = $this->applyRequalificationPreApproverNames($report, $storedFields);
        $effectiveDateLocation = $this->findEffectiveDateFieldLocation($storedFields);

        // Revalidation uses the same pre-approval fill as Requalification.
        // Approver cells keep the values the user selected; they are not wiped by table.
        $fieldsToPython = [];
        foreach ($storedFields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }
            $loc = (string) ($field['location'] ?? '');
            if ($loc === '') {
                continue;
            }
            $val = array_key_exists($loc, $formFields) ? (string) $formFields[$loc] : (string) ($field['value'] ?? '');
            if (trim($val) === '' && ($field['type'] ?? '') === 'approver' && ! empty($field['value'])) {
                $val = (string) $field['value'];
            }
            // Effective Date is NOT yet approved during pre-approval submission; keep blank until QA signs
            if ($this->isEffectiveDateLikeField($field) && ! $this->isRevisionHistoryEffectiveDateLocation($storedFields, $loc)) {
                $val = '';
            }
            $storedFields[$idx]['value'] = $val;
            $fieldsToPython[] = [
                'location' => $loc,
                'value'    => $val,
                'locked'   => false,
                'type'     => $field['type'] ?? '',
            ];
        }

        // Include any new preview fields not in storedFields
        $knownLocs = array_column($fieldsToPython, 'location');
        foreach ($formFields as $loc => $val) {
            if (! is_string($loc) || in_array($loc, $knownLocs, true)) {
                continue;
            }
            if (! preg_match('/^(para_|table_|header_|txbx_)/', $loc)) {
                continue;
            }
            if (($effectiveDateLocation !== null && $loc === $effectiveDateLocation) || (str_contains(strtolower($loc), 'effective') && ! $this->isRevisionHistoryEffectiveDateLocation($storedFields, $loc))) {
                $val = '';
            }
            $fieldsToPython[] = [
                'location' => $loc,
                'value'    => (string) $val,
                'locked'   => false,
                'type'     => '',
            ];
        }

        $pythonUrl = env('PYTHON_NLP_URL', 'http://127.0.0.1:8001');
        $fileId    = $report->docx_import_path;

        if ($fileId && $this->ensureDocxTemplateOnDisk($report)) {
            $outputFilename   = "pre_approved_{$report->id}.docx";
            $generatedRelPath = "generated_reports/{$fileId}_filled.docx";
            $fillSucceeded    = false;

            Log::info('Requalification pre-approval fill initiated', [
                'report_id' => $report->id,
                'file_id'   => $fileId,
                'table_ops' => count($tableOps),
                'fields'    => count($fieldsToPython),
            ]);

            // Attempt 1: HTTP service
            try {
                $response = Http::timeout(120)->asJson()->post("{$pythonUrl}/fill", [
                    'file_id'           => $fileId,
                    'original_filename' => $outputFilename,
                    'fields'            => $fieldsToPython,
                    'table_ops'         => $tableOps,
                    'equipment_id'      => (string) ($report->equipment_id ?? ''),
                    'import_source'     => (string) ($report->import_source ?? ''),
                ]);

                if ($response->successful() && Storage::disk('public')->exists($generatedRelPath)) {
                    $fillSucceeded = true;
                    Log::info('Python /fill HTTP succeeded for pre-approval');
                } else {
                    Log::warning('Python /fill HTTP error in pre-approval, using CLI fallback: ' . ($response ? $response->body() : ''));
                }
            } catch (\Throwable $e) {
                Log::info('Python /fill HTTP unavailable in pre-approval, using CLI fallback: ' . $e->getMessage());
            }

            // Attempt 2: CLI fallback
            if (! $fillSucceeded) {
                try {
                    $pythonBin = base_path('python-service/venv/Scripts/python.exe');
                    if (! is_file($pythonBin)) {
                        $pythonBin = 'python';
                    }
                    $cliScript = base_path('python-service/cli.py');
                    $templatePath = $this->getDocxTemplateAbsolutePath($fileId);
                    if (! $templatePath || ! is_file($templatePath)) {
                        $templatePath = storage_path('app/public/templates/docx/' . $fileId . '.docx');
                    }

                    $outDir = storage_path('app/public/generated_reports');
                    if (! is_dir($outDir)) {
                        @mkdir($outDir, 0755, true);
                    }
                    $cliOutputPath = $outDir . DIRECTORY_SEPARATOR . "{$fileId}_filled.docx";

                    $tmpFields = tempnam(sys_get_temp_dir(), 'pre_fill_fields_');
                    file_put_contents($tmpFields, json_encode($fieldsToPython, JSON_UNESCAPED_UNICODE));

                    $tmpAtt = tempnam(sys_get_temp_dir(), 'pre_fill_att_');
                    file_put_contents($tmpAtt, json_encode((object) [], JSON_UNESCAPED_UNICODE));

                    $tmpTableOps = tempnam(sys_get_temp_dir(), 'pre_fill_ops_');
                    file_put_contents($tmpTableOps, json_encode($tableOps, JSON_UNESCAPED_UNICODE));

                    $eqId = (string) ($report->equipment_id ?? '');
                    $importSource = (string) ($report->import_source ?? '');

                    $process = new Process([
                        $pythonBin,
                        $cliScript,
                        'fill',
                        $templatePath,
                        $tmpFields,
                        $cliOutputPath,
                        $tmpAtt,
                        $eqId,
                        $tmpTableOps,
                        $importSource,
                    ]);
                    $process->setTimeout(120);
                    $process->run();
                    @unlink($tmpFields);
                    @unlink($tmpAtt);
                    @unlink($tmpTableOps);

                    $cliOutput = trim($process->getOutput());
                    $jsonStart = strpos($cliOutput, '{');
                    if ($jsonStart !== false) {
                        $parsed = json_decode(substr($cliOutput, $jsonStart), true);
                        if (is_array($parsed) && ($parsed['status'] ?? '') === 'success' && is_file($cliOutputPath)) {
                            $fillSucceeded = true;
                            Log::info('Python /fill CLI fallback succeeded in pre-approval submission');
                        }
                    }

                    if (! $fillSucceeded) {
                        Log::error('Python /fill CLI fallback failed in pre-approval', [
                            'exit_code' => $process->getExitCode(),
                            'output'    => $process->getOutput(),
                            'error'     => $process->getErrorOutput(),
                        ]);
                    }
                } catch (\Throwable $cliEx) {
                    Log::error('Python /fill CLI fallback exception in pre-approval: ' . $cliEx->getMessage());
                }
            }

            if ($fillSucceeded && Storage::disk('public')->exists($generatedRelPath)) {
                $newFileContent = Storage::disk('public')->get($generatedRelPath);
                $newFileId      = (string) \Illuminate\Support\Str::uuid();
                $newTemplatePath = storage_path('app/public/templates/docx/' . $newFileId . '.docx');
                @mkdir(dirname($newTemplatePath), 0755, true);
                file_put_contents($newTemplatePath, $newFileContent);

                $parsedFields = null;

                // Try HTTP parse
                try {
                    $parseResponse = Http::timeout(90)
                        ->attach('file', $newFileContent, $outputFilename)
                        ->post("{$pythonUrl}/parse");

                    if ($parseResponse->successful() && is_array($parseResponse->json())) {
                        $parseResult  = $parseResponse->json();
                        $parsedFields = $parseResult['fields'] ?? [];
                    }
                } catch (\Throwable $e) {
                    Log::info('Python /parse HTTP unavailable in pre-approval, using CLI parse: ' . $e->getMessage());
                }

                // CLI parse fallback
                if ($parsedFields === null) {
                    try {
                        $pythonBin = base_path('python-service/venv/Scripts/python.exe');
                        if (! is_file($pythonBin)) {
                            $pythonBin = 'python';
                        }
                        $cliScript = base_path('python-service/cli.py');
                        $process = new Process([$pythonBin, $cliScript, 'parse', $newTemplatePath]);
                        $process->setTimeout(60);
                        $process->run();

                        $cliOutput = trim($process->getOutput());
                        $jsonStart = strpos($cliOutput, '{"status"');
                        if ($jsonStart !== false) {
                            $decoded = json_decode(substr($cliOutput, $jsonStart), true);
                            if (is_array($decoded) && ($decoded['status'] ?? '') === 'success') {
                                $parsedFields = $decoded['fields'] ?? [];
                            }
                        }
                    } catch (\Throwable $cliEx) {
                        Log::error('Python /parse CLI fallback failed in pre-approval: ' . $cliEx->getMessage());
                    }
                }

                if (! empty($parsedFields)) {
                    $newFields = $this->normalizeImportFields($parsedFields);
                    $newFields = $this->applySourceSpecificImportFieldRules($newFields, $report->import_source);
                    $newFields = $this->upgradeProtocolCoverFieldMetadata($newFields);
                    $newFields = $this->ensureProtocolIdentityHeaderFields(
                        $newFields,
                        (string) ($data['document_number'] ?? ''),
                        (string) ($data['reference_protocol_no'] ?? '')
                    );

                    $this->mirrorDocxTemplateAfterParse($newFileId, $newFileContent, $outputFilename);
                    $data['original_docx_path'] = $fileId;
                    $report->docx_import_path   = $newFileId;
                    $storedFields               = $newFields;
                    // Rows from table_ops are now real Word rows in the new template.
                    // Keep them in data and fill.blade will replay → duplicate rows on send-back.
                    $data['table_ops'] = [];
                    $tableOps = [];
                    $this->flushDocxPreviewCache($fileId, $report->id);
                    $this->flushDocxPreviewCache($newFileId, $report->id);
                    Log::info('Requalification pre-approval template replaced with filled template', [
                        'old_file_id'      => $fileId,
                        'new_file_id'      => $newFileId,
                        'new_fields_count' => count($newFields),
                    ]);
                }
            }
        }

        $report->approvalSteps()->where('stage', 'pre')->delete();

        $stepOrder = 1;
        foreach (self::PRE_APPROVER_FIELDS as $roleKey => $meta) {
            ReportApprovalStep::create([
                'report_id'  => $report->id,
                'stage'      => 'pre',
                'user_id'    => (int) $data[$roleKey],
                'label'      => $meta['label'],
                'step_order' => $stepOrder++,
            ]);
        }

        $first = $report->approvalSteps()
            ->where('stage', 'pre')
            ->orderBy('step_order')
            ->first();

        $report->update([
            'data'               => $data,
            'equipment_name'     => $report->equipment_name,
            'equipment_id'       => $report->equipment_id,
            'facility_section'   => $report->facility_section,
            'version'            => $report->version,
            'stage'              => 'pre',
            'status'             => $first ? 'pre_pending' : 'pre_approved',
            'current_step_order' => $first?->step_order ?? 0,
            'docx_import_fields' => $storedFields,
            'docx_import_status' => 'pending',
            'rendered_html'      => null,
        ]);

        if ($first) {
            $approver = User::find($first->user_id);
            if ($approver) {
                app(\App\Services\ReportApprovalNotifier::class)->notifyApprover(
                    $approver,
                    $report->fresh(),
                    "New PRE approval request: {$report->displayCode()} (Step {$first->step_order})"
                );
            }
        }

        return redirect()
            ->route('reports.show', $report)
            ->with('status', 'Sent for pre-approval. The document stays locked until every pre-approver has approved.');
    }

    /**
     * Recall a pre-approval request back to draft mode - DISABLED per workflow policy.
     * Once sent for pre-approval, the document remains locked until an approver sends it back.
     */
    public function recallRequalificationPreApproval(Report $report)
    {
        return redirect()->route('reports.show', $report)
            ->with('info', 'Once sent for pre-approval, the document cannot be recalled. It remains locked and can only be edited if an approver sends it back for correction.');
    }

    /**
     * Post filled fields to Python /fill.
     */
    // ── fill() ───────────────────────────────────────────
    // Submits user-entered DOCX field values and starts POST approval workflow.
    // Parameters: Request $request (fields[] + post approver selects), Report $report.
    // Returns: Redirect to report show with success, or back with error.
    // Key steps:
    // 1. Validates POST approver assignments (mirrors ReportController@submitToPost)
    // 2. Merges form fields with stored import fields; maps header inputs to table locations
    // 3. Sends unlocked fields to Python /fill to generate filled DOCX on disk
    // 4. Saves docx_filled_path, attachment index 99, and updated field values on report
    // 5. Creates POST approval steps and notifies the first approver
    public function fill(Request $request, Report $report)
    {
        $this->extendPythonExecutionTime();

        if ($report->status === 'rejected') {
            return redirect()
                ->route('reports.show', $report)
                ->with('error', 'This protocol was rejected and cannot be filled again.');
        }

        if ((int) $report->created_by !== (int) auth()->id()) {
            return redirect()
                ->route('reports.show', $report)
                ->with('error', 'Only the document creator can edit or submit for approval.');
        }

        if (in_array($report->status, ['pre_pending', 'post_pending'], true)) {
            return redirect()
                ->route('reports.show', $report)
                ->with('error', 'This document is currently pending approval.');
        }

        // E-sign password required before submitting filled DOCX for POST approval.
        if (! Hash::check($request->password, auth()->user()->password)) {
            return back()->with('error', 'Wrong E-Sign password.');
        }

        if ($request->boolean('requal_pre_submit')) {
            return $this->submitRequalificationPreApproval($request, $report);
        }

        if ($this->requalificationFillIsLocked($report)) {
            return back()->with('error', 'This document stays locked until pre-approval is complete.');
        }

        $importFields = $this->upgradeCommentsTextareaLocations(
            $this->upgradeTocFieldsLocked(
                $this->upgradeParagraphYesNoFields(
                    $this->upgradeProtocolCoverFieldMetadata(
                        $this->upgradeSignatureApproverRoles(
                            $this->upgradeComponentSectionSerialFields(
                                $this->upgradeCertificateNumberTextFields(
                                    $this->upgradeApprovalPageFieldMetadata(
                                        is_array($report->docx_import_fields ?? null) ? $report->docx_import_fields : [],
                                        $report->import_source ?? null
                                    )
                                )
                            ),
                            (string) ($report->docx_import_path ?? '')
                        )
                    )
                )
            )
        );
        $coverLoc = $this->buildCoverFieldLocationMap($importFields);
        $importFields = $this->pruneSpuriousHeaderImportFields($importFields, $coverLoc);
        $importFields = $this->unlockRevisionHistoryDateFields($importFields);
        if ($this->isNeqImportSource($report->import_source ?? null) && ! empty($report->docx_import_path)) {
            $importFields = $this->pruneApprovalPageFieldsBeyondDocxRows(
                $importFields,
                (string) $report->docx_import_path
            );
        }

        foreach ($importFields as $idx => $f) {
            if (! is_array($f)) {
                continue;
            }
            $loc = (string) ($f['location'] ?? '');
            $lbl = strtolower((string) ($f['label'] ?? ''));
            if (str_starts_with($loc, 'para_') && str_contains($lbl, 'following test were performed')) {
                $importFields[$idx]['type'] = 'paragraph_blank';
                $importFields[$idx]['widget'] = 'text';
            }
        }

        $isPreApprovalDraft = in_array($report->import_source, ['requalification', 'revalidation'], true)
            && in_array($report->status, ['pre_setup', 'pre_returned'], true)
            && $report->stage === 'pre';

        if ($isPreApprovalDraft) {
            $importFields = $this->applyRequalificationPreApprovalUnlockRules($importFields);
        } else {
            if (in_array(($report->import_source ?? ''), ['requalification', 'revalidation'], true)) {
                $importFields = $this->applyRequalificationPostPreApprovalLockRules($importFields);
            } elseif (($report->import_source ?? '') === 'new_equipment_qualification') {
                $importFields = $this->applySourceSpecificImportFieldRules(
                    $importFields,
                    'new_equipment_qualification'
                );
            } else {
                $importFields = $this->lockConnectedAccessoriesDescriptions($importFields);
                $importFields = $this->lockExistingTableContent($importFields);
            }
        }

        // Unlock after lock rules so send-back stays as fillable as a fresh import.
        if (in_array($report->status, ['post_returned', 'pre_returned'], true)) {
            $importFields = $this->unlockDocxFieldsForReturnedEdit($importFields);
        }

        $importFields = $this->applyRequalificationPreApproverNames($report, $importFields);

        $usesApprovalPage     = $this->reportUsesApprovalPageTable($importFields);
        $presentApproverRoles = $this->collectPostApproverRolesFromFields($importFields);
        if (($report->import_source ?? '') === 'new_equipment_qualification' && $usesApprovalPage) {
            $presentApproverRoles = $this->ensureNeqPostApprovalRoleChain($presentApproverRoles);
        }
        $approverRules        = $this->buildPostApproverValidationRules($presentApproverRoles);

        $request->validate(array_merge($approverRules, [
            'document_id'      => ['nullable', 'integer', 'exists:documents,id'],
            'version'          => ['nullable', 'string', 'max:255'],
            'facility_section' => ['nullable', 'string', 'max:255'],
            'document_title'   => ['nullable', 'string', 'max:255'],
            'equipment_name'   => ['required', 'string', 'max:255'],
            'equipment_id'     => ['required', 'string', 'max:255'],
            'protocol_document_no' => ['nullable', 'string', 'max:255'],
            'reference_protocol_no' => ['nullable', 'string', 'max:255'],
        ]));

        $formFields = $request->input('fields', []);

        $formFields['equipment_name']   = (string) $request->input('equipment_name', $report->equipment_name ?? '');
        $formFields['equipment_id']     = (string) $request->input('equipment_id', $report->equipment_id ?? '');
        $formFields['facility_section'] = (string) $request->input('facility_section', $report->facility_section ?? '');
        $formFields['document_title']   = (string) $request->input('document_title', $this->resolveDocumentTitle($report, $importFields));

        $coverLoc = $this->buildCoverFieldLocationMap($importFields);
        // Prefer textual Document Number from mapping card over documents-master FK id.
        if (! empty($coverLoc['document_no'])) {
            $docNoText = trim((string) $request->input('protocol_document_no', ''));
            if ($docNoText !== '') {
                $formFields[$coverLoc['document_no']] = $docNoText;
            }
        }

        $effectiveDateLocation = $this->findEffectiveDateFieldLocation($importFields);
        $hasEffectiveDateField = $effectiveDateLocation !== null;
        // Only accept effective_date when the template actually has that cover field.
        if ($hasEffectiveDateField) {
            $formFields['effective_date'] = (string) $request->input(
                'effective_date',
                $this->resolveEffectiveDate($report, $importFields)
            );
        } else {
            unset($formFields['effective_date']);
        }

        $formFields = $this->filterInvalidHeaderFormFields($formFields, $importFields, $coverLoc);
        $formFields = $this->remapCommentsFormSubmissions($formFields, $importFields);

        // Merge POST approver user IDs from request (signature-table dropdowns sync to these keys).
        $approverData = $request->only([
            'post_prepared_by_name',
            'post_checked_by_engineering_name',
            'post_checked_by_production_name',
            'post_checked_by_quality_name',
            'post_checked_by_validation_name',
            'post_approved_by_user_name',
            'post_approved_by_quality_name',
        ]);

        $identityDataSeed = is_array($report->data) ? $report->data : [];
        if (is_string($report->data)) {
            $identityDataSeed = json_decode($report->data, true) ?? [];
        }
        if (! is_array($identityDataSeed)) {
            $identityDataSeed = [];
        }
        [$identityDataSeed, $formFields] = $this->mergeProtocolIdentityIntoDataAndFields(
            $request,
            $identityDataSeed,
            $formFields,
            $importFields
        );

        // Map mapping-card inputs → protocol cover cells (locations vary per template).
        $headerMap = $this->buildCoverHeaderMap($coverLoc);
        foreach ($headerMap as $headerKey => $location) {
            if (! isset($formFields[$headerKey]) || $formFields[$headerKey] === '') {
                continue;
            }
            $value = (string) $formFields[$headerKey];
            if ($headerKey === 'effective_date') {
                $value = $this->formatEffectiveDateForDocx($value);
            }
            $formFields[$location] = $value;
        }

        Log::info('Form fields received', ['fields' => $formFields]);

        try {
            $reportForCover = clone $report;
            $coverData = is_array($reportForCover->data) ? $reportForCover->data : [];
            if (is_string($reportForCover->data)) {
                $coverData = json_decode($reportForCover->data, true) ?? [];
            }
            if (! is_array($coverData)) {
                $coverData = [];
            }
            $coverData['document_number'] = (string) ($identityDataSeed['document_number'] ?? '');
            $coverData['reference_protocol_no'] = (string) ($identityDataSeed['reference_protocol_no'] ?? '');
            $reportForCover->data = $coverData;

            // Merge cover-page and protocol metadata into submitted cell values.
            $formFields = $this->applyProtocolCoverValues($formFields, $importFields, $reportForCover);

            $effLoc = $effectiveDateLocation;
            // Default empty effective-date cells to today in DOCX display format.
            if ($effLoc !== null && trim((string) ($formFields[$effLoc] ?? '')) === '') {
                $formFields[$effLoc] = $this->formatEffectiveDateForDocx(
                    (string) ($formFields['effective_date'] ?? $this->resolveEffectiveDate($report, $importFields))
                );
            }

            // Resolve signature-table approver dropdowns → display names for DOCX cells.
            $formFields = $this->applySignatureApproverValues(
                $formFields,
                $importFields,
                $approverData
            );

            // Store uploaded attachment files and set DOCX cell values to filenames.
            [$formFields, $uploadedAttachments] = $this->handleDocxAttachmentUploads(
                $request,
                $report,
                $formFields
            );

            $updatedFields  = [];
            $fieldsToPython = [];

            foreach ($importFields as $field) {
                $location = $field['location'] ?? '';
                if ($location === '') {
                    continue;
                }

                // Locked fields keep parser/DB values; only unlocked cells accept form input.
                $isLocked = $this->isDocxFieldLocked($field);
                $fieldType = strtolower(trim((string) ($field['type'] ?? '')));
                $fieldWidget = strtolower(trim((string) ($field['widget'] ?? '')));
                // Yes/No radios must always accept the user's selection (never keep a stale locked "No").
                $isChoiceField = in_array($fieldType, ['paragraph_radio', 'paragraph_checkbox', 'table_radio', 'table_checkbox'], true)
                    || in_array($fieldWidget, ['radio', 'checkbox'], true);
                if ($isChoiceField) {
                    $isLocked = false;
                }

                // NEVER allow locked field values to be changed — always use original VIVA value from DB
                $originalValue = (string) ($field['value'] ?? '');
                $submittedValue = $originalValue;

                if (!$isLocked && array_key_exists($location, $formFields)) {
                    $submittedValue = (string) $formFields[$location];
                }

                $savedValue = $isLocked ? $originalValue : $submittedValue;
                $updatedField = array_filter([
                    'label'                   => $field['label'] ?? '',
                    'location'                => $location,
                    'value'                   => $savedValue,
                    'locked'                  => $isLocked,
                    'type'                    => $field['type'] ?? '',
                    'widget'                  => $field['widget'] ?? null,
                    'approver_role'           => $field['approver_role'] ?? null,
                    'signature_date_location' => $field['signature_date_location'] ?? null,
                    'date_selected'           => $this->isDocxDateTimeEntryField($field)
                        ? trim((string) $savedValue) !== ''
                        : null,
                ], fn ($v) => $v !== null);

                $updatedFields[] = $updatedField;

                // Serial numbers / header-echo values stay in the template — do not send to /fill.
                if ($this->shouldSkipFieldForPythonFill($updatedField)) {
                    continue;
                }

                $fieldsToPython[] = [
                    'location' => $location,
                    'value'    => $isLocked ? $originalValue : $submittedValue,
                    // Python /fill must receive unlocked flags so user-filled cells are written.
                    'locked'   => false,
                    'type'     => $field['type'] ?? '',
                ];

                if ($isLocked) {
                    continue;
                }
            }

            // Include newly detected preview fields not yet in stored import metadata (e.g. after parser upgrade).
            $knownLocations = array_column($updatedFields, 'location');
            foreach ($formFields as $location => $submittedValue) {
                if (! is_string($location) || in_array($location, $knownLocations, true)) {
                    continue;
                }
                if (! preg_match('/^(para_|table_|header_|txbx_|toc_)/', $location)) {
                    continue;
                }
                $updatedFields[] = [
                    'label'    => $location,
                    'location' => $location,
                    'value'    => (string) $submittedValue,
                    'locked'   => false,
                    'type'     => '',
                ];
                $fieldsToPython[] = [
                    'location' => $location,
                    'value'    => (string) $submittedValue,
                    'locked'   => false,
                    'type'     => '',
                ];
                $knownLocations[] = $location;
            }

            // Do not stamp Performed/Checked/Approved rows at submit time.
            // Like requal post-approval: each selected user must log in and Approve in order.

            $updatedFields = $this->ensureProtocolIdentityHeaderFields(
                $updatedFields,
                (string) ($identityDataSeed['document_number'] ?? ''),
                (string) ($identityDataSeed['reference_protocol_no'] ?? '')
            );

            $updatedFields = $this->finalizePreApprovalDocxFields($updatedFields);
            // Signature/Date stay blank until Approve; Name shows the selected user.
            $updatedFields = $this->blankUnapprovedPostApprovalDocxFields($updatedFields, $report);
            $updatedFields = $this->applyPendingSelectedApproverNames(
                $updatedFields,
                $report,
                is_array($report->data) ? $report->data : []
            );
            $stampedByLocation = array_column($updatedFields, 'value', 'location');
            $lockedByLocation    = [];
            foreach ($updatedFields as $updatedField) {
                if (! is_array($updatedField)) {
                    continue;
                }
                $loc = (string) ($updatedField['location'] ?? '');
                if ($loc !== '') {
                    $lockedByLocation[$loc] = (bool) ($updatedField['locked'] ?? false);
                }
            }
            foreach ($fieldsToPython as $idx => $row) {
                $loc = $row['location'] ?? '';
                if ($loc !== '' && array_key_exists($loc, $stampedByLocation)) {
                    $fieldsToPython[$idx]['value']  = (string) $stampedByLocation[$loc];
                    // Always unlock for Python /fill so filled values are written into the DOCX.
                    $fieldsToPython[$idx]['locked'] = false;
                } else {
                    $fieldsToPython[$idx]['locked'] = false;
                }
            }

            Log::info('Fields being sent to Python', [
                'count'  => count($fieldsToPython),
                'sample' => array_slice($fieldsToPython, 0, 3),
            ]);

            $pythonUrl = env('PYTHON_NLP_URL', 'http://127.0.0.1:8001');
            $fileId    = $report->docx_import_path;

            if (!$fileId) {
                return redirect()->back()->with('error', 'Import file ID is missing on the report.');
            }

            if (! $this->ensureDocxTemplateOnDisk($report)) {
                return redirect()->back()->with(
                    'error',
                    'Template DOCX is missing and could not be recovered. Please re-import the DOCX file.'
                );
            }

            $originalFilename = $report->template
                ? basename((string) $report->template->docx_template_path)
                : 'report.docx';

            $attachmentLinks = $this->buildAttachmentLinksMap($report, $uploadedAttachments);
            $tableOps = $this->normalizeTableOpsPayload($request->input('table_ops'));

            // [API] POST {PYTHON_NLP_URL}/fill — write cells + fix TOC pages in Python.
            // TOC uses [WORD COM] first, then [LIBREOFFICE] only if Word fails.
            $result = null;
            try {
            $response = Http::timeout(90)
                ->asJson()
                ->post("{$pythonUrl}/fill", [
                    'file_id'           => $fileId,
                    'original_filename' => $originalFilename,
                    'fields'            => $fieldsToPython,
                    'attachment_links'  => $this->formatAttachmentLinksForPython($attachmentLinks),
                    'table_ops'         => $tableOps,
                    'equipment_id'      => in_array($report->import_source, ['requalification', 'revalidation'], true)
                        ? (string) ($report->equipment_id ?? '')
                        : '',
                    'import_source'     => (string) ($report->import_source ?? ''),
                    'preserve_toc_pages' => ($report->import_source ?? '') === 'new_equipment_qualification',
                ]);

                if ($response->successful()) {
                    $result = $response->json();
                    Log::info('Python /fill response', ['result' => $result]);
                } else {
                    Log::warning('Python /fill HTTP error: ' . $response->body());
                }
            } catch (\Throwable $e) {
                Log::warning('Python /fill HTTP request exception: ' . $e->getMessage());
            }

            // CLI fallback if Python HTTP service is unavailable
            if (! is_array($result) || empty($result['output_filename'])) {
                try {
                    $pythonBin = base_path('python-service/venv/Scripts/python.exe');
                    if (! is_file($pythonBin)) {
                        $pythonBin = 'python';
                    }
                    $cliScript = base_path('python-service/cli.py');
                    $templatePath = storage_path('app/public/templates/docx/' . $fileId . '.docx');
                    $cliOutputFilename = "{$fileId}_filled.docx";
                    $outDir = storage_path('app/public/generated_reports');
                    if (! is_dir($outDir)) {
                        @mkdir($outDir, 0755, true);
                    }
                    $cliOutputPath = $outDir . DIRECTORY_SEPARATOR . $cliOutputFilename;

                    $tmpFields = tempnam(sys_get_temp_dir(), 'fill_fields_');
                    file_put_contents($tmpFields, json_encode($fieldsToPython, JSON_UNESCAPED_UNICODE));
                    $tmpAtt = tempnam(sys_get_temp_dir(), 'fill_att_');
                    file_put_contents($tmpAtt, json_encode($this->formatAttachmentLinksForPython($attachmentLinks), JSON_UNESCAPED_UNICODE));
                    $tmpTableOps = tempnam(sys_get_temp_dir(), 'fill_tops_');
                    file_put_contents($tmpTableOps, json_encode($tableOps, JSON_UNESCAPED_UNICODE));

                    $eqId = in_array($report->import_source, ['requalification', 'revalidation'], true)
                        ? (string) ($report->equipment_id ?? '')
                        : '';
                    $importSource = (string) ($report->import_source ?? '');

                    $process = new Process([
                        $pythonBin,
                        $cliScript,
                        'fill',
                        $templatePath,
                        $tmpFields,
                        $cliOutputPath,
                        $tmpAtt,
                        $eqId,
                        $tmpTableOps,
                        $importSource,
                    ]);
                    $process->setTimeout(90);
                    $process->run();
                    @unlink($tmpFields);
                    @unlink($tmpAtt);
                    @unlink($tmpTableOps);

                    $cliOutput = trim($process->getOutput());
                    $jsonStart = strpos($cliOutput, '{');
                    if ($jsonStart !== false) {
                        $parsed = json_decode(substr($cliOutput, $jsonStart), true);
                        if (is_array($parsed) && ($parsed['status'] ?? '') === 'success') {
                            $result = $parsed;
                            Log::info('Python /fill CLI fallback succeeded', ['result' => $result]);
                        }
                    }
                } catch (\Throwable $cliEx) {
                    Log::error('Python /fill CLI fallback failed: ' . $cliEx->getMessage());
                }
            }

            if (! is_array($result) || empty($result['output_filename'])) {
                Log::error('Python /fill failed and CLI fallback failed');
                return redirect()->back()->with('error', 'Failed to generate filled DOCX.');
            }

            $repairedValues = $result['repaired_field_values'] ?? [];
            if (is_array($repairedValues) && $repairedValues !== []) {
                $updatedFields = $this->applyRepairedFieldValues($updatedFields, $repairedValues);
            }
            $tocValues = $result['toc_field_values'] ?? [];
            if (! is_array($tocValues) || $tocValues === []) {
                $tocValues = $this->extractTocValuesFromRepaired(
                    is_array($repairedValues) ? $repairedValues : []
                );
            }
            // NEQ page numbers are typed. Do not replace them with layout digits.
            $preserveNeqToc = ($report->import_source ?? '') === 'new_equipment_qualification';
            if (! $preserveNeqToc && is_array($tocValues) && $tocValues !== []) {
                $updatedFields = $this->applyTocFieldValues($updatedFields, $tocValues);
            }

            $outputFilename = $result['output_filename'] ?? null;

            if (!$outputFilename) {
                return redirect()->back()->with('error', 'Service did not return an output filename.');
            }

            $relative     = 'generated_reports/' . $outputFilename;
            $absolutePath = storage_path('app/public/' . $relative);
            $pythonOutputPath = isset($result['output_path']) ? (string) $result['output_path'] : '';

            if (!is_file($absolutePath) && $pythonOutputPath !== '') {
                $this->syncFilledDocxToLocalStorage($absolutePath, $pythonOutputPath);
            }

            Log::info('Output path saved', [
                'path'        => $relative,
                'absolute'    => $absolutePath,
                'python_path' => $pythonOutputPath,
                'exists'      => is_file($absolutePath),
                'size'        => is_file($absolutePath) ? filesize($absolutePath) : 0,
            ]);

            if (!is_file($absolutePath)) {
                return redirect()->back()->with('error', 'Filled DOCX was not written to disk.');
            }

            $this->mirrorFilledDocxAsTemplate($fileId, $absolutePath);

            $attachment = [
                'index'         => 99,
                'name'          => 'Generated Document',
                'file_path'     => $relative,
                'stored_name'   => $outputFilename,
                'original_name' => $outputFilename,
                'mime'          => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'size'          => is_file($absolutePath) ? filesize($absolutePath) : 0,
            ];

            $attachments = $report->attachments ?? [];
            if (is_string($attachments)) {
                $attachments = json_decode($attachments, true) ?? [];
            }

            // Replace any prior generated DOCX (index 99) before appending the new one.
            $attachments = array_filter($attachments, function ($att) {
                return isset($att['index']) && (int) $att['index'] !== 99;
            });

            $attachments = $this->mergeAttachmentsByLocation(
                array_values($attachments),
                $uploadedAttachments
            );
            $attachments[] = $attachment;

            // POST approval workflow (mirror ReportController@submitToPost)
            $newData = $request->except(['_token', 'fields', 'attachments']);
            $oldData = $report->data ?? [];
            if (is_string($oldData)) {
                $oldData = json_decode($oldData, true) ?? [];
            }
            if (!is_array($oldData)) {
                $oldData = [];
            }

            $data = array_merge($oldData, $newData);
            if (! empty($oldData['import_source'])) {
                $data['import_source'] = $oldData['import_source'];
            }
            $data['table_ops'] = $tableOps;

            if ($request->filled('document_id')) {
                $data['document_id'] = $request->input('document_id');
                $report->document_no = (int) $request->input('document_id');
            }
            if ($request->filled('version')) {
                $data['version'] = $request->input('version');
                $report->version = $request->input('version');
            }
            if ($request->filled('facility_section')) {
                $data['facility_section'] = $request->input('facility_section');
                $report->facility_section = $request->input('facility_section');
            }
            if ($request->filled('equipment_name')) {
                $data['equipment_name'] = $request->input('equipment_name');
                $report->equipment_name = $request->input('equipment_name');
            }
            if ($request->filled('equipment_id')) {
                $data['equipment_id'] = $request->input('equipment_id');
                $report->equipment_id = $request->input('equipment_id');
            }
            if ($request->filled('document_title')) {
                $data['document_title'] = $request->input('document_title');
            }
            $data['document_number'] = (string) ($identityDataSeed['document_number'] ?? $data['document_number'] ?? '');
            $data['reference_protocol_no'] = (string) ($identityDataSeed['reference_protocol_no'] ?? $data['reference_protocol_no'] ?? '');
            if ($hasEffectiveDateField && (
                $request->filled('effective_date') || $this->reportHasCoverField($importFields, 'effective_date')
            )) {
                $effectiveDate = trim((string) $request->input('effective_date', ''));
                if ($effectiveDate === '') {
                    $effectiveDate = $this->resolveEffectiveDate($report, $importFields);
                }
                $data['effective_date'] = $this->formatEffectiveDateForDocx($effectiveDate);
            }

            if (
                $usesApprovalPage
                && in_array('post_prepared_by_name', $presentApproverRoles, true)
            ) {
                $selectedPreparer = $approverData['post_prepared_by_name'] ?? auth()->id();
                $data['post_prepared_by_name'] = is_numeric($selectedPreparer)
                    ? (int) $selectedPreparer
                    : auth()->id();
            }

            $this->assertPostApproversPresent($data, $presentApproverRoles);
            $this->enrichUserDisplayTextFields($data);

            $isNeq = $this->isNeqImportSource($report->import_source ?? null);
            if ($isNeq) {
                // NEQ-only: clear leftover stamp text from a previous premature auto-approve.
                foreach ([
                    'post_prepared_by',
                    'post_checked_by_engineering',
                    'post_checked_by_production',
                    'post_checked_by_quality',
                    'post_checked_by_validation',
                    'post_approved_by_quality',
                ] as $stampPrefix) {
                    unset(
                        $data[$stampPrefix . '_name_text'],
                        $data[$stampPrefix . '_date'],
                        $data[$stampPrefix . '_signature'],
                        $data[$stampPrefix . '_decision']
                    );
                }
            } elseif (
                $usesApprovalPage
                && ! empty($data['post_prepared_by_name'])
                && in_array('post_prepared_by_name', $presentApproverRoles, true)
            ) {
                // Requal/reval legacy: stamp Performed By on submit.
                $preparer = User::find((int) $data['post_prepared_by_name']);
                if ($preparer) {
                    $recorderName = $this->formatUserOptionLabel($preparer);
                    $data['post_prepared_by_name_text'] = $recorderName;
                    $data['post_prepared_by_date']      = $this->formatSignatureDateTimeForDocx(now());
                    $data['post_prepared_by_signature'] = $recorderName;
                }
            }

            $renderedHtml = '';
            // Build readonly HTML snapshot for report show page after successful fill.
            $readonlyBody = $this->requestPreviewHtml(
                $fileId,
                $pythonUrl,
                $updatedFields,
                $report->id,
                'readonly'
            );
            if (trim($readonlyBody) !== '') {
                $report->attachments = array_values($attachments);
                $snapshot            = $this->wrapReadonlyPreviewWithHeader($report, $readonlyBody, $updatedFields);
                $renderedHtml        = $this->enrichReadonlyPreviewForShow($snapshot, $report);
                $renderedHtml        = $this->applyAttachmentLinksToPreviewHtml($renderedHtml, $report);
            }

            $report->update([
                'docx_filled_path'     => $relative,
                'docx_import_status'   => 'completed',
                'docx_import_fields'   => $updatedFields,
                'rendered_html'        => $renderedHtml,
                'attachments'          => array_values($attachments),
                'data'                 => $data,
                'stage'                => 'post',
                'status'               => 'post_pending',
                'current_step_order'   => 1,
                'document_no'          => $report->document_no,
                'version'              => $report->version,
                'facility_section'     => $report->facility_section,
                'equipment_name'       => $report->equipment_name,
                'equipment_id'         => $report->equipment_id,
            ]);

            $report->approvalSteps()->where('stage', 'post')->delete();

            // Create POST approval steps in template order for each assigned approver.
            $stepOrder = 1;
            foreach ($presentApproverRoles as $roleKey) {
                $userId = $data[$roleKey] ?? null;
                if ($userId === null || $userId === '' || ! is_numeric($userId)) {
                    continue;
                }

                ReportApprovalStep::create([
                    'report_id'  => $report->id,
                    'stage'      => 'post',
                    'user_id'    => (int) $userId,
                    'label'      => self::POST_APPROVER_STEP_LABELS[$roleKey] ?? $roleKey,
                    'step_order' => $stepOrder++,
                ]);
            }

            if (
                ! $isNeq
                && $usesApprovalPage
                && in_array('post_prepared_by_name', $presentApproverRoles, true)
            ) {
                // Requal/reval legacy: auto-approve preparer step because submitter just filled.
                $preparedStep = $report->approvalSteps()
                    ->where('stage', 'post')
                    ->where('label', 'POST_PREPARED')
                    ->first();
                if ($preparedStep) {
                    $preparedStep->update([
                        'approved_at' => now(),
                        'approved_by' => auth()->id(),
                    ]);
                }
            }
            // NEQ: do NOT auto-approve Performed By — first assignee must log in and Approve.

            $first = $report->approvalSteps()
                ->where('stage', 'post')
                ->whereNull('approved_at')
                ->whereNull('rejected_at')
                ->orderBy('step_order')
                ->first();

            $report->update([
                'current_step_order' => $first?->step_order ?? 0,
                'status'             => $first ? 'post_pending' : 'approved',
            ]);

            // Notify the first pending POST approver by email/in-app notification.
            if ($first) {
                $approver = User::find($first->user_id);
                if ($approver) {
                    app(\App\Services\ReportApprovalNotifier::class)->notifyApprover(
                        $approver,
                        $report,
                        "New POST approval request: {$report->displayCode()} (Step {$first->step_order})"
                    );
                }
            }

            $report->refresh();
            ProtocolAuditLogger::recordFormSubmitted($report);

            $statusMessage = 'Submitted for Post Approval! ' . $report->displayCode();
            $listRoute = match ((string) ($report->import_source ?? '')) {
                'revalidation' => 'protocol.revalidation',
                'requalification' => 'protocol.requalification',
                'new_equipment_qualification' => 'protocol.new-equipment-qualification.reports',
                default => 'protocol',
            };

            return redirect()->route($listRoute)->with('status', $statusMessage);

        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('DOCX filling error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return redirect()->back()->with('error', 'Error generating filled document: ' . $e->getMessage());
        }
    }

    /**
     * NEQ-only: generate a Word (.docx) preview from the current fill form values.
     * Does not submit for approval. Uses Word/LibreOffice TOC page recompute so
     * the initiator can check real page numbers before Send for Approval.
     */
    public function previewWordDraft(Request $request, Report $report)
    {
        $this->extendPythonExecutionTime();

        if (! $this->isNeqImportSource($report->import_source ?? null)) {
            return response()->json([
                'success' => false,
                'message' => 'Word preview is only available for New Equipment Qualification.',
            ], 404);
        }

        if ((int) $report->created_by !== (int) auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Only the document creator can preview the Word draft.',
            ], 403);
        }

        if ($report->status === 'rejected') {
            return response()->json([
                'success' => false,
                'message' => 'This protocol was rejected and cannot be previewed.',
            ], 403);
        }

        if (in_array($report->status, ['pre_pending', 'post_pending', 'approved'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Word preview is only available while filling (before send for approval).',
            ], 422);
        }

        $isReturnedEdit = in_array($report->status, ['post_returned', 'pre_returned'], true);
        if ($report->docx_import_status === 'completed' && ! $isReturnedEdit) {
            return response()->json([
                'success' => false,
                'message' => 'This document is already submitted.',
            ], 422);
        }

        if (! $report->docx_import_path) {
            return response()->json([
                'success' => false,
                'message' => 'No DOCX template is linked to this report.',
            ], 422);
        }

        // Persist current form values first (same as autosave draft).
        $draftResponse = $this->saveDraft($request, $report);
        if ($draftResponse->getStatusCode() >= 400) {
            return $draftResponse;
        }

        $report->refresh();
        $fields = is_array($report->docx_import_fields) ? $report->docx_import_fields : [];
        if ($fields === []) {
            return response()->json([
                'success' => false,
                'message' => 'No fill fields found. Save the form and try again.',
            ], 422);
        }

        // Preview must use the TOC digits on this request even if an older
        // autosave won the draft race. Overlay request fields[...] on top.
        $requestFields = $request->input('fields', []);
        if (is_array($requestFields) && $requestFields !== []) {
            foreach ($fields as $idx => $field) {
                if (! is_array($field)) {
                    continue;
                }
                $location = (string) ($field['location'] ?? '');
                if ($location === '' || ! array_key_exists($location, $requestFields)) {
                    continue;
                }
                $isTocPage = (
                    (str_contains($location, '_toc_') || str_starts_with($location, 'toc_sdt_'))
                    && ! str_ends_with($location, '_title')
                );
                if (! $isTocPage) {
                    continue;
                }
                $page = trim((string) $requestFields[$location]);
                if ($page === '' || ! preg_match('/^\d+$/', $page)) {
                    continue;
                }
                $fields[$idx]['value'] = $page;
                $fields[$idx]['locked'] = false;
                $fields[$idx]['user_filled'] = true;
            }
        }

        $fields = $this->applyRequalificationPreApproverNames($report, $fields);
        $result = $this->regenerateFilledDocxAttachment($report, $fields, true);
        if ($result === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to generate Word preview. Check that the Python service is running.',
            ], 500);
        }

        $syncedFields = (isset($result['fields']) && is_array($result['fields']))
            ? $result['fields']
            : $fields;

        // Keep the draft the user just saved, including the TOC page numbers they typed.
        $report->docx_import_fields = $syncedFields;
        $report->save();

        $relative = (string) ($result['docx_filled_path'] ?? '');
        $absolute = $relative !== ''
            ? storage_path('app/public/' . ltrim($relative, '/'))
            : '';
        if ($absolute === '' || ! is_file($absolute)) {
            return response()->json([
                'success' => false,
                'message' => 'Word preview file was not written to disk.',
            ], 500);
        }

        // Stable preview copy — official submit regenerates its own filled DOCX.
        $previewRelative = 'generated_reports/' . $report->docx_import_path . '_word_preview.docx';
        $previewAbsolute = storage_path('app/public/' . $previewRelative);
        if (! @copy($absolute, $previewAbsolute) || ! is_file($previewAbsolute)) {
            return response()->json([
                'success' => false,
                'message' => 'Could not store Word preview copy.',
            ], 500);
        }

        $previewUrl = route('reports.docx-import.preview-word-download', $report);

        return response()->json([
            'success'           => true,
            'message'           => 'Word preview ready. Table of contents uses the page numbers you entered.',
            'toc_preserved'     => true,
            'preview_url'       => $previewUrl,
            'download_url'      => $previewUrl, // legacy alias
            'toc_field_values'  => [],
        ]);
    }

    /**
     * NEQ-only: open Word preview in-browser (PDF inline in a new tab).
     * Converts the last generated `_word_preview.docx` via LibreOffice.
     */
    public function downloadWordPreview(Report $report)
    {
        $this->extendPythonExecutionTime();

        if (! $this->isNeqImportSource($report->import_source ?? null)) {
            abort(404);
        }

        if ((int) $report->created_by !== (int) auth()->id()) {
            abort(403);
        }

        $fileId = (string) ($report->docx_import_path ?? '');
        if ($fileId === '') {
            abort(404);
        }

        $docxPath = storage_path('app/public/generated_reports/' . $fileId . '_word_preview.docx');
        if (! is_file($docxPath)) {
            abort(404, 'Word preview not found. Generate preview first.');
        }

        $pdfPath = $this->convertDocxPathToPdf($docxPath, true);
        $filename = $this->reportExportFilename($report, 'docx');
        clearstatcache(true, $pdfPath);
        $mtime = is_file($pdfPath) ? (string) filemtime($pdfPath) : (string) time();

        return response()->file($pdfPath, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
            'Cache-Control'       => 'private, no-cache, must-revalidate',
            'ETag'                => '"' . $mtime . '"',
        ]);
    }

    /**
     * Convert a DOCX on disk to PDF via LibreOffice (same folder, same basename).
     */
    private function convertDocxPathToPdf(string $docxPath, bool $force = false): string
    {
        if (! is_file($docxPath)) {
            abort(404, 'DOCX file not found for PDF conversion.');
        }

        $pdfPath = dirname($docxPath) . DIRECTORY_SEPARATOR . pathinfo($docxPath, PATHINFO_FILENAME) . '.pdf';
        clearstatcache(true, $docxPath);
        clearstatcache(true, $pdfPath);

        $docxMtime = (int) @filemtime($docxPath);
        $pdfFresh = is_file($pdfPath) && filesize($pdfPath) > 0 && (int) filemtime($pdfPath) >= $docxMtime;
        if (! $force && $pdfFresh) {
            return $pdfPath;
        }

        if (is_file($pdfPath)) {
            @unlink($pdfPath);
        }

        $sofficeBinary = $this->resolveLibreOfficeBinary();
        if ($sofficeBinary === null) {
            abort(500, 'LibreOffice is required for PDF conversion.');
        }

        $quoteForShell = static function (string $value): string {
            if (DIRECTORY_SEPARATOR === '\\') {
                return '"' . str_replace('"', '\"', $value) . '"';
            }

            return escapeshellarg($value);
        };

        $command = $quoteForShell($sofficeBinary)
            . ' --headless --convert-to pdf --outdir '
            . $quoteForShell(dirname($docxPath))
            . ' '
            . $quoteForShell($docxPath)
            . ' 2>&1';

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        clearstatcache(true, $pdfPath);

        if ($exitCode !== 0 || ! is_file($pdfPath)) {
            $tail = implode(PHP_EOL, array_slice($output, -5));
            Log::error('DOCX Word-preview PDF conversion failed', [
                'docx_path' => $docxPath,
                'pdf_path'  => $pdfPath,
                'exit_code' => $exitCode,
                'output'    => $tail,
            ]);
            abort(500, 'Unable to convert Word preview to PDF. ' . $tail);
        }

        return $pdfPath;
    }

    /**
     * Resolve LibreOffice soffice binary path, or null when unavailable.
     */
    private function resolveLibreOfficeBinary(): ?string
    {
        foreach (array_filter([
            env('LIBREOFFICE_BIN'),
            'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
            'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
            'soffice',
        ]) as $candidate) {
            if ($candidate === 'soffice') {
                return $candidate;
            }
            if (is_string($candidate) && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Apply Python layout fixes to an existing filled DOCX.
     * Requal/reval: 14/11 layout_v1. NEQ: layout_v4 (Att08 multi-row header + cantSplit).
     */
    private function normalizeFilledDocxLayoutOnDisk(string $docxPath, ?string $importSource = null): void
    {
        if (! is_file($docxPath)) {
            return;
        }

        $pythonUrl = rtrim((string) env('PYTHON_NLP_URL', 'http://127.0.0.1:8001'), '/');
        $payload = [
            'path'          => $docxPath,
            'import_source' => (string) ($importSource ?? ''),
        ];
        try {
            $response = Http::timeout(90)
                ->asJson()
                ->post("{$pythonUrl}/normalize-layout", $payload);
            if ($response->successful()) {
                clearstatcache(true, $docxPath);

                return;
            }
            Log::warning('DOCX normalize-layout HTTP failed', [
                'path'   => $docxPath,
                'status' => $response->status(),
                'body'   => mb_substr($response->body(), 0, 400),
            ]);
        } catch (\Throwable $e) {
            Log::warning('DOCX normalize-layout HTTP exception: ' . $e->getMessage());
        }

        // CLI fallback when uvicorn is briefly unavailable.
        $pythonBin = base_path('python-service/venv/Scripts/python.exe');
        if (! is_file($pythonBin)) {
            $pythonBin = 'python';
        }
        $sourceArg = str_replace('"', '\\"', (string) ($importSource ?? ''));
        $script = 'from report_generator import normalize_existing_filled_docx; normalize_existing_filled_docx(r"'
            . str_replace('"', '\\"', $docxPath) . '", import_source="' . $sourceArg . '")';
        $cmd = escapeshellarg($pythonBin) . ' -c ' . escapeshellarg($script) . ' 2>&1';
        $cwd = base_path('python-service');
        $output = [];
        $exitCode = 0;
        $prev = getcwd();
        @chdir($cwd);
        exec($cmd, $output, $exitCode);
        if ($prev) {
            @chdir($prev);
        }
        clearstatcache(true, $docxPath);
        if ($exitCode !== 0) {
            Log::warning('DOCX normalize-layout CLI failed', [
                'path' => $docxPath,
                'exit' => $exitCode,
                'out'  => implode("\n", array_slice($output, -5)),
            ]);
        }
    }

    /**
     * Convert filled DOCX → PDF via LibreOffice and return absolute PDF path.
     * Caches beside the DOCX; regenerates when forced or DOCX is newer.
     */
    private function ensureFilledPdfOnDisk(Report $report, bool $force = false): string
    {
        $this->extendPythonExecutionTime();

        $relative = $report->docx_filled_path;
        if (! $relative) {
            abort(404, 'Filled DOCX not found.');
        }

        $docxPath = storage_path('app/public/' . ltrim($relative, '/'));
        if (! is_file($docxPath)) {
            abort(404, 'Filled DOCX file not found on disk.');
        }

        $pdfPath = dirname($docxPath) . DIRECTORY_SEPARATOR . pathinfo($docxPath, PATHINFO_FILENAME) . '.pdf';
        $isNeq = $this->isNeqImportSource($report->import_source ?? null);
        // Requal/reval layout_v6 = 8.4 key+value + unbold VFD/dept labels.
        // NEQ layout_v18 = page break after TOC so 1.0 OBJECTIVE starts next page (was v17 TOC dots).
        $layoutMarker = $docxPath . ($isNeq ? '.layout_v18' : '.layout_v6');
        $needsLayout = ! is_file($layoutMarker);
        if ($needsLayout) {
            $legacySuffixes = $isNeq
                ? ['.layout_v1', '.layout_v2', '.layout_v3', '.layout_v4', '.layout_v5', '.layout_v6', '.layout_v7', '.layout_v8', '.layout_v9', '.layout_v10', '.layout_v11', '.layout_v12', '.layout_v13', '.layout_v14', '.layout_v15', '.layout_v16', '.layout_v17']
                : ['.layout_v1', '.layout_v2', '.layout_v3', '.layout_v4', '.layout_v5'];
            foreach ($legacySuffixes as $legacySuffix) {
                $legacyMarker = $docxPath . $legacySuffix;
                if (is_file($legacyMarker)) {
                    @unlink($legacyMarker);
                }
            }
        }

        clearstatcache(true, $docxPath);
        clearstatcache(true, $pdfPath);

        $docxMtime = (int) @filemtime($docxPath);
        $pdfFresh = is_file($pdfPath) && filesize($pdfPath) > 0 && (int) filemtime($pdfPath) >= $docxMtime;
        if (! $force && $pdfFresh && ! $needsLayout) {
            return $pdfPath;
        }

        // Revalidation / requalification / NEQ: normalize before LO convert (once, or on force).
        if ($needsLayout || $force || ! $pdfFresh) {
            $this->normalizeFilledDocxLayoutOnDisk($docxPath, $report->import_source ?? null);
            @file_put_contents($layoutMarker, (string) time());
            clearstatcache(true, $docxPath);
        }

        if (is_file($pdfPath)) {
            @unlink($pdfPath);
        }

        $sofficeBinary = $this->resolveLibreOfficeBinary();
        if ($sofficeBinary === null) {
            abort(500, 'LibreOffice is required for PDF conversion.');
        }

        $quoteForShell = static function (string $value): string {
            if (DIRECTORY_SEPARATOR === '\\') {
                return '"' . str_replace('"', '\"', $value) . '"';
            }

            return escapeshellarg($value);
        };

        // [LIBREOFFICE COMMAND] soffice --headless --convert-to pdf --outdir <folder> <filled.docx>
        $command = $quoteForShell($sofficeBinary)
            . ' --headless --convert-to pdf --outdir '
            . $quoteForShell(dirname($docxPath))
            . ' '
            . $quoteForShell($docxPath)
            . ' 2>&1';

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        clearstatcache(true, $pdfPath);

        if ($exitCode !== 0 || ! is_file($pdfPath)) {
            $tail = implode(PHP_EOL, array_slice($output, -5));
            Log::error('DOCX PDF conversion failed', [
                'report_id' => $report->id,
                'docx_path' => $docxPath,
                'pdf_path'  => $pdfPath,
                'exit_code' => $exitCode,
                'output'    => $tail,
            ]);
            abort(500, 'Unable to convert DOCX to PDF. ' . $tail);
        }

        Log::info('DOCX PDF conversion complete', [
            'report_id' => $report->id,
            'pdf_path'  => $pdfPath,
            'pdf_size'  => filesize($pdfPath),
            'forced'    => $force,
        ]);

        return $pdfPath;
    }

    /**
     * Browser/download filename from original protocol id (REQ1 / REV2 / NEQ3).
     */
    private function reportExportFilename(Report $report, string $extension = 'docx'): string
    {
        $code = trim($report->displayCode());
        $safe = preg_replace('/[^\w.\-]+/', '_', $code) ?: ('report-' . $report->id);
        $ext = ltrim(strtolower($extension), '.');

        return $safe . '.' . ($ext !== '' ? $ext : 'docx');
    }

    /**
     * Inline PDF preview for report show — same filled DOCX as download (real page count).
     */
    // Streams filled PDF with Content-Disposition: inline for iframe embedding.
    // Filename uses original protocol id + .docx so the viewer title is REQ1.docx, not report-18.pdf.
    public function previewFilledPdf(Report $report)
    {
        $pdfPath = $this->ensureFilledPdfOnDisk($report, false);

        clearstatcache(true, $pdfPath);
        $mtime = is_file($pdfPath) ? (string) filemtime($pdfPath) : (string) time();
        $filename = $this->reportExportFilename($report, 'docx');

        return response()->file($pdfPath, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
            // Avoid stale browser PDF after post-approval stamps.
            'Cache-Control'       => 'private, no-cache, must-revalidate',
            'ETag'                => '"' . $mtime . '"',
        ]);
    }

    /**
     * Download filled DOCX as PDF.
     *
     * [LIBREOFFICE] Uses soffice --headless --convert-to pdf
     * This is a download helper only — not the TOC page calculator.
     * TOC pages are fixed earlier by Python API POST /fill.
     */
    // ── downloadFilledPdf() ──────────────────────────────
    // 1. Find filled DOCX on disk (docx_filled_path)
    // 2. [LIBREOFFICE] soffice --headless --convert-to pdf
    // 3. Send PDF to browser
    public function downloadFilledPdf(Report $report)
    {
        // NEQ: PDF download only after full POST chain completes.
        if (
            ($report->import_source ?? '') === 'new_equipment_qualification'
            && ($report->status ?? '') !== 'approved'
        ) {
            abort(403, 'Download is available only after all post-approval steps are complete.');
        }

        $pdfPath = $this->ensureFilledPdfOnDisk($report, true);

        ProtocolAuditLogger::recordExported($report, 'PDF');

        return response()->download(
            $pdfPath,
            $this->reportExportFilename($report, 'docx')
        )->deleteFileAfterSend(true);
    }

    /**
     * True when a filled DOCX exists on disk (can build print-accurate PDF preview).
     */
    public function hasFilledDocxOnDisk(Report $report): bool
    {
        $relative = $report->docx_filled_path;
        if (! $relative) {
            return false;
        }

        return is_file(storage_path('app/public/' . ltrim($relative, '/')));
    }

    /**
     * True when requal/reval is in POST but PRE-APPROVAL Signature & Date cells are still blank.
     * Used by show to regenerate the filled DOCX/PDF once after send-to-post wipe.
     */
    public function requalPreApprovalSignaturesNeedRestore(Report $report): bool
    {
        if (! in_array(($report->import_source ?? ''), ['requalification', 'revalidation'], true)) {
            return false;
        }
        // PRE complete (pre_approved) or already in POST — blank Signature & Date must be restored.
        if (! $this->requalificationPreApprovalIsComplete($report)) {
            return false;
        }

        $fields = $report->docx_import_fields ?? [];
        if (! is_array($fields) || $fields === []) {
            return false;
        }

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $loc = (string) ($field['location'] ?? '');
            // PRE-APPROVAL signature tables are the first three Word tables.
            if (! preg_match('/^table_[123]_row_\d+_col_\d+$/', $loc)) {
                continue;
            }
            $label = strtolower((string) ($field['label'] ?? ''));
            if (! str_contains($label, 'signature')) {
                continue;
            }
            $val = trim((string) ($field['value'] ?? ''));
            if ($val === '' || in_array($val, ['—', '-', '–'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Show import template form.
     */
    // Admin page to upload a reusable DOCX template (not tied to a report).
    public function createTemplate()
    {
        return view('templates.docx_import.create');
    }

    /**
     * Upload template file and parse placeholders.
     */
    // Parse template via Python /parse, store file on disk, and save placeholder metadata.
    public function uploadTemplate(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:doc,docx'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        try {
            // Send template to Python /parse to discover fillable placeholders.
            $response = Http::timeout(90)
                ->attach(
                    'file',
                    file_get_contents($request->file('file')->getRealPath()),
                    $request->file('file')->getClientOriginalName()
                )
                ->post(env('PYTHON_NLP_URL', 'http://127.0.0.1:8001') . '/parse');

            if (!$response->successful()) {
                return back()->with('error', 'DOCX parse failed: ' . $response->body());
            }

            $result = $response->json();
            Storage::disk('public')->makeDirectory('templates/docx');
            // Persist original template file alongside parsed placeholder list.
            $path   = $request->file('file')->store('templates/docx', 'public');

            $template = Template::create([
                'name'               => $request->name,
                'template_type'      => 'docx',
                'docx_template_path' => $path,
                'docx_placeholders'  => $result['fields'] ?? [],
                'body_html'          => '',
            ]);

            return redirect()->route('templates.show', $template)
                ->with('status', 'DOCX template imported successfully! ' .
                    count($result['fields'] ?? []) . ' fields detected.');

        } catch (\Throwable $e) {
            Log::error('DOCX template import error: ' . $e->getMessage());
            return back()->with('error', 'DOCX parse failed: ' . $e->getMessage());
        }
    }

    /**
     * Decode table_ops from the fill form / draft. Draft autosave used to
     * JSON-encode an already-encoded string, producing nested quotes around [].
     *
     * @param  mixed  $raw
     * @return list<array<string, mixed>>
     */
    private function normalizeTableOpsPayload(mixed $raw): array
    {
        $decoded = $raw;
        for ($i = 0; $i < 12; $i++) {
            if (is_array($decoded)) {
                $ops = [];
                foreach ($decoded as $op) {
                    if (is_array($op) && isset($op['op'])) {
                        $ops[] = $op;
                    }
                }

                return $ops;
            }
            if (! is_string($decoded)) {
                return [];
            }
            $trimmed = trim($decoded);
            if ($trimmed === '' || $trimmed === '[]' || $trimmed === 'null') {
                return [];
            }
            $next = json_decode($trimmed, true);
            if ($next === null && json_last_error() !== JSON_ERROR_NONE) {
                return [];
            }
            $decoded = $next;
        }

        return [];
    }

    // ── normalizeImportFields() ──────────────────────────
    // Normalizes Python /parse field objects into a consistent Laravel array shape.
    // Parameters: array $fields — raw fields list from Python /parse JSON response.
    // Returns: array of field arrays (label, location, value, locked, type); skips invalid entries.
    // Key steps:
    // 1. Requires each field to be an array with a non-empty location key
    // 2. Coerces locked flag from bool/string/numeric into boolean
    // 3. Casts label, value, and type to strings for safe JSON storage on SQL Server
    private function normalizeImportFields(array $fields): array
    {
        $normalized = [];

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $location = trim((string) ($field['location'] ?? ''));
            if ($location === '') {
                continue;
            }

            $locked = $field['locked'] ?? false;
            // Coerce parser locked values from string/number into boolean for SQL Server JSON.
            if (is_string($locked)) {
                $locked = in_array(strtolower(trim($locked)), ['1', 'true', 'yes'], true);
            } else {
                $locked = (bool) $locked;
            }

            $normalized[] = array_filter([
                'label'                   => (string) ($field['label'] ?? $location),
                'location'                => $location,
                'value'                   => (string) ($field['value'] ?? ''),
                'locked'                  => $locked,
                'type'                    => (string) ($field['type'] ?? ''),
                'widget'                  => isset($field['widget']) ? (string) $field['widget'] : null,
                'cover_field'             => isset($field['cover_field']) && trim((string) $field['cover_field']) !== ''
                    ? (string) $field['cover_field']
                    : null,
                'approver_role'           => isset($field['approver_role']) ? (string) $field['approver_role'] : null,
                'signature_date_location' => isset($field['signature_date_location'])
                    ? (string) $field['signature_date_location']
                    : null,
                'prefilled'               => ($field['prefilled'] ?? false) ? true : null,
                'stamp_eligible'          => array_key_exists('stamp_eligible', $field)
                    ? (bool) $field['stamp_eligible']
                    : null,
                'draft_editable'          => array_key_exists('draft_editable', $field)
                    ? (bool) $field['draft_editable']
                    : null,
                'auto_serial'             => $field['auto_serial'] ?? null,
            ], fn ($v) => $v !== null);
        }

        $normalized = $this->lockPrefilledImportFields($normalized);

        return array_values($normalized);
    }

    /**
     * NEQ-only field unlock/lock rules. Requalification / revalidation are never modified here.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function applySourceSpecificImportFieldRules(array $fields, string $importSource): array
    {
        if ($importSource === 'new_equipment_qualification') {
            $fields = $this->upgradeCertificateNumberTextFields($fields);
            $fields = $this->upgradeNeqPlaceholderTableCells($fields);
            $fields = $this->upgradeNeqTrainingPersonnelFields($fields);
            $fields = $this->repairNeqBlankPartSectionHeadings($fields);
            $fields = $this->stripNeqMisparsedSectionHeadingFields($fields);

            return $this->applyNeqFillFieldRules($fields);
        }

        return $fields;
    }

    /**
     * Drop / lock mis-parsed section titles that became paragraph_blank inputs
     * (INSTALLATION CHECKS:, Pre-Installation Checks:, Motor Verification:).
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function stripNeqMisparsedSectionHeadingFields(array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $label = (string) ($field['label'] ?? '');
            $type = strtolower(trim((string) ($field['type'] ?? '')));
            $loc = (string) ($field['location'] ?? '');

            // Keep real fill blanks (machine I.D. / responsibility (blank N), etc.).
            $isBlankPart = $type === 'paragraph_blank_part'
                || preg_match('/_blank_\d+$/', $loc) === 1
                || preg_match('/\(\s*blank\s*\d+\s*\)\s*$/i', $label) === 1;

            // Drop mis-parsed section titles so the Word heading (with 7.0 / 7.2 / 8.2.1) shows.
            if (
                ! $isBlankPart
                && str_starts_with($loc, 'para_')
                && $this->isLockedSectionHeadingText($label)
                && in_array($type, ['paragraph_blank', 'paragraph_blank_part', 'text', 'protocol_cover', ''], true)
            ) {
                continue;
            }

            // Bullet lines wrongly tagged as protocol_cover via "equipment id" substring match
            // (e.g. "General appearance & equipment identification") — no description box.
            if (
                str_starts_with($loc, 'para_')
                && ($type === 'protocol_cover' || ! empty($field['cover_field']))
                && ! str_starts_with($loc, 'header_')
                && preg_match('/equipment\s+identification/i', $label) === 1
            ) {
                continue;
            }

            $out[] = $field;
        }

        return $out;
    }

    /**
     * Att01 — Identification of Personnel and Training:
     * Name + Training Imparted by = user selects; Department/Signature auto from Name.
     * Validation /Quality Assurance = the only POST approval assignee.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function upgradeNeqTrainingPersonnelFields(array $fields): array
    {
        $byTable = [];
        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }
            $loc = (string) ($field['location'] ?? '');
            if (! preg_match('/^table_(\d+)_row_(\d+)_col_(\d+)$/', $loc, $m)) {
                continue;
            }
            $byTable[(int) $m[1]][(int) $m[2]][(int) $m[3]] = $idx;
        }

        foreach ($byTable as $tableNum => $rows) {
            if (! isset($rows[1][0], $rows[1][1], $rows[1][2], $rows[1][3])) {
                continue;
            }
            $labels = [];
            foreach ([0, 1, 2, 3, 4] as $col) {
                $idx = $rows[1][$col] ?? null;
                $labels[$col] = strtolower(trim((string) (($idx !== null ? ($fields[$idx]['label'] ?? '') : ''))));
            }
            // Fingerprint Att01 grid: Name | Department | Signature | Training Imparted by | Date
            if (
                ! str_contains($labels[0], 'name')
                || ! str_contains($labels[1], 'department')
                || ! str_contains($labels[2], 'signature')
                || ! str_contains($labels[3], 'training')
            ) {
                continue;
            }

            ksort($rows);
            foreach ($rows as $rowNum => $cols) {
                if ($rowNum < 1) {
                    continue;
                }
                $nameIdx = $cols[0] ?? null;
                $deptIdx = $cols[1] ?? null;
                $sigIdx = $cols[2] ?? null;
                $trainIdx = $cols[3] ?? null;
                if ($nameIdx === null || ! is_array($fields[$nameIdx] ?? null)) {
                    continue;
                }

                $nameLoc = (string) ($fields[$nameIdx]['location'] ?? '');
                $deptLoc = $deptIdx !== null ? (string) ($fields[$deptIdx]['location'] ?? '') : '';
                $sigLoc = $sigIdx !== null ? (string) ($fields[$sigIdx]['location'] ?? '') : '';

                // Non-POST role so table people never become approval steps.
                $fields[$nameIdx]['type'] = 'approver';
                $fields[$nameIdx]['widget'] = 'approver';
                $fields[$nameIdx]['locked'] = false;
                $fields[$nameIdx]['draft_editable'] = true;
                $fields[$nameIdx]['approver_role'] = 'training_personnel_name';
                $fields[$nameIdx]['department_location'] = $deptLoc;
                $fields[$nameIdx]['signature_location'] = $sigLoc;
                $fields[$nameIdx]['stamp_eligible'] = false;

                if ($deptIdx !== null && is_array($fields[$deptIdx] ?? null)) {
                    $fields[$deptIdx]['type'] = 'table_multicol';
                    $fields[$deptIdx]['widget'] = 'text';
                    $fields[$deptIdx]['locked'] = false;
                    $fields[$deptIdx]['draft_editable'] = true;
                    $fields[$deptIdx]['auto_from_name'] = true;
                    // Strip leftover Prepared/Engineering/QA roles from auto-filled cells.
                    unset($fields[$deptIdx]['approver_role']);
                }
                if ($sigIdx !== null && is_array($fields[$sigIdx] ?? null)) {
                    $fields[$sigIdx]['type'] = 'table_multicol';
                    $fields[$sigIdx]['widget'] = 'text';
                    $fields[$sigIdx]['locked'] = false;
                    $fields[$sigIdx]['draft_editable'] = true;
                    $fields[$sigIdx]['auto_from_name'] = true;
                    unset($fields[$sigIdx]['approver_role']);
                }
                $dateIdx = $cols[4] ?? null;
                if ($dateIdx !== null && is_array($fields[$dateIdx] ?? null)) {
                    unset($fields[$dateIdx]['approver_role']);
                    $fields[$dateIdx]['stamp_eligible'] = false;
                    $fields[$dateIdx]['type'] = 'table_multicol';
                    $fields[$dateIdx]['widget'] = 'datetime-local';
                    $fields[$dateIdx]['locked'] = false;
                    $fields[$dateIdx]['draft_editable'] = true;
                    $fields[$dateIdx]['prefilled'] = false;
                }
                if ($trainIdx !== null && is_array($fields[$trainIdx] ?? null)) {
                    $fields[$trainIdx]['type'] = 'approver';
                    $fields[$trainIdx]['widget'] = 'approver';
                    $fields[$trainIdx]['locked'] = false;
                    $fields[$trainIdx]['draft_editable'] = true;
                    $fields[$trainIdx]['approver_role'] = 'training_imparted_by_name';
                    $fields[$trainIdx]['stamp_eligible'] = false;
                }
            }

            // Att01 table must never contribute Prepared/Checked/Approved POST steps.
            foreach ($rows as $cols) {
                foreach ($cols as $cellIdx) {
                    if (! is_array($fields[$cellIdx] ?? null)) {
                        continue;
                    }
                    $role = trim((string) ($fields[$cellIdx]['approver_role'] ?? ''));
                    if ($role !== '' && str_starts_with($role, 'post_')) {
                        unset($fields[$cellIdx]['approver_role']);
                    }
                }
            }
        }

        // Validation /Quality Assurance: — sole POST approval assignee for Att01.
        $att01Detected = false;
        foreach ($byTable as $rows) {
            if (! isset($rows[1][0], $rows[1][1], $rows[1][2], $rows[1][3])) {
                continue;
            }
            $labels = [];
            foreach ([0, 1, 2, 3] as $col) {
                $idx = $rows[1][$col] ?? null;
                $labels[$col] = strtolower(trim((string) (($idx !== null ? ($fields[$idx]['label'] ?? '') : ''))));
            }
            if (
                str_contains($labels[0], 'name')
                && str_contains($labels[1], 'department')
                && str_contains($labels[2], 'signature')
                && str_contains($labels[3], 'training')
            ) {
                $att01Detected = true;
                break;
            }
        }

        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }
            $label = strtolower(trim((string) ($field['label'] ?? '')));
            $loc = strtolower(trim((string) ($field['location'] ?? '')));
            if (! str_starts_with($loc, 'para_')) {
                continue;
            }
            if (
                ! str_contains($label, 'validation')
                || (! str_contains($label, 'quality') && ! str_contains($label, 'assurance'))
            ) {
                continue;
            }
            $fields[$idx]['type'] = 'approver';
            $fields[$idx]['widget'] = 'approver';
            $fields[$idx]['locked'] = false;
            $fields[$idx]['draft_editable'] = true;
            $fields[$idx]['approver_role'] = 'post_approved_by_quality_name';
            $fields[$idx]['stamp_eligible'] = true;
            $fields[$idx]['label'] = 'Validation /Quality Assurance';
        }

        // For Att01 docs, drop any other POST approver selects so only Validation/QA is required.
        if ($att01Detected) {
            foreach ($fields as $idx => $field) {
                if (! is_array($field)) {
                    continue;
                }
                $role = trim((string) ($field['approver_role'] ?? ''));
                if ($role === '' || ! str_starts_with($role, 'post_')) {
                    continue;
                }
                if ($role === 'post_approved_by_quality_name') {
                    continue;
                }
                unset($fields[$idx]['approver_role']);
                if (
                    strtolower(trim((string) ($field['type'] ?? ''))) === 'approver'
                    || strtolower(trim((string) ($field['widget'] ?? ''))) === 'approver'
                ) {
                    $fields[$idx]['type'] = 'table_multicol';
                    $fields[$idx]['widget'] = 'text';
                    $fields[$idx]['stamp_eligible'] = false;
                }
            }
        }

        return $fields;
    }

    /**
     * Requalification / Revalidation Pre-Approval drafting rules:
     * Unlocks all protocol content (paragraphs, table labels, table cells, criteria)
     * so creators can edit the protocol prior to pre-approval sign-off.
     * Only table row 0 headers, serial numbers, and approval stamps stay non-editable.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function applyRequalificationPreApprovalUnlockRules(array $fields): array
    {
        $unlocked = 0;
        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }

            $type = strtolower(trim((string) ($field['type'] ?? '')));
            $location = strtolower(trim((string) ($field['location'] ?? '')));
            $label = strtolower(trim((string) ($field['label'] ?? '')));
            $isSlNoField = $type === 'table_sl_no'
                || preg_match('/^(sr\.?\s*no\.?|s\.?\s*no\.?|sl\.?\s*no\.?|#)\b/i', $label) === 1;

            // Sr. No. / Sl. No.: Word-numbered cells stay locked; blank stay editable.
            if ($isSlNoField && preg_match('/^table_\d+_row_[1-9]\d*_col_/', $location) === 1) {
                $serialVal = trim((string) ($field['value'] ?? ''));
                if ($serialVal !== '' && ! $this->looksLikeSlNoValue($serialVal)) {
                    $serialVal = '';
                }
                if (
                    $serialVal === ''
                    && array_key_exists('auto_serial', $field)
                    && $field['auto_serial'] !== null
                    && $field['auto_serial'] !== ''
                ) {
                    $serialVal = ((int) $field['auto_serial']).'.';
                }
                $fields[$idx]['value'] = $serialVal;
                $fields[$idx]['type'] = 'table_multicol';
                $fields[$idx]['widget'] = 'text';
                if ($serialVal !== '' && $this->looksLikeSlNoValue($serialVal)) {
                    $fields[$idx]['locked'] = true;
                    $fields[$idx]['prefilled'] = true;
                    $fields[$idx]['user_filled'] = false;
                    $fields[$idx]['draft_editable'] = false;
                } else {
                    $fields[$idx]['locked'] = false;
                    $fields[$idx]['prefilled'] = false;
                    $fields[$idx]['user_filled'] = true;
                    $fields[$idx]['draft_editable'] = true;
                    $unlocked++;
                }
                continue;
            }

            // Never unlock 2-col label cells (col 0) — Sl./Sr. No. handled above.
            // Exception: draft Add Row / user-typed left cells must stay editable.
            if ($type === 'table_2col_label' || str_ends_with($location, '_col_0')) {
                $isDraftOwnedCol0 = ($field['user_filled'] ?? false) === true
                    || ($field['draft_editable'] ?? false) === true;
                if ($isDraftOwnedCol0) {
                    $fields[$idx]['locked'] = false;
                    $fields[$idx]['prefilled'] = false;
                    $fields[$idx]['user_filled'] = true;
                    $fields[$idx]['draft_editable'] = true;
                    $unlocked++;
                    continue;
                }
                $fields[$idx]['locked'] = true;
                continue;
            }

            // Headings / fixed text stay locked (never lock blank-part fill targets).
            $isBlankPart = $type === 'paragraph_blank_part'
                || preg_match('/_blank_\d+$/', $location) === 1
                || preg_match('/\(\s*blank\s*\d+\s*\)\s*$/i', (string) ($field['label'] ?? '')) === 1;
            if ($isBlankPart) {
                $fields[$idx]['type'] = 'paragraph_blank_part';
                $fields[$idx]['widget'] = 'text';
                $fields[$idx]['locked'] = false;
                $unlocked++;
                continue;
            }
            if ($this->isLockedSectionHeadingText($label) || str_contains($label, 'methodology') || str_contains($label, 'report approval')) {
                $fields[$idx]['locked'] = true;
                continue;
            }

            // Row 0 col 1 of 2-col key-value tables is fillable (Name of Equipment value)
            if (preg_match('/^table_\d+_row_0_col_1$/', $location) && $type === 'table_2col') {
                $fields[$idx]['locked'] = false;
                $unlocked++;
                continue;
            }

            // Never unlock table header rows (row 0)
            if (preg_match('/^table_\d+_row_0(_col_\d+)?$/', $location)) {
                $fields[$idx]['locked'] = true;
                continue;
            }

            // Approval stamp cells stay managed by e-sign workflow
            if (str_starts_with($type, 'approval_stamp') || ($field['stamp_eligible'] ?? true) === false) {
                continue;
            }

            // Approver selector dropdowns are editable for selecting signers
            if ($type === 'approver') {
                $fields[$idx]['locked'] = false;
                $fields[$idx]['prefilled'] = false;
                $fields[$idx]['user_filled'] = true;
                $fields[$idx]['draft_editable'] = true;
                continue;
            }

            // All content paragraphs, blanks, table cells are editable.
            // Clear prefilled so Python /preview does not re-lock baked send-back values
            // (e.g. "for testing", "cvvv") as Word template text.
            $fields[$idx]['locked'] = false;
            $fields[$idx]['prefilled'] = false;
            $fields[$idx]['user_filled'] = true;
            $fields[$idx]['draft_editable'] = true;
            $unlocked++;
        }

        Log::info('Requalification/Revalidation pre-approval unlock rules applied', [
            'unlocked_fields' => $unlocked,
            'total_fields' => count($fields),
        ]);

        // Match Word: Qualification History "Initial Qualification (PQ)" row stays
        // static (PQ label + Report No. + Approval Date), not editable inputs.
        // Also lock original Word template columns during pre-approval fill:
        // Description, Instrument Name, Checks, Methodology, Acceptance, Sr. No.
        $fields = $this->lockQualificationHistoryTemplateCells($fields);
        $fields = $this->lockConnectedAccessoriesDescriptions($fields);

        return $this->lockRequalificationTemplateFilledColumns($fields);
    }

    /**
     * Match Word Qualification History layout:
     *   | Initial Qualification (PQ) | (blank) | (blank) |
     *   | Periodic Requalification   | (blank) | (blank) |
     * Sub-header cells keep text "Report No." / "Approval Date".
     * Never put those labels into the Initial data-row blanks, and never use a date picker there.
     *
     * @param  list<array<string, mixed>>  $fields
     * @return list<array<string, mixed>>
     */
    private function lockQualificationHistoryTemplateCells(array $fields): array
    {
        $initialRows = [];
        $periodicRows = [];
        $qualTableIds = [];
        $revisionTableIds = [];
        $rowCells = []; // rowKey => [col => value]

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $location = (string) ($field['location'] ?? '');
            if (preg_match('/^(table_(\d+)_row_\d+)_col_(\d+)$/', $location, $m) !== 1) {
                continue;
            }
            $rowKey = $m[1];
            $tableId = $m[2];
            $col = (int) $m[3];
            $value = trim((string) ($field['value'] ?? ''));
            $label = strtolower(trim((string) ($field['label'] ?? '')));
            $rowCells[$rowKey][$col] = $value;
            // 15.0 REVISION HISTORY must never be treated as Qualification History
            // (Reason "Initial Qualification" was overwriting Revision No. "1").
            if (
                str_contains($label, 'revision no')
                || str_contains($label, 'rev. no')
                || str_contains($label, 'rev no')
                || str_contains($label, 'reason for revision')
            ) {
                $revisionTableIds[$tableId] = true;
                continue;
            }
            if (
                str_contains($label, 'qualification')
                || str_contains($label, 'report no')
                || str_contains($label, 'approval date')
                || str_contains(strtolower($value), 'initial qualification')
                || str_contains(strtolower($value), 'periodic requal')
            ) {
                $qualTableIds[$tableId] = true;
            }
            if (preg_match('/^initial\s+qualification\b/i', $value) === 1) {
                $initialRows[$rowKey] = true;
            }
            if (preg_match('/^periodic\s+requalification\b/i', $value) === 1) {
                $periodicRows[$rowKey] = true;
            }
        }

        foreach (array_keys($revisionTableIds) as $revTableId) {
            unset($qualTableIds[$revTableId]);
        }
        foreach (array_keys($initialRows) as $rowKey) {
            if (preg_match('/^table_(\d+)_row_/', $rowKey, $rm) === 1 && isset($revisionTableIds[$rm[1]])) {
                unset($initialRows[$rowKey]);
            }
        }
        foreach (array_keys($periodicRows) as $rowKey) {
            if (preg_match('/^table_(\d+)_row_/', $rowKey, $rm) === 1 && isset($revisionTableIds[$rm[1]])) {
                unset($periodicRows[$rowKey]);
            }
        }

        // Repair already-corrupted Revision No. cells (PQ text instead of 1/2/3).
        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }
            $label = strtolower(trim((string) ($field['label'] ?? '')));
            $value = trim((string) ($field['value'] ?? ''));
            $location = (string) ($field['location'] ?? '');
            if (
                (
                    str_contains($label, 'revision no')
                    || str_contains($label, 'rev. no')
                    || str_contains($label, 'rev no')
                )
                && preg_match('/^initial\s+qualification\b/i', $value) === 1
                && preg_match('/^(table_\d+_row_(\d+))_col_0$/', $location, $rm) === 1
            ) {
                $fields[$idx]['value'] = (string) max(1, ((int) $rm[2]));
                $fields[$idx]['locked'] = false;
                $fields[$idx]['draft_editable'] = true;
                $fields[$idx]['prefilled'] = false;
            }
        }

        // Word sub-header row: same sheet as PQ merge, but col1/col2 are "Report No." / "Approval Date".
        $subheaderRows = [];
        foreach ($rowCells as $rowKey => $cols) {
            $c1 = trim((string) ($cols[1] ?? ''));
            $c2 = trim((string) ($cols[2] ?? ''));
            if (
                preg_match('/^report\s*no\.?$/i', $c1) === 1
                && preg_match('/^approval\s*date$/i', $c2) === 1
            ) {
                $subheaderRows[$rowKey] = true;
            }
        }

        if ($qualTableIds === [] && $initialRows === [] && $periodicRows === []) {
            return $fields;
        }

        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }
            $location = (string) ($field['location'] ?? '');
            if (preg_match('/^(table_(\d+)_row_\d+)_col_(\d+)$/', $location, $m) !== 1) {
                continue;
            }
            $rowKey = $m[1];
            $tableId = $m[2];
            $col = (int) $m[3];
            if (! isset($qualTableIds[$tableId]) && ! isset($initialRows[$rowKey]) && ! isset($periodicRows[$rowKey])) {
                continue;
            }

            $value = trim((string) ($field['value'] ?? ''));

            // Sub-header row keeps "Report No." / "Approval Date" as locked labels.
            if (isset($subheaderRows[$rowKey])) {
                $fields[$idx]['locked'] = true;
                $fields[$idx]['widget'] = 'text';
                $fields[$idx]['prefilled'] = true;
                $fields[$idx]['user_filled'] = false;
                $fields[$idx]['draft_editable'] = false;
                continue;
            }

            // Left-column static labels.
            if (
                preg_match('/^initial\s+qualification\b/i', $value) === 1
                || preg_match('/^periodic\s+requalification\b/i', $value) === 1
            ) {
                $fields[$idx]['locked'] = true;
                $fields[$idx]['widget'] = 'text';
                $fields[$idx]['prefilled'] = true;
                $fields[$idx]['user_filled'] = false;
                $fields[$idx]['draft_editable'] = false;
                continue;
            }

            // Initial Qualification 2nd sub-row (under Report No./Approval Date): fillable.
            // Periodic detail cells: same — col1 text, col2 date calendar.
            if (
                ((isset($initialRows[$rowKey]) && ! isset($subheaderRows[$rowKey]))
                    || isset($periodicRows[$rowKey]))
                && $col > 0
            ) {
                if (
                    preg_match('/^report\s*no\.?$/i', $value) === 1
                    || preg_match('/^approval\s*date$/i', $value) === 1
                ) {
                    $fields[$idx]['value'] = '';
                }
                $fields[$idx]['value'] = (string) ($fields[$idx]['value'] ?? '');
                $fields[$idx]['locked'] = false;
                $isApprovalDateCol = $col === 2;
                $fields[$idx]['widget'] = $isApprovalDateCol ? 'date' : 'text';
                $fields[$idx]['type'] = $fields[$idx]['type'] ?: 'table_multicol';
                // Explicit labels so JS/Python never treat Report No. as a date via the merged header.
                $fields[$idx]['label'] = $isApprovalDateCol ? 'Approval Date' : 'Report No.';
                $fields[$idx]['prefilled'] = false;
                $fields[$idx]['user_filled'] = true;
                $fields[$idx]['draft_editable'] = true;
                if (! $isApprovalDateCol) {
                    unset($fields[$idx]['date_selected']);
                    $fields[$idx]['date_selected'] = false;
                }
            }
        }

        return $fields;
    }

    /**
     * True when a Qualification History cell must stay template-locked (never draft-unlocked).
     */
    private function isQualificationHistoryTemplateLock(array $field): bool
    {
        $label = strtolower(trim((string) ($field['label'] ?? '')));
        // Never lock 15.0 REVISION HISTORY cells as Qualification History.
        if (
            str_contains($label, 'revision no')
            || str_contains($label, 'rev. no')
            || str_contains($label, 'rev no')
            || str_contains($label, 'reason for revision')
        ) {
            return false;
        }

        $value = trim((string) ($field['value'] ?? ''));
        if (preg_match('/^initial\s+qualification\b/i', $value) === 1) {
            return true;
        }
        if (preg_match('/^periodic\s+requalification\b/i', $value) === 1) {
            return true;
        }
        if (
            preg_match('/^report\s*no\.?$/i', $value) === 1
            || preg_match('/^approval\s*date$/i', $value) === 1
        ) {
            return true;
        }

        // Only labels / sub-headers stay template-locked.
        // Initial Qualification 2nd sub-row blanks are fillable (not locked here).
        return false;
    }

    /**
     * Checks if a table column label represents an execution fill field that must be editable post-pre-approval.
     */
    private function isExecutionFillColumn(string $label): bool
    {
        $label = strtolower(trim($label));
        if ($label === '') {
            return false;
        }

        if (str_contains($label, 'observation') || str_contains($label, 'result') || str_contains($label, 'evaluation') || str_contains($label, 'finding')) {
            return true;
        }
        if (
            str_contains($label, 'instrument id')
            || str_contains($label, 'serial no')
            || str_contains($label, 'model no')
            || str_contains($label, 'identification no')
            || str_contains($label, 'identification number')
        ) {
            return true;
        }
        if (str_contains($label, 'calibration') || (str_contains($label, 'date') && ! str_contains($label, 'effective'))) {
            return true;
        }
        if (str_contains($label, 'deviation') || str_contains($label, 'corrective action') || str_contains($label, 'preventive action')) {
            return true;
        }
        if (str_contains($label, 'remark') || str_contains($label, 'comment') || str_contains($label, 'pass/fail') || str_contains($label, 'status')) {
            return true;
        }
        if (
            str_starts_with($label, 'done by')
            || str_starts_with($label, 'performed by')
            || str_contains($label, 'tested by')
            || str_contains($label, 'checked by')
            || str_contains($label, 'checked. by')
        ) {
            return true;
        }
        // 8.2 Preventive Maintenance execution cells.
        if (str_contains($label, 'preventive maintenance done') || str_contains($label, 'preventive maintenance due')) {
            return true;
        }
        // 8.2 Preventive Maintenance "Equipment Name / ID" is fillable.
        // Do NOT treat Connected Accessories "Description" or Instrument Name as execution.
        if (str_contains($label, 'equipment name / id') || $label === 'equipment name / id') {
            return true;
        }

        return false;
    }

    /**
     * Requalification / Revalidation Post-Pre-Approval lock rules:
     * Once Pre-Approval is granted (status is pre_approved or stage is execution),
     * all protocol template content MUST BE LOCKED:
     * - All body paragraphs (Objective, Scope, Responsibilities, etc.) -> locked = true
     * - All 2-column parameter keys / labels on the left (Tag. No., Plant / Module, MOC, etc., Col 0) -> locked = true
     * - All table row 0 headers -> locked = true
     * - All table serial numbers (Sl. No.) -> locked = true
     * - Connected accessories descriptions & existing template content -> locked = true
     *
     * ONLY execution fill fields remain editable (locked = false):
     * - Paragraph fill-in-the-blanks (e.g. "demonstrate that _____ located at...") -> locked = false
     * - 2-column description/details on the right (Col 1) -> locked = false
     * - Multi-column execution entry columns (Observation, Done By, Date, Result, Remarks, Attachment) -> locked = false
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function applyRequalificationPostPreApprovalLockRules(array $fields): array
    {
        $fields = $this->lockConnectedAccessoriesDescriptions($fields);
        $fields = $this->lockRequalificationTemplateFilledColumns($fields);

        $lockedCount = 0;
        $unlockedCount = 0;

        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }

            $type = strtolower(trim((string) ($field['type'] ?? '')));
            $location = strtolower(trim((string) ($field['location'] ?? '')));
            $label = strtolower(trim((string) ($field['label'] ?? '')));

            // Template locks from Checks/Acceptance/etc. — but never block execution
            // columns (Instrument ID, dates, Observation, Checked by, 8.2 PM cells).
            if (($field['locked'] ?? false) === true
                && ($field['prefilled'] ?? false) === true
                && ($field['draft_editable'] ?? true) === false) {
                if ($this->isExecutionFillColumn($label)) {
                    $fields[$idx]['locked'] = false;
                    $fields[$idx]['prefilled'] = false;
                    $fields[$idx]['draft_editable'] = true;
                    $fields[$idx]['user_filled'] = true;
                    $unlockedCount++;
                    continue;
                }
                $lockedCount++;
                continue;
            }

            // 1. Table row 0 headers locked — except 2-col first-row VALUE
            // (Name of Equipment | …) which is fillable during execution fill.
            if (preg_match('/^table_\d+_row_0(_col_(\d+))?$/', $location, $row0m) === 1) {
                $row0Col = isset($row0m[2]) ? (int) $row0m[2] : null;
                if ($type === 'table_2col' && $row0Col === 1) {
                    $fields[$idx]['locked'] = false;
                    $fields[$idx]['prefilled'] = false;
                    $fields[$idx]['draft_editable'] = true;
                    $fields[$idx]['user_filled'] = true;
                    $unlockedCount++;
                    continue;
                }
                $fields[$idx]['locked'] = true;
                $lockedCount++;
                continue;
            }

            // 2. Sr. No. / Sl. No.: numbered cells stay locked; blank stay editable.
            $isSlNoField = $type === 'table_sl_no'
                || preg_match('/^(sr\.?\s*no\.?|s\.?\s*no\.?|sl\.?\s*no\.?|#)\b/i', $label) === 1;
            if ($isSlNoField && preg_match('/^table_\d+_row_[1-9]\d*_col_/', $location) === 1) {
                $serialVal = trim((string) ($field['value'] ?? ''));
                if ($serialVal !== '' && ! $this->looksLikeSlNoValue($serialVal)) {
                    $serialVal = '';
                }
                if (
                    $serialVal === ''
                    && array_key_exists('auto_serial', $field)
                    && $field['auto_serial'] !== null
                    && $field['auto_serial'] !== ''
                ) {
                    $serialVal = ((int) $field['auto_serial']).'.';
                }
                $fields[$idx]['value'] = $serialVal;
                $fields[$idx]['type'] = 'table_multicol';
                $fields[$idx]['widget'] = 'text';
                if ($serialVal !== '' && $this->looksLikeSlNoValue($serialVal)) {
                    $fields[$idx]['locked'] = true;
                    $fields[$idx]['prefilled'] = true;
                    $fields[$idx]['user_filled'] = false;
                    $fields[$idx]['draft_editable'] = false;
                    $lockedCount++;
                } else {
                    $fields[$idx]['locked'] = false;
                    $fields[$idx]['prefilled'] = false;
                    $fields[$idx]['user_filled'] = true;
                    $fields[$idx]['draft_editable'] = true;
                    $unlockedCount++;
                }
                continue;
            }

            // 3. Approval stamps managed by e-sign workflow
            if (str_starts_with($type, 'approval_stamp')) {
                continue;
            }

            // 4. Attachment upload fields MUST BE UNLOCKED during execution fill mode so users can attach files
            if ($type === 'attachment' || ($field['widget'] ?? '') === 'file') {
                $fields[$idx]['locked'] = false;
                $unlockedCount++;
                continue;
            }

            // 5. Paragraph content (Objective, Scope, etc.) locked, BUT paragraph blanks MUST REMAIN UNLOCKED for execution fill
            // Except METHODOLOGY and REPORT APPROVAL, which are strictly locked
            $isBlankPart = $type === 'paragraph_blank_part'
                || preg_match('/_blank_\d+$/', $location) === 1
                || preg_match('/\(\s*blank\s*\d+\s*\)\s*$/i', (string) ($field['label'] ?? '')) === 1;
            if ($isBlankPart || $type === 'paragraph_blank') {
                if ($isBlankPart) {
                    $fields[$idx]['type'] = 'paragraph_blank_part';
                    $fields[$idx]['widget'] = 'text';
                }
                $fields[$idx]['locked'] = false;
                $unlockedCount++;
                continue;
            }
            // 5b. 8.4 Verification P&ID notes (para_N_body) — never lock; these are fill textareas.
            if (preg_match('/_body$/', $location) === 1 || str_contains($location, 'verification_pid')) {
                $fields[$idx]['locked'] = false;
                $fields[$idx]['prefilled'] = false;
                $fields[$idx]['draft_editable'] = true;
                $fields[$idx]['type'] = $type !== '' ? $type : 'paragraph_content';
                $fields[$idx]['widget'] = 'textarea';
                if (trim((string) ($field['value'] ?? '')) !== '') {
                    $fields[$idx]['user_filled'] = true;
                }
                $unlockedCount++;
                continue;
            }
            if ($this->isLockedSectionHeadingText($label) || str_contains($label, 'methodology') || str_contains($label, 'report approval')) {
                $fields[$idx]['locked'] = true;
                $lockedCount++;
                continue;
            }
            if ($type === 'paragraph_content' || str_starts_with($location, 'para_') || str_starts_with($type, 'para')) {
                $fields[$idx]['locked'] = true;
                $lockedCount++;
                continue;
            }

            // 6. Pre-approval approver cells and signatures: ALWAYS LOCKED post-pre-approval
            $role = (string) ($field['approver_role'] ?? '');
            $isPreApprover = ! empty($field['is_pre_approver'])
                || in_array($role, array_keys(self::PRE_APPROVER_FIELDS), true);

            // In Requalification protocols, tables 1, 2, 3 are explicit pre-approval signature tables
            // (never lock execution fill columns or non-signature tables)
            if (! $isPreApprover && ! $this->isExecutionFillColumn($label)) {
                $isPreApprover = preg_match('/^table_[123]_row_\d+_col_\d+$/', $location)
                    && (
                        $type === 'approver'
                        || in_array($role, ['prepared_by_engineering_name', 'checked_by_engineering_name', 'checked_by_production_name', 'checked_by_validation_name', 'checked_by_quality_name', 'approved_by_user_name', 'approved_by_quality_name'], true)
                        || (str_contains($label, 'signature') && preg_match('/^table_[123]_/', $location))
                    );
            }

            if ($isPreApprover) {
                $fields[$idx]['locked'] = true;
                $fields[$idx]['stamp_eligible'] = false;
                $fields[$idx]['widget'] = 'text';
                $lockedCount++;
                continue;
            }

            // 7. Post-approval approver cells: keep unlocked so approvers can be chosen
            if ($type === 'approver') {
                $fields[$idx]['locked'] = false;
                $unlockedCount++;
                continue;
            }

            // 8. Execution fill columns (Observation / Result, Instrument ID, Serial No, Calibration dates, Deviations, etc.):
            // MUST BE UNLOCKED so users can enter test execution data!
            if ($this->isExecutionFillColumn($label)) {
                $fields[$idx]['locked'] = false;
                $unlockedCount++;
                continue;
            }

            // 9. 2-column table keys (Col 0) MUST BE LOCKED: Tag. No., Plant / Module, MOC, etc.
            if ($type === 'table_2col_label' || str_ends_with($location, '_col_0')) {
                $fields[$idx]['locked'] = true;
                $lockedCount++;
                continue;
            }

            // 10. 2-column table fill values (Col 1) MUST BE UNLOCKED: only 2-col values, not multicol tables!
            if ($type !== 'approver' && ($type === 'table_2col' || ($type !== 'table_multicol' && (str_ends_with($location, '_col_1') || preg_match('/^table_\d+_row_\d+$/', $location))))) {
                $fields[$idx]['locked'] = false;
                $unlockedCount++;
                continue;
            }
        }

        Log::info('Requalification/Revalidation post-pre-approval lock rules applied', [
            'locked_fields'   => $lockedCount,
            'unlocked_fields' => $unlockedCount,
            'total_fields'    => count($fields),
        ]);

        return $this->lockQualificationHistoryTemplateCells($fields);
    }

    /**
     * NEQ-only fill rules: unlock empty cells that shared parse locked incorrectly.
     * Does not run for revalidation / requalification.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function applyNeqFillFieldRules(array $fields): array
    {
        $unlocked = 0;

        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }

            $type = strtolower(trim((string) ($field['type'] ?? '')));
            $widget = strtolower(trim((string) ($field['widget'] ?? '')));
            // Keep TOC / 2-col labels locked.
            if (in_array($type, ['toc_entry', 'table_2col_label'], true)) {
                $fields[$idx]['locked'] = true;
                continue;
            }

            // Att07 / Att3: "Verified by sign and date :" lines stay fillable for e-sign.
            $labelRaw = trim((string) ($field['label'] ?? ''));
            $valueRaw = trim((string) ($field['value'] ?? ''));
            if (
                preg_match('/^verified\s+by\s+sign\s+and\s+date\b/i', $labelRaw) === 1
                || preg_match('/^verified\s+by\s+sign\s+and\s+date\b/i', $valueRaw) === 1
            ) {
                $fields[$idx]['locked'] = false;
                $fields[$idx]['draft_editable'] = true;
                $fields[$idx]['stamp_eligible'] = true;
                if (in_array($type, ['paragraph_content', 'paragraph_blank', 'text', ''], true)) {
                    $fields[$idx]['type'] = 'table_multicol';
                    $fields[$idx]['widget'] = 'text';
                }
                $unlocked++;
                continue;
            }

            // Att10: "Safety Checks By (Sign./Date)" / "Safety By (Sign./Date)" stay fillable for e-sign.
            if (
                preg_match('/^safety\s*(?:checks?\s*)?s?\s*by\b/i', $labelRaw) === 1
                || preg_match('/^safety\s*(?:checks?\s*)?s?\s*by\b/i', $valueRaw) === 1
            ) {
                $fields[$idx]['locked'] = false;
                $fields[$idx]['draft_editable'] = true;
                $fields[$idx]['stamp_eligible'] = true;
                $fields[$idx]['type'] = 'table_multicol';
                $fields[$idx]['widget'] = 'text';
                if ($labelRaw === '' || preg_match('/^safety\s*(?:checks?\s*)?s?\s*by\b/i', $labelRaw) !== 1) {
                    $fields[$idx]['label'] = 'Safety Checks By (Sign./Date) :';
                } elseif (! str_ends_with(rtrim($labelRaw), ':')) {
                    $fields[$idx]['label'] = rtrim($labelRaw) . ' :';
                }
                $unlocked++;
                continue;
            }

            // Empty Sr. No. / Sl. No. data cells (Att11) stay editable so users can type 1, 2, 3…
            // Pre-numbered Word serials (Att14 "1.") and Att07 auto_serials remain locked display text.
            $serialVal = trim((string) ($field['value'] ?? ''));
            $hasAutoSerial = array_key_exists('auto_serial', $field)
                && $field['auto_serial'] !== null
                && $field['auto_serial'] !== '';
            $isSrNoDataCell = preg_match('/^table_\d+_row_[1-9]\d*_col_0$/', (string) ($field['location'] ?? '')) === 1
                && (
                    $type === 'table_sl_no'
                    || preg_match('/^(sr\.?\s*no\.?|s\.?\s*no\.?|sl\.?\s*no\.?|#)$/i', (string) ($field['label'] ?? '')) === 1
                );
            if ($isSrNoDataCell && $serialVal === '' && ! $hasAutoSerial) {
                $fields[$idx]['type'] = 'table_multicol';
                $fields[$idx]['widget'] = 'text';
                $fields[$idx]['locked'] = false;
                $fields[$idx]['draft_editable'] = true;
                $unlocked++;
                continue;
            }
            if ($type === 'table_sl_no' || ($isSrNoDataCell && $hasAutoSerial)) {
                $fields[$idx]['type'] = 'table_sl_no';
                $fields[$idx]['locked'] = true;
                if ($serialVal === '' && $hasAutoSerial) {
                    $fields[$idx]['value'] = ((int) $field['auto_serial']) . '.';
                }
                continue;
            }

            // Name dropdowns on the approval page must stay selectable until submit.
            if ($type === 'approver') {
                $fields[$idx]['locked'] = false;
                $fields[$idx]['draft_editable'] = true;
                $unlocked++;
                continue;
            }

            // Att01 "Training imparted: Yes/No" (and other radios/checkboxes) stay fillable.
            if (in_array($type, ['paragraph_radio', 'paragraph_checkbox', 'table_radio', 'table_checkbox'], true)
                || in_array($widget, ['radio', 'checkbox'], true)
            ) {
                $fields[$idx]['locked'] = false;
                $fields[$idx]['draft_editable'] = true;
                $fields[$idx]['prefilled'] = false;
                $unlocked++;
                continue;
            }

            // Personnel / calibration date pickers stay editable (never treat as Word-prefilled).
            if ($this->isDocxDateTimeEntryField($field)) {
                $fields[$idx]['locked'] = false;
                $fields[$idx]['draft_editable'] = true;
                $fields[$idx]['prefilled'] = false;
                $fields[$idx]['widget'] = 'datetime-local';
                $unlocked++;
                continue;
            }

            // Signature / Date stamps fill only after the selected user approves.
            if (str_starts_with($type, 'approval_stamp')) {
                $fields[$idx]['locked'] = true;
                continue;
            }

            $label = strtolower(trim((string) ($field['label'] ?? '')));
            $location = strtolower(trim((string) ($field['location'] ?? '')));
            $isBlankPart = $type === 'paragraph_blank_part'
                || preg_match('/_blank_\d+$/', $location) === 1
                || preg_match('/\(\s*blank\s*\d+\s*\)\s*$/i', (string) ($field['label'] ?? '')) === 1;
            $isRevisionNo = str_contains($label, 'revision no')
                || str_contains($label, 'rev. no')
                || str_contains($label, 'rev no');

            // Paragraph blank parts (machine name / I.D. under Responsibility, etc.) stay fillable.
            if ($isBlankPart) {
                $fields[$idx]['type'] = 'paragraph_blank_part';
                $fields[$idx]['widget'] = $widget !== '' ? $widget : 'text';
                $fields[$idx]['locked'] = false;
                $fields[$idx]['draft_editable'] = true;
                unset($fields[$idx]['prefilled']);
                $unlocked++;
                continue;
            }

            // Revision No. from DOCX must remain visible + editable at fill time.
            if ($isRevisionNo) {
                $fields[$idx]['locked'] = false;
                $fields[$idx]['draft_editable'] = true;
                unset($fields[$idx]['prefilled']);
                if (in_array($type, ['', 'locked', 'section_heading', 'paragraph_content'], true)) {
                    $fields[$idx]['type'] = 'table_multicol';
                }
                if ($widget === '') {
                    $fields[$idx]['widget'] = 'text';
                }
                $unlocked++;
                continue;
            }

            // "APPROVAL PAGE:" / "Report Approval" headings are never fill targets.
            if (
                $this->isLockedSectionHeadingText($label)
                || str_contains($label, 'approval page')
                || str_contains($label, 'report approval')
            ) {
                $fields[$idx]['locked'] = true;
                if (in_array($type, ['paragraph_blank', 'text'], true)) {
                    $fields[$idx]['type'] = 'paragraph_content';
                }
                continue;
            }

            if ($this->isPostApprovalStampField($field)) {
                continue;
            }
            $isLetterhead = $type === 'header_cell'
                || $type === 'protocol_cover'
                || str_starts_with($location, 'header_')
                || ($field['protocol_cover'] ?? false) === true
                || ($field['cover_field'] ?? '') !== ''
                || in_array($label, [
                    'document_number',
                    'document_title',
                    'equipment_name',
                    'equipment_id',
                    'facility_section',
                    'reference_document_no',
                    'reference_protocol_no',
                    'version',
                    'document no.',
                    'document no',
                ], true)
                || preg_match(
                    '/^(reference\s*document\s*no|document\s*(title|no\.?)|equipment\s*\/?\s*system\s*(name|id)|facility\s*\/?\s*section|version)/i',
                    $label
                ) === 1;
            // Only real comment/remarks/observation labels unlock — not every
            // paragraph_content that happens to use widget=textarea.
            $isComments = (bool) preg_match(
                '/\b(comments?|remarks?|observation|description)\b/i',
                $label
            );
            $isSeverityS = $label === 's' || $label === 'severity';

            $existingValue = trim((string) ($field['value'] ?? ''));

            // Letterhead / protocol cover cells stay fillable even when Word-prefilled
            // (Att6 FACILITY, EQUIPMENT, REF, DOCUMENT No., VERSION).
            if ($isLetterhead) {
                $fields[$idx]['locked'] = false;
                $fields[$idx]['draft_editable'] = true;
                $unlocked++;
                continue;
            }

            // Att01 Department/Signature (and other draft-editable cells) stay fillable
            // even when they already have a value from Name select / prior draft.
            $keepEditable = ! empty($field['auto_from_name'])
                || ($field['draft_editable'] ?? false) === true
                || ($field['user_filled'] ?? false) === true;

            // Data-row fill cells (URS/DQ/IOQ/Remark, observations, etc.) must stay
            // editable during NEQ draft even after Save Draft / refresh. An older rule
            // wrongly tagged typed values as Word-prefilled and locked them forever.
            $isDataRowFillCell = preg_match('/^table_\d+_row_[1-9]\d*_col_/', $location) === 1
                && in_array($type, ['table_multicol', 'table_2col', 'text', 'textarea'], true)
                && ! $this->isTemplateContentHeader($label);

            if ($isDataRowFillCell || $keepEditable || $isComments) {
                // Re-open cells that were incorrectly locked after a prior refresh.
                $fields[$idx]['prefilled'] = false;
                $fields[$idx]['locked'] = false;
                $fields[$idx]['draft_editable'] = true;
                if ($existingValue !== '') {
                    $fields[$idx]['user_filled'] = true;
                }
                $unlocked++;
                continue;
            }

            // True Word/VIVA content from import stays locked.
            if (($field['prefilled'] ?? false) === true) {
                $fields[$idx]['locked'] = true;
                continue;
            }

            // Parser-locked template columns (Checks / Methodology / Acceptance) stay locked.
            if (
                ($field['locked'] ?? false) === true
                && $existingValue !== ''
                && ($field['user_filled'] ?? false) !== true
                && $this->isTemplateContentHeader($label)
            ) {
                $fields[$idx]['prefilled'] = true;
                continue;
            }

            if ($this->looksLikeNeqStructuralLabelField($field) && ! $isComments && ! $isLetterhead) {
                $fields[$idx]['locked'] = true;
                continue;
            }

            if (($field['stamp_eligible'] ?? true) === false && ! $isComments && ! $isLetterhead) {
                continue;
            }

            if ($type === 'table_1col' && ! $isComments) {
                continue;
            }

            // Fill-target types (and draft-marked cells) stay editable until
            // Submit for Approval — even after Save Draft + refresh.
            $isFillTarget = $isLetterhead
                || $isComments
                || $isSeverityS
                || $isRevisionNo
                || ($field['user_filled'] ?? false) === true
                || ($field['draft_editable'] ?? false) === true
                || in_array($type, [
                    'table_multicol',
                    'table_2col',
                    'paragraph_blank',
                    'paragraph_blank_part',
                    'header_cell',
                    'text',
                    'textarea',
                    'attachment',
                ], true)
                || in_array($widget, ['text', 'textarea', 'datetime-local', 'date', 'file', 'select'], true)
                || trim((string) ($field['value'] ?? '')) === '';

            // Long locked body paragraphs from the DOCX stay locked.
            if ($type === 'paragraph_content' && ($field['locked'] ?? false) === true
                && ($field['user_filled'] ?? false) !== true
                && ! $isComments
            ) {
                continue;
            }

            if (! $isFillTarget) {
                continue;
            }

            $fields[$idx]['locked'] = false;
            $fields[$idx]['draft_editable'] = true;
            $unlocked++;
        }

        if ($unlocked > 0) {
            Log::info('NEQ fill field rules applied', [
                'unlocked_draft_fields' => $unlocked,
                'total_fields' => count($fields),
            ]);
        }

        return $fields;
    }

    /**
     * True when an empty NEQ field looks like a fixed label/header, not a fill target.
     */
    private function looksLikeNeqStructuralLabelField(array $field): bool
    {
        $label = trim((string) ($field['label'] ?? ''));
        $location = strtolower(trim((string) ($field['location'] ?? '')));

        if ($label === '') {
            return false;
        }

        // Header-ish labels stay locked, but empty Sr. No. *data* cells are fill targets (Att11).
        if (preg_match('/^(page|section)$/i', $label)) {
            return true;
        }
        // Att07 / Att3 e-sign footers must stay fillable.
        if (preg_match('/^verified\s+by\s+sign\s+and\s+date\b/i', $label) === 1) {
            return false;
        }
        if (
            preg_match('/^(sr\.?\s*no\.?|s\.?\s*no\.?|sl\.?\s*no\.?|#)$/i', $label)
            && ! preg_match('/^table_\d+_row_[1-9]\d*_col_0$/', $location)
        ) {
            return true;
        }

        if (preg_match('/:\s*$/', $label) && mb_strlen($label) <= 40) {
            return true;
        }

        // First-column serial-looking locations in header-ish tables stay locked when empty.
        if (preg_match('/^table_\d+_row_\d+_col_0$/', $location)
            && preg_match('/^\d+[\.\)]?$/', $label)
        ) {
            return true;
        }

        return false;
    }

    /**
     * Lock every field that already has content from the source DOCX at import time.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function lockPrefilledImportFields(array $fields): array
    {
        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }

            if (($field['prefilled'] ?? false) === true) {
                $fields[$idx]['locked'] = true;
                continue;
            }

            if (($field['stamp_eligible'] ?? true) === false) {
                $fields[$idx]['locked'] = true;
                continue;
            }

            $value = trim((string) ($field['value'] ?? ''));
            if ($value === '') {
                continue;
            }

            if ($this->isDocxFieldLocked($field)) {
                continue;
            }

            if ($this->isPostApprovalStampField($field)) {
                continue;
            }

            $fields[$idx]['locked'] = true;
        }

        return $fields;
    }

    /**
     * Log which fields were locked vs editable immediately after DOCX parse.
     *
     * @param  array<int, array<string, mixed>>  $fields
     */
    // Log counts of prefilled, locked, and editable fields right after /parse.
    private function logImportFieldLockSummary(array $fields, ?int $reportId = null): void
    {
        $locked = [];
        $editable = [];
        $prefilled = [];

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $loc = (string) ($field['location'] ?? '');
            $val = mb_substr((string) ($field['value'] ?? ''), 0, 80);
            $entry = ['location' => $loc, 'value' => $val, 'type' => $field['type'] ?? ''];

            if ($this->isPrefilledImportField($field)) {
                $prefilled[] = $entry;
            } elseif ($this->isDocxFieldLocked($field)) {
                $locked[] = $entry;
            } else {
                $editable[] = $entry;
            }
        }

        Log::info('DOCX import field lock summary', [
            'report_id'        => $reportId,
            'prefilled_count'  => count($prefilled),
            'locked_count'     => count($locked),
            'editable_count'   => count($editable),
            'prefilled_sample' => array_slice($prefilled, 0, 8),
            'editable_sample'  => array_slice($editable, 0, 8),
        ]);
    }

    // ── logDocxPreviewWidgetDebug() ──────────────────────
    // Logs widget assignments for EFFECTIVE DATE, Comments, and checkbox fields.
    private function logDocxPreviewWidgetDebug(array $debugWidgets): void
    {
        if ($debugWidgets === []) {
            return;
        }

        Log::info('DOCX preview widget debug', ['fields' => $debugWidgets]);

        foreach ($debugWidgets as $row) {
            Log::info('DOCX widget assignment', [
                'location' => $row['location'] ?? '',
                'widget'   => $row['widget'] ?? '',
                'type'     => $row['type'] ?? '',
                'locked'   => $row['locked'] ?? null,
                'label'    => mb_substr((string) ($row['label'] ?? ''), 0, 80),
            ]);
        }
    }

    // ── DOCX template storage / recovery ─────────────────
    private function getDocxTemplateAbsolutePath(string $fileId): ?string
    {
        foreach (['.docx', '.doc'] as $ext) {
            $path = storage_path('app/public/templates/docx/' . $fileId . $ext);
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private function publicStorageAbsolutePath(?string $relative): ?string
    {
        if ($relative === null || trim($relative) === '') {
            return null;
        }

        $relative = trim(str_replace('\\', '/', $relative));

        if (preg_match('#^[a-zA-Z]:/#', $relative) || str_starts_with($relative, '/')) {
            return is_file($relative) ? $relative : null;
        }

        foreach (['storage/app/public/', 'app/public/', 'public/', 'storage/'] as $prefix) {
            $normalized = ltrim($prefix, '/');
            if (str_starts_with($relative, $normalized)) {
                $relative = substr($relative, strlen($normalized));
                break;
            }
        }

        $absolute = storage_path('app/public/' . ltrim($relative, '/'));

        return is_file($absolute) ? $absolute : null;
    }

    private function findDocxRecoverySourcePath(Report $report, string $fileId): ?string
    {
        $candidates = [
            storage_path('app/public/generated_reports/' . $fileId . '_filled.docx'),
            storage_path('app/public/templates/docx/' . $fileId . '.docx'),
        ];

        $filledRelative = $report->docx_filled_path ?? null;
        if ($filledRelative) {
            $candidates[] = $this->publicStorageAbsolutePath($filledRelative);
        }

        $attachments = $report->attachments ?? [];
        if (is_string($attachments)) {
            $attachments = json_decode($attachments, true) ?? [];
        }
        if (! is_array($attachments)) {
            $attachments = [];
        }

        foreach ($attachments as $attachment) {
            if (! is_array($attachment)) {
                continue;
            }

            $index = (int) ($attachment['index'] ?? 0);
            if (! in_array($index, [99, 100], true)) {
                continue;
            }

            foreach (['file_path', 'path', 'stored_path'] as $key) {
                $candidates[] = $this->publicStorageAbsolutePath($attachment[$key] ?? null);
            }

            $storedName = $attachment['stored_name']
                ?? $attachment['original_name']
                ?? $attachment['file_name']
                ?? null;
            if (is_string($storedName) && $storedName !== '') {
                foreach (
                    [
                        storage_path('app/public/generated_reports'),
                        storage_path('app/public/reports/generated'),
                        storage_path('app/public/reports/attachments'),
                        storage_path('app/public/reports/docx_attachments'),
                        storage_path('app/public/attachments'),
                    ] as $dir
                ) {
                    $candidates[] = $dir . DIRECTORY_SEPARATOR . $storedName;
                }
            }
        }

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && is_file($candidate)) {
                return $candidate;
            }
        }

        $searchDirs = [
            storage_path('app/public/generated_reports'),
            storage_path('app/public/reports/generated'),
            storage_path('app/public/reports/attachments'),
            storage_path('app/public/reports/docx_attachments'),
            storage_path('app/public/attachments'),
            storage_path('app/public/new_equipment_qualifications'),
        ];

        foreach ($searchDirs as $dir) {
            if (! is_dir($dir)) {
                continue;
            }

            foreach (
                [
                    $dir . DIRECTORY_SEPARATOR . $fileId . '_filled.docx',
                    $dir . DIRECTORY_SEPARATOR . $fileId . '.docx',
                ] as $path
            ) {
                if (is_file($path)) {
                    return $path;
                }
            }

            $matches = glob($dir . DIRECTORY_SEPARATOR . '*' . $fileId . '*.docx') ?: [];
            if ($matches !== []) {
                return $matches[0];
            }
        }

        $publicRoot = storage_path('app/public');
        if (is_dir($publicRoot)) {
            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($publicRoot, \FilesystemIterator::SKIP_DOTS)
                );
                foreach ($iterator as $file) {
                    if (! $file->isFile()) {
                        continue;
                    }
                    if (strtolower($file->getExtension()) !== 'docx') {
                        continue;
                    }
                    if (str_contains($file->getFilename(), $fileId)) {
                        return $file->getPathname();
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('DOCX recovery recursive scan failed: ' . $e->getMessage());
            }
        }

        return null;
    }

    private function ensureDocxTemplateOnDisk(Report $report): bool
    {
        $fileId = trim((string) ($report->docx_import_path ?? ''));
        if ($fileId === '') {
            return false;
        }

        if ($this->getDocxTemplateAbsolutePath($fileId) !== null) {
            return true;
        }

        $source = $this->findDocxRecoverySourcePath($report, $fileId);
        if ($source === null) {
            Log::warning('DOCX template missing and no recovery source found', [
                'report_id' => $report->id,
                'file_id'   => $fileId,
            ]);

            return false;
        }

        $destDir = storage_path('app/public/templates/docx');
        if (! is_dir($destDir)) {
            @mkdir($destDir, 0775, true);
        }

        $dest = $destDir . DIRECTORY_SEPARATOR . $fileId . '.docx';
        if (@copy($source, $dest) && is_file($dest)) {
            Log::info('DOCX template restored from recovery source', [
                'report_id' => $report->id,
                'file_id'   => $fileId,
                'source'    => $source,
                'dest'      => $dest,
            ]);

            return true;
        }

        Log::error('DOCX template recovery copy failed', [
            'report_id' => $report->id,
            'file_id'   => $fileId,
            'source'    => $source,
            'dest'      => $dest,
        ]);

        return false;
    }

    private function attemptAutoRestoreDocxTemplate(Report $report): bool
    {
        if ($this->ensureDocxTemplateOnDisk($report)) {
            return true;
        }

        $fileId = trim((string) ($report->docx_import_path ?? ''));
        if ($fileId === '') {
            return false;
        }

        $this->flushDocxPreviewCache($fileId, $report->id);

        return $this->ensureDocxTemplateOnDisk($report);
    }

    private function reparseDocxForReport(Report $report): ?array
    {
        $fileId = trim((string) ($report->docx_import_path ?? ''));
        if ($fileId === '') {
            return null;
        }

        $this->ensureDocxTemplateOnDisk($report);
        $filePath = $this->getDocxTemplateAbsolutePath($fileId);
        if ($filePath === null || ! is_file($filePath)) {
            return null;
        }

        $parsedFields = null;
        $pythonUrl = env('PYTHON_NLP_URL', 'http://127.0.0.1:8001');

        try {
            $response = Http::timeout(30)
                ->attach('file', file_get_contents($filePath), basename($filePath))
                ->post("{$pythonUrl}/parse");

            if ($response->successful() && is_array($response->json())) {
                $result = $response->json();
                $parsedFields = $result['fields'] ?? [];
                if (! empty($result['file_id'])) {
                    $newDocxPath = storage_path('app/public/templates/docx/' . $result['file_id'] . '.docx');
                    if (is_file($newDocxPath) && $newDocxPath !== $filePath) {
                        @copy($newDocxPath, $filePath);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::info('HTTP reparse unavailable, attempting CLI parse: ' . $e->getMessage());
        }

        // Direct Python CLI fallback if HTTP server is unavailable
        if (empty($parsedFields)) {
            try {
                $pythonBin = base_path('python-service/venv/Scripts/python.exe');
                if (! is_file($pythonBin)) {
                    $pythonBin = 'python';
                }
                $cliScript = base_path('python-service/cli.py');
                $process = new Process([$pythonBin, $cliScript, 'parse', $filePath]);
                $process->setTimeout(60);
                $process->run();
                $output = $process->getOutput();
                if ($output) {
                    $jsonStart = strpos($output, '{"status"');
                    if ($jsonStart !== false) {
                        $decoded = json_decode(substr($output, $jsonStart), true);
                        if (is_array($decoded) && ($decoded['status'] ?? '') === 'success') {
                            $parsedFields = $decoded['fields'] ?? [];
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('CLI reparse failed: ' . $e->getMessage());
            }
        }

        if (! empty($parsedFields)) {
            $newFields = $this->normalizeImportFields($parsedFields);
            $existing = is_array($report->docx_import_fields) ? $report->docx_import_fields : [];
            $existingByLoc = [];
            $existingByRole = [];
            $oldHadAttachmentTable = false;
            foreach ($existing as $ef) {
                if (is_array($ef) && ! empty($ef['location'])) {
                    $existingByLoc[$ef['location']] = $ef;
                    if (($ef['type'] ?? '') === 'attachment' || ($ef['widget'] ?? '') === 'file') {
                        $oldHadAttachmentTable = true;
                    }
                    if (! empty($ef['approver_role'])) {
                        $existingByRole[$ef['approver_role']] = $ef;
                    }
                }
            }

            // Detect if an attachment table was newly inserted (e.g. at table_16)
            $attTableIdx = null;
            if (! $oldHadAttachmentTable) {
                foreach ($newFields as $nf) {
                    if (($nf['type'] ?? '') === 'attachment' || ($nf['widget'] ?? '') === 'file') {
                        if (preg_match('/^table_(\d+)_/', $nf['location'] ?? '', $m)) {
                            $attTableIdx = (int) $m[1];
                            break;
                        }
                    }
                }
            }

            foreach ($newFields as $idx => $nf) {
                $loc = $nf['location'] ?? '';

                // If this cell is in the newly inserted attachment table, keep parser values!
                if ($attTableIdx !== null && preg_match('/^table_' . $attTableIdx . '_/', $loc)) {
                    continue;
                }

                // Determine previous location if tables shifted
                $prevLoc = $loc;
                if ($attTableIdx !== null && preg_match('/^table_(\d+)_(.+)$/', $loc, $matches)) {
                    $tIdx = (int) $matches[1];
                    $rest = $matches[2];
                    if ($tIdx > $attTableIdx) {
                        $prevLoc = 'table_' . ($tIdx - 1) . '_' . $rest;
                    }
                }

                if (isset($existingByLoc[$prevLoc])) {
                    $prev = $existingByLoc[$prevLoc];
                    $lblNf = strtolower(trim((string) ($nf['label'] ?? '')));
                    $lblPrev = strtolower(trim((string) ($prev['label'] ?? '')));
                    $isCompatible = true;
                    if (str_contains($lblNf, 'attachment') !== str_contains($lblPrev, 'attachment')) {
                        $isCompatible = false;
                    }
                    if (str_contains($lblNf, 'revision') !== str_contains($lblPrev, 'revision')) {
                        $isCompatible = false;
                    }
                    if (($nf['type'] ?? '') === 'attachment' && ($prev['type'] ?? '') !== 'attachment') {
                        $isCompatible = false;
                    }

                    if ($isCompatible) {
                        if (($prev['value'] ?? '') !== '') {
                            $newFields[$idx]['value'] = $prev['value'];
                        }
                        if (isset($prev['approver_role'])) {
                            $newFields[$idx]['approver_role'] = $prev['approver_role'];
                        }
                        if (isset($prev['user_id'])) {
                            $newFields[$idx]['user_id'] = $prev['user_id'];
                        }
                        if (isset($prev['is_pre_approver'])) {
                            $newFields[$idx]['is_pre_approver'] = $prev['is_pre_approver'];
                        }
                    }
                }

                // Also map by approver role if present
                $role = $nf['approver_role'] ?? '';
                if ($role !== '' && isset($existingByRole[$role])) {
                    $prev = $existingByRole[$role];
                    if (($prev['value'] ?? '') !== '') {
                        $newFields[$idx]['value'] = $prev['value'];
                    }
                    if (isset($prev['user_id'])) {
                        $newFields[$idx]['user_id'] = $prev['user_id'];
                    }
                    if (isset($prev['is_pre_approver'])) {
                        $newFields[$idx]['is_pre_approver'] = $prev['is_pre_approver'];
                    }
                }
            }

            // Keep draft-only Add Row / typed locations that are not present in the Word reparse.
            $newFields = $this->mergeDraftOnlyFieldsAfterReparse($existing, $newFields);

            return $this->applySourceSpecificImportFieldRules($newFields, (string) $report->import_source);
        }

        return null;
    }

    /**
     * After a Word reparse, restore draft-only field locations (Add Row cells, etc.)
     * that do not exist in the template yet but were saved during pre-approval drafting.
     *
     * @param  list<array<string, mixed>>  $existing
     * @param  list<array<string, mixed>>  $reparsed
     * @return list<array<string, mixed>>
     */
    private function mergeDraftOnlyFieldsAfterReparse(array $existing, array $reparsed): array
    {
        $reparsedByLoc = [];
        foreach ($reparsed as $field) {
            if (! is_array($field)) {
                continue;
            }
            $loc = trim((string) ($field['location'] ?? ''));
            if ($loc !== '') {
                $reparsedByLoc[$loc] = true;
            }
        }

        foreach ($existing as $field) {
            if (! is_array($field)) {
                continue;
            }
            $loc = trim((string) ($field['location'] ?? ''));
            if ($loc === '' || isset($reparsedByLoc[$loc])) {
                continue;
            }
            if (! preg_match('/^(para_|table_|header_|txbx_|toc_)/', $loc)) {
                continue;
            }

            $isDraftOwned = ($field['user_filled'] ?? false) === true
                || ($field['draft_editable'] ?? false) === true
                || trim((string) ($field['value'] ?? '')) !== '';
            if (! $isDraftOwned) {
                continue;
            }

            $kept = $field;
            $kept['locked'] = false;
            $kept['user_filled'] = true;
            $kept['draft_editable'] = true;
            $kept['prefilled'] = false;
            $reparsed[] = $kept;
            $reparsedByLoc[$loc] = true;
        }

        return array_values($reparsed);
    }

    private function mirrorDocxTemplateAfterParse(string $fileId, string $fileContents, string $extension = 'docx'): void
    {
        $ext = strtolower($extension) === 'doc' ? '.doc' : '.docx';
        $destDir = storage_path('app/public/templates/docx');
        if (! is_dir($destDir)) {
            @mkdir($destDir, 0775, true);
        }

        $dest = $destDir . DIRECTORY_SEPARATOR . $fileId . $ext;
        if (is_file($dest)) {
            return;
        }

        if (@file_put_contents($dest, $fileContents) !== false) {
            Log::info('DOCX template mirrored to Laravel storage', [
                'file_id' => $fileId,
                'path'    => $dest,
            ]);
        }
    }

    private function mirrorFilledDocxAsTemplate(string $fileId, string $filledAbsolutePath): void
    {
        // Keep the original parsed template intact. Approval regeneration must always
        // start from the clean import DOCX and re-apply all stored field values
        // (including stamps). Overwriting the template with a filled DOCX caused
        // mid-table header rows and merged cells to accumulate wrong values.
        if ($fileId === '' || ! is_file($filledAbsolutePath)) {
            return;
        }

        Log::debug('DOCX skip template overwrite from filled output', [
            'file_id' => $fileId,
            'source'  => $filledAbsolutePath,
        ]);
    }

    private function docxPreviewCacheVersion(string $fileId, ?int $reportId): int
    {
        $key = 'docx_preview_ver:' . $fileId . ':' . ($reportId ?? 0);

        return (int) Cache::get($key, 0);
    }

    private function flushDocxPreviewCache(string $fileId, ?int $reportId = null): void
    {
        $key = 'docx_preview_ver:' . $fileId . ':' . ($reportId ?? 0);
        Cache::put($key, $this->docxPreviewCacheVersion($fileId, $reportId) + 1, now()->addDays(30));
    }

    private function previewHtmlHasInteractiveFields(string $html): bool
    {
        if (strlen(trim($html)) === 0) {
            return false;
        }

        return str_contains($html, '<input')
            || str_contains($html, '<textarea')
            || str_contains($html, '<select');
    }

    // ── requestPreviewHtml() ─────────────────────────────
    // Fetches DOCX table-layout preview HTML from Python /preview (with caching).
    private function requestPreviewHtml(
        string $fileId,
        string $pythonUrl,
        array $fields = [],
        ?int $reportId = null,
        string $mode = 'edit'
    ): string {
        @set_time_limit(300);
        $importSource = '';
        if ($reportId !== null) {
            $reportForSource = Report::find($reportId);
            if ($reportForSource) {
                $importSource = (string) ($reportForSource->import_source ?? '');
            }
        }
        $fieldsHash = md5(json_encode(array_values($fields), JSON_UNESCAPED_UNICODE));
        $cacheVer   = $this->docxPreviewCacheVersion($fileId, $reportId);
        $sourceKey  = $importSource !== '' ? $importSource : 'legacy';
        // v115: body TOC1 (PR-WBL-046) dotted leaders between title and page.
        $cacheKey   = $reportId
            ? "docx_preview:v115:{$mode}:{$sourceKey}:{$fileId}:{$reportId}:{$cacheVer}:{$fieldsHash}"
            : "docx_preview:v115:{$mode}:{$sourceKey}:{$fileId}:{$reportId}:{$cacheVer}:{$fieldsHash}";
        $cached     = Cache::get($cacheKey);

        // Return cached HTML when fields and mode have not changed since last preview.
        if (is_string($cached) && strlen(trim($cached)) > 0) {
            return $cached;
        }

        if ($reportId !== null) {
            $report = Report::find($reportId);
            if ($report) {
                $this->ensureDocxTemplateOnDisk($report);
                if ($importSource === '') {
                    $importSource = (string) ($report->import_source ?? '');
                }
            }
        }

        $templatePath = storage_path('app/public/templates/docx/' . $fileId . '.docx');
        Log::info('DOCX template file check', [
            'path'   => $templatePath,
            'exists' => is_file($templatePath),
        ]);

        try {
            $payload = [
                'file_id' => $fileId,
                'mode'    => $mode,
            ];
            if ($importSource !== '') {
                $payload['import_source'] = $importSource;
            }
            if (!empty($fields)) {
                $payload['fields_json'] = json_encode(array_values($fields));
            }

            // POST to Python /preview to render table-layout HTML for fill or readonly mode.
            $response = Http::timeout(90)
                ->asForm()
                ->post("{$pythonUrl}/preview", $payload);

            Log::info('DOCX preview response', [
                'status'       => $response->status(),
                'body_snippet' => mb_substr($response->body(), 0, 500),
            ]);

            if ($response->successful()) {
                $payload = $response->json();
                $html    = $payload['html'] ?? '';
                $this->logDocxPreviewWidgetDebug($payload['debug_widgets'] ?? []);
                if (strlen(trim($html)) > 0) {
                    Cache::put($cacheKey, $html, now()->addDays(7));
                }
                return $html;
            }

            // Non-success falls through to flat form without raising to the user.
            Log::warning('DOCX preview HTTP error, using flat form', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('DOCX preview exception, using flat form: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
        }

        // Direct Python CLI fallback if HTTP preview service is down/offline
        if (is_file($templatePath)) {
            try {
                $pythonBin = base_path('python-service/venv/Scripts/python.exe');
                if (! is_file($pythonBin)) {
                    $pythonBin = 'python';
                }
                $cliScript = base_path('python-service/cli.py');
                $tmpJsonFile = tempnam(sys_get_temp_dir(), 'prev_fields_');
                file_put_contents($tmpJsonFile, json_encode(array_values($fields), JSON_UNESCAPED_UNICODE));
                $process = new Process([$pythonBin, $cliScript, 'preview', $templatePath, $tmpJsonFile, $mode]);
                $process->setTimeout(60);
                $process->run();
                $htmlOutput = $process->getOutput();
                @unlink($tmpJsonFile);
                if ($htmlOutput && strlen(trim($htmlOutput)) > 0) {
                    $htmlStart = strpos($htmlOutput, '<div class="docx-preview"');
                    if ($htmlStart !== false) {
                        $htmlOutput = substr($htmlOutput, $htmlStart);
                    }
                    Cache::put($cacheKey, $htmlOutput, now()->addDays(7));
                    return $htmlOutput;
                }
            } catch (\Throwable $ex) {
                Log::warning('DOCX preview CLI fallback failed: ' . $ex->getMessage());
            }
        }

        return is_string($cached) ? $cached : '';
    }

    // ── applyReportPrefills() ────────────────────────────
    // Pre-fills unlocked DOCX import fields from report equipment/document metadata.
    // Parameters: Report $report (equipment_name, equipment_id, facility_section),
    //              array $fields — docx_import_fields list.
    // Returns: Updated fields array with value set on matching unlocked locations/labels.
    // Key steps:
    // 1. Maps known table locations (table_0_row_0/1/2) and label text to report columns
    // 2. Skips locked fields so pre-approved DOCX values are not overwritten
    // 3. Returns the same array structure for preview and fill form rendering
    /**
     * Show the users chosen for requalification pre-approval in the first signature block.
     * Name and designation only. Later post-approval tables are left blank.
     */
    private function applyRequalificationPreApproverNames(Report $report, array $fields): array
    {
        // Requalification templates contain explicit PRE-APPROVAL SIGNATURE tables (tables 1, 2, 3).
        // Revalidation templates only contain REPORT APPROVAL tables (reserved exclusively for POST-approval).
        // Therefore pre-approver names must only be stamped for requalification protocols.
        if (($report->import_source ?? '') !== 'requalification' || $fields === []) {
            return $fields;
        }

        $data = $report->data ?? [];
        if (! is_array($data)) {
            return $fields;
        }

        // Word PRE tables are always table_1 / table_2 / table_3. Name cells may be
        // type=approver OR table_multicol ("NAME & DESIGNATION") — both must count.
        $byTable = [
            1 => [],
            2 => [],
            3 => [],
        ];
        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }
            if (! preg_match('/^table_([123])_row_(\d+)_col_(\d+)$/', (string) ($field['location'] ?? ''), $match)) {
                continue;
            }
            $tableNum = (int) $match[1];
            $rowNum = (int) $match[2];
            $colNum = (int) $match[3];
            $label = strtolower(trim((string) ($field['label'] ?? '')));
            $type = strtolower(trim((string) ($field['type'] ?? '')));

            // Signature / date columns are stamped via the name-row sibling walk.
            if (str_contains($label, 'signature') || $type === 'approval_stamp_signature' || $type === 'approval_stamp_date') {
                continue;
            }

            $isNameCell = $type === 'approver'
                || $type === 'approval_stamp_name'
                || str_contains($label, 'name');
            // Fallback: col_1 is Name & Designation on standard requal PRE tables.
            if (! $isNameCell && $colNum === 1 && ! str_contains($label, 'role')) {
                $isNameCell = true;
            }
            if (! $isNameCell) {
                continue;
            }

            $byTable[$tableNum][] = [
                'idx' => $idx,
                'row' => $rowNum,
                'col' => $colNum,
            ];
        }

        $approvedAt = $this->preApprovalDatesByRole($report, $data);
        $lockApproved = $this->requalificationPreApprovalIsComplete($report);
        $roleMap = [
            1 => ['prepared_by_engineering_name'],
            2 => [
                'checked_by_engineering_name',
                'checked_by_production_name',
                'checked_by_validation_name',
                'checked_by_quality_name',
            ],
            3 => [
                'approved_by_user_name',
                'approved_by_quality_name',
            ],
        ];

        foreach ($roleMap as $tableNum => $roles) {
            $cells = $byTable[$tableNum] ?? [];
            if ($cells === []) {
                continue;
            }
            usort($cells, static function (array $a, array $b) {
                return ($a['row'] <=> $b['row']) ?: ($a['col'] <=> $b['col']);
            });
            // One name cell per data row (ignore duplicate col hits).
            $uniqueByRow = [];
            foreach ($cells as $cell) {
                $rowKey = (int) $cell['row'];
                if (! isset($uniqueByRow[$rowKey])) {
                    $uniqueByRow[$rowKey] = $cell;
                }
            }
            $ordered = array_values($uniqueByRow);
            foreach ($roles as $offset => $role) {
                if (! isset($ordered[$offset])) {
                    break;
                }
                $this->writePreApproverRow(
                    $fields,
                    (int) $ordered[$offset]['idx'],
                    $data,
                    $role,
                    $approvedAt[$role] ?? null,
                    $lockApproved
                );
            }
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function preApprovalDatesByRole(Report $report, array $data): array
    {
        $report->loadMissing('approvalSteps');
        $labelToRole = [
            'PREPARED_ENGINEERING' => 'prepared_by_engineering_name',
            'ENGINEERING' => 'checked_by_engineering_name',
            'PRODUCTION' => 'checked_by_production_name',
            'VALIDATION' => 'checked_by_validation_name',
            'CHECKED_QA' => 'checked_by_quality_name',
            'USER/VALIDATION' => 'approved_by_user_name',
            'QUALITY ASSURANCE' => 'approved_by_quality_name',
        ];
        $allRoles = array_values(array_unique(array_values($labelToRole)));
        $dates = [];
        foreach ($report->approvalSteps as $step) {
            if ($step->stage !== 'pre' || $step->approved_at === null) {
                continue;
            }
            $role = $labelToRole[$step->label] ?? null;
            if ($role === null) {
                continue;
            }
            $prefix = preg_replace('/_name$/', '', $role) ?? $role;
            $date = $this->formatSignatureDateTimeForDocx((string) ($data[$prefix . '_date'] ?? ''));
            if ($date === '') {
                $date = $this->formatSignatureDateTimeForDocx($step->approved_at);
            }
            if ($date !== '') {
                $dates[$role] = $date;
            }
        }

        // Always fall back to report.data *_date so stamps work even if a step
        // row is missing / label mismatched after workflow changes.
        foreach ($allRoles as $role) {
            if (! empty($dates[$role])) {
                continue;
            }
            $prefix = preg_replace('/_name$/', '', $role) ?? $role;
            $date = $this->formatSignatureDateTimeForDocx((string) ($data[$prefix . '_date'] ?? ''));
            if ($date !== '') {
                $dates[$role] = $date;
            }
        }

        return $dates;
    }

    private function writePreApproverRow(array &$fields, int $idx, array $data, string $roleKey, ?string $approvedDate, bool $lockApproved = false): void
    {
        $fields[$idx]['approver_role'] = $roleKey;
        $fields[$idx]['is_pre_approver'] = true;

        if (! empty($data[$roleKey])) {
            $fields[$idx]['user_id'] = (int) $data[$roleKey];
        }

        $name = $this->preApproverNameAndDesignation($data, $roleKey);
        if ($name === '') {
            return;
        }

        // Always write Name & Designation from the selected user. Fill-form selects
        // often leave "Name (email)" in the cell — that must not block "Name - designation".
        $fields[$idx]['value'] = $name;
        // Select-label leftovers are not Word-prefilled content — allow stamp/merge updates.
        $fields[$idx]['prefilled'] = false;
        if ($lockApproved) {
            $this->lockPreApprovalCell($fields[$idx]);
        }

        $loc = (string) ($fields[$idx]['location'] ?? '');
        if (! preg_match('/^(table_\d+_row_\d+)_col_\d+$/', $loc, $match)) {
            return;
        }

        $combined = '';
        if ($approvedDate !== null && $approvedDate !== '') {
            $signature = $this->signatureLabelFromDisplayName($name);
            $combined = trim($signature . '  ' . $approvedDate);
        }

        foreach ($fields as $fieldIndex => $field) {
            if (! is_array($field)) {
                continue;
            }
            $fieldLoc = (string) ($field['location'] ?? '');
            if ($fieldLoc === $loc || ! str_starts_with($fieldLoc, $match[1] . '_col_')) {
                continue;
            }
            $label = strtolower((string) ($field['label'] ?? ''));
            if (! str_contains($label, 'signature') && ! str_contains($label, 'date')) {
                continue;
            }
            // Detach from POST blank/stamp pipeline so send-to-post cannot clear these cells.
            $fields[$fieldIndex]['approver_role'] = $roleKey;
            $fields[$fieldIndex]['is_pre_approver'] = true;
            $fields[$fieldIndex]['prefilled'] = false;

            if ($combined !== '') {
                $existing = trim((string) ($field['value'] ?? ''));
                // Replace blank/dash cells and leftover select labels so Signature & Date
                // always shows name + datetime after the step is approved.
                // When PRE is complete, always force the PRE stamp (do not keep a wrong POST stamp
                // that landed here because upgradeSignatureApproverRoles tagged the cell post_*).
                if (
                    $lockApproved
                    || $existing === ''
                    || in_array($existing, ['—', '-', '–'], true)
                    || preg_match('/^.+\([^@\)]+@[^)]+\)$/', $existing)
                ) {
                    $fields[$fieldIndex]['value'] = $combined;
                }
            }
            if ($lockApproved) {
                $this->lockPreApprovalCell($fields[$fieldIndex]);
            }
        }
    }

    private function requalificationPreApprovalIsComplete(Report $report): bool
    {
        return in_array($report->import_source, ['requalification', 'revalidation'], true)
            && ($report->stage === 'post' || in_array($report->status, ['pre_approved', 'post_pending', 'post_returned', 'approved'], true));
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function lockPreApprovalCell(array &$field): void
    {
        $field['locked'] = true;
        $field['stamp_eligible'] = false;
        $field['widget'] = 'text';
    }

    private function preApproverNameAndDesignation(array $data, string $roleKey): string
    {
        $prefix = preg_replace('/_name$/', '', $roleKey) ?? $roleKey;

        // Prefer live user record so Name & Designation always includes designation,
        // even when *_name_text was cached as "Name (email)" from a select label.
        $userId = $data[$roleKey] ?? null;
        if (is_numeric($userId)) {
            $user = User::find((int) $userId);
            if ($user) {
                return $this->formatUserOptionLabel($user);
            }
        }

        $stored = trim((string) ($data[$prefix . '_name_text'] ?? ''));
        if ($stored !== '' && ! preg_match('/^.+\([^@\)]+@[^)]+\)$/', $stored)) {
            return $stored;
        }

        return $stored;
    }

    private function applyReportPrefills(Report $report, array $fields): array
    {
        $effectiveDateForPreview = $this->resolveEffectiveDate($report, $fields);

        $labelPrefill = [
            'name of equipment'              => $report->equipment_name ?? '',
            'equipment identification no.'   => $report->equipment_id ?? '',
            'equipment location'             => $report->facility_section ?? '',
        ];

        $coverLoc = $this->buildCoverFieldLocationMap($fields);
        $locationPrefill = [
            'header_ref_protocol' => $this->resolveReferenceProtocolNo($report, $fields),
        ];
        // Bind by real cover locations — never hardcode HVAC row_4 as Document No. / Version.
        if (! empty($coverLoc['document_no'])) {
            $locationPrefill[$coverLoc['document_no']] = $this->resolveProtocolDocumentNumber($report, $fields);
        }
        if (! empty($coverLoc['reference_protocol_no'])) {
            $locationPrefill[$coverLoc['reference_protocol_no']] = $this->resolveReferenceProtocolNo($report, $fields);
        }
        if (! empty($coverLoc['version']) && trim((string) ($report->version ?? '')) !== '') {
            $ver = trim((string) $report->version);
            // Ignore broken split-run reads (".") and page chrome — keep Word "0.2".
            if (
                $ver !== '.'
                && $ver !== '0.'
                && $ver !== '.2'
                && ! preg_match('/\bpage\s+\d+\s+of\s+\d+\b/i', $ver)
            ) {
                $locationPrefill[$coverLoc['version']] = $ver;
            }
        }

        return array_map(function (array $field) use ($labelPrefill, $locationPrefill, $report, $fields, $effectiveDateForPreview) {
            $location = (string) ($field['location'] ?? '');
            // Revision-history dates are their own cells (13/01/17, 22/04/22). Do not
            // stamp the protocol effective date onto them.
            if (
                $this->isEffectiveDateLikeField($field)
                && ! $this->isRevisionHistoryEffectiveDateLocation($fields, $location)
            ) {
                $field['value'] = $effectiveDateForPreview !== '' ? $effectiveDateForPreview : '';
                $field['widget'] = 'date';
                $field['cover_field'] = $field['cover_field'] ?? 'effective_date';
            }

            // Never overwrite locked or prefilled Word values with report metadata.
            if ($this->isDocxFieldLocked($field)) {
                return $field;
            }

            // Never overwrite 2-column label cells (Col 0) with prefill values.
            if (($field['type'] ?? '') === 'table_2col_label' || str_ends_with($location, '_col_0')) {
                return $field;
            }

            $location = $field['location'] ?? '';
            $label    = strtolower(trim((string) ($field['label'] ?? '')));
            $type     = strtolower(trim((string) ($field['type'] ?? '')));
            $existing = trim((string) ($field['value'] ?? ''));
            $coverKey = (string) ($field['cover_field'] ?? '');

            // Letterhead / cover cells that already have Word text must keep it
            // (do not replace PRODUCTION / WEIGHING BALANCE / 0.2 with create-form junk).
            if (
                $existing !== ''
                && (
                    $coverKey !== ''
                    || in_array($type, ['header_cell', 'protocol_cover'], true)
                    || str_starts_with((string) $location, 'header_')
                )
            ) {
                return $field;
            }

            $prefill = $locationPrefill[$location] ?? $labelPrefill[$label] ?? null;

            if ($coverKey !== '') {
                $coverSources = [
                    'equipment_name'        => $report->equipment_name ?? '',
                    'equipment_id'          => $report->equipment_id ?? '',
                    'facility_section'      => $report->facility_section ?? '',
                    'document_no'           => $this->resolveProtocolDocumentNumber($report, $fields),
                    'document_title'        => $this->resolveDocumentTitle($report, $fields),
                    'reference_protocol_no' => $this->resolveReferenceProtocolNo($report, $fields),
                    'version'               => $report->version ?? '',
                    'effective_date'        => $this->reportHasCoverField($fields, 'effective_date')
                        ? $this->resolveEffectiveDate($report, $fields)
                        : '',
                ];
                $fromCover = $coverSources[$coverKey] ?? null;
                if (
                    $coverKey === 'effective_date'
                    && $this->isRevisionHistoryEffectiveDateLocation($fields, (string) ($field['location'] ?? ''))
                ) {
                    $fromCover = null;
                }
                // Never treat page chrome or broken "." reads as a version prefill.
                if (
                    $coverKey === 'version'
                    && is_string($fromCover)
                    && (
                        in_array(trim($fromCover), ['.', '0.', '.2'], true)
                        || preg_match('/\bpage\s+\d+\s+of\s+\d+\b/i', $fromCover)
                    )
                ) {
                    $fromCover = null;
                }
                if ($fromCover !== null && $fromCover !== '') {
                    $prefill = $fromCover;
                }
            }

            if ($prefill !== null && $prefill !== '') {
                if (
                    $coverKey === 'version'
                    && preg_match('/\bpage\s+\d+\s+of\s+\d+\b/i', (string) $prefill)
                ) {
                    return $field;
                }
                $field['value'] = $prefill;
            }

            return $field;
        }, $fields);
    }

    // True when a field label or cover_field marks it as the protocol effective date.
    private function isEffectiveDateLikeField(array $field): bool
    {
        $cover = strtolower(trim((string) ($field['cover_field'] ?? '')));
        if ($cover === 'effective_date') {
            return true;
        }

        $labelRaw = strtolower(trim((string) ($field['label'] ?? '')));
        if ($labelRaw === '') {
            return false;
        }

        // Handles label variants like "EFFECTIVE DATE/EFFECTIVE DATE".
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $labelRaw) ?? $labelRaw;
        $normalized = trim($normalized);

        return str_contains($labelRaw, 'effective date')
            || str_contains($normalized, 'effective date');
    }

    /**
     * Resolve effective date location even when parser missed cover_field metadata.
     *
     * @param  array<int, array<string, mixed>>  $fields
     */
    // Find effective-date cell location via cover_field metadata or label heuristics.
    private function findEffectiveDateFieldLocation(array $fields): ?string
    {
        $coverLoc = $this->findCoverFieldLocation($fields, 'effective_date');
        if ($coverLoc !== null) {
            return $coverLoc;
        }

        foreach ($fields as $field) {
            if (! is_array($field) || ! $this->isEffectiveDateLikeField($field)) {
                continue;
            }
            $loc = trim((string) ($field['location'] ?? ''));
            if ($loc !== '') {
                return $loc;
            }
        }

        return null;
    }

    // Central lock check: honor explicit locked flag (Sr. No. may be editable).
    private function isDocxFieldLocked(array $field): bool
    {
        $locked = $field['locked'] ?? false;

        if (is_bool($locked)) {
            return $locked;
        }

        if (is_numeric($locked)) {
            return (int) $locked === 1;
        }

        if (is_string($locked)) {
            return in_array(strtolower(trim($locked)), ['1', 'true', 'yes'], true);
        }

        return false;
    }

    // Detect table-of-contents rows that must stay locked and hidden from the flat list.
    private function isTocField(array $field): bool
    {
        $label = strtolower(trim((string) ($field['label'] ?? '')));
        $type = strtolower(trim((string) ($field['type'] ?? '')));
        $location = strtolower(trim((string) ($field['location'] ?? '')));
        $value = trim((string) ($field['value'] ?? ''));

        // Paragraph content, blanks, table data cells are NOT TOC entries.
        if (str_starts_with($location, 'toc_sdt_') || str_contains($location, '_toc_')) {
            return false;
        }
        if (in_array($type, [
            'table_multicol', 'table_sl_no', 'attachment', 'table_radio',
            'paragraph_content', 'table_2col_label', 'table_2col',
            'paragraph_blank', 'paragraph_blank_part', 'paragraph_checkbox', 'paragraph_radio'
        ], true)) {
            return false;
        }

        // Data rows in tables: table_N_row_R_col_C or table_N_row_R with R >= 1.
        if (preg_match('/^table_\d+_row_(\d+)(_col_\d+)?$/', $location, $m) && (int) $m[1] >= 1) {
            // Only treat as TOC when the value itself looks like a TOC line (dots + page number).
            return $this->isTocEntryText($value !== '' ? $value : (string) ($field['label'] ?? ''));
        }

        $tocLabels = [
            'sl. no.', 'sl no', 's.no', 's. no.',
            '1.0', '2.0', '3.0', '4.0', '5.0', '6.0', '7.0',
            'observation / results and evaluation',
            'deviation and corrective action taken (if any)',
            'report approval',
        ];

        // Label-only TOC match: only for toc_entry / table_1col
        if (in_array($label, $tocLabels, true)) {
            if ($type === 'toc_entry' || $type === 'table_1col') {
                return true;
            }
            if (preg_match('/^table_\d+_row_0(_col_\d+)?$/', $location)) {
                return true;
            }

            return false;
        }

        if ($type === 'table_1col' || $type === 'toc_entry') {
            return $this->isTocEntryText((string) ($field['label'] ?? ''));
        }

        if (preg_match('/^table_\d+_row_\d+_col_0$/', $location)) {
            return $this->isTocEntryText((string) ($field['label'] ?? ''));
        }

        return false;
    }

    // Heuristic: dotted leaders and trailing page numbers indicate a TOC line.
    private function isTocEntryText(string $text): bool
    {
        $t = trim($text);
        if ($t === '') {
            return false;
        }

        if (preg_match('/^table\s+of\s+contents$/i', $t)) {
            return true;
        }

        if (!preg_match('/\d+\.\d+\s+/', $t)) {
            return false;
        }

        $hasLeaders = str_contains($t, '…')
            || str_contains($t, "\u{2026}")
            || preg_match('/\.{4,}/', $t) === 1;

        $hasTrailingPage = preg_match('/\d+\s*$/', $t) === 1;

        return $hasTrailingPage && ($hasLeaders || preg_match('/\t/', $t) === 1);
    }

    // Set TOC fields editable so users can change section titles and page numbers.
    private function upgradeTocFieldsLocked(array $fields): array
    {
        foreach ($fields as $idx => $field) {
            if (! is_array($field) || ! $this->isTocField($field)) {
                continue;
            }

            $type = strtolower(trim((string) ($field['type'] ?? '')));
            // Never convert attachment-list serial / multicol cells into toc_entry.
            if (in_array($type, ['table_multicol', 'table_sl_no', 'attachment'], true)) {
                continue;
            }

            // Keep TOC fields unlocked so users can customize page numbers and titles
            $fields[$idx]['locked'] = false;
            $fields[$idx]['type'] = 'toc_entry';

            $existing = trim((string) ($field['value'] ?? ''));
            $label = trim((string) ($field['label'] ?? ''));
            // Keep a real cell value; only fall back to label when value is empty.
            if ($existing === '' && $label !== '') {
                $fields[$idx]['value'] = $label;
            }
        }

        return $this->lockSectionHeadingFields(
            $this->repairAttachmentListFieldsCorruptedByToc($fields)
        );
    }

    /**
     * Protocol section titles such as 1.0 OBJECTIVE: end with a colon, so they were
     * parsed as fill boxes. They stay as headings.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function lockSectionHeadingFields(array $fields): array
    {
        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }
            $type = strtolower(trim((string) ($field['type'] ?? '')));
            // Never convert fill blanks / checklist items into empty section headings.
            if (in_array($type, [
                'paragraph_blank',
                'paragraph_blank_part',
                'paragraph_checkbox',
                'paragraph_radio',
                'table_checkbox',
                'table_radio',
            ], true)) {
                continue;
            }
            $label = trim((string) ($field['label'] ?? ''));
            $location = (string) ($field['location'] ?? '');
            if (
                (str_contains($location, '_toc_') || str_starts_with($location, 'toc_sdt_'))
                && ! str_ends_with($location, '_title')
            ) {
                continue;
            }
            if (! $this->isLockedSectionHeadingText($label)) {
                continue;
            }
            $fields[$idx]['locked'] = true;
            $fields[$idx]['type'] = 'section_heading';
            $fields[$idx]['value'] = '';
        }

        return $this->unlockSummaryAndConclusionField(
            $this->unlockVerificationPAndIdField(
                $this->repairNeqBlankPartSectionHeadings($fields)
            )
        );
    }

    /**
     * Repair blank-part fields wrongly converted to empty section_heading
     * (e.g. "Responsibilities of different department (blank 1)").
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function repairNeqBlankPartSectionHeadings(array $fields): array
    {
        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }
            $loc = (string) ($field['location'] ?? '');
            $label = trim((string) ($field['label'] ?? ''));
            $type = strtolower(trim((string) ($field['type'] ?? '')));
            $isBlankPartLoc = (bool) preg_match('/_blank_\d+$/', $loc);
            $isBlankPartLabel = (bool) preg_match('/\(\s*blank\s*\d+\s*\)\s*$/i', $label);
            if (! $isBlankPartLoc && ! $isBlankPartLabel) {
                continue;
            }
            if (! in_array($type, ['section_heading', 'paragraph_content', 'locked', ''], true)
                && $type !== 'paragraph_blank_part'
            ) {
                // Keep real checkbox/radio/attachment types untouched.
                if (! in_array($type, ['paragraph_blank', 'text'], true)) {
                    continue;
                }
            }
            $fields[$idx]['type'] = 'paragraph_blank_part';
            $fields[$idx]['widget'] = 'text';
            $fields[$idx]['locked'] = false;
            $fields[$idx]['draft_editable'] = true;
            unset($fields[$idx]['prefilled']);
        }

        return $fields;
    }

    /**
     * "SUMMARY AND CONCLUSION:" is a label plus a typed conclusion, not a TOC line.
     * A stored copy of the label is the duplicate shown on the fill page.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function unlockSummaryAndConclusionField(array $fields): array
    {
        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }
            $label = strtolower(trim(preg_replace('/\s+/', ' ', (string) ($field['label'] ?? '')) ?? ''));
            $label = trim((string) (preg_replace('/^\d+(?:\.\d+)+\s*/', '', $label) ?? $label));
            $label = trim((string) (preg_replace('/\s*:\s*/', ' ', $label) ?? $label));
            $label = trim((string) (preg_replace('/\s+/', ' ', $label) ?? $label));
            if (! in_array($label, ['summary and conclusion', 'summary & conclusion'], true)) {
                continue;
            }
            $valueKey = strtolower(trim(preg_replace('/\s+/', ' ', (string) ($field['value'] ?? '')) ?? ''));
            $valueKey = trim(str_replace(':', '', $valueKey));
            if (in_array($valueKey, ['summary and conclusion', 'summary & conclusion'], true)) {
                $fields[$idx]['value'] = '';
            }
            $fields[$idx]['locked'] = false;
            $fields[$idx]['type'] = 'paragraph_blank';
            $fields[$idx]['widget'] = 'textarea';
        }

        return $fields;
    }

    /**
     * "Verification with the current P & ID:" is a label plus a value, not a locked heading.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function unlockVerificationPAndIdField(array $fields): array
    {
        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }
            $label = strtolower(trim(preg_replace('/\s+/', ' ', (string) ($field['label'] ?? '')) ?? ''));
            if (! str_contains($label, 'verification with the current p')) {
                continue;
            }
            $fields[$idx]['locked'] = false;
            $fields[$idx]['type'] = 'paragraph_blank';
            $fields[$idx]['widget'] = 'text';
        }

        return $fields;
    }

    private function isLockedSectionHeadingText(string $text): bool
    {
        $key = strtolower(trim(preg_replace('/\s+/u', ' ', str_replace(["\u{2019}", '`'], "'", $text)) ?? ''));
        $key = preg_replace('/^\d+(?:\.\d+)+\s*/', '', $key) ?? $key;
        $key = trim(preg_replace('/\s*:\s*/', ' ', $key) ?? $key);
        $key = trim(preg_replace('/\s+/u', ' ', $key) ?? $key);
        if ($key === '') {
            return false;
        }
        // Fill blanks / blank-part labels are never section headings.
        if (preg_match('/_{3,}|\.{3,}|…/', $text) === 1) {
            return false;
        }
        if (preg_match('/\(\s*blank\s*\d+\s*\)\s*$/i', $text) === 1) {
            return false;
        }

        if (strlen($key) > 110) {
            return false;
        }

        $exact = [
            'objective',
            'scope',
            'responsibility',
            'pre-requisites',
            'prerequisites',
            'references',
            'abbreviations',
            'deviations',
            'deviations (if any)',
            'qualification history',
            'methodology',
            'report approval',
            'approval page',
            'observation / results and evaluation',
            'observation/results and evaluation',
            'list of sops identified',
            'verification of draft sops',
            'remarks / recommendation (if any)',
            'remarks / recommendation',
            'remarks/recommendation (if any)',
            'remarks/recommendation',
            'installation checks',
            'pre-installation checks',
            'pre installation checks',
            'motor verification',
            'identification of major components',
            'identification of the contact surfaces',
        ];
        if (in_array($key, $exact, true)) {
            return true;
        }
        if (preg_match('/^remarks\s*\/\s*recommendation\b/i', $key) === 1) {
            return true;
        }

        $prefixes = [
            'type of qualification',
            'equipment / system details',
            'equipment/system details',
            'details of air handling unit',
            'applicable standard operating procedure',
            'pre-qualification checks',
            'calibrations of instruments',
            'preventive maintenance details',
            'any other specific tests',
            'history of equipment',
            'review of change controls',
            'remarks / recommendation',
            'remarks/recommendation',
            'requalification checks',
            'installation re-qualification checks',
            'general checks',
            'operational qualification checks',
            'list of attachment',
            'list of sops identified',
            'verification of draft sops',
            'revision history',
            'methodology',
            'report approval',
            'approval page',
            'observation / results',
            'observation/results',
            'installation checks',
            'pre-installation checks',
            'motor verification',
            'identification of major components',
            'identification of the contact surfaces',
            'identification and classification',
            'identification of execution team members',
            'operational, functional, safety and performance checks',
        ];
        foreach ($prefixes as $prefix) {
            if ($key === $prefix || str_starts_with($key, $prefix . ' ') || str_starts_with($key, $prefix . '(')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Undo TOC upgrades that turned LIST OF ATTACHMENT / Sl. No. data cells into toc_entry
     * and copied the column header into value.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function repairAttachmentListFieldsCorruptedByToc(array $fields): array
    {
        $slLabels = ['sl. no.', 'sl no', 's.no', 's. no.'];
        $listLabels = ['list of attachment'];
        $attachNoLabels = ['attachment no.', 'attachment no'];

        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }

            $location = (string) ($field['location'] ?? '');
            if (! preg_match('/^table_\d+_row_(\d+)_col_(\d+)$/', $location, $m) || (int) $m[1] < 1) {
                continue;
            }

            $label = strtolower(trim((string) ($field['label'] ?? '')));
            $value = trim((string) ($field['value'] ?? ''));
            $type = strtolower(trim((string) ($field['type'] ?? '')));
            $row = (int) $m[1];

            $isSl = in_array($label, $slLabels, true);
            $isList = in_array($label, $listLabels, true);
            $isAttachNo = in_array($label, $attachNoLabels, true);

            if (! $isSl && ! $isList && ! $isAttachNo) {
                continue;
            }

            $valueEchoesLabel = $value !== '' && (
                strtolower($value) === $label
                || (bool) preg_match('/^\d+\.?\s*' . preg_quote($label, '/') . '$/i', $value)
            );

            if ($type === 'toc_entry' || $valueEchoesLabel) {
                if ($isSl) {
                    $fields[$idx]['type'] = 'table_sl_no';
                    $fields[$idx]['locked'] = true;
                    if ($valueEchoesLabel || $value === '') {
                        $fields[$idx]['value'] = (string) ($field['auto_serial'] ?? $row);
                    }
                } elseif ($isAttachNo) {
                    $fields[$idx]['type'] = 'attachment';
                    // Keep filenames; only type needed fixing when misclassified as toc_entry.
                } else {
                    $fields[$idx]['type'] = 'table_multicol';
                    $fields[$idx]['locked'] = true;
                    // Keep value so Python /fill can detect header-echo and return repaired_field_values.
                }
            }
        }

        return $this->repairMissingAttachmentUploadRows($fields);
    }

    /**
     * List-of-attachment rows whose title looks like a section banner (Dust Extractor,
     * Area Recovery) were skipped at parse time, so those rows have no file input.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function repairMissingAttachmentUploadRows(array $fields): array
    {
        $locations = [];
        $byTable = [];
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $location = (string) ($field['location'] ?? '');
            if ($location !== '') {
                $locations[$location] = true;
            }
            if (! preg_match('/^table_(\d+)_row_(\d+)_col_(\d+)$/', $location, $m)) {
                continue;
            }
            $byTable[$m[1]][(int) $m[2]][(int) $m[3]] = $field;
        }

        foreach ($byTable as $tableNum => $rows) {
            $attachCol = null;
            $maxRow = 0;
            foreach ($rows as $rowNum => $cols) {
                $maxRow = max($maxRow, (int) $rowNum);
                foreach ($cols as $colNum => $field) {
                    $type = strtolower((string) ($field['type'] ?? ''));
                    $label = strtolower(trim((string) ($field['label'] ?? '')));
                    if ($type === 'attachment' || in_array($label, ['attachment no.', 'attachment no'], true)) {
                        $attachCol = (int) $colNum;
                    }
                }
            }
            if ($attachCol === null || $maxRow < 1) {
                continue;
            }

            for ($row = 1; $row <= $maxRow; $row++) {
                $location = "table_{$tableNum}_row_{$row}_col_{$attachCol}";
                if (isset($locations[$location])) {
                    continue;
                }
                $fields[] = [
                    'label'    => 'Attachment No.',
                    'location' => $location,
                    'value'    => '',
                    'locked'   => false,
                    'type'     => 'attachment',
                    'widget'   => 'file',
                ];
                $locations[$location] = true;
            }
        }

        return $fields;
    }

    /**
     * Pull TOC page locations out of repaired_field_values when toc_field_values is absent.
     *
     * @param  array<string, string>  $repaired
     * @return array<string, string>
     */
    private function extractTocValuesFromRepaired(array $repaired): array
    {
        $toc = [];
        foreach ($repaired as $loc => $value) {
            $location = (string) $loc;
            if ($location === '') {
                continue;
            }
            if (str_starts_with($location, 'toc_sdt_') || str_contains($location, '_toc_')) {
                if (str_ends_with($location, '_title')) {
                    continue;
                }
                $toc[$location] = (string) $value;
            }
        }

        return $toc;
    }

    /**
     * Persist recomputed TOC page digits from Python /fill into docx_import_fields.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @param  array<string, string>  $tocValues
     * @return array<int, array<string, mixed>>
     */
    private function applyTocFieldValues(array $fields, array $tocValues): array
    {
        if ($tocValues === []) {
            return $fields;
        }

        $byLocation = [];
        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }
            $loc = (string) ($field['location'] ?? '');
            if ($loc !== '') {
                $byLocation[$loc] = $idx;
            }
        }

        foreach ($tocValues as $loc => $page) {
            $location = (string) $loc;
            $pageStr = trim((string) $page);
            if ($location === '' || $pageStr === '' || ! ctype_digit($pageStr)) {
                continue;
            }
            if (isset($byLocation[$location])) {
                $fields[$byLocation[$location]]['value'] = $pageStr;
                continue;
            }
            // Create TOC field if parse/store did not keep this location yet.
            $fields[] = [
                'location' => $location,
                'label'    => 'TOC Page',
                'type'     => 'toc_entry',
                'value'    => $pageStr,
                'locked'   => false,
            ];
        }

        return $fields;
    }

    /**
     * Merge Python /fill repaired_field_values (template cell text) back into stored fields.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @param  array<string, string>  $repaired
     * @return array<int, array<string, mixed>>
     */
    private function applyRepairedFieldValues(array $fields, array $repaired): array
    {
        if ($repaired === []) {
            return $fields;
        }

        $slLabels = ['sl. no.', 'sl no', 's.no', 's. no.'];
        $attachNoLabels = ['attachment no.', 'attachment no'];
        $listLabels = ['list of attachment'];
        $allowed = array_merge($slLabels, $attachNoLabels, $listLabels);

        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }
            $loc = (string) ($field['location'] ?? '');
            if ($loc === '' || ! array_key_exists($loc, $repaired)) {
                continue;
            }
            // TOC digits are handled by applyTocFieldValues — skip here.
            if (str_starts_with($loc, 'toc_sdt_') || str_contains($loc, '_toc_')) {
                continue;
            }

            $label = strtolower(trim((string) ($field['label'] ?? '')));
            // Only persist attachment-list repairs — never rewrite instrument/calibration cells.
            if (! in_array($label, $allowed, true)) {
                continue;
            }

            $fields[$idx]['value'] = (string) $repaired[$loc];
            $type = strtolower(trim((string) ($field['type'] ?? '')));

            if ($type === 'toc_entry' || $type === '') {
                if (in_array($label, $slLabels, true)) {
                    $fields[$idx]['type'] = 'table_sl_no';
                    $fields[$idx]['locked'] = true;
                } elseif (in_array($label, $attachNoLabels, true)) {
                    $fields[$idx]['type'] = 'attachment';
                } else {
                    $fields[$idx]['type'] = 'table_multicol';
                    $fields[$idx]['locked'] = true;
                }
            }
        }

        return $fields;
    }

    /**
     * Move legacy Comments fields from header row 0 to content row 1.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    // Move legacy Comments rows from header row 0 to editable textarea row 1.
    private function upgradeCommentsTextareaLocations(array $fields): array
    {
        $locations = [];
        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }
            $loc = (string) ($field['location'] ?? '');
            if ($loc !== '') {
                $locations[$loc] = $idx;
            }
        }

        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }

            $loc = (string) ($field['location'] ?? '');
            if (! preg_match('/^table_(\d+)_row_0_col_0$/', $loc, $m)) {
                continue;
            }

            $label = strtolower(trim(preg_replace('/:\s*$/', '', (string) ($field['label'] ?? ''))));
            if (! in_array($label, ['comments', 'comment', 'remarks', 'observation', 'description'], true)) {
                continue;
            }

            $target = "table_{$m[1]}_row_1_col_0";
            if (isset($locations[$target])) {
                continue;
            }

            $fields[$idx]['location'] = $target;
            $fields[$idx]['widget']   = 'textarea';
            $fields[$idx]['type']     = 'table_1col';
            $fields[$idx]['locked']   = false;
            $locations[$target]       = $idx;
        }

        return array_values($fields);
    }

    /**
     * @param  array<string, string|null>  $formFields
     * @param  array<int, array<string, mixed>>  $importFields
     * @return array<string, string|null>
     */
    private function remapCommentsFormSubmissions(array $formFields, array $importFields): array
    {
        $targets = [];
        foreach ($importFields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $loc = (string) ($field['location'] ?? '');
            if (! preg_match('/^table_(\d+)_row_1_col_0$/', $loc, $m)) {
                continue;
            }
            $label = strtolower(trim(preg_replace('/:\s*$/', '', (string) ($field['label'] ?? ''))));
            if (! in_array($label, ['comments', 'comment', 'remarks', 'observation', 'description'], true)) {
                continue;
            }
            $targets[$m[1]] = $loc;
        }

        foreach ($targets as $tableNum => $targetLoc) {
            $wrongLoc = "table_{$tableNum}_row_0_col_0";
            if (! array_key_exists($wrongLoc, $formFields)) {
                continue;
            }
            if (! array_key_exists($targetLoc, $formFields) || $formFields[$targetLoc] === null || $formFields[$targetLoc] === '') {
                $formFields[$targetLoc] = $formFields[$wrongLoc];
            }
            unset($formFields[$wrongLoc]);
        }

        return $formFields;
    }

    /**
     * @param  list<string>  $requiredRoles  POST approver keys present on this report's form
     */
    private function assertPostApproversPresent(array $data, array $requiredRoles = []): void
    {
        if ($requiredRoles === []) {
            return;
        }

        $errors = [];
        foreach ($requiredRoles as $field) {
            $value = $data[$field] ?? null;

            if ($value === null || $value === '' || !is_numeric($value)) {
                $errors[$field] = 'This post-approval user is required.';
                continue;
            }

            if (!User::query()->whereKey((int) $value)->exists()) {
                $errors[$field] = 'Selected user is invalid.';
            }
        }

        if (!empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Copy Python-generated DOCX into Laravel public storage when paths differ.
     */
    // Copy filled DOCX from Python output dir when Laravel public path is empty.
    private function syncFilledDocxToLocalStorage(string $absolutePath, string $pythonOutputPath = ''): bool
    {
        if ($pythonOutputPath !== '' && is_file($pythonOutputPath)) {
            $targetDir = dirname($absolutePath);
            if (! is_dir($targetDir)) {
                @mkdir($targetDir, 0775, true);
            }

            if (@copy($pythonOutputPath, $absolutePath)) {
                return is_file($absolutePath);
            }
        }

        return is_file($absolutePath);
    }

    // Populate *_name_text keys from numeric user IDs for display in reports.
    private function enrichUserDisplayTextFields(array &$data): void
    {
        $users = User::query()
            ->select(['id', 'first_name', 'last_name', 'email', 'designation'])
            ->get()
            ->keyBy('id');

        foreach ($data as $field => $value) {
            if (!is_string($field) || !str_ends_with($field, '_name')) {
                continue;
            }

            $textKey = $field . '_text';
            if (!empty($data[$textKey])) {
                continue;
            }

            if ($value === null || $value === '' || !is_numeric($value)) {
                continue;
            }

            $user = $users->get((int) $value);
            if (!$user) {
                continue;
            }

            $data[$textKey] = $this->formatUserOptionLabel($user);
        }
    }

    // Full name plus designation for approval-page Name & Designation cells.
    private function formatUserOptionLabel(User $user): string
    {
        $fullName = $this->formatUserNameOnly($user);
        $designation = trim((string) ($user->designation ?? ''));

        return $designation !== '' ? $fullName . ' - ' . $designation : $fullName;
    }

    /** Plain full name without designation — used in Signature & Date cells. */
    private function formatUserNameOnly(User $user): string
    {
        $fullName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
        if ($fullName === '') {
            $fullName = trim((string) ($user->name ?? ''));
        }
        if ($fullName === '') {
            $fullName = 'User #' . $user->id;
        }

        return $fullName;
    }

    /** Strip " - Designation" suffix from a Name & Designation label for signature cells. */
    private function signatureLabelFromDisplayName(string $displayName): string
    {
        $displayName = trim($displayName);
        if ($displayName === '') {
            return '';
        }

        $parts = preg_split('/\s-\s/', $displayName, 2);

        return trim($parts[0] ?? $displayName);
    }

    /** Dropdown label for fill-form approver selects: "Full Name (email@example.com)". */
    private function formatUserSelectLabel(User $user): string
    {
        $fullName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
        if ($fullName === '') {
            $fullName = trim((string) ($user->name ?? ''));
        }
        if ($fullName === '') {
            $fullName = 'User #' . $user->id;
        }

        $email = trim((string) ($user->email ?? ''));

        return $email !== '' ? $fullName . ' (' . $email . ')' : $fullName;
    }

    /**
     * applySignatureApproverValues() — Write user display names into signature DOCX field locations.
     *
     * Signature-table Name & Designation cells store approver_role keys; the selected user ID
     * lives in post_*_name request fields (same keys as ReportController@buildPostApprovalSteps).
     *
     * @param  array  $formFields   Submitted fields[location] values
     * @param  array  $importFields Parsed docx_import_fields metadata
     * @param  array  $approverData post_prepared_by_name, post_checked_by_* keys → user IDs
     * @return array Updated formFields with resolved display names
     */
    // Intentionally no-op: signature names are stamped at approval time, not on submit.
    private function applySignatureApproverValues(
        array $formFields,
        array $importFields,
        array $approverData
    ): array {
        // Approver user IDs are stored on the report via $approverData → report.data.
        // Do NOT write display names into DOCX field locations here — that would
        // pre-fill empty signature rows (Validation, QA) before their approval step,
        // and can overwrite pre-filled Word content on partially filled rows.
        return $formFields;
    }

    /**
     * handleDocxAttachmentUploads() — Persist attachment column uploads on the report.
     *
     * @return array{0: array, 1: array} Updated formFields and new attachment records
     */
    // Store per-cell attachment uploads and set cell values to the original filenames.
    private function handleDocxAttachmentUploads(
        Request $request,
        Report $report,
        array $formFields
    ): array {
        $uploaded = [];
        $files = $request->file('attachments', []);
        if (! is_array($files)) {
            return [$formFields, $uploaded];
        }

        $existing = $report->attachments ?? [];
        if (is_string($existing)) {
            $existing = json_decode($existing, true) ?? [];
        }
        $nextIndex = $this->nextDocxAttachmentIndex(is_array($existing) ? $existing : []);

        foreach ($files as $location => $file) {
            if (! $file || ! $file->isValid()) {
                continue;
            }

            $originalName = $file->getClientOriginalName();
            $cleanName = str_replace(' ', '_', $originalName);
            // Each protocol gets its own folder under public storage for attachment files.
            $folder = 'reports/docx_attachments/protocol_' . $report->id;
            Storage::disk('public')->makeDirectory($folder);
            $path = $file->storeAs($folder, $cleanName, 'public');

            $uploaded[] = [
                'index'         => $nextIndex + count($uploaded),
                'name'          => $originalName,
                'location'      => (string) $location,
                'file_path'     => $path,
                'stored_name'   => $cleanName,
                'original_name' => $originalName,
                'mime'          => $file->getMimeType(),
                'size'          => $file->getSize(),
            ];

            $formFields[(string) $location] = $originalName;
        }

        return [$formFields, $uploaded];
    }

    /**
     * Map sidebar import links (Revalidation / Requalification / NEQ) to a stored source key.
     */
    private function normalizeImportSource(mixed $source): string
    {
        $source = strtolower(trim((string) $source));

        return array_key_exists($source, Report::importSourceLabels()) ? $source : '';
    }

    /**
     * Enforce menu permission for each DOCX import source.
     */
    private function assertImportSourcePermission(string $importSource): void
    {
        $permission = match ($importSource) {
            'new_equipment_qualification' => 'protocol-fill-new-equipment',
            'revalidation' => 'protocol-revalidation',
            'requalification' => 'protocol-requalification',
            default => null,
        };

        if ($permission === null) {
            abort(403, 'A valid import source permission is required.');
        }

        if (! auth()->user()?->can($permission)) {
            abort(403, 'You do not have permission for this protocol import.');
        }
    }

    /**
     * resolveDocxImportTemplate() — Template record used for VIVA DOCX import reports.
     *
     * Uses only columns that exist on the MSSQL templates table (name, template_type,
     * body_html, created_by) — matches TemplateController@store / uploadTemplate pattern.
     */
    // Find or create the shared "VIVA DOCX Import" template record for protocol imports.
    private function resolveDocxImportTemplate(): Template
    {
        $existing = Template::query()
            ->where('template_type', 'docx')
            ->where('name', 'VIVA DOCX Import')
            ->first();

        if ($existing) {
            return $existing;
        }

        return Template::create([
            'name'          => 'VIVA DOCX Import',
            'template_type' => 'docx',
            'body_html'     => '',
            'created_by'    => auth()->id(),
        ]);
    }

    /**
     * getCompletedDocuments() — Approved documents for mapping dropdowns.
     */
    // Completed documents feed document-number mapping dropdowns on the fill form.
    private function getCompletedDocuments()
    {
        return Document::with(['facility:id,facility_name', 'equipment:id,equipment_name,equipment_id'])
            ->where('status', 'completed')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * mergeAttachmentsByLocation() — Replace prior upload for the same table cell location.
     */
    // Drop old attachment rows when the same table cell is re-uploaded.
    private function mergeAttachmentsByLocation(array $existing, array $newUploads): array
    {
        $newLocations = array_filter(array_map(
            fn ($att) => is_array($att) ? (string) ($att['location'] ?? '') : '',
            $newUploads
        ));

        if ($newLocations === []) {
            return $existing;
        }

        $kept = array_values(array_filter($existing, function ($att) use ($newLocations) {
            if (! is_array($att)) {
                return false;
            }
            $loc = (string) ($att['location'] ?? '');

            return $loc === '' || ! in_array($loc, $newLocations, true);
        }));

        return array_merge($kept, $newUploads);
    }

    /**
     * formatAttachmentLinksForPython() — JSON object {} for Python /fill (not empty array []).
     */
    // Python expects an object for attachment_links; empty must be {} not [].
    private function formatAttachmentLinksForPython(array $links): object|array
    {
        return $links === [] ? (object) [] : $links;
    }

    /**
     * buildAttachmentLinksMap() — Full URLs for clickable attachment cells in filled DOCX.
     */
    private function buildAttachmentLinksMap(Report $report, array $newUploads): array
    {
        $links  = [];
        $merged = $this->mergeAttachmentsByLocation(
            is_array($report->attachments) ? $report->attachments : [],
            $newUploads
        );

        foreach ($merged as $att) {
            if (! is_array($att) || empty($att['location']) || empty($att['file_path'])) {
                continue;
            }
            $index = (int) ($att['index'] ?? 0);
            if ($index <= 0) {
                continue;
            }
            $links[(string) $att['location']] = url(route('reports.attachments.download', [
                'report' => $report->id,
                'index'  => $index,
            ]));
        }

        return $links;
    }

    /**
     * nextDocxAttachmentIndex() — Next free attachment index (avoids reserved 98/99/100 slots).
     */
    private function nextDocxAttachmentIndex(array $attachments): int
    {
        $reserved = [98, 99, 100];
        $max      = 0;

        foreach ($attachments as $att) {
            if (! is_array($att)) {
                continue;
            }
            $idx = (int) ($att['index'] ?? 0);
            if (in_array($idx, $reserved, true)) {
                continue;
            }
            $max = max($max, $idx);
        }

        return $max + 1;
    }

    /**
     * prepareFillFormAttachmentCells() — One download link per cell; keep file input for re-upload.
     */
    // On the fill form, show download link plus re-upload control for existing attachments.
    private function prepareFillFormAttachmentCells(string $html, Report $report): string
    {
        libxml_use_internal_errors(true);

        $attachmentsByLocation = [];
        $raw = $report->attachments ?? [];
        if (is_string($raw)) {
            $raw = json_decode($raw, true) ?? [];
        }
        foreach (is_array($raw) ? $raw : [] as $att) {
            if (! is_array($att)) {
                continue;
            }
            $loc = (string) ($att['location'] ?? '');
            if ($loc !== '' && ! empty($att['file_path'])) {
                $attachmentsByLocation[$loc] = $att;
            }
        }

        if ($attachmentsByLocation === []) {
            return $html;
        }

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->loadHTML(
            '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        $xpath = new \DOMXPath($doc);

        foreach ($xpath->query('//td[contains(@class,"docx-attachment-cell")]') as $td) {
            if (! $td instanceof \DOMElement) {
                continue;
            }

            $location = (string) $td->getAttribute('data-attachment-location');
            if ($location === '') {
                $hidden = $xpath->query('.//input[contains(@class,"attachment-name-field")]', $td)->item(0);
                if ($hidden instanceof \DOMElement) {
                    $location = (string) $hidden->getAttribute('data-attachment-location');
                }
            }

            $att = ($location !== '' && isset($attachmentsByLocation[$location]))
                ? $attachmentsByLocation[$location]
                : null;
            if (! $att) {
                continue;
            }

            $hiddenNode = $xpath->query('.//input[contains(@class,"attachment-name-field")]', $td)->item(0);
            $fileNode   = $xpath->query('.//input[contains(@class,"docx-attachment-field")]', $td)->item(0);
            $hintNode   = $xpath->query('.//small', $td)->item(0);

            $label = '';
            if ($hiddenNode instanceof \DOMElement) {
                $label = (string) $hiddenNode->getAttribute('value');
            }
            if ($label === '') {
                $label = (string) ($att['original_name'] ?? $att['name'] ?? 'Open attachment');
            }

            $fileClone   = ($fileNode instanceof \DOMElement) ? $fileNode->cloneNode(true) : null;
            $hintClone   = ($hintNode instanceof \DOMElement) ? $hintNode->cloneNode(true) : null;
            $hiddenClone = ($hiddenNode instanceof \DOMElement) ? $hiddenNode->cloneNode(true) : null;

            while ($td->firstChild) {
                $td->removeChild($td->firstChild);
            }

            $td->appendChild($this->buildAttachmentDownloadLinkElement($doc, $report, $att, $label));

            $edit = $doc->createElement('input');
            $edit->setAttribute('type', 'text');
            $edit->setAttribute('name', 'fields[' . $location . ']');
            $edit->setAttribute('value', $label);
            $edit->setAttribute('class', 'docx-input docx-attachment-label-edit');
            $edit->setAttribute(
                'style',
                'display:block;width:100%;margin:4px 0;font-size:12px;border-bottom:1px dashed #4e73df;'
            );
            $td->appendChild($edit);

            if ($fileClone instanceof \DOMElement) {
                $td->appendChild($fileClone);
            }
            if ($hintClone instanceof \DOMElement) {
                $td->appendChild($hintClone);
            }
            if ($hiddenClone instanceof \DOMElement) {
                $hiddenClone->setAttribute('value', $label);
                $td->appendChild($hiddenClone);
            }
        }

        $body = $doc->getElementsByTagName('body')->item(0);
        $out  = '';
        foreach ($body->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return $out;
    }

    /**
     * extendPythonExecutionTime() — Allow long-running Python DOCX / preview calls.
     */
    private function extendPythonExecutionTime(): void
    {
        set_time_limit(120);
        ini_set('max_execution_time', '120');
    }

    /**
     * fieldValueByLocation() — Lookup a submitted field value by location key.
     */
    private function fieldValueByLocation(array $fields, string $location, string $fallback = ''): string
    {
        foreach ($fields as $field) {
            if (is_array($field) && ($field['location'] ?? '') === $location) {
                return (string) ($field['value'] ?? $fallback);
            }
        }

        return $fallback;
    }

    /**
     * readonlyDisplay() — Plain-text cell value for approver header table.
     */
    // Escape header values or show em dash when a readonly cell is empty.
    private function readonlyDisplay(string $value): string
    {
        $value = trim($value);

        return $value !== '' ? e($value) : '—';
    }

    /**
     * wrapReadonlyPreviewWithHeader() — Prepend Lupin header table (matches fill form layout).
     */
    // Wrap Python readonly preview body with the same Lupin header table as the fill form.
    private function wrapReadonlyPreviewWithHeader(Report $report, string $bodyHtml, array $fields): string
    {
        $report->loadMissing('template');

        $documentNo    = $this->resolveProtocolDocumentNumber($report, $fields);
        $equipmentName = $this->fieldValueByLocation(
            $fields,
            'header_0_row_2_col_4',
            (string) ($report->equipment_name ?? '')
        );
        $equipmentId   = $this->fieldValueByLocation(
            $fields,
            'header_0_row_3_col_4',
            (string) ($report->equipment_id ?? '')
        );
        $refProtocol   = $this->resolveReferenceProtocolNo($report, $fields);
        $displayTitle  = $this->resolveProtocolDisplayTitle($report, $fields);

        $titleUpper = e(strtoupper($displayTitle));
        $title      = e($displayTitle);

        $header = <<<HTML
<div class="docx-preview">
<div class="docx-summary-header-card">
<table class="docx-table header-table" style="margin-bottom:0;background:#ffffff;color:#0f172a;">
<tr>
<td rowspan="3" style="width:100px;text-align:center;vertical-align:middle;border:1px solid #cbd5e1;padding:8px;background:#ffffff;">
<img src="/storage/lupin.png" alt="Lupin" style="height:50px;width:auto;" onerror="this.style.display='none'">
<div style="font-size:11px;font-weight:bold;margin-top:4px;color:#0f172a;">LUPIN</div>
</td>
<td colspan="4" style="text-align:center;font-weight:bold;font-size:15px;border:1px solid #cbd5e1;padding:8px;background:#f8f9fa;color:#0f172a;">{$titleUpper}</td>
</tr>
<tr>
<td style="border:1px solid #cbd5e1;padding:8px;background:#ffffff;color:#0f172a;"><strong>Title:</strong> {$title}</td>
<td style="border:1px solid #cbd5e1;padding:8px;background:#f8fafc;color:#0f172a;" data-field="header_0_row_4_col_2"><strong>Document Number:</strong> {$this->readonlyDisplay($documentNo)}</td>
<td style="border:1px solid #cbd5e1;padding:8px;background:#f8fafc;color:#0f172a;" data-field="header_0_row_2_col_4"><strong>Equipment Name:</strong> {$this->readonlyDisplay($equipmentName)}</td>
<td style="border:1px solid #cbd5e1;padding:8px;background:#f8fafc;color:#0f172a;" data-field="header_0_row_3_col_4"><strong>Equipment ID:</strong> {$this->readonlyDisplay($equipmentId)}</td>
</tr>
<tr>
<td colspan="4" style="border:1px solid #cbd5e1;padding:8px;background:#f8fafc;color:#0f172a;" data-field="header_ref_protocol"><strong>Reference Protocol No:</strong> {$this->readonlyDisplay($refProtocol)}</td>
</tr>
</table>
</div>
HTML;

        $inner = trim($bodyHtml);
        $inner = preg_replace('#^<div class="docx-preview">\s*#s', '', $inner) ?? $inner;
        $inner = preg_replace('#\s*</div>\s*$#s', '', $inner) ?? $inner;

        return $header . $inner . '</div>';
    }

    /**
     * Prefer fill Protocol/Document Title over shared "VIVA DOCX Import" template name.
     *
     * @param  array<int, array<string, mixed>>  $fields
     */
    private function resolveProtocolDisplayTitle(Report $report, array $fields = []): string
    {
        $title = trim($this->resolveDocumentTitle($report, $fields));
        if ($title !== '') {
            return $title;
        }

        $sourceLabel = Report::importSourceLabel($report->import_source);
        if ($sourceLabel !== '') {
            return $sourceLabel;
        }

        $report->loadMissing('template');

        return trim((string) ($report->template->name ?? 'PERIODIC HVAC VALIDATION REPORT'));
    }

    /**
     * Refresh Title / banner in cached readonly HTML so old "VIVA DOCX Import" is replaced.
     */
    private function patchReadonlyHeaderTitles(string $html, Report $report, array $fields = []): string
    {
        if (trim($html) === '') {
            return $html;
        }

        $displayTitle = $this->resolveProtocolDisplayTitle($report, $fields);
        if ($displayTitle === '') {
            return $html;
        }

        $escaped = e($displayTitle);
        $upper = e(strtoupper($displayTitle));

        $html = preg_replace(
            '~(<strong>\s*Title:\s*</strong>\s*)[^<]*~i',
            '${1}' . $escaped,
            $html,
            1
        ) ?? $html;

        // Banner cell in wrap header (first uppercase title row).
        // Use ~ delimiters — color hex #f8f9fa must not break the pattern.
        $html = preg_replace(
            '~(<td[^>]*background:#f8f9fa;[^>]*>)([^<]*)(</td>)~i',
            '${1}' . $upper . '${3}',
            $html,
            1
        ) ?? $html;

        return $html;
    }

    /**
     * enrichReadonlyPreviewForShow() — Convert preview inputs to readonly values.
     */
    // Entry point: turn editable preview HTML into static HTML for report show.
    private function enrichReadonlyPreviewForShow(string $html, Report $report): string
    {
        $readonlyHtml = $this->convertPreviewHtmlToReadonly($html, $report);
        return $this->formatEffectiveDateInPreviewHtml($readonlyHtml, $report);
    }

    /**
     * Ensures Effective Date is blank while pending approval, and displays QA's approval date once pre-approved.
     */
    private function formatEffectiveDateInPreviewHtml(string $html, Report $report): string
    {
        if (trim($html) === '') {
            return $html;
        }

        $isPreApproved = in_array($report->status, ['pre_approved', 'post_setup', 'post_pending', 'post_returned', 'approved'], true);

        $qaApprovalDate = '';
        if ($isPreApproved) {
            $lastPreStep = $report->relationLoaded('approvalSteps')
                ? $report->approvalSteps->where('stage', 'pre')->whereNotNull('approved_at')->sortByDesc('step_order')->first()
                : $report->approvalSteps()->where('stage', 'pre')->whereNotNull('approved_at')->orderByDesc('step_order')->first();

            if ($lastPreStep && $lastPreStep->approved_at) {
                $qaApprovalDate = \Carbon\Carbon::parse($lastPreStep->approved_at)->timezone('Asia/Kolkata')->format('d-m-Y');
            } elseif (! empty($report->data['effective_date'])) {
                $qaApprovalDate = $this->formatEffectiveDateForDocx((string) $report->data['effective_date']);
            }
        }

        return preg_replace_callback(
            '/(<p\b[^>]*>)\s*(?:EFFECTIVE\s*(?:DATE\s*)?:?\s*)*(EFFECTIVE\s*DATE\s*:?\s*)(?:<span[^>]*>[^<]*<\/span>|<input[^>]*>|[0-9]{2,4}[-\/\.][0-9]{1,2}[-\/\.][0-9]{2,4}|[A-Za-z0-9_\-\.\/\s]+?)?(<\/p>)/i',
            function ($m) use ($isPreApproved, $qaApprovalDate) {
                $tagOpen = $m[1];
                $tagClose = $m[3];
                if ($isPreApproved && $qaApprovalDate !== '') {
                    return $tagOpen . 'EFFECTIVE DATE: <span class="docx-readonly-value">' . htmlspecialchars($qaApprovalDate) . '</span>' . $tagClose;
                }
                return $tagOpen . 'EFFECTIVE DATE: ' . $tagClose;
            },
            $html
        );
    }

    /**
     * collectAttachmentFieldLocations() — Table cell locations that hold uploaded files.
     *
     * @return array<string, true>
     */
    // Collect all table locations that hold file attachments for preview processing.
    private function collectAttachmentFieldLocations(?Report $report, array $fields = []): array
    {
        $locations = [];

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $loc = (string) ($field['location'] ?? '');
            if ($loc === '') {
                continue;
            }
            $type   = strtolower((string) ($field['type'] ?? ''));
            $widget = strtolower((string) ($field['widget'] ?? ''));
            if ($type === 'attachment' || $widget === 'file') {
                $locations[$loc] = true;
            }
        }

        if ($report) {
            $raw = $report->attachments ?? [];
            if (is_string($raw)) {
                $raw = json_decode($raw, true) ?? [];
            }
            foreach (is_array($raw) ? $raw : [] as $att) {
                if (! is_array($att)) {
                    continue;
                }
                $loc = (string) ($att['location'] ?? '');
                if ($loc !== '' && ! empty($att['file_path'])) {
                    $locations[$loc] = true;
                }
            }
        }

        return $locations;
    }

    /**
     * applyAttachmentLinksToPreviewHtml() — Ensure every uploaded attachment cell is a download link.
     */
    // Post-process readonly preview so attachment cells link to stored files.
    private function applyAttachmentLinksToPreviewHtml(string $html, Report $report): string
    {
        if (trim($html) === '') {
            return $html;
        }

        libxml_use_internal_errors(true);

        $attachmentsByLocation = [];
        $raw                   = $report->attachments ?? [];
        if (is_string($raw)) {
            $raw = json_decode($raw, true) ?? [];
        }
        foreach (is_array($raw) ? $raw : [] as $att) {
            if (! is_array($att) || empty($att['file_path'])) {
                continue;
            }
            $loc = (string) ($att['location'] ?? '');
            if ($loc !== '') {
                $attachmentsByLocation[$loc] = $att;
            }
        }

        if ($attachmentsByLocation === []) {
            return $html;
        }

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->loadHTML(
            '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        $xpath = new \DOMXPath($doc);

        $this->convertAttachmentCellsToLinks($doc, $xpath, $report, $attachmentsByLocation);

        foreach ($attachmentsByLocation as $location => $att) {
            $this->replaceAttachmentCellWithLink($doc, $xpath, $report, $location, $att);
        }

        $body = $doc->getElementsByTagName('body')->item(0);
        if (! $body) {
            return $html;
        }

        $out = '';
        foreach ($body->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return $out;
    }

    /**
     * replaceAttachmentCellWithLink() — Set one attachment table cell to a download anchor.
     */
    // Replace a single attachment cell's contents with one download anchor.
    private function replaceAttachmentCellWithLink(
        \DOMDocument $doc,
        \DOMXPath $xpath,
        Report $report,
        string $location,
        array $attachment
    ): void {
        $escapedLoc = str_replace("'", "\\'", $location);
        $query      = "//td[@data-attachment-location='{$escapedLoc}']"
            . " | //td[@data-field='{$escapedLoc}']";
        $nodes      = $xpath->query($query);
        if (! $nodes) {
            return;
        }

        foreach ($nodes as $td) {
            if (! $td instanceof \DOMElement) {
                continue;
            }

            if ($xpath->query('.//a[contains(@class,"docx-attachment-link")]', $td)->length > 0) {
                continue;
            }

            $label = (string) ($attachment['original_name'] ?? $attachment['name'] ?? 'Download attachment');

            // Clear cell and insert a single download link for this attachment location.
            while ($td->firstChild) {
                $td->removeChild($td->firstChild);
            }

            $td->setAttribute('class', trim((string) $td->getAttribute('class')) . ' docx-attachment-cell docx-attachment-filled');
            $td->appendChild($this->buildAttachmentDownloadLinkElement($doc, $report, $attachment, $label));
        }
    }

    /**
     * convertPreviewHtmlToReadonly() — Replace preview inputs with read-only values and attachment links.
     *
     * Encoding note: uses UTF-8 charset wrapper and createTextNode for safe display.
     */
    // Convert inputs, textareas, and selects in preview HTML to readonly spans and links.
    private function convertPreviewHtmlToReadonly(string $html, ?Report $report = null): string
    {
        libxml_use_internal_errors(true);

        $attachments = [];
        if ($report) {
            $raw = $report->attachments ?? [];
            if (is_string($raw)) {
                $raw = json_decode($raw, true) ?? [];
            }
            $attachments = is_array($raw) ? array_values(array_filter($raw, 'is_array')) : [];
        }

        $attachmentsByLocation = [];
        foreach ($attachments as $att) {
            $loc = (string) ($att['location'] ?? '');
            if ($loc !== '') {
                $attachmentsByLocation[$loc] = $att;
            }
        }

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->loadHTML(
            '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        $xpath = new \DOMXPath($doc);

        // Attachment cells: one link per cell (file input + hidden field must not both become links).
        $this->convertAttachmentCellsToLinks($doc, $xpath, $report, $attachmentsByLocation);

        foreach ($xpath->query('//input') as $input) {
            if ($this->isInsideAttachmentCell($input)) {
                continue;
            }

            $name  = (string) $input->getAttribute('name');
            $value = (string) $input->getAttribute('value');
            $class = (string) $input->getAttribute('class');
            $inputType = strtolower(trim((string) $input->getAttribute('type')));

            // Hidden form carriers (approver-name-field, etc.) already have a visible
            // readonly span sibling — converting them creates a second name that
            // overflows onto department labels like "Head - Quality Assurance".
            if ($inputType === 'hidden') {
                $input->parentNode?->removeChild($input);
                continue;
            }

            // Drop empty field inputs so readonly view does not show blank controls.
            if ($value === '' && str_starts_with($name, 'fields[')) {
                $input->parentNode->removeChild($input);
                continue;
            }

            $span = $doc->createElement('span');
            // Keep TOC page markers so show-view sync can still find/update them.
            if (str_contains($class, 'docx-toc-page-input')) {
                $span->setAttribute('class', 'docx-readonly-value toc-page');
                foreach (['data-toc-sec', 'data-toc-title', 'data-field'] as $attr) {
                    $attrVal = (string) $input->getAttribute($attr);
                    if ($attrVal !== '') {
                        $span->setAttribute($attr, $attrVal);
                    }
                }
            } else {
            $span->setAttribute('class', 'docx-readonly-value');
            }
            $span->setAttribute(
                'style',
                'display:inline-block;min-width:80px;padding:2px 8px;border-bottom:1px dotted #999;'
                . 'overflow:visible;text-indent:0;white-space:normal;word-break:break-word;'
            );

            $span->appendChild($doc->createTextNode($value));
            $input->parentNode->replaceChild($span, $input);
        }

        foreach ($xpath->query('//textarea') as $textarea) {
            $value = (string) $textarea->textContent;
            $span  = $doc->createElement('span');
            $span->setAttribute('class', 'docx-readonly-value');
            $span->setAttribute(
                'style',
                'white-space:pre-wrap;display:block;padding:4px 8px;overflow:visible;text-indent:0;'
            );
            $span->appendChild($doc->createTextNode($value));
            $textarea->parentNode->replaceChild($span, $textarea);
        }

        // Replace approver <select> placeholders with saved display names.
        foreach ($xpath->query('//select[contains(@class,"approver-field")]') as $select) {
            $role     = (string) $select->getAttribute('data-approver-role');
            $fieldLoc = (string) $select->getAttribute('data-field-location');
            $display  = '';

            if ($report && $role !== '') {
                $data = $report->data ?? [];
                if (is_string($data)) {
                    $data = json_decode($data, true) ?? [];
                }
                $textKey = $role . '_text';
                if (! empty($data[$textKey])) {
                    $display = (string) $data[$textKey];
                }
            }

            if ($display === '' && $fieldLoc !== '' && $report) {
                foreach ($report->docx_import_fields ?? [] as $field) {
                    if (is_array($field) && ($field['location'] ?? '') === $fieldLoc) {
                        $display = (string) ($field['value'] ?? '');
                        break;
                    }
                }
            }

            $span = $doc->createElement('span');
            $span->setAttribute('class', 'docx-readonly-value docx-approver-readonly');
            $span->setAttribute(
                'style',
                'display:inline-block;width:100%;padding:2px 8px;overflow:visible;text-indent:0;white-space:normal;'
            );
            $span->appendChild($doc->createTextNode($display !== '' ? $display : '—'));
            $parent = $select->parentNode;
            $parent->replaceChild($span, $select);
            // Drop sibling hidden name carriers so they cannot become a second visible stamp.
            if ($parent instanceof \DOMElement && $fieldLoc !== '') {
                foreach ($xpath->query('.//input[@type="hidden"]', $parent) as $hidden) {
                    if (! ($hidden instanceof \DOMElement)) {
                        continue;
                    }
                    $hiddenLoc = (string) $hidden->getAttribute('data-field-location');
                    $hiddenName = (string) $hidden->getAttribute('name');
                    if (
                        $hiddenLoc === $fieldLoc
                        || $hiddenName === 'fields[' . $fieldLoc . ']'
                        || str_contains((string) $hidden->getAttribute('class'), 'approver-name-field')
                    ) {
                        $parent->removeChild($hidden);
                    }
                }
            }
        }

        $body = $doc->getElementsByTagName('body')->item(0);
        $out  = '';
        foreach ($body->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return $out;
    }

    /**
     * isInsideAttachmentCell() — True when a node lives inside an already-processed attachment table cell.
     */
    // Skip generic input conversion for nodes already handled as attachment cells.
    private function isInsideAttachmentCell(\DOMNode $node): bool
    {
        $parent = $node->parentNode;
        while ($parent !== null) {
            if ($parent instanceof \DOMElement && str_contains((string) $parent->getAttribute('class'), 'docx-attachment-cell')) {
                return true;
            }
            $parent = $parent->parentNode;
        }

        return false;
    }

    /**
     * convertAttachmentCellsToLinks() — Replace upload controls with a single open/download link per cell.
     */
    // Strip file inputs from attachment cells and render link or placeholder text.
    private function convertAttachmentCellsToLinks(
        \DOMDocument $doc,
        \DOMXPath $xpath,
        ?Report $report,
        array $attachmentsByLocation
    ): void {
        foreach ($xpath->query('//td[contains(@class,"docx-attachment-cell")]') as $td) {
            if (! $td instanceof \DOMElement) {
                continue;
            }

            $location = (string) $td->getAttribute('data-attachment-location');
            if ($location === '') {
                foreach ($xpath->query('.//input[starts-with(@name,"attachments[")]', $td) as $fileInput) {
                    if (preg_match('/attachments\[(.+)\]$/', (string) $fileInput->getAttribute('name'), $m)) {
                        $location = $m[1];
                        break;
                    }
                }
            }
            if ($location === '') {
                $hidden = $xpath->query('.//input[contains(@class,"attachment-name-field")]', $td)->item(0);
                if ($hidden instanceof \DOMElement) {
                    $location = (string) $hidden->getAttribute('data-attachment-location');
                }
            }

            $displayValue = '';
            $hidden       = $xpath->query('.//input[contains(@class,"attachment-name-field")]', $td)->item(0);
            if ($hidden instanceof \DOMElement) {
                $displayValue = (string) $hidden->getAttribute('value');
            }

            $att = ($location !== '' && isset($attachmentsByLocation[$location]))
                ? $attachmentsByLocation[$location]
                : null;

            while ($td->firstChild) {
                $td->removeChild($td->firstChild);
            }

            $td->setAttribute('class', trim((string) $td->getAttribute('class')) . ' docx-attachment-filled');

            if ($att && ! empty($att['file_path']) && $report) {
                $label = $displayValue !== '' ? $displayValue : (string) ($att['original_name'] ?? 'Open attachment');
                $td->appendChild($this->buildAttachmentDownloadLinkElement($doc, $report, $att, $label));
            } elseif ($displayValue !== '') {
                $span = $doc->createElement('span');
                $span->setAttribute('class', 'docx-readonly-value');
                $span->appendChild($doc->createTextNode($displayValue));
                $td->appendChild($span);
            } else {
                // No file on disk yet — show placeholder for empty attachment cells.
                $td->appendChild($doc->createTextNode('—'));
            }
        }
    }

    /**
     * buildAttachmentDownloadLinkElement() — Clickable download link for an uploaded attachment.
     */
    // Build an anchor element pointing at reports.attachments.download for one file.
    private function buildAttachmentDownloadLinkElement(
        \DOMDocument $doc,
        Report $report,
        array $attachment,
        string $label
    ): \DOMElement {
        $index = (int) ($attachment['index'] ?? 0);
        $url   = route('reports.attachments.download', ['report' => $report->id, 'index' => $index]);

        $a = $doc->createElement('a');
        $a->setAttribute('href', $url);
        $a->setAttribute('target', '_blank');
        $a->setAttribute('class', 'docx-attachment-link');
        $a->setAttribute(
            'style',
            'color:#0d6efd;text-decoration:underline;cursor:pointer;pointer-events:auto;'
            . 'display:inline-block;padding:2px 8px;overflow:visible;white-space:normal;word-break:break-word;'
        );
        $a->appendChild($doc->createTextNode($label));

        return $a;
    }
}
