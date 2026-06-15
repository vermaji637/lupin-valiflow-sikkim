<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('documents', function (Blueprint $table) {
           // $table->unsignedBigInteger('created_by')->after('facility_id')->nullable();
          //  $table->enum('status', ['pending', 'completed'])->default('pending')->after('created_by');
            $table->unsignedBigInteger('qa_user_id')->after('status')->nullable();
        });
    }

    public function down()
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn([ 'qa_user_id']);
        });
    }
};

