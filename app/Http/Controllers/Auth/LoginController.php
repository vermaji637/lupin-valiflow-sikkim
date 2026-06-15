<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Providers\RouteServiceProvider;
use App\Services\LicenseService;
use App\Services\PermissionAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Support\Facades\Log;

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

    protected function authenticated(Request $request, $user)
    {
        if ((int) ($user->role_id ?? 0) !== 1) {
            return null;
        }

        if (!app(LicenseService::class)->hasValidLicense()) {
            return null;
        }

        try {
            $adminRole = Role::query()->find(1);
            if ($adminRole && !$user->hasRole($adminRole->name)) {
                $user->assignRole($adminRole);
            }

            $results = PermissionAssignmentService::syncAllPermissionsToAdmin();

            if (!empty($results['errors'])) {
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
