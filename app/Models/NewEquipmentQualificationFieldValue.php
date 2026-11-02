<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// One typed NEQ preview field saved with a document copy.
class NewEquipmentQualificationFieldValue extends Model
{
    protected $fillable = [
        'new_equipment_qualification_id',
        'field_index',
        'zone',
        'original_text',
        'saved_text',
    ];

    public function qualification(): BelongsTo
    {
        return $this->belongsTo(NewEquipmentQualification::class, 'new_equipment_qualification_id');
    }
}
