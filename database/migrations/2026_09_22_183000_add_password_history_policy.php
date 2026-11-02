<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Password history: block reuse of last N passwords (count configured on security policy).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('security_policies')) {
            Schema::table('security_policies', function (Blueprint $table) {
                if (! Schema::hasColumn('security_policies', 'password_history_enabled')) {
                    $table->boolean('password_history_enabled')->default(true);
                }
                if (! Schema::hasColumn('security_policies', 'password_history_count')) {
                    $table->unsignedInteger('password_history_count')->default(5);
                }
            });
        }

        if (! Schema::hasTable('password_histories')) {
            Schema::create('password_histories', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('password');
                $table->timestamps();

                $table->index('user_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('password_histories');

        if (Schema::hasTable('security_policies')) {
            Schema::table('security_policies', function (Blueprint $table) {
                if (Schema::hasColumn('security_policies', 'password_history_count')) {
                    $table->dropColumn('password_history_count');
                }
                if (Schema::hasColumn('security_policies', 'password_history_enabled')) {
                    $table->dropColumn('password_history_enabled');
                }
            });
        }
    }
};
