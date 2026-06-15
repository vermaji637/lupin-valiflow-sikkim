<?php

namespace App\Http\Controllers;

use Laravel\Socialite\Facades\Socialite;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use App\Models\ActivityLog;
class GoogleAuthController extends Controller
{
    public function redirectToGoogle()
    {
        return Socialite::driver('google')->redirect();
    }
 

   public function handleGoogleCallback()
{
    try {
        $googleUser = Socialite::driver('google')->stateless()->user();

        // Split full name into first_name and last_name
        $fullName = $googleUser->getName();
        $nameParts = explode(' ', $fullName, 2);
        $firstName = $nameParts[0] ?? '';
        $lastName  = $nameParts[1] ?? '';

        // Check if user already exists
        $user = User::firstOrNew(['email' => $googleUser->getEmail()]);

        // Fill in fields
        $user->first_name = $firstName;
        $user->last_name  = $lastName;
        
        $user->google_id  = $googleUser->getId();
        $user->avatar     = $googleUser->getAvatar();

        // Only set password for new users
        if (!$user->exists) {
            $user->password = bcrypt(str()->random(16));
        }

        $user->save();

        Auth::login($user);
         
         ActivityLog::create([
            'user_id' => Auth::id(),
            'event' => 'login',
            'description' => 'User logged in successfully',
        ]);

        return redirect('/offer');

    } catch (\Exception $e) {
        \Log::error('Google login error: ' . $e->getMessage());
        return redirect('/login')->withErrors('Unable to login using Google.');
    }
}



}
