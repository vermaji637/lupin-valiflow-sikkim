<?php

namespace App\Services;

use App\Mail\AccountLockedMail;
use App\Models\SecurityPolicy;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class LoginLockoutService
{
    public function policy(): ?SecurityPolicy
    {
        return SecurityPolicy::query()->first();
    }

    public function isEnabled(?SecurityPolicy $policy = null): bool
    {
        $policy = $policy ?: $this->policy();

        return $policy ? (bool) $policy->login_lockout_enabled : true;
    }

    public function maxAttempts(?SecurityPolicy $policy = null): int
    {
        $policy = $policy ?: $this->policy();
        $value = (int) ($policy->max_failed_login_attempts ?? 5);

        return max(1, min(50, $value));
    }

    public function lockoutMinutes(?SecurityPolicy $policy = null): int
    {
        $policy = $policy ?: $this->policy();
        $value = (int) ($policy->lockout_minutes ?? 10);

        return max(1, min(1440, $value));
    }

    public function isLocked(User $user): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        if (! $user->locked_until) {
            return false;
        }

        $until = Carbon::parse($user->locked_until);
        if ($until->isFuture()) {
            return true;
        }

        // Auto-unlock when lock window has expired.
        $this->clearFailures($user);

        return false;
    }

    public function remainingLockSeconds(User $user): int
    {
        if (! $user->locked_until) {
            return 0;
        }

        return max(0, Carbon::parse($user->locked_until)->getTimestamp() - Carbon::now()->getTimestamp());
    }

    public function lockedMessage(User $user): string
    {
        $seconds = $this->remainingLockSeconds($user);
        $minutes = max(1, (int) ceil($seconds / 60));

        return "Your account is locked due to too many wrong password attempts. Please try again after {$minutes} minute(s).";
    }

    public function clearFailures(User $user): void
    {
        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_failed_login_at' => null,
        ])->save();
    }

    /**
     * Record a failed password attempt. Returns true when the account was newly locked.
     */
    public function recordFailedAttempt(User $user): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $policy = $this->policy();
        $attempts = (int) ($user->failed_login_attempts ?? 0) + 1;
        $max = $this->maxAttempts($policy);
        $minutes = $this->lockoutMinutes($policy);

        $payload = [
            'failed_login_attempts' => $attempts,
            'last_failed_login_at' => Carbon::now(),
        ];

        $newlyLocked = false;

        if ($attempts >= $max) {
            $payload['locked_until'] = Carbon::now()->addMinutes($minutes);
            $payload['failed_login_attempts'] = 0;
            $newlyLocked = true;
        }

        $user->forceFill($payload)->save();

        if ($newlyLocked) {
            $this->notifyLock($user, $minutes, $policy);
        }

        return $newlyLocked;
    }

    protected function notifyLock(User $user, int $minutes, ?SecurityPolicy $policy): void
    {
        $notifyEmail = $policy ? (bool) $policy->lockout_notify_email : true;
        $notifySms = $policy ? (bool) $policy->lockout_notify_sms : true;
        $name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: 'User';
        $until = $user->locked_until
            ? Carbon::parse($user->locked_until)->format('d M Y h:i A')
            : null;

        if ($notifyEmail && ! empty($user->email)) {
            try {
                Mail::to($user->email)->send(new AccountLockedMail($name, $minutes, $until));
            } catch (\Throwable $e) {
                Log::error('Account lock email failed.', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($notifySms && ! empty($user->mobile_number)) {
            $message = "ValiFlow: Your account was locked for {$minutes} minute(s) due to multiple wrong password attempts."
                . ($until ? " Unlock after {$until}." : '');

            try {
                app(SmsService::class)->send((string) $user->mobile_number, $message);
            } catch (\Throwable $e) {
                Log::error('Account lock SMS failed.', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
