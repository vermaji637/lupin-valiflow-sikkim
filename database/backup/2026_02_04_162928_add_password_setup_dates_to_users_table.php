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
        Schema::table('users', function (Blueprint $table) {
           $table->timestamp('password_setup_used_at')->nullable()->after('password');
            $table->timestamp('password_setup_expires_at')->nullable()->after('password_setup_used_at');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
         Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'password_setup_used_at',
                'password_setup_expires_at'
            ]);
        });
    }
};
