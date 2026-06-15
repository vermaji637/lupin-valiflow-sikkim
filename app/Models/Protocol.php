<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\LogsActivity;

class Protocol extends Model
{   use LogsActivity;
    protected $fillable = ['owner_id','title','description','status'];

    public function workflows(): HasMany {
        return $this->hasMany(ProtocolWorkflow::class)->orderBy('sequence');
    }

    public function currentStep() {
        return $this->workflows()->where('status','pending')->orderBy('sequence')->first();
    }
}

