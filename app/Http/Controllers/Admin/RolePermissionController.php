<?php 

// app/Http/Controllers/Admin/RolePermissionController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Permission;
use App\Services\PermissionGroupingService;
use Illuminate\Http\Request;
use DB;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:permission-assign');
    }

    public function edit($roleId)
    {
        $role = \Spatie\Permission\Models\Role::with('permissions')->findOrFail($roleId);
        $rolePermissions = $role->permissions->pluck('id')->toArray();
        
        // Get master permissions from Role ID = 1 (Super Admin) - this defines the allowed permission set for ALL roles
        $masterRole = \Spatie\Permission\Models\Role::where('id', 1)->first();
        $masterPermissions = $masterRole ? $masterRole->permissions()->pluck('id')->toArray() : [];
        
        // Get grouped permissions for dynamic UI
        $groupedPermissions = PermissionGroupingService::getAllGroupedPermissionDetails();
        
        // Apply master permission filtering for ALL roles
        $groupedPermissions = $this->filterGroupedPermissionsByMaster($groupedPermissions, $masterPermissions);

        return view('roles.permissions', compact(
            'role',
            'groupedPermissions',
            'rolePermissions',
            'masterPermissions'
        ));
    }

    public function update(Request $request, $roleId)
    {
        DB::beginTransaction();

        try {
            $role = \Spatie\Permission\Models\Role::findOrFail($roleId);

            $permissionIds = $request->input('permissions', []);

            // Validate IDs exist in DB
            $validPermissions = PermissionGroupingService::validatePermissions($permissionIds);

            // STRICT SYNC using Spatie best practice
            $role->syncPermissions($validPermissions);

            // Clear permission cache
            app()[PermissionRegistrar::class]->forgetCachedPermissions();

            DB::commit();

            return redirect()->back()->with('success', 'Permissions updated successfully.');

        } catch (\Exception $e) {

            DB::rollBack();

            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /**
     * Filter grouped permissions to only include permissions from master permission set (Role ID = 1)
     * This is used for UI filtering only - does NOT modify database
     * Role ID = 1 defines the master permission template for the entire system
     */
    private function filterGroupedPermissionsByMaster(array $groupedPermissions, array $masterPermissionIds): array
    {
        $filteredGroups = [];
        
        foreach ($groupedPermissions as $groupKey => $group) {
            $filteredPermissions = [];
            
            foreach ($group['permissions'] as $permission) {
                // Only include permissions that exist in master permission set
                if (in_array($permission['id'], $masterPermissionIds)) {
                    $filteredPermissions[] = $permission;
                }
            }
            
            // Only include groups that have permissions
            if (!empty($filteredPermissions)) {
                $filteredGroups[$groupKey] = [
                    'title' => $group['title'],
                    'icon' => $group['icon'],
                    'permissions' => $filteredPermissions
                ];
            }
        }
        
        return $filteredGroups;
    }
}
