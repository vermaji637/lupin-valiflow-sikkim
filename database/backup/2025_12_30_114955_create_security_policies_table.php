<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('security_policies', function (Blueprint $table) {
            $table->id();

            // Password policy
            $table->integer('password_min_length')->default(8);
            $table->integer('password_max_length')->default(50);
            $table->boolean('require_alphanumeric')->default(true);
            $table->boolean('require_special_char')->default(true);

            // Session policy
            $table->integer('auto_logout_minutes')->default(30);

            // E-Signature
            $table->boolean('esignature_enabled')->default(false);

            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('security_policies');
    }
};

