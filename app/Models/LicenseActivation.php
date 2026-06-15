<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LicenseActivation extends Model
{
    protected $fillable = [
        'license_key',
        'activation_token',
        'machine_fingerprint',
        'machine_profile',
        'license_payload',
        'customer_name',
        'status',
        'activated_by',
        'activated_at',
        'last_validated_at',
        'expires_at',
    ];

    protected $casts = [
        'machine_profile' => 'array',
        'license_payload' => 'array',
        'activated_at' => 'datetime',
        'last_validated_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
