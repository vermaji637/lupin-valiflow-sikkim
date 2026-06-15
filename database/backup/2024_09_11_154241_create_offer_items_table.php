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
        Schema::create('offer_items', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('offer_id'); // Foreign key to offers table
        $table->string('description');
        $table->string('make');
        $table->integer('qty');
        $table->decimal('rate', 10, 2);
        $table->decimal('amount', 10, 2);
        $table->timestamps();

        $table->foreign('offer_id')->references('id')->on('offers')->onDelete('cascade');
    });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('offer_items');
    }
};
