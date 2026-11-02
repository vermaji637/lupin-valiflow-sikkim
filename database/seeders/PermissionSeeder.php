<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $permissions = [
            'user-list',
            'user-create',
            'user-edit',
            'user-delete',
            'role-create',
            'role-edit',
            'role-list',
            'role-delete',
            'permission-list',
            'permission-create',
            'permission-edit',
            'permission-delete',
            // Masters
            'equipment-list',
            'equipment-create',
            'protocol-type-list',
            'facility-list',
            'document-view',
            'document-create',
            // Template
            'template-list',
            'template-create',
            'template-view',
            // Protocol
            'protocol-create',
            'protocol-view',
            'protocol-approve',
            'protocol-send-back',
            'protocol-reject',
            'protocol-all',
            'protocol-new-equipment',
            'protocol-fill-new-equipment',
            'protocol-neq-view',
            'protocol-neq-download',
            'protocol-revalidation',
            'protocol-requalification',
            'protocol-view-approved',
            'protocol-view-returned',
            'protocol-view-rejected',
            'protocol-preapproved',
            'protocol-postprocessing',
            'protocol-approved',
            'protocol-my-pending',
            // Report
            'report-fill',
            // Audit
            'audit-view',
            'activity-view',
        ];

        foreach($permissions as $permission){
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        // All Permissions
        $permission_saved = Permission::pluck('id')->toArray();
        
        // Give Role Admin All Access
        $role = Role::whereId(1)->first();
        $role->syncPermissions($permission_saved);
        
        // Admin Role Sync Permission
        $user = User::where('role_id', 1)->first();
        $user->assignRole($role->id);
    }
}
