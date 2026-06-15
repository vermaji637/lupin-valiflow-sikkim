<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\LogsActivity;
class Customer extends Model
{
    use HasFactory;
    use LogsActivity;
	 protected $fillable = ['name', 'email', 'phone_number', 'company']; // Add 'company' here
}
