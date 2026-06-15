<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->string('facility_section')->nullable()->after('template_id');
            $table->string('document_no')->nullable()->after('facility_section');
            $table->string('version')->nullable()->after('document_no');
            $table->string('equipment_name')->nullable()->after('version');
            $table->string('equipment_id')->nullable()->after('equipment_name');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn([
                'facility_section',
                'document_no',
                'version',
                'equipment_name',
                'equipment_id',
            ]);
        });
    }
};
