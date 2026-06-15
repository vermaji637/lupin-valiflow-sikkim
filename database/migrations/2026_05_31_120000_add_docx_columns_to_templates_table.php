<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            if (!Schema::hasColumn('templates', 'docx_template_path')) {
                $table->string('docx_template_path')->nullable()->after('body_html');
            }

            if (!Schema::hasColumn('templates', 'docx_placeholders')) {
                $table->longText('docx_placeholders')->nullable()->after('docx_template_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            if (Schema::hasColumn('templates', 'docx_placeholders')) {
                $table->dropColumn('docx_placeholders');
            }

            if (Schema::hasColumn('templates', 'docx_template_path')) {
                $table->dropColumn('docx_template_path');
            }
        });
    }
};

