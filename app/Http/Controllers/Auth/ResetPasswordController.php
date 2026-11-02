<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Providers\RouteServiceProvider;
use App\Services\PasswordExpiryService;
use App\Services\PasswordHistoryService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Auth\ResetsPasswords;
use Illuminate\Support\Str;

class ResetPasswordController extends Controller
{
    use ResetsPasswords;

    protected $redirectTo = RouteServiceProvider::HOME;

    protected function rules()
    {
        return [
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|confirmed|min:4',
        ];
    }

    protected function resetPassword($user, $password)
    {
        $history = app(PasswordHistoryService::class);
        $history->assertNotReused($user, $password);
        $history->rememberCurrent($user);

        $this->setUserPassword($user, $password);
        $user->setRememberToken(Str::random(60));
        $user->save();

        app(PasswordExpiryService::class)->markPasswordChanged($user);

        event(new PasswordReset($user));

        $this->guard()->login($user);
    }
}
