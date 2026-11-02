<?php

namespace App\Console\Commands;

use App\Services\PasswordExpiryService;
use Illuminate\Console\Command;

class SendPasswordExpiryReminders extends Command
{
    protected $signature = 'passwords:send-expiry-reminders';

    protected $description = 'Email users whose passwords are nearing expiry (5d/4d/3d/2d/1d/5h/1h)';

    public function handle(PasswordExpiryService $service): int
    {
        if (! $service->isEnabled()) {
            $this->info('Password expiry policy is disabled. Nothing to send.');

            return self::SUCCESS;
        }

        $stats = $service->sendDueReminders();

        $this->info(sprintf(
            'Password expiry reminders: checked=%d sent=%d skipped=%d errors=%d',
            $stats['checked'],
            $stats['sent'],
            $stats['skipped'],
            $stats['errors']
        ));

        return self::SUCCESS;
    }
}
