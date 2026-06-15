<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;

class SidebarHelper
{
    /**
     * Get explicit route name for a permission
     */
    private static function getRouteForPermission($permission)
    {
        $routes = config('permission_routes');
        return $routes[$permission] ?? null;
    }

    /**
     * Check if route exists before returning URL
     */
    private static function getSafeRoute($permission)
    {
        $routeName = self::getRouteForPermission($permission);
        
        if (!$routeName || !Route::has($routeName)) {
            \Log::warning("Route '{$routeName}' not found for permission '{$permission}'");
            return null;
        }
        
        return $routeName;
    }

    /**
     * Get all permissions for the current user grouped by category
     */
    public static function getGroupedPermissions()
    {
        if (!Auth::check()) {
            return [];
        }

        $user = Auth::user();
        $permissions = $user->getAllPermissions();
        
        return self::groupPermissionsByCategory($permissions);
    }

    /**
     * Group permissions by logical categories for sidebar organization
     */
    private static function groupPermissionsByCategory($permissions)
    {
        $groups = [
            'user_management' => [
                'title' => 'User Management',
                'icon' => 'fas fa-user-alt',
                'permissions' => ['user-list', 'user-create', 'user-edit', 'user-delete', 'user-import', 'security-policy-view', 'security-policy-edit', 'role-list']
            ],
            'masters' => [
                'title' => 'Masters', 
                'icon' => 'fas fa-cogs',
                'permissions' => ['permission-list', 'permission-create', 'permission-assign', 'equipment-list', 'equipment-create', 'protocol-type-list', 'facility-list', 'document-view', 'document-create']
            ],
            'template' => [
                'title' => 'Template',
                'icon' => 'fas fa-file-alt',
                'permissions' => ['template-create', 'template-view']
            ],
            'protocol' => [
                'title' => 'Protocol',
                'icon' => 'fas fa-clipboard-list', 
                'permissions' => ['protocol-create', 'protocol-view', 'protocol-approve', 'protocol-all', 'protocol-preapproved', 'protocol-postprocessing', 'protocol-approved', 'protocol-my-pending']
            ],
            'audit' => [
                'title' => 'Audit',
                'icon' => 'fas fa-history',
                'permissions' => ['audit-view', 'activity-view']
            ]
        ];

        $userGroups = [];

        foreach ($groups as $key => $group) {
            $hasPermission = false;
            $userPermissions = [];

            foreach ($group['permissions'] as $permission) {
                if ($permissions->pluck('name')->contains($permission)) {
                    $hasPermission = true;
                    $userPermissions[] = $permission;
                }
            }

            if ($hasPermission) {
                $userGroups[$key] = [
                    'title' => $group['title'],
                    'icon' => $group['icon'],
                    'permissions' => $userPermissions
                ];
            }
        }

        return $userGroups;
    }

    /**
     * Check if user has any permission in a group
     */
    public static function hasAnyPermissionInGroup($groupKey)
    {
        $groupedPermissions = self::getGroupedPermissions();
        return isset($groupedPermissions[$groupKey]);
    }

    /**
     * Check if user has specific permission
     */
    public static function hasPermission($permission)
    {
        if (!Auth::check()) {
            return false;
        }

        return Auth::user()->can($permission);
    }

    /**
     * Get safe route URL for permission
     */
    public static function getRouteUrl($permission)
    {
        $routeName = self::getSafeRoute($permission);
        
        if (!$routeName) {
            return '#';
        }
        
        try {
            return route($routeName);
        } catch (\Exception $e) {
            \Log::error("Route error for '{$routeName}': " . $e->getMessage());
            return '#';
        }
    }

    /**
     * Get all available permissions from database (for admin reference)
     */
    public static function getAllAvailablePermissions()
    {
        return Permission::orderBy('name')->get()->groupBy(function ($permission) {
            return self::getPermissionCategory($permission->name);
        });
    }

    /**
     * Determine category based on permission name
     */
    private static function getPermissionCategory($permissionName)
    {
        $categories = [
            'user_management' => ['user-list', 'user-create', 'user-edit', 'user-delete', 'user-import', 'security-policy-view', 'security-policy-edit', 'role-list'],
            'masters' => ['permission-list', 'permission-create', 'permission-assign', 'equipment-list', 'equipment-create', 'protocol-type-list', 'facility-list', 'document-view', 'document-create'],
            'template' => ['template-create', 'template-view'],
            'protocol' => ['protocol-create', 'protocol-view', 'protocol-approve', 'protocol-all', 'protocol-preapproved', 'protocol-postprocessing', 'protocol-approved', 'protocol-my-pending'],
            'audit' => ['audit-view', 'activity-view']
        ];

        foreach ($categories as $category => $permissions) {
            if (in_array($permissionName, $permissions)) {
                return $category;
            }
        }

        return 'other';
    }
}
