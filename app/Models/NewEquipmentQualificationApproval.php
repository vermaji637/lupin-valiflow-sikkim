<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewEquipmentQualificationApproval extends Model
{
    protected $fillable = [
        'new_equipment_qualification_id',
        'user_id',
        'role_label',
        'table_index',
        'row_index',
        'name_col',
        'department_col',
        'name_includes_designation',
        'signature_col',
        'date_col',
        'paragraph_index',
        'step_order',
        'approved_at',
        'approved_by',
        'rejected_at',
        'rejected_by',
        'remark',
    ];

    protected $casts = [
        'name_includes_designation' => 'boolean',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function record(): BelongsTo
    {
        return $this->belongsTo(NewEquipmentQualification::class, 'new_equipment_qualification_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isApproved(): bool
    {
        return ! is_null($this->approved_at);
    }

    public function isPending(): bool
    {
        return is_null($this->approved_at)
            && is_null($this->rejected_at)
            && ! empty($this->user_id);
    }
}
