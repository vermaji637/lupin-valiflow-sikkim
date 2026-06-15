<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\LogsActivity;

class OfferItem extends Model
{
    use HasFactory, LogsActivity;
	
	protected $fillable = ['offer_id', 'description', 'make', 'qty', 'rate', 'amount'];

    public function offer()
    {
        return $this->belongsTo(Offer::class);
    }
}
