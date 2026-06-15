<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\LogsActivity;

class ProtocolWorkflow extends Model
{
    protected $fillable = ['protocol_id','user_id','step','sequence','status'];
     use LogsActivity;

    public function protocol(): BelongsTo {
        return $this->belongsTo(Protocol::class);
    }

    public function user(): BelongsTo {
        return $this->belongsTo(User::class);
    }

    // Scope to show tasks only if earlier steps are done
    public function scopeActionableFor($query, int $userId) {
        return $query->where('user_id', $userId)
            ->where('status', 'pending')
            ->whereNotExists(function ($sub) {
                $sub->selectRaw(1)
                    ->from('protocol_workflows as pw2')
                    ->whereColumn('pw2.protocol_id', 'protocol_workflows.protocol_id')
                    ->whereColumn('pw2.sequence', '<', 'protocol_workflows.sequence')
                    ->where('pw2.status', 'pending');
            });
    }
}

