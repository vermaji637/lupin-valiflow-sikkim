<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\LogsActivity;
class Company extends Model
{
    use HasFactory;
	use LogsActivity;
	  protected $table = 'companies';
	   protected $fillable = ['name', 'address', 'email', 'phone','city', 'state', 'postal_code', 'country'];
}
