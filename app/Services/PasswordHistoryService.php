<?php

namespace App\Services;

use App\Models\PasswordHistory;
use App\Models\SecurityPolicy;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PasswordHistoryService
{
    public function policy(): ?SecurityPolicy
    {
        return SecurityPolicy::query()->first();
    }

    public function isEnabled(?SecurityPolicy $policy = null): bool
    {
        $policy = $policy ?: $this->policy();

        return $policy ? (bool) $policy->password_history_enabled : true;
    }

    public function historyCount(?SecurityPolicy $policy = null): int
    {
        $policy = $policy ?: $this->policy();
        $value = (int) ($policy->password_history_count ?? 5);

        return max(1, min(20, $value));
    }

    public function reuseMessage(?SecurityPolicy $policy = null): string
    {
        $count = $this->historyCount($policy);

        return "You cannot reuse any of your last {$count} passwords. Please choose a different password.";
    }

    /**
     * True when plain password matches current or any of the remembered previous passwords.
     */
    public function isReused(User $user, string $plainPassword): bool
    {
        if (! $this->isEnabled() || $plainPassword === '') {
            return false;
        }

        $count = $this->historyCount();
        $hashes = [];

        if (! empty($user->password)) {
            $hashes[] = $user->password;
        }

        $historyHashes = PasswordHistory::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(max(0, $count - 1))
            ->pluck('password')
            ->all();

        $hashes = array_values(array_unique(array_merge($hashes, $historyHashes)));

        foreach ($hashes as $hash) {
            if (is_string($hash) && $hash !== '' && Hash::check($plainPassword, $hash)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws ValidationException
     */
    public function assertNotReused(User $user, string $plainPassword, string $field = 'password'): void
    {
        if ($this->isReused($user, $plainPassword)) {
            throw ValidationException::withMessages([
                $field => [$this->reuseMessage()],
            ]);
        }
    }

    /**
     * Store the user's current password hash into history, then prune to policy count.
     */
    public function rememberCurrent(User $user): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        if (empty($user->password)) {
            return;
        }

        PasswordHistory::query()->create([
            'user_id' => $user->id,
            'password' => $user->password,
        ]);

        $this->prune($user);
    }

    public function prune(User $user): void
    {
        $count = $this->historyCount();
        $keepIds = PasswordHistory::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit($count)
            ->pluck('id')
            ->all();

        $query = PasswordHistory::query()->where('user_id', $user->id);
        if (! empty($keepIds)) {
            $query->whereNotIn('id', $keepIds);
        }
        $query->delete();
    }
}
