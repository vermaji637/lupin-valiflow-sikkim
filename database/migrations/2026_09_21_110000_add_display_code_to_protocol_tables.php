<?php

use App\Models\NewEquipmentQualification;
use App\Models\Report;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Persist UI protocol labels (NEQ1, REV2, REQ3) without changing numeric PKs / FKs.
 * SQL Server: filtered unique indexes (multiple NULLs allowed).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('reports') && ! Schema::hasColumn('reports', 'display_code')) {
            Schema::table('reports', function (Blueprint $table) {
                $table->unsignedInteger('display_seq')->nullable();
                $table->string('display_code', 32)->nullable();
                $table->index('display_seq');
            });
            $this->addFilteredUnique('reports', 'display_code', 'reports_display_code_unique');
        }

        if (Schema::hasTable('new_equipment_qualifications')
            && ! Schema::hasColumn('new_equipment_qualifications', 'display_code')) {
            Schema::table('new_equipment_qualifications', function (Blueprint $table) {
                $table->unsignedInteger('display_seq')->nullable();
                $table->string('display_code', 32)->nullable();
                $table->index('display_seq');
            });
            $this->addFilteredUnique(
                'new_equipment_qualifications',
                'display_code',
                'neq_display_code_unique'
            );
        }

        $this->backfillReports();
        $this->backfillNeq();
    }

    public function down(): void
    {
        $this->dropFilteredUnique('reports', 'reports_display_code_unique', 'display_code');
        $this->dropFilteredUnique('new_equipment_qualifications', 'neq_display_code_unique', 'display_code');

        if (Schema::hasTable('reports') && Schema::hasColumn('reports', 'display_code')) {
            Schema::table('reports', function (Blueprint $table) {
                $table->dropIndex(['display_seq']);
                $table->dropColumn(['display_code', 'display_seq']);
            });
        }

        if (Schema::hasTable('new_equipment_qualifications')
            && Schema::hasColumn('new_equipment_qualifications', 'display_code')) {
            Schema::table('new_equipment_qualifications', function (Blueprint $table) {
                $table->dropIndex(['display_seq']);
                $table->dropColumn(['display_code', 'display_seq']);
            });
        }
    }

    private function addFilteredUnique(string $table, string $column, string $indexName): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlsrv') {
            DB::statement(
                "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'{$indexName}' AND object_id = OBJECT_ID(N'{$table}'))
                 CREATE UNIQUE INDEX [{$indexName}] ON [{$table}] ([{$column}]) WHERE [{$column}] IS NOT NULL"
            );

            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column) {
            $blueprint->unique($column);
        });
    }

    private function dropFilteredUnique(string $table, string $indexName, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlsrv') {
            DB::statement(
                "IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'{$indexName}' AND object_id = OBJECT_ID(N'{$table}'))
                 DROP INDEX [{$indexName}] ON [{$table}]"
            );

            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column) {
            $blueprint->dropUnique([$column]);
        });
    }

    private function backfillReports(): void
    {
        if (! Schema::hasColumn('reports', 'display_code')) {
            return;
        }

        $seqBySource = [];
        Report::query()
            ->orderBy('id')
            ->get(['id', 'data', 'display_code', 'display_seq'])
            ->each(function (Report $report) use (&$seqBySource) {
                if (! empty($report->display_code) && (int) $report->display_seq > 0) {
                    return;
                }

                $prefix = Report::importSourceDisplayPrefix($report->import_source);
                if ($prefix === '') {
                    return;
                }

                $source = strtolower(trim((string) $report->import_source));
                $seqBySource[$source] = (int) ($seqBySource[$source] ?? 0) + 1;
                $seq = $seqBySource[$source];

                Report::query()->whereKey($report->id)->update([
                    'display_seq' => $seq,
                    'display_code' => $prefix . $seq,
                ]);
            });
    }

    private function backfillNeq(): void
    {
        if (! Schema::hasColumn('new_equipment_qualifications', 'display_code')) {
            return;
        }

        $seq = 0;
        NewEquipmentQualification::query()
            ->where(function ($query) {
                $query->whereNull('source_id')->orWhere('source_id', 0);
            })
            ->orderBy('id')
            ->get(['id', 'display_code', 'display_seq'])
            ->each(function (NewEquipmentQualification $row) use (&$seq) {
                if (! empty($row->display_code) && (int) $row->display_seq > 0) {
                    return;
                }

                $seq++;
                NewEquipmentQualification::query()->whereKey($row->id)->update([
                    'display_seq' => $seq,
                    'display_code' => 'NEQ' . $seq,
                ]);
            });
    }
};
