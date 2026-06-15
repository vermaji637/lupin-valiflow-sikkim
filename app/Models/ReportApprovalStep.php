<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\LogsActivity;

class ReportApprovalStep extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'report_approval_steps';

    protected $fillable = [
        'report_id',
        'user_id',
        'label',
        'step_order',
        'approved_at',
        'approved_by',
        'rejected_at',
        'rejected_by',
        'remark',
        'stage',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    // Belongs to Report
    public function report()
    {
        return $this->belongsTo(Report::class);
    }

    // Assigned approver user
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // Who approved
    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // Who rejected
    public function rejectedBy()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    public function isApproved(): bool
    {
        return !is_null($this->approved_at);
    }

    public function isRejected(): bool
    {
        return !is_null($this->rejected_at);
    }

    public function isPending(): bool
    {
        return is_null($this->approved_at) && is_null($this->rejected_at);
    }
}
