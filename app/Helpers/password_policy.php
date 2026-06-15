<?php

use App\Models\SecurityPolicy;

if (!function_exists('passwordPolicyRules')) {
    function passwordPolicyRules()
    {
        // Direct DB fetch - no caching
        $policy = SecurityPolicy::first();

        // Fallback safety (important)
        if (!$policy) {
            return ['required', 'string', 'min:8', 'max:50'];
        }

        $rules = [
            'required',
            'string',
            'min:' . $policy->password_min_length,
            'max:' . $policy->password_max_length,
        ];

        if ($policy->require_alphanumeric) {
            $rules[] = 'regex:/^(?=.*[A-Za-z])(?=.*\d).+$/';
        }

        if ($policy->require_special_char) {
            $rules[] = 'regex:/[@$!%*#?&]/';
        }

        return $rules;
    }
}
