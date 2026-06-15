<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            if (!Schema::hasColumn('reports', 'docx_import_path')) {
                $table->string('docx_import_path')->nullable();
            }

            if (!Schema::hasColumn('reports', 'docx_import_fields')) {
                $table->json('docx_import_fields')->nullable();
            }

            if (!Schema::hasColumn('reports', 'docx_filled_path')) {
                $table->string('docx_filled_path')->nullable();
            }

            if (!Schema::hasColumn('reports', 'docx_import_status')) {
                $table->string('docx_import_status')->nullable()->default('pending');
            }
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $columns = ['docx_import_path', 'docx_import_fields', 'docx_filled_path', 'docx_import_status'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('reports', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
