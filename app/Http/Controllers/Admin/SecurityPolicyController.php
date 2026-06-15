<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SecurityPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

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
    ]);

    if ((int) $request->password_min_length > (int) $request->password_max_length) {
        return redirect()->back()
            ->withInput()
            ->with('error', 'Minimum password length cannot be greater than maximum password length.');
    }

    $policy = SecurityPolicy::first();

    if (!$policy) {
        $policy = new SecurityPolicy();
    }

    $policy->password_min_length = (int) $request->password_min_length;
    $policy->password_max_length = (int) $request->password_max_length;
    $policy->require_alphanumeric = $request->has('require_alphanumeric') ? 1 : 0;
    $policy->require_special_char = $request->has('require_special_char') ? 1 : 0;
    $policy->auto_logout_minutes = (int) $request->auto_logout_minutes;
    $policy->esignature_enabled = $request->has('esignature_enabled') ? 1 : 0;

    $policy->save();

    return redirect()->route('admin.security-policy')
        ->with('success', 'Settings saved successfully.');
}
}
