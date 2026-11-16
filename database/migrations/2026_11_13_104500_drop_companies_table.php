<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Drop legacy companies table (Offer/Customer/Company UI removed).
 * offers/customers already dropped in 2026_09_22_174500_drop_unused_legacy_tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('companies');
    }

    public function down(): void
    {
        // Intentionally empty: legacy companies table is not recreated.
    }
};
