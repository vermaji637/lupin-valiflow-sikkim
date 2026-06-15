<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Equipment;
use App\Models\Report;
use App\Models\ReportApprovalStep;
use App\Models\Template;
use App\Models\User;
use App\Notifications\ReportApprovalAssigned;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class DocxImportController extends Controller
{
    /**
     * createFromProtocol() — Show standalone VIVA DOCX import form from All Protocol list.
     *
     * Skips PRE approval (document is already pre-approved in VIVA).
     *
     * @return \Illuminate\View\View
     */
    public function createFromProtocol()
    {
        $documents = $this->getCompletedDocuments();

        return view('reports.docx_import.protocol_create', compact('documents'));
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
            'equipment_name'   => ['nullable', 'string', 'max:255'],
            'equipment_id'     => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $file         = $request->file('docx_file');
            $fileContents = file_get_contents($file->getRealPath());
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
            $fields = $this->normalizeImportFields($result['fields'] ?? []);

            Log::info('Fields count: ' . count($fields), [
                'file_id' => $fileId,
                'summary' => $result['summary'] ?? null,
            ]);

            if (!$fileId || empty($fields)) {
                return redirect()->back()->with('error', 'No fillable fields were detected in the uploaded DOCX.');
            }

            $template = $this->resolveDocxImportTemplate();
            $document = null;

            if ($request->filled('document_id')) {
                $document = Document::with(['facility', 'equipment'])->find($request->document_id);
            }

            $report = Report::create([
                'template_id'        => $template->id,
                'templatedata'       => [],
                'data'               => [],
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
                'stage'              => 'post',
                'status'             => 'post_pending',
                'current_step_order' => 0,
                'created_by'         => auth()->id(),
                'docx_import_path'   => $fileId,
                'docx_import_fields' => $fields,
                'docx_import_status' => 'pending',
                'docx_filled_path'   => null,
            ]);

            $report->refresh();

            Log::info('Fields saved to report', [
                'report_id'        => $report->id,
                'saved_field_count'=> is_array($report->docx_import_fields)
                    ? count($report->docx_import_fields)
                    : 0,
            ]);

            $prefilledFields = $this->applyReportPrefills($report, $fields);
            $this->requestPreviewHtml($fileId, $pythonUrl, $prefilledFields, $report->id);

            Log::info('Redirecting to fill form', [
                'report_id' => $report->id,
                'route'     => 'reports.docx-import.fill-form',
            ]);

            return redirect()
                ->route('reports.docx-import.fill-form', $report)
                ->with('status', 'DOCX imported. Fill blank fields and submit for POST approval.');

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
    public function buildShowPreviewHtml(Report $report): string
    {
        $this->extendPythonExecutionTime();

        $report->loadMissing('approvalSteps');
        $stampedFields = $this->fieldsWithApprovalStamps($report);

        $saved   = trim((string) ($report->rendered_html ?? ''));
        $savedOk = $saved !== '' && str_contains($saved, 'docx-table')
            && str_contains($saved, 'border:1px solid #000');

        if ($savedOk) {
            $saved = $this->injectDocxFieldValuesIntoHtml($saved, $stampedFields, $report);
            $saved = $this->enrichReadonlyPreviewForShow($saved, $report);
            $saved = $this->applyAttachmentLinksToPreviewHtml($saved, $report);

            return $saved;
        }

        $snapshot = $this->generateReadonlyDocxSnapshot($report);
        if ($snapshot === '') {
            return '';
        }

        $enriched = $this->enrichReadonlyPreviewForShow($snapshot, $report);
        $enriched = $this->injectDocxFieldValuesIntoHtml($enriched, $stampedFields, $report);
        $enriched = $this->applyAttachmentLinksToPreviewHtml($enriched, $report);

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
    private function generateReadonlyDocxSnapshot(Report $report): string
    {
        $fileId = $report->docx_import_path;
        if (! $fileId) {
            return '';
        }

        $fields = $report->docx_import_fields ?? [];
        if ($report->docx_import_status !== 'completed') {
            $fields = $this->applyReportPrefills($report, $fields);
        }

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
    public function refreshDocxAfterApproval(Report $report, ?string $prefix, string $decision = 'Approved'): void
    {
        if ($prefix === null || $prefix === '') {
            return;
        }

        $fileId = $report->docx_import_path;
        if (! $fileId || $report->docx_import_status !== 'completed') {
            return;
        }

        $this->extendPythonExecutionTime();

        $data = $report->data ?? [];
        if (is_string($data)) {
            $data = json_decode($data, true) ?? [];
        }
        if (! is_array($data)) {
            $data = [];
        }

        $roleKey   = $prefix . '_name';
        $name      = trim((string) ($data[$prefix . '_name_text'] ?? ''));
        $date      = trim((string) ($data[$prefix . '_date'] ?? ''));
        $signature = $name !== '' ? $name : trim((string) ($data[$prefix . '_signature'] ?? $decision));

        $fields = $report->docx_import_fields ?? [];
        if (! is_array($fields) || $fields === []) {
            return;
        }

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

        foreach ($fields as $i => $field) {
            if (! is_array($field)) {
                continue;
            }
            if ((string) ($field['approver_role'] ?? '') !== $roleKey) {
                continue;
            }

            $type = (string) ($field['type'] ?? '');
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
                $signature
            );
        }

        $attachments = $this->regenerateFilledDocxAttachment($report, $fields);

        $pythonUrl    = env('PYTHON_NLP_URL', 'http://127.0.0.1:8001');
        $renderedHtml = (string) ($report->rendered_html ?? '');
        $readonlyBody = $this->requestPreviewHtml($fileId, $pythonUrl, $fields, $report->id, 'readonly');

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
     * @return array<int, array<string, mixed>>
     */
    private function fieldsWithApprovalStamps(Report $report): array
    {
        $fields = $report->docx_import_fields ?? [];
        if (! is_array($fields) || $fields === []) {
            return [];
        }

        $fields = $this->upgradeSignatureColumnDisplayNames(
            $this->upgradeSignatureApproverRoles(
                $this->upgradeApprovalPageFieldMetadata($fields)
            )
        );

        $data = $report->data ?? [];
        if (! is_array($data)) {
            $data = [];
        }

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

        $labelToPrefix = [
            'POST_PREPARED'    => 'post_prepared_by',
            'POST_ENGINEERING' => 'post_checked_by_engineering',
            'POST_PRODUCTION'  => 'post_checked_by_production',
            'POST_VALIDATION'  => 'post_checked_by_validation',
            'POST_APPROVED_QA' => 'post_approved_by_quality',
        ];

        foreach ($report->approvalSteps as $step) {
            if ($step->stage !== 'post' || $step->approved_at === null) {
                continue;
            }

            $prefix = $labelToPrefix[$step->label] ?? null;
            if ($prefix === null) {
                continue;
            }

            $name = trim((string) ($data[$prefix . '_name_text'] ?? ''));
            $date = trim((string) ($data[$prefix . '_date'] ?? ''));
            $sig  = $name !== '' ? $name : trim((string) ($data[$prefix . '_signature'] ?? 'Approved'));
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

                $type = (string) ($field['type'] ?? '');
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
                    $sig
                );
            }
        }

        return $fields;
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
        'post_checked_by_validation_name',
        'post_approved_by_quality_name',
    ];

    /** @var array<string, string> */
    private const POST_APPROVER_STEP_LABELS = [
        'post_prepared_by_name'            => 'POST_PREPARED',
        'post_checked_by_engineering_name' => 'POST_ENGINEERING',
        'post_checked_by_production_name'  => 'POST_PRODUCTION',
        'post_checked_by_validation_name'  => 'POST_VALIDATION',
        'post_approved_by_quality_name'    => 'POST_APPROVED_QA',
    ];

    /**
     * POST approver roles that exist in this report's parsed docx_import_fields.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return list<string>
     */
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

    private function isVerifiedBySignRowText(string $text): bool
    {
        return str_contains($text, 'verified by');
    }

    /**
     * @param  array<int, array{idx: int, value: string}>  $cols
     */
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
    private function upgradeProtocolCoverFieldMetadata(array $fields): array
    {
        $labelToCover = [
            'document no'        => 'document_no',
            'document title'     => 'document_title',
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
            foreach ($labelToCover as $needle => $key) {
                if (str_contains($label, $needle)) {
                    $fields[$idx]['cover_field'] = $key;
                    $fields[$idx]['type']        = 'protocol_cover';
                    if ($key === 'effective_date') {
                        $fields[$idx]['widget'] = 'date';
                    }
                    break;
                }
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
        if ($effectiveDate === '' && $this->reportHasCoverField($importFields, 'effective_date')) {
            $effectiveDate = now()->timezone('Asia/Kolkata')->format('d-m-Y');
        } else {
            $effectiveDate = $this->formatEffectiveDateForDocx($effectiveDate);
        }

        $sources = [
            'equipment_name'   => (string) ($formFields['equipment_name'] ?? $report->equipment_name ?? ''),
            'equipment_id'     => (string) ($formFields['equipment_id'] ?? $report->equipment_id ?? ''),
            'facility_section' => (string) ($formFields['facility_section'] ?? $report->facility_section ?? ''),
            'effective_date'   => $effectiveDate,
            'document_no'      => (string) ($report->document_no ?? ''),
            'document_title'   => (string) ($formFields['document_title'] ?? ''),
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
            $formFields[$loc] = $sources[$key];
        }

        return $formFields;
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     */
    private function reportHasCoverField(array $fields, string $coverField): bool
    {
        return $this->findCoverFieldLocation($fields, $coverField) !== null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     */
    private function findCoverFieldLocation(array $fields, string $coverField): ?string
    {
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
     * @param  array<string, string>  $coverLoc
     * @return array<string, string>
     */
    private function buildCoverHeaderMap(array $coverLoc): array
    {
        $map = [];
        foreach ([
            'facility_section' => 'facility_section',
            'equipment_name'   => 'equipment_name',
            'equipment_id'     => 'equipment_id',
            'effective_date'   => 'effective_date',
            'document_title'   => 'document_title',
            'document_no'      => 'document_no',
        ] as $formKey => $coverKey) {
            if (! empty($coverLoc[$coverKey])) {
                $map[$formKey] = $coverLoc[$coverKey];
            }
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

        foreach (array_keys($formFields) as $key) {
            if (! is_string($key)) {
                continue;
            }
            if (str_starts_with($key, 'header_') && ! in_array($key, $valid, true)) {
                unset($formFields[$key]);
            }
            if ($key === 'header_ref_protocol' && ! in_array($key, $valid, true)) {
                unset($formFields[$key]);
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
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<string, string>
     */
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
    private function normalizeEffectiveDateForForm(string $date): string
    {
        $date = trim($date);
        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $date, $m)) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }

        return $date;
    }

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
    private function ensureEffectiveDateCoverFieldValues(Report $report, array $fields): array
    {
        $upgraded = $this->upgradeProtocolCoverFieldMetadata($fields);
        $loc      = $this->findCoverFieldLocation($upgraded, 'effective_date');
        if ($loc === null) {
            return $fields;
        }

        $data = $report->data ?? [];
        if (is_string($data)) {
            $data = json_decode($data, true) ?? [];
        }

        $date = '';
        if (is_array($data) && ! empty($data['effective_date'])) {
            $date = $this->formatEffectiveDateForDocx((string) $data['effective_date']);
        }
        if ($date === '') {
            $date = now()->timezone('Asia/Kolkata')->format('d-m-Y');
        }

        $found = false;
        foreach ($fields as $idx => $field) {
            if (! is_array($field) || ($field['location'] ?? '') !== $loc) {
                continue;
            }
            $found = true;
            if (trim((string) ($field['value'] ?? '')) === '') {
                $fields[$idx]['value'] = $date;
            }
            break;
        }

        if (! $found) {
            $fields[] = [
                'label'       => 'Effective Date',
                'location'    => $loc,
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
    private function upgradeSignatureApproverRoles(array $fields): array
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

            if (str_contains($title, 'approved by')) {
                $this->assignApproverRoleIfEmpty($fields, $rows, 1, 'post_checked_by_validation_name');
                $this->assignApproverRoleIfEmpty($fields, $rows, 2, 'post_approved_by_quality_name');
                continue;
            }

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

        return $fields;
    }

    /**
     * @param  array<int, array<int, array{idx: int, value: string, type: string, role: string}>>  $rows
     * @return array<int, array{idx: int, value: string, type: string, role: string}>
     */
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
    private function upgradeApprovalPageFieldMetadata(array $fields): array
    {
        if (! $this->detectApprovalPageFromLegacyFields($fields)) {
            return $this->repairMissingApprovalPagePerformerRows(
                $this->repairMissingApprovalPageStampRows($fields)
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
            $fields[$idx]['approver_role'] = $roleByRow[$row] ?? null;
        }

        return $this->repairMissingApprovalPagePerformerRows(
            $this->repairMissingApprovalPageStampRows($fields)
        );
    }

    /**
     * Inject editable Performed/Recorded/Complied By dropdown on approval-page row 1.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
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
    private function approvalPageStampRowComplete(array $rows, int $row): bool
    {
        return isset($rows[$row][1], $rows[$row][2], $rows[$row][3]);
    }

    /**
     * Write auto-captured approver name/date into APPROVAL PAGE Name/Signature/Date cells.
     *
     * @param  array<int, array<string, mixed>>  $fields
     */
    private function applyApprovalPageStampToFields(
        array &$fields,
        string $roleKey,
        string $name,
        string $date
    ): void {
        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }
            if ((string) ($field['approver_role'] ?? '') !== $roleKey) {
                continue;
            }

            $type = (string) ($field['type'] ?? '');
            if ($type === 'approval_stamp_name') {
                $fields[$idx]['value'] = $name;
            } elseif ($type === 'approval_stamp_signature') {
                $fields[$idx]['value'] = $name;
            } elseif ($type === 'approval_stamp_date') {
                $fields[$idx]['value'] = $date;
            } elseif ($type === 'approver') {
                $fields[$idx]['value'] = $name;
            }
        }
    }

    private function applyApprovalStampToSignatureRow(
        array &$fields,
        array $indexByLocation,
        string $approverLocation,
        string $name,
        string $date,
        string $signature
    ): void {
        if ($approverLocation === '' || ! preg_match('/^(table_\d+_row_\d+)_col_\d+$/', $approverLocation, $m)) {
            return;
        }

        $rowKey = $m[1];

        if ($name !== '' && isset($indexByLocation[$approverLocation])) {
            $fields[$indexByLocation[$approverLocation]]['value'] = $name;
        }

        $combined = trim($name);
        if ($date !== '') {
            $combined = $combined !== '' ? $combined . '  ' . $date : $date;
        }

        foreach ($fields as $idx => $field) {
            if (! is_array($field)) {
                continue;
            }

            $loc = (string) ($field['location'] ?? '');
            if (! str_starts_with($loc, $rowKey . '_col_')) {
                continue;
            }

            $label  = strtolower((string) ($field['label'] ?? ''));
            $widget = strtolower((string) ($field['widget'] ?? ''));

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
                $sigDisplay = $name !== '' ? $name : $signature;
                if ($sigDisplay !== '') {
                    $fields[$idx]['value'] = $sigDisplay;
                }
            }
        }

        $approverField = $fields[$indexByLocation[$approverLocation]] ?? null;
        $dateLoc       = is_array($approverField) ? ($approverField['signature_date_location'] ?? null) : null;
        if ($dateLoc && isset($indexByLocation[$dateLoc]) && $combined !== '') {
            $fields[$indexByLocation[$dateLoc]]['value'] = $combined;
        }
    }

    /**
     * Replace legacy Approved/Prepared text in Signature columns with the row approver name.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
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
            $nameCol = $cols[0] ?? null;
            if (! is_array($nameCol) || ($nameCol['type'] ?? '') !== 'approver') {
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

                $val = strtolower($col['value']);
                if (in_array($val, $decisionWords, true)) {
                    $fields[$col['idx']]['value'] = $nameVal;
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
    private function regenerateFilledDocxAttachment(Report $report, array $fields): ?array
    {
        $fileId = $report->docx_import_path;
        if (! $fileId) {
            return null;
        }

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
            if ((string) ($field['type'] ?? '') === 'table_sl_no') {
                continue;
            }
            $fieldsToPython[] = [
                'location' => $location,
                'value'    => (string) ($field['value'] ?? ''),
                'locked'   => (bool) ($field['locked'] ?? false),
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
                ]);

            if (! $response->successful()) {
                Log::warning('DOCX approval stamp: Python /fill failed', ['body' => $response->body()]);

                return null;
            }

            $outputFilename = $response->json('output_filename');
            if (! $outputFilename) {
                return null;
            }

            $relative     = 'generated_reports/' . $outputFilename;
            $absolutePath = storage_path('app/public/' . $relative);
            if (! is_file($absolutePath)) {
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
            $attachments = array_values(array_filter(
                is_array($attachments) ? $attachments : [],
                fn ($att) => ! is_array($att) || (int) ($att['index'] ?? 0) !== 99
            ));
            $attachments[] = $attachment;

            return [
                'attachments'      => array_values($attachments),
                'docx_filled_path' => $relative,
            ];
        } catch (\Throwable $e) {
            Log::error('DOCX approval stamp regenerate error: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Show DOCX upload form page.
     */
    public function showUploadForm(Report $report)
    {
        return view('reports.docx_import.upload', compact('report'));
    }

    /**
     * Upload DOCX template file to Python /parse.
     */
    // ── upload() ──────────────────────────────────────────
    // Handles DOCX file upload from the report show page modal.
    // Parameters: Request $request (expects docx_file upload), Report $report (route model).
    // Returns: Redirect to fill form on success, or back with error flash message.
    // Key steps:
    // 1. Validates file (.doc/.docx extension, max 10MB)
    // 2. Sends file contents to Python /parse endpoint to detect fields
    // 3. Normalizes parsed fields and saves file_id + fields on the report record
    // 4. Verifies fields persisted to the database
    // 5. Warms the preview cache for faster fill form load
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

            $pythonUrl = env('PYTHON_NLP_URL', 'http://127.0.0.1:8001');

            Log::info('DOCX import calling Python /parse', [
                'report_id'  => $report->id,
                'python_url' => $pythonUrl,
            ]);

            $response = Http::timeout(90)
                ->attach('file', $fileContents, $file->getClientOriginalName())
                ->post("{$pythonUrl}/parse");

            Log::info('DOCX import Python /parse response', [
                'report_id' => $report->id,
                'status'    => $response->status(),
                'successful'=> $response->successful(),
                'body_snippet' => mb_substr($response->body(), 0, 500),
            ]);

            if (!$response->successful()) {
                Log::error('Python /parse failed: ' . $response->body());
                return redirect()->back()->with('error', 'Failed to parse DOCX file: ' . $response->body());
            }

            $result = $response->json();
            if (!is_array($result)) {
                Log::error('DOCX import parse returned non-JSON response', [
                    'report_id' => $report->id,
                    'body'      => $response->body(),
                ]);

                return redirect()->back()->with('error', 'Parsing service returned an invalid response.');
            }

            $fileId = $result['file_id'] ?? null;
            $fields = $this->normalizeImportFields($result['fields'] ?? []);

            Log::info('DOCX import parsed fields', [
                'report_id'   => $report->id,
                'file_id'     => $fileId,
                'field_count' => count($fields),
                'summary'     => $result['summary'] ?? null,
            ]);

            if (!$fileId) {
                return redirect()->back()->with('error', 'Parsing succeeded but no file_id was returned from service.');
            }

            if (empty($fields)) {
                Log::warning('DOCX import parsed zero fields', [
                    'report_id' => $report->id,
                    'file_id'   => $fileId,
                ]);

                return redirect()->back()->with('error', 'No fillable fields were detected in the uploaded DOCX.');
            }

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
        $fields          = $this->pruneSpuriousHeaderImportFields($fields, $coverFieldLocations);
        $fields          = $this->applyReportPrefills($report, $fields);
        $fileId          = $report->docx_import_path;
        $pythonUrl       = env('PYTHON_NLP_URL', 'http://127.0.0.1:8001');
        $previewHtml     = $fileId ? $this->requestPreviewHtml($fileId, $pythonUrl, $fields, $report->id) : '';
        if (trim($previewHtml) !== '') {
            $previewHtml = $this->prepareFillFormAttachmentCells($previewHtml, $report);
        }
        $useTablePreview = strlen(trim($previewHtml)) > 0;
        $flatListFields  = collect($fields)
            ->filter(fn (array $field) => !$this->isTocField($field))
            ->values()
            ->all();

        $equipmentName   = $report->equipment_name ?? '';
        $equipmentId     = $report->equipment_id ?? '';
        $facilitySection = $report->facility_section ?? '';
        $documentNo      = $report->document_no ?? '';
        $documentTitleLocation = $this->findCoverFieldLocation($fields, 'document_title');
        $hasDocumentTitleField = $documentTitleLocation !== null;
        $documentTitle   = $this->resolveDocumentTitle($report, $fields);
        $effectiveDate       = $this->resolveEffectiveDate($report, $fields);
        $effectiveDateLocation = $this->findCoverFieldLocation($fields, 'effective_date');
        $hasEffectiveDateField = $effectiveDateLocation !== null;
        $coverFieldLocations = $this->buildCoverFieldLocationMap($fields);
        $showLegacyBladeHeader = ($coverFieldLocations['document_no'] ?? '') === 'header_0_row_4_col_2';
        $equipmentList   = Equipment::query()
            ->orderBy('equipment_name')
            ->get(['id', 'equipment_name', 'equipment_id', 'facility']);
        // Active users only — users.status is tinyint (1 = active, 0 = inactive).
        $users = User::with('roles:id,name')
            ->select('id', 'first_name', 'last_name', 'email')
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
        $documents       = $this->getCompletedDocuments();

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

        return view('reports.docx_import.fill', compact(
            'report',
            'fields',
            'flatListFields',
            'previewHtml',
            'useTablePreview',
            'usesApprovalPage',
            'presentApproverRoles',
            'equipmentName',
            'equipmentId',
            'facilitySection',
            'documentNo',
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
            'documents'
        ));
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

        if (! Hash::check($request->password, auth()->user()->password)) {
            return back()->with('error', 'Wrong E-Sign password.');
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
        $usesApprovalPage     = $this->reportUsesApprovalPageTable($importFields);
        $presentApproverRoles = $this->collectPostApproverRolesFromFields($importFields);
        $approverRules        = $this->buildPostApproverValidationRules($presentApproverRoles);

        $request->validate(array_merge($approverRules, [
            'document_id'      => ['nullable', 'integer', 'exists:documents,id'],
            'version'          => ['nullable', 'string', 'max:255'],
            'facility_section' => ['nullable', 'string', 'max:255'],
            'equipment_name'   => ['nullable', 'string', 'max:255'],
            'equipment_id'     => ['nullable', 'string', 'max:255'],
        ]));

        $formFields = $request->input('fields', []);

        $formFields['equipment_name']   = (string) $request->input('equipment_name', $report->equipment_name ?? '');
        $formFields['equipment_id']     = (string) $request->input('equipment_id', $report->equipment_id ?? '');
        $formFields['facility_section'] = (string) $request->input('facility_section', $report->facility_section ?? '');
        $formFields['document_title']   = (string) $request->input('document_title', $this->resolveDocumentTitle($report, $importFields));

        $coverLoc = $this->buildCoverFieldLocationMap($importFields);
        if (! empty($coverLoc['document_no'])) {
            $docNo = (string) ($request->input('document_id') ?: $report->document_no ?? '');
            if ($docNo !== '') {
                $formFields[$coverLoc['document_no']] = $docNo;
            }
        }

        $hasEffectiveDateField = $this->reportHasCoverField($importFields, 'effective_date');
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
            'post_checked_by_validation_name',
            'post_approved_by_quality_name',
        ]);

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
            $formFields = $this->applyProtocolCoverValues($formFields, $importFields, $report);

            $effLoc = $coverLoc['effective_date'] ?? null;
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

                $isLocked = $this->isDocxFieldLocked($field);

                // NEVER allow locked field values to be changed — always use original VIVA value from DB
                $originalValue = (string) ($field['value'] ?? '');
                $submittedValue = $originalValue;

                if (!$isLocked && array_key_exists($location, $formFields)) {
                    $submittedValue = (string) $formFields[$location];
                }

                $updatedField = array_filter([
                    'label'                   => $field['label'] ?? '',
                    'location'                => $location,
                    'value'                   => $isLocked ? $originalValue : $submittedValue,
                    'locked'                  => $isLocked,
                    'type'                    => $field['type'] ?? '',
                    'widget'                  => $field['widget'] ?? null,
                    'approver_role'           => $field['approver_role'] ?? null,
                    'signature_date_location' => $field['signature_date_location'] ?? null,
                ], fn ($v) => $v !== null);

                $updatedFields[] = $updatedField;

                if ((string) ($field['type'] ?? '') === 'table_sl_no') {
                    continue;
                }

                $fieldsToPython[] = [
                    'location' => $location,
                    'value'    => $isLocked ? $originalValue : $submittedValue,
                    'locked'   => $isLocked,
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

            Log::info('Fields being sent to Python', [
                'count'  => count($fieldsToPython),
                'sample' => array_slice($fieldsToPython, 0, 3),
            ]);

            $pythonUrl = env('PYTHON_NLP_URL', 'http://127.0.0.1:8001');
            $fileId    = $report->docx_import_path;

            if (!$fileId) {
                return redirect()->back()->with('error', 'Import file ID is missing on the report.');
            }

            $templatePath = storage_path('app/public/templates/docx/' . $fileId . '.docx');
            if (!is_file($templatePath)) {
                $templatePath = storage_path('app/public/templates/docx/' . $fileId . '.doc');
            }
            Log::info('DOCX fill template check', [
                'file_id' => $fileId,
                'path'    => $templatePath,
                'exists'  => is_file($templatePath),
            ]);

            if (!is_file($templatePath)) {
                return redirect()->back()->with('error', 'Original DOCX template file not found on disk.');
            }

            $originalFilename = $report->template
                ? basename((string) $report->template->docx_template_path)
                : 'report.docx';

            $attachmentLinks = $this->buildAttachmentLinksMap($report, $uploadedAttachments);

            $response = Http::timeout(90)
                ->asJson()
                ->post("{$pythonUrl}/fill", [
                    'file_id'           => $fileId,
                    'original_filename' => $originalFilename,
                    'fields'            => $fieldsToPython,
                    'attachment_links'  => $this->formatAttachmentLinksForPython($attachmentLinks),
                ]);

            if (!$response->successful()) {
                Log::error('Python /fill failed: ' . $response->body());
                return redirect()->back()->with('error', 'Failed to generate filled DOCX: ' . $response->body());
            }

            $result         = $response->json();
            Log::info('Python response', ['result' => $result]);

            $outputFilename = $result['output_filename'] ?? null;

            if (!$outputFilename) {
                return redirect()->back()->with('error', 'Service did not return an output filename.');
            }

            $relative     = 'generated_reports/' . $outputFilename;
            $absolutePath = storage_path('app/public/' . $relative);

            Log::info('Output path saved', [
                'path'        => $relative,
                'absolute'    => $absolutePath,
                'exists'      => is_file($absolutePath),
                'size'        => is_file($absolutePath) ? filesize($absolutePath) : 0,
            ]);

            if (!is_file($absolutePath)) {
                return redirect()->back()->with('error', 'Filled DOCX was not written to disk.');
            }

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

            if ($first) {
                $approver = User::find($first->user_id);
                if ($approver) {
                    $approver->notify(new ReportApprovalAssigned(
                        report: $report,
                        message: "New POST approval request: Report #{$report->id} (Step {$first->step_order})",
                        url: route('reports.show', $report)
                    ));
                }
            }

            return redirect()->route('protocol')
                ->with('status', 'Submitted for Post Approval! Report #' . $report->id);

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
        if (is_file($pdfPath)) {
            @unlink($pdfPath);
        }

        $sofficeBinary = null;
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

        return response()->download($pdfPath, 'report-' . $report->id . '.pdf')->deleteFileAfterSend(true);
    }

    /**
     * Show import template form.
     */
    public function createTemplate()
    {
        return view('templates.docx_import.create');
    }

    /**
     * Upload template file and parse placeholders.
     */
    public function uploadTemplate(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:doc,docx'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        try {
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
            ], fn ($v) => $v !== null);
        }

        return array_values($normalized);
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
        $cacheKey   = $reportId
            ? "docx_preview:v40:{$mode}:{$fileId}:{$reportId}:{$fieldsHash}"
            : "docx_preview:v40:{$mode}:{$fileId}:{$fieldsHash}";
        $cached     = Cache::get($cacheKey);

        if (is_string($cached) && strlen(trim($cached)) > 0) {
            return $cached;
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

            Log::warning('DOCX preview HTTP error, using flat form', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('DOCX preview exception, using flat form: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
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
    private function applyReportPrefills(Report $report, array $fields): array
    {
        $labelPrefill = [
            'name of equipment'              => $report->equipment_name ?? '',
            'equipment identification no.'   => $report->equipment_id ?? '',
            'equipment location'             => $report->facility_section ?? '',
        ];

        $locationPrefill = [
            'header_0_row_4_col_2' => $report->document_no ?? '',
            'header_0_row_4_col_4' => $report->version ?? '',
        ];

        return array_map(function (array $field) use ($labelPrefill, $locationPrefill, $report, $fields) {
            if ($this->isDocxFieldLocked($field)) {
                return $field;
            }

            $location = $field['location'] ?? '';
            $label    = strtolower(trim((string) ($field['label'] ?? '')));

            $prefill = $locationPrefill[$location] ?? $labelPrefill[$label] ?? null;

            $coverKey = (string) ($field['cover_field'] ?? '');
            if ($coverKey !== '') {
                $coverSources = [
                    'equipment_name'   => $report->equipment_name ?? '',
                    'equipment_id'     => $report->equipment_id ?? '',
                    'facility_section' => $report->facility_section ?? '',
                    'document_no'      => $report->document_no ?? '',
                    'document_title'   => $this->resolveDocumentTitle($report, $fields),
                    'effective_date'   => $this->reportHasCoverField($fields, 'effective_date')
                        ? $this->resolveEffectiveDate($report, $fields)
                        : '',
                ];
                $fromCover = $coverSources[$coverKey] ?? null;
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

    private function isTocField(array $field): bool
    {
        $label = strtolower(trim((string) ($field['label'] ?? '')));
        $type = strtolower(trim((string) ($field['type'] ?? '')));
        $location = strtolower(trim((string) ($field['location'] ?? '')));

        $tocLabels = [
            'sl. no.', 'sl no', 's.no', 's. no.', 'description',
            '1.0', '2.0', '3.0', '4.0', '5.0', '6.0', '7.0',
            'objective', 'methodology',
            'observation / results and evaluation',
            'list of attachment',
            'deviation and corrective action taken (if any)',
            'summary and conclusion', 'report approval',
        ];

        if (in_array($label, $tocLabels, true)) {
            return true;
        }

        if ($type === 'table_1col' || $type === 'toc_entry') {
            return $this->isTocEntryText((string) ($field['label'] ?? ''));
        }

        if (preg_match('/^table_\d+_row_\d+_col_0$/', $location)) {
            return $this->isTocEntryText((string) ($field['label'] ?? ''));
        }

        return false;
    }

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

    private function upgradeTocFieldsLocked(array $fields): array
    {
        foreach ($fields as $idx => $field) {
            if (!$this->isTocField($field)) {
                continue;
            }

            $fields[$idx]['locked'] = true;
            $fields[$idx]['type'] = 'toc_entry';
            $fields[$idx]['value'] = (string) ($field['label'] ?? $field['value'] ?? '');
        }

        return $fields;
    }

    /**
     * Move legacy Comments fields from header row 0 to content row 1.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
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

    private function enrichUserDisplayTextFields(array &$data): void
    {
        $users = User::query()
            ->select(['id', 'first_name', 'last_name', 'email'])
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

    private function formatUserOptionLabel(User $user): string
    {
        $fullName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
        $roleName = method_exists($user, 'roles') ? ($user->roles->pluck('name')->first() ?? '') : '';

        if ($fullName === '') {
            $fullName = 'User #' . $user->id;
        }

        return $roleName !== '' ? $fullName . ' - ' . $roleName : $fullName;
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
    private function applySignatureApproverValues(
        array $formFields,
        array $importFields,
        array $approverData
    ): array {
        $userIds = array_filter($approverData, fn ($v) => $v !== null && $v !== '' && is_numeric($v));
        if ($userIds === []) {
            return $formFields;
        }

        $users = User::with('roles:id,name')
            ->whereIn('id', array_map('intval', $userIds))
            ->get(['id', 'first_name', 'last_name'])
            ->keyBy('id');

        foreach ($importFields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $role = $field['approver_role'] ?? null;
            $location = $field['location'] ?? '';
            if (! $role || $location === '') {
                continue;
            }

            $userId = $approverData[$role] ?? null;
            if ($userId === null || $userId === '' || ! is_numeric($userId)) {
                continue;
            }

            $user = $users->get((int) $userId);
            if ($user) {
                $formFields[$location] = $this->formatUserOptionLabel($user);
            }
        }

        return $formFields;
    }

    /**
     * handleDocxAttachmentUploads() — Persist attachment column uploads on the report.
     *
     * @return array{0: array, 1: array} Updated formFields and new attachment records
     */
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
            $folder = 'reports/docx_attachments/protocol_' . $report->id;
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
     * resolveDocxImportTemplate() — Template record used for VIVA DOCX import reports.
     *
     * Uses only columns that exist on the MSSQL templates table (name, template_type,
     * body_html, created_by) — matches TemplateController@store / uploadTemplate pattern.
     */
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
    private function readonlyDisplay(string $value): string
    {
        $value = trim($value);

        return $value !== '' ? e($value) : '—';
    }

    /**
     * wrapReadonlyPreviewWithHeader() — Prepend Lupin header table (matches fill form layout).
     */
    private function wrapReadonlyPreviewWithHeader(Report $report, string $bodyHtml, array $fields): string
    {
        $report->loadMissing('template');
        $templateName = $report->template->name ?? 'PERIODIC HVAC VALIDATION REPORT';

        $documentNo    = $this->fieldValueByLocation(
            $fields,
            'header_0_row_4_col_2',
            (string) ($report->document_no ?? '')
        );
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
        $refProtocol   = $this->fieldValueByLocation($fields, 'header_ref_protocol', '');

        $titleUpper = e(strtoupper($templateName));
        $title      = e($templateName);

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
     * enrichReadonlyPreviewForShow() — Convert preview inputs to readonly values.
     */
    private function enrichReadonlyPreviewForShow(string $html, Report $report): string
    {
        return $this->convertPreviewHtmlToReadonly($html, $report);
    }

    /**
     * collectAttachmentFieldLocations() — Table cell locations that hold uploaded files.
     *
     * @return array<string, true>
     */
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
                $td->appendChild($doc->createTextNode('—'));
            }
        }
    }

    /**
     * buildAttachmentDownloadLinkElement() — Clickable download link for an uploaded attachment.
     */
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
