<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();

            // Foreign key columns
            $table->unsignedBigInteger('protocol_type_id');
            $table->unsignedBigInteger('equipment_id');
            $table->unsignedBigInteger('facility_id');

            $table->string('document_file')->nullable();
            $table->timestamps();
        });

        // Add foreign keys separately (SQL Server safe)
        Schema::table('documents', function (Blueprint $table) {
            $table->foreign('protocol_type_id')
                  ->references('id')
                  ->on('protocol_type_master')
                  ->onDelete('cascade');

            $table->foreign('equipment_id')
                  ->references('id')
                  ->on('equipment')
                  ->onDelete('cascade');

            $table->foreign('facility_id')
                  ->references('id')
                  ->on('facility_master')
                  ->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['protocol_type_id']);
            $table->dropForeign(['equipment_id']);
            $table->dropForeign(['facility_id']);
        });

        Schema::dropIfExists('documents');
    }
};
