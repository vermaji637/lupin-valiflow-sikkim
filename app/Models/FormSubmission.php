<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\LogsActivity;

class FormSubmission extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'form_template_id',
        'user_id',
        'status',
        'values_json',
    ];

    protected $casts = [
        'values_json' => 'array',
    ];
}
