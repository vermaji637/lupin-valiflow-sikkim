<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // Audit logs are retained permanently — cleanup disabled
        // $schedule->command('audit:cleanup')->daily();

        // Password expiry reminders (5d/4d/3d/2d/1d/5h/1h) — needs schedule:run every minute via Task Scheduler
        $schedule->command('passwords:send-expiry-reminders')->everyFifteenMinutes();

        // Daily backup → BACKUP_PATH/daily/YYYY-MM-DD (prune 7 days)
        // + weekly forever → BACKUP_PATH/weekly/YYYY-MM-W1..W5 (never pruned)
        $schedule->command('backup:daily')
            ->dailyAt(config('backup.daily_at', '02:00'))
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/backup-daily.log'));
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
