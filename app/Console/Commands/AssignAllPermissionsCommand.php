<?php

namespace App\Console\Commands;

use App\Services\PermissionAssignmentService;
use Illuminate\Console\Command;

class AssignAllPermissionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'permissions:assign-all {--method=sync : Assignment method (sync|manual|batch)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Assign ALL permissions to admin role (role_id = 1)';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $method = $this->option('method') ?? 'sync';
        
        $this->info("Starting permission assignment to admin role (role_id = 1)...");
        $this->info("Method: {$method}");
        $this->line(str_repeat('-', 50));

        switch ($method) {
            case 'sync':
                $results = PermissionAssignmentService::syncAllPermissionsToAdmin();
                break;
            case 'manual':
                $results = PermissionAssignmentService::manualAssignAllPermissionsToAdmin();
                break;
            case 'batch':
                $results = PermissionAssignmentService::assignAllPermissionsToAdmin();
                break;
            default:
                $this->error("Invalid method: {$method}. Use sync, manual, or batch.");
                return 1;
        }

        // Display results
        $this->displayResults($results);

        return 0;
    }

    /**
     * Display assignment results
     */
    private function displayResults(array $results): void
    {
        if (!empty($results['errors'])) {
            foreach ($results['errors'] as $error) {
                $this->error("Error: {$error}");
            }
            return;
        }

        if (isset($results['total_permissions'])) {
            $this->info("Total permissions in database: {$results['total_permissions']}");
        }

        if (isset($results['existing_assignments'])) {
            $this->info("Existing assignments: {$results['existing_assignments']}");
        }

        if (isset($results['new_assignments'])) {
            $this->info("New assignments: {$results['new_assignments']}");
        }

        if (isset($results['before_count'])) {
            $this->info("Before assignment: {$results['before_count']} permissions");
        }

        if (isset($results['after_count'])) {
            $this->info("After assignment: {$results['after_count']} permissions");
        }

        $this->line(str_repeat('-', 50));
        $this->info("✅ Permission assignment completed successfully!");
        $this->info("🔄 Permission cache cleared");
        $this->info("👤 Admin role (role_id = 1) now has ALL permissions");
    }
}
