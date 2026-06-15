<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;
use App\Models\SecurityPolicy;

class AutoLogoutMiddleware
{
    public function handle($request, Closure $next)
    {
        if (Auth::check()) {

            // Direct DB fetch - no caching for immediate updates
            $policy = SecurityPolicy::first();
            $timeout = ($policy && $policy->auto_logout_minutes ? $policy->auto_logout_minutes : 30) * 60;

            if (session()->has('last_activity')) {

                if (time() - session('last_activity') > $timeout) {

                    Auth::logout();
                    session()->invalidate();
                    session()->regenerateToken();

                    return redirect()->route('login')
                        ->with('error', 'Session expired due to inactivity.');
                }
            }

            session(['last_activity' => time()]);
        }

        return $next($request);
    }
}
