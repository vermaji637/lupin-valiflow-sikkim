<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->unique(
                ['protocol_type_id', 'equipment_id', 'facility_id'],
                'documents_unique_protocol_equipment_facility'
            );
        });
    }

    public function down()
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropUnique('documents_unique_protocol_equipment_facility');
        });
    }
};

