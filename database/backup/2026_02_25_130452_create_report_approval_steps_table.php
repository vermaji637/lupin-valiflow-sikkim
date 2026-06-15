<?php // php artisan make:migration create_report_approval_steps_table

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('report_approval_steps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('report_id');
            $table->unsignedBigInteger('user_id')->nullable();

            $table->string('label')->nullable(); // ENGINEERING, PRODUCTION, QA etc
            $table->unsignedInteger('step_order')->default(0);

            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable(); // same user generally
            $table->timestamp('rejected_at')->nullable();
            $table->unsignedBigInteger('rejected_by')->nullable();
            $table->text('remark')->nullable();

            $table->timestamps();

            $table->foreign('report_id')->references('id')->on('reports')->onDelete('cascade');
            $table->index(['user_id', 'approved_at', 'rejected_at']);
            $table->index(['report_id', 'step_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_approval_steps');
    }
};