<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\Permission\Models\Permission as SpatiePermission;
use App\Traits\LogsActivity;

class Permission extends SpatiePermission
{
    use HasFactory, LogsActivity;

    protected $fillable = ['name'];
}
