<?php

namespace App\Services;

use Spatie\Permission\Models\Permission;

class PermissionGroupingService
{
    /**
     * Define permission groups based on sidebar structure
     */
    public static function getPermissionGroups(): array
    {
        return [
            'user_management' => [
                'title' => 'User Management',
                'icon' => 'fas fa-user-alt',
                'permissions' => [
                    'user-list',
                    'user-create', 
                    'user-edit',
                    'user-delete',
                    'user-status-change',
                    'user-import',
                    'user-export',
                    'user-change-password',
                    'security-policy-view',
                    'security-policy-edit',
                    'role-list',
                    'role-create',
                    'role-edit',
                    'role-delete',
                    'permission-list',
                    'permission-create',
                    'permission-edit',
                    'permission-delete',
                    'permission-assign',
                ]
            ],
            'masters' => [
                'title' => 'Masters',
                'icon' => 'fas fa-folder',
                'permissions' => [
                    'equipment-list',
                    'equipment-create',
                    'equipment-edit',
                    'equipment-delete',
                    'protocol-type-list',
                    'protocol-type-create',
                    'protocol-type-edit',
                    'protocol-type-delete',
                    'facility-list',
                    'facility-create',
                    'facility-edit',
                    'facility-delete',
                    'document-view',
                    'document-create',
                    'document-edit',
                    'document-delete',
                    'document-download',
                    'document-upload',
                ]
            ],
            'protocol_template' => [
                'title' => 'Protocol Template',
                'icon' => 'fas fa-file-alt',
                'permissions' => [
                    'template-list',
                    'template-create',
                    'template-edit',
                    'template-delete',
                    'template-view',
                    'template-fill',
                    'template-download',
                    'template-export',
                    'template-import',
                    'template-clone',
                    'template-publish',
                    'template-status-change',
                    'template-store',
                    'template-update',
                    'template-submit',
                ]
            ],
            'protocol' => [
                'title' => 'Protocol',
                'icon' => 'fas fa-clipboard-list',
                'permissions' => [
                    'protocol-list',
                    'protocol-create',
                    'protocol-view',
                    'protocol-edit',
                    'protocol-delete',
                    'protocol-approve',
                    'protocol-reject',
                    'protocol-submit',
                    'protocol-download',
                    'protocol-export',
                    'protocol-clone',
                    'protocol-status-change',
                    'workflow-complete',
                    'task-complete',
                    'protocol-all',
                    'protocol-preapproved',
                    'protocol-postprocessing',
                    'protocol-approved',
                    'protocol-my-pending',
                ]
            ],
            'audit' => [
                'title' => 'Audit',
                'icon' => 'fas fa-shield-alt',
                'permissions' => [
                    'audit-list',
                    'audit-view',
                    'audit-filter',
                    'audit-filter-users',
                    'audit-search',
                    'audit-export',
                    'audit-download',
                    'audit-create',
                    'audit-edit',
                    'audit-delete',
                    'audit-approve',
                    'audit-status-change',
                    'audit-restore',
                    'audit-archive',
                    'audit-report',
                    'activity-list',
                    'activity-view',
                    'activity-filter',
                    'activity-search',
                    'activity-export',
                ]
            ],
        ];
    }

    /**
     * Get all permissions grouped by section
     */
    public static function getGroupedPermissions(): array
    {
        $allPermissions = Permission::where('guard_name', 'web')
            ->orderBy('name')
            ->pluck('name')
            ->toArray();

        $definedGroups = self::getPermissionGroups();
        $groupedPermissions = [];
        $assignedPermissions = [];

        // Process defined groups
        foreach ($definedGroups as $key => $group) {
            $groupPermissions = [];
            
            foreach ($group['permissions'] as $permissionName) {
                if (in_array($permissionName, $allPermissions)) {
                    $groupPermissions[] = $permissionName;
                    $assignedPermissions[] = $permissionName;
                }
            }

            if (!empty($groupPermissions)) {
                $groupedPermissions[$key] = [
                    'title' => $group['title'],
                    'icon' => $group['icon'],
                    'permissions' => $groupPermissions
                ];
            }
        }

        // Add "Other Permissions" for remaining permissions
        $remainingPermissions = array_diff($allPermissions, $assignedPermissions);
        
        if (!empty($remainingPermissions)) {
            $groupedPermissions['other'] = [
                'title' => 'Other Permissions',
                'icon' => 'fas fa-ellipsis-h',
                'permissions' => array_values($remainingPermissions)
            ];
        }

        return $groupedPermissions;
    }

    /**
     * Get permission details with IDs for a group
     */
    public static function getGroupPermissionDetails(string $groupKey): array
    {
        $groupedPermissions = self::getGroupedPermissions();
        
        if (!isset($groupedPermissions[$groupKey])) {
            return [];
        }

        $group = $groupedPermissions[$groupKey];
        $permissionDetails = [];

        foreach ($group['permissions'] as $permissionName) {
            $permission = Permission::where('name', $permissionName)
                ->where('guard_name', 'web')
                ->first();

            if ($permission) {
                $permissionDetails[] = [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'display_name' => self::formatPermissionDisplayName($permission->name)
                ];
            }
        }

        return $permissionDetails;
    }

    /**
     * Format permission name for display
     */
    public static function formatPermissionDisplayName(string $permissionName): string
    {
        return ucwords(str_replace(['-', '_'], ' ', $permissionName));
    }

    /**
     * Get all permission details grouped
     */
    public static function getAllGroupedPermissionDetails(): array
    {
        $groupedPermissions = self::getGroupedPermissions();
        $allGroupDetails = [];

        foreach ($groupedPermissions as $key => $group) {
            $allGroupDetails[$key] = [
                'title' => $group['title'],
                'icon' => $group['icon'],
                'permissions' => []
            ];

            foreach ($group['permissions'] as $permissionName) {
                $permission = Permission::where('name', $permissionName)
                    ->where('guard_name', 'web')
                    ->first();

                if ($permission) {
                    $allGroupDetails[$key]['permissions'][] = [
                        'id' => $permission->id,
                        'name' => $permission->name,
                        'display_name' => self::formatPermissionDisplayName($permission->name)
                    ];
                }
            }
        }

        return $allGroupDetails;
    }

    /**
     * Validate if permission exists in database
     */
    public static function validatePermissions(array $permissionIds): array
    {
        $validPermissions = Permission::whereIn('id', $permissionIds)
            ->where('guard_name', 'web')
            ->pluck('id')
            ->toArray();

        return $validPermissions;
    }
}
