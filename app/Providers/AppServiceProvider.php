<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;
use App\Observers\AuditObserver;
use App\Models\ActivityLog;
use App\Models\AuditLog;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        if (! $this->app->runningInConsole()) {
            @ini_set('max_execution_time', '300');
        }
        Paginator::useBootstrap();
        $this->registerAuditObservers();
        $this->ensureRequiredStorageDirectories();
 
        // Set Carbon locale and timezone
        Carbon::setLocale('en');
        date_default_timezone_set('Asia/Kolkata');
 
        // Share fresh SecurityPolicy to all views (no caching)
        view()->composer('*', function ($view) {
            try {
                $view->with('policy', \App\Models\SecurityPolicy::first());
            } catch (\Exception $e) {
                $view->with('policy', null);
            }
        });
 
        // Force email / url() links to use APP_URL (not localhost)
        if ($root = config('app.url')) {
            URL::forceRootUrl($root);
        }
    }

    private function registerAuditObservers(): void
    {
        $modelsPath = app_path('Models');
        if (!File::isDirectory($modelsPath)) {
            return;
        }

        $excluded = [
            AuditLog::class,
            ActivityLog::class,
        ];

        foreach (File::allFiles($modelsPath) as $file) {
            $relativePath = str_replace(
                ['/', '.php'],
                ['\\', ''],
                $file->getRelativePathname()
            );
            $className = 'App\\Models\\' . $relativePath;

            if (!class_exists($className) || !is_subclass_of($className, EloquentModel::class)) {
                continue;
            }

            if (in_array($className, $excluded, true)) {
                continue;
            }

            $className::observe(AuditObserver::class);
        }
    }

    private function ensureRequiredStorageDirectories(): void
    {
        $publicDirectories = [
            'templates/docx',
            'generated_reports',
            'reports/attachments',
            'reports/generated',
            'reports/docx_attachments',
            'new_equipment_qualifications',
            'attachments',
        ];

        foreach ($publicDirectories as $directory) {
            Storage::disk('public')->makeDirectory($directory);
        }

        $localDirectories = [
            'temp',
            'temp/equipment-imports',
        ];

        foreach ($localDirectories as $directory) {
            Storage::disk('local')->makeDirectory($directory);
        }
    }
}
