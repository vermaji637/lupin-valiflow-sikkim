<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SecurityPolicySeeder extends Seeder
{
    public function run()
    {
        DB::table('security_policies')->insert([
            'password_min_length' => 8,
            'password_max_length' => 50,
            'require_alphanumeric' => 1,
            'require_special_char' => 1,
            'auto_logout_minutes' => 30,
            'esignature_enabled' => 0,
        ]);
    }
}

