<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configurable login lockout: max wrong attempts + auto-unlock minutes + notify flags.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('security_policies')) {
            Schema::table('security_policies', function (Blueprint $table) {
                if (! Schema::hasColumn('security_policies', 'login_lockout_enabled')) {
                    $table->boolean('login_lockout_enabled')->default(true);
                }
                if (! Schema::hasColumn('security_policies', 'max_failed_login_attempts')) {
                    $table->unsignedInteger('max_failed_login_attempts')->default(5);
                }
                if (! Schema::hasColumn('security_policies', 'lockout_minutes')) {
                    $table->unsignedInteger('lockout_minutes')->default(10);
                }
                if (! Schema::hasColumn('security_policies', 'lockout_notify_email')) {
                    $table->boolean('lockout_notify_email')->default(true);
                }
                if (! Schema::hasColumn('security_policies', 'lockout_notify_sms')) {
                    $table->boolean('lockout_notify_sms')->default(true);
                }
            });
        }

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (! Schema::hasColumn('users', 'failed_login_attempts')) {
                    $table->unsignedInteger('failed_login_attempts')->default(0);
                }
                if (! Schema::hasColumn('users', 'locked_until')) {
                    $table->timestamp('locked_until')->nullable();
                }
                if (! Schema::hasColumn('users', 'last_failed_login_at')) {
                    $table->timestamp('last_failed_login_at')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('security_policies')) {
            Schema::table('security_policies', function (Blueprint $table) {
                foreach ([
                    'login_lockout_enabled',
                    'max_failed_login_attempts',
                    'lockout_minutes',
                    'lockout_notify_email',
                    'lockout_notify_sms',
                ] as $column) {
                    if (Schema::hasColumn('security_policies', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                foreach (['failed_login_attempts', 'locked_until', 'last_failed_login_at'] as $column) {
                    if (Schema::hasColumn('users', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
