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
    protected $description = 'Audit log cleanup — DISABLED (logs are retained permanently)';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->warn('Audit log cleanup is DISABLED. Audit records are retained permanently.');
        $this->info('No records were deleted.');

        return Command::SUCCESS;
    }
}
