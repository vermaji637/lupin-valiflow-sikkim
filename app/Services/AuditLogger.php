<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class AuditLogger
{
    protected const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'new_confirm_password',
        'remember_token',
        'password_setup_token',
        'password_setup_token_created_at',
        'password_set',
        'password_setup_used_at',
        'password_setup_expires_at',
        'token',
    ];

    protected const HEAVY_KEYS = [
        'body_html',
        'rendered_html',
        'schema_json',
        'templatedata',
        'data',
        'attachments',
        'session_urls',
        'user_agent',
    ];

    public static function logModel(string $event, Model $model, $oldValues = null, $newValues = null): void
    {
        if ($model instanceof AuditLog || $model instanceof ActivityLog) {
            return;
        }

        self::record($event, [
            'auditable_type' => get_class($model),
            'auditable_id'   => $model->getKey(),
            'old_values'     => self::sanitizePayload($oldValues),
            'new_values'     => self::sanitizePayload($newValues),
        ]);
    }

    public static function record(string $event, array $payload = []): void
    {
        try {
            /** @var Request|null $request */
            $request = app()->bound('request') ? request() : null;

            // Always attribute the action to the authenticated actor — never the audited model owner.
            $actor = auth()->user();
            $actorId = $payload['user_id'] ?? $actor?->getAuthIdentifier();

            if (empty($actorId)) {
                return;
            }

            if ($actor && (int) $actor->getAuthIdentifier() !== (int) $actorId) {
                $actor = User::query()->find($actorId) ?? $actor;
            } elseif (! $actor) {
                $actor = User::query()->find($actorId);
            }

            $newValues = self::mergeDefaultNewValues($payload['new_values'] ?? null, $request, $payload);
            $newValues = is_array($newValues) ? $newValues : [];
            $newValues['_audit_actor'] = self::actorSnapshot($actor);

            AuditLog::query()->create([
                'user_id'        => (int) $actorId,
                'event'          => $event,
                'auditable_type' => $payload['auditable_type'] ?? null,
                'auditable_id'   => $payload['auditable_id'] ?? null,
                'old_values'     => self::sanitizePayload($payload['old_values'] ?? null),
                'new_values'     => self::sanitizePayload($newValues),
                'url'            => $payload['url'] ?? ($request?->fullUrl()),
                'ip_address'     => $payload['ip_address'] ?? ($request?->ip()),
                'user_agent'     => $payload['user_agent'] ?? ($request?->userAgent()),
                'login_at'       => $payload['login_at'] ?? null,
                'logout_at'      => $payload['logout_at'] ?? null,
                'session_urls'   => isset($payload['session_urls'])
                    ? json_encode(self::sanitizePayload($payload['session_urls']), JSON_UNESCAPED_UNICODE)
                    : null,
            ]);
        } catch (\Throwable $e) {
            // Never let audit logging break the request lifecycle.
        }
    }

    public static function sanitizePayload($payload)
    {
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $payload = $decoded;
            }
        }

        if (!is_array($payload)) {
            return self::sanitizeScalar($payload);
        }

        $clean = [];
        foreach ($payload as $key => $value) {
            if (in_array((string) $key, self::SENSITIVE_KEYS, true)) {
                $clean[$key] = '[hidden]';
                continue;
            }

            if (in_array((string) $key, self::HEAVY_KEYS, true)) {
                $clean[$key] = self::summarizeHeavyValue($value);
                continue;
            }

            $clean[$key] = is_array($value)
                ? self::sanitizePayload($value)
                : self::sanitizeScalar($value);
        }

        return $clean;
    }

    public static function sanitizeRequestInput(array $input): array
    {
        return Arr::except(self::sanitizePayload($input), ['_token', '_method']);
    }

    protected static function mergeDefaultNewValues($newValues, ?Request $request, array $payload)
    {
        $normalized = self::normalizeArrayPayload($newValues);

        if (!$request) {
            return $normalized ?? $newValues;
        }

        $defaults = array_filter([
            'method' => $request->method(),
            'route_name' => optional($request->route())->getName(),
            'path' => $request->path(),
            'session_id' => $payload['session_id'] ?? session()->getId(),
        ], static fn ($value) => $value !== null && $value !== '');

        if ($normalized === null) {
            return $defaults ?: $newValues;
        }

        return array_merge($defaults, $normalized);
    }

    protected static function normalizeArrayPayload($payload): ?array
    {
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return is_array($payload) ? $payload : null;
    }

    protected static function sanitizeScalar($value)
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if ($value instanceof \Stringable) {
            $value = (string) $value;
        }

        if (!is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);

        if (strlen($trimmed) > 400) {
            return substr($trimmed, 0, 400) . '...';
        }

        return $trimmed;
    }

    protected static function summarizeHeavyValue($value): string
    {
        if (is_array($value)) {
            return '[array with ' . count($value) . ' items]';
        }

        if (is_string($value)) {
            return '[content length ' . strlen($value) . ']';
        }

        return '[complex content]';
    }

    /**
     * Snapshot of who performed the action (stored on each log for reliable display).
     */
    protected static function actorSnapshot(?User $user): array
    {
        if (! $user) {
            return [];
        }

        $name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));

        return array_filter([
            'id'    => (int) $user->getAuthIdentifier(),
            'name'  => $name !== '' ? $name : ($user->email ?? 'User'),
            'email' => $user->email ?? null,
        ], static fn ($value) => $value !== null && $value !== '');
    }
}
