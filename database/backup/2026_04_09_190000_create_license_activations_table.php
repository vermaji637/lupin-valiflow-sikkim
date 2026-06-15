<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('license_activations', function (Blueprint $table) {
            $table->id();
            $table->string('license_key');
            $table->longText('activation_token');
            $table->string('machine_fingerprint', 64);
            $table->text('customer_name')->nullable();
            $table->string('status')->default('active');
            $table->unsignedBigInteger('activated_by')->nullable();
            $table->json('machine_profile')->nullable();
            $table->json('license_payload')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('last_validated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('license_activations');
    }
};
