<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\Permission\Models\Role as SpatieRole;
use App\Traits\LogsActivity;

class Role extends SpatieRole
{
    use HasFactory, LogsActivity;

    protected $fillable = ['name'];
}

