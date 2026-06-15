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
    ];
}
