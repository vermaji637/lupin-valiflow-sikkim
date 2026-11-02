<?php

namespace App\Http\Controllers;

use App\Models\NewEquipmentQualification;
use App\Models\NewEquipmentQualificationApproval;
use App\Models\User;
use App\Notifications\NeqApprovalAssigned;
use App\Notifications\NeqApprovalCompleted;
use App\Notifications\NeqApprovalDecision;
use App\Services\DocxDownloadProtection;
use App\Services\NeqAuditLogger;
use App\Services\NewEquipmentQualificationDocxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

// Handles upload, preview, edit, and download of New Equipment Qualification Word documents.
class NewEquipmentQualificationController extends Controller
{
    // Show all uploaded NEQ documents on the protocol page (temporary: no per-user filter).
    public function index()
    {
        if (! auth()->user()?->can('protocol-new-equipment')
            && ! auth()->user()?->can('protocol-fill-new-equipment')
            && ! auth()->user()?->can('protocol-neq-view')
            && ! auth()->user()?->can('protocol-neq-download')) {
            abort(403, 'You do not have permission for New Equipment Qualification.');
        }

        // Temporary: show every imported NEQ document in the list (no uploader/assignee filter).
        $allUploads = NewEquipmentQualification::query()
            ->orderByDesc('id')
            ->get();

        $uploadsById = $allUploads->keyBy('id');
        $resolveRootId = function ($record) use ($uploadsById) {
            $current = $record;
            $guard = 0;
            while ($current->source_id && $guard < 25 && $uploadsById->has($current->source_id)) {
                $current = $uploadsById->get($current->source_id);
                $guard++;
            }

            return (int) $current->id;
        };

        $uploads = $allUploads->filter(function ($record) {
            return empty($record->source_id);
        })->values()->map(function ($original) use ($allUploads, $resolveRootId) {
            $descendants = $allUploads->filter(function ($record) use ($original, $resolveRootId) {
                return (int) $record->id !== (int) $original->id
                    && $resolveRootId($record) === (int) $original->id;
            })->sortByDesc('id')->values();
            $original->setRelation('editedCopies', $descendants);

            return $original;
        });

        // Prefer persisted display_seq; backfill / sanitize Document No. on roots.
        foreach ($uploads as $upload) {
            $upload->assignDisplayCodeIfNeeded();
            // Always re-resolve: clears bad PR-WBL-… auto-fills and picks QDS/ANNEX from edits.
            $upload->referenceDocumentNo(true);
        }

        // List newest protocol codes first (NEQ29, NEQ28, …).
        $uploads = $uploads
            ->sortByDesc(function ($upload) {
                return [(int) ($upload->display_seq ?? 0), (int) $upload->id];
            })
            ->values();

        $rootIds = $uploads->pluck('id');
        $approvals = NewEquipmentQualificationApproval::query()
            ->with('user:id,first_name,last_name,email,designation')
            ->whereIn('new_equipment_qualification_id', $rootIds)
            ->orderBy('step_order')
            ->get()
            ->groupBy('new_equipment_qualification_id');

        $neqApprovals = [];
        foreach ($uploads as $upload) {
            $uploadApprovals = $approvals->get($upload->id) ?? collect();
            $upload->setRelation('approvals', $uploadApprovals);
            $neqApprovals[(string) $upload->id] = $this->serializeApprovals($uploadApprovals);
        }

        return view('protocol.new_equipment_qualification.index', [
            'uploads' => $uploads,
            'neqUsers' => $this->approverUsers(),
            'neqApprovals' => $neqApprovals,
        ]);
    }

    // Accept a new DOCX upload after E-Sign password check and store it for this user.
    public function upload(Request $request)
    {
        // Reject upload when the user's E-Sign password does not match their login password.
        if (! Hash::check($request->password, auth()->user()->password)) {
            return back()->with('error', 'Wrong E-Sign password.');
        }

        $request->validate([
            'password'  => ['required', 'string'],
            'docx_file' => ['required', 'file', 'mimes:docx', 'max:20480'],
        ]);

        $file = $request->file('docx_file');
        $originalName = $file->getClientOriginalName();
        $storedName = Str::uuid()->toString() . '.docx';
        $directory = 'new_equipment_qualifications/' . auth()->id();
        Storage::disk('public')->makeDirectory($directory);

        $storedPath = $file->storeAs($directory, $storedName, 'public');

        $record = NewEquipmentQualification::create([
            'original_filename' => $originalName,
            'stored_path'       => $storedPath,
            'uploaded_by'       => auth()->id(),
        ]);
        $record->assignDisplayCodeIfNeeded();

        return redirect()
            ->route('protocol.new-equipment-qualification.index')
            ->with('success', 'File uploaded successfully.');
    }

    // Legacy view route — redirects back to the index list for this record.
    public function view(NewEquipmentQualification $newEquipmentQualification)
    {
        $this->authorizeRecord($newEquipmentQualification, 'view');

        return redirect()->route('protocol.new-equipment-qualification.index');
    }

    // Stream the stored DOCX inline in the browser for preview or embedding.
    public function file(NewEquipmentQualification $newEquipmentQualification): BinaryFileResponse
    {
        $this->authorizeRecord($newEquipmentQualification, 'view');

        $absolutePath = Storage::disk('public')->path($newEquipmentQualification->stored_path);

        if (! is_file($absolutePath)) {
            abort(404, 'File not found.');
        }

        return response()->file($absolutePath, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    // Ask Python to extract header/body images (with crop) for the NEQ visual preview overlay.
    public function previewHtml(NewEquipmentQualification $newEquipmentQualification): JsonResponse
    {
        $this->authorizeRecord($newEquipmentQualification, 'view');

        $absolutePath = Storage::disk('public')->path($newEquipmentQualification->stored_path);

        if (! is_file($absolutePath)) {
            return response()->json(['error' => 'File not found.'], 404);
        }

        $pythonUrl = rtrim(env('PYTHON_NLP_URL', 'http://127.0.0.1:8001'), '/');

        // Python resolves embedded images and returns base64 placements for the preview UI.
        $response = Http::timeout(120)
            ->attach(
                'file',
                file_get_contents($absolutePath),
                $newEquipmentQualification->original_filename
            )
            ->post($pythonUrl . '/docx-image-map');

        if (! $response->ok()) {
            return response()->json([
                'error'   => 'Image resolution failed.',
                'details' => $response->json('detail') ?? $response->body(),
            ], 500);
        }

        return response()->json([
            'placements' => $response->json('placements', []),
            'messages'   => $response->json('messages', []),
        ]);
    }

    // Return DOCX body text targets for frontend edit-index mapping.
    public function textTargets(
        NewEquipmentQualification $newEquipmentQualification,
        NewEquipmentQualificationDocxService $docxService
    ): JsonResponse {
        $this->authorizeRecord($newEquipmentQualification, 'view');

        $absolutePath = Storage::disk('public')->path($newEquipmentQualification->stored_path);

        if (! is_file($absolutePath)) {
            return response()->json(['error' => 'File not found.'], 404);
        }

        try {
            $targets = $docxService->listTextTargets($absolutePath);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'error' => 'Unable to read document text targets. Ensure the Python service is running.',
            ], 500);
        }

        return response()->json([
            'targets' => $targets,
            'count'   => count($targets),
        ]);
    }

    // Apply text edits from the preview UI, save a new DOCX copy, and return download links.
    public function saveAs(
        Request $request,
        NewEquipmentQualification $newEquipmentQualification,
        NewEquipmentQualificationDocxService $docxService
    ): JsonResponse {
        set_time_limit(180);

        $this->authorizeRecord($newEquipmentQualification, 'mutate');

        // Typing autosave does not ask for an e-sign password. Approve, reject,
        // and send-back still do. A password is checked only when one is sent.
        $typedPassword = (string) $request->input('password', '');
        if ($typedPassword !== '' && ! Hash::check($typedPassword, auth()->user()->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Wrong E-Sign password.',
            ], 422);
        }

        $request->validate([
            'password' => ['nullable', 'string'],
            'autosave' => ['nullable'],
            'edits'    => ['nullable', 'array'],
            'edits.*.index' => ['required_with:edits', 'integer', 'min:0'],
            'edits.*.text'  => ['nullable', 'string'],
            'edits.*.original_text' => ['nullable', 'string'],
            'edits.*.zone' => ['nullable', 'string', 'in:header,body,footer'],
            'table_ops' => ['nullable', 'array'],
            'table_ops.*.op' => ['required_with:table_ops', 'string', 'in:insert,delete'],
            'table_ops.*.table' => ['required_with:table_ops', 'integer', 'min:0'],
            'table_ops.*.row' => ['nullable', 'integer', 'min:0'],
            'table_ops.*.after_row' => ['nullable', 'integer', 'min:0'],
            'table_ops.*.values' => ['nullable', 'array'],
            'cell_edits' => ['nullable', 'array'],
            'cell_edits.*.table' => ['nullable', 'integer', 'min:0'],
            'cell_edits.*.row' => ['nullable', 'integer', 'min:0'],
            'cell_edits.*.col' => ['nullable', 'integer', 'min:0'],
            'cell_edits.*.text' => ['nullable', 'string'],
            'cell_edits.*.original_text' => ['nullable', 'string'],
            'cell_edits.*.zone' => ['nullable', 'string', 'in:header,body,footer'],
            'cell_edits.*.match_by_label' => ['nullable', 'boolean'],
            'cell_edits.*.label' => ['nullable', 'string', 'max:255'],
            'cell_edits.*.match_by_headers' => ['nullable', 'boolean'],
            'cell_edits.*.headers' => ['nullable', 'array'],
            'cell_edits.*.headers.*' => ['nullable', 'string', 'max:255'],
            'cell_edits.*.header_row_index' => ['nullable', 'integer', 'min:-1'],
            'cell_edits.*.user_edited' => ['nullable'],
            'cell_edits.*.allow_clear' => ['nullable'],
            'cell_edits.*.allow_banner_edit' => ['nullable'],
            'cell_edits.*.match_abs_row' => ['nullable'],
            'checkbox_ops' => ['nullable', 'array'],
            'checkbox_ops.*.index' => ['nullable', 'integer', 'min:0'],
            'checkbox_ops.*.checked' => ['required', 'boolean'],
            'checkbox_ops.*.table' => ['nullable', 'integer', 'min:0'],
            'checkbox_ops.*.row' => ['nullable', 'integer', 'min:0'],
            'checkbox_ops.*.col' => ['nullable', 'integer', 'min:0'],
            'checkbox_ops.*.original_text' => ['nullable', 'string'],
            'structure_ops' => ['nullable', 'array'],
            'structure_ops.*.op' => ['required_with:structure_ops', 'string', 'in:insert_paragraph,insert_bullet'],
            'structure_ops.*.after_index' => ['required_with:structure_ops', 'integer', 'min:0'],
            'structure_ops.*.text' => ['nullable', 'string'],
            'structure_ops.*.list' => ['nullable', 'boolean'],
            'approvers' => ['nullable', 'array'],
            'approvers.*.user_id' => ['nullable', 'integer'],
            'approvers.*.role' => ['nullable', 'string', 'max:191'],
            'approvers.*.table' => ['required_with:approvers', 'integer', 'min:0'],
            'approvers.*.row' => ['required_with:approvers', 'integer', 'min:0'],
            'approvers.*.name_col' => ['nullable', 'integer', 'min:0'],
            'approvers.*.department_col' => ['nullable', 'integer', 'min:0'],
            'approvers.*.name_includes_designation' => ['nullable', 'boolean'],
            'approvers.*.signature_col' => ['nullable', 'integer', 'min:0'],
            'approvers.*.date_col' => ['nullable', 'integer', 'min:0'],
            'approvers.*.paragraph_index' => ['nullable', 'integer', 'min:0'],
            'approvers.*.qa_stamp' => ['nullable'],
            'approvers.*.section_verified' => ['nullable'],
            'approvers.*.safety_by' => ['nullable'],
            'filename' => ['nullable', 'string', 'max:255'],
        ]);

        $edits = array_values($request->input('edits') ?: []);
        $tableOps = array_values($request->input('table_ops') ?: []);
        $cellEdits = array_values($request->input('cell_edits') ?: []);
        $checkboxOps = array_values(array_filter(
            $request->input('checkbox_ops') ?: [],
            static function ($op) {
                if (! is_array($op) || ! array_key_exists('checked', $op)) {
                    return false;
                }
                if (isset($op['index']) && is_numeric($op['index']) && (int) $op['index'] >= 0) {
                    return true;
                }

                return isset($op['table'], $op['row'], $op['col'])
                    && is_numeric($op['table'])
                    && is_numeric($op['row'])
                    && is_numeric($op['col']);
            }
        ));
        $structureOps = array_values($request->input('structure_ops') ?: []);
        $tocLines = array_values($request->input('toc_lines') ?: []);
        $approvers = array_values($request->input('approvers') ?: []);
        $cellEdits = $this->mergeApproverNameCellEdits($cellEdits, $approvers);
        if ($edits === [] && $tableOps === [] && $cellEdits === [] && $checkboxOps === [] && $structureOps === [] && $tocLines === [] && $approvers === []) {
            return response()->json([
                'success' => false,
                'message' => 'No text changes or row changes to save.',
            ], 422);
        }

        $root = $newEquipmentQualification->rootOriginal();
        if ((int) $root->uploaded_by !== (int) auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Only the document owner can save edits.',
            ], 403);
        }

        $status = $root->workflowStatus();
        if (in_array($status, ['rejected', 'approved'], true)) {
            return response()->json([
                'success' => false,
                'message' => $status === 'rejected'
                    ? 'This document was rejected and cannot be edited.'
                    : 'This document is fully approved and cannot be edited.',
            ], 403);
        }
        if ($status === 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'This document is awaiting approval and cannot be edited.',
            ], 403);
        }

        Log::info('NEQ save-as payload received', [
            'record_id' => $newEquipmentQualification->id,
            'user_id' => auth()->id(),
            'edit_count' => count($edits),
            'cell_edits' => count($cellEdits),
            'table_ops' => count($tableOps),
            'filename' => $request->input('filename'),
            'cell_edits_sample' => array_slice(array_map(static function ($op) {
                if (! is_array($op)) {
                    return $op;
                }

                return [
                    'table' => $op['table'] ?? null,
                    'row' => $op['row'] ?? null,
                    'col' => $op['col'] ?? null,
                    'match_by_label' => $op['match_by_label'] ?? false,
                    'match_by_headers' => $op['match_by_headers'] ?? false,
                    'header_row_index' => $op['header_row_index'] ?? null,
                    'label' => isset($op['label']) ? mb_substr((string) $op['label'], 0, 40) : null,
                    'headers' => isset($op['headers']) && is_array($op['headers'])
                        ? array_slice($op['headers'], 0, 6)
                        : null,
                    'text_len' => mb_strlen((string) ($op['text'] ?? '')),
                    'text_preview' => mb_substr((string) ($op['text'] ?? ''), 0, 40),
                ];
            }, $cellEdits), 0, 12),
        ]);

        // Keep one edited copy per original so the imported list does not grow.
        $baseName = pathinfo($newEquipmentQualification->rootOriginal()->displayName(), PATHINFO_FILENAME);
        $baseName = trim((string) $baseName);
        if ($baseName === '') {
            $baseName = 'edited_document';
        }
        $originalFilename = $baseName . '_edited.docx';

        // Always mutate the latest edited copy (never re-apply onto a blank original
        // when an edited file already exists — that wiped prior fills).
        $root = $newEquipmentQualification->rootOriginal();
        $baseRecord = $root->latestEditedCopy() ?: $root;
        if (! empty($newEquipmentQualification->source_id)) {
            // Saving against an edited record — use that file as the base.
            $baseRecord = $newEquipmentQualification;
        }
        $sourcePath = Storage::disk('public')->path($baseRecord->stored_path);

        if (! is_file($sourcePath)) {
            return response()->json([
                'success' => false,
                'message' => 'Original document file not found on disk.',
            ], 404);
        }

        try {
            // Delegate DOCX mutation to Python (CLI first, HTTP fallback).
            $applyResult = $docxService->applyEditsToDocx(
                $sourcePath,
                $edits,
                $tableOps,
                $cellEdits,
                $checkboxOps,
                $structureOps,
                $tocLines
            );
            $tempDocxPath = $applyResult['path'] ?? null;
            $applyStats = is_array($applyResult['stats'] ?? null) ? $applyResult['stats'] : [];
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to save edited document. Ensure the Python service is running and try again.',
            ], 422);
        }

        if (! is_string($tempDocxPath) || $tempDocxPath === '' || ! is_file($tempDocxPath)) {
            return response()->json([
                'success' => false,
                'message' => 'Edited document was not generated.',
            ], 422);
        }

        $appliedCount = (int) ($applyStats['applied'] ?? 0);
        $rowChanges = (int) ($applyStats['rows_inserted'] ?? 0) + (int) ($applyStats['rows_deleted'] ?? 0);
        $extraOps = (int) ($applyStats['checkbox_applied'] ?? 0) + (int) ($applyStats['structure_applied'] ?? 0);
        if ($appliedCount < 1 && $rowChanges < 1 && $extraOps < 1 && $approvers === []) {
            @unlink($tempDocxPath);

            if ($request->boolean('autosave')) {
                return response()->json([
                    'success' => true,
                    'message' => 'Draft saved.',
                    'saved_at' => now()->format('d-m-Y h:i A'),
                    'stats' => $applyStats,
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'No editable changes were applied to the Word document. Re-open the file, edit again, and retry Save As.',
                'stats'   => $applyStats,
            ], 422);
        }

        try {
            $root = $newEquipmentQualification->rootOriginal();
            $editsJson = json_encode([
                'edits' => $edits,
                'cell_edits' => $cellEdits,
                'table_ops' => $tableOps,
                'approvers' => $approvers,
            ], JSON_UNESCAPED_UNICODE);

            $record = $root->editedCopies()->orderByDesc('id')->first();
            if (! $record) {
                $record = NewEquipmentQualification::query()
                    ->where('uploaded_by', $root->uploaded_by)
                    ->where('id', '!=', $root->id)
                    ->orderByDesc('id')
                    ->get()
                    ->first(function ($item) use ($root) {
                        return (int) $item->rootOriginal()->id === (int) $root->id;
                    });
            }
            if ($record) {
                Storage::disk('public')->put($record->stored_path, file_get_contents($tempDocxPath));
                $record->update([
                    'original_filename' => $originalFilename,
                    'source_id'         => $root->id,
                    'applied_count'     => $appliedCount + $rowChanges,
                    'edits_json'        => $editsJson,
                ]);
                $record->fieldValues()->delete();
            } else {
                $storedName = Str::uuid()->toString() . '.docx';
                $storedPath = 'new_equipment_qualifications/' . auth()->id() . '/' . $storedName;
                Storage::disk('public')->makeDirectory(dirname($storedPath));
                Storage::disk('public')->put($storedPath, file_get_contents($tempDocxPath));

                $record = NewEquipmentQualification::create([
                    'original_filename' => $originalFilename,
                    'stored_path'       => $storedPath,
                    'uploaded_by'       => auth()->id(),
                    'source_id'         => $root->id,
                    'applied_count'     => $appliedCount + $rowChanges,
                    'edits_json'        => $editsJson,
                ]);
            }

            foreach ($edits as $edit) {
                $record->fieldValues()->create([
                    'field_index'   => (int) ($edit['index'] ?? 0),
                    'zone'          => $edit['zone'] ?? 'body',
                    'original_text' => $edit['original_text'] ?? null,
                    'saved_text'    => $edit['text'] ?? null,
                ]);
            }
            foreach ($cellEdits as $cellEdit) {
                $table = (int) ($cellEdit['table'] ?? 0);
                $row = (int) ($cellEdit['row'] ?? 0);
                $col = (int) ($cellEdit['col'] ?? 0);
                $record->fieldValues()->create([
                    'field_index'   => 1000000 + ($table * 10000) + ($row * 100) + $col,
                    'zone'          => $cellEdit['zone'] ?? 'body',
                    'original_text' => $cellEdit['original_text'] ?? null,
                    'saved_text'    => $cellEdit['text'] ?? null,
                ]);
            }
            $this->syncApprovals($root, $approvers);
            // Persist letterhead Reference Document No. on the root for list + notifications.
            $refNo = NewEquipmentQualification::extractReferenceDocumentNoFromPayload([
                'edits' => $edits,
                'cell_edits' => $cellEdits,
            ]);
            $root->syncReferenceDocumentNo($refNo !== '' ? $refNo : null, $editsJson);
            // Save keeps the document editable (draft/returned). Pending only via Send for Approval.
            $root->update([
                'approval_status' => $status === 'returned' ? 'returned' : 'draft',
            ]);
        } finally {
            if (is_file($tempDocxPath)) {
                @unlink($tempDocxPath);
            }
        }

        $record->refresh();
        $root->refresh()->load(['approvals.user']);

        return response()->json($this->documentActionPayload(
            $root,
            $record,
            'Saved. Document ' . $root->displayCode() . ' was updated — the list stays on one row.',
            $applyStats
        ));
    }

    public function sendForApproval(
        Request $request,
        NewEquipmentQualification $newEquipmentQualification
    ): JsonResponse {
        $this->authorizeRecord($newEquipmentQualification, 'mutate');

        $root = $newEquipmentQualification->rootOriginal();
        if ((int) $root->uploaded_by !== (int) auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Only the document owner can send for approval.',
            ], 403);
        }

        $status = $root->workflowStatus();
        if (! in_array($status, ['draft', 'returned'], true)) {
            return response()->json([
                'success' => false,
                'message' => $status === 'pending'
                    ? 'This document is already awaiting approval.'
                    : 'This document cannot be sent for approval in its current status.',
            ], 422);
        }

        if (! Hash::check((string) $request->input('password', ''), auth()->user()->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Wrong E-Sign password.',
            ], 422);
        }

        $request->validate([
            'password' => ['required', 'string'],
            'approvers' => ['nullable', 'array'],
            'approvers.*.user_id' => ['nullable', 'integer'],
            'approvers.*.role' => ['nullable', 'string', 'max:191'],
            'approvers.*.table' => ['required_with:approvers', 'integer', 'min:0'],
            'approvers.*.row' => ['required_with:approvers', 'integer', 'min:0'],
            'approvers.*.name_col' => ['nullable', 'integer', 'min:0'],
            'approvers.*.department_col' => ['nullable', 'integer', 'min:0'],
            'approvers.*.name_includes_designation' => ['nullable', 'boolean'],
            'approvers.*.signature_col' => ['nullable', 'integer', 'min:0'],
            'approvers.*.date_col' => ['nullable', 'integer', 'min:0'],
            'approvers.*.paragraph_index' => ['nullable', 'integer', 'min:0'],
            'approvers.*.qa_stamp' => ['nullable'],
            'approvers.*.section_verified' => ['nullable'],
            'approvers.*.safety_by' => ['nullable'],
        ]);

        $approvers = array_values($request->input('approvers') ?: []);
        if ($approvers !== []) {
            $this->syncApprovals($root, $approvers);
        }

        $root->refresh()->load('approvals');
        $steps = $root->approvals()->orderBy('step_order')->get();
        if ($steps->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Select all approvers before sending for approval.',
            ], 422);
        }

        $missing = $steps->filter(function ($step) {
            return empty($step->user_id);
        });
        if ($missing->isNotEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Select all approvers before sending for approval.',
            ], 422);
        }

        $root->update(['approval_status' => 'pending']);
        // Clear previous send-back / reject notes when starting a fresh approval cycle.
        $root->approvals()->update([
            'remark' => null,
            'rejected_at' => null,
            'rejected_by' => null,
        ]);
        $this->notifyNewNeqAssignees($root, [], true);

        $target = $root->latestEditedCopy() ?: $root;
        $root->refresh()->load(['approvals.user']);

        return response()->json($this->documentActionPayload(
            $root,
            $target,
            'Sent for approval. Editing is locked until an approver sends it back.',
            []
        ));
    }

    public function approve(
        Request $request,
        NewEquipmentQualification $newEquipmentQualification,
        NewEquipmentQualificationDocxService $docxService
    ): JsonResponse {
        set_time_limit(180);
        $this->authorizeRecord($newEquipmentQualification, 'mutate');

        if (! Hash::check($request->password, auth()->user()->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Wrong E-Sign password.',
            ], 422);
        }

        $root = $newEquipmentQualification->rootOriginal();
        $status = $root->workflowStatus();
        if (in_array($status, ['rejected', 'approved'], true)) {
            return response()->json([
                'success' => false,
                'message' => $status === 'rejected'
                    ? 'This document was rejected and is closed.'
                    : 'This document is already fully approved.',
            ], 422);
        }

        $step = $this->currentPendingStep($root);
        if (! $step || (int) $step->user_id !== (int) auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'You are not the current approver.',
            ], 422);
        }

        $target = $root->latestEditedCopy() ?: $root;
        $sourcePath = Storage::disk('public')->path($target->stored_path);
        if (! is_file($sourcePath)) {
            return response()->json([
                'success' => false,
                'message' => 'Document file not found. Save the document first.',
            ], 404);
        }

        $user = auth()->user();
        // Document-flexible: split Name + Department when template has a separate department column.
        [$nameText, $departmentText] = $this->approvalDisplayTexts($user, $step);
        $signatureText = 'Approved';
        $dateText = now()->timezone('Asia/Kolkata')->format('d-m-Y h:i A');
        [$paragraphEdits, $cellEdits] = $this->buildApprovalStampPayload(
            $docxService,
            $sourcePath,
            $step,
            $nameText,
            $signatureText,
            $dateText,
            $departmentText
        );

        try {
            $applyResult = $docxService->applyEditsToDocx($sourcePath, $paragraphEdits, [], $cellEdits);
            $tempDocxPath = $applyResult['path'] ?? null;
            $applyStats = is_array($applyResult['stats'] ?? null) ? $applyResult['stats'] : [];
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to stamp approval into the Word document.',
            ], 422);
        }

        if (! is_string($tempDocxPath) || $tempDocxPath === '' || ! is_file($tempDocxPath)) {
            return response()->json([
                'success' => false,
                'message' => 'Approved document was not generated.',
            ], 422);
        }

        try {
            Storage::disk('public')->put($target->stored_path, file_get_contents($tempDocxPath));
            $step->update([
                'approved_at' => now(),
                'approved_by' => auth()->id(),
                'rejected_at' => null,
                'rejected_by' => null,
                'remark' => null,
            ]);
            $target->update([
                'applied_count' => (int) ($target->applied_count ?? 0) + (int) ($applyStats['applied'] ?? 0),
            ]);
            $root->refresh();
            if ($this->currentPendingStep($root)) {
                $root->update(['approval_status' => 'pending']);
            } else {
                $root->update(['approval_status' => 'approved']);
            }
            $this->notifyAfterNeqApproval($root);
        } finally {
            if (is_file($tempDocxPath)) {
                @unlink($tempDocxPath);
            }
        }

        $target->refresh();
        $root->refresh()->load(['approvals.user']);

        return response()->json($this->documentActionPayload(
            $root,
            $target,
            'Approved. Signature and date were filled for your row.',
            $applyStats
        ));
    }

    public function sendBack(
        Request $request,
        NewEquipmentQualification $newEquipmentQualification,
        NewEquipmentQualificationDocxService $docxService
    ): JsonResponse {
        set_time_limit(180);
        $this->authorizeRecord($newEquipmentQualification, 'mutate');

        $request->validate([
            'remark' => ['required', 'string'],
        ]);

        if (! Hash::check($request->password, auth()->user()->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Wrong E-Sign password.',
            ], 422);
        }

        $root = $newEquipmentQualification->rootOriginal();
        if (in_array($root->workflowStatus(), ['rejected', 'approved'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'This document cannot be sent back.',
            ], 422);
        }

        $step = $this->currentPendingStep($root);
        if (! $step || (int) $step->user_id !== (int) auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'You are not the current approver.',
            ], 422);
        }

        $remark = trim((string) $request->input('remark'));
        $actor = auth()->user();
        $priorApprovers = $this->priorNeqApprovers($root);
        $root->load('approvals');
        $target = $root->latestEditedCopy() ?: $root;
        $applyStats = [];

        // Persist the send-back note on the acting step before resetting the chain.
        $step->update([
            'remark' => $remark,
        ]);

        $clearEdits = $this->clearApprovalStampCellEdits($root);
        $sourcePath = Storage::disk('public')->path($target->stored_path);
        if ($clearEdits !== [] && is_file($sourcePath)) {
            try {
                $applyResult = $docxService->applyEditsToDocx($sourcePath, [], [], $clearEdits);
                $tempDocxPath = $applyResult['path'] ?? null;
                $applyStats = is_array($applyResult['stats'] ?? null) ? $applyResult['stats'] : [];
                if (is_string($tempDocxPath) && $tempDocxPath !== '' && is_file($tempDocxPath)) {
                    Storage::disk('public')->put($target->stored_path, file_get_contents($tempDocxPath));
                    @unlink($tempDocxPath);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // Reset approval stamps for resubmit, but keep this send-back remark on the acting step.
        $root->approvals()
            ->where('id', '!=', $step->id)
            ->update([
                'approved_at' => null,
                'approved_by' => null,
                'rejected_at' => null,
                'rejected_by' => null,
                'remark' => null,
            ]);
        $step->update([
            'approved_at' => null,
            'approved_by' => null,
            'rejected_at' => null,
            'rejected_by' => null,
            'remark' => $remark,
        ]);
        $root->update(['approval_status' => 'returned']);

        NeqAuditLogger::recordDecision(
            $root,
            'sent_back',
            $remark,
            $this->formatUserName($actor)
        );
        $this->notifyNeqDecision($root, $remark, $actor, $priorApprovers, 'sent_back');

        $target->refresh();
        $root->refresh()->load(['approvals.user']);

        return response()->json($this->documentActionPayload(
            $root,
            $target,
            'Sent back for correction. The uploader can edit and resubmit.',
            $applyStats
        ));
    }

    public function reject(
        Request $request,
        NewEquipmentQualification $newEquipmentQualification,
        NewEquipmentQualificationDocxService $docxService
    ): JsonResponse {
        set_time_limit(180);
        $this->authorizeRecord($newEquipmentQualification, 'mutate');

        $request->validate([
            'remark' => ['required', 'string'],
        ]);

        if (! Hash::check($request->password, auth()->user()->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Wrong E-Sign password.',
            ], 422);
        }

        $root = $newEquipmentQualification->rootOriginal();
        if (in_array($root->workflowStatus(), ['rejected', 'approved'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'This document is already closed.',
            ], 422);
        }

        $step = $this->currentPendingStep($root);
        if (! $step || (int) $step->user_id !== (int) auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'You are not the current approver.',
            ], 422);
        }

        $remark = trim((string) $request->input('remark'));
        $actor = auth()->user();
        $priorApprovers = $this->priorNeqApprovers($root);
        $target = $root->latestEditedCopy() ?: $root;
        $applyStats = [];
        $sourcePath = Storage::disk('public')->path($target->stored_path);
        $dateText = now()->timezone('Asia/Kolkata')->format('d-m-Y h:i A');
        [$nameText, $departmentText] = $this->approvalDisplayTexts($actor, $step);
        $paragraphEdits = [];
        $cellEdits = [];
        if (is_file($sourcePath)) {
            [$paragraphEdits, $cellEdits] = $this->buildApprovalStampPayload(
                $docxService,
                $sourcePath,
                $step,
                $nameText,
                'Rejected',
                $dateText,
                $departmentText
            );
            try {
                $applyResult = $docxService->applyEditsToDocx($sourcePath, $paragraphEdits, [], $cellEdits);
                $tempDocxPath = $applyResult['path'] ?? null;
                $applyStats = is_array($applyResult['stats'] ?? null) ? $applyResult['stats'] : [];
                if (is_string($tempDocxPath) && $tempDocxPath !== '' && is_file($tempDocxPath)) {
                    Storage::disk('public')->put($target->stored_path, file_get_contents($tempDocxPath));
                    @unlink($tempDocxPath);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $step->update([
            'rejected_at' => now(),
            'rejected_by' => auth()->id(),
            'remark' => $remark,
        ]);
        $root->update(['approval_status' => 'rejected']);

        NeqAuditLogger::recordDecision(
            $root,
            'rejected',
            $remark,
            $this->formatUserName($actor)
        );
        $this->notifyNeqDecision($root, $remark, $actor, $priorApprovers, 'rejected');

        $target->refresh();
        $root->refresh()->load(['approvals.user']);

        return response()->json($this->documentActionPayload(
            $root,
            $target,
            'Rejected. This document is closed and cannot be edited or resubmitted.',
            $applyStats
        ));
    }

    public function download(NewEquipmentQualification $newEquipmentQualification): BinaryFileResponse
    {
        $this->authorizeRecord($newEquipmentQualification, 'download');

        $root = $newEquipmentQualification->rootOriginal();
        if (! $root->isFullyApproved()) {
            abort(403, 'Download is available only after the document is fully approved.');
        }

        $absolutePath = Storage::disk('public')->path($newEquipmentQualification->stored_path);

        if (! is_file($absolutePath)) {
            abort(404, 'File not found.');
        }

        $stampedPath = $this->docxWithFinalApprovalStamps($root, $absolutePath);
        $downloadPath = $stampedPath ?: $absolutePath;
        $lockedPath = app(DocxDownloadProtection::class)->protectedCopy($downloadPath);
        if ($stampedPath && $stampedPath !== $absolutePath && $stampedPath !== $lockedPath) {
            @unlink($stampedPath);
        }

        return response()->download(
            $lockedPath,
            $newEquipmentQualification->original_filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']
        )->deleteFileAfterSend($lockedPath !== $absolutePath);
    }

    private function docxWithFinalApprovalStamps(NewEquipmentQualification $root, string $absolutePath): ?string
    {
        $root->loadMissing(['approvals.user']);
        $cellEdits = [];
        $paragraphEdits = [];
        $docxService = app(NewEquipmentQualificationDocxService::class);

        foreach ($root->approvals as $step) {
            if (! $step->approved_at || ! $step->user) {
                continue;
            }
            [$nameText, $departmentText] = $this->approvalDisplayTexts($step->user, $step);
            $signatureText = 'Approved';
            $dateText = $step->approved_at->timezone('Asia/Kolkata')->format('d-m-Y h:i A');
            [$stepParagraphs, $stepCells] = $this->buildApprovalStampPayload(
                $docxService,
                $absolutePath,
                $step,
                $nameText,
                $signatureText,
                $dateText,
                $departmentText
            );
            foreach ($stepParagraphs as $edit) {
                $paragraphEdits[] = $edit;
            }
            foreach ($stepCells as $edit) {
                $cellEdits[] = $edit;
            }
        }

        if ($cellEdits === [] && $paragraphEdits === []) {
            return null;
        }

        try {
            $applyResult = $docxService->applyEditsToDocx($absolutePath, $paragraphEdits, [], $cellEdits);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        $path = $applyResult['path'] ?? null;

        return is_string($path) && $path !== '' && is_file($path) ? $path : null;
    }

    private function currentPendingStep(NewEquipmentQualification $root): ?NewEquipmentQualificationApproval
    {
        return NewEquipmentQualificationApproval::query()
            ->where('new_equipment_qualification_id', $root->id)
            ->whereNotNull('user_id')
            ->whereNull('approved_at')
            ->whereNull('rejected_at')
            ->orderBy('step_order')
            ->first();
    }

    private function priorNeqApprovers(NewEquipmentQualification $root)
    {
        $userIds = $root->approvals()
            ->whereNotNull('approved_at')
            ->pluck('user_id')
            ->unique()
            ->filter()
            ->values();

        if ($userIds->isEmpty()) {
            return collect();
        }

        return User::whereIn('id', $userIds)->get();
    }

    private function isQaApprovalStep(NewEquipmentQualificationApproval $step): bool
    {
        if ((int) $step->table_index >= 900000) {
            return true;
        }

        // Only the body line "Validation /Quality Assurance:" is a paragraph stamp.
        // Department rows such as "User / Validation" and "Quality Assurance"
        // are ordinary approval-table cells.
        return (bool) preg_match(
            '/^validation\s*\/\s*quality\s*assurance\b/i',
            trim((string) ($step->role_label ?? ''))
        );
    }

    private function isSectionVerifiedApprovalStep(NewEquipmentQualificationApproval $step): bool
    {
        return (bool) preg_match('/^verified\s*by\b/i', (string) ($step->role_label ?? ''));
    }

    private function isSafetyByApprovalStep(NewEquipmentQualificationApproval $step): bool
    {
        return (bool) preg_match('/^safety(\s*checks?)?\s*by\b/i', (string) ($step->role_label ?? ''));
    }

    private function approvalSignerStamp(string $nameText, string $signatureText, string $dateText): string
    {
        $name = trim($nameText);
        $when = trim($dateText);
        if (strcasecmp(trim($signatureText), 'Rejected') === 0) {
            return trim($name . ' / Rejected' . ($when !== '' ? ' / ' . $when : ''));
        }

        return trim($name . ($when !== '' ? "\n" . $when : ''));
    }

    private function safetyByStampLine(string $nameText, string $signatureText, string $dateText): string
    {
        return 'Safety By (Sign./Date) : ' . str_replace("\n", ' ', $this->approvalSignerStamp($nameText, $signatureText, $dateText));
    }

    private function sectionVerifiedStampLine(string $nameText, string $signatureText, string $dateText): string
    {
        return 'Verified by sign and date : ' . str_replace("\n", ' ', $this->approvalSignerStamp($nameText, $signatureText, $dateText));
    }

    private function sectionVerifiedStampCellEdit(
        NewEquipmentQualificationApproval $step,
        string $text
    ): array {
        return [
            'table' => (int) $step->table_index,
            'row' => (int) $step->row_index,
            'col' => 0,
            'text' => $text,
            'original_text' => '',
            'zone' => 'body',
            'allow_banner_edit' => true,
            'match_abs_row' => true,
            'user_edited' => true,
        ];
    }

    private function safetyByStampCellEdit(
        NewEquipmentQualificationApproval $step,
        string $text
    ): array {
        // Same as Verified-by: one full-width merged cell (no column borders).
        return [
            'table' => (int) $step->table_index,
            'row' => (int) $step->row_index,
            'col' => 0,
            'text' => $text,
            'original_text' => '',
            'zone' => 'body',
            'allow_banner_edit' => true,
            'match_abs_row' => true,
            'user_edited' => true,
        ];
    }

    private function findQaStampTargetIndex(
        NewEquipmentQualificationDocxService $docxService,
        string $sourcePath,
        ?int $preferredIndex = null
    ): ?int {
        try {
            $targets = $docxService->listTextTargets($sourcePath);
        } catch (\Throwable $e) {
            report($e);

            return $preferredIndex !== null && $preferredIndex >= 0 ? $preferredIndex : null;
        }

        $qaMatches = [];
        foreach ($targets as $target) {
            if (! is_array($target)) {
                continue;
            }
            $index = isset($target['index']) ? (int) $target['index'] : -1;
            $text = strtolower(trim((string) ($target['text'] ?? '')));
            if ($index < 0 || $text === '') {
                continue;
            }
            if (preg_match('/validation\s*\/?\s*quality\s*assurance/', $text)) {
                $qaMatches[] = $index;
            }
        }

        if ($preferredIndex !== null && $preferredIndex >= 0 && in_array($preferredIndex, $qaMatches, true)) {
            return $preferredIndex;
        }
        if ($qaMatches !== []) {
            return $qaMatches[0];
        }
        if ($preferredIndex !== null && $preferredIndex >= 0) {
            return $preferredIndex;
        }

        return null;
    }

    private function approvalStampParagraphEdits(
        NewEquipmentQualificationApproval $step,
        string $nameText,
        string $signatureText,
        string $dateText,
        ?int $resolvedIndex = null
    ): array {
        $index = $resolvedIndex;
        if ($index === null && $step->paragraph_index !== null) {
            $index = (int) $step->paragraph_index;
        }
        if ($index === null || $index < 0) {
            return [];
        }

        $line = 'Validation /Quality Assurance: '
            . str_replace("\n", ' ', $this->approvalSignerStamp($nameText, $signatureText, $dateText));

        return [[
            'index' => $index,
            'text' => $line,
            'original_text' => '',
            'zone' => 'body',
            'match_qa_line' => true,
        ]];
    }

    private function buildApprovalStampPayload(
        NewEquipmentQualificationDocxService $docxService,
        string $sourcePath,
        NewEquipmentQualificationApproval $step,
        string $nameText,
        string $signatureText,
        string $dateText,
        ?string $departmentText = null
    ): array {
        if ($this->isQaApprovalStep($step)) {
            $preferred = $step->paragraph_index !== null ? (int) $step->paragraph_index : null;
            $resolved = $this->findQaStampTargetIndex($docxService, $sourcePath, $preferred);
            if ($resolved !== null && (int) ($step->paragraph_index ?? -1) !== $resolved) {
                $step->paragraph_index = $resolved;
                $step->save();
            }
            $paragraphEdits = $this->approvalStampParagraphEdits(
                $step,
                $nameText,
                $signatureText,
                $dateText,
                $resolved
            );

            return [$paragraphEdits, []];
        }

        return [
            [],
            $this->approvalStampCellEdits($step, $nameText, $signatureText, $dateText, $departmentText),
        ];
    }

    private function approvalStampCellEdits(
        NewEquipmentQualificationApproval $step,
        string $nameText,
        string $signatureText,
        string $dateText,
        ?string $departmentText = null
    ): array {
        if ($this->isSectionVerifiedApprovalStep($step)) {
            return [
                $this->sectionVerifiedStampCellEdit(
                    $step,
                    $this->sectionVerifiedStampLine($nameText, $signatureText, $dateText)
                ),
            ];
        }

        if ($this->isSafetyByApprovalStep($step)) {
            return [
                $this->safetyByStampCellEdit(
                    $step,
                    $this->safetyByStampLine($nameText, $signatureText, $dateText)
                ),
            ];
        }

        $table = (int) $step->table_index;
        $row = (int) $step->row_index;
        $nameCol = (int) $step->name_col;
        $signatureCol = (int) $step->signature_col;
        $dateCol = (int) $step->date_col;
        $departmentCol = $step->department_col !== null ? (int) $step->department_col : null;

        $edits = [
            [
                'table' => $table,
                'row' => $row,
                'col' => $nameCol,
                'text' => $nameText,
                'original_text' => '',
                'zone' => 'body',
            ],
        ];

        if ($departmentCol !== null && $departmentCol >= 0 && $departmentCol !== $nameCol) {
            $edits[] = [
                'table' => $table,
                'row' => $row,
                'col' => $departmentCol,
                'text' => (string) ($departmentText ?? ''),
                'original_text' => '',
                'zone' => 'body',
            ];
        }

        // Signature shows who approved and when. The Date column still keeps the time.
        $signatureCellText = $this->approvalSignerStamp($nameText, $signatureText, $dateText);
        if ($signatureCol === $dateCol) {
            $edits[] = [
                'table' => $table,
                'row' => $row,
                'col' => $signatureCol,
                'text' => $signatureCellText,
                'original_text' => '',
                'zone' => 'body',
            ];

            return $edits;
        }

        $edits[] = [
            'table' => $table,
            'row' => $row,
            'col' => $signatureCol,
            'text' => $signatureCellText,
            'original_text' => '',
            'zone' => 'body',
        ];
        $edits[] = [
            'table' => $table,
            'row' => $row,
            'col' => $dateCol,
            'text' => $dateText,
            'original_text' => '',
            'zone' => 'body',
        ];

        return $edits;
    }

    private function clearApprovalStampCellEdits(NewEquipmentQualification $root): array
    {
        $edits = [];
        foreach ($root->approvals as $step) {
            if ($this->isQaApprovalStep($step)) {
                continue;
            }
            if ($this->isSectionVerifiedApprovalStep($step)) {
                $edits[] = $this->sectionVerifiedStampCellEdit(
                    $step,
                    'Verified by sign and date :'
                );
                continue;
            }
            if ($this->isSafetyByApprovalStep($step)) {
                $edits[] = $this->safetyByStampCellEdit($step, 'Safety By (Sign./Date)');
                continue;
            }
            $signatureCol = (int) $step->signature_col;
            $dateCol = (int) $step->date_col;
            $edits[] = [
                'table' => (int) $step->table_index,
                'row' => (int) $step->row_index,
                'col' => $signatureCol,
                'text' => '—',
                'original_text' => '',
                'zone' => 'body',
            ];
            if ($dateCol !== $signatureCol) {
                $edits[] = [
                    'table' => (int) $step->table_index,
                    'row' => (int) $step->row_index,
                    'col' => $dateCol,
                    'text' => '—',
                    'original_text' => '',
                    'zone' => 'body',
                ];
            }
        }

        return $edits;
    }

    private function documentActionPayload(
        NewEquipmentQualification $root,
        NewEquipmentQualification $target,
        string $message,
        array $applyStats = []
    ): array {
        $fullyApproved = $root->isFullyApproved();
        $edited = ((int) $target->id !== (int) $root->id)
            ? $target
            : $root->latestEditedCopy();
        $downloadTarget = $edited ?: $root;
        $downloadUrl = $fullyApproved
            ? route('protocol.new-equipment-qualification.download', $downloadTarget)
            : null;

        return [
            'success' => true,
            'message' => $message,
            'record_id' => $target->id,
            'list_id' => $root->id,
            'display_code' => $root->displayCode(),
            'display_name' => $root->displayName(),
            'reference_document_no' => $root->referenceDocumentNo(),
            'has_edited_copy' => $edited !== null,
            'approval_status' => $root->workflowStatus(),
            'is_fully_approved' => $fullyApproved,
            'download_url' => $downloadUrl,
            'edited_download_url' => ($fullyApproved && $edited)
                ? route('protocol.new-equipment-qualification.download', $edited)
                : null,
            'file_url' => route('protocol.new-equipment-qualification.file', $target),
            'preview_html_url' => route('protocol.new-equipment-qualification.preview-html', $target),
            'text_targets_url' => route('protocol.new-equipment-qualification.text-targets', $target),
            'save_url' => route('protocol.new-equipment-qualification.save-as', $target),
            'send_for_approval_url' => route('protocol.new-equipment-qualification.send-for-approval', $root),
            'approve_url' => route('protocol.new-equipment-qualification.approve', $root),
            'send_back_url' => route('protocol.new-equipment-qualification.send-back', $root),
            'reject_url' => route('protocol.new-equipment-qualification.reject', $root),
            'filename' => $root->displayName(),
            'saved_at' => optional($target->updated_at)->format('d-m-Y h:i A'),
            'applied_count' => (int) ($applyStats['applied'] ?? 0),
            'stats' => $applyStats,
            'approvals' => $this->serializeApprovals($root->approvals),
        ];
    }

    /**
     * @param  string  $action  view|download|mutate
     */
    private function authorizeRecord(NewEquipmentQualification $record, string $action = 'mutate'): void
    {
        $root = $record->rootOriginal();
        $user = auth()->user();
        $userId = (int) auth()->id();

        if ((int) $record->uploaded_by === $userId || (int) $root->uploaded_by === $userId) {
            return;
        }

        $assigned = NewEquipmentQualificationApproval::query()
            ->where('new_equipment_qualification_id', $root->id)
            ->where('user_id', $userId)
            ->exists();
        if ($assigned) {
            return;
        }

        // Shared view / download (Amit + other roles via Permissions page).
        if (in_array($action, ['view', 'download'], true) && $user) {
            $canView = $user->can('protocol-neq-view')
                || $user->can('protocol-new-equipment')
                || $user->can('protocol-fill-new-equipment');
            if ($action === 'view' && $canView) {
                return;
            }
            if ($action === 'download') {
                $canDownload = $user->can('protocol-neq-download')
                    || $user->can('protocol-new-equipment');
                if ($canView && $canDownload) {
                    return;
                }
            }
        }

        abort(403);
    }

    private function approverUsers()
    {
        return User::query()
            ->select('id', 'first_name', 'last_name', 'email', 'designation')
            ->where(function ($query) {
                $query->where('status', 1)->orWhereNull('status');
            })
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(function (User $user) {
                $fullName = $this->formatUserFullName($user);
                $designation = $this->formatUserDesignation($user);
                $combined = $this->formatUserName($user);

                return [
                    'id' => $user->id,
                    'label' => $this->formatUserSelectLabel($user),
                    'name' => $combined,
                    'full_name' => $fullName,
                    'designation' => $designation,
                    'signature' => $this->signatureLabelFromDisplayName($combined),
                ];
            })
            ->values();
    }

    private function formatUserFullName(User $user): string
    {
        $fullName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
        // Never use email as a document display name (training Name column must stay blank / real name).
        if ($fullName === '') {
            $fullName = 'User #' . $user->id;
        }

        return $fullName;
    }

    private function formatUserDesignation(User $user): string
    {
        return trim((string) ($user->designation ?? ''));
    }

    private function formatUserName(User $user): string
    {
        $fullName = $this->formatUserFullName($user);
        $designation = $this->formatUserDesignation($user);

        return $designation !== '' ? $fullName . ' - ' . $designation : $fullName;
    }

    /**
     * @return array{0: string, 1: ?string} [nameText, departmentText|null]
     */
    private function approvalDisplayTexts(User $user, NewEquipmentQualificationApproval|array $stepOrApprover): array
    {
        $departmentCol = is_array($stepOrApprover)
            ? ($stepOrApprover['department_col'] ?? null)
            : $stepOrApprover->department_col;

        if ($departmentCol !== null && (int) $departmentCol >= 0) {
            return [
                $this->formatUserFullName($user),
                $this->formatUserDesignation($user),
            ];
        }

        if ($this->approverNameIncludesDesignation($stepOrApprover)) {
            return [$this->formatUserName($user), null];
        }

        return [$this->formatUserFullName($user), null];
    }

    private function approverNameIncludesDesignation(NewEquipmentQualificationApproval|array $stepOrApprover): bool
    {
        if (is_array($stepOrApprover)) {
            return filter_var($stepOrApprover['name_includes_designation'] ?? false, FILTER_VALIDATE_BOOLEAN);
        }

        return (bool) ($stepOrApprover->name_includes_designation ?? false);
    }

    private function formatUserSelectLabel(User $user): string
    {
        $fullName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
        if ($fullName === '') {
            $fullName = 'User #' . $user->id;
        }
        $email = trim((string) ($user->email ?? ''));

        return $email !== '' ? $fullName . ' (' . $email . ')' : $fullName;
    }

    private function signatureLabelFromDisplayName(string $displayName): string
    {
        $displayName = trim($displayName);
        if ($displayName === '') {
            return '';
        }
        $parts = preg_split('/\s-\s/', $displayName, 2);

        return trim($parts[0] ?? $displayName);
    }

    private function mergeApproverNameCellEdits(array $cellEdits, array $approvers): array
    {
        $seen = [];
        foreach ($cellEdits as $edit) {
            $key = (int) ($edit['table'] ?? 0) . ':' . (int) ($edit['row'] ?? 0) . ':' . (int) ($edit['col'] ?? 0);
            $seen[$key] = true;
        }

        $userIds = collect($approvers)->pluck('user_id')->filter()->unique()->all();
        if ($userIds === []) {
            return array_values($cellEdits);
        }

        $users = User::query()
            ->select('id', 'first_name', 'last_name', 'email', 'designation')
            ->whereIn('id', $userIds)
            ->get()
            ->keyBy('id');

        foreach ($approvers as $approver) {
            $userId = (int) ($approver['user_id'] ?? 0);
            if ($userId < 1) {
                continue;
            }
            // Validation/QA, section Verified-by, and Safety By stamps apply on approve only.
            if (! empty($approver['qa_stamp']) || ! empty($approver['section_verified']) || ! empty($approver['safety_by'])) {
                continue;
            }
            if (preg_match('/^verified\s*by\b/i', (string) ($approver['role'] ?? ''))) {
                continue;
            }
            if (preg_match('/^safety(\s*checks?)?\s*by\b/i', (string) ($approver['role'] ?? ''))) {
                continue;
            }
            $user = $users->get($userId);
            if (! $user) {
                continue;
            }
            $table = (int) ($approver['table'] ?? 0);
            $row = (int) ($approver['row'] ?? 0);
            $col = (int) ($approver['name_col'] ?? 1);
            $departmentCol = array_key_exists('department_col', $approver) && $approver['department_col'] !== null
                ? (int) $approver['department_col']
                : null;
            [$nameText, $departmentText] = $this->approvalDisplayTexts($user, $approver);
            $forceSplit = $departmentCol !== null && $departmentCol >= 0 && $departmentCol !== $col;

            $key = $table . ':' . $row . ':' . $col;
            if (! isset($seen[$key]) || $forceSplit) {
                if ($forceSplit && isset($seen[$key])) {
                    $cellEdits = array_values(array_filter($cellEdits, static function ($edit) use ($table, $row, $col) {
                        return ! (
                            (int) ($edit['table'] ?? -1) === $table
                            && (int) ($edit['row'] ?? -1) === $row
                            && (int) ($edit['col'] ?? -1) === $col
                        );
                    }));
                }
                $seen[$key] = true;
                $cellEdits[] = [
                    'table' => $table,
                    'row' => $row,
                    'col' => $col,
                    'text' => $nameText,
                    'original_text' => '',
                    'zone' => 'body',
                ];
            }

            if ($forceSplit) {
                $deptKey = $table . ':' . $row . ':' . $departmentCol;
                if (isset($seen[$deptKey])) {
                    $cellEdits = array_values(array_filter($cellEdits, static function ($edit) use ($table, $row, $departmentCol) {
                        return ! (
                            (int) ($edit['table'] ?? -1) === $table
                            && (int) ($edit['row'] ?? -1) === $row
                            && (int) ($edit['col'] ?? -1) === $departmentCol
                        );
                    }));
                }
                $seen[$deptKey] = true;
                $cellEdits[] = [
                    'table' => $table,
                    'row' => $row,
                    'col' => $departmentCol,
                    'text' => (string) ($departmentText ?? ''),
                    'original_text' => '',
                    'zone' => 'body',
                ];
            }
        }

        return array_values($cellEdits);
    }

    private function syncApprovals(NewEquipmentQualification $root, array $approvers): void
    {
        $keepKeys = [];
        foreach ($approvers as $index => $approver) {
            $table = (int) ($approver['table'] ?? 0);
            $row = (int) ($approver['row'] ?? 0);
            $userId = (int) ($approver['user_id'] ?? 0);
            $key = $table . ':' . $row;
            $keepKeys[$key] = true;
            $existing = NewEquipmentQualificationApproval::query()
                ->where('new_equipment_qualification_id', $root->id)
                ->where('table_index', $table)
                ->where('row_index', $row)
                ->first();
            if ($existing && $existing->approved_at) {
                continue;
            }
            $departmentCol = array_key_exists('department_col', $approver) && $approver['department_col'] !== null
                ? (int) $approver['department_col']
                : null;
            $paragraphIndex = array_key_exists('paragraph_index', $approver) && $approver['paragraph_index'] !== null
                ? (int) $approver['paragraph_index']
                : null;
            NewEquipmentQualificationApproval::query()->updateOrCreate(
                [
                    'new_equipment_qualification_id' => $root->id,
                    'table_index' => $table,
                    'row_index' => $row,
                ],
                [
                    'user_id' => $userId > 0 ? $userId : null,
                    'role_label' => trim((string) ($approver['role'] ?? '')) ?: null,
                    'name_col' => (int) ($approver['name_col'] ?? 1),
                    'department_col' => $departmentCol,
                    'name_includes_designation' => filter_var($approver['name_includes_designation'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'signature_col' => (int) ($approver['signature_col'] ?? 2),
                    'date_col' => (int) ($approver['date_col'] ?? 3),
                    'paragraph_index' => $paragraphIndex,
                    'step_order' => $index + 1,
                ]
            );
        }

        $pending = NewEquipmentQualificationApproval::query()
            ->where('new_equipment_qualification_id', $root->id)
            ->whereNull('approved_at')
            ->get();
        foreach ($pending as $step) {
            $key = (int) $step->table_index . ':' . (int) $step->row_index;
            if (! isset($keepKeys[$key])) {
                $step->delete();
            }
        }
    }

    private function serializeApprovals($approvals): array
    {
        return collect($approvals)->map(function ($step) {
            $user = $step->user;
            $nameText = '';
            $departmentText = '';
            if ($user) {
                [$nameText, $departmentText] = $this->approvalDisplayTexts($user, $step);
                $departmentText = (string) ($departmentText ?? '');
            }

            return [
                'id' => $step->id,
                'user_id' => $step->user_id,
                'role' => $step->role_label,
                'table' => (int) $step->table_index,
                'row' => (int) $step->row_index,
                'step_order' => (int) $step->step_order,
                'name_col' => (int) $step->name_col,
                'department_col' => $step->department_col !== null ? (int) $step->department_col : null,
                'signature_col' => (int) $step->signature_col,
                'date_col' => (int) $step->date_col,
                'paragraph_index' => $step->paragraph_index !== null ? (int) $step->paragraph_index : null,
                'approved_at' => $step->approved_at
                    ? $step->approved_at->timezone('Asia/Kolkata')->format('d-m-Y h:i A')
                    : null,
                'rejected_at' => $step->rejected_at
                    ? $step->rejected_at->timezone('Asia/Kolkata')->format('d-m-Y h:i A')
                    : null,
                'remark' => $step->remark,
                'name_text' => $nameText,
                'department_text' => $departmentText,
                'signature_text' => $step->rejected_at
                    ? 'Rejected'
                    : ($step->approved_at
                        ? trim($nameText . "\n" . $step->approved_at->timezone('Asia/Kolkata')->format('d-m-Y h:i A'))
                        : ''),
                'date_text' => $step->approved_at
                    ? $step->approved_at->timezone('Asia/Kolkata')->format('d-m-Y h:i A')
                    : ($step->rejected_at
                        ? $step->rejected_at->timezone('Asia/Kolkata')->format('d-m-Y h:i A')
                        : ''),
            ];
        })->values()->all();
    }

    private function notifyNewNeqAssignees(
        NewEquipmentQualification $root,
        array $previousAssignments,
        bool $isResubmit = false
    ): void {
        $first = $this->currentPendingStep($root);
        if (! $first) {
            return;
        }

        $key = (int) $first->table_index . ':' . (int) $first->row_index;
        $previousUserId = (int) ($previousAssignments[$key] ?? 0);
        if (! $isResubmit && (int) $first->user_id === $previousUserId) {
            return;
        }

        $user = User::find($first->user_id);
        if (! $user) {
            return;
        }

        $identity = $root->documentIdentityLabel();
        $role = trim((string) ($first->role_label ?? ''));
        $message = $isResubmit
            ? "Document {$identity} has been resubmitted and requires your approval"
                . ($role !== '' ? " as {$role}" : '') . '.'
            : "You have been assigned to approve document {$identity}"
                . ($role !== '' ? " as {$role}" : '') . '.';

        $this->safeNotify($user, new NeqApprovalAssigned(
            title: $identity,
            message: $message,
            url: route('protocol.new-equipment-qualification.index'),
            recordId: (int) $root->id,
            displayCode: $root->displayCode(),
            documentNumber: $root->referenceDocumentNo(),
            roleLabel: $role,
        ));
    }

    private function notifyAfterNeqApproval(NewEquipmentQualification $root): void
    {
        $url = route('protocol.new-equipment-qualification.index');
        $identity = $root->documentIdentityLabel();
        $next = $this->currentPendingStep($root);

        if ($next) {
            $user = User::find($next->user_id);
            if ($user) {
                $role = trim((string) ($next->role_label ?? ''));
                $this->safeNotify($user, new NeqApprovalAssigned(
                    title: $identity,
                    message: "Document {$identity} is ready for your approval"
                        . ($role !== '' ? " as {$role}" : '') . '.',
                    url: $url,
                    recordId: (int) $root->id,
                    displayCode: $root->displayCode(),
                    documentNumber: $root->referenceDocumentNo(),
                    roleLabel: $role,
                ));
            }

            return;
        }

        $uploader = User::find($root->uploaded_by);
        if ($uploader) {
            $this->safeNotify($uploader, new NeqApprovalCompleted(
                title: $identity,
                url: $url,
                recordId: (int) $root->id,
                displayCode: $root->displayCode(),
                documentNumber: $root->referenceDocumentNo(),
            ));
        }
    }

    private function notifyNeqDecision(
        NewEquipmentQualification $root,
        string $remark,
        User $actor,
        $priorApprovers,
        string $decision
    ): void {
        $url = route('protocol.new-equipment-qualification.index');
        $identity = $root->documentIdentityLabel();
        $decision = $decision === 'sent_back' ? 'sent_back' : 'rejected';
        $remark = trim($remark);
        $actorName = $this->formatUserName($actor);
        $notifiedIds = [];

        foreach ($priorApprovers as $user) {
            if (! $user instanceof User) {
                continue;
            }
            $uid = (int) $user->id;
            if ($uid === (int) $actor->id || in_array($uid, $notifiedIds, true)) {
                continue;
            }

            $this->safeNotifyDecision(
                $user,
                $identity,
                $remark,
                $actorName,
                $url,
                (int) $root->id,
                $decision,
                'prior_approver',
                $root->displayCode(),
                $root->referenceDocumentNo()
            );
            $notifiedIds[] = $uid;
        }

        $uploader = User::find($root->uploaded_by);
        if ($uploader && ! in_array((int) $uploader->id, $notifiedIds, true)) {
            $this->safeNotifyDecision(
                $uploader,
                $identity,
                $remark,
                $actorName,
                $url,
                (int) $root->id,
                $decision,
                'uploader',
                $root->displayCode(),
                $root->referenceDocumentNo()
            );
        }
    }

    private function safeNotifyDecision(
        User $user,
        string $title,
        string $remark,
        string $actorName,
        string $url,
        int $recordId,
        string $decision,
        string $recipientRole,
        string $displayCode = '',
        string $documentNumber = ''
    ): void {
        $notification = new NeqApprovalDecision(
            title: $title,
            remark: $remark,
            actorName: $actorName,
            url: $url,
            recordId: $recordId,
            decision: $decision,
            recipientRole: $recipientRole,
            mailStatus: 'pending',
            displayCode: $displayCode,
            documentNumber: $documentNumber,
        );

        try {
            $user->notifyNow($notification, ['database']);
        } catch (\Throwable $e) {
            report($e);
            Log::error('NEQ decision database notify failed', [
                'user_id' => $user->id,
                'record_id' => $recordId,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $mailStatus = 'failed';
        try {
            $user->notifyNow(
                new NeqApprovalDecision(
                    title: $title,
                    remark: $remark,
                    actorName: $actorName,
                    url: $url,
                    recordId: $recordId,
                    decision: $decision,
                    recipientRole: $recipientRole,
                    mailStatus: 'sent',
                    displayCode: $displayCode,
                    documentNumber: $documentNumber,
                ),
                ['mail']
            );
            $mailStatus = 'sent';
        } catch (\Throwable $e) {
            report($e);
            $mailStatus = 'failed';
            Log::warning('NEQ decision mail notify failed', [
                'user_id' => $user->id,
                'record_id' => $recordId,
                'error' => $e->getMessage(),
            ]);
        }

        // Patch the latest matching DB notification with mail delivery status + note.
        try {
            $stored = $user->notifications()
                ->where('type', NeqApprovalDecision::class)
                ->first();
            if ($stored) {
                $data = is_array($stored->data) ? $stored->data : (json_decode((string) $stored->data, true) ?: []);
                $data['remark'] = $remark;
                $data['note'] = $remark;
                $data['mail_status'] = $mailStatus;
                $data['mail_direction'] = 'inbound';
                // Keep message user-friendly — note is shown separately, do not append "Remark:".
                if ($remark !== '' && preg_match('/\s*Remark:\s*.+$/is', (string) ($data['message'] ?? ''))) {
                    $data['message'] = trim(preg_replace('/\s*Remark:\s*.+$/is', '', (string) $data['message']));
                }
                $stored->forceFill(['data' => $data])->save();
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function safeNotify(User $user, $notification): void
    {
        try {
            $user->notifyNow($notification, ['database']);
        } catch (\Throwable $e) {
            report($e);
        }

        try {
            $user->notifyNow($notification, ['mail']);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
