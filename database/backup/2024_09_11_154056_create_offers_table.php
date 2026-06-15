<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('offers', function (Blueprint $table) {
        $table->id();
        $table->string('offer_title');
        $table->string('offer_number')->unique();
        $table->string('company');
        $table->string('location');
        $table->text('bss')->nullable();
        $table->text('notes')->nullable();
        $table->date('offer_date');
        $table->timestamps();
    });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('offers');
    }
};
