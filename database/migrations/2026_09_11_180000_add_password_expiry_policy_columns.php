<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('security_policies')) {
            Schema::table('security_policies', function (Blueprint $table) {
                if (! Schema::hasColumn('security_policies', 'password_expiry_enabled')) {
                    $table->boolean('password_expiry_enabled')->default(false)->after('esignature_enabled');
                }
                if (! Schema::hasColumn('security_policies', 'password_expiry_days')) {
                    $table->unsignedInteger('password_expiry_days')->default(45)->after('password_expiry_enabled');
                }
            });
        }

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (! Schema::hasColumn('users', 'password_changed_at')) {
                    $table->timestamp('password_changed_at')->nullable()->after('password');
                }
                if (! Schema::hasColumn('users', 'password_expiry_reminders_sent')) {
                    $table->text('password_expiry_reminders_sent')->nullable()->after('password_changed_at');
                }
            });

            // Backfill so existing users are not instantly expired when policy is enabled.
            DB::table('users')
                ->whereNull('password_changed_at')
                ->update([
                    'password_changed_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('security_policies')) {
            Schema::table('security_policies', function (Blueprint $table) {
                if (Schema::hasColumn('security_policies', 'password_expiry_days')) {
                    $table->dropColumn('password_expiry_days');
                }
                if (Schema::hasColumn('security_policies', 'password_expiry_enabled')) {
                    $table->dropColumn('password_expiry_enabled');
                }
            });
        }

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (Schema::hasColumn('users', 'password_expiry_reminders_sent')) {
                    $table->dropColumn('password_expiry_reminders_sent');
                }
                if (Schema::hasColumn('users', 'password_changed_at')) {
                    $table->dropColumn('password_changed_at');
                }
            });
        }
    }
};
