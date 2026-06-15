<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\LogsActivity;

class ProtocolType extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'protocol_type_master';

    protected $fillable = [
        'protocol_type_name'
    ];
}
