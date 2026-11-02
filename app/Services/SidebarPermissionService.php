<?php

namespace App\Services;

class SidebarPermissionService
{
    /**
     * Define permission groups for sidebar sections
     */
    public static function getSidebarPermissionGroups(): array
    {
        return [
            'user_management' => [
                'permissions' => [
                    'user-list',
                    'user-create',
                    'user-edit',
                    'user-delete',
                    'user-status-change',
                    'user-import',
                    'user-export',
                    'user-change-password',
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
            'settings' => [
                'permissions' => [
                    'security-policy-view',
                    'security-policy-edit',
                ]
            ],
            'masters' => [
                'permissions' => [
                    'permission-list',
                    'permission-create',
                    'permission-edit',
                    'permission-delete',
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
                'permissions' => [
                    'protocol-list',
                    'protocol-create',
                    'protocol-view',
                    'protocol-edit',
                    'protocol-delete',
                    'protocol-approve',
                    'protocol-send-back',
                    'protocol-reject',
                    'protocol-submit',
                    'protocol-download',
                    'protocol-export',
                    'protocol-clone',
                    'protocol-status-change',
                    'workflow-complete',
                    'task-complete',
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
                    'report-fill',
                ]
            ],
            'audit' => [
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
            'qa_documents' => [
    'permissions' => [
        'qa-documents-view',
    ]
],
        ];
    }

    /**
     * Check if user has any permission in a specific group
     */
    public static function userHasAnyPermissionInGroup(string $groupKey): bool
    {
        if (!auth()->check()) {
            return false;
        }

        $groups = self::getSidebarPermissionGroups();

        if (!isset($groups[$groupKey])) {
            return false;
        }

        $permissions = $groups[$groupKey]['permissions'];

        // Check each permission individually
        foreach ($permissions as $permission) {
            if (auth()->user()->can($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all permissions for a specific group
     */
    public static function getGroupPermissions(string $groupKey): array
    {
        $groups = self::getSidebarPermissionGroups();

        return $groups[$groupKey]['permissions'] ?? [];
    }

    /**
     * Check if user has specific permission (helper method)
     */
    public static function userCan(string $permission): bool
    {
        return auth()->check() && auth()->user()->can($permission);
    }

    /**
     * Check if user has any of the specified permissions
     */
    public static function userCanAny(array $permissions): bool
    {
        if (!auth()->check()) {
            return false;
        }

        foreach ($permissions as $permission) {
            if (auth()->user()->can($permission)) {
                return true;
            }
        }

        return false;
    }
}
