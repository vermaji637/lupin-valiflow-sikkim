<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('report_approval_steps', function (Blueprint $table) {
            if (!Schema::hasColumn('report_approval_steps', 'stage')) {
                $table->string('stage')->default('pre'); // pre | post
            }
            if (!Schema::hasColumn('report_approval_steps', 'approved_by')) {
                $table->unsignedBigInteger('approved_by')->nullable();
            }
            if (!Schema::hasColumn('report_approval_steps', 'rejected_by')) {
                $table->unsignedBigInteger('rejected_by')->nullable();
            }
            if (!Schema::hasColumn('report_approval_steps', 'remark')) {
                $table->text('remark')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('report_approval_steps', function (Blueprint $table) {
            $table->dropColumn(['stage','approved_by','rejected_by','remark']);
        });
    }
};