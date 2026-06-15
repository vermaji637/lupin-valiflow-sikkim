<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\LogsActivity;

class Offer extends Model
{
    use HasFactory, LogsActivity;
	
	 protected $fillable = ['facility_section', 'document_no', 'company', 'version', 'equipment_name', 'equipment_id', 'offer_date','protocol'];

    public function items()
    {
        return $this->hasMany(OfferItem::class);
    }
	 protected $casts = [
        'offer_date' => 'date', // Cast offer_date as a date
    ];
}
