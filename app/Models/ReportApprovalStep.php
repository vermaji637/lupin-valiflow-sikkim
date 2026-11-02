<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\LogsActivity;

// One row per approver in a report's PRE or POST approval chain.
class ReportApprovalStep extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'report_approval_steps';

    protected $fillable = [
        'report_id',   // parent report
        'user_id',     // assigned approver
        'label',       // step role label (e.g. ENGINEERING, POST_PREPARED)
        'step_order',  // sequence within stage
        'approved_at',
        'approved_by',
        'rejected_at',
        'rejected_by',
        'remark',      // rejection reason when send-back occurs
        'stage',       // pre or post
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

    // Parent protocol report this step belongs to
    public function report()
    {
        return $this->belongsTo(Report::class);
    }

    // User who must approve at this step
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // User who recorded the approval (may differ from assignee after reassignment)
    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // User who rejected and sent the report back
    public function rejectedBy()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    // True when this step has an approval timestamp
    public function isApproved(): bool
    {
        return !is_null($this->approved_at);
    }

    // True when this step was rejected with a remark
    public function isRejected(): bool
    {
        return !is_null($this->rejected_at);
    }

    // True when neither approved nor rejected yet
    public function isPending(): bool
    {
        return is_null($this->approved_at) && is_null($this->rejected_at);
    }
}
