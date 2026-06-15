<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;

class CleanupOldAuditLogs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'audit:cleanup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up audit logs older than 30 days';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Starting audit logs cleanup...');
        
        $deletedCount = AuditLog::where('created_at', '<', now()->subDays(30))->delete();
        
        $this->info("Deleted {$deletedCount} audit log entries older than 30 days.");
        
        return Command::SUCCESS;
    }
}
