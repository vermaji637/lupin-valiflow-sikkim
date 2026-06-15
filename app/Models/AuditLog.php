<?php

// app/Models/AuditLog.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $table = 'audit_logs';

    protected $fillable = [
        'user_id',
        'event',
        'auditable_type',
        'auditable_id',
        'old_values',
        'new_values',
        'url',
        'ip_address',
        'user_agent',
        'login_at',
        'logout_at',
        'session_urls',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'login_at' => 'datetime',
        'logout_at' => 'datetime',
        'session_urls' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
