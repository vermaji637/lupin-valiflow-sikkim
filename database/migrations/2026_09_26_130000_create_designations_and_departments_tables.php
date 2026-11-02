<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('designations', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('designation_id')->nullable()->after('designation')->constrained('designations')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->after('designation_id')->constrained('departments')->nullOnDelete();
        });

        $now = now();
        $designationNames = DB::table('users')
            ->whereNotNull('designation')
            ->where('designation', '!=', '')
            ->distinct()
            ->pluck('designation');

        foreach ($designationNames as $name) {
            $id = DB::table('designations')->insertGetId([
                'name' => $name,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('users')->where('designation', $name)->update(['designation_id' => $id]);
        }

        foreach (['Production', 'Engineering', 'Validation', 'Quality Assurance', 'Quality Control'] as $name) {
            DB::table('departments')->insert([
                'name' => $name,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('designation_id');
            $table->dropConstrainedForeignId('department_id');
        });

        Schema::dropIfExists('designations');
        Schema::dropIfExists('departments');
    }
};
