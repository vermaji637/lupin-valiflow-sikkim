<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Models\Template;
use App\Models\ReportApprovalStep;
use App\Models\User;
use App\Models\Document;
use App\Services\DocxTemplateService;
use Illuminate\Http\Request;
use Carbon\Carbon;
use ZipArchive;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Notifications\ReportApprovalAssigned;
use Illuminate\Validation\ValidationException;

class ReportController extends Controller
{
    /**
     * safeUtf8() — normalize a string to valid UTF-8 for display and DOM text nodes.
     *
     * @param  mixed  $value  Raw scalar from request, MSSQL JSON, or HTML
     * @return string Clean UTF-8 string
     *
     * Encoding note: prevents MSSQL / HTML-entity / Latin-1 misreads from showing as mojibake
     * (e.g. ÃØÃ) in Name & Designation during approval steps.
     */
    private function safeUtf8(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $value = (string) $value;

        if ($value === '') {
            return '';
        }

        // Recover UTF-8 only when classic mojibake markers are present (e.g. Ã, Â, â€)
        if (preg_match('/Ã|Â|â€|ï¿½/', $value)) {
            $recovered = @mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
            if (is_string($recovered) && mb_check_encoding($recovered, 'UTF-8') && $recovered !== $value) {
                $value = $recovered;
            }
        }

        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $detected = mb_detect_encoding($value, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);

        return $detected
            ? (string) mb_convert_encoding($value, 'UTF-8', $detected)
            : (string) utf8_encode($value);
    }

    /**
     * normalizeReportData() — recursively apply safeUtf8() to all string values in report data.
     *
     * @param  array  $data  Decoded reports.data array
     * @return array Same structure with UTF-8-safe strings
     */
    private function normalizeReportData(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->normalizeReportData($value);
            } elseif (is_string($value)) {
                $data[$key] = $this->safeUtf8($value);
            }
        }

        return $data;
    }

    /**
     * wrapHtmlForDom() — wrap HTML fragment for DOMDocument::loadHTML with UTF-8 charset.
     *
     * @param  string  $html  Partial HTML from rendered_html or template body
     * @return string Full minimal document safe for DOM parsing
     *
     * Encoding note: without <meta charset="utf-8"> DOMDocument misreads UTF-8 bytes as Latin-1,
     * which corrupts Name & Designation text after renderReadOnlyHtml() during approval display.
     */
    private function wrapHtmlForDom(string $html): string
    {
        return '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>';
    }

    // =========================
    // 1) FILL (Create new)
    // =========================
    public function fill(Template $template)
    {
        if ($template->template_type === 'docx') {
            $fields = is_array($template->docx_placeholders) ? $template->docx_placeholders : [];
            $users = $this->getTemplateUsers();
            $documents = $this->getTemplateDocuments();

            return view('reports.fill_docx', compact('template', 'fields', 'users', 'documents'));
        }

        $users = $this->getTemplateUsers();
        $documents = $this->getTemplateDocuments();

        $bodyHtml = $this->hydrateDynamicSelects($template->body_html, $users, $documents, [
            'prepared_by_name' => auth()->id(),
            'prepared_name' => auth()->id(),
            'post_prepared_by_name' => auth()->id(),
            'post_review_prepared_by_name' => auth()->id(),
            'prepared_by_date'                  => now()->format('Y-m-d'), // ✅ NEW
            'effective_date'                    => now()->format('Y-m-d'), // ✅ NEW
        ]);

        // ✅ Process image paths for browser view during creation preview
        $bodyHtml = preg_replace_callback('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', function($m){
            $tag = $m[0];
            $src = $m[1];

            // if logo is storage path, convert to full URL for browser
            if (str_contains($src, '/storage/lupin.png')) {
                // Use request base URL to ensure correct path generation
                $baseUrl = request()->getSchemeAndHttpHost();
                $fullUrl = $baseUrl . '/storage/lupin.png';
                return str_replace($src, $fullUrl, $tag);
            }

            return $tag;
        }, $bodyHtml);

        $requiresPreapprovedFile = $this->requiresPreapprovedDocumentUpload($template);

        return view('reports.fill', compact('template', 'bodyHtml', 'requiresPreapprovedFile'));
    }

    // =========================
    // 2) STORE (Create report + Start PRE)
    // =========================
public function store(Request $request, Template $template)
{
    if ($template->template_type === 'docx') {
        return $this->storeDocxReport($request, $template);
    }

    if ($this->requiresPreapprovedDocumentUpload($template)) {
        $request->validate([
            'preapproved_doc_file' => ['required', 'file', 'mimes:doc,docx,pdf'],
        ]);
    }

    $data = $request->except(['_token']);
    $data['prepared_by_signature'] = $data['prepared_by_signature'] ?? 'Prepared';
    $data['prepared_by_date']      = $data['prepared_by_date'] ?? now()->timezone('Asia/Kolkata')->format('d-m-Y h:i A');

    $preparedName = trim((auth()->user()->first_name ?? '') . ' ' . (auth()->user()->last_name ?? ''));
    if ($preparedName === '') $preparedName = auth()->user()->name ?? '';
    $data['prepared_by_name'] = $data['prepared_by_name'] ?? $preparedName;

    // If pre-approver is not selected in the form, use the approver mapped on selected equipment/document.
    $this->applyDefaultPreApproverFromDocument($data);

    // STEP 1: pehle rendered HTML banao
    $rendered = $this->injectValuesIntoHtml($template->body_html, $data);

    $rendered = preg_replace_callback('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', function($m){
        $tag = $m[0];
        $src = $m[1];
        if (str_contains($src, '/storage/lupin.png')) {
            return str_replace($src, asset('storage/lupin.png'), $tag);
        }
        return $tag;
    }, $rendered);

    $skipPreApproval = $this->shouldSkipPreApproval($data, $template);
    if ($skipPreApproval) {
        $this->normalizePostApproverAssignments($data);
        $this->assertPostApproversPresent($data);
    }
    $this->enrichUserDisplayTextFields($data);
    $initialStage = $skipPreApproval ? 'post' : 'pre';
    $initialStatus = $skipPreApproval ? 'post_pending' : 'pre_pending';

    // STEP 2: report create karo (attachments abhi empty)
    $report = Report::create([
        'template_id'        => (int) $template->id,
        'templatedata'       => $data,
        'rendered_html'      => $rendered,
        'data'               => $data,
        'attachments'        => [],
        'facility_section'   => $data['facility_section'] ?? null,
        'document_no'        => $data['document_id'] ?? ((isset($data['document_no']) && is_numeric($data['document_no'])) ? $data['document_no'] : null),
        'version'            => $data['version'] ?? null,
        'equipment_name'     => $data['equipment_name'] ?? null,
        'equipment_id'       => $data['equipment_id'] ?? null,
        'stage'              => $initialStage,
        'status'             => $initialStatus,
        'current_step_order' => 1,
        'created_by'         => auth()->id(),
    ]);

    // STEP 3: ab report->id milne ke baad files upload karo
    $attachments = $this->handleAttachments($request, $report);
    $preapprovedAttachment = $this->handlePreapprovedDocUpload($request, $report);
    if ($preapprovedAttachment !== null) {
        $attachments[] = $preapprovedAttachment;
    }
    if (!empty($attachments)) {
        $report->update(['attachments' => $attachments]);
    }

    // STEP 4: approval steps
    $this->createStepsForStage($report, $data, $initialStage);

    $first = $report->activeStepQuery()->first();
    $report->current_step_order = $first?->step_order ?? 0;
    if (!$first) {
        $report->status = $skipPreApproval ? 'approved' : 'pre_approved';
    }
    $report->save();

    if ($first) {
        $approver = \App\Models\User::find($first->user_id);
        if ($approver) {
            $this->notifyApprover(
                $approver,
                $report,
                $skipPreApproval
                    ? "New POST approval request: Report #{$report->id} (Step {$first->step_order})"
                    : "New PRE approval request: Report #{$report->id} (Step {$first->step_order})"
            );
        }
    }

    return redirect()->route('reports.show', $report)->with(
        'status',
        $skipPreApproval ? 'Submitted directly for POST approval.' : 'Submitted for PRE approval.'
    );
}

    // =========================
    // 3) SHOW
    // =========================
   public function show(Report $report)
{
    $report->load(['template','approvalSteps']);

    $data = $report->data ?? [];
    if (is_string($data)) $data = json_decode($data, true) ?? [];
    if (!is_array($data)) $data = [];
    $data = $this->normalizeReportData($data);

    $bodyHtml = $report->rendered_html ?: ($report->template->body_html ?? '');

    // Decode HTML entities to prevent double-encoding in approval view
    // This fixes: garbled characters (e.g. ÃØÃ) in Name & Designation during POST approval steps
    $bodyHtml = html_entity_decode((string) $bodyHtml, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    if (($report->template->template_type ?? '') === 'docx' && $this->isLegacyDocxPreviewHtml($bodyHtml)) {
        $bodyHtml = $this->resolveDocxRenderedHtml($report->template, $data);
    }

    // ✅ Process image paths FIRST before any DOM manipulation
    $bodyHtml = preg_replace_callback('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', function($m){
        $tag = $m[0];
        $src = $m[1];

        // if logo is storage path, convert to full URL for browser
        if (str_contains($src, '/storage/lupin.png') || str_contains($src, '/storage/lupin-logo.png')) {
            $target = str_contains($src, 'lupin-logo.png') ? 'storage/lupin-logo.png' : 'storage/lupin.png';
            $fullUrl = asset($target);
            return str_replace($src, $fullUrl, $tag);
        }

        return $tag;
    }, $bodyHtml);

    // ✅ If final approved → fully readonly + lock all
    if ($report->status === 'approved') {

    $bodyHtml = $this->injectApprovalMarks($bodyHtml, $report);
    $bodyHtml = $this->renderReadOnlyHtml($bodyHtml, $data, $report);
    $bodyHtml = $this->lockAllFields($bodyHtml);

} elseif (in_array($report->status, ['pre_pending','post_pending','pre_returned','post_returned'], true)) {

    // Same read-only rendering path as approved — only lockAllFields is skipped while pending
    $bodyHtml = $this->injectApprovalMarks($bodyHtml, $report);
    $bodyHtml = $this->renderReadOnlyHtml($bodyHtml, $data, $report);
}

    // Ensure links remain clickable even if template content has editable wrappers.
    $bodyHtml = $this->disableContentEditable($bodyHtml);

    $currentStep = $report->approvalSteps()
        ->where('stage', $report->stage)
        ->whereNull('approved_at')
        ->whereNull('rejected_at')
        ->orderBy('step_order')
        ->first();

    // ✅ All steps for listing on UI
    $preSteps  = $report->approvalSteps->where('stage','pre')->sortBy('step_order');
    $postSteps = $report->approvalSteps->where('stage','post')->sortBy('step_order');

    // DOCX import reports: show read-only table preview instead of HTML template body
    $docxPreviewHtml = '';
    if ($report->docx_import_path) {
        $docxPreviewHtml = app(DocxImportController::class)->buildShowPreviewHtml($report);
    }

    return view('reports.show', compact('report','bodyHtml','currentStep','preSteps','postSteps','docxPreviewHtml'));
}

private function lockAllFields(string $html): string
{
    libxml_use_internal_errors(true);

    $doc = new \DOMDocument('1.0','UTF-8');
    $doc->loadHTML(
        $this->wrapHtmlForDom($html),
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    $xp = new \DOMXPath($doc);

    // Step 1: sabhi form fields lock karo
    foreach ($xp->query('//input | //textarea | //select') as $node){
        $tag  = strtolower($node->nodeName);
        $type = strtolower($node->getAttribute('type') ?? '');

        $node->setAttribute('disabled', 'disabled');

        if (!($tag === 'input' && in_array($type, ['checkbox','file','radio'], true))) {
            $node->setAttribute('readonly', 'readonly');
        }

        $style = (string)$node->getAttribute('style');
        $node->setAttribute('style', $style . ';background:#f5f5f5;');
    }

    // Step 2: saare <a href> tags ko clickable rakho
    foreach ($xp->query('//a[@href]') as $anchor) {
        $existingStyle = (string)$anchor->getAttribute('style');
        $fixedStyle = preg_replace('/pointer-events\s*:\s*none\s*;?/i', '', $existingStyle);
        $anchor->setAttribute('style', $fixedStyle . '; pointer-events:auto !important; cursor:pointer; position:relative; z-index:100;');
        $anchor->setAttribute('contenteditable', 'false');
        $anchor->removeAttribute('disabled');
        $anchor->removeAttribute('readonly');
    }


    foreach ($xp->query('//a[@href]/ancestor::td | //a[@href]/ancestor::th | //a[@href]/ancestor::div | //a[@href]/ancestor::span') as $parent) {
        $existingStyle = (string)$parent->getAttribute('style');
        if (str_contains($existingStyle, 'pointer-events')) {
            $fixedStyle = preg_replace('/pointer-events\s*:\s*none\s*;?/i', 'pointer-events:auto;', $existingStyle);
            $parent->setAttribute('style', $fixedStyle);
        }
    }

    // In case legacy templates stored editable zones, force them non-editable.
    foreach ($xp->query('//*[@contenteditable]') as $editableNode) {
        $editableNode->setAttribute('contenteditable', 'false');
    }

    $body = $doc->getElementsByTagName('body')->item(0);
    $out = '';
    foreach ($body->childNodes as $child) $out .= $doc->saveHTML($child);
    return $out;
}
    // =========================
    // 4) EDIT AFTER PRE APPROVED
    // =========================
    public function editAfterPreApproved(Report $report)
    {
        //abort_unless($report->stage === 'pre' && $report->status === 'pre_approved', 403);

        $report->load('template');
       // dd($report);

        $data = $report->data ?? [];
        if (is_string($data)) $data = json_decode($data, true) ?? [];
        if (!is_array($data)) $data = [];

        if (($report->template->template_type ?? '') === 'docx') {
            if ($report->docx_import_path) {
                return redirect()->route('reports.docx-import.fill-form', $report);
            }

            $bodyHtml = $report->rendered_html ?: $this->resolveDocxRenderedHtml($report->template, $data);
            if ($this->isLegacyDocxPreviewHtml($bodyHtml)) {
                $bodyHtml = $this->resolveDocxRenderedHtml($report->template, $data);
            }
            $bodyHtml = $this->injectValuesIntoHtml($bodyHtml, $data);

            return view('reports.edit_after_pre', compact('report', 'bodyHtml'));
        }

        $users = $this->getTemplateUsers();
        $documents = $this->getTemplateDocuments();

        // hydrate selects + inject saved values
        $bodyHtml = $this->hydrateDynamicSelects($report->template->body_html, $users, $documents, $data);
        $bodyHtml = $this->injectValuesIntoHtml($bodyHtml, $data);


        // PRE section read-only
        $bodyHtml = $this->renderPreSectionReadOnly($bodyHtml, $data, $report);
         $bodyHtml = $this->disableContentEditable($bodyHtml);

        return view('reports.edit_after_pre', compact('report', 'bodyHtml'));
    }

    // =========================
    // 5) SUBMIT TO POST (User fills after pre approved)
    // =========================
    public function submitToPost(Request $request, Report $report)
    {
        //abort_unless($report->stage === 'pre' && $report->status === 'pre_approved', 403);
//dd($report->id);
        $report->load(['template', 'approvalSteps']);

        // collect new form data
        $newData = $request->except(['_token']);

        // merge with old data (keep PRE values)
        $oldData = $report->data ?? [];
        if (is_string($oldData)) $oldData = json_decode($oldData, true) ?? [];
        if (!is_array($oldData)) $oldData = [];

        $data = array_merge($oldData, $newData);

        // Ensure *_name_text labels exist for read-only approval display
        $this->enrichUserDisplayTextFields($data);
        $data = $this->normalizeReportData($data);

        // save text labels for post selects (so readonly shows name)
        $userMap = $this->getTemplateUsers()->keyBy('id');


        $saveUserText = function (string $field) use (&$data, $userMap) {
            $val = $data[$field] ?? null;
            if ($val === null || $val === '') return;

            if (is_numeric($val)) {
                $u = $userMap->get((int) $val);
                if ($u) $data[$field . '_text'] = $this->formatUserOptionLabel($u);
            } else {
                $data[$field . '_text'] = (string) $val;
            }
        };

        $saveUserText('post_prepared_by_name');
        $saveUserText('post_checked_by_engineering_name');
        $saveUserText('post_checked_by_production_name');
        $saveUserText('post_checked_by_validation_name');
        $saveUserText('post_approved_by_quality_name');

        // attachments
        $attachments = [];
        for ($i = 1; $i <= 9; $i++) {
            if ($request->hasFile("attachment_file_$i")) {
                $file = $request->file("attachment_file_$i");
                  $originalName = $file->getClientOriginalName();
                  $cleanName = str_replace(' ', '_', $file->getClientOriginalName());
                  $newFileName = 'protocol_'.$report->id.'/'.$cleanName;
                   $path = $file->storeAs('reports/attachments', $newFileName, 'public');
               // $path = $file->store('reports/attachments', 'public');


                 $attachments[] = [
            'index'         => $i,
            'name'          => $request->input("attachment_name_$i"),
            'file_path'     => $path,            // saved file path
            'stored_name'   => $newFileName,     // name saved in folder
            'original_name' => $originalName,    // original user file name
            'mime'          => $file->getClientMimeType(),
            'size'          => $file->getSize(),
        ];
            }
        }

        $oldAtt = $report->attachments ?? [];
        if (is_string($oldAtt)) $oldAtt = json_decode($oldAtt, true) ?? [];
        if (!is_array($oldAtt)) $oldAtt = [];

        $attachments = array_values(array_merge($oldAtt, $attachments));

        // render HTML using last rendered base
        $baseHtml = $report->rendered_html ?: ($report->template->body_html ?? '');
        $rendered = $this->injectValuesIntoHtml($baseHtml, $data);

        // update report => POST stage
        $report->update([
            'data'              => $data,
            'rendered_html'      => $rendered,
            'attachments'        => $attachments,
            'stage'              => 'post',
            'status'             => 'post_pending',
            'current_step_order' => 1,
        ]);

        // remove old post steps and recreate from selected users
        $report->approvalSteps()->where('stage', 'post')->delete();

        $steps = $this->buildPostApprovalSteps($data);

        foreach ($steps as $s) {
            $userId = $data[$s['key']] ?? null;
            if (!$userId) continue;

            ReportApprovalStep::create([
                'report_id'  => $report->id,
                'stage'      => 'post',
                'user_id'    => (int) $userId,
                'label'      => $s['label'],
                'step_order' => (int) $s['order'],
            ]);
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
    $approver = \App\Models\User::find($first->user_id);
    if ($approver) {
        $this->notifyApprover(
            $approver,
            $report,
            "New POST approval request: Report #{$report->id} (Step {$first->step_order})"
        );
    }
}

        return redirect()->route('reports.show', $report)->with('status', 'Submitted for Post Approval!');
    }

    // =========================
    // 6) APPROVE (PRE or POST)
    // =========================
    public function approve(Request $request, Report $report)
    {
        if (! Hash::check($request->password, auth()->user()->password)) {
            return back()->with('error', 'Wrong E-Sign password.');
        }

        $report->load('approvalSteps');

        $step = $report->approvalSteps()
            ->where('stage', $report->stage)
            ->whereNull('approved_at')
            ->whereNull('rejected_at')
            ->orderBy('step_order')
            ->first();

        if (!$step || (int) $step->user_id !== (int) auth()->id()) {
            abort(403, 'You are not the current approver.');
        }

        $data = $report->data ?? [];
        if (is_string($data)) $data = json_decode($data, true) ?? [];
        if (!is_array($data)) $data = [];
        $data = $this->normalizeReportData($data);

        $prefix = $this->stepPrefix($report->stage, $step->label);
        if ($prefix) {
            $this->stampDecisionFieldsArray($data, $prefix, 'Approved');
        }

        $step->update([
            'approved_at' => now(),
            'approved_by' => auth()->id(),
        ]);

        $report->update(['data' => $data]);

        // DOCX import: stamp signature cells via Python preview + /fill (not HTML injectValuesIntoHtml).
        if ($report->docx_import_path && $report->docx_import_status === 'completed' && $prefix) {
            app(DocxImportController::class)->refreshDocxAfterApproval($report->fresh(), $prefix, 'Approved');
        } else {
            $baseHtml = $report->rendered_html ?: ($report->template->body_html ?? '');
            $rendered = $this->injectValuesIntoHtml($baseHtml, $data);
            $report->update(['rendered_html' => $rendered]);
        }

        $next = $report->approvalSteps()
            ->where('stage', $report->stage)
            ->whereNull('approved_at')
            ->whereNull('rejected_at')
            ->orderBy('step_order')
            ->first();

        if ($next) {
            $report->update([
                'status'             => $report->stage === 'pre' ? 'pre_pending' : 'post_pending',
                'current_step_order' => $next->step_order,
            ]);
             $approver = \App\Models\User::find($next->user_id);
    if ($approver) {
        $this->notifyApprover(
            $approver,
            $report,
            "Approval required: Report #{$report->id} (Stage: {$report->stage}, Step {$next->step_order})"
        );
    }
        } else {
            if ($report->stage === 'pre') {
                $report->update([
                    'status'             => 'pre_approved',
                    'current_step_order' => 0,
                ]);
            } else {
                $report->update([
                    'status'             => 'approved',
                    'current_step_order' => 0,
                ]);
            }
        }

        return back()->with('success', 'Approved successfully.');
    }

    // =========================
    // 7) REJECT (reset current stage)
    // =========================
    public function reject(Request $request, Report $report)
{
    $request->validate([
        'remark' => 'required|string'
    ]);

    if (! Hash::check($request->password, auth()->user()->password)) {
        return back()->with('error', 'Wrong E-Sign password.');
    }

    $report->load(['template', 'approvalSteps']);

    // 🔹 Find current pending step
    $currentStep = $report->approvalSteps()
        ->where('stage', $report->stage)
        ->whereNull('approved_at')
        ->whereNull('rejected_at')
        ->orderBy('step_order')
        ->first();

    if (!$currentStep || (int)$currentStep->user_id !== (int)auth()->id()) {
        abort(403, 'You are not the current approver.');
    }

    // 🔹 Mark this step rejected (audit)
    $currentStep->update([
        'rejected_at' => now(),
        'rejected_by' => auth()->id(),
        'remark'      => $request->remark,
    ]);

    // 🔹 Load report form data
    $data = $report->data ?? [];
    if (is_string($data)) $data = json_decode($data, true) ?? [];
    if (!is_array($data)) $data = [];

    // 🔹 Reset only signature/date fields in form data
    $this->resetApprovalMarksInData($data, $report->stage);

    // 🔹 Reset approval steps timestamps (DO NOT delete steps)
    $report->approvalSteps()
        ->where('stage', $report->stage)
        ->update([
            'approved_at' => null,
            'approved_by' => null,
            'rejected_at' => null,
            'rejected_by' => null,
            'remark'      => null,
        ]);

    // 🔹 Re-render HTML
    $baseHtml = $report->rendered_html ?: ($report->template->body_html ?? '');
    $rendered = $this->injectValuesIntoHtml($baseHtml, $data);

    // =============================
    // 🔴 IF PRE STAGE REJECTED
    // =============================
    if ($report->stage === 'pre') {

        $report->update([
            'data'               => $data,
            'rendered_html'       => $rendered,
            'stage'              => 'pre',
            'status'             => 'pre_returned',   // 👈 goes to creator
            'current_step_order' => 0,
        ]);

        return redirect()
            ->route('reports.editReturnedPre', $report)
            ->with('error', 'Rejected! Sent back to creator for correction.');
    }

    // =============================
    // 🔴 IF POST STAGE REJECTED
    // =============================
   if ($report->stage === 'post') {

    // Load form data
    $data = $report->data ?? [];
    if (is_string($data)) $data = json_decode($data, true) ?? [];
    if (!is_array($data)) $data = [];

    // 🔹 Reset ONLY signature/date marks in POST section
    $this->resetApprovalMarksInData($data, 'post');

    // 🔹 Reset ALL POST approval steps timestamps (keep same users)
    $report->approvalSteps()
        ->where('stage', 'post')
        ->update([
            'approved_at' => null,
            'approved_by' => null,
            'rejected_at' => null,
            'rejected_by' => null,
            'remark'      => null,
        ]);

    // 🔹 Re-render HTML
    $baseHtml = $report->rendered_html ?: ($report->template->body_html ?? '');
    $rendered = $this->injectValuesIntoHtml($baseHtml, $data);

    // 🔹 Send back to creator
    $report->update([
        'data'               => $data,
        'rendered_html'      => $rendered,
        'stage'              => 'post',
        'status'             => 'post_returned',   // 👈 NEW STATUS
        'current_step_order' => 0,
    ]);
    $creator = \App\Models\User::find($report->created_by);
if ($creator) {
    $this->notifyApprover(
        $creator,
        $report,
        "Report #{$report->id} was rejected in {$report->stage} stage. Please update and resubmit."
    );
}

    if ($report->docx_import_path) {
        return redirect()
            ->route('reports.docx-import.fill-form', $report)
            ->with('error', 'Rejected! Returned to creator. Update the document and resubmit for POST approval.');
    }

    return redirect()
        ->route('reports.editReturnedPost', $report)
        ->with('error', 'Rejected! Returned to creator. Post approval will restart from first approver.');
}

    return back();
}
private function clearAllSignatureDateFromData(array &$data): void
{
    foreach (array_keys($data) as $k) {
        if (str_ends_with($k, '_signature') || str_ends_with($k, '_date')) {
            $data[$k] = '';
        }
    }
}
private function clearStageSignaturesFromData(array &$data, string $stage): void
{
    // only signature/date keys clear, NOT the user selection fields
    $prefixes = ($stage === 'pre')
        ? [
            'checked_by_engineering',
            'checked_by_production',
            'checked_by_validation',
            'approved_by_user',
            'approved_by_quality',
        ]
        : [
            'post_prepared_by',
            'post_checked_by_engineering',
            'post_checked_by_production',
            'post_checked_by_validation',
            'post_approved_by_quality',
        ];

    foreach ($prefixes as $p) {
        unset($data[$p . '_signature']);
        unset($data[$p . '_date']);

        // OPTIONAL: agar aap *_text bhi stamp kar rahe ho (names)
        // to keep it OR remove it — your choice.
        // Agar text keep karna hai => comment these 2 lines.
        // unset($data[$p . '_name_text']);
        // unset($data[$p . '_text']);
    }

    // prepared by signature/date bhi reset karna ho to uncomment:
    // unset($data['prepared_by_signature'], $data['prepared_by_date']);
}

    // =========================
    // 8) DASHBOARDS
    // =========================
     public function myPendingApprovals()
    {
        $reports = Report::query()
            ->whereIn('status', ['pre_pending', 'post_pending'])
            ->whereHas('approvalSteps', function ($q) {
                $q->where('user_id', auth()->id())
                    ->whereNull('approved_at')
                    ->whereNull('rejected_at');
            })
            ->orderByDesc('id')
            ->paginate(20);

        return view('dashboard.pending', compact('reports'));
    }



    // =====================================================
    // HELPERS: create steps (based on TEMPLATE fields)
    // =====================================================
    private function createStepsForStage(Report $report, array $data, string $stage): void
    {
        $steps = $stage === 'pre'
            ? $this->buildPreApprovalSteps()
            : $this->buildPostApprovalSteps($data);

        foreach ($steps as $s) {
            $userId = $data[$s['key']] ?? null;
            if (!$userId) continue;

            ReportApprovalStep::create([
                'report_id'  => $report->id,
                'user_id'    => (int) $userId,
                'label'      => $s['label'],
                'step_order' => (int) $s['order'],
                'stage'      => $stage,
            ]);
        }
    }

    private function buildPreApprovalSteps(): array
    {
        return [
            ['key' => 'checked_by_engineering_name', 'label' => 'ENGINEERING',       'order' => 1],
            ['key' => 'checked_by_production_name',  'label' => 'PRODUCTION',        'order' => 2],
            ['key' => 'checked_by_validation_name',  'label' => 'VALIDATION',        'order' => 3],
            ['key' => 'approved_by_user_name',       'label' => 'USER/VALIDATION',   'order' => 4],
            ['key' => 'approved_by_quality_name',    'label' => 'QUALITY ASSURANCE', 'order' => 5],
        ];
    }

    private function buildPostApprovalSteps(array $data): array
    {
        return [
            ['key' => 'post_prepared_by_name',            'label' => 'POST_PREPARED',    'order' => 1],
            ['key' => 'post_checked_by_engineering_name', 'label' => 'POST_ENGINEERING', 'order' => 2],
            ['key' => 'post_checked_by_production_name',  'label' => 'POST_PRODUCTION',  'order' => 3],
            ['key' => 'post_checked_by_validation_name',  'label' => 'POST_VALIDATION',  'order' => 4],
            ['key' => 'post_approved_by_quality_name',    'label' => 'POST_APPROVED_QA', 'order' => 5],
        ];
    }

    // =====================================================
    // ATTACHMENTS
    // =====================================================
   private function handleAttachments(Request $request, ?Report $report = null): array
{
    $attachments = [];

    for ($i = 1; $i <= 10; $i++) {

        if ($request->hasFile("attachment_file_$i")) {

            $file = $request->file("attachment_file_$i");

            $cleanName = str_replace(' ', '_', $file->getClientOriginalName());

            // ✅ if report exist → use protocol folder
            if ($report && $report->id) {
                $newFileName = 'protocol_'.$report->id.'/'.$cleanName;
                $path = $file->storeAs('reports/attachments', $newFileName, 'public');
            } else {
                // ✅ fallback (before report create)
                $path = $file->store('reports/attachments', 'public');
            }

            $attachments[] = [
                'index'         => $i,
                'name'          => $request->input("attachment_name_$i"),
                'file_path'     => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime'          => $file->getClientMimeType(),
                'size'          => $file->getSize(),
            ];
        }
    }

    return $attachments;
}
    // =====================================================
    // HTML Helpers
    // =====================================================

    private function hydrateDynamicSelects(string $html, $users, $documents, array $selectedData = []): string
    {
        libxml_use_internal_errors(true);

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->loadHTML(
            $this->wrapHtmlForDom($html),
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        $xpath = new \DOMXPath($doc);

        // USER SELECTS (PRE + POST)
        $userSelects = $xpath->query(
            '//select[@data-options="users"
                or @name="prepared_by_name"
                or @name="prepared_name"
                or @name="report_prepared_by_name"
                or @name="post_prepared_by_name"
                or @name="post_review_prepared_by_name"
                or starts-with(@name,"checked_by_")
                or starts-with(@name,"checked_")
                or starts-with(@name,"approved_by_")
                or starts-with(@name,"approved_head_")
                or starts-with(@name,"post_checked_by_")
                or starts-with(@name,"post_approved_by")
                or starts-with(@name,"post_review_")]'
        );

        foreach ($userSelects as $select) {
            $name = (string) $select->getAttribute('name');
            $currentSelected = $this->resolveUserSelectedValue($selectedData[$name] ?? '', $users);
            $this->populateUserSelect($doc, $select, $users, $currentSelected);
        }

        $userInputs = $xpath->query(
            '//input[
                @name="prepared_by_name"
                or @name="prepared_name"
                or @name="report_prepared_by_name"
                or @name="post_prepared_by_name"
                or @name="post_review_prepared_by_name"
                or (starts-with(@name,"checked_by_") and contains(@name,"_name"))
                or (starts-with(@name,"checked_") and contains(@name,"_name"))
                or (starts-with(@name,"approved_by_") and contains(@name,"_name"))
                or (starts-with(@name,"approved_head_") and contains(@name,"_name"))
                or (starts-with(@name,"post_checked_by_") and contains(@name,"_name"))
                or (starts-with(@name,"post_approved_by_") and contains(@name,"_name"))
                or (starts-with(@name,"post_review_") and contains(@name,"_name"))
            ]'
        );

        foreach ($userInputs as $input) {
            $name = (string) $input->getAttribute('name');
            $type = strtolower((string) $input->getAttribute('type'));
            if ($type === 'hidden') {
                continue;
            }

            $select = $doc->createElement('select');
            $this->copyInputAttributesToSelect($input, $select, $name);

            $currentSelected = $this->resolveUserSelectedValue($selectedData[$name] ?? '', $users);
            $this->populateUserSelect($doc, $select, $users, $currentSelected);

            $input->parentNode->replaceChild($select, $input);
        }

        // DOCUMENT SELECTS
        $docSelects = $xpath->query('//select[@data-options="documents" or @name="document_id" or @name="document_no"]');

        foreach ($docSelects as $select) {
            $name = (string) $select->getAttribute('name');
            $currentRaw = $selectedData[$name] ?? ($name === 'document_id' ? ($selectedData['document_no'] ?? '') : ($selectedData['document_id'] ?? ''));
            $currentSelected = $this->resolveDocumentSelectedValue($currentRaw, $documents);

            $this->populateDocumentSelect($doc, $select, $documents, $currentSelected);
        }

        if ($docSelects->length === 0) {
            $documentInputs = $xpath->query('//input[@name="document_no"]');

            foreach ($documentInputs as $input) {
                $type = strtolower((string) $input->getAttribute('type'));
                if ($type === 'hidden') {
                    continue;
                }

                $select = $doc->createElement('select');
                $this->copyInputAttributesToSelect($input, $select, 'document_no');

                $currentSelected = $this->resolveDocumentSelectedValue($selectedData['document_no'] ?? '', $documents);
                $this->populateDocumentSelect($doc, $select, $documents, $currentSelected);

                $input->parentNode->replaceChild($select, $input);
            }
        }

        $body = $doc->getElementsByTagName('body')->item(0);
        $result = '';
        foreach ($body->childNodes as $child) $result .= $doc->saveHTML($child);

        return $result;
    }

    private function injectValuesIntoHtml(string $html, array $data): string
    {
        libxml_use_internal_errors(true);

        $data = $this->normalizeReportData($data);

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->loadHTML(
            $this->wrapHtmlForDom($html),
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        $xpath = new \DOMXPath($doc);
        $flat = $this->flatten($data);

        foreach ($flat as $name => $value) {
            $safeValue = $this->safeUtf8((string) $value);

            foreach ($xpath->query('//input[@name="' . $this->xpathEscape($name) . '"]') as $input) {
                $type = strtolower($input->getAttribute('type') ?: 'text');

                if ($type === 'checkbox') {
                    if (!empty($value)) $input->setAttribute('checked', 'checked');
                    else $input->removeAttribute('checked');
                } else {
                    $input->setAttribute('value', $safeValue);
                }
            }

            foreach ($xpath->query('//textarea[@name="' . $this->xpathEscape($name) . '"]') as $ta) {
                while ($ta->firstChild) $ta->removeChild($ta->firstChild);
                $ta->appendChild($doc->createTextNode($safeValue));
            }

            foreach ($xpath->query('//select[@name="' . $this->xpathEscape($name) . '"]') as $sel) {
                foreach ($sel->getElementsByTagName('option') as $opt) {
                    if ($opt->getAttribute('value') === (string) $value) $opt->setAttribute('selected', 'selected');
                    else $opt->removeAttribute('selected');
                }
            }
        }

        $body = $doc->getElementsByTagName('body')->item(0);
        $out = '';
        foreach ($body->childNodes as $child) $out .= $doc->saveHTML($child);
        return $out;
    }

    private function flatten($data, string $prefix = ''): array
    {
        $out = [];
        foreach ((array) $data as $k => $v) {
            $key = $prefix === '' ? $k : $prefix . '[' . $k . ']';
            if (is_array($v)) $out += $this->flatten($v, $key);
            else $out[$key] = $v;
        }
        return $out;
    }

    private function xpathEscape(string $str): string
    {
        return str_replace('"', '&quot;', $str);
    }

    private function getTemplateUsers()
    {
        return User::with('roles:id,name')
            ->select('id', 'first_name', 'last_name')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(function ($user) {
                $user->display_name = $this->formatUserOptionLabel($user);
                return $user;
            });
    }

private function getTemplateDocuments()
{
    return \App\Models\Document::with([
        'facility:id,facility_name',
        'equipment:id,equipment_name,equipment_id,approver_username'
    ])
    ->where('status', 'completed')
    ->orderByDesc('id')
    ->get();
}

    private function formatUserOptionLabel($user): string
    {
        $fullName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
        $roleName = method_exists($user, 'roles') ? ($user->roles->pluck('name')->first() ?? '') : '';

        if ($fullName === '') {
            $fullName = 'User #' . $user->id;
        }

        return $roleName !== '' ? $fullName . ' - ' . $roleName : $fullName;
    }

    // private function formatDocumentOptionLabel($document): string
    // {
    //     $docNumber = trim((string) ($document->doc_title ?? ''));
    //     $title = trim((string) ($document->title ?? ''));

    //     if ($docNumber !== '' && $title !== '') {
    //         return $docNumber . ' - ' . $title;
    //     }

    //     return $docNumber !== '' ? $docNumber : ($title !== '' ? $title : 'Document #' . $document->id);
    // }

    private function formatDocumentOptionLabel($document): string
{
    $id = $document->id ?? '';
    $title = trim((string) ($document->title ?? ''));

    return $id . ' - ' . $title;
}


    private function resolveUserSelectedValue($selectedValue, $users): string
    {
        $selectedValue = trim((string) $selectedValue);
        if ($selectedValue === '') {
            return '';
        }

        if (is_numeric($selectedValue)) {
            return (string) $selectedValue;
        }

        foreach ($users as $user) {
            $fullName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
            if ($selectedValue === $user->display_name || $selectedValue === $fullName) {
                return (string) $user->id;
            }
        }

        return '';
    }

    private function resolveDocumentSelectedValue($selectedValue, $documents): string
    {
        $selectedValue = trim((string) $selectedValue);
        if ($selectedValue === '') {
            return '';
        }

        if (is_numeric($selectedValue)) {
            return (string) $selectedValue;
        }

        foreach ($documents as $document) {
            if ($selectedValue === $this->formatDocumentOptionLabel($document) || $selectedValue === trim((string) ($document->title ?? '')) || $selectedValue === trim((string) ($document->doc_title ?? ''))) {
                return (string) $document->id;
            }
        }

        return '';
    }

    private function populateUserSelect(\DOMDocument $doc, \DOMElement $select, $users, string $currentSelected = ''): void
    {
        while ($select->firstChild) {
            $select->removeChild($select->firstChild);
        }

        $placeholder = $doc->createElement('option', 'Select user');
        $placeholder->setAttribute('value', '');
        $select->appendChild($placeholder);

        foreach ($users as $user) {
            $option = $doc->createElement('option', $this->formatUserOptionLabel($user));
            $option->setAttribute('value', (string) $user->id);

            if ($currentSelected !== '' && (string) $user->id === $currentSelected) {
                $option->setAttribute('selected', 'selected');
            }

            $select->appendChild($option);
        }

        $select->setAttribute('data-options', 'users');
    }

    private function populateDocumentSelect(\DOMDocument $doc, \DOMElement $select, $documents, string $currentSelected = ''): void
    {
        while ($select->firstChild) {
            $select->removeChild($select->firstChild);
        }

        $placeholder = $doc->createElement('option', '-- Select Approved Document --');
        $placeholder->setAttribute('value', '');
        $select->appendChild($placeholder);

        foreach ($documents as $document) {
    if (!$document || !is_numeric($document->id)) {
        continue;
    }

    $option = $doc->createElement('option', $this->formatDocumentOptionLabel($document));
    $option->setAttribute('value', (string) $document->id);

    // ✅ ADD THIS (IMPORTANT)
    $facilityName = $document->facility->facility_name ?? '';
$equipmentName = $document->equipment->equipment_name ?? '';
$equipmentCode = $document->equipment->equipment_id ?? '';
$equipmentApproverId = $document->equipment->approver_username ?? '';

    $option->setAttribute('data-facility', $facilityName);
$option->setAttribute('data-equipment-name', $equipmentName);
$option->setAttribute('data-equipment-id', $equipmentCode);
$option->setAttribute('data-approver-id', (string) $equipmentApproverId);

    if ($currentSelected !== '' && (string) $document->id === $currentSelected) {
        $option->setAttribute('selected', 'selected');
    }

    $select->appendChild($option);
}

        $select->setAttribute('data-options', 'documents');
    }

    private function copyInputAttributesToSelect(\DOMElement $input, \DOMElement $select, string $name): void
    {
        $allowedAttributes = ['class', 'style', 'id', 'required', 'disabled', 'readonly'];

        foreach ($allowedAttributes as $attribute) {
            if ($input->hasAttribute($attribute)) {
                $select->setAttribute($attribute, $input->getAttribute($attribute));
            }
        }

        $select->setAttribute('name', $name);
    }

    private function applyDefaultPreApproverFromDocument(array &$data): void
    {
        if (!empty($data['approved_by_user_name'])) {
            return;
        }

        $documentId = $data['document_id'] ?? $data['document_no'] ?? null;
        if (!$documentId || !is_numeric($documentId)) {
            return;
        }

        $document = Document::query()
            ->with('equipment:id,approver_username')
            ->find((int) $documentId);

        $approverId = $document?->equipment?->approver_username;
        if (!is_numeric($approverId)) {
            return;
        }

        $approverId = (int) $approverId;
        if ($approverId <= 0 || !User::query()->whereKey($approverId)->exists()) {
            return;
        }

        $data['approved_by_user_name'] = (string) $approverId;
    }

    private function shouldSkipPreApproval(array $data, ?Template $template = null): bool
    {
        if ($this->isHvacPostOnlyTemplate($template)) {
            return true;
        }

        $documentId = $data['document_id'] ?? $data['document_no'] ?? null;
        if (!$documentId || !is_numeric($documentId)) {
            return false;
        }

        $document = Document::query()
            ->select(['id', 'title', 'doc_title'])
            ->find((int) $documentId);

        if (!$document) {
            return false;
        }

        $needles = [
            'periodic hvac validation report sikkim 1',
            'periodic hvac validation report',
            'hvac validation report',
        ];
        $haystacks = [
            strtolower(trim((string) $document->title)),
            strtolower(trim((string) $document->doc_title)),
        ];

        foreach ($haystacks as $haystack) {
            if ($haystack === '') {
                continue;
            }

            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isHvacPostOnlyTemplate(?Template $template): bool
    {
        if (!$template) {
            return false;
        }

        $name = strtolower(trim((string) ($template->name ?? '')));
        $path = strtolower(trim((string) ($template->docx_template_path ?? '')));
        $body = strtolower(trim((string) ($template->body_html ?? '')));

        if ($name !== '' && str_contains($name, 'hvac')) {
            return true;
        }

        if ($path !== '' && str_contains($path, 'hvac')) {
            return true;
        }

        return $body !== '' && str_contains($body, 'periodic hvac validation report');
    }

    private function requiresPreapprovedDocumentUpload(?Template $template): bool
    {
        return $this->isHvacPostOnlyTemplate($template);
    }

    private function assertPostApproversPresent(array $data): void
    {
        $requiredFields = [
            'post_prepared_by_name',
            'post_checked_by_engineering_name',
            'post_checked_by_production_name',
            'post_checked_by_validation_name',
            'post_approved_by_quality_name',
        ];

        $errors = [];
        foreach ($requiredFields as $field) {
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

    private function normalizePostApproverAssignments(array &$data): void
    {
        $legacyMap = [
            'post_prepared_by_name' => ['report_prepared_by_name'],
            'post_checked_by_engineering_name' => ['checked_engineering_name'],
            'post_checked_by_production_name' => ['checked_production_name'],
            'post_checked_by_validation_name' => ['checked_validation_name'],
            'post_approved_by_quality_name' => ['approved_head_qa_name', 'checked_qa_name'],
        ];

        foreach ($legacyMap as $target => $fallbacks) {
            $current = $data[$target] ?? null;
            if ($current !== null && $current !== '') {
                $resolved = $this->resolveUserIdFromMixedValue($current);
                if ($resolved !== null) {
                    $data[$target] = $resolved;
                }
                continue;
            }

            foreach ($fallbacks as $fallbackKey) {
                $fallbackValue = $data[$fallbackKey] ?? null;
                if ($fallbackValue === null || $fallbackValue === '') {
                    continue;
                }

                $resolved = $this->resolveUserIdFromMixedValue($fallbackValue);
                if ($resolved !== null) {
                    $data[$target] = $resolved;
                    break;
                }
            }
        }
    }

    private function resolveUserIdFromMixedValue(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $candidate = (int) $value;
            return User::query()->whereKey($candidate)->exists() ? $candidate : null;
        }

        $needle = strtolower(trim((string) $value));
        if ($needle === '') {
            return null;
        }

        $users = User::query()
            ->select(['id', 'first_name', 'last_name', 'email'])
            ->get();

        foreach ($users as $user) {
            $fullName = strtolower(trim(((string) ($user->first_name ?? '')) . ' ' . ((string) ($user->last_name ?? ''))));
            $email = strtolower(trim((string) ($user->email ?? '')));

            if ($needle === $fullName || $needle === $email) {
                return (int) $user->id;
            }
        }

        return null;
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

            $userId = $this->resolveUserIdFromMixedValue($value);
            if ($userId === null) {
                continue;
            }

            $user = $users->get($userId);
            if (!$user) {
                continue;
            }

            $data[$textKey] = $this->formatUserOptionLabel($user);
        }
    }

    private function storeDocxReport(Request $request, Template $template)
    {
        $fields = is_array($template->docx_placeholders) ? $template->docx_placeholders : [];
        $manualMode = empty($fields);
        $rules = [];

        foreach ($fields as $field) {
            if (is_array($field)) {
                $location = $field['location'] ?? null;
                if ($location && !($field['locked'] ?? false)) {
                    $rules['fields.' . $location] = ['nullable', 'string', 'max:4000'];
                }
            } else {
                // legacy string placeholder
                $rules[$field] = ['nullable', 'string', 'max:4000'];
            }
        }

        $rules = array_merge($rules, [
            'document_id' => ['required', 'integer'],
            'document_no' => ['nullable'],
            'facility_section' => ['nullable', 'string', 'max:255'],
            'equipment_name' => ['nullable', 'string', 'max:255'],
            'equipment_id' => ['nullable', 'string', 'max:255'],
            'version' => ['nullable', 'string', 'max:255'],
            'preapproved_doc_file' => ['required', 'file', 'mimes:doc,docx,pdf'],
            'post_prepared_by_name' => ['required', 'integer'],
            'post_checked_by_engineering_name' => ['required', 'integer'],
            'post_checked_by_production_name' => ['required', 'integer'],
            'post_checked_by_validation_name' => ['required', 'integer'],
            'post_approved_by_quality_name' => ['required', 'integer'],
        ]);
        $rules['completed_doc_file'] = $manualMode
            ? ['required', 'file', 'mimes:doc,docx,pdf']
            : ['nullable', 'file', 'mimes:doc,docx,pdf'];

        $request->validate($rules);

        $data = $request->except(['_token']);
        $data['prepared_by_signature'] = $data['prepared_by_signature'] ?? 'Prepared';
        $data['prepared_by_date'] = $data['prepared_by_date'] ?? now()->timezone('Asia/Kolkata')->format('d-m-Y h:i A');

        $preparedName = trim((auth()->user()->first_name ?? '') . ' ' . (auth()->user()->last_name ?? ''));
        if ($preparedName === '') {
            $preparedName = auth()->user()->name ?? '';
        }
        $data['prepared_by_name'] = $data['prepared_by_name'] ?? $preparedName;

        $skipPreApproval = $this->shouldSkipPreApproval($data, $template);
        if ($skipPreApproval) {
            $this->normalizePostApproverAssignments($data);
            $this->assertPostApproversPresent($data);
        }
        $this->enrichUserDisplayTextFields($data);
        $initialStage = $skipPreApproval ? 'post' : 'pre';
        $initialStatus = $skipPreApproval ? 'post_pending' : 'pre_pending';

        $rendered = $this->resolveDocxRenderedHtml($template, $data);

        $report = Report::create([
            'template_id' => (int) $template->id,
            'templatedata' => $data,
            'rendered_html' => $rendered,
            'data' => $data,
            'attachments' => [],
            'facility_section' => $data['facility_section'] ?? null,
            'document_no' => $data['document_id'] ?? ((isset($data['document_no']) && is_numeric($data['document_no'])) ? $data['document_no'] : null),
            'version' => $data['version'] ?? null,
            'equipment_name' => $data['equipment_name'] ?? null,
            'equipment_id' => $data['equipment_id'] ?? null,
            'stage' => $initialStage,
            'status' => $initialStatus,
            'current_step_order' => 1,
            'created_by' => auth()->id(),
        ]);

        $attachments = $this->handleAttachments($request, $report);
        $preapprovedAttachment = $this->handlePreapprovedDocUpload($request, $report);
        if ($preapprovedAttachment !== null) {
            $attachments[] = $preapprovedAttachment;
        }

        if ($manualMode) {
            $manualAttachment = $this->handleCompletedDocUpload($request, $report);
            if ($manualAttachment !== null) {
                $attachments[] = $manualAttachment;
            }
        } else {
            $generatedPath = $this->generateDocxOutputForReport($template, $report, $data);
            if ($generatedPath !== null) {
                $attachments[] = $this->buildGeneratedDocAttachment($generatedPath);
            }

            $optionalManualAttachment = $this->handleCompletedDocUpload($request, $report);
            if ($optionalManualAttachment !== null) {
                $attachments[] = $optionalManualAttachment;
            }
        }

        if (!empty($attachments)) {
            $report->update(['attachments' => $attachments]);
        }

        $this->createStepsForStage($report, $data, $initialStage);

        $first = $report->activeStepQuery()->first();
        $report->current_step_order = $first?->step_order ?? 0;
        if (!$first) {
            $report->status = $skipPreApproval ? 'approved' : 'pre_approved';
        }
        $report->save();

        if ($first) {
            $approver = User::find($first->user_id);
            if ($approver) {
                $this->notifyApprover(
                    $approver,
                    $report,
                    $skipPreApproval
                        ? "New POST approval request: Report #{$report->id} (Step {$first->step_order})"
                        : "New PRE approval request: Report #{$report->id} (Step {$first->step_order})"
                );
            }
        }

        return redirect()->route('reports.show', $report)->with(
            'status',
            $skipPreApproval
                ? 'Submitted to POST approval successfully.'
                : 'Submitted to PRE approval successfully.'
        );
    }

    private function handleCompletedDocUpload(Request $request, Report $report): ?array
    {
        if (!$request->hasFile('completed_doc_file')) {
            return null;
        }

        $file = $request->file('completed_doc_file');
        $cleanName = str_replace(' ', '_', $file->getClientOriginalName());
        $storedName = 'protocol_' . $report->id . '/completed_' . $cleanName;
        $path = $file->storeAs('reports/attachments', $storedName, 'public');

        return [
            'index' => 100,
            'name' => 'Completed Document',
            'file_path' => $path,
            'stored_name' => $storedName,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
        ];
    }

    private function handlePreapprovedDocUpload(Request $request, Report $report): ?array
    {
        if (!$request->hasFile('preapproved_doc_file')) {
            return null;
        }

        $file = $request->file('preapproved_doc_file');
        $cleanName = str_replace(' ', '_', $file->getClientOriginalName());
        $storedName = 'protocol_' . $report->id . '/preapproved_' . $cleanName;
        $path = $file->storeAs('reports/attachments', $storedName, 'public');

        return [
            'index' => 98,
            'name' => 'Pre-Approved Document',
            'file_path' => $path,
            'stored_name' => $storedName,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
        ];
    }

    private function buildGeneratedDocAttachment(string $generatedRelativePath): array
    {
        $normalizedPath = ltrim($generatedRelativePath, '/');
        $originalName = basename($normalizedPath);
        $absolutePath = storage_path('app/public/' . $normalizedPath);

        return [
            'index' => 99,
            'name' => 'Generated Document',
            'file_path' => $normalizedPath,
            'stored_name' => $originalName,
            'original_name' => $originalName,
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'size' => is_file($absolutePath) ? filesize($absolutePath) : 0,
        ];
    }

    private function generateDocxOutputForReport(Template $template, Report $report, array $data): ?string
    {
        try {
            $importFields = $report->docx_import_fields ??
                            $template->docx_placeholders ?? [];

            if (!empty($importFields) && is_array($importFields)) {
                return $this->generateDocxViaPython($template, $report, $data, $importFields);
            }

            $timestamp = now()->format('Ymd_His');
            $relative = 'reports/generated/protocol_' . $report->id . '/report_' . $report->id . '_' . $timestamp . '.docx';

            return app(DocxTemplateService::class)->populateTemplateFromPublicPath(
                (string) $template->docx_template_path,
                $data,
                $relative
            );

        } catch (\Throwable $exception) {
            report($exception);
            return null;
        }
    }

    private function generateDocxViaPython(Template $template, Report $report, array $data, array $importFields): ?string
    {
        try {
            $pythonUrl = env('PYTHON_NLP_URL', 'http://127.0.0.1:8001');
            $fileId    = $report->docx_import_path ?? null;

            if (!$fileId) {
                return null;
            }

            $fields = [];
            foreach ($importFields as $field) {
                $location = $field['location'] ?? null;
                $locked   = $field['locked']   ?? false;
                $label    = $field['label']    ?? '';

                if (!$location || $locked) {
                    continue;
                }

                $value = $data[$label]
                      ?? $data[str_replace(' ', '_', strtolower($label))]
                      ?? $field['value']
                      ?? '';

                $fields[] = [
                    'location' => $location,
                    'value'    => (string) $value,
                    'locked'   => false,
                ];
            }

            $response = \Illuminate\Support\Facades\Http::timeout(120)
                ->post("{$pythonUrl}/fill", [
                    'file_id'           => $fileId,
                    'original_filename' => basename((string) $template->docx_template_path),
                    'fields'            => $fields,
                ]);

            if (!$response->successful()) {
                throw new \RuntimeException('Python /fill failed: ' . $response->body());
            }

            $result = $response->json();
            $outputFilename = $result['output_filename'] ?? null;
            if (!$outputFilename) {
                return null;
            }

            $relative = 'generated_reports/' . $outputFilename;
            $report->update(['docx_filled_path' => $relative]);

            return $relative;

        } catch (\Throwable $e) {
            report($e);
            return null;
        }
    }

    private function resolveDocxRenderedHtml(Template $template, array $data): string
    {
        $html = trim((string) ($template->body_html ?? ''));

        if ($html === '' && $this->shouldUseHvacDocxMaster($template)) {
            $masterPath = resource_path('views/templates/PERIODIC_HVAC_VALIDATION_REPORT.html');
            if (is_file($masterPath)) {
                $html = (string) file_get_contents($masterPath);
            }
        }

        if ($html !== '') {
            return $this->injectValuesIntoHtml($html, $data);
        }

        return $this->buildDocxPreviewHtml($template, $data);
    }

    private function shouldUseHvacDocxMaster(Template $template): bool
    {
        $name = strtolower(trim((string) ($template->name ?? '')));
        if ($name !== '' && str_contains($name, 'hvac')) {
            return true;
        }

        $path = strtolower(trim((string) ($template->docx_template_path ?? '')));
        return $path !== '' && str_contains($path, 'hvac');
    }

    private function isLegacyDocxPreviewHtml(string $html): bool
    {
        $normalized = strtolower($html);
        return str_contains($normalized, '<table class="table table-bordered"')
            && str_contains($normalized, '<h4>');
    }

    private function buildDocxPreviewHtml(Template $template, array $data): string
    {
        $fields = is_array($template->docx_placeholders) ? $template->docx_placeholders : [];
        $rows = '';

        foreach ($fields as $field) {
            if (is_array($field)) {
                $location = $field['location'] ?? '';
                $label    = $field['label']    ?? $location;
                $locked   = $field['locked']   ?? false;
                $val      = $field['value']    ?? ($data[$location] ?? '');
            } else {
                $location = (string) $field;
                $label    = str_replace(['_', '-'], ' ', $location);
                $locked   = false;
                $val      = $data[$location] ?? '';
            }

            $safeField = e($location);
            $value     = e((string) $val);
            $safeLabel = e(ucwords($label));

            if ($locked) {
                $rows .= '<tr><th>' . $safeLabel . '</th>'
                       . '<td class="text-muted">' . $value . '</td></tr>';
            } else {
                $rows .= '<tr><th>' . $safeLabel . '</th>'
                       . '<td><input type="text" class="form-control" '
                       . 'name="fields[' . $safeField . ']" '
                       . 'value="' . $value . '"></td></tr>';
            }
        }

        return '<div><h4>' . e($template->name) . '</h4><table class="table table-bordered"><tbody>' . $rows . '</tbody></table></div>';
    }

    // Convert form to read-only HTML for approval stage
   /**
    * renderReadOnlyHtml() — convert inputs/selects to read-only spans for approver viewing.
    *
    * @param  string  $html    Template or rendered HTML fragment
    * @param  array   $data    Normalized report field values (UTF-8)
    * @param  Report  $report  Report model (attachments, etc.)
    * @return string HTML with text nodes (not double-encoded entities)
    *
    * Encoding note: uses createTextNode() instead of htmlspecialchars()+createElement()
    * to avoid entity double-encoding that appears as mojibake during approval steps.
    */
   private function renderReadOnlyHtml(string $html, array $data, Report $report): string
{
    libxml_use_internal_errors(true);

    $data = $this->normalizeReportData($data);

    $doc = new \DOMDocument('1.0', 'UTF-8');
    $doc->loadHTML(
        $this->wrapHtmlForDom($html),
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );

    $xpath = new \DOMXPath($doc);

    // Users & Documents
    $userMap = $this->getTemplateUsers()->keyBy('id');

    $docMap  = class_exists(\App\Models\Document::class)
        ? \App\Models\Document::select('id', 'title', 'doc_title')->get()->keyBy('id')
        : collect();

    // Attachments decode
    $atts = $report->attachments ?? [];
    if (is_string($atts)) $atts = json_decode($atts, true) ?? [];
    if (!is_array($atts)) $atts = [];

    foreach ($xpath->query('//input') as $input) {

        $name  = (string) $input->getAttribute('name');
        $type  = strtolower((string) $input->getAttribute('type'));
        $value = $data[$name] ?? '';
        if ($name !== '' && str_ends_with($name, '_name')) {
            $textKey = $name . '_text';
            if (!empty($data[$textKey])) {
                $value = (string) $data[$textKey];
            } elseif (is_numeric((string) $value)) {
                $resolvedUser = $userMap->get((int) $value);
                if ($resolvedUser) {
                    $value = $this->formatUserOptionLabel($resolvedUser);
                }
            }
        }

        // ===============================
        // ✅ FILE INPUT → CLICKABLE LINK
        // ===============================
        if ($name !== '' && preg_match('/^attachment_file_(\d+)$/', $name, $m)) {

            $idx = (int) $m[1];

            $att = collect($atts)->first(function ($a) use ($idx) {
                return isset($a['index']) && (int)$a['index'] === $idx;
            });

            if (!$att) {
                $att = $atts[$idx - 1] ?? null;
            }

            if ($att && !empty($att['file_path'])) {

                $fileName = $att['original_name']
                    ?? $att['stored_name']
                    ?? basename($att['file_path']);

                // Use an authenticated route instead of direct public/storage URL,
                // so links work across hosts/ports/subdirectories.
                $basePath = rtrim((string) request()->getBaseUrl(), '/');
                $fileUrl = ($basePath !== '' ? $basePath : '')
                    . '/reports/' . $report->id
                    . '/attachments/' . $idx
                    . '/download';

                $a = $doc->createElement('a');
                $a->setAttribute('href', $fileUrl);
                $a->setAttribute('target', '_blank');
                $a->setAttribute('onclick', "event.stopPropagation();");

                $a->setAttribute('style',
                    'text-decoration:underline; color:#0d6efd; cursor:pointer;
                     pointer-events:auto !important; position:relative; z-index:999;'
                );

                $a->appendChild($doc->createTextNode($fileName));

                $input->parentNode->replaceChild($a, $input);

            } else {
                $span = $doc->createElement('span', '—');
                $span->setAttribute('style', 'color:#aaa; font-size:13px;');
                $input->parentNode->replaceChild($span, $input);
            }

            continue;
        }

        // ===============================
        // ✅ CHECKBOX
        // ===============================
        if ($type === 'checkbox') {
            $span = $doc->createElement('span', !empty($value) ? '✔' : '');
            $span->setAttribute('style', 'font-weight:800;');
            $input->parentNode->replaceChild($span, $input);
            continue;
        }

        // ===============================
        // ✅ SIGNATURE
        // ===============================
        if ($name !== '' && str_ends_with($name, '_signature')) {

            $label = trim((string) $value);
            $lower = strtolower($label);

            $color = '#0d6efd';
            if (str_contains($lower, 'approved')) $color = '#198754';
            elseif (str_contains($lower, 'reject')) $color = '#dc3545';

            $wrap = $doc->createElement('div');
            $status = $doc->createElement('span');
            $status->setAttribute('style', "color:{$color}; font-weight:600;");
            $status->appendChild($doc->createTextNode($this->safeUtf8($label)));
            $wrap->appendChild($status);

            $input->parentNode->replaceChild($wrap, $input);
            continue;
        }

        // ===============================
        // ✅ DATE
        // ===============================
        if ($name !== '' && str_ends_with($name, '_date')) {

            $span = $doc->createElement('span');
            $span->setAttribute('style', 'color:#6c757d; font-size:14px;');
            $span->appendChild($doc->createTextNode($this->safeUtf8((string) $value)));

            $input->parentNode->replaceChild($span, $input);
            continue;
        }

        // ===============================
        // ✅ NORMAL INPUT
        // ===============================
        $span = $doc->createElement('span');
        $span->setAttribute(
            'style',
            'display:inline-block; min-width:120px; border-bottom:1px dotted #999; padding:2px 4px;'
        );
        $span->appendChild($doc->createTextNode($this->safeUtf8((string) $value)));

        $input->parentNode->replaceChild($span, $input);
    }

    // ===============================
    // TEXTAREA
    // ===============================
    foreach ($xpath->query('//textarea') as $ta) {

        $name  = (string) $ta->getAttribute('name');
        $value = $data[$name] ?? '';

        $div = $doc->createElement('div');
        $div->setAttribute('style', 'white-space:pre-wrap; border:1px solid #ddd; padding:8px;');
        $div->appendChild($doc->createTextNode($this->safeUtf8((string) $value)));

        $ta->parentNode->replaceChild($div, $ta);
    }

    // ===============================
    // SELECT
    // ===============================
    foreach ($xpath->query('//select') as $select) {

        $name = (string) $select->getAttribute('name');
        $val  = (string) ($data[$name] ?? '');
        $textVal = (string) ($data[$name . '_text'] ?? '');

        $label = '';

        if ($textVal !== '') {
            $label = $textVal;
        } elseif ($val !== '') {
            $u = $userMap->get((int)$val);
            $d = $docMap->get((int)$val);

            if ($u) $label = $this->formatUserOptionLabel($u);
            elseif ($d) $label = $this->formatDocumentOptionLabel($d);
            else $label = $val;
        }

        $strong = $doc->createElement('strong');
        $strong->setAttribute(
            'style',
            'display:inline-block; min-width:180px; border-bottom:1px dotted #999; padding:2px 4px;'
        );
        $strong->appendChild($doc->createTextNode($this->safeUtf8($label)));

        $select->parentNode->replaceChild($strong, $select);
    }

    $body = $doc->getElementsByTagName('body')->item(0);

    $out = '';
    foreach ($body->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }

    return $out;
}

 // Put Approved/Rejected text into signature/date inputs
     private function injectApprovalMarks(string $html, Report $report): string
    {
        libxml_use_internal_errors(true);

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->loadHTML(
            $this->wrapHtmlForDom($html),
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        $xpath = new \DOMXPath($doc);

        // prefix => label (must match DB labels exactly)
        $map = [
            // PRE
            'checked_by_engineering' => 'ENGINEERING',
            'checked_by_production'  => 'PRODUCTION',
            'checked_by_validation'  => 'VALIDATION',
            'approved_by_user'       => 'USER/VALIDATION',
            'approved_by_quality'    => 'QUALITY ASSURANCE',

            // POST
            'post_prepared_by'             => 'POST_PREPARED',
            'post_checked_by_engineering'  => 'POST_ENGINEERING',
            'post_checked_by_production'   => 'POST_PRODUCTION',
            'post_checked_by_validation'   => 'POST_VALIDATION',
            'post_approved_by_quality'     => 'POST_APPROVED_QA',
        ];

        foreach ($map as $prefix => $label) {
            $step = $report->approvalSteps->firstWhere('label', $label);
            if (!$step) continue;

            $sigInput  = $xpath->query('//input[@name="' . $prefix . '_signature"]')->item(0);
            $dateInput = $xpath->query('//input[@name="' . $prefix . '_date"]')->item(0);

            if ($step->approved_at) {
                $approvedDate = $step->approved_at->timezone('Asia/Kolkata')->format('d-m-Y h:i A');

                if ($sigInput) {
                    $span = $doc->createElement('span', 'Approved');
                    $span->setAttribute('style', 'color:green;font-weight:bold;');
                    $sigInput->parentNode->replaceChild($span, $sigInput);
                }
                if ($dateInput) {
                    $span = $doc->createElement('span', $approvedDate);
                    $dateInput->parentNode->replaceChild($span, $dateInput);
                }
            } elseif ($step->rejected_at) {
                $rejectedDate = $step->rejected_at->timezone('Asia/Kolkata')->format('d-m-Y h:i A');

                if ($sigInput) {
                    $span = $doc->createElement('span', 'Send Back');
                    $span->setAttribute('style', 'color:red;font-weight:bold;');
                    $sigInput->parentNode->replaceChild($span, $sigInput);
                }
                if ($dateInput) {
                    $span = $doc->createElement('span', $rejectedDate);
                    $dateInput->parentNode->replaceChild($span, $dateInput);
                }
            }
        }

        // prepared by always show Prepared + date
       $preparedSigInputs = $xpath->query('//input[@name="prepared_by_signature"]');
$preparedDateInputs = $xpath->query('//input[@name="prepared_by_date"]');

if ($preparedSigInputs->length > 0) {
    foreach ($preparedSigInputs as $preparedSig) {
        $span = $doc->createElement('span', 'Prepared');
        $span->setAttribute('style', 'color:blue;font-weight:bold;');
        $preparedSig->parentNode->replaceChild($span, $preparedSig);
    }
}

if ($preparedDateInputs->length > 0) {
    foreach ($preparedDateInputs as $preparedDate) {
        $span = $doc->createElement('span', now()->format('d-m-Y'));
        $preparedDate->parentNode->replaceChild($span, $preparedDate);
    }
}

        $body = $doc->getElementsByTagName('body')->item(0);
        $out = '';
        foreach ($body->childNodes as $child) $out .= $doc->saveHTML($child);
        return $out;
    }

    private function stepPrefix(string $stage, string $label): ?string
    {
        if ($stage === 'pre') {
            return match ($label) {
                'ENGINEERING'        => 'checked_by_engineering',
                'PRODUCTION'         => 'checked_by_production',
                'VALIDATION'         => 'checked_by_validation',
                'USER/VALIDATION'    => 'approved_by_user',
                'QUALITY ASSURANCE'  => 'approved_by_quality',
                default              => null,
            };
        }

        return match ($label) {
            'POST_PREPARED'    => 'post_prepared_by',
            'POST_ENGINEERING' => 'post_checked_by_engineering',
            'POST_PRODUCTION'  => 'post_checked_by_production',
            'POST_VALIDATION'  => 'post_checked_by_validation',
            'POST_APPROVED_QA' => 'post_approved_by_quality',
            default            => null,
        };
    }

    // ✅ stamps into $data (used by readonly rendering)
     /**
      * stampDecisionFieldsArray() — write approver name, signature, and date into reports.data.
      *
      * @param  array   $data      Mutable report data array
      * @param  string  $prefix    Field prefix (e.g. post_checked_by_engineering)
      * @param  string  $decision  Signature label (Approved / Send Back)
      *
      * Encoding note: names are normalized to UTF-8 before JSON storage in MSSQL.
      */
     private function stampDecisionFieldsArray(array &$data, string $prefix, string $decision): void
    {
        $name = trim((auth()->user()->first_name ?? '') . ' ' . (auth()->user()->last_name ?? ''));
        if ($name === '') {
            $name = auth()->user()->name ?? '—';
        }

        $data[$prefix . '_name_text'] = $this->safeUtf8($name);
        $data[$prefix . '_signature'] = $this->safeUtf8($decision);
        $data[$prefix . '_date']      = now()->timezone('Asia/Kolkata')->format('d-m-Y h:i A');
    }

    private function renderPreSectionReadOnly(string $html, array $data, Report $report): string
    {
        libxml_use_internal_errors(true);

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->loadHTML($this->wrapHtmlForDom($html), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $xpath = new \DOMXPath($doc);

        $preContainer = $xpath->query('//*[contains(@class,"pre-approval-section")]')->item(0);
        if (!$preContainer) return $html;

        $innerHtml = '';
        foreach ($preContainer->childNodes as $child) {
            $innerHtml .= $doc->saveHTML($child);
        }

        $converted = $this->renderReadOnlyHtml($innerHtml, $data, $report);

        while ($preContainer->firstChild) {
            $preContainer->removeChild($preContainer->firstChild);
        }

        $tmp = new \DOMDocument('1.0', 'UTF-8');
        $tmp->loadHTML($this->wrapHtmlForDom($converted), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        foreach ($tmp->getElementsByTagName('body')->item(0)->childNodes as $node) {
            $imported = $doc->importNode($node, true);
            $preContainer->appendChild($imported);
        }

        $body = $doc->getElementsByTagName('body')->item(0);
        $out = '';
        foreach ($body->childNodes as $child) $out .= $doc->saveHTML($child);

        return $out;
    }

public function downloadAttachmentsFolderZip($id)
{
    $report = Report::findOrFail($id);
    $attachmentsRaw = $report->attachments ?? [];
    if (is_string($attachmentsRaw)) {
        $attachmentsRaw = json_decode($attachmentsRaw, true) ?? [];
    }
    if (!is_array($attachmentsRaw)) {
        $attachmentsRaw = [];
    }

    $attachmentsList = array_values(array_filter($attachmentsRaw, fn ($item) => is_array($item)));

    $zipBaseName = "protocol_{$report->id}_attachments";
    $zipTempDir = storage_path('app/temp');
    if (!is_dir($zipTempDir)) {
        @mkdir($zipTempDir, 0777, true);
    }
    $zipFullPath = $zipTempDir . DIRECTORY_SEPARATOR . $zipBaseName . '_' . uniqid() . '.zip';

    $zip = new ZipArchive();

    if ($zip->open($zipFullPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        abort(500, 'Cannot create ZIP file.');
    }

    $added = 0;
    $usedNames = [];

    foreach ($attachmentsList as $idx => $attachment) {
        $rawPath = (string) ($attachment['file_path'] ?? '');
        if ($rawPath === '') {
            continue;
        }

        $parsedPath = parse_url($rawPath, PHP_URL_PATH);
        $normalizedPath = (string) ($parsedPath ?: $rawPath);
        $normalizedPath = str_replace('\\', '/', $normalizedPath);
        $normalizedPath = ltrim($normalizedPath, '/');

        if (str_starts_with($normalizedPath, 'storage/')) {
            $normalizedPath = substr($normalizedPath, strlen('storage/'));
        }
        if (str_starts_with($normalizedPath, 'public/')) {
            $normalizedPath = substr($normalizedPath, strlen('public/'));
        }

        $absolutePath = null;

        if (Storage::disk('public')->exists($normalizedPath)) {
            $candidate = storage_path('app/public/' . $normalizedPath);
            if (is_file($candidate)) {
                $absolutePath = $candidate;
            }
        }

        if (!$absolutePath) {
            $candidatePaths = [
                storage_path('app/public/' . $normalizedPath),
                public_path('storage/' . $normalizedPath),
                public_path($normalizedPath),
            ];

            foreach ($candidatePaths as $candidatePath) {
                if (is_file($candidatePath)) {
                    $absolutePath = $candidatePath;
                    break;
                }
            }
        }

        if (!$absolutePath) {
            continue;
        }

        $zipEntryName = (string) ($attachment['original_name'] ?? basename($normalizedPath));
        $zipEntryName = trim(basename(str_replace('\\', '/', $zipEntryName)));
        if ($zipEntryName === '') {
            $zipEntryName = 'attachment_' . ($idx + 1);
        }

        $base = pathinfo($zipEntryName, PATHINFO_FILENAME);
        $ext = pathinfo($zipEntryName, PATHINFO_EXTENSION);
        $counter = 1;
        $candidateName = $zipEntryName;
        while (isset($usedNames[strtolower($candidateName)])) {
            $candidateName = $base . '_' . $counter . ($ext !== '' ? '.' . $ext : '');
            $counter++;
        }
        $usedNames[strtolower($candidateName)] = true;

        if ($zip->addFile($absolutePath, $candidateName)) {
            $added++;
        }
    }

    // Backward compatibility: if attachment JSON has no usable paths, zip legacy folder content.
    if ($added === 0) {
        $folderRelativePath = "reports/attachments/protocol_{$report->id}";
        $folderFullPath = storage_path("app/public/{$folderRelativePath}");

        if (is_dir($folderFullPath)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($folderFullPath),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($files as $file) {
                if ($file->isDir()) {
                    continue;
                }

                $filePath = $file->getRealPath();
                if (!$filePath || !is_file($filePath)) {
                    continue;
                }

                $relativePathInZip = substr($filePath, strlen($folderFullPath) + 1);
                if ($relativePathInZip === '' || isset($usedNames[strtolower($relativePathInZip)])) {
                    continue;
                }

                if ($zip->addFile($filePath, $relativePathInZip)) {
                    $usedNames[strtolower($relativePathInZip)] = true;
                    $added++;
                }
            }
        }
    }

    $zip->close();

    if ($added === 0 || !file_exists($zipFullPath)) {
        @unlink($zipFullPath);
        abort(404, 'No attachment files found to zip for this report.');
    }

    return response()->download($zipFullPath, $zipBaseName . '.zip')->deleteFileAfterSend(true);
}

/**
 * authorizeReportAttachmentAccess() — Creators, approvers, and admins may download report files.
 */
private function authorizeReportAttachmentAccess(Report $report): void
{
    $userId = (int) auth()->id();
    if ($userId === 0) {
        abort(403, 'Authentication required.');
    }

    if ((int) $report->created_by === $userId) {
        return;
    }

    $user = auth()->user();
    if ($user && method_exists($user, 'isAdmin') && $user->isAdmin()) {
        return;
    }

    $report->loadMissing('approvalSteps');
    foreach ($report->approvalSteps as $step) {
        if ((int) $step->user_id === $userId) {
            return;
        }
    }

    // Other authenticated users (same access model as report show page).
}

public function downloadAttachment(Report $report, int $index)
{
    $this->authorizeReportAttachmentAccess($report);

    $attachmentsRaw = $report->attachments ?? [];
    if (is_string($attachmentsRaw)) {
        $attachmentsRaw = json_decode($attachmentsRaw, true) ?? [];
    }
    if (!is_array($attachmentsRaw)) {
        $attachmentsRaw = [];
    }

    $attachmentsList = array_values(array_filter($attachmentsRaw, fn ($item) => is_array($item)));

    $attachment = collect($attachmentsList)->first(function ($item) use ($index) {
        return isset($item['index']) && (int) $item['index'] === $index;
    });

    if (!$attachment && isset($attachmentsRaw[(string) $index]) && is_array($attachmentsRaw[(string) $index])) {
        $attachment = $attachmentsRaw[(string) $index];
    }

    if (!$attachment) {
        $attachment = $attachmentsList[$index - 1] ?? null;
    }

    if (!$attachment) {
        abort(404, 'Attachment not found.');
    }

    $rawPath = (string) ($attachment['file_path'] ?? '');
    if ($rawPath === '') {
        abort(404, 'Attachment file path missing.');
    }

    $parsedPath = parse_url($rawPath, PHP_URL_PATH);
    $normalizedPath = (string) ($parsedPath ?: $rawPath);
    $normalizedPath = str_replace('\\', '/', $normalizedPath);
    $normalizedPath = ltrim($normalizedPath, '/');

    if (str_starts_with($normalizedPath, 'storage/')) {
        $normalizedPath = substr($normalizedPath, strlen('storage/'));
    }
    if (str_starts_with($normalizedPath, 'public/')) {
        $normalizedPath = substr($normalizedPath, strlen('public/'));
    }

    $downloadName = (string) ($attachment['original_name'] ?? basename($normalizedPath));

    if (Storage::disk('public')->exists($normalizedPath)) {
        return Storage::disk('public')->download($normalizedPath, $downloadName);
    }

    $candidatePaths = [
        storage_path('app/public/' . $normalizedPath),
        public_path('storage/' . $normalizedPath),
        public_path($normalizedPath),
    ];

    foreach ($candidatePaths as $candidatePath) {
        if (is_file($candidatePath)) {
            return response()->download($candidatePath, $downloadName);
        }
    }

    abort(404, 'Attachment file not found.');
}

public function downloadApprovedWord(Report $report)
{
    $attachmentsRaw = $report->attachments ?? [];
    if (is_string($attachmentsRaw)) {
        $attachmentsRaw = json_decode($attachmentsRaw, true) ?? [];
    }
    if (!is_array($attachmentsRaw)) {
        $attachmentsRaw = [];
    }

    $attachmentsList = array_values(array_filter($attachmentsRaw, fn ($item) => is_array($item)));

    $isWordAttachment = function (array $attachment): bool {
        $filePath = strtolower((string) ($attachment['file_path'] ?? ''));
        $storedName = strtolower((string) ($attachment['stored_name'] ?? ''));
        $originalName = strtolower((string) ($attachment['original_name'] ?? ''));
        $mime = strtolower((string) ($attachment['mime'] ?? ''));

        if ($mime === 'application/msword'
            || $mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
            return true;
        }

        return str_ends_with($filePath, '.doc')
            || str_ends_with($filePath, '.docx')
            || str_ends_with($storedName, '.doc')
            || str_ends_with($storedName, '.docx')
            || str_ends_with($originalName, '.doc')
            || str_ends_with($originalName, '.docx');
    };

    $preferredAttachment = collect($attachmentsList)->first(function ($attachment) use ($isWordAttachment) {
        if (!is_array($attachment)) {
            return false;
        }
        $index = isset($attachment['index']) ? (int) $attachment['index'] : null;
        return $index === 100 && $isWordAttachment($attachment);
    });

    if (!$preferredAttachment) {
        $preferredAttachment = collect($attachmentsList)->first(function ($attachment) use ($isWordAttachment) {
            if (!is_array($attachment)) {
                return false;
            }
            $index = isset($attachment['index']) ? (int) $attachment['index'] : null;
            return $index === 99 && $isWordAttachment($attachment);
        });
    }

    if (!$preferredAttachment) {
        $preferredAttachment = collect($attachmentsList)->first(function ($attachment) use ($isWordAttachment) {
            if (!is_array($attachment)) {
                return false;
            }
            $index = isset($attachment['index']) ? (int) $attachment['index'] : null;
            return $index !== 98 && $isWordAttachment($attachment);
        });
    }

    if (is_array($preferredAttachment) && isset($preferredAttachment['index'])) {
        return $this->downloadAttachment($report, (int) $preferredAttachment['index']);
    }

    $report->load(['template', 'approvalSteps']);

    $data = $report->data ?? [];
    if (is_string($data)) {
        $data = json_decode($data, true) ?? [];
    }
    if (!is_array($data)) {
        $data = [];
    }

    $bodyHtml = $report->rendered_html ?: ($report->template->body_html ?? '');
    $bodyHtml = $this->injectApprovalMarks($bodyHtml, $report);
    $bodyHtml = $this->renderReadOnlyHtml($bodyHtml, $data, $report);
    $bodyHtml = $this->lockAllFields($bodyHtml);

    $document = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $bodyHtml . '</body></html>';
    $downloadName = 'approved_protocol_' . $report->id . '.doc';

    return response($document, 200, [
        'Content-Type' => 'application/msword; charset=UTF-8',
        'Content-Disposition' => 'attachment; filename="' . $downloadName . '"',
    ]);
}
public function downloadPDF($id)
{
    $report = Report::with('template')->findOrFail($id);
     $report->load(['template','approvalSteps']);

    $data = $report->data ?? [];
    if (is_string($data)) $data = json_decode($data, true) ?? [];
    if (!is_array($data)) $data = [];
$bodyHtml = $report->rendered_html ?: ($report->template->body_html ?? '');
     $bodyHtml = $this->injectApprovalMarks($bodyHtml, $report);
     $bodyHtml = $this->renderReadOnlyHtml($bodyHtml, $data, $report);
        $bodyHtml = $this->lockAllFields($bodyHtml);
     $bodyHtml = preg_replace_callback('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', function($m){
    $tag = $m[0];
    $src = $m[1];

    // if logo is your localhost path
    if (str_contains($src, '/admin/img/lupin1.png')) {
        $localPath = public_path('admin/img/lupin1.png'); // ✅ correct path
        return str_replace($src, $localPath, $tag);
    }

    // if logo is storage path
    if (str_contains($src, '/storage/lupin.png')) {
        $localPath = storage_path('app/public/lupin.png'); // ✅ storage path
        return str_replace($src, $localPath, $tag);
    }

    return $tag;
}, $bodyHtml);

    //odyHtml = $report->rendered_html ?: $report->template->body_html;

    // Replace placeholders (example)
    $replace = [
        '{{customer}}'   => $report->customer_name,
        '{{total}}'      => $report->total,
        '{{invoice_no}}' => $report->invoice_no,
    ];
$css = '
<style> input, .input, .input-sm, .input-lg, .input-num, .input-date, select {
  width: 100%;
    border: none;
    padding: 4px 6px;
    background: transparent;
    font: inherit;
    color: inherit;
    outline: none;}
</style>
';
$bodyHtml = $css . $bodyHtml;
    $bodyHtml = str_replace(array_keys($replace), array_values($replace), $bodyHtml);

//print_r($bodyHtml); exit;
    $pdf = Pdf::loadHTML($bodyHtml)->setPaper('A4');
    $pdf->render();

$canvas = $pdf->getDomPDF()->getCanvas();
$width = $canvas->get_width();
$height = $canvas->get_height();

$font = null; // default font

// First line (Note)
// $canvas->page_text(
//     40,
//     $height - 60,
//     "Note: Replace sample values with actual data. Use the toolbar to auto-fill today's date or to clear all fields. Printing hides the toolbar and form outlines.",
//     $font,
//     8,
//     [0, 0, 0]
// );

// Second line (Document Info - Centered)
$canvas->page_text(
    $width / 2 - 120,
    $height - 40,
    "ANNEX_xxx_xxx_XXXXX | (1.0) | SOP_XXXX_XXX_00000 | CLASS I",
    $font,
    9,
    [0, 0, 0]
);

// Third line (Page Number - Right aligned)
$canvas->page_text(
    $width - 120,
    $height - 20,
    "Page {PAGE_NUM} of {PAGE_COUNT}",
    $font,
    9,
    [0, 0, 0]
);

    return $pdf->download("report-{$report->id}.pdf");
}


public function allProtocol(Request $request)
{
    $approvedReports = $this->buildProtocolFilteredQuery($request)
        ->orderByDesc('id')
        ->paginate(20)
        ->withQueryString();

    return view('reports.pre_approved', compact('approvedReports'));
}

public function preApproved(Request $request)
{
    $approvedReports = $this->buildProtocolFilteredQuery($request, [
            'status' => 'pre_approved',
            'stage'  => 'pre',
        ])
        ->orderByDesc('id')
        ->paginate(20)
        ->withQueryString();

    return view('reports.pre_approved', compact('approvedReports'));
}

public function approvedProtocol(Request $request)
{
     $approvedReports = $this->buildProtocolFilteredQuery($request, [
            'status' => 'approved',
        ])
        ->orderByDesc('id')
        ->paginate(20)
        ->withQueryString();

    return view('reports.approve_list', compact('approvedReports'));
}

public function PostproccesingPotocol(Request $request)
{
     $approvedReports = $this->buildProtocolFilteredQuery($request, [
            'status' => 'post_pending',
            'stage' => 'post',
        ])
        ->orderByDesc('id')
        ->paginate(20)
        ->withQueryString();

    return view('reports.processinglist', compact('approvedReports'));
}

public function proccesingPotocol(Request $request)
{
     $approvedReports = $this->buildProtocolFilteredQuery($request, [
            'status' => 'pre_pending',
            'stage' => 'pre',
        ])
        ->orderByDesc('id')
        ->paginate(20)
        ->withQueryString();

    return view('reports.processinglist', compact('approvedReports'));
}

public function myApprovalDashboard()
{
    $userId = auth()->id();
    $user = auth()->user();
    $isAdminDashboard = $user && method_exists($user, 'isAdmin') && $user->isAdmin();

    if ($isAdminDashboard) {
        $pending = Report::query()
            ->whereIn('status', ['pre_pending', 'post_pending'])
            ->with([
                'template:id,name',
                'creator:id,first_name,last_name',
                'approvalSteps' => function ($query) {
                    $query->with('user:id,first_name,last_name')
                        ->orderBy('stage')
                        ->orderBy('step_order');
                },
            ])
            ->orderByDesc('updated_at')
            ->paginate(5, ['*'], 'pending_page');

        $approvedByMe = Report::query()
            ->where('status', 'approved')
            ->with(['template:id,name', 'creator:id,first_name,last_name'])
            ->orderByDesc('updated_at')
            ->paginate(5, ['*'], 'approved_page');

        $rejectedByMe = Report::query()
            ->whereIn('status', ['pre_returned', 'post_returned', 'returned_to_preparer'])
            ->with(['template:id,name', 'creator:id,first_name,last_name'])
            ->orderByDesc('updated_at')
            ->paginate(5, ['*'], 'rejected_page');
    } else {
        // 1) Pending (ONLY current step user)
        $pending = Report::query()
            ->whereIn('status', ['pre_pending','post_pending'])
            ->whereHas('approvalSteps', function ($q) use ($userId) {
                $q->where('user_id', $userId)
                  ->whereNull('approved_at')
                  ->whereNull('rejected_at');
            })
            ->with(['template'])
            ->orderByDesc('id')
            ->paginate(5, ['*'], 'pending_page');

        // 2) Approved by me
        $approvedByMe = Report::query()
            ->whereHas('approvalSteps', function ($q) use ($userId) {
                $q->where('approved_by', $userId)
                  ->whereNotNull('approved_at');
            })
            ->with(['template'])
            ->orderByDesc('id')
            ->paginate(5, ['*'], 'approved_page');

        // 3) Rejected by me
        $rejectedByMe = Report::query()
            ->whereHas('approvalSteps', function ($q) use ($userId) {
                $q->where('rejected_by', $userId)
                  ->whereNotNull('rejected_at');
            })
            ->with(['template'])
            ->orderByDesc('id')
            ->paginate(5, ['*'], 'rejected_page');
    }

    $adminProtocolStats = null;
    $adminProtocolActivity = null;

    if ($isAdminDashboard) {
        $protocolQuery = Report::query();

        $adminProtocolStats = [
            'total' => (clone $protocolQuery)->count(),
            'pending' => (clone $protocolQuery)->whereIn('status', ['pre_pending', 'post_pending'])->count(),
            'approved' => (clone $protocolQuery)->where('status', 'approved')->count(),
            'returned' => (clone $protocolQuery)->whereIn('status', ['pre_returned', 'post_returned', 'returned_to_preparer'])->count(),
        ];

        $adminProtocolActivity = Report::query()
            ->with([
                'template:id,name',
                'creator:id,first_name,last_name',
                'document:id,title,doc_title',
                'approvalSteps' => function ($query) {
                    $query->with('user:id,first_name,last_name')
                        ->orderBy('stage')
                        ->orderBy('step_order');
                },
            ])
            ->orderByDesc('updated_at')
            ->paginate(5, ['*'], 'activity_page');
    }

    return view('home', compact('pending', 'approvedByMe', 'rejectedByMe', 'adminProtocolStats', 'adminProtocolActivity', 'isAdminDashboard'));
}

public function reportfillAfterPreaAprove(Report $report)
{
    abort_unless($report->stage === 'pre' && $report->status === 'pre_approved', 403);

    $report->load('template');

    if ($report->template && $report->template->template_type === 'docx'
        && $report->docx_import_path) {
        return redirect()->route('reports.docx-import.fill-form', $report);
    }

    $template = $report->template;

    $data = $report->data ?? [];
    if (is_string($data)) $data = json_decode($data, true) ?? [];
    if (!is_array($data)) $data = [];

    $users = $this->getTemplateUsers();
    $documents = $this->getTemplateDocuments();

    // hydrate selects + inject saved values
    $bodyHtml = $this->hydrateDynamicSelects($report->template->body_html, $users, $documents, $data);
    $bodyHtml = $this->injectValuesIntoHtml($bodyHtml, $data);

    // Convert to read-only for preview
    $bodyHtml = $this->renderReadOnlyHtml($bodyHtml, $data, $report);
    $bodyHtml = $this->lockAllFields($bodyHtml);

    return view('reports.preapprovalfill', compact('report', 'template', 'bodyHtml'));
}

public function editReturnedPre(Report $report)
{
   if (
    $report->status !== 'pre_returned' ||
    (int)$report->created_by !== (int)auth()->id()
) {
    return redirect()->route('reports.show', $report)->with('error', 'This report is no longer in returned state.');;
}

    $report->load('template');

    $data = $report->data ?? [];
    if (is_string($data)) $data = json_decode($data, true) ?? [];
    if (!is_array($data)) $data = [];

    // ✅ hydrate selects (so HTML contains options) then inject saved values
    $users = $this->getTemplateUsers();
    $documents = $this->getTemplateDocuments();

    $bodyHtml = $this->hydrateDynamicSelects($report->template->body_html, $users, $documents, $data);
    $bodyHtml = $this->injectValuesIntoHtml($bodyHtml, $data);

    // ✅ IMPORTANT:
    // Keep user selections as TEXT only (not editable dropdown)
    $bodyHtml = $this->renderSelectsAsTextOnly($bodyHtml, $data);

    // ✅ BUT keep rest fields editable (creator can edit + add row/col etc.)
    // (Do not lock all fields here)

    return view('reports.edit_returned_pre', compact('report', 'bodyHtml'));
}
private function makeUserSelectsReadOnlyText(string $html, array $data): string
{
    libxml_use_internal_errors(true);

    $doc = new \DOMDocument('1.0','UTF-8');
    $doc->loadHTML('<!DOCTYPE html><html><body>'.$html.'</body></html>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $xp = new \DOMXPath($doc);

    $userMap = $this->getTemplateUsers()->keyBy('id');

    foreach ($xp->query('//select') as $select) {

        $name = (string)$select->getAttribute('name');
        if ($name === '') continue;

        // ✅ Only these are user-selection fields
        $isUserSelect =
            str_starts_with($name, 'checked_by_') ||
            str_starts_with($name, 'approved_by_') ||
            str_starts_with($name, 'post_prepared_by_') ||
            str_starts_with($name, 'post_checked_by_') ||
            str_starts_with($name, 'post_approved_by_') ||
            ((string)$select->getAttribute('data-options') === 'users');

        if (!$isUserSelect) continue;

        $selected = (string)($data[$name] ?? '');

        $label = '';
        if ($selected !== '' && is_numeric($selected)) {
            $u = $userMap->get((int)$selected);
            $label = $u ? $this->formatUserOptionLabel($u) : $selected;
        }

        $strong = $doc->createElement('strong', htmlspecialchars($label));
        $strong->setAttribute('style','display:inline-block; min-width:220px; border-bottom:1px dotted #999; padding:2px 4px;');

        $select->parentNode->replaceChild($strong, $select);
    }

    $body = $doc->getElementsByTagName('body')->item(0);
    $out = '';
    foreach ($body->childNodes as $child) $out .= $doc->saveHTML($child);
    return $out;
}

private function renderSelectsAsTextOnly(string $html, array $data): string
{
    libxml_use_internal_errors(true);

    $doc = new \DOMDocument('1.0','UTF-8');
    $doc->loadHTML('<!DOCTYPE html><html><body>'.$html.'</body></html>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $xp = new \DOMXPath($doc);

    $userMap = $this->getTemplateUsers()->keyBy('id');
    $docMap  = class_exists(\App\Models\Document::class)
        ? \App\Models\Document::select('id','title','doc_title')->get()->keyBy('id')
        : collect();

    foreach ($xp->query('//select') as $select) {
        $name = (string)$select->getAttribute('name');
        $val  = (string)($data[$name] ?? '');
        $opt  = (string)$select->getAttribute('data-options');

        $label = '';

        // saved text preferred
        if ($name !== '' && !empty($data[$name.'_text'])) {
            $label = (string)$data[$name.'_text'];
        }
        // user dropdown
        elseif (
            $opt === 'users' ||
            str_starts_with($name, 'checked_by_') ||
            str_starts_with($name, 'approved_by_') ||
            str_starts_with($name, 'post_')
        ) {
            if ($val !== '') {
                $u = $userMap->get((int)$val);
                $label = $u ? $this->formatUserOptionLabel($u) : $val;
            }
        }
        // document dropdown
        elseif ($opt === 'documents' || $name === 'document_id' || $name === 'document_no') {
            if ($val !== '') {
                $d = $docMap->get((int)$val);
                $label = $d ? $this->formatDocumentOptionLabel($d) : $val;
            }
        } else {
            $label = $val;
        }

        $strong = $doc->createElement('strong', htmlspecialchars($label));
        $strong->setAttribute('style','display:inline-block; min-width:180px; border-bottom:1px dotted #999; padding:2px 4px;');
        $select->parentNode->replaceChild($strong, $select);
    }

    $body = $doc->getElementsByTagName('body')->item(0);
    $out = '';
    foreach ($body->childNodes as $child) $out .= $doc->saveHTML($child);
    return $out;
}

private function convertUserSelectsToText(string $html, array $data, $users): string
{
    libxml_use_internal_errors(true);

    $doc = new \DOMDocument('1.0', 'UTF-8');
    $doc->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

    $xpath = new \DOMXPath($doc);

    $userSelects = $xpath->query(
        '//select[
            @data-options="users"
            or starts-with(@name,"checked_by_")
            or starts-with(@name,"approved_by_")
            or starts-with(@name,"post_checked_by_")
            or starts-with(@name,"post_prepared_by_")
            or starts-with(@name,"post_approved_by_")
        ]'
    );

    foreach ($userSelects as $select) {

        $name = (string)$select->getAttribute('name');
        $selectedValue = $data[$name] ?? null;

        $label = '';

        if ($selectedValue && isset($users[$selectedValue])) {
            $u = $users[$selectedValue];
            $label = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''));
        }

        // Create text node
        $span = $doc->createElement('span', htmlspecialchars($label));
        $span->setAttribute('style',
            'display:inline-block; min-width:180px; border-bottom:1px dotted #999; padding:2px 4px; font-weight:600;'
        );

        $select->parentNode->replaceChild($span, $select);
    }

    $body = $doc->getElementsByTagName('body')->item(0);
    $out = '';
    foreach ($body->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }

    return $out;
}

public function resubmitReturnedPre(Request $request, Report $report)
{
    abort_unless(
        $report->status === 'pre_returned' && (int)$report->created_by === (int)auth()->id(),
        403
    );

    $report->load(['template', 'approvalSteps']);

    $newData = $request->except(['_token']);

    $oldData = $report->data ?? [];
    if (is_string($oldData)) $oldData = json_decode($oldData, true) ?? [];
    if (!is_array($oldData)) $oldData = [];

    // ✅ merge (keep selections etc.)
    $data = array_merge($oldData, $newData);

    // ✅ reset only signatures/dates/remarks in data
    $this->resetApprovalMarksInData($data, 'pre');

    // ✅ reset pre approval steps (DB)
    $report->approvalSteps()->where('stage', 'pre')->update([
        'approved_at' => null,
        'approved_by' => null,
        'rejected_at' => null,
        'rejected_by' => null,
        'remark'      => null,
    ]);

    // ✅ render latest HTML
    $baseHtml  = $report->rendered_html ?: ($report->template->body_html ?? '');
    $rendered  = $this->injectValuesIntoHtml($baseHtml, $data);

    // ✅ move back to approval
    $report->update([
        'data'              => $data,
        'rendered_html'      => $rendered,
        'stage'             => 'pre',
        'status'            => 'pre_pending',
        'current_step_order'=> 1,
    ]);

    return redirect()->route('reports.show', $report)->with('status', 'Re-submitted for PRE approval.');
}

private function resetApprovalMarksInData(array &$data, string $stage): void
{
    $prefixes = $stage === 'pre'
        ? [
            'checked_by_engineering',
            'checked_by_production',
            'checked_by_validation',
            'approved_by_user',
            'approved_by_quality',
        ]
        : [
            'post_prepared_by',
            'post_checked_by_engineering',
            'post_checked_by_production',
            'post_checked_by_validation',
            'post_approved_by_quality',
        ];

    foreach ($prefixes as $p) {
        unset($data[$p.'_signature']);
        unset($data[$p.'_date']);
        // optional: keep name_text OR remove it
        // unset($data[$p.'_name_text']);
    }
}

public function resubmitPre(Request $request, Report $report)
{
    abort_unless($report->status === 'returned_to_preparer', 403);
    abort_unless((int)$report->created_by === (int)auth()->id(), 403);

    $report->load(['template','approvalSteps']);

    // take new data
    $newData = $request->except(['_token']);

    $oldData = $report->data ?? [];
    if (is_string($oldData)) $oldData = json_decode($oldData, true) ?? [];
    if (!is_array($oldData)) $oldData = [];

    // ✅ merge: keep existing user selections, update edited fields
    $data = array_merge($oldData, $newData);

    // ✅ handle new uploads (append)
    $attachments = $this->handleAttachments($request, $report);

    // render html
    $baseHtml = $report->template->body_html ?? '';
    $rendered = $this->injectValuesIntoHtml($baseHtml, $data);

    // ✅ reset PRE steps approvals (keep same users)
    $report->approvalSteps()
        ->where('stage','pre')
        ->update([
            'approved_at' => null,
            'approved_by' => null,
            'rejected_at' => null,
            'rejected_by' => null,
            'remark'      => null,
        ]);

    // set current step = first
    $first = $report->approvalSteps()
        ->where('stage','pre')
        ->orderBy('step_order')
        ->first();

    $report->update([
        'data'              => $data,
        'rendered_html'      => $rendered,
        'attachments'        => $attachments,
        'stage'              => 'pre',
        'status'             => 'pre_pending',
        'current_step_order' => $first?->step_order ?? 1,
    ]);

    return redirect()->route('reports.show', $report)->with('status', 'Resubmitted! PRE approval restarted from Step 1.');
}

/**
 * canEditReturnedReport() — Creator (or admin) may edit a returned PRE/POST protocol.
 */
private function canEditReturnedReport(Report $report, string $stage = 'post'): bool
{
    $expectedStatus = $stage === 'pre' ? 'pre_returned' : 'post_returned';
    if ($report->status !== $expectedStatus) {
        return false;
    }

    if ((int) $report->created_by === (int) auth()->id()) {
        return true;
    }

    $user = auth()->user();

    return $user && method_exists($user, 'isAdmin') && $user->isAdmin();
}

public function editReturnedPost(Report $report)
{
    if (! $this->canEditReturnedReport($report, 'post')) {
        return redirect()
            ->route('reports.show', $report)
            ->with('error', 'Only the report creator can edit a returned POST protocol. '
                . 'Please sign in as the creator (report owner) to make corrections.');
    }

    $report->load('template');

    // DOCX import reports use the fill form, not the HTML template editor.
    if ($report->docx_import_path) {
        return redirect()
            ->route('reports.docx-import.fill-form', $report)
            ->with('status', 'Update the returned document and resubmit for POST approval.');
    }

    $data = $report->data ?? [];
    if (is_string($data)) $data = json_decode($data, true) ?? [];
    if (!is_array($data)) $data = [];

    $users = $this->getTemplateUsers();
    $documents = $this->getTemplateDocuments();

    $bodyHtml = $this->hydrateDynamicSelects($report->template->body_html, $users, $documents, $data);
    $bodyHtml = $this->injectValuesIntoHtml($bodyHtml, $data);

    // Keep user selections as text only
    $bodyHtml = $this->renderSelectsAsTextOnly($bodyHtml, $data);

    return view('reports.edit_returned_post', compact('report','bodyHtml'));
}
public function resubmitReturnedPost(Request $request, Report $report)
{
    if (! $this->canEditReturnedReport($report, 'post')) {
        return redirect()
            ->route('reports.show', $report)
            ->with('error', 'Only the report creator can resubmit a returned POST protocol.');
    }

    if ($report->docx_import_path) {
        return redirect()
            ->route('reports.docx-import.fill-form', $report)
            ->with('status', 'Use the DOCX fill form to update and resubmit this returned protocol.');
    }

    $report->load(['template','approvalSteps']);

    // ✅ collect form data
    $newData = $request->except(['_token']);

    $oldData = $report->data ?? [];
    if (is_string($oldData)) $oldData = json_decode($oldData, true) ?? [];
    if (!is_array($oldData)) $oldData = [];

    $data = array_merge($oldData, $newData);

    // ✅ handle NEW attachments
    $newAttachments = [];
    for ($i = 1; $i <= 9; $i++) {
        if ($request->hasFile("attachment_file_$i")) {
            $file = $request->file("attachment_file_$i");
            $originalName = $file->getClientOriginalName();
            $newFileName =  'protocol_'.$report->id.'/'.$file->getClientOriginalName() ;
            $path = $file->storeAs('reports/attachments', $newFileName, 'public');
          //  $path = $file->store('reports/attachments', 'public');

            $newAttachments[] = [
                'index'         => $i,
                'name'          => $request->input("attachment_name_$i"),
                'file_path'     => $path,
                'original_name' => $originalName,
                'stored_name'   => $newFileName,
                'mime'          => $file->getClientMimeType(),
                'size'          => $file->getSize(),
                'uploaded_at'   => now()->toDateTimeString(),
            ];
        }
    }

    // ✅ merge OLD attachments + NEW attachments
    $oldAtt = $report->attachments ?? [];
    if (is_string($oldAtt)) $oldAtt = json_decode($oldAtt, true) ?? [];
    if (!is_array($oldAtt)) $oldAtt = [];

    $attachments = array_values(array_merge($oldAtt, $newAttachments));

    // ✅ Reset post approval marks in JSON again
    $this->resetApprovalMarksInData($data, 'post');

    // ✅ Reset DB post steps
    $report->approvalSteps()
        ->where('stage','post')
        ->update([
            'approved_at' => null,
            'approved_by' => null,
            'rejected_at' => null,
            'rejected_by' => null,
            'remark'      => null,
        ]);

    $baseHtml = $report->template->body_html ?? '';
    $rendered = $this->injectValuesIntoHtml($baseHtml, $data);

    // ✅ update report
    $report->update([
        'data'               => $data,
        'rendered_html'      => $rendered,
        'attachments'        => $attachments,
        'status'             => 'post_pending',
        'current_step_order' => 1,
    ]);

    return redirect()
        ->route('reports.show', $report)
        ->with('status','Post approval restarted from first approver.');
}

public function resubmitPost(Request $request, Report $report)
{
    abort_unless((int)$report->created_by === (int)auth()->id(), 403);

    // simplest: reuse your submitToPost()
    return $this->submitToPost($request, $report);
}

private function resetAllApprovalFields(array &$data): void
{
    // These prefixes match your template field names
    $prefixes = [
        // PRE
        'checked_by_engineering',
        'checked_by_production',
        'checked_by_validation',
        'approved_by_user',
        'approved_by_quality',

        // POST
        'post_prepared_by',
        'post_checked_by_engineering',
        'post_checked_by_production',
        'post_checked_by_validation',
        'post_approved_by_quality',
    ];

    foreach ($prefixes as $p) {
        unset(
            $data[$p . '_signature'],
            $data[$p . '_date'],
            $data[$p . '_name_text']
        );
    }

    // optional: keep prepared_by_name but clear signature/date
    unset($data['prepared_by_signature'], $data['prepared_by_date']);
}

private function clearDecisionFields(array $data, string $stage): array
{
    $prefixes = $stage === 'pre'
        ? [
            'checked_by_engineering',
            'checked_by_production',
            'checked_by_validation',
            'approved_by_user',
            'approved_by_quality',
          ]
        : [
            'post_prepared_by',
            'post_checked_by_engineering',
            'post_checked_by_production',
            'post_checked_by_validation',
            'post_approved_by_quality',
          ];

    foreach ($prefixes as $p) {
        unset($data[$p.'_signature']);
        unset($data[$p.'_date']);
        unset($data[$p.'_name_text']);
    }

    return $data;
}

/**
 * disableContentEditable() — force contenteditable=false on template HTML fragments.
 *
 * @param  string  $html  Rendered report body HTML
 * @return string Same HTML with contenteditable disabled
 *
 * Encoding note: MUST use wrapHtmlForDom() — parsing UTF-8 without charset corrupts
 * Name & Designation text that was correctly rendered by renderReadOnlyHtml().
 */
private function disableContentEditable(string $html): string
{
    libxml_use_internal_errors(true);

    $doc = new \DOMDocument('1.0', 'UTF-8');

    $doc->loadHTML(
        $this->wrapHtmlForDom($html),
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );

    $xpath = new \DOMXPath($doc);

    // Find ALL elements that have contenteditable attribute
    foreach ($xpath->query('//*[@contenteditable]') as $node) {
        $node->setAttribute('contenteditable', 'false');
    }

    $body = $doc->getElementsByTagName('body')->item(0);

    $output = '';
    foreach ($body->childNodes as $child) {
        $output .= $doc->saveHTML($child);
    }

    return $output;
}

private function notifyApprover(\App\Models\User $user, Report $report, string $text): void
{
    $url = route('reports.show', $report);

    $user->notify(new ReportApprovalAssigned(
        report: $report,
        message: $text,
        url: $url
    ));
}


public function myPendingUserApprovals()
{
    $userId = auth()->id();

    $reports = Report::query()
        ->with(['template'])
        ->whereIn('status', ['pre_pending', 'post_pending'])
        ->whereHas('approvalSteps', function ($q) use ($userId) {
            $q->where('user_id', $userId)
              ->whereNull('approved_at')
              ->whereNull('rejected_at')
              // ✅ step must match current stage + current step_order of report
              ->whereColumn('report_approval_steps.stage', 'reports.stage')
              ->whereColumn('report_approval_steps.step_order', 'reports.current_step_order');
        })
        ->orderByDesc('reports.id')
        ->paginate(20);

    return view('reports.my_pending_approvals', compact('reports'));
}

public function preApprovedList(Request $request)
{
    $approvedReports = $this->buildProtocolFilteredQuery($request, [
            'status' => 'pre_approved',
            'stage'  => 'pre',
        ])
        ->orderByDesc('id')
        ->paginate(20)
        ->withQueryString();

    return view('reports.pre_approved', compact('approvedReports'));
}

private function buildProtocolFilteredQuery(Request $request, array $defaults = [])
{
    $query = Report::query()->with(['approvalSteps', 'document', 'template']);

    $hasStatusFilter = $request->filled('status');

    if (!empty($defaults['status']) && !$hasStatusFilter) {
        $query->where('status', $defaults['status']);
    }

    if (!empty($defaults['stage']) && !$hasStatusFilter) {
        $query->where('stage', $defaults['stage']);
    }

    if ($request->filled('search')) {
        $search = trim((string) $request->search);

        $query->where(function ($q) use ($search) {
            if (is_numeric($search)) {
                $q->orWhere('id', (int) $search);
            }

            $q->orWhere('equipment_name', 'like', "%{$search}%")
                ->orWhere('facility_section', 'like', "%{$search}%")
                ->orWhere('equipment_id', 'like', "%{$search}%")
                ->orWhere('document_no', 'like', "%{$search}%")
                ->orWhereHas('template', function ($templateQuery) use ($search) {
                    $templateQuery->where('name', 'like', "%{$search}%");
                });
        });
    }

    if ($request->filled('status')) {
        $query->where('status', $request->status);
    }

    if ($request->filled('from_date')) {
        $query->whereDate('created_at', '>=', $request->from_date);
    }

    if ($request->filled('to_date')) {
        $query->whereDate('created_at', '<=', $request->to_date);
    }

    return $query;
}

}
