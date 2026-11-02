<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use App\Traits\LogsActivity;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles, LogsActivity;

    protected $fillable = [
        'first_name',
        'last_name',
        'designation',
        'designation_id',
        'department_id',
        'department_name',
        'email',
        'mobile_number',
        'company',
        'role_id',
        'status',
        'password',
        'password_changed_at',
        'password_expiry_reminders_sent',
        'password_setup_token',
        'password_setup_token_created_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'password_setup_token',
        'password_setup_token_created_at',
        'password_set',
        'password_setup_used_at',
        'password_setup_expires_at',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password_changed_at' => 'datetime',
        'password_expiry_reminders_sent' => 'array',
        'failed_login_attempts' => 'integer',
        'locked_until' => 'datetime',
        'last_failed_login_at' => 'datetime',
    ];

    public function getFullNameAttribute()
    {
        return "{$this->first_name} {$this->last_name}";
    }

    public function designationRecord(): BelongsTo
    {
        return $this->belongsTo(Designation::class, 'designation_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function isAdmin()
    {
        return $this->role_id == 1;
    }
}
