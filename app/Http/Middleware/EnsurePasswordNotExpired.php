<?php

namespace App\Http\Middleware;

use App\Services\PasswordExpiryService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsurePasswordNotExpired
{
    public function handle(Request $request, Closure $next)
    {
        if (! Auth::check()) {
            return $next($request);
        }

        // Allow logout and password reset routes while expired.
        if ($request->routeIs('logout', 'password.request', 'password.email', 'password.reset', 'password.update', 'login')) {
            return $next($request);
        }

        $user = Auth::user();
        $service = app(PasswordExpiryService::class);

        if ($service->isExpired($user)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $resetRoute = $request->routeIs('password.*')
                ? null
                : ( \Illuminate\Support\Facades\Route::has('password.request')
                    ? route('password.request')
                    : url('/password/reset'));

            return redirect()->route('login')
                ->with('error', 'Your password has expired. Please use Forgot Password to set a new password.')
                ->with('password_expired', true)
                ->with('password_reset_url', $resetRoute);
        }

        return $next($request);
    }
}
