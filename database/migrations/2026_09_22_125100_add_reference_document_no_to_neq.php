<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persist letterhead "Reference Document No. (Version No.)" for list + notifications.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('new_equipment_qualifications')) {
            return;
        }
        if (! Schema::hasColumn('new_equipment_qualifications', 'reference_document_no')) {
            Schema::table('new_equipment_qualifications', function (Blueprint $table) {
                $table->string('reference_document_no', 255)->nullable();
                $table->index('reference_document_no');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('new_equipment_qualifications')
            && Schema::hasColumn('new_equipment_qualifications', 'reference_document_no')) {
            Schema::table('new_equipment_qualifications', function (Blueprint $table) {
                $table->dropIndex(['reference_document_no']);
                $table->dropColumn('reference_document_no');
            });
        }
    }
};
