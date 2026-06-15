<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

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
           // 'token'    => 'required',
            'password' => 'required|min:8|confirmed'
        ]);

        $user = DB::table('users')
            ->where('password_setup_token', $request->token)
            ->whereNull('password_setup_used_at')
            ->first();

     //dd( $user);

        if (!$user) {
            return abort(403, 'Password setup link already used or invalid.');
        }

        DB::table('users')->where('id', $user->id)->update([
            'password'                 => Hash::make($request->password),
            'password_setup_used_at'   => Carbon::now(),
            //'password_setup_token'     => NULL,
            //'password_setup_expires_at'=> NULL,
            'updated_at'               => Carbon::now()
        ]);

        return redirect()->route('login')
            ->with('success', 'Password set successfully. You can now login.');
    }
}
