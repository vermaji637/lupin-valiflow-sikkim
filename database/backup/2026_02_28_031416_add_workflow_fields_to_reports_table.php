<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            if (!Schema::hasColumn('reports', 'stage')) {
                $table->string('stage')->default('pre'); // pre | post
            }
            if (!Schema::hasColumn('reports', 'status')) {
                $table->string('status')->default('pre_pending');
            }
            if (!Schema::hasColumn('reports', 'current_step_order')) {
                $table->unsignedInteger('current_step_order')->default(1);
            }
            if (!Schema::hasColumn('reports', 'rendered_html')) {
                $table->longText('rendered_html')->nullable();
            }
            if (!Schema::hasColumn('reports', 'templatedata')) {
                $table->longText('templatedata')->nullable();
            }
            if (!Schema::hasColumn('reports', 'attachments')) {
                $table->longText('attachments')->nullable();
            }
            if (!Schema::hasColumn('reports', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn(['stage','status','current_step_order','rendered_html','templatedata','attachments','created_by']);
        });
    }
};
