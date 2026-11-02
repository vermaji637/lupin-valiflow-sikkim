<?php

namespace App\Services;

use App\Mail\PasswordExpiryReminder;
use App\Models\SecurityPolicy;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class PasswordExpiryService
{
    /**
     * Fixed reminder offsets before expiry (as requested).
     *
     * @return array<int, array{key: string, label: string, seconds: int}>
     */
    public function reminderDefinitions(): array
    {
        return [
            ['key' => '5d', 'label' => '5 days', 'seconds' => 5 * 24 * 3600],
            ['key' => '4d', 'label' => '4 days', 'seconds' => 4 * 24 * 3600],
            ['key' => '3d', 'label' => '3 days', 'seconds' => 3 * 24 * 3600],
            ['key' => '2d', 'label' => '2 days', 'seconds' => 2 * 24 * 3600],
            ['key' => '1d', 'label' => '1 day', 'seconds' => 1 * 24 * 3600],
            ['key' => '5h', 'label' => '5 hours', 'seconds' => 5 * 3600],
            ['key' => '1h', 'label' => '1 hour', 'seconds' => 1 * 3600],
        ];
    }

    public function policy(): ?SecurityPolicy
    {
        return SecurityPolicy::query()->first();
    }

    public function isEnabled(?SecurityPolicy $policy = null): bool
    {
        $policy ??= $this->policy();

        return (bool) ($policy?->password_expiry_enabled);
    }

    public function expiryDays(?SecurityPolicy $policy = null): int
    {
        $policy ??= $this->policy();
        $days = (int) ($policy?->password_expiry_days ?? 45);

        return max(1, $days);
    }

    public function passwordChangedAt(User $user): Carbon
    {
        if ($user->password_changed_at) {
            return Carbon::parse($user->password_changed_at);
        }

        if ($user->created_at) {
            return Carbon::parse($user->created_at);
        }

        return now()->subYears(10);
    }

    public function expiresAt(User $user, ?SecurityPolicy $policy = null): Carbon
    {
        return $this->passwordChangedAt($user)->copy()->addDays($this->expiryDays($policy));
    }

    public function isExpired(User $user, ?SecurityPolicy $policy = null): bool
    {
        $policy ??= $this->policy();
        if (! $this->isEnabled($policy)) {
            return false;
        }

        return now()->greaterThanOrEqualTo($this->expiresAt($user, $policy));
    }

    public function secondsUntilExpiry(User $user, ?SecurityPolicy $policy = null): int
    {
        $diff = now()->diffInSeconds($this->expiresAt($user, $policy), false);

        return (int) max(0, $diff);
    }

    /**
     * Mark password as freshly set and clear reminder history for the new cycle.
     */
    public function markPasswordChanged(User $user): void
    {
        $user->forceFill([
            'password_changed_at' => now(),
            'password_expiry_reminders_sent' => [],
        ])->save();
    }

    /**
     * @return array<int, string>
     */
    public function sentReminderKeys(User $user): array
    {
        $raw = $user->password_expiry_reminders_sent;
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        return is_array($raw) ? array_values(array_map('strval', $raw)) : [];
    }

    public function markReminderSent(User $user, string $key): void
    {
        $sent = $this->sentReminderKeys($user);
        if (! in_array($key, $sent, true)) {
            $sent[] = $key;
        }

        $user->forceFill([
            'password_expiry_reminders_sent' => $sent,
        ])->save();
    }

    /**
     * Reminder is due in its window: nextLower < secondsLeft <= current.
     * Missed windows are catch-up sent once (still unsent and secondsLeft <= offset).
     *
     * @return array{key: string, label: string, seconds: int}|null
     */
    public function nextDueReminder(User $user, ?SecurityPolicy $policy = null): ?array
    {
        $secondsLeft = $this->secondsUntilExpiry($user, $policy);
        if ($secondsLeft <= 0) {
            return null;
        }

        $sentKeys = $this->sentReminderKeys($user);
        $definitions = $this->reminderDefinitions();

        foreach ($definitions as $index => $reminder) {
            if (in_array($reminder['key'], $sentKeys, true)) {
                continue;
            }

            $lowerBound = $definitions[$index + 1]['seconds'] ?? 0;
            $inWindow = $secondsLeft <= $reminder['seconds'] && $secondsLeft > $lowerBound;
            $missedWindow = $secondsLeft <= $lowerBound;

            if ($inWindow || $missedWindow) {
                return $reminder;
            }
        }

        return null;
    }

    /**
     * Send due reminders for all active users. Returns counts for logging.
     *
     * @return array{checked: int, sent: int, skipped: int, errors: int}
     */
    public function sendDueReminders(): array
    {
        $policy = $this->policy();
        $stats = ['checked' => 0, 'sent' => 0, 'skipped' => 0, 'errors' => 0];

        if (! $this->isEnabled($policy)) {
            return $stats;
        }

        $resetUrl = Route::has('password.request')
            ? route('password.request')
            : url('/password/reset');

        User::query()
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->when(
                Schema::hasColumn('users', 'status'),
                fn ($q) => $q->where(function ($inner) {
                    $inner->whereNull('status')
                        ->orWhereIn('status', [1, '1', 'active', 'Active']);
                })
            )
            ->orderBy('id')
            ->chunkById(100, function ($users) use (&$stats, $policy, $resetUrl) {
                foreach ($users as $user) {
                    $stats['checked']++;

                    if ($this->isExpired($user, $policy)) {
                        $stats['skipped']++;
                        continue;
                    }

                    $reminder = $this->nextDueReminder($user, $policy);
                    if (! $reminder) {
                        continue;
                    }

                    try {
                        Mail::to($user->email)->send(new PasswordExpiryReminder(
                            userName: trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: 'User',
                            expiresAt: $this->expiresAt($user, $policy),
                            remainingLabel: $reminder['label'],
                            resetUrl: $resetUrl,
                        ));

                        $this->markReminderSent($user, $reminder['key']);
                        $stats['sent']++;
                    } catch (\Throwable $e) {
                        $stats['errors']++;
                        Log::error('Password expiry reminder failed', [
                            'user_id' => $user->id,
                            'reminder' => $reminder['key'],
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $stats;
    }
}
