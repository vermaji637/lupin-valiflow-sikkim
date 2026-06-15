<?php

namespace App\Providers;

use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;
use App\Services\AuditLogger;

class EventServiceProvider extends ServiceProvider
{
    /**
     * Only constant listeners here
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
    ];

    /**
     * Register events using closures (allowed here)
     */
    public function boot(): void
    {
        // Successful Login
        Event::listen(Login::class, function (Login $event) {
            AuditLogger::record('login', [
                'user_id'  => $event->user?->id,
                'login_at' => now(),
                'new_values' => [
                    'email'      => $event->user?->email,
                    'session_id' => session()->getId(),
                ],
            ]);
        });

        // Logout
        Event::listen(Logout::class, function (Logout $event) {
            AuditLogger::record('logout', [
                'user_id'   => $event->user?->id,
                'logout_at' => now(),
                'new_values' => [
                    'session_id' => session()->getId(),
                ],
                'session_urls' => session()->get('audit_session_urls', []),
            ]);
        });

    }
}
