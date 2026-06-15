<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Facility extends Model
{
    use HasFactory;

    // Explicitly set the table name
    protected $table = 'facility_master';

    protected $fillable = [
        'facility_name'
    ];
}
