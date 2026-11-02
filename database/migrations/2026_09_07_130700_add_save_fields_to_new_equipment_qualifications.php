<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('new_equipment_qualifications', function (Blueprint $table) {
            if (! Schema::hasColumn('new_equipment_qualifications', 'source_id')) {
                $table->unsignedBigInteger('source_id')->nullable()->after('uploaded_by');
            }
            if (! Schema::hasColumn('new_equipment_qualifications', 'applied_count')) {
                $table->unsignedInteger('applied_count')->nullable()->after('source_id');
            }
            if (! Schema::hasColumn('new_equipment_qualifications', 'edits_json')) {
                $table->longText('edits_json')->nullable()->after('applied_count');
            }
        });

        if (! Schema::hasTable('new_equipment_qualification_field_values')) {
            Schema::create('new_equipment_qualification_field_values', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('new_equipment_qualification_id');
                $table->unsignedInteger('field_index');
                $table->string('zone', 20)->nullable();
                $table->longText('original_text')->nullable();
                $table->longText('saved_text')->nullable();
                $table->timestamps();

                $table->index('new_equipment_qualification_id', 'neq_field_values_parent_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('new_equipment_qualification_field_values');

        Schema::table('new_equipment_qualifications', function (Blueprint $table) {
            if (Schema::hasColumn('new_equipment_qualifications', 'edits_json')) {
                $table->dropColumn('edits_json');
            }
            if (Schema::hasColumn('new_equipment_qualifications', 'applied_count')) {
                $table->dropColumn('applied_count');
            }
            if (Schema::hasColumn('new_equipment_qualifications', 'source_id')) {
                $table->dropColumn('source_id');
            }
        });
    }
};
