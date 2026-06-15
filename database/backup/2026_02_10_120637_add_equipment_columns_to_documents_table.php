<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedBigInteger('equipment_name_id')->nullable()->after('equipment_id');
            $table->unsignedBigInteger('equipment_code_id')->nullable()->after('equipment_name_id');
        });
    }

    public function down()
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['equipment_name_id', 'equipment_code_id']);
        });
    }
};

