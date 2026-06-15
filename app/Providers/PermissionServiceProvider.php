<?php

namespace App\Providers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionServiceProvider extends ServiceProvider
{
    public function register()
    {
        //
    }

    public function boot()
    {
        // Listen for permission changes and clear cache automatically
        Event::listen([
            'permission.created',
            'permission.updated', 
            'permission.deleted',
            'role.created',
            'role.updated',
            'role.deleted',
            'permission.synced',
            'role.synced',
        ], function ($event) {
            $this->clearPermissionCache();
        });

        // Listen for role_has_permissions changes
        Event::listen([
            'role_has_permissions.attached',
            'role_has_permissions.detached',
            'role_has_permissions.synced',
        ], function ($event) {
            $this->clearPermissionCache();
        });

        // Listen for model_has_roles changes
        Event::listen([
            'model_has_roles.attached',
            'model_has_roles.detached', 
            'model_has_roles.synced',
        ], function ($event) {
            $this->clearPermissionCache();
        });
    }

    private function clearPermissionCache()
    {
        try {
            Cache::forget(config('permission.cache.key'));
            app()['cache']->forget(config('permission.cache.key'));
        } catch (\Exception $e) {
            // Log error but don't break the application
            \Log::error('Failed to clear permission cache: ' . $e->getMessage());
        }
    }
}
