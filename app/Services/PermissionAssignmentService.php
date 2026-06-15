<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionAssignmentService
{
    /**
     * Assign ALL permissions from permissions table to role_id = 1
     * 
     * @return array Results of the assignment process
     */
    public static function assignAllPermissionsToAdmin(): array
    {
        $results = [
            'total_permissions' => 0,
            'existing_assignments' => 0,
            'new_assignments' => 0,
            'errors' => []
        ];

        try {
            // Get all permissions from permissions table
            $permissions = Permission::all();
            $results['total_permissions'] = $permissions->count();

            // Get admin role
            $adminRole = Role::findOrFail(1);

            // Get existing permission assignments for admin role
            $existingPermissionIds = DB::table('role_has_permissions')
                ->where('role_id', 1)
                ->pluck('permission_id')
                ->toArray();

            $results['existing_assignments'] = count($existingPermissionIds);

            // Prepare insert data for missing permissions
            $insertData = [];
            foreach ($permissions as $permission) {
                // Skip if permission already assigned to admin role
                if (!in_array($permission->id, $existingPermissionIds)) {
                    $insertData[] = [
                        'permission_id' => $permission->id,
                        'role_id' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            $results['new_assignments'] = count($insertData);

            // Insert missing permissions in batch if any
            if (!empty($insertData)) {
                DB::table('role_has_permissions')->insert($insertData);
            }

            // Clear permission cache
            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        } catch (\Exception $e) {
            $results['errors'][] = $e->getMessage();
        }

        return $results;
    }

    /**
     * Alternative method using Spatie's sync functionality
     * More efficient and handles all edge cases
     * 
     * @return array Results of the sync process
     */
    public static function syncAllPermissionsToAdmin(): array
    {
        $results = [
            'total_permissions' => 0,
            'before_count' => 0,
            'after_count' => 0,
            'errors' => []
        ];

        try {
            // Get all permission IDs
            $allPermissionIds = Permission::pluck('id')->toArray();
            $results['total_permissions'] = count($allPermissionIds);

            // Get admin role
            $adminRole = Role::findOrFail(1);

            // Count current permissions
            $results['before_count'] = $adminRole->permissions()->count();

            // Use Spatie's sync method (most efficient)
            $adminRole->permissions()->sync($allPermissionIds);

            // Count after sync
            $results['after_count'] = $adminRole->permissions()->count();

            // Clear permission cache
            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        } catch (\Exception $e) {
            $results['errors'][] = $e->getMessage();
        }

        return $results;
    }

    /**
     * Manual loop method as requested in example
     * Less efficient but follows exact requested logic
     * 
     * @return array Results of the assignment process
     */
    public static function manualAssignAllPermissionsToAdmin(): array
    {
        $results = [
            'total_permissions' => 0,
            'existing_assignments' => 0,
            'new_assignments' => 0,
            'errors' => []
        ];

        try {
            // Get all permissions from permissions table
            $permissions = Permission::all();
            $results['total_permissions'] = $permissions->count();

            foreach ($permissions as $permission) {
                // Check if permission already exists for role_id = 1
                $exists = DB::table('role_has_permissions')
                    ->where('role_id', 1)
                    ->where('permission_id', $permission->id)
                    ->exists();

                if ($exists) {
                    $results['existing_assignments']++;
                } else {
                    // Insert the permission assignment
                    DB::table('role_has_permissions')->insert([
                        'permission_id' => $permission->id,
                        'role_id' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $results['new_assignments']++;
                }
            }

            // Clear permission cache
            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        } catch (\Exception $e) {
            $results['errors'][] = $e->getMessage();
        }

        return $results;
    }
}
