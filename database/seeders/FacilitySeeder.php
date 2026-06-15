<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Facility;

class FacilitySeeder extends Seeder
{
    public function run(): void
    {
        $facilities = ['Lab', 'ICU', 'Pharmacy', 'Radiology', 'Operation Theater'];
        foreach($facilities as $f){
            Facility::create(['facility_name' => $f]);
        }
    }
}
