<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Drop unused legacy tables that are empty and not required by the current UI.
 * Child tables are dropped before parents to avoid foreign-key conflicts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('offer_items');
        Schema::dropIfExists('offers');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('form_submissions');
        Schema::dropIfExists('form_templates');
        Schema::dropIfExists('ui_settings');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('protocol_workflows');
        Schema::dropIfExists('protocols');
    }

    public function down(): void
    {
        // Intentionally empty: these legacy tables are not recreated.
    }
};
