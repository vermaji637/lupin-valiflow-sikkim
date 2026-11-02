<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('new_equipment_qualification_approvals')) {
            return;
        }

        Schema::create('new_equipment_qualification_approvals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('new_equipment_qualification_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('role_label', 191)->nullable();
            $table->unsignedInteger('table_index')->default(0);
            $table->unsignedInteger('row_index')->default(0);
            $table->unsignedInteger('name_col')->default(1);
            $table->unsignedInteger('signature_col')->default(2);
            $table->unsignedInteger('date_col')->default(3);
            $table->unsignedInteger('step_order')->default(0);
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamps();

            $table->index('new_equipment_qualification_id', 'neq_appr_parent_idx');
            $table->index('user_id', 'neq_appr_user_idx');
            $table->unique(
                ['new_equipment_qualification_id', 'table_index', 'row_index'],
                'neq_appr_row_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('new_equipment_qualification_approvals');
    }
};
