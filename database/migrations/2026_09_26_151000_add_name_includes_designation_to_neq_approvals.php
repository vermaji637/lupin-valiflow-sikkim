<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('new_equipment_qualification_approvals', 'name_includes_designation')) {
            return;
        }

        Schema::table('new_equipment_qualification_approvals', function (Blueprint $table) {
            $table->boolean('name_includes_designation')->default(false)->after('department_col');
        });
    }

    public function down(): void
    {
        Schema::table('new_equipment_qualification_approvals', function (Blueprint $table) {
            if (Schema::hasColumn('new_equipment_qualification_approvals', 'name_includes_designation')) {
                $table->dropColumn('name_includes_designation');
            }
        });
    }
};
