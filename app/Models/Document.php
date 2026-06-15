<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\LogsActivity;

class Document extends Model
{
    use HasFactory, LogsActivity;

     protected $fillable = [
        'title',
        'doc_title',
        'protocol_type_id',
        'equipment_id',
        'facility_id',
        'created_by',
        'qa_user_id',
        'status',
         'equipment_name_id',
        'equipment_code_id',
    ];

    public function protocolType()
    {
        return $this->belongsTo(ProtocolType::class);
    }

    public function equipment()
    {
        return $this->belongsTo(Equipment::class);
    }

    public function facility()
    {
        return $this->belongsTo(Facility::class);
    }
}
