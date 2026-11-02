<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use App\Services\LicenseService;
use App\Services\LoginLockoutService;
use App\Services\PasswordExpiryService;
use App\Services\PermissionAssignmentService;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Login Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles authenticating users for the application and
    | redirecting them to your home screen. The controller uses a trait
    | to conveniently provide its functionality to your applications.
    |
    */

    use AuthenticatesUsers;

    /**
     * Where to redirect users after login.
     *
     * @var string
     */
    protected $redirectTo = RouteServiceProvider::HOME;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('guest')->except('logout');
    }

    /**
     * Handle a login request with account lockout support.
     */
    public function login(Request $request)
    {
        $this->validateLogin($request);

        $lockout = app(LoginLockoutService::class);
        $user = User::query()
            ->where($this->username(), $request->input($this->username()))
            ->first();

        if ($user && $lockout->isLocked($user)) {
            throw ValidationException::withMessages([
                $this->username() => [$lockout->lockedMessage($user)],
            ]);
        }

        if (method_exists($this, 'hasTooManyLoginAttempts') &&
            $this->hasTooManyLoginAttempts($request)) {
            $this->fireLockoutEvent($request);

            return $this->sendLockoutResponse($request);
        }

        if ($this->attemptLogin($request)) {
            if ($request->hasSession()) {
                $request->session()->put('auth.password_confirmed_at', time());
            }

            return $this->sendLoginResponse($request);
        }

        if ($user) {
            $newlyLocked = $lockout->recordFailedAttempt($user);
            if ($newlyLocked) {
                throw ValidationException::withMessages([
                    $this->username() => [$lockout->lockedMessage($user->fresh())],
                ]);
            }
        }

        $this->incrementLoginAttempts($request);

        return $this->sendFailedLoginResponse($request);
    }

    protected function authenticated(Request $request, $user)
    {
        app(LoginLockoutService::class)->clearFailures($user);

        $expiry = app(PasswordExpiryService::class);
        if ($expiry->isExpired($user)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->with('error', 'Your password has expired. Please use Forgot Password to set a new password.');
        }

        if ((int) ($user->role_id ?? 0) !== 1) {
            return null;
        }

        if (! app(LicenseService::class)->hasValidLicense()) {
            return null;
        }

        try {
            $adminRole = Role::query()->find(1);
            if ($adminRole && ! $user->hasRole($adminRole->name)) {
                $user->assignRole($adminRole);
            }

            $results = PermissionAssignmentService::syncAllPermissionsToAdmin();

            if (! empty($results['errors'])) {
                Log::warning('Admin permission auto-sync completed with errors.', [
                    'user_id' => $user->id,
                    'errors' => $results['errors'],
                ]);
            }
        } catch (\Throwable $exception) {
            Log::error('Failed to auto-assign all permissions to admin on login.', [
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return null;
    }
}
