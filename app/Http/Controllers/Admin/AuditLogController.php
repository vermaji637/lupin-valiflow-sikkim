<?php

// app/Http/Controllers/Admin/AuditLogController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Report;
use App\Models\ReportApprovalStep;
use App\Models\User;
use App\Services\AuditHistoryPdf;
use App\Services\ProtocolAuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;


class AuditLogController extends Controller
{
    /** Events stored in the database and eligible for display. */
    private const STORED_EVENTS = [
        'login',
        'logout',
        'created',
        'updated',
        'imported',
        'exported',
        'approved',
        'rejected',
        'fill_with_docx',
        'docx_autofill',
    ];

    /** Model types that are internal bookkeeping — hidden from audit history UI. */
    private const INTERNAL_AUDITABLE_TYPES = [
        ReportApprovalStep::class,
    ];

    /** User-facing Event filter dropdown (value => label). */
    private const FILTER_EVENTS = [
        'login'          => 'Login',
        'logout'         => 'Logout',
        'created'        => 'Created',
        'updated'        => 'Updated',
        'imported'       => 'Imported',
        'fill_with_docx' => 'Fill with DOCX',
        'exported'       => 'Exported',
        'approved'       => 'Approved',
        'rejected'       => 'Rejected / Sent Back',
    ];

    /** Fields never shown to end users. */
    private const HIDDEN_FIELDS = [
        'rendered_html',
        'template_id',
        'template_data',
        'templatedata',
        'order',
        'docx_import',
        'docx_import_path',
        'docx_import_fields',
        'docx_filled_path',
        'docx_import_status',
        'body_html',
        'attachments',
        'data',
        'schema_json',
        'session_id',
        'method',
        'route_name',
        'path',
        'url',
        'ip_address',
        'user_agent',
        'payload',
        'headers',
        'server',
        'files',
        'password',
        'password_confirmation',
        'remember_token',
        '_token',
        '_audit_actor',
        '_audit_action',
        '_audit_description',
        '_audit_document_ref',
        '_audit_workflow_status',
        '_audit_docx_number',
        'id',
        'created_at',
        'updated_at',
        'report_id',
        'is_final',
        'step_label',
        'export_format',
        'filename',
        'source_report_id',
        'source_docx_number',
        'target_docx_number',
        'filled_count',
        'docx_number',
        'decision',
        'approval_status',
    ];

    /** Human-readable field labels. */
    private const FIELD_LABELS = [
        'current_step'        => 'Approval Step',
        'current_step_order'  => 'Approval Step',
        'stage'               => 'Workflow Stage',
        'status'              => 'Approval Status',
        'equipment_id'        => 'Equipment Code',
        'equipment_name'      => 'Equipment Name',
        'facility_section'    => 'Facility / Department',
        'document_no'         => 'Document Number',
        'created_by'          => 'Report Created By',
        'version'             => 'Document Version',
        'email'               => 'Login Email',
        'remark'              => 'Note / Remark',
    ];

    /** Human-readable value labels. */
    private const VALUE_LABELS = [
        'post'          => 'Post-Approval Stage',
        'pre'           => 'Pre-Approval Stage',
        'post_pending'  => 'Pending Approval',
        'pre_pending'   => 'Pending Review',
        'post_returned' => 'Sent Back — Returned for Correction',
        'pre_returned'  => 'Sent Back — Returned for Correction',
        'approved'      => 'Approved — Fully Completed',
        'rejected'      => 'Rejected — Closed',
        'completed'     => 'Completed',
        'draft'         => 'Draft',
        'in_progress'   => 'In Progress',
        'pre_approved'  => 'Pre-Approved — Ready for Post Stage',
    ];

    public function index(Request $request)
    {
        $query = $this->filteredAuditQuery($request);

        $logs = $query->paginate(20)->withQueryString();
        $logs->getCollection()->transform(fn ($log) => $this->presentAuditLog($log));

        $summaryQuery = clone $query;

        return view('admin.audit-logs.index', [
            'logs'          => $logs,
            'users'         => User::select('id', 'first_name', 'last_name', 'email')->orderBy('first_name')->get(),
            'filterEvents'  => self::FILTER_EVENTS,
            'summary'       => [
                'total'    => (clone $summaryQuery)->count(),
                'requests' => 0,
                'changes'  => (clone $summaryQuery)->whereIn('event', ['created', 'updated', 'imported', 'exported', 'approved', 'rejected', 'fill_with_docx'])->count(),
                'auth'     => (clone $summaryQuery)->whereIn('event', ['login', 'logout'])->count(),
                'exceptions' => 0,
            ],
        ]);
    }

    public function downloadPdf(Request $request)
    {
        set_time_limit(0);

        $query = $this->filteredAuditQuery($request);
        $total = (clone $query)->count();
        $filters = [
            'user' => $this->auditExportUserLabel($request),
            'event' => self::FILTER_EVENTS[$request->input('event')] ?? 'All Events',
            'from' => $request->filled('from_date') ? $request->from_date : 'Any',
            'to' => $request->filled('to_date') ? $request->to_date : 'Any',
        ];

        $zipPath = $this->buildAuditHistoryZip($query, $filters, $total);
        $filename = 'audit-history-' . now()->timezone('Asia/Kolkata')->format('Ymd-His') . '.zip';

        return response()->download($zipPath, $filename, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Write the filtered audit in batches. Each batch is one PDF, and the PDFs
     * go into one zip so a 2 lakh or 4 lakh export does not stay in memory.
     */
    private function buildAuditHistoryZip(Builder $query, array $filters, int $total): string
    {
        $chunkSize = 2000;
        $partCount = max(1, (int) ceil($total / $chunkSize));
        $dir = storage_path('app/temp/audit-export-' . now()->format('YmdHis') . '-' . bin2hex(random_bytes(4)));
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            abort(500, 'The audit download could not be started.');
        }

        $pdf = new AuditHistoryPdf();
        $generatedAt = now()->timezone('Asia/Kolkata')->format('d-m-Y h:i A');
        $buffer = [];
        $oldestPart = 0;
        $written = [];

        $flush = function () use (
            &$buffer,
            &$oldestPart,
            &$written,
            $pdf,
            $filters,
            $total,
            $generatedAt,
            $chunkSize,
            $partCount,
            $dir
        ) {
            if ($buffer === [] && $oldestPart > 0) {
                return;
            }
            $count = count($buffer);
            $oldestOffset = $oldestPart * $chunkSize;
            $rankEnd = $total - $oldestOffset;
            $rankStart = $count > 0 ? $rankEnd - $count + 1 : 1;
            $userPart = $partCount - $oldestPart;
            $rows = array_reverse($buffer);
            $buffer = [];
            $partFilters = $filters;
            $partFilters['part'] = $total === 0
                ? 'Part 1 of 1'
                : 'Part ' . $userPart . ' of ' . $partCount
                    . '  |  Records ' . $rankStart . '-' . $rankEnd . ' of ' . $total;
            $binary = $pdf->render($rows, $partFilters, $total, $generatedAt);
            $path = $dir . DIRECTORY_SEPARATOR . 'asc-' . sprintf('%04d', $oldestPart) . '.pdf';
            file_put_contents($path, $binary);
            $written[] = ['path' => $path, 'userPart' => $userPart];
            $oldestPart++;
            unset($binary, $rows);
        };

        try {
            if ($total === 0) {
                $flush();
            } else {
                $query->reorder()->orderBy('id')->chunkById(200, function ($logs) use (&$buffer, $flush, $chunkSize) {
                    foreach ($logs as $log) {
                        $buffer[] = $this->auditPdfRow($log);
                        $log->unsetRelation('user');
                        $log->old_values = null;
                        $log->new_values = null;
                        if (count($buffer) >= $chunkSize) {
                            $flush();
                        }
                    }
                });
                if ($buffer !== []) {
                    $flush();
                }
            }
        } catch (\Throwable $e) {
            $this->deleteAuditExportDir($dir);
            throw $e;
        }

        usort($written, function (array $a, array $b) {
            return $a['userPart'] <=> $b['userPart'];
        });

        $zipPath = $dir . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $this->deleteAuditExportDir($dir);
            abort(500, 'The audit download could not be created.');
        }
        foreach ($written as $file) {
            $zip->addFile($file['path'], 'audit-part-' . sprintf('%03d', $file['userPart']) . '.pdf');
        }
        $zip->close();
        $this->deleteAuditExportDir($dir);

        return $zipPath;
    }

    private function auditPdfRow($log): array
    {
        $presented = $this->presentAuditLog($log);
        $actor = trim((string) ($presented->actor_name ?? 'System'));
        $email = trim((string) ($presented->actor_email ?? ''));

        return [
            'when' => optional($presented->created_at)->timezone('Asia/Kolkata')->format('d-m-Y h:i A'),
            'user' => $this->pdfCell($email !== '' ? $actor . ' ' . $email : $actor, 160),
            'action' => $this->pdfCell((string) ($presented->action_label ?? '—'), 80),
            'activity' => $this->pdfCell((string) ($presented->activity_label ?? '—'), 320),
            'status' => $this->pdfCell((string) ($presented->workflow_status_label ?? '—'), 80),
            'fields' => $this->pdfCell((string) ($presented->changed_fields_text ?? '—'), 200),
            'old' => $this->pdfCell((string) ($presented->previous_value_text ?? '—'), 320),
            'new' => $this->pdfCell((string) ($presented->new_value_text ?? '—'), 320),
        ];
    }

    private function auditExportUserLabel(Request $request): string
    {
        if ($request->filled('user_id') && auth()->user()->can('audit-view')) {
            $selected = User::query()->select('id', 'first_name', 'last_name', 'email')->find($request->user_id);
            if ($selected) {
                $name = trim(($selected->first_name ?? '') . ' ' . ($selected->last_name ?? ''));

                return $name !== '' ? $name : (string) $selected->email;
            }
        }
        if (! auth()->user()->can('audit-view')) {
            $name = trim((auth()->user()->first_name ?? '') . ' ' . (auth()->user()->last_name ?? ''));

            return $name !== '' ? $name : (string) auth()->user()->email;
        }

        return 'All Users';
    }

    private function deleteAuditExportDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($dir);
    }

    private function pdfCell(string $text, int $max): string
    {
        $text = preg_replace('/\s+/u', ' ', trim($text));
        if (! is_string($text) || $text === '') {
            return '—';
        }
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $max)) . '…';
    }

    private function filteredAuditQuery(Request $request): Builder
    {
        $query = AuditLog::with(['user:id,first_name,last_name,email'])
            ->whereIn('event', self::STORED_EVENTS)
            ->where(function ($q) {
                $q->whereNull('auditable_type')
                    ->orWhereNotIn('auditable_type', self::INTERNAL_AUDITABLE_TYPES);
            })
            ->latest();

        if (! auth()->user()->can('audit-view')) {
            $query->where('user_id', auth()->id());
        }

        if ($request->filled('user_id') && auth()->user()->can('audit-view')) {
            $query->where('user_id', $request->user_id);
        }

        $this->applyEventFilter($query, $request->input('event'));

        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where(function ($q) use ($search) {
                $q->where('auditable_type', 'like', "%{$search}%")
                    ->orWhere('event', 'like', "%{$search}%")
                    ->orWhere('url', 'like', "%{$search}%")
                    ->orWhere('ip_address', 'like', "%{$search}%")
                    ->orWhere('auditable_id', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        return $query;
    }

    private function presentAuditLog(AuditLog $log): AuditLog
    {
        $log->change_rows = $this->buildHumanReadableChangeRows(
            is_array($log->old_values) ? $log->old_values : [],
            is_array($log->new_values) ? $log->new_values : [],
            (string) $log->event
        );

        $context = $this->resolveAuditContext($log);

        $log->activity_label        = $context['description'];
        $log->action_label          = $context['action_label'];
        $log->document_ref          = $context['document_ref'];
        $log->docx_number_label     = $context['docx_number'];
        $log->workflow_status_label = $context['workflow_status'];
        $log->status_label          = $context['workflow_status'];
        $log->changed_fields_text   = $this->buildFieldListText($log->change_rows);
        $log->previous_value_text   = $this->buildChangeText($log->change_rows, 'old', false);
        $log->new_value_text        = $this->buildChangeText($log->change_rows, 'new', false);

        [$log->actor_name, $log->actor_email] = $this->resolveActorDisplay($log);

        return $log;
    }

    private function applyEventFilter($query, ?string $filterEvent): void
    {
        if ($filterEvent === null || $filterEvent === '') {
            return;
        }

        if (! array_key_exists($filterEvent, self::FILTER_EVENTS)) {
            return;
        }

        $reportType = Report::class;
        $docxPath   = $this->jsonValueExpression('new_values', '$.docx_import_path');
        $newStatus  = $this->jsonValueExpression('new_values', '$.status');
        $routeName  = $this->jsonValueExpression('new_values', '$.route_name');
        $auditAction = $this->jsonValueExpression('new_values', '$._audit_action');
        $auditDescription = $this->jsonValueExpression('new_values', '$._audit_description');

        switch ($filterEvent) {
            case 'login':
            case 'logout':
            case 'exported':
                $query->where('event', $filterEvent);
                break;

            case 'fill_with_docx':
                $query->where('auditable_type', $reportType)
                    ->where(function ($q) use ($routeName, $auditAction, $auditDescription) {
                        $q->where(function ($inner) use ($auditDescription) {
                            $inner->where('event', 'fill_with_docx')
                                ->whereRaw("{$auditDescription} LIKE '%Submitted for Approval%'");
                        })->orWhere(function ($inner) use ($routeName) {
                            $inner->where('event', 'updated')
                                ->whereRaw("{$routeName} LIKE '%docx-import.fill%'");
                        })->orWhere(function ($inner) use ($auditAction) {
                            $inner->where('event', 'updated')
                                ->whereRaw("{$auditAction} = 'submitted'");
                        });
                    });
                break;

            case 'imported':
                $query->where(function ($q) use ($reportType, $docxPath) {
                    $q->where('event', 'imported')
                        ->orWhere(function ($inner) use ($reportType, $docxPath) {
                            $inner->where('event', 'created')
                                ->where('auditable_type', $reportType)
                                ->whereRaw("{$docxPath} IS NOT NULL");
                        });
                });
                break;

            case 'approved':
                $query->where(function ($q) use ($reportType, $newStatus) {
                    $q->where('event', 'approved')
                        ->orWhere(function ($inner) use ($reportType, $newStatus) {
                            $inner->where('event', 'updated')
                                ->where('auditable_type', $reportType)
                                ->whereRaw("{$newStatus} IN ('approved', 'pre_approved')");
                        });
                });
                break;

            case 'rejected':
                $query->where(function ($q) use ($reportType, $newStatus) {
                    $q->where('event', 'rejected')
                        ->orWhere(function ($inner) use ($reportType, $newStatus) {
                            $inner->where('event', 'updated')
                                ->where('auditable_type', $reportType)
                                ->whereRaw("{$newStatus} IN ('pre_returned', 'post_returned', 'rejected')");
                        });
                });
                break;

            case 'created':
                $query->where('event', 'created')
                    ->whereNotIn('auditable_type', self::INTERNAL_AUDITABLE_TYPES)
                    ->where(function ($q) use ($reportType, $docxPath) {
                        $q->where('auditable_type', '!=', $reportType)
                            ->orWhereNull('auditable_type')
                            ->orWhereRaw("{$docxPath} IS NULL");
                    });
                break;

            case 'updated':
                $query->where('event', 'updated')
                    ->where(function ($q) use ($reportType, $routeName, $auditAction) {
                        $q->where(function ($inner) use ($reportType, $routeName, $auditAction) {
                            $inner->where('auditable_type', '!=', $reportType)
                                ->orWhereNull('auditable_type')
                                ->orWhere(function ($reportOnly) use ($reportType, $routeName, $auditAction) {
                                    $reportOnly->where('auditable_type', $reportType)
                                        ->whereRaw("{$routeName} NOT LIKE '%docx-import.fill%'")
                                        ->whereRaw("({$auditAction} IS NULL OR {$auditAction} NOT IN ('submitted', 'fill_with_docx'))");
                                });
                        });
                    });
                break;
        }
    }

    private function jsonValueExpression(string $column, string $path): string
    {
        if (config('database.default') === 'sqlsrv') {
            return "JSON_VALUE({$column}, '{$path}')";
        }

        return "JSON_UNQUOTE(JSON_EXTRACT({$column}, '{$path}'))";
    }

    /**
     * @return array{description: string, action_label: string, document_ref: string, docx_number: string, workflow_status: string}
     */
    private function resolveAuditContext(AuditLog $log): array
    {
        $storedDescription = trim((string) data_get($log->new_values, '_audit_description', ''));
        $storedAction      = trim((string) data_get($log->new_values, '_audit_action', ''));
        $storedRef         = trim((string) data_get($log->new_values, '_audit_document_ref', ''));
        $storedStatus      = trim((string) data_get($log->new_values, '_audit_workflow_status', ''));

        if ($storedDescription !== '') {
            return [
                'description'     => $storedDescription,
                'action_label'    => $this->actionLabelFromKey($storedAction !== '' ? $storedAction : (string) $log->event),
                'document_ref'    => $storedRef !== '' ? $storedRef : $this->resolveDocumentRef($log),
                'docx_number'     => $this->resolveDocxNumber($log),
                'workflow_status' => $storedStatus !== '' ? $storedStatus : $this->resolveWorkflowStatus($log),
            ];
        }

        $inferred = $this->inferLegacyContext($log);

        return [
            'description'     => $inferred['description'],
            'action_label'    => $inferred['action_label'],
            'document_ref'    => $inferred['document_ref'],
            'docx_number'     => $inferred['docx_number'],
            'workflow_status' => $inferred['workflow_status'],
        ];
    }

    /**
     * @return array{description: string, action_label: string, document_ref: string, docx_number: string, workflow_status: string}
     */
    private function inferLegacyContext(AuditLog $log): array
    {
        $event       = (string) $log->event;
        $documentRef = $this->resolveDocumentRef($log);
        $docxNumber  = $this->resolveDocxNumber($log);
        $oldStatus   = data_get($log->old_values, 'status');
        $newStatus   = data_get($log->new_values, 'status');
        $routeName   = (string) data_get($log->new_values, 'route_name', '');

        if ($event === 'login') {
            return [
                'description'     => 'User logged in',
                'action_label'    => 'Login',
                'document_ref'    => '—',
                'docx_number'     => '—',
                'workflow_status' => 'Session started',
            ];
        }

        if ($event === 'logout') {
            return [
                'description'     => 'User logged out',
                'action_label'    => 'Logout',
                'document_ref'    => '—',
                'docx_number'     => '—',
                'workflow_status' => 'Session ended',
            ];
        }

        if ($event === 'imported' || ($event === 'created' && $this->isDocxImportCreate($log))) {
            return [
                'description'     => "Imported DOCX Document ({$documentRef})",
                'action_label'    => 'Imported',
                'document_ref'    => $documentRef,
                'docx_number'     => $docxNumber,
                'workflow_status' => $this->translateStatusValue(data_get($log->new_values, 'status')),
            ];
        }

        if ($event === 'fill_with_docx') {
            $description = trim((string) data_get($log->new_values, '_audit_description', ''));
            if ($description === '') {
                $description = "Filled DOCX Form and Submitted for Approval ({$documentRef})";
            }

            return [
                'description'     => $description,
                'action_label'    => 'Fill with DOCX',
                'document_ref'    => $documentRef,
                'docx_number'     => $docxNumber,
                'workflow_status' => $this->resolveWorkflowStatus($log),
            ];
        }

        if ($event === 'docx_autofill') {
            $description = trim((string) data_get($log->new_values, '_audit_description', ''));
            if ($description === '') {
                $description = "Copied field values from approved protocol ({$documentRef})";
            }

            return [
                'description'     => $description,
                'action_label'    => 'Copied from Protocol',
                'document_ref'    => $documentRef,
                'docx_number'     => $docxNumber,
                'workflow_status' => $this->resolveWorkflowStatus($log),
            ];
        }

        if ($event === 'approved' || $this->statusChangedTo($oldStatus, $newStatus, ['approved', 'pre_approved'])) {
            $ref = $documentRef !== '—' ? $documentRef : $this->modelLabel($log);

            return [
                'description'     => "Approved {$ref}",
                'action_label'    => 'Approved',
                'document_ref'    => $documentRef,
                'docx_number'     => $docxNumber,
                'workflow_status' => $this->translateStatusValue($newStatus),
            ];
        }

        if ($event === 'rejected' || $this->statusChangedTo($oldStatus, $newStatus, ['pre_returned', 'post_returned', 'rejected'])) {
            $ref = $documentRef !== '—' ? $documentRef : $this->modelLabel($log);

            return [
                'description'     => "Rejected {$ref}",
                'action_label'    => 'Rejected',
                'document_ref'    => $documentRef,
                'docx_number'     => $docxNumber,
                'workflow_status' => $this->translateStatusValue($newStatus),
            ];
        }

        if ($event === 'exported') {
            $format = strtoupper((string) data_get($log->new_values, 'export_format', 'FILE'));

            return [
                'description'     => "Exported {$format} — {$documentRef}",
                'action_label'    => 'Exported',
                'document_ref'    => $documentRef,
                'docx_number'     => $docxNumber,
                'workflow_status' => 'Downloaded',
            ];
        }

        if ($event === 'updated' && str_contains($routeName, 'docx-import.fill')) {
            return [
                'description'     => "Filled DOCX Form and Submitted for Approval ({$documentRef})",
                'action_label'    => 'Fill with DOCX',
                'document_ref'    => $documentRef,
                'docx_number'     => $docxNumber,
                'workflow_status' => $this->translateStatusValue($newStatus),
            ];
        }

        if ($event === 'created') {
            $model = $this->modelLabel($log);

            return [
                'description'     => "Created {$model}",
                'action_label'    => 'Created',
                'document_ref'    => $documentRef,
                'docx_number'     => $docxNumber,
                'workflow_status' => $this->translateStatusValue($newStatus),
            ];
        }

        if ($event === 'updated') {
            $model = $this->modelLabel($log);

            return [
                'description'     => "Updated {$model}",
                'action_label'    => 'Updated',
                'document_ref'    => $documentRef,
                'docx_number'     => $docxNumber,
                'workflow_status' => $this->translateStatusValue($newStatus),
            ];
        }

        return [
            'description'     => ucfirst($event) . ' action recorded',
            'action_label'    => ucfirst($event),
            'document_ref'    => $documentRef,
            'docx_number'     => $docxNumber,
            'workflow_status' => $this->translateStatusValue($newStatus),
        ];
    }

    private function isDocxImportCreate(AuditLog $log): bool
    {
        if ($log->auditable_type !== Report::class) {
            return false;
        }

        return data_get($log->new_values, 'docx_import_path') !== null;
    }

    private function statusChangedTo($oldStatus, $newStatus, array $targets): bool
    {
        if ($newStatus === null || $oldStatus === $newStatus) {
            return false;
        }

        return in_array((string) $newStatus, $targets, true);
    }

    private function resolveDocxNumber(AuditLog $log): string
    {
        foreach (['_audit_docx_number', 'target_docx_number', 'docx_number'] as $key) {
            $value = trim((string) data_get($log->new_values, $key, ''));
            if ($value !== '') {
                return $value;
            }
        }

        if ($log->auditable_type !== Report::class || empty($log->auditable_id)) {
            return '—';
        }

        $report = Report::query()->find($log->auditable_id);
        if (! $report) {
            return '—';
        }

        $number = ProtocolAuditLogger::resolveDocxNumber($report);

        return $number !== '' ? $number : '—';
    }

    private function resolveDocumentRef(AuditLog $log): string
    {
        $stored = trim((string) data_get($log->new_values, '_audit_document_ref', ''));
        if ($stored !== '') {
            return $stored;
        }

        if ($log->auditable_type !== Report::class || empty($log->auditable_id)) {
            return '—';
        }

        $report = Report::query()
            ->with('document')
            ->find($log->auditable_id);

        if (! $report) {
            $equipmentId = trim((string) data_get($log->new_values, 'equipment_id', ''));
            if ($equipmentId !== '') {
                return $equipmentId . ' — Protocol #' . $log->auditable_id;
            }

            return 'Protocol #' . $log->auditable_id;
        }

        return ProtocolAuditLogger::documentRef($report);
    }

    private function resolveWorkflowStatus(AuditLog $log): string
    {
        $stored = trim((string) data_get($log->new_values, '_audit_workflow_status', ''));
        if ($stored !== '') {
            return $stored;
        }

        $status = data_get($log->new_values, 'status');

        return $this->translateStatusValue($status);
    }

    private function translateStatusValue($status): string
    {
        if ($status === null || $status === '') {
            return '—';
        }

        return ProtocolAuditLogger::workflowStatusLabel((string) $status);
    }

    private function actionLabelFromKey(string $key): string
    {
        return match ($key) {
            'login'     => 'Login',
            'logout'    => 'Logout',
            'created'   => 'Created',
            'updated'   => 'Updated',
            'imported'  => 'Imported',
            'exported'  => 'Exported',
            'approved'  => 'Approved',
            'rejected'  => 'Rejected',
            'sent_back' => 'Sent Back',
            'submitted' => 'Fill with DOCX',
            'fill_with_docx' => 'Fill with DOCX',
            'docx_autofill' => 'Copied from Protocol',
            default     => ucfirst($key),
        };
    }

    private function modelLabel(AuditLog $log): string
    {
        $modelName = '';
        if (! empty($log->auditable_type)) {
            $modelName = class_basename((string) $log->auditable_type);
        }

        if ($modelName === 'Report') {
            $modelName = 'Protocol';
        }

        if ($modelName === 'NewEquipmentQualification') {
            $modelName = 'NEQ Document';
        }

        if ($modelName !== '' && ! empty($log->auditable_id)) {
            return "{$modelName} #{$log->auditable_id}";
        }

        if ($modelName !== '') {
            return $modelName;
        }

        return 'Record';
    }

    /**
     * Resolve the user who performed the action (never the audited record owner).
     *
     * @return array{0: string, 1: string}
     */
    private function resolveActorDisplay(AuditLog $log): array
    {
        $snapshot = data_get($log->new_values, '_audit_actor', []);
        if (is_array($snapshot) && ! empty($snapshot)) {
            $name  = trim((string) ($snapshot['name'] ?? ''));
            $email = trim((string) ($snapshot['email'] ?? ''));

            if ($name !== '' || $email !== '') {
                return [$name !== '' ? $name : 'User', $email];
            }
        }

        $user = $log->user;
        if ($user) {
            $name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));

            return [$name !== '' ? $name : 'User', (string) ($user->email ?? '')];
        }

        return ['System', ''];
    }

    private function buildHumanReadableChangeRows(array $oldValues, array $newValues, string $event): array
    {
        $oldFlat = Arr::dot($oldValues);
        $newFlat = Arr::dot($newValues);

        foreach (array_keys($oldFlat) as $key) {
            if ($this->shouldHideField($key)) {
                unset($oldFlat[$key]);
            }
        }
        foreach (array_keys($newFlat) as $key) {
            if ($this->shouldHideField($key)) {
                unset($newFlat[$key]);
            }
        }

        $keys = array_unique(array_merge(array_keys($oldFlat), array_keys($newFlat)));
        sort($keys);

        $rows = [];
        foreach ($keys as $key) {
            $oldExists = array_key_exists($key, $oldFlat);
            $newExists = array_key_exists($key, $newFlat);

            $oldRaw = $oldExists ? $oldFlat[$key] : null;
            $newRaw = $newExists ? $newFlat[$key] : null;

            if ($oldExists && $newExists && $oldRaw == $newRaw) {
                continue;
            }

            $oldDisplay = $this->formatDisplayValue($key, $oldRaw, $oldExists);
            $newDisplay = $this->formatDisplayValue($key, $newRaw, $newExists);

            if ($oldDisplay === null && $newDisplay === null) {
                continue;
            }

            $rows[] = [
                'field' => $this->resolveFieldLabel($key),
                'old'   => $oldDisplay ?? '—',
                'new'   => $newDisplay ?? '—',
            ];
        }

        if (empty($rows)) {
            $rows[] = [
                'field' => 'Activity',
                'old'   => '—',
                'new'   => ucfirst($event) . ' action recorded',
            ];
        }

        return $rows;
    }

    private function shouldHideField(string $key): bool
    {
        $baseKey = $this->baseFieldKey($key);

        foreach (self::HIDDEN_FIELDS as $hidden) {
            if ($key === $hidden
                || $baseKey === $hidden
                || str_starts_with($key, $hidden . '.')
                || str_starts_with($key, $hidden . '_')) {
                return true;
            }
        }

        return false;
    }

    private function baseFieldKey(string $key): string
    {
        if (! str_contains($key, '.')) {
            return $key;
        }

        $parts = explode('.', $key);

        return (string) end($parts);
    }

    private function resolveFieldLabel(string $key): string
    {
        $baseKey = $this->baseFieldKey($key);

        return self::FIELD_LABELS[$key]
            ?? self::FIELD_LABELS[$baseKey]
            ?? ucwords(str_replace(['.', '_'], ' ', $key));
    }

    private function formatDisplayValue(string $fieldKey, $value, bool $exists): ?string
    {
        if (! $exists) {
            return '—';
        }

        if ($value === null || $value === '') {
            return '—';
        }

        $baseKey = $this->baseFieldKey($fieldKey);
        $text    = $this->stringifyAuditValue($value);

        if ($text === '—' || str_starts_with($text, '[content length')) {
            return null;
        }

        if (str_starts_with($text, '[array with') || str_starts_with($text, '[complex content]')) {
            return null;
        }

        if ($baseKey === 'created_by' && is_numeric($value)) {
            $user = User::query()->find((int) $value);
            if ($user) {
                $name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));

                return $name !== '' ? $name : (string) ($user->email ?? $text);
            }
        }

        if (in_array($baseKey, ['current_step', 'current_step_order'], true) && is_numeric($text)) {
            return 'Step ' . (int) $text;
        }

        $lower = strtolower($text);

        return self::VALUE_LABELS[$text]
            ?? self::VALUE_LABELS[$lower]
            ?? $text;
    }

    private function stringifyAuditValue($value): string
    {
        if ($value === null) {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            $json = json_encode($value, JSON_UNESCAPED_UNICODE);

            return $json === false ? '—' : $json;
        }

        return trim((string) $value);
    }

    private function buildFieldListText(array $changeRows): string
    {
        $fields = [];
        foreach ($changeRows as $change) {
            $field = trim((string) ($change['field'] ?? ''));
            if ($field !== '' && $field !== 'Activity') {
                $fields[] = $field;
            }
        }

        if (empty($fields)) {
            return '—';
        }

        return implode("\n", $fields);
    }

    private function buildChangeText(array $changeRows, string $key, bool $includeFieldName = true): string
    {
        $lines = [];
        foreach ($changeRows as $change) {
            $field = (string) ($change['field'] ?? 'Field');
            $value = (string) ($change[$key] ?? '—');
            if ($field === 'Activity') {
                continue;
            }

            $lines[] = $includeFieldName ? "{$field}: {$value}" : $value;
        }

        if (empty($lines)) {
            return '—';
        }

        return implode("\n", $lines);
    }
}
