<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SecurityPolicy;
use Illuminate\Http\Request;

class SecurityPolicyController extends Controller
{
    public function index()
    {
        $policy = SecurityPolicy::first();

        return view('admin.security-policy', compact('policy'));
    }

    public function update(Request $request)
    {
        $request->validate([
            'password_min_length' => 'required|integer|min:4|max:100',
            'password_max_length' => 'required|integer|min:4|max:100',
            'auto_logout_minutes' => 'required|integer|min:1|max:1440',
            'password_expiry_days' => 'nullable|integer|min:1|max:3650',
            'max_failed_login_attempts' => 'required|integer|min:1|max:50',
            'lockout_minutes' => 'required|integer|min:1|max:1440',
            'password_history_count' => 'required|integer|min:1|max:20',
        ]);

        if ((int) $request->password_min_length > (int) $request->password_max_length) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Minimum password length cannot be greater than maximum password length.');
        }

        $policy = SecurityPolicy::first() ?: new SecurityPolicy();

        $policy->password_min_length = (int) $request->password_min_length;
        $policy->password_max_length = (int) $request->password_max_length;
        $policy->require_alphanumeric = $request->has('require_alphanumeric') ? 1 : 0;
        $policy->require_special_char = $request->has('require_special_char') ? 1 : 0;
        $policy->auto_logout_minutes = (int) $request->auto_logout_minutes;
        $policy->esignature_enabled = $request->has('esignature_enabled') ? 1 : 0;
        $policy->password_expiry_enabled = $request->has('password_expiry_enabled') ? 1 : 0;
        $policy->password_expiry_days = (int) ($request->password_expiry_days ?: 45);
        $policy->login_lockout_enabled = $request->has('login_lockout_enabled') ? 1 : 0;
        $policy->max_failed_login_attempts = (int) $request->max_failed_login_attempts;
        $policy->lockout_minutes = (int) $request->lockout_minutes;
        $policy->lockout_notify_email = $request->has('lockout_notify_email') ? 1 : 0;
        $policy->lockout_notify_sms = $request->has('lockout_notify_sms') ? 1 : 0;
        $policy->password_history_enabled = $request->has('password_history_enabled') ? 1 : 0;
        $policy->password_history_count = (int) $request->password_history_count;

        $policy->save();

        return redirect()->route('admin.security-policy')
            ->with('success', 'Settings saved successfully.');
    }
}
