<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class AssignNewPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // New protocol permissions to assign
        $newPermissions = [
            'protocol-all',
            'protocol-preapproved',
            'protocol-postprocessing',
            'protocol-approved',
            'protocol-my-pending',
        ];

        // Create new permissions if they don't exist
        foreach ($newPermissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // Get Admin role and assign all new permissions
        $adminRole = Role::where('name', 'admin')->first();
        if ($adminRole) {
            $adminRole->givePermissionTo($newPermissions);
        }

        // Also assign to any existing admin users
        $adminUsers = User::whereHas('roles', function($query) {
            $query->where('name', 'admin');
        })->get();

        foreach ($adminUsers as $user) {
            $user->givePermissionTo($newPermissions);
        }

        $this->command->info('New protocol permissions assigned to admin role and users successfully!');
    }
}
