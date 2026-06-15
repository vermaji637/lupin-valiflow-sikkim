<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    protected $fillable = [
        'user_id',
        'event',
        'description',
    ];

    // 👇 Add this
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
