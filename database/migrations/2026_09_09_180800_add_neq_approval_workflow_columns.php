<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('new_equipment_qualifications')
            && ! Schema::hasColumn('new_equipment_qualifications', 'approval_status')
        ) {
            Schema::table('new_equipment_qualifications', function (Blueprint $table) {
                $table->string('approval_status', 32)->nullable()->default('draft');
            });
        }

        if (Schema::hasTable('new_equipment_qualification_approvals')) {
            if (! Schema::hasColumn('new_equipment_qualification_approvals', 'rejected_at')) {
                Schema::table('new_equipment_qualification_approvals', function (Blueprint $table) {
                    $table->timestamp('rejected_at')->nullable();
                });
            }
            if (! Schema::hasColumn('new_equipment_qualification_approvals', 'rejected_by')) {
                Schema::table('new_equipment_qualification_approvals', function (Blueprint $table) {
                    $table->unsignedBigInteger('rejected_by')->nullable();
                });
            }
            if (! Schema::hasColumn('new_equipment_qualification_approvals', 'remark')) {
                Schema::table('new_equipment_qualification_approvals', function (Blueprint $table) {
                    $table->text('remark')->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('new_equipment_qualifications')
            && Schema::hasColumn('new_equipment_qualifications', 'approval_status')
        ) {
            Schema::table('new_equipment_qualifications', function (Blueprint $table) {
                $table->dropColumn('approval_status');
            });
        }

        if (Schema::hasTable('new_equipment_qualification_approvals')) {
            $drop = [];
            if (Schema::hasColumn('new_equipment_qualification_approvals', 'rejected_at')) {
                $drop[] = 'rejected_at';
            }
            if (Schema::hasColumn('new_equipment_qualification_approvals', 'rejected_by')) {
                $drop[] = 'rejected_by';
            }
            if (Schema::hasColumn('new_equipment_qualification_approvals', 'remark')) {
                $drop[] = 'remark';
            }
            if ($drop !== []) {
                Schema::table('new_equipment_qualification_approvals', function (Blueprint $table) use ($drop) {
                    $table->dropColumn($drop);
                });
            }
        }
    }
};
