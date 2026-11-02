<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\LogsActivity;

class SecurityPolicy extends Model
{
    use LogsActivity;

    protected $fillable = [
        'password_min_length',
        'password_max_length',
        'require_alphanumeric',
        'require_special_char',
        'auto_logout_minutes',
        'esignature_enabled',
        'password_expiry_enabled',
        'password_expiry_days',
        'login_lockout_enabled',
        'max_failed_login_attempts',
        'lockout_minutes',
        'lockout_notify_email',
        'lockout_notify_sms',
        'password_history_enabled',
        'password_history_count',
    ];

    protected $casts = [
        'require_alphanumeric' => 'boolean',
        'require_special_char' => 'boolean',
        'esignature_enabled' => 'boolean',
        'password_expiry_enabled' => 'boolean',
        'password_expiry_days' => 'integer',
        'password_min_length' => 'integer',
        'password_max_length' => 'integer',
        'auto_logout_minutes' => 'integer',
        'login_lockout_enabled' => 'boolean',
        'max_failed_login_attempts' => 'integer',
        'lockout_minutes' => 'integer',
        'lockout_notify_email' => 'boolean',
        'lockout_notify_sms' => 'boolean',
        'password_history_enabled' => 'boolean',
        'password_history_count' => 'integer',
    ];
}
