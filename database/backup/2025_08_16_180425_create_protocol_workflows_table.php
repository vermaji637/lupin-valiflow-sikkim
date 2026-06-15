<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('protocol_workflows', function (Blueprint $table) {
            $table->id();

            // ✅ Allowed: single cascade path
            $table->foreignId('protocol_id')
                  ->constrained('protocols')
                  ->cascadeOnDelete();

            // ❌ Removed cascade (SQL Server safe)
            $table->foreignId('user_id')
                  ->constrained('users')
                  ->onDelete('no action');

            $table->enum('step', ['review', 'check', 'validation']);
            $table->unsignedTinyInteger('sequence');
            $table->enum('status', ['pending', 'completed'])->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('protocol_workflows');
    }
};
