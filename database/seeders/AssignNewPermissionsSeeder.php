<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AssignNewPermissionsSeeder extends Seeder
{
    /**
     * Insert protocol menu/action permissions and grant them to admin roles/users.
     */
    public function run()
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $newPermissions = [
            // Menu
            'protocol-all',
            'protocol-new-equipment',
            'protocol-fill-new-equipment',
            'protocol-neq-view',
            'protocol-neq-download',
            'protocol-revalidation',
            'protocol-requalification',
            // Actions
            'protocol-approve',
            'protocol-send-back',
            'protocol-reject',
            // List / filter visibility
            'protocol-view-approved',
            'protocol-view-returned',
            'protocol-view-rejected',
            // Legacy menu keys (kept for older roles)
            'protocol-preapproved',
            'protocol-postprocessing',
            'protocol-approved',
            'protocol-my-pending',
        ];

        foreach ($newPermissions as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission, 'guard_name' => 'web']
            );
        }

        $adminRoles = Role::query()
            ->where('guard_name', 'web')
            ->where(function ($query) {
                $query->whereIn('name', ['admin', 'Admin', 'Super Admin', 'super-admin'])
                    ->orWhere('id', 1);
            })
            ->get();

        foreach ($adminRoles as $role) {
            $role->givePermissionTo($newPermissions);
        }

        $adminUsers = User::query()
            ->where(function ($query) {
                $query->where('role_id', 1)
                    ->orWhereHas('roles', function ($roleQuery) {
                        $roleQuery->whereIn('name', ['admin', 'Admin', 'Super Admin', 'super-admin'])
                            ->orWhere('id', 1);
                    });
            })
            ->get();

        foreach ($adminUsers as $user) {
            $user->givePermissionTo($newPermissions);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->command?->info('Protocol menu/action permissions created and assigned to admin.');
    }
}
