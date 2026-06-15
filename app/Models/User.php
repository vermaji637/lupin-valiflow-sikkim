<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use App\Traits\LogsActivity;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles, LogsActivity;

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'mobile_number',
        'company',
        'role_id',
        'status',
        'password',
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
    ];

    public function getFullNameAttribute()
    {
        return "{$this->first_name} {$this->last_name}";
    }

    public function isAdmin()
    {
        return $this->role_id == 1;
    }

    // ✅ roles() method HATA DIYA — HasRoles trait khud handle karta hai
    // ✅ login() method HATA DIYA — Model mein login logic nahi hona chahiye
}
