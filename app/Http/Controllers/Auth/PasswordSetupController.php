<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PasswordHistoryService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class PasswordSetupController extends Controller
{
    /**
     * Show password setup form
     */
    public function showForm($token)
    {
        $user = DB::table('users')
            ->where('password_setup_token', $token)
            ->whereNull('password_setup_used_at')
            ->where('password_setup_expires_at', '>', Carbon::now())
            ->first();

        if (!$user) {
            //return abort(403, 'Invalid or expired password setup link.');
        }

        return view('auth.passwords.setup-password', compact('token'));
    }

    /**
     * Save new password (ONE TIME ONLY)
     */
    public function setup(Request $request)
    {
        $request->validate([
            'password' => 'required|min:8|confirmed',
        ]);

        $userRow = DB::table('users')
            ->where('password_setup_token', $request->token)
            ->whereNull('password_setup_used_at')
            ->first();

        if (! $userRow) {
            return abort(403, 'Password setup link already used or invalid.');
        }

        $user = User::query()->findOrFail($userRow->id);
        $history = app(PasswordHistoryService::class);
        $history->assertNotReused($user, $request->password);
        $history->rememberCurrent($user);

        DB::table('users')->where('id', $user->id)->update([
            'password' => Hash::make($request->password),
            'password_changed_at' => Carbon::now(),
            'password_expiry_reminders_sent' => json_encode([]),
            'password_setup_used_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        return redirect()->route('login')
            ->with('success', 'Password set successfully. You can now login.');
    }
}
