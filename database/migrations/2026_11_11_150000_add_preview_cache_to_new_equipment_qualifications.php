<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('new_equipment_qualifications')) {
            return;
        }

        Schema::table('new_equipment_qualifications', function (Blueprint $table) {
            if (! Schema::hasColumn('new_equipment_qualifications', 'preview_html')) {
                $table->longText('preview_html')->nullable();
            }
            if (! Schema::hasColumn('new_equipment_qualifications', 'preview_fields')) {
                $table->json('preview_fields')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('new_equipment_qualifications')) {
            return;
        }

        Schema::table('new_equipment_qualifications', function (Blueprint $table) {
            $drop = [];
            if (Schema::hasColumn('new_equipment_qualifications', 'preview_html')) {
                $drop[] = 'preview_html';
            }
            if (Schema::hasColumn('new_equipment_qualifications', 'preview_fields')) {
                $drop[] = 'preview_fields';
            }
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};

