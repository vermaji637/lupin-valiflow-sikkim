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

        // Fill New Equipment Qualification is merged into the NEQ edit workspace.
        if ($importSource === 'new_equipment_qualification') {
            return redirect()
                ->route('protocol.new-equipment-qualification.index')
                ->with('status', 'Edit and fill New Equipment Qualification documents in this workspace.');
        }

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

        $importSourceEarly = $this->normalizeImportSource(
            $request->input('import_source', $request->query('source'))
        );
        if ($importSourceEarly === 'new_equipment_qualification') {
            return redirect()
                ->route('protocol.new-equipment-qualification.index')
                ->with('status', 'Edit and fill New Equipment Qualification documents in this workspace.');
        }

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

            $response = Http::timeout(90)
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

            if (!$fileId || empty($fields)) {
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

            $reportData = $importSource !== '' ? ['import_source' => $importSource] : [];
            if ($identity['document_number'] !== '') {
                $reportData['document_number'] = $identity['document_number'];
            }
            if ($identity['reference_protocol_no'] !== '') {
                $reportData['reference_protocol_no'] = $identity['reference_protocol_no'];
            }

            // Requalification and Revalidation wait for PRE approval before anyone can fill post execution details.
            $requiresPreApproval = in_array($importSource, ['requalification', 'revalidation'], true);
            if ($requiresPreApproval) {
                $fields = $this->applyRequalificationPreApprovalUnlockRules($fields);
            }
            $report = Report::create([
                'template_id'        => $template->id,
                'templatedata'       => [],
                'data'               => $reportData,
                'rendered_html'      => '',
                'attachments'        => [],
                'facility_section'   => $request->input('facility_section')
                    ?: ($document->facility->facility_name ?? null),
                'document_no'        => $document?->id,
                'version'            => $request->input('version'),
                'equipment_name'     => $request->input('equipment_name')
                    ?: ($document->equipment->equipment_name ?? null),
                'equipment_id'       => $request->input('equipment_id')
                    ?: ($document->equipment->equipment_id ?? null),
                'stage'              => $requiresPreApproval ? 'pre' : 'post',
                'status'             => $requiresPreApproval ? 'pre_setup' : 'post_pending',
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
            $prefilledFields = $this->applyReportPrefills($report, $fields);
            $this->requestPreviewHtml($fileId, $pythonUrl, $prefilledFields, $report->id, $requiresPreApproval ? 'pre_approval' : 'edit');

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
        // Merge stamps without overwriting pre-approval baseline values locked at fill submit.
        $fields        = $this->mergeDocxImportFieldsPreservingBaseline($baselineFields, $stampedFields, $report->id);
        if ($fields === []) {
            return;
        }

        $attachments = $this->regenerateFilledDocxAttachment($report, $fields);
        if ($attachments === null) {
            return;
        }

        if (isset($attachments['fields']) && is_array($attachments['fields'])) {
            $fields = $attachments['fields'];
        }

        // Persist merged fields and regenerated attachment paths back onto the report record.
        $report->update([
            'docx_import_fields' => $fields,
            'docx_filled_path'   => $attachments['docx_filled_path'] ?? $report->docx_filled_path,
            'attachments'        => $attachments['attachments'] ?? $report->attachments,
        ]);
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
            $saved = $this->patchReadonlyHeaderTitles($saved, $report, $stampedFields);
            $saved = $this->enrichReadonlyPreviewForShow($saved, $report);
            $saved = $this->applyAttachmentLinksToPreviewHtml($saved, $report);

            return $saved;
        }

        // Fall back to live Python snapshot when cache is missing or stale.
        $snapshot = $this->generateReadonlyDocxSnapshot($report);
        if ($snapshot === '') {
            return '';
        }

        $enriched = $this->enrichReadonlyPreviewForShow($snapshot, $report);
        $enriched = $this->injectDocxFieldValuesIntoHtml($enriched, $stampedFields, $report);
        $enriched = $this->applyAttachmentLinksToPreviewHtml($enriched, $report);

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

        // Re-apply stamp for this approval step only (do not re-stamp earlier completed steps).
        $stampedFields = $this->fieldsWithApprovalStamps($report, $prefix);
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
                $this->upgradeApprovalPageFieldMetadata($fields)
            )
        );

        $data = $report->data ?? [];
        if (! is_array($data)) {
            $data = [];
        }

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
            'POST_PREPARED'    => 'post_prepared_by',
            'POST_ENGINEERING' => 'post_checked_by_engineering',
            'POST_PRODUCTION'  => 'post_checked_by_production',
            'POST_CHECKED_QA'  => 'post_checked_by_quality',
            'POST_VALIDATION'  => 'post_checked_by_validation',
            'POST_APPROVED_QA' => 'post_approved_by_quality',
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
            $date = trim((string) ($data[$prefix . '_date'] ?? ''));
            if ($date === '' && $isRejectedStep) {
                $date = $step->rejected_at->timezone('Asia/Kolkata')->format('d-m-Y h:i A');
            } elseif ($date === '' && $step->approved_at !== null) {
                $date = $step->approved_at->timezone('Asia/Kolkata')->format('d-m-Y h:i A');
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
            foreach ($fields as $field) {
                if (! is_array($field)) {
                    continue;
                }
                if ((string) ($field['approver_role'] ?? '') !== $roleKey) {
                    continue;
                }

                // Never overwrite cells that were filled in the source DOCX at import time.
                if ($this->isPrefilledImportField($field)) {
                    continue;
                }

                $type = (string) ($field['type'] ?? '');
                // APPROVAL PAGE tables use dedicated stamp types handled separately from signature rows.
                if (str_starts_with($type, 'approval_stamp_')) {
                    $this->applyApprovalPageStampToFields($fields, $roleKey, $name, $date);
                    break;
                }

                $this->applyApprovalStampToSignatureRow(
                    $fields,
                    $indexByLocation,
                    (string) ($field['location'] ?? ''),
                    $name,
                    $date,
                    $sig,
                    $roleKey
                );
            }
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

        $type = (string) ($field['type'] ?? '');
        if (str_starts_with($type, 'approval_stamp_')) {
            return ($field['stamp_eligible'] ?? true) !== false;
        }

        if ($type === 'approver') {
            return ($field['stamp_eligible'] ?? true) !== false;
        }

        $label  = strtolower((string) ($field['label'] ?? ''));
        $widget = strtolower((string) ($field['widget'] ?? ''));

        // Department signature tables: Signature & Date cells are table_multicol and often
        // have no approver_role on the field itself (role lives on the paired Name cell).
        if (str_contains($label, 'signature') && str_contains($label, 'date')) {
            return ($field['stamp_eligible'] ?? true) !== false;
        }

        $role = trim((string) ($field['approver_role'] ?? ''));
        if ($role === '') {
            return false;
        }

        return str_contains($label, 'signature')
            || str_contains($label, 'date')
            || $widget === 'date';
    }

    /**
     * Fill-with-DOCX must not copy approval name, signature, date, or time.
     * A value already stored on the source protocol is still left blank here.
     */
    private function isApprovalFieldExcludedFromDocxFill(array $field): bool
    {
        $type = strtolower((string) ($field['type'] ?? ''));
        if (str_starts_with($type, 'approval_stamp_') || $type === 'approver') {
            return true;
        }

        $label  = strtolower((string) ($field['label'] ?? ''));
        $widget = strtolower((string) ($field['widget'] ?? ''));
        if (str_contains($label, 'signature') && (str_contains($label, 'date') || str_contains($label, 'time'))) {
            return true;
        }

        $role = trim((string) ($field['approver_role'] ?? ''));
        if ($role === '') {
            return false;
        }

        return str_contains($label, 'signature')
            || str_contains($label, 'date')
            || str_contains($label, 'time')
            || $widget === 'date'
            || $widget === 'datetime';
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

        $label = strtolower(trim((string) ($field['label'] ?? '')));
        if ($label !== '' && $this->isSignOffTextField($field)) {
            return false;
        }
        if ($label !== '' && str_contains($label, 'signature')) {
            return false;
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
                $fields[$idx]['widget'] = 'datetime-local';
                $fields[$idx]['value'] = $this->normalizeDateTimeInputValue((string) ($field['value'] ?? ''));
                continue;
            }
            $kept = $this->normalizeDateTimeInputValue((string) ($field['value'] ?? ''));
            // A value written by the date-time picker is Y-m-d or Y-m-dTH:i.
            // Keep that after refresh. Template dates such as 13/01/17 stay blank.
            if ($kept !== '' && preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2})?$/', $kept) === 1) {
                $fields[$idx]['date_selected'] = true;
                $fields[$idx]['widget'] = 'datetime-local';
                $fields[$idx]['value'] = $kept;
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
     * "Checked by (Engineering) (Sign. /Date)" is typed text, not a calendar.
     */
    private function isSignOffTextField(array $field): bool
    {
        $label = strtolower(trim((string) ($field['label'] ?? '')));
        if ($label === '') {
            return false;
        }
        $hasSign = str_contains($label, 'signature') || preg_match('/\bsign\b/', $label) === 1;
        if (! $hasSign) {
            return false;
        }

        return str_contains($label, 'date') || str_contains($label, 'sign.');
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
     * Baseline value must not be replaced (locked, prefilled, or substantive existing content).
     */
    // Guards baseline merge: keep prefilled content and locked cells with existing values.
    private function shouldPreserveBaselineFieldValue(array $field, string $baselineValue): bool
    {
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
        if ($this->isPrefilledImportField($field)) {
            return false;
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
     * On returned resubmit, restore editable fill UX like a fresh import.
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

            if ($this->isTocField($field)) {
                $fields[$idx]['locked'] = true;

                continue;
            }

            if ((string) ($field['type'] ?? '') === 'table_sl_no') {
                $fields[$idx]['locked'] = true;

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

            if ($this->isPostApprovalStampField($field)) {
                $fields[$idx]['locked'] = false;
                $fields[$idx]['value']  = '';

                continue;
            }

            $fields[$idx]['locked'] = false;
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

        if ($roleKey === '' || (string) ($field['approver_role'] ?? '') !== $roleKey) {
            return false;
        }

        $label  = strtolower((string) ($field['label'] ?? ''));
        $widget = strtolower((string) ($field['widget'] ?? ''));

        return str_contains($label, 'signature')
            || str_contains($label, 'date')
            || $widget === 'date';
    }

    /**
     * applyApprovalStampToSignatureRow() — Write approver name + date into one signature row.
     *
     * Name & Designation keeps the approver name; Signature shows the approver name;
     * Date shows name + datetime together.
     *
     * @param  array<string, int>  $indexByLocation
     */
    /** Canonical POST approver keys, in workflow order. */
    private const POST_APPROVER_FIELD_ORDER = [
        'post_prepared_by_name',
        'post_checked_by_engineering_name',
        'post_checked_by_production_name',
        'post_checked_by_quality_name',
        'post_checked_by_validation_name',
        'post_approved_by_quality_name',
    ];

    /** @var array<string, string> */
    private const POST_APPROVER_STEP_LABELS = [
        'post_prepared_by_name'            => 'POST_PREPARED',
        'post_checked_by_engineering_name' => 'POST_ENGINEERING',
        'post_checked_by_production_name'  => 'POST_PRODUCTION',
        'post_checked_by_quality_name'     => 'POST_CHECKED_QA',
        'post_checked_by_validation_name'  => 'POST_VALIDATION',
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
            if ((string) ($field['type'] ?? '') !== 'attachment') {
                continue;
            }
            if (! $this->isCertificateNumberColumnLabel((string) ($field['label'] ?? ''))) {
                continue;
            }

            $fields[$idx]['type']   = 'table_multicol';
            $fields[$idx]['widget'] = 'text';
            $fields[$idx]['locked'] = false;
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

    // Matches common serial-number formats like "1.", "1)", or "1.1".
    private function looksLikeSlNoValue(string $value): bool
    {
        return (bool) preg_match('/^\d+[\.\)]?$|^\d+\.\d+$/', trim($value));
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
        $labelToCover = [
            'reference protocol'    => 'reference_protocol_no',
            'reference document no' => 'reference_protocol_no',
            'reference document'    => 'reference_protocol_no',
            'ref protocol'          => 'reference_protocol_no',
            'document number'       => 'document_no',
            'document no'           => 'document_no',
            'document title'        => 'document_title',
            'equipment/system name' => 'equipment_name',
            'equipment/system id'   => 'equipment_id',
            'facility / section'    => 'facility_section',
            'facility/section'      => 'facility_section',
            'effective date'        => 'effective_date',
        ];

        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }
            if (! empty($field['cover_field'])) {
                $fields[$idx]['type'] = 'protocol_cover';
                continue;
            }
            $label = strtolower(trim((string) ($field['label'] ?? '')));
            $label = trim(preg_replace('/[^a-z0-9]+/', ' ', $label) ?? $label);
            // Collapse duplicated "Effective Date Effective Date" labels from merged DOCX cells.
            $label = preg_replace('/\b(effective date)(?:\s+\1)+\b/', '$1', $label) ?? $label;
            foreach ($labelToCover as $needle => $key) {
                if (! str_contains($label, $needle)) {
                    continue;
                }
                // Avoid tagging "Reference Document No." as plain Document No.
                if ($key === 'document_no' && str_contains($label, 'reference')) {
                    continue;
                }
                $fields[$idx]['cover_field'] = $key;
                $fields[$idx]['type']        = 'protocol_cover';
                if ($key === 'effective_date') {
                    $fields[$idx]['widget'] = 'date';
                }
                break;
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
        // Default Effective Date to today when cover expects it but user has not supplied one yet.
        if ($effectiveDate === '' && $this->reportHasCoverField($importFields, 'effective_date')) {
            $effectiveDate = now()->timezone('Asia/Kolkata')->format('d-m-Y');
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

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            if ((string) ($field['cover_field'] ?? '') === $coverField) {
                $loc = (string) ($field['location'] ?? '');
                if ($loc !== '') {
                    return $loc;
                }
            }
        }

        return null;
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
            if (str_contains($label, 'connected accessories') && preg_match('/^table_(\d+)_row_0$/', $location, $m) === 1) {
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
            $fields[$idx]['locked'] = true;
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
        if (in_array($text, ['checks', 'methodology', 'instrument name', 'reason for revision'], true)) {
            return true;
        }
        if (str_starts_with($text, 'acceptance')) {
            return true;
        }

        return str_starts_with($text, 'identification') && ! str_contains($text, 'number');
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
        if (empty($map['protocol_document_no']) && empty($map['document_no'])) {
            $map['protocol_document_no'] = 'header_0_row_4_col_2';
        }
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
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            if ((string) ($field['cover_field'] ?? '') !== $coverKey) {
                continue;
            }
            $val = trim((string) ($field['value'] ?? ''));
            if ($val !== '') {
                return $val;
            }
        }

        return '';
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

        $fromFields = $this->extractCoverTextValue($fields, 'document_no');
        if ($fromFields !== '') {
            return $fromFields;
        }

        $identity = $this->extractProtocolIdentityFromFields($fields);
        if ($identity['document_number'] !== '') {
            return $identity['document_number'];
        }

        $legacy = trim($this->fieldValueByLocation($fields, 'header_0_row_4_col_2', ''));
        if ($legacy !== '' && ! ctype_digit($legacy)) {
            return $legacy;
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
                // Always apply mapping-card value (including clear) for these wrap-header slots.
                $fields[$idx]['value'] = $value;
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

        $ensure('header_0_row_4_col_2', 'Document Number', 'document_no', $documentNumber);
        $ensure('header_ref_protocol', 'Reference Protocol No', 'reference_protocol_no', $referenceProtocolNo);

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
        $docNo = trim((string) $request->input(
            'protocol_document_no',
            $formFields['protocol_document_no']
                ?? $formFields['header_0_row_4_col_2']
                ?? $data['document_number']
                ?? ''
        ));
        $refNo = trim((string) $request->input(
            'reference_protocol_no',
            $formFields['reference_protocol_no']
                ?? $formFields['header_ref_protocol']
                ?? $data['reference_protocol_no']
                ?? ''
        ));

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
        $formFields['header_0_row_4_col_2'] = $docNo;
        $formFields['header_ref_protocol'] = $refNo;

        $docLoc = $this->findCoverFieldLocation($importFields, 'document_no');
        if ($docLoc) {
            $formFields[$docLoc] = $docNo;
        }
        $refLoc = $this->findCoverFieldLocation($importFields, 'reference_protocol_no');
        if ($refLoc) {
            $formFields[$refLoc] = $refNo;
        }

        return [$data, $formFields];
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
            if ($key !== '' && $loc !== '') {
                $map[$key] = $loc;
            }
        }

        return $map;
    }

    // Resolves Effective Date for the fill form, defaulting to today in Asia/Kolkata timezone.
    private function resolveEffectiveDate(Report $report, array $fields): string
    {
        $data = $report->data ?? [];
        if (is_string($data)) {
            $data = json_decode($data, true) ?? [];
        }
        if (is_array($data) && ! empty($data['effective_date'])) {
            return $this->normalizeEffectiveDateForForm((string) $data['effective_date']);
        }

        $loc = $this->findCoverFieldLocation($fields, 'effective_date');
        if ($loc !== null) {
            foreach ($fields as $field) {
                if (is_array($field) && ($field['location'] ?? '') === $loc) {
                    $val = trim((string) ($field['value'] ?? ''));
                    if ($val !== '') {
                        return $this->normalizeEffectiveDateForForm($val);
                    }
                }
            }
        }

        return now()->timezone('Asia/Kolkata')->format('Y-m-d');
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
    private function formatEffectiveDateForDocx(string $date): string
    {
        $date = trim($date);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }

        return $date;
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
        if (is_array($data) && ! empty($data['effective_date'])) {
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
        // Last resort: stamp today's date so cover never exports blank.
        if ($date === '') {
            $date = now()->timezone('Asia/Kolkata')->format('d-m-Y');
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
            'label'    => 'Training imparted',
            'value'    => '',
            'locked'   => false,
            'type'     => 'paragraph_radio',
            'location' => 'para_1',
            'widget'   => 'radio',
        ];

        return $fields;
    }

    /**
     * Repair missing approver_role on legacy Approved By / Checked By rows.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    // Back-fills missing approver_role on legacy Checked By / Approved By signature table rows.
    private function upgradeSignatureApproverRoles(array $fields): array
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

        foreach ($byTable as $rows) {
            $title = '';
            foreach ($rows[0] ?? [] as $col) {
                $title .= ' ' . strtolower($col['value'] ?? '');
            }
            $title = trim($title);

            $colZeroApprovers = $this->collectColZeroApproverRows($rows);
            if ($colZeroApprovers !== []) {
                // Multi-row department table: map each row to a POST approver role by position.
                if (count($colZeroApprovers) >= 3) {
                    $checkedRoles = [
                        2 => 'post_checked_by_engineering_name',
                        3 => 'post_checked_by_production_name',
                        4 => 'post_checked_by_validation_name',
                        5 => 'post_approved_by_quality_name',
                    ];
                    foreach ($checkedRoles as $rowNum => $role) {
                        $this->assignApproverRoleIfEmpty($fields, $rows, $rowNum, $role, 0);
                    }
                    continue;
                }

                // Single approver column layout: infer role from table title text.
                if (count($colZeroApprovers) === 1) {
                    $rowNum = array_key_first($colZeroApprovers);
                    $existingRole = (string) ($colZeroApprovers[$rowNum]['role'] ?? '');
                    if ($existingRole === '') {
                        if (str_contains($title, 'approved by')) {
                            $this->assignApproverRoleIfEmpty($fields, $rows, (int) $rowNum, 'post_approved_by_quality_name', 0);
                        } else {
                            $this->assignApproverRoleIfEmpty($fields, $rows, (int) $rowNum, 'post_prepared_by_name', 0);
                        }
                    }
                    continue;
                }
            }

            // Standard Approved By two-row layout: validation then QA approval.
            if (str_contains($title, 'approved by')) {
                $this->assignApproverRoleIfEmpty($fields, $rows, 1, 'post_checked_by_validation_name');
                $this->assignApproverRoleIfEmpty($fields, $rows, 2, 'post_approved_by_quality_name');
                continue;
            }

            // Checked By multi-department layout: assign roles top-to-bottom by row number.
            if (str_contains($title, 'checked by')) {
                $checkedRoles = [
                    1 => 'post_checked_by_validation_name',
                    2 => 'post_checked_by_production_name',
                    3 => 'post_checked_by_engineering_name',
                    4 => 'post_approved_by_quality_name',
                ];
                foreach ($checkedRoles as $rowNum => $role) {
                    $this->assignApproverRoleIfEmpty($fields, $rows, $rowNum, $role);
                }
            }
        }

        return $this->placeCheckedByQualityBeforeValidation($fields);
    }

    /**
     * Checked By lists Quality Assurance above Validation.
     * That row must approve before Validation. Approved By Quality Assurance stays last.
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
            foreach ($cells as $cell) {
                if ($cell['row'] >= $validationRow) {
                    continue;
                }
                if (! in_array($cell['role'], ['post_approved_by_quality_name', 'post_checked_by_quality_name'], true)) {
                    continue;
                }
                $fields[$cell['idx']]['approver_role'] = 'post_checked_by_quality_name';
            }
        }

        $hasFinalQa = false;
        foreach ($fields as $field) {
            if (is_array($field) && (string) ($field['approver_role'] ?? '') === 'post_approved_by_quality_name') {
                $hasFinalQa = true;
                break;
            }
        }
        if (! $hasFinalQa) {
            foreach ($fields as $idx => $field) {
                if (! is_array($field)) {
                    continue;
                }
                if ((string) ($field['approver_role'] ?? '') === 'post_checked_by_quality_name') {
                    $fields[$idx]['approver_role'] = 'post_approved_by_quality_name';
                }
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
            || (bool) preg_match('/\bcert\.?\s*no\.?/', $ll);
    }

    /**
     * Upgrade legacy table_multicol Name/Signature/Date cells to approval_stamp_* metadata.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    // Upgrades legacy Name/Signature/Date cells to approval_stamp_* types with approver roles.
    private function upgradeApprovalPageFieldMetadata(array $fields): array
    {
        if (! $this->detectApprovalPageFromLegacyFields($fields)) {
            return $this->upgradeApprovalPageSelectableNameRows(
                $this->repairMissingApprovalPagePerformerRows(
                    $this->repairMissingApprovalPageStampRows($fields)
                )
            );
        }

        $roleByRow = [
            1 => 'post_prepared_by_name',
            2 => 'post_checked_by_engineering_name',
            3 => 'post_approved_by_quality_name',
        ];

        $typeByLabel = [
            'name'       => 'approval_stamp_name',
            'signature'  => 'approval_stamp_signature',
            'date'       => 'approval_stamp_date',
        ];

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
            // Row number maps to POST workflow role for stamp application during approval.
            $fields[$idx]['approver_role'] = $roleByRow[$row] ?? null;
        }

        return $this->upgradeApprovalPageSelectableNameRows(
            $this->repairMissingApprovalPagePerformerRows(
                $this->repairMissingApprovalPageStampRows($fields)
            )
        );
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
            'post_checked_by_engineering_name',
            'post_checked_by_production_name',
            'post_checked_by_quality_name',
            'post_checked_by_validation_name',
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
            if (! preg_match('/^table_\d+_row_\d+_col_1$/', $location)) {
                continue;
            }

            $type  = (string) ($field['type'] ?? '');
            $label = strtolower(trim((string) ($field['label'] ?? '')));
            if ($type !== 'approval_stamp_name' && $type !== 'approver') {
                continue;
            }
            if ($label !== '' && $label !== 'name') {
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

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $loc = (string) ($field['location'] ?? '');
            if ($loc !== '') {
                $locations[$loc] = true;
            }
            $type = (string) ($field['type'] ?? '');
            if (! str_starts_with($type, 'approval_stamp_')) {
                continue;
            }
            if (! preg_match('/^table_(\d+)_row_(\d+)_col_([1-3])$/', $loc, $m)) {
                continue;
            }
            $stampTables[$m[1]][(int) $m[2]][(int) $m[3]] = true;
        }

        foreach ($stampTables as $tableNum => $rows) {
            if (! isset($rows[2][1], $rows[2][2], $rows[2][3])) {
                continue;
            }

            $performerLoc = "table_{$tableNum}_row_1_col_1";
            // Row 1 col 1 is the user-selectable performer; rows 2-3 receive auto stamps.
            if (isset($locations[$performerLoc])) {
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
            if (! str_starts_with($type, 'approval_stamp_')) {
                continue;
            }
            $location = (string) ($field['location'] ?? '');
            if (! preg_match('/^table_(\d+)_row_(\d+)_col_([1-3])$/', $location, $m)) {
                continue;
            }
            $stampByTable[$m[1]][(int) $m[2]][(int) $m[3]] = true;
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
            $completeRows = 0;
            for ($row = 1; $row <= 3; $row++) {
                if ($this->approvalPageStampRowComplete($rows, $row)) {
                    $completeRows++;
                }
            }
            if ($completeRows < 2) {
                continue;
            }

            // Fill in any incomplete Name/Signature/Date triplets for rows 1-3.
            for ($row = 1; $row <= 3; $row++) {
                if ($this->approvalPageStampRowComplete($rows, $row)) {
                    continue;
                }

                foreach ($stampCols as $col => $meta) {
                    $loc = "table_{$tableNum}_row_{$row}_col_{$col}";
                    if (isset($locations[$loc])) {
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
    private function applyApprovalPageStampToFields(
        array &$fields,
        string $roleKey,
        string $name,
        string $date
    ): void {
        $signatureName     = $this->signatureLabelFromDisplayName($name);
        $signatureWithDate = trim($signatureName . ($date !== '' ? '  ' . $date : ''));

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
            if ($type === 'approval_stamp_name') {
                $fields[$idx]['value'] = $name;
            } elseif ($type === 'approval_stamp_signature') {
                $fields[$idx]['value'] = $signatureWithDate !== '' ? $signatureWithDate : $signatureName;
            } elseif ($type === 'approval_stamp_date') {
                $fields[$idx]['value'] = $date;
            } elseif ($type === 'approver') {
                $fields[$idx]['value'] = $name;
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
        // Abort row stamp when name or date baseline must be preserved.
        if (
            is_array($approverField)
            && $this->shouldPreserveBaselineFieldValue(
                $approverField,
                trim((string) ($approverField['value'] ?? ''))
            )
        ) {
            return;
        }

        $stampRoleKey = $roleKey ?? (is_array($approverField) ? (string) ($approverField['approver_role'] ?? '') : '');
        $dateLoc      = is_array($approverField) ? (string) ($approverField['signature_date_location'] ?? '') : '';

        if ($dateLoc !== '' && isset($indexByLocation[$dateLoc])) {
            $dateField = $fields[$indexByLocation[$dateLoc]] ?? null;
            if (
                is_array($dateField)
                && $this->shouldPreserveBaselineFieldValue(
                    $dateField,
                    trim((string) ($dateField['value'] ?? ''))
                )
            ) {
                return;
            }
        }

        if ($name !== '') {
            $fields[$indexByLocation[$approverLocation]]['value'] = $name;
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

            if ($this->shouldPreserveBaselineFieldValue($field, trim((string) ($field['value'] ?? '')))) {
                continue;
            }

            $label  = strtolower((string) ($field['label'] ?? ''));
            $widget = strtolower((string) ($field['widget'] ?? ''));

            // Combined Signature & Date column gets name plus timestamp on one line.
            if (str_contains($label, 'signature') && str_contains($label, 'date')) {
                if ($combined !== '') {
                    $fields[$idx]['value'] = $combined;
                }
                continue;
            }

            if ($widget === 'date' || preg_match('/\bdate\b/', $label)) {
                if ($combined !== '') {
                    $fields[$idx]['value'] = $combined;
                } elseif ($date !== '') {
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

        // Also write combined value to explicitly linked signature_date_location when present.
        if ($dateLoc !== '' && isset($indexByLocation[$dateLoc]) && $combined !== '') {
            $dateIdx   = $indexByLocation[$dateLoc];
            $dateField = $fields[$dateIdx] ?? null;
            if (
                is_array($dateField)
                && ! $this->shouldPreserveBaselineFieldValue(
                    $dateField,
                    trim((string) ($dateField['value'] ?? ''))
                )
            ) {
                $fields[$dateIdx]['value'] = $combined;
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
            if (! $nodes) {
                continue;
            }

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
    private function regenerateFilledDocxAttachment(Report $report, array $fields): ?array
    {
        $fileId = $report->docx_import_path;
        if (! $fileId) {
            return null;
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
                ]);

            if (! $response->successful()) {
                Log::warning('DOCX approval stamp: Python /fill failed', ['body' => $response->body()]);

                return null;
            }

            $repairedValues = $response->json('repaired_field_values') ?? [];
            if (is_array($repairedValues) && $repairedValues !== []) {
                $fields = $this->applyRepairedFieldValues($fields, $repairedValues);
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
                                        $this->upgradeApprovalPageFieldMetadata($fields)
                                    )
                                )
                            )
                        )
                    )
                )
            );

            $report->update([
                'docx_import_path'     => $fileId,
                'docx_import_fields'   => $fields,
                'docx_import_status'   => 'pending',
                'docx_filled_path'     => null,
                'stage'                => 'post',
                'status'               => 'post_pending',
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
                                        is_array($fields) ? $fields : []
                                    )
                                )
                            )
                        )
                    )
                )
            )
        );
        $coverFieldLocations = $this->buildCoverFieldLocationMap($fields);
        // Drop header cells that duplicate cover-page fields already mapped elsewhere.
        $fields          = $this->pruneSpuriousHeaderImportFields($fields, $coverFieldLocations);
        // Pre-fill equipment, document, and date values from the report record.
        $fields          = $this->applyRequalificationPreApproverNames(
            $report,
            $this->applyReportPrefills($report, $fields)
        );
        $fields          = $this->unlockRevisionHistoryDateFields($fields);
        // Re-apply NEQ-only unlock/lock rules when opening fill form (revalidation untouched).
        $fields          = $this->applySourceSpecificImportFieldRules($fields, $report->import_source);
        $fields          = $this->restoreSignOffTextFields($fields);
        // Calibration dates from an older protocol stay blank until chosen here.
        $fields          = $this->blankUnselectedDateTimeValues($fields);

        if (in_array($report->status, ['post_returned', 'pre_returned'], true)) {
            $fields = $this->unlockDocxFieldsForReturnedEdit($fields);
            if (! empty($report->docx_import_path)) {
                $this->flushDocxPreviewCache((string) $report->docx_import_path, $report->id);
            }
        }

        $isPreApprovalMode = in_array($report->import_source, ['requalification', 'revalidation'], true)
            && in_array($report->status, ['pre_setup', 'pre_returned'], true)
            && $report->stage === 'pre';

        if ($isPreApprovalMode) {
            $hasParagraphContent = collect($fields)->contains(fn ($f) => is_array($f) && ($f['type'] ?? '') === 'paragraph_content');
            $hasCol0Fields = collect($fields)->contains(fn ($f) => is_array($f) && str_ends_with((string) ($f['location'] ?? ''), '_col_0'));
            if ((! $hasParagraphContent || ! $hasCol0Fields) && ! empty($report->docx_import_path)) {
                $this->flushDocxPreviewCache((string) $report->docx_import_path, $report->id);
                $reparsed = $this->reparseDocxForReport($report);
                if (! empty($reparsed)) {
                    $fields = $reparsed;
                    $report->docx_import_fields = $fields;
                    $report->save();
                }
            }

            $fields = $this->applyRequalificationPreApprovalUnlockRules($fields);
            $previewMode = 'pre_approval';
            $report->docx_import_fields = $fields;
            $report->save();
        } else {
            if (in_array(($report->import_source ?? ''), ['requalification', 'revalidation'], true)) {
                $fields = $this->applyRequalificationPostPreApprovalLockRules($fields);
                $previewMode = 'requalification';
            } else {
                $fields = $this->lockConnectedAccessoriesDescriptions($fields);
                $fields = $this->lockExistingTableContent($fields);
                $previewMode = 'edit';
            }
            $report->docx_import_fields = $fields;
            $report->save();
        }

        $fileId          = $report->docx_import_path;
        $docxTemplateAvailable = $this->attemptAutoRestoreDocxTemplate($report);
        $pythonUrl       = env('PYTHON_NLP_URL', 'http://127.0.0.1:8001');
        // Fetch table-layout HTML from Python /preview; empty result uses flat field list.
        $previewHtml     = $fileId ? $this->requestPreviewHtml($fileId, $pythonUrl, $fields, $report->id, $previewMode) : '';

        if (trim($previewHtml) === '' && $docxTemplateAvailable && $fileId) {
            $this->flushDocxPreviewCache($fileId, $report->id);
            $previewHtml = $this->requestPreviewHtml($fileId, $pythonUrl, $fields, $report->id, $previewMode);
        }
        // Replace attachment cells with download links when files were uploaded earlier.
        if (trim($previewHtml) !== '') {
            $previewHtml = $this->prepareFillFormAttachmentCells($previewHtml, $report);
        }
        // Interactive table only when Python returned editable inputs (same as fresh import).
        $useTablePreview = $this->previewHtmlHasInteractiveFields($previewHtml);
        // Flat list excludes TOC rows that users must not edit.
        $flatListFields  = collect($fields)
            ->filter(fn (array $field) => ! $this->isTocField($field) && ! $this->isLockedSectionHeadingText((string) ($field['label'] ?? '')))
            ->values()
            ->all();

        $equipmentName   = $report->equipment_name ?? '';
        $equipmentId     = $report->equipment_id ?? '';
        $facilitySection = $report->facility_section ?? '';
        $documentNo      = $report->document_no ?? '';
        $protocolDocumentNo = $this->resolveProtocolDocumentNumber($report, $fields);
        $referenceProtocolNo = $this->resolveReferenceProtocolNo($report, $fields);
        $fields = $this->ensureProtocolIdentityHeaderFields($fields, $protocolDocumentNo, $referenceProtocolNo);
        $documentTitleLocation = $this->findCoverFieldLocation($fields, 'document_title');
        $hasDocumentTitleField = $documentTitleLocation !== null;
        $documentTitle   = $this->resolveDocumentTitle($report, $fields);
        $effectiveDate       = $this->resolveEffectiveDate($report, $fields);
        $effectiveDateLocation = $this->findEffectiveDateFieldLocation($fields);
        $hasEffectiveDateField = $effectiveDateLocation !== null;
        $coverFieldLocations = $this->buildCoverFieldLocationMap($fields);
        $showLegacyBladeHeader = ($coverFieldLocations['document_no'] ?? '') === 'header_0_row_4_col_2';
        $equipmentList   = Equipment::query()
            ->orderBy('equipment_name')
            ->get(['id', 'equipment_name', 'equipment_id', 'facility']);
        // Active users only — users.status is tinyint (1 = active, 0 = inactive).
        $users = User::with('roles:id,name')
            ->select('id', 'first_name', 'last_name', 'email', 'designation')
            ->where(function ($q) {
                $q->where('status', 1)
                    ->orWhereNull('status');
            })
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(function ($user) {
                $user->display_name = $this->formatUserSelectLabel($user);

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
        $requalFillLocked = $this->requalificationFillIsLocked($report);
        $requalPreEditable = $requalFillLocked
            && in_array($report->status, ['pre_setup', 'pre_returned'], true);
        $draftPreApprovers = [];
        foreach (array_keys(self::PRE_APPROVER_FIELDS) as $roleKey) {
            if (! empty($reportData[$roleKey]) && is_numeric($reportData[$roleKey])) {
                $draftPreApprovers[$roleKey] = (int) $reportData[$roleKey];
            }
        }

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
            'requalFillLocked',
            'requalPreEditable',
            'draftPreApprovers',
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
                                        is_array($report->docx_import_fields ?? null) ? $report->docx_import_fields : []
                                    )
                                )
                            )
                        )
                    )
                )
            )
        );
        $coverLoc = $this->buildCoverFieldLocationMap($importFields);
        $importFields = $this->pruneSpuriousHeaderImportFields($importFields, $coverLoc);
        $importFields = $this->unlockRevisionHistoryDateFields($importFields);

        if ($isReturnedEdit) {
            $importFields = $this->unlockDocxFieldsForReturnedEdit($importFields);
        }
        if ($isPreApprovalDraft) {
            $importFields = $this->applyRequalificationPreApprovalUnlockRules($importFields);
        } else {
            if (in_array(($report->import_source ?? ''), ['requalification', 'revalidation'], true)) {
                $importFields = $this->applyRequalificationPostPreApprovalLockRules($importFields);
            } else {
                $importFields = $this->lockConnectedAccessoriesDescriptions($importFields);
                $importFields = $this->lockExistingTableContent($importFields);
            }
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
            $originalValue = (string) ($field['value'] ?? '');
            $submittedValue = $originalValue;
            if (! $isLocked && array_key_exists($location, $formFields)) {
                $submittedValue = (string) $formFields[$location];
            }
            if (isset($uploadedNames[$location])) {
                $submittedValue = $uploadedNames[$location];
                $isLocked = false;
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
            if (isset($uploadedNames[$location])) {
                $updatedField['type'] = 'attachment';
                $updatedField['widget'] = 'file';
            }
            if ($this->isSignOffTextField($field)) {
                $updatedField['widget'] = 'text';
                unset($updatedField['date_selected']);
            } elseif ($this->isDocxDateTimeEntryField($field)) {
                $picked = trim((string) $updatedField['value']) !== '';
                $updatedField['date_selected'] = $picked;
                if ($picked) {
                    $updatedField['widget'] = 'datetime-local';
                }
            }
            $updatedFields[] = $updatedField;
        }

        $knownLocations = array_column($updatedFields, 'location');
        foreach ($formFields as $location => $submittedValue) {
            if (! is_string($location) || in_array($location, $knownLocations, true)) {
                continue;
            }
            if (! preg_match('/^(para_|table_|header_|txbx_)/', $location)) {
                continue;
            }
            $updatedFields[] = [
                'label'    => $location,
                'location' => $location,
                'value'    => (string) $submittedValue,
                'locked'   => false,
                'type'     => isset($uploadedNames[$location]) ? 'attachment' : '',
                'widget'   => isset($uploadedNames[$location]) ? 'file' : null,
            ];
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

        $savedAt = now()->timezone('Asia/Kolkata')->format('d-m-Y h:i A');
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
        $report->save();

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

            $matchedFields[$location] = $value;
        }

        return [
            'fields'       => $matchedFields,
            'approvers'    => [],
            'filled_count' => count($matchedFields),
        ];
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
        $tableOpsRaw = $request->input('table_ops');
        $tableOps = [];
        if (is_string($tableOpsRaw)) {
            $tableOps = json_decode($tableOpsRaw, true) ?: [];
        } elseif (is_array($tableOpsRaw)) {
            $tableOps = $tableOpsRaw;
        }

        // Apply updated field values
        $storedFields = $report->docx_import_fields ?? [];
        if (! is_array($storedFields)) {
            $storedFields = [];
        }

        $report->data = $data;
        $storedFields = $this->applyRequalificationPreApproverNames($report, $storedFields);

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

                    $this->mirrorDocxTemplateAfterParse($newFileId, $newFileContent, $outputFilename);
                    $data['original_docx_path'] = $fileId;
                    $report->docx_import_path   = $newFileId;
                    $storedFields               = $newFields;
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
     * Recall a pre-approval request back to draft mode so creator can add/delete rows or edit.
     */
    public function recallRequalificationPreApproval(Report $report)
    {
        $isCreator = (int) $report->created_by === (int) auth()->id();
        $isSuperAdmin = (auth()->user() && auth()->user()->isAdmin()) || auth()->id() === 1;

        abort_unless($isCreator || $isSuperAdmin, 403);

        if ($report->status === 'pre_pending' && $report->stage === 'pre') {
            $report->approvalSteps()->where('stage', 'pre')->delete();
            $report->update([
                'status'             => 'pre_setup',
                'current_step_order' => 0,
                'rendered_html'      => null,
            ]);
            $this->flushDocxPreviewCache((string) $report->docx_import_path, $report->id);

            return redirect()->route('reports.docx-import.fill-form', $report)
                ->with('status', 'Pre-approval recalled. You can now edit the document, add/delete rows, and re-send for pre-approval.');
        }

        return redirect()->route('reports.show', $report);
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
                                        is_array($report->docx_import_fields ?? null) ? $report->docx_import_fields : []
                                    )
                                )
                            )
                        )
                    )
                )
            )
        );
        $coverLoc = $this->buildCoverFieldLocationMap($importFields);
        $importFields = $this->pruneSpuriousHeaderImportFields($importFields, $coverLoc);
        $importFields = $this->unlockRevisionHistoryDateFields($importFields);

        if (in_array($report->status, ['post_returned', 'pre_returned'], true)) {
            $importFields = $this->unlockDocxFieldsForReturnedEdit($importFields);
        }

        $isPreApprovalDraft = in_array($report->import_source, ['requalification', 'revalidation'], true)
            && in_array($report->status, ['pre_setup', 'pre_returned'], true)
            && $report->stage === 'pre';

        if ($isPreApprovalDraft) {
            $importFields = $this->applyRequalificationPreApprovalUnlockRules($importFields);
        } else {
            if (in_array(($report->import_source ?? ''), ['requalification', 'revalidation'], true)) {
                $importFields = $this->applyRequalificationPostPreApprovalLockRules($importFields);
            } else {
                $importFields = $this->lockConnectedAccessoriesDescriptions($importFields);
                $importFields = $this->lockExistingTableContent($importFields);
            }
        }
        $importFields = $this->applyRequalificationPreApproverNames($report, $importFields);

        $usesApprovalPage     = $this->reportUsesApprovalPageTable($importFields);
        $presentApproverRoles = $this->collectPostApproverRolesFromFields($importFields);
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
                if (! preg_match('/^(para_|table_|header_|txbx_)/', $location)) {
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

            if ($usesApprovalPage && auth()->user()) {
                $preparerPrefilled = false;
                foreach ($updatedFields as $prepField) {
                    if (
                        is_array($prepField)
                        && (string) ($prepField['approver_role'] ?? '') === 'post_prepared_by_name'
                        && $this->isPrefilledImportField($prepField)
                    ) {
                        $preparerPrefilled = true;
                        break;
                    }
                }

                // Stamp preparer name/date on approval page when Word did not pre-fill that row.
                if (! $preparerPrefilled) {
                    $recorderName = $this->formatUserOptionLabel(auth()->user());
                    $recorderDate = now()->timezone('Asia/Kolkata')->format('d-m-Y h:i A');
                    $this->applyApprovalPageStampToFields(
                        $updatedFields,
                        'post_prepared_by_name',
                        $recorderName,
                        $recorderDate
                    );
                    $stampedByLocation = array_column($updatedFields, 'value', 'location');
                    foreach ($fieldsToPython as $idx => $row) {
                        $loc = $row['location'] ?? '';
                        if ($loc !== '' && array_key_exists($loc, $stampedByLocation)) {
                            $fieldsToPython[$idx]['value'] = (string) $stampedByLocation[$loc];
                        }
                    }
                }
            }

            // Do not stamp signature rows at submit time — only when each approval step completes.
            // (stampApproverSignatureRowsInFields was overwriting pre-filled Word values.)

            $updatedFields = $this->ensureProtocolIdentityHeaderFields(
                $updatedFields,
                (string) ($identityDataSeed['document_number'] ?? ''),
                (string) ($identityDataSeed['reference_protocol_no'] ?? '')
            );

            $updatedFields = $this->finalizePreApprovalDocxFields($updatedFields);
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

            // Call Python /fill to write cell values and hyperlinks into the output DOCX.
            $result = null;
            try {
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

                    $eqId = in_array($report->import_source, ['requalification', 'revalidation'], true)
                        ? (string) ($report->equipment_id ?? '')
                        : '';

                    $process = new Process([$pythonBin, $cliScript, 'fill', $templatePath, $tmpFields, $cliOutputPath, $tmpAtt, $eqId]);
                    $process->setTimeout(90);
                    $process->run();
                    @unlink($tmpFields);
                    @unlink($tmpAtt);

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
                    $effectiveDate = now()->timezone('Asia/Kolkata')->format('Y-m-d');
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

            if (
                $usesApprovalPage
                && ! empty($data['post_prepared_by_name'])
                && in_array('post_prepared_by_name', $presentApproverRoles, true)
            ) {
                $preparer = User::find((int) $data['post_prepared_by_name']);
                if ($preparer) {
                    $recorderName = $this->formatUserOptionLabel($preparer);
                    $data['post_prepared_by_name_text'] = $recorderName;
                    $data['post_prepared_by_date']      = now()->timezone('Asia/Kolkata')->format('d-m-Y h:i A');
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
                $usesApprovalPage
                && in_array('post_prepared_by_name', $presentApproverRoles, true)
            ) {
                // Auto-approve preparer step because submitter just filled the form.
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

            return redirect()->route('protocol')
                ->with('status', 'Submitted for Post Approval! ' . $report->displayCode());

        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('DOCX filling error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return redirect()->back()->with('error', 'Error generating filled document: ' . $e->getMessage());
        }
    }

    /**
     * Download filled DOCX import as PDF (LibreOffice conversion).
     */
    // ── downloadFilledPdf() ──────────────────────────────
    // Converts the filled DOCX (docx_filled_path) to PDF via LibreOffice headless.
    // Parameters: Report $report (must have docx_filled_path set).
    // Returns: File download response (report-{id}.pdf), or 404/500 abort on failure.
    // Key steps:
    // 1. Resolves absolute path from report->docx_filled_path under storage/app/public
    // 2. Runs soffice --headless --convert-to pdf on that filled DOCX file only
    // 3. Streams the generated PDF to the browser and deletes temp PDF after send
    public function downloadFilledPdf(Report $report)
    {
        $this->extendPythonExecutionTime();

        $relative = $report->docx_filled_path;
        if (!$relative) {
            abort(404, 'Filled DOCX not found.');
        }

        $docxPath = storage_path('app/public/' . ltrim($relative, '/'));
        Log::info('DOCX PDF conversion source', [
            'report_id'        => $report->id,
            'docx_filled_path' => $relative,
            'absolute_path'    => $docxPath,
            'exists'           => is_file($docxPath),
            'size'             => is_file($docxPath) ? filesize($docxPath) : 0,
        ]);

        if (!is_file($docxPath)) {
            abort(404, 'Filled DOCX file not found on disk.');
        }

        $pdfPath = dirname($docxPath) . DIRECTORY_SEPARATOR . pathinfo($docxPath, PATHINFO_FILENAME) . '.pdf';
        // Remove stale PDF so LibreOffice always regenerates from the latest DOCX.
        if (is_file($pdfPath)) {
            @unlink($pdfPath);
        }

        $sofficeBinary = null;
        // Probe common LibreOffice install paths on Windows and PATH.
        foreach (array_filter([
            env('LIBREOFFICE_BIN'),
            'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
            'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
            'soffice',
        ]) as $candidate) {
            if ($candidate === 'soffice') {
                $sofficeBinary = $candidate;
                break;
            }
            if (is_string($candidate) && is_file($candidate)) {
                $sofficeBinary = $candidate;
                break;
            }
        }

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
        clearstatcache();

        // Abort when soffice fails or does not produce the expected PDF beside the DOCX.
        if ($exitCode !== 0 || !is_file($pdfPath)) {
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
        ]);

        ProtocolAuditLogger::recordExported($report, 'PDF');

        return response()->download($pdfPath, 'report-' . $report->id . '.pdf')->deleteFileAfterSend(true);
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
                'approver_role'           => isset($field['approver_role']) ? (string) $field['approver_role'] : null,
                'signature_date_location' => isset($field['signature_date_location'])
                    ? (string) $field['signature_date_location']
                    : null,
                'prefilled'               => ($field['prefilled'] ?? false) ? true : null,
                'stamp_eligible'          => array_key_exists('stamp_eligible', $field)
                    ? (bool) $field['stamp_eligible']
                    : null,
            ], fn ($v) => $v !== null);
        }

        $normalized = $this->lockPrefilledImportFields($normalized);

        return array_values($normalized);
    }

    /**
     * Apply import-source-specific field rules without changing the shared default path.
     *
     * Revalidation / requalification keep existing behaviour; NEQ fill gets its own unlock rules.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function applySourceSpecificImportFieldRules(array $fields, string $importSource): array
    {
        if ($importSource === 'new_equipment_qualification') {
            return $this->applyNeqFillFieldRules($fields);
        }
        if (in_array($importSource, ['requalification', 'revalidation'], true)) {
            return $this->applyRequalificationPreApprovalUnlockRules($fields);
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

            // Never unlock table header rows (row 0)
            if (preg_match('/^table_\d+_row_0(_col_\d+)?$/', $location)) {
                $fields[$idx]['locked'] = true;
                continue;
            }

            // Sl. No column stays locked
            if ($type === 'table_sl_no') {
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
                continue;
            }

            // All content paragraphs, blanks, table cells, and 2-col labels are editable!
            $fields[$idx]['locked'] = false;
            $unlocked++;
        }

        Log::info('Requalification/Revalidation pre-approval unlock rules applied', [
            'unlocked_fields' => $unlocked,
            'total_fields' => count($fields),
        ]);

        return $fields;
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
     * - 2-column description/details on the right (Col 1) -> locked = false
     * - Multi-column execution entry columns (Observation, Done By, Date, Result, Remarks, Attachment) -> locked = false
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function applyRequalificationPostPreApprovalLockRules(array $fields): array
    {
        $fields = $this->lockConnectedAccessoriesDescriptions($fields);
        $fields = $this->lockExistingTableContent($fields);

        $lockedCount = 0;
        $unlockedCount = 0;

        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }

            $type = strtolower(trim((string) ($field['type'] ?? '')));
            $location = strtolower(trim((string) ($field['location'] ?? '')));

            // 1. Table row 0 headers always locked
            if (preg_match('/^table_\d+_row_0(_col_\d+)?$/', $location)) {
                $fields[$idx]['locked'] = true;
                $lockedCount++;
                continue;
            }

            // 2. Serial number column always locked
            if ($type === 'table_sl_no') {
                $fields[$idx]['locked'] = true;
                $lockedCount++;
                continue;
            }

            // 3. Approval stamps managed by e-sign workflow
            if (str_starts_with($type, 'approval_stamp')) {
                continue;
            }

            // 4. All paragraphs MUST BE LOCKED post-pre-approval (Objective, Scope, Responsibilities, etc.)
            if ($type === 'paragraph_content' || str_starts_with($location, 'para_') || str_starts_with($type, 'para')) {
                if ($type !== 'paragraph_blank_part') {
                    $fields[$idx]['locked'] = true;
                    $lockedCount++;
                    continue;
                }
            }

            // 5. 2-column table keys (Col 0) MUST BE LOCKED: Tag. No., Plant / Module, MOC, etc.
            if ($type === 'table_2col_label' || str_ends_with($location, '_col_0')) {
                $fields[$idx]['locked'] = true;
                $lockedCount++;
                continue;
            }

            // 6. 2-column table fill values (Col 1) MUST BE UNLOCKED: only fill bus ho!
            if ($type === 'table_2col' || str_ends_with($location, '_col_1') || preg_match('/^table_\d+_row_\d+$/', $location)) {
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

        return $fields;
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
            if (in_array($type, ['toc_entry', 'table_1col', 'table_sl_no'], true)) {
                continue;
            }

            if (($field['prefilled'] ?? false) === true) {
                $fields[$idx]['locked'] = true;
                continue;
            }

            if (($field['stamp_eligible'] ?? true) === false) {
                continue;
            }

            if ($this->isPostApprovalStampField($field)) {
                continue;
            }

            $value = trim((string) ($field['value'] ?? ''));
            if ($value !== '') {
                // Keep pre-approved / already-filled content locked.
                $fields[$idx]['locked'] = true;
                continue;
            }

            if ($this->looksLikeNeqStructuralLabelField($field)) {
                continue;
            }

            if (($field['locked'] ?? false) === true) {
                $fields[$idx]['locked'] = false;
                $unlocked++;
            }
        }

        if ($unlocked > 0) {
            Log::info('NEQ fill field rules applied', [
                'unlocked_empty_fields' => $unlocked,
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

        // Common fixed labels / section headers that should stay non-editable.
        if (preg_match('/^(sr\.?\s*no\.?|s\.?\s*no\.?|sl\.?\s*no\.?|#|page|section)$/i', $label)) {
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
            foreach ($existing as $ef) {
                if (is_array($ef) && ! empty($ef['location'])) {
                    $existingByLoc[$ef['location']] = $ef;
                }
            }
            foreach ($newFields as $idx => $nf) {
                $loc = $nf['location'] ?? '';
                if (isset($existingByLoc[$loc])) {
                    $prev = $existingByLoc[$loc];
                    if (($prev['value'] ?? '') !== '') {
                        $newFields[$idx]['value'] = $prev['value'];
                    }
                    if (isset($prev['approver_role'])) {
                        $newFields[$idx]['approver_role'] = $prev['approver_role'];
                    }
                }
            }

            return $this->applySourceSpecificImportFieldRules($newFields, (string) $report->import_source);
        }

        return null;
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
        $fieldsHash = md5(json_encode(array_values($fields), JSON_UNESCAPED_UNICODE));
        $cacheVer   = $this->docxPreviewCacheVersion($fileId, $reportId);
        $cacheKey   = $reportId
            ? "docx_preview:v63:{$mode}:{$fileId}:{$reportId}:{$cacheVer}:{$fieldsHash}"
            : "docx_preview:v63:{$mode}:{$fileId}:{$cacheVer}:{$fieldsHash}";
        $cached     = Cache::get($cacheKey);

        // Return cached HTML when fields and mode have not changed since last preview.
        if (is_string($cached) && strlen(trim($cached)) > 0) {
            return $cached;
        }

        if ($reportId !== null) {
            $report = Report::find($reportId);
            if ($report) {
                $this->ensureDocxTemplateOnDisk($report);
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
        if (! in_array($report->import_source, ['requalification', 'revalidation'], true) || $fields === []) {
            return $fields;
        }

        $data = $report->data ?? [];
        if (! is_array($data)) {
            return $fields;
        }

        $byTable = [];
        foreach ($fields as $idx => $field) {
            if (! is_array($field) || (string) ($field['type'] ?? '') !== 'approver') {
                continue;
            }
            if (! preg_match('/^table_(\d+)_row_(\d+)_col_(\d+)$/', (string) ($field['location'] ?? ''), $match)) {
                continue;
            }
            $byTable[(int) $match[1]][] = [
                'idx' => $idx,
                'row' => (int) $match[2],
            ];
        }
        if ($byTable === []) {
            return $fields;
        }

        ksort($byTable);
        $approvedAt = $this->preApprovalDatesByRole($report, $data);
        $lockApproved = $this->requalificationPreApprovalIsComplete($report);
        $checkedRoles = [
            'checked_by_engineering_name',
            'checked_by_production_name',
            'checked_by_validation_name',
            'checked_by_quality_name',
        ];
        $approvedRoles = [
            'approved_by_user_name',
            'approved_by_quality_name',
        ];

        foreach (array_slice(array_keys($byTable), 0, 3) as $tableNum) {
            $cells = $byTable[$tableNum];
            usort($cells, static function (array $a, array $b) {
                return $a['row'] <=> $b['row'];
            });
            $count = count($cells);
            if ($count === 1) {
                $this->writePreApproverRow(
                    $fields,
                    $cells[0]['idx'],
                    $data,
                    'prepared_by_engineering_name',
                    $approvedAt['prepared_by_engineering_name'] ?? null,
                    $lockApproved
                );
                continue;
            }
            if ($count >= 4) {
                foreach (array_slice($cells, 0, 4) as $offset => $cell) {
                    $role = $checkedRoles[$offset];
                    $this->writePreApproverRow($fields, $cell['idx'], $data, $role, $approvedAt[$role] ?? null, $lockApproved);
                }
                continue;
            }
            if ($count === 2) {
                foreach ($cells as $offset => $cell) {
                    $role = $approvedRoles[$offset];
                    $this->writePreApproverRow($fields, $cell['idx'], $data, $role, $approvedAt[$role] ?? null, $lockApproved);
                }
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
            $date = trim((string) ($data[$prefix . '_date'] ?? ''));
            if ($date === '') {
                $date = $step->approved_at->timezone('Asia/Kolkata')->format('d-m-Y h:i A');
            }
            $dates[$role] = $date;
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

        $current = trim((string) ($fields[$idx]['value'] ?? ''));
        if ($lockApproved || $current === '' || in_array($current, ['—', '-', '–'], true)) {
            $fields[$idx]['value'] = $name;
        }
        if ($lockApproved) {
            $this->lockPreApprovalCell($fields[$idx]);
        }

        if ($approvedDate === null || $approvedDate === '') {
            return;
        }

        $loc = (string) ($fields[$idx]['location'] ?? '');
        if (! preg_match('/^(table_\d+_row_\d+)_col_\d+$/', $loc, $match)) {
            return;
        }
        $signature = $this->signatureLabelFromDisplayName($name);
        $combined = trim($signature . '  ' . $approvedDate);
        if ($combined === '') {
            return;
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
            $existing = trim((string) ($field['value'] ?? ''));
            if ($existing === '' || in_array($existing, ['—', '-', '–'], true)) {
                $fields[$fieldIndex]['value'] = $combined;
            }
            if ($lockApproved) {
                $this->lockPreApprovalCell($fields[$fieldIndex]);
            }
        }
    }

    private function requalificationPreApprovalIsComplete(Report $report): bool
    {
        return in_array($report->import_source, ['requalification', 'revalidation'], true)
            && in_array($report->status, ['pre_approved', 'post_pending', 'post_returned', 'approved'], true);
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
        $stored = trim((string) ($data[$prefix . '_name_text'] ?? ''));
        if ($stored !== '') {
            return $stored;
        }

        $userId = $data[$roleKey] ?? null;
        if (! is_numeric($userId)) {
            return '';
        }

        $user = User::find((int) $userId);
        if (! $user) {
            return '';
        }

        $fullName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
        if ($fullName === '') {
            $fullName = trim((string) ($user->name ?? ''));
        }
        $designation = trim((string) ($user->designation ?? ''));
        if ($fullName === '') {
            return '';
        }

        return $designation !== '' ? $fullName . ' - ' . $designation : $fullName;
    }

    private function applyReportPrefills(Report $report, array $fields): array
    {
        $effectiveDateForPreview = $this->resolveEffectiveDate($report, $fields);
        if ($effectiveDateForPreview === '') {
            $effectiveDateForPreview = now()->timezone('Asia/Kolkata')->format('Y-m-d');
        }

        $labelPrefill = [
            'name of equipment'              => $report->equipment_name ?? '',
            'equipment identification no.'   => $report->equipment_id ?? '',
            'equipment location'             => $report->facility_section ?? '',
        ];

        $locationPrefill = [
            'header_0_row_4_col_2' => $this->resolveProtocolDocumentNumber($report, $fields),
            'header_ref_protocol'  => $this->resolveReferenceProtocolNo($report, $fields),
            'header_0_row_4_col_4' => $report->version ?? '',
        ];

        return array_map(function (array $field) use ($labelPrefill, $locationPrefill, $report, $fields, $effectiveDateForPreview) {
            $location = (string) ($field['location'] ?? '');
            // Revision-history dates are their own cells (13/01/17, 22/04/22). Do not
            // stamp the protocol effective date onto them.
            if (
                $this->isEffectiveDateLikeField($field)
                && $effectiveDateForPreview !== ''
                && ! $this->isRevisionHistoryEffectiveDateLocation($fields, $location)
            ) {
                $field['value'] = $effectiveDateForPreview;
                $field['widget'] = 'date';
                $field['cover_field'] = $field['cover_field'] ?? 'effective_date';
            }

            // Never overwrite locked or prefilled Word values with report metadata.
            if ($this->isDocxFieldLocked($field)) {
                return $field;
            }

            $location = $field['location'] ?? '';
            $label    = strtolower(trim((string) ($field['label'] ?? '')));

            $prefill = $locationPrefill[$location] ?? $labelPrefill[$label] ?? null;

            $coverKey = (string) ($field['cover_field'] ?? '');
            if ($coverKey !== '') {
                $coverSources = [
                    'equipment_name'        => $report->equipment_name ?? '',
                    'equipment_id'          => $report->equipment_id ?? '',
                    'facility_section'      => $report->facility_section ?? '',
                    'document_no'           => $this->resolveProtocolDocumentNumber($report, $fields),
                    'document_title'        => $this->resolveDocumentTitle($report, $fields),
                    'reference_protocol_no' => $this->resolveReferenceProtocolNo($report, $fields),
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
                if ($fromCover !== null && $fromCover !== '') {
                    $prefill = $fromCover;
                }
            }

            if ($prefill !== null && $prefill !== '') {
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

    // Central lock check: serial rows and explicit locked flags block user edits.
    private function isDocxFieldLocked(array $field): bool
    {
        if ((string) ($field['type'] ?? '') === 'table_sl_no') {
            return true;
        }

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

    // Force TOC fields locked; never overwrite real cell values with column labels.
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

            $fields[$idx]['locked'] = true;
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
            $label = trim((string) ($field['label'] ?? ''));
            if (! $this->isLockedSectionHeadingText($label)) {
                continue;
            }
            $fields[$idx]['locked'] = true;
            $fields[$idx]['type'] = 'section_heading';
            $fields[$idx]['value'] = '';
        }

        return $this->unlockSummaryAndConclusionField(
            $this->unlockVerificationPAndIdField($fields)
        );
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
        if (preg_match('/_{3,}|\.{3,}|…/', $text) === 1) {
            return false;
        }

        foreach (['responsibilities of different department'] as $longPrefix) {
            if ($key === $longPrefix || str_starts_with($key, $longPrefix . ' ') || str_starts_with($key, $longPrefix . '/') || str_starts_with($key, $longPrefix . '(')) {
                return true;
            }
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
        ];
        if (in_array($key, $exact, true)) {
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
            'requalification checks',
            'installation re-qualification checks',
            'general checks',
            'operational qualification checks',
            'list of attachment',
            'revision history',
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
<table class="docx-table header-table" style="margin-bottom:0;">
<tr>
<td rowspan="3" style="width:100px;text-align:center;vertical-align:middle;border:1px solid #000;padding:8px;">
<img src="/storage/lupin.png" alt="Lupin" style="height:50px;width:auto;" onerror="this.style.display='none'">
<div style="font-size:11px;font-weight:bold;margin-top:4px;">LUPIN</div>
</td>
<td colspan="4" style="text-align:center;font-weight:bold;font-size:15px;border:1px solid #000;padding:8px;background:#f8f9fa;">{$titleUpper}</td>
</tr>
<tr>
<td style="border:1px solid #000;padding:6px;"><strong>Title:</strong> {$title}</td>
<td style="border:1px solid #000;padding:6px;background:#F9F9F9;" data-field="header_0_row_4_col_2"><strong>Document Number:</strong> {$this->readonlyDisplay($documentNo)}</td>
<td style="border:1px solid #000;padding:6px;background:#F9F9F9;" data-field="header_0_row_2_col_4"><strong>Equipment Name:</strong> {$this->readonlyDisplay($equipmentName)}</td>
<td style="border:1px solid #000;padding:6px;background:#F9F9F9;" data-field="header_0_row_3_col_4"><strong>Equipment ID:</strong> {$this->readonlyDisplay($equipmentId)}</td>
</tr>
<tr>
<td colspan="4" style="border:1px solid #000;padding:6px;background:#F9F9F9;" data-field="header_ref_protocol"><strong>Reference Protocol No:</strong> {$this->readonlyDisplay($refProtocol)}</td>
</tr>
</table>
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
        return $this->convertPreviewHtmlToReadonly($html, $report);
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

            // Drop empty field inputs so readonly view does not show blank controls.
            if ($value === '' && str_starts_with($name, 'fields[')) {
                $input->parentNode->removeChild($input);
                continue;
            }

            $span = $doc->createElement('span');
            $span->setAttribute('class', 'docx-readonly-value');
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
            $select->parentNode->replaceChild($span, $select);
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
