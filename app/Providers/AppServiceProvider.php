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
        Paginator::useBootstrap();
        $this->registerAuditObservers();

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
}
