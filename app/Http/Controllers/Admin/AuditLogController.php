<?php

// app/Http/Controllers/Admin/AuditLogController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class AuditLogController extends Controller
{
    private const TRACKED_EVENTS = [
        'login',
        'logout',
        'created',
        'updated',
        'deleted',
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
        'id',
        'created_at',
        'updated_at',
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
    ];

    /** Human-readable value labels. */
    private const VALUE_LABELS = [
        'post'          => 'Post-Approval Stage',
        'pre'           => 'Pre-Approval Stage',
        'post_pending'  => 'Pending Approval',
        'pre_pending'   => 'Pending Review',
        'post_returned' => 'Returned (Post)',
        'pre_returned'  => 'Returned (Pre)',
        'approved'      => 'Approved',
        'rejected'      => 'Rejected',
        'completed'     => 'Completed',
        'draft'         => 'Draft',
        'in_progress'   => 'In Progress',
        'pre_approved'  => 'Pre-Approved',
    ];

    public function index(Request $request)
    {
        $query = AuditLog::with(['user:id,first_name,last_name,email'])
            ->whereIn('event', self::TRACKED_EVENTS)
            ->latest();

        if (! auth()->user()->can('audit-view')) {
            $query->where('user_id', auth()->id());
        }

        if ($request->filled('user_id') && auth()->user()->can('audit-view')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('event') && in_array($request->event, self::TRACKED_EVENTS, true)) {
            $query->where('event', $request->event);
        }

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

        $logs = $query->paginate(20)->withQueryString();
        $logs->getCollection()->transform(function ($log) {
            $log->change_rows = $this->buildHumanReadableChangeRows(
                is_array($log->old_values) ? $log->old_values : [],
                is_array($log->new_values) ? $log->new_values : [],
                (string) $log->event
            );

            $log->activity_label = $this->buildActivityLabel($log);
            $log->status_label = $this->resolveStatusLabel($log);
            $log->changed_fields_text = $this->buildFieldListText($log->change_rows);
            $log->previous_value_text = $this->buildChangeText($log->change_rows, 'old', false);
            $log->new_value_text = $this->buildChangeText($log->change_rows, 'new', false);

            [$log->actor_name, $log->actor_email] = $this->resolveActorDisplay($log);

            return $log;
        });

        $summaryQuery = clone $query;

        return view('admin.audit-logs.index', [
            'logs'   => $logs,
            'users'  => User::select('id', 'first_name', 'last_name', 'email')->orderBy('first_name')->get(),
            'events' => self::TRACKED_EVENTS,
            'summary' => [
                'total'      => (clone $summaryQuery)->count(),
                'requests'   => 0,
                'changes'    => (clone $summaryQuery)->whereIn('event', ['created', 'updated', 'deleted'])->count(),
                'auth'       => (clone $summaryQuery)->whereIn('event', ['login', 'logout'])->count(),
                'exceptions' => 0,
            ],
        ]);
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

    private function buildActivityLabel(AuditLog $log): string
    {
        $eventLabel = match ((string) $log->event) {
            'submitted' => 'Submitted Form',
            'created'   => 'Created Record',
            'updated'   => 'Updated Record',
            'deleted'   => 'Deleted Record',
            'login'     => 'User Login',
            'logout'    => 'User Logout',
            default     => ucfirst((string) $log->event),
        };

        $modelName = '';
        if (! empty($log->auditable_type)) {
            $modelName = class_basename((string) $log->auditable_type);
        }

        if ($modelName === 'Report') {
            $modelName = 'Protocol';
        }

        if ($modelName !== '' && ! empty($log->auditable_id)) {
            return "{$eventLabel} ({$modelName} #{$log->auditable_id})";
        }

        if ($modelName !== '') {
            return "{$eventLabel} ({$modelName})";
        }

        return $eventLabel;
    }

    private function resolveStatusLabel(AuditLog $log): string
    {
        $statusCode = data_get($log->new_values, 'status_code');
        if (is_numeric($statusCode) && (int) $statusCode >= 400) {
            return 'Failed';
        }

        return 'Success';
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
