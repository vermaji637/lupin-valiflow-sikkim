<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('new_equipment_qualifications', function (Blueprint $table) {
            $table->id();
            $table->string('original_filename');
            $table->string('stored_path');
            $table->unsignedBigInteger('uploaded_by');
            $table->timestamps();

            $table->index('uploaded_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('new_equipment_qualifications');
    }
};
