<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Equipment extends Model
{
    use LogsActivity;

    protected $fillable = [
        'equipment_name',
        'equipment_id',
        'facility',
        'approver_username',
        'created_by',
        'approval_status',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
    ];

    public function approverUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_username');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
