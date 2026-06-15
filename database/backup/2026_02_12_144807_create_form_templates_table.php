<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_templates', function (Blueprint $table) {
            $table->id();
            $table->string('form_key')->unique();   // example: ahu_requalification
            $table->longText('schema_json');        // store full template JSON
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_templates');
    }
};
