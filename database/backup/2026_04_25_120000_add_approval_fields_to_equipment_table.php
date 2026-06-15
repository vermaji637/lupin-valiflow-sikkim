<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('equipment')) {
            return;
        }

        Schema::table('equipment', function (Blueprint $table) {
            if (!Schema::hasColumn('equipment', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable()->after('approver_username');
            }

            if (!Schema::hasColumn('equipment', 'approval_status')) {
                $table->string('approval_status')->default('pending')->after('created_by');
            }

            if (!Schema::hasColumn('equipment', 'approved_by')) {
                $table->unsignedBigInteger('approved_by')->nullable()->after('approval_status');
            }

            if (!Schema::hasColumn('equipment', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approved_by');
            }
        });

        if (Schema::hasColumn('equipment', 'approval_status')) {
            DB::table('equipment')
                ->whereNull('approval_status')
                ->update(['approval_status' => 'pending']);
        }
    }

    public function down()
    {
        if (!Schema::hasTable('equipment')) {
            return;
        }

        Schema::table('equipment', function (Blueprint $table) {
            if (Schema::hasColumn('equipment', 'approved_at')) {
                $table->dropColumn('approved_at');
            }

            if (Schema::hasColumn('equipment', 'approved_by')) {
                $table->dropColumn('approved_by');
            }

            if (Schema::hasColumn('equipment', 'approval_status')) {
                $table->dropColumn('approval_status');
            }

            if (Schema::hasColumn('equipment', 'created_by')) {
                $table->dropColumn('created_by');
            }
        });
    }
};
