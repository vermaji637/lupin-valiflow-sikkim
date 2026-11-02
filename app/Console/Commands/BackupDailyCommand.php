<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;
use ZipArchive;

class BackupDailyCommand extends Command
{
    protected $signature = 'backup:daily
                            {--path= : Override backup root path}
                            {--keep= : Override retention days for daily folders}
                            {--skip-files : Skip zipping storage files}
                            {--skip-db : Skip database backup}
                            {--skip-weekly : Skip refreshing the weekly forever archive}';

    protected $description = 'Daily backup under daily/ (prune after N days) + weekly/ forever archive';

    public function handle(): int
    {
        $root = rtrim($this->option('path') ?: config('backup.path'), DIRECTORY_SEPARATOR);
        $retentionDays = (int) ($this->option('keep') ?: config('backup.retention_days', 7));
        $dailySub = trim((string) config('backup.daily_subdir', 'daily'), DIRECTORY_SEPARATOR) ?: 'daily';
        $weeklySub = trim((string) config('backup.weekly_subdir', 'weekly'), DIRECTORY_SEPARATOR) ?: 'weekly';

        $dateFolder = now()->format('Y-m-d');
        $dailyRoot = $root . DIRECTORY_SEPARATOR . $dailySub;
        $weeklyRoot = $root . DIRECTORY_SEPARATOR . $weeklySub;
        $targetDir = $dailyRoot . DIRECTORY_SEPARATOR . $dateFolder;
        $weekKey = $this->currentWeeklyFolderKey();
        $weeklyDir = $weeklyRoot . DIRECTORY_SEPARATOR . $weekKey;

        $this->info("Starting daily backup → {$targetDir}");

        try {
            if (! File::isDirectory($targetDir)) {
                File::makeDirectory($targetDir, 0755, true);
            }
        } catch (Throwable $e) {
            $this->error('Cannot create backup folder: ' . $e->getMessage());

            return self::FAILURE;
        }

        $info = [
            'started_at' => now()->toDateTimeString(),
            'app' => config('app.name'),
            'environment' => config('app.env'),
            'connection' => config('database.default'),
            'database' => config('database.connections.' . config('database.default') . '.database'),
            'target' => $targetDir,
            'weekly_target' => $weeklyDir,
            'steps' => [],
            'errors' => [],
        ];

        $ok = true;

        if (! $this->option('skip-db')) {
            $ok = $this->backupDatabase($targetDir, $info) && $ok;
        } else {
            $info['steps'][] = 'database: skipped';
        }

        if (! $this->option('skip-files')) {
            $ok = $this->backupFiles($targetDir, $info) && $ok;
        } else {
            $info['steps'][] = 'files: skipped';
        }

        $weeklyOk = true;
        if ($ok && ! $this->option('skip-weekly') && config('backup.weekly_enabled', true)) {
            $weeklyOk = $this->archiveWeeklyForever($targetDir, $weeklyDir, $weekKey, $info);
            $ok = $weeklyOk && $ok;
        } elseif ($this->option('skip-weekly') || ! config('backup.weekly_enabled', true)) {
            $info['steps'][] = 'weekly: skipped';
        }

        $pruned = $this->pruneOldDailyBackups($root, $dailyRoot, $retentionDays);
        $info['steps'][] = "retention: removed {$pruned} daily folder(s) older than {$retentionDays} day(s); weekly/ never pruned";
        $info['finished_at'] = now()->toDateTimeString();
        $info['status'] = $ok ? 'success' : 'completed_with_errors';

        File::put(
            $targetDir . DIRECTORY_SEPARATOR . 'backup-info.txt',
            $this->formatInfo($info)
        );

        // Keep a copy of the report inside the weekly folder too when present.
        if (is_dir($weeklyDir)) {
            File::put(
                $weeklyDir . DIRECTORY_SEPARATOR . 'backup-info.txt',
                $this->formatInfo($info)
            );
        }

        if ($ok) {
            $this->info('Daily backup completed successfully.');
            if (is_dir($weeklyDir)) {
                $this->info("Weekly forever archive updated → {$weeklyDir}");
            }

            return self::SUCCESS;
        }

        $this->warn('Daily backup finished with errors. See backup-info.txt in the target folder.');

        return self::FAILURE;
    }

    /**
     * Month week key: W1 (days 1–7), W2 (8–14), W3 (15–21), W4 (22–28), W5 (29–31).
     * Example: 2026-09-W1
     */
    private function currentWeeklyFolderKey(?\Carbon\Carbon $when = null): string
    {
        $when = $when ?: now();
        $weekOfMonth = (int) ceil($when->day / 7);

        return sprintf('%s-W%d', $when->format('Y-m'), max(1, min(5, $weekOfMonth)));
    }

    /**
     * Copy today's daily snapshot into weekly/YYYY-MM-Wn (never auto-deleted).
     * Same month-week folder is refreshed; previous weeks stay forever.
     * Example: 2026-09-W1, 2026-09-W2, 2026-09-W3, 2026-09-W4
     */
    private function archiveWeeklyForever(string $dailyDir, string $weeklyDir, string $weekKey, array &$info): bool
    {
        try {
            if (! File::isDirectory($weeklyDir)) {
                File::makeDirectory($weeklyDir, 0755, true);
            }

            // Refresh week snapshot from today's daily folder (full DB + files).
            $dbSrc = $dailyDir . DIRECTORY_SEPARATOR . 'database';
            $dbDst = $weeklyDir . DIRECTORY_SEPARATOR . 'database';
            if (is_dir($dbSrc)) {
                if (is_dir($dbDst)) {
                    File::deleteDirectory($dbDst);
                }
                File::copyDirectory($dbSrc, $dbDst);
            }

            $filesSrc = $dailyDir . DIRECTORY_SEPARATOR . 'files.zip';
            $filesDst = $weeklyDir . DIRECTORY_SEPARATOR . 'files.zip';
            if (is_file($filesSrc)) {
                File::copy($filesSrc, $filesDst);
            } elseif (is_dir($dailyDir . DIRECTORY_SEPARATOR . 'files')) {
                $filesCopy = $weeklyDir . DIRECTORY_SEPARATOR . 'files';
                if (is_dir($filesCopy)) {
                    File::deleteDirectory($filesCopy);
                }
                File::copyDirectory($dailyDir . DIRECTORY_SEPARATOR . 'files', $filesCopy);
            }

            $bak = $dbDst . DIRECTORY_SEPARATOR . 'database.bak';
            if (is_file($bak) && filesize($bak) > 0) {
                $info['bak_path'] = ($info['bak_path'] ?? null) ?: $bak;
                $info['weekly_bak_path'] = $bak;
            }

            $info['steps'][] = "weekly: forever archive refreshed → weekly/{$weekKey} (not pruned)";
            $this->line("  • weekly forever archive → {$weeklyDir}");

            return true;
        } catch (Throwable $e) {
            $info['errors'][] = 'weekly: ' . $e->getMessage();
            $this->error('Weekly archive failed: ' . $e->getMessage());

            return false;
        }
    }

    private function backupDatabase(string $targetDir, array &$info): bool
    {
        $dbDir = $targetDir . DIRECTORY_SEPARATOR . 'database';
        if (! File::isDirectory($dbDir)) {
            File::makeDirectory($dbDir, 0755, true);
        }

        $driver = config('database.default');
        $connection = config("database.connections.{$driver}", []);

        try {
            if ($driver === 'sqlsrv' && config('backup.use_native_sqlsrv_backup')) {
                $bakPath = $dbDir . DIRECTORY_SEPARATOR . 'database.bak';
                if ($this->backupSqlServerNative($connection, $bakPath, $info)) {
                    $info['steps'][] = 'database: native SQL Server .bak created';

                    return true;
                }

                $this->warn('Native SQL Server backup failed; falling back to JSON table dump.');
            }

            if ($driver === 'mysql') {
                if ($this->backupMysqlDump($connection, $dbDir, $info)) {
                    $info['steps'][] = 'database: mysqldump .sql created';

                    return true;
                }

                $this->warn('mysqldump failed; falling back to JSON table dump.');
            }

            $this->backupTablesAsJson($dbDir, $info);
            $info['steps'][] = 'database: JSON table dump created';

            return true;
        } catch (Throwable $e) {
            $info['errors'][] = 'database: ' . $e->getMessage();
            $this->error('Database backup failed: ' . $e->getMessage());

            return false;
        }
    }

    private function backupSqlServerNative(array $connection, string $finalBakPath, array &$info): bool
    {
        $database = $connection['database'] ?? null;
        if (! $database) {
            $info['errors'][] = 'database: DB_DATABASE is empty';

            return false;
        }

        $finalDisk = str_replace('/', '\\', $finalBakPath);
        $finalDir = dirname($finalDisk);
        if (! is_dir($finalDir)) {
            File::makeDirectory($finalDir, 0755, true);
        }

        $safeDb = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string) $database) ?: 'database';
        $stamp = now()->format('Ymd_His');
        $stagingName = "{$safeDb}_{$stamp}.bak";

        $candidatePaths = [];
        // Prefer final destination first (easy SSMS restore path).
        $candidatePaths[] = $finalDisk;

        $configuredStaging = trim((string) config('backup.sqlsrv_staging_path', ''));
        if ($configuredStaging !== '') {
            $candidatePaths[] = rtrim(str_replace('/', '\\', $configuredStaging), '\\') . '\\' . $stagingName;
        }

        $defaultDir = $this->resolveSqlServerBackupDirectory();
        if ($defaultDir) {
            $candidatePaths[] = rtrim(str_replace('/', '\\', $defaultDir), '\\') . '\\' . $stagingName;
        }

        $candidatePaths = array_values(array_unique(array_filter($candidatePaths)));
        $errors = [];

        foreach ($candidatePaths as $writePath) {
            $this->line("  • trying SQL .bak write → {$writePath}");
            $writeDir = dirname($writePath);
            if (! is_dir($writeDir)) {
                @mkdir($writeDir, 0755, true);
            }

            if (! $this->runSqlServerBackup($connection, $database, $writePath, $errors)) {
                continue;
            }

            clearstatcache(true, $writePath);
            $attempts = 0;
            while ($attempts < 15 && (! is_file($writePath) || filesize($writePath) < 1)) {
                usleep(200000);
                clearstatcache(true, $writePath);
                $attempts++;
            }

            if (! is_file($writePath) || filesize($writePath) < 1) {
                $errors[] = "BACKUP finished but file missing: {$writePath}";
                continue;
            }

            if (strtolower($writePath) !== strtolower($finalDisk)) {
                if (! @copy($writePath, $finalDisk)) {
                    $errors[] = "Created .bak at {$writePath} but could not copy to {$finalDisk}";
                    $info['steps'][] = "database: native .bak at {$writePath} (copy to target failed)";
                    $info['bak_path'] = $writePath;

                    return true;
                }
                @unlink($writePath);
            }

            clearstatcache(true, $finalDisk);
            if (is_file($finalDisk) && filesize($finalDisk) > 0) {
                $info['bak_path'] = $finalDisk;
                $sizeMb = round(filesize($finalDisk) / 1048576, 2);
                $this->info("Native SQL Server .bak ready ({$sizeMb} MB): {$finalDisk}");

                return true;
            }

            $errors[] = "Final .bak missing after copy: {$finalDisk}";
        }

        foreach ($errors as $error) {
            $info['errors'][] = 'native sqlsrv: ' . $error;
        }

        return false;
    }

    /**
     * Run BACKUP DATABASE via sqlcmd (reliable on Windows). Falls back to PDO.
     *
     * @param  array<int, string>  $errors
     */
    private function runSqlServerBackup(array $connection, string $database, string $writePath, array &$errors): bool
    {
        $sql = sprintf(
            "BACKUP DATABASE [%s] TO DISK = N'%s' WITH FORMAT, INIT, COPY_ONLY, STATS = 10;",
            str_replace([']', "'"], '', $database),
            str_replace("'", "''", $writePath)
        );

        $sqlcmd = $this->findExecutable(['sqlcmd', 'sqlcmd.exe']);
        if ($sqlcmd) {
            $server = (string) ($connection['host'] ?? 'localhost');
            $port = $connection['port'] ?? null;
            if (! empty($port) && strpos($server, ',') === false && strpos($server, '\\') === false) {
                $server .= ',' . $port;
            }

            $auth = strtolower((string) env('DB_AUTHENTICATION', ''));
            $username = (string) ($connection['username'] ?? '');
            $password = (string) ($connection['password'] ?? '');
            $useWindows = $auth === 'windows' || ($username === '' && $password === '');

            $sqlFile = null;
            try {
                $sqlFile = tempnam(sys_get_temp_dir(), 'vf_bak_');
                if ($sqlFile === false) {
                    throw new \RuntimeException('Unable to create temp SQL file');
                }
                // sqlcmd -i expects a real .sql path
                $sqlFilePath = $sqlFile . '.sql';
                @rename($sqlFile, $sqlFilePath);
                $sqlFile = $sqlFilePath;
                File::put($sqlFile, $sql);

                // Single command string is more reliable than arg-array on Windows PHP.
                if ($useWindows) {
                    $cmdline = sprintf(
                        '"%s" -S %s -E -C -b -i %s',
                        $sqlcmd,
                        escapeshellarg($server),
                        escapeshellarg($sqlFile)
                    );
                } else {
                    $cmdline = sprintf(
                        '"%s" -S %s -U %s -P %s -C -b -i %s',
                        $sqlcmd,
                        escapeshellarg($server),
                        escapeshellarg($username),
                        escapeshellarg($password),
                        escapeshellarg($sqlFile)
                    );
                }

                $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
                $process = proc_open($cmdline, $descriptor, $pipes, null, null, ['bypass_shell' => false]);
                if (! is_resource($process)) {
                    $errors[] = 'sqlcmd: unable to start process';
                } else {
                    $stdout = stream_get_contents($pipes[1]);
                    $stderr = stream_get_contents($pipes[2]);
                    fclose($pipes[1]);
                    fclose($pipes[2]);
                    $code = proc_close($process);
                    $combined = trim((string) $stdout . "\n" . (string) $stderr);

                    if ($code === 0 && (
                        stripos($combined, 'BACKUP DATABASE successfully') !== false
                        || stripos($combined, 'pages for database') !== false
                        || (is_file($writePath) && filesize($writePath) > 1024 * 100)
                    )) {
                        return true;
                    }

                    $errors[] = 'sqlcmd: ' . ($combined !== '' ? $combined : "exit code {$code}");
                    if (is_file($writePath) && filesize($writePath) < 1024 * 100) {
                        @unlink($writePath);
                    }
                }
            } catch (Throwable $e) {
                $errors[] = 'sqlcmd: ' . $e->getMessage();
            } finally {
                if (is_string($sqlFile) && is_file($sqlFile)) {
                    @unlink($sqlFile);
                }
            }
        } else {
            $errors[] = 'sqlcmd not found on PATH; trying PDO fallback';
        }

        try {
            DB::connection()->getPdo()->exec(rtrim($sql, ';'));

            return is_file($writePath) && filesize($writePath) > 1024 * 100;
        } catch (Throwable $e) {
            $errors[] = 'pdo: ' . $e->getMessage();

            return false;
        }
    }

    /**
     * Resolve a directory the SQL Server service can write .bak files into.
     */
    private function resolveSqlServerBackupDirectory(): ?string
    {
        try {
            $row = DB::selectOne('SELECT CONVERT(nvarchar(4000), SERVERPROPERTY(\'InstanceDefaultBackupPath\')) AS backup_path');
            $path = trim((string) ($row->backup_path ?? ''));
            if ($path !== '' && $path !== 'NULL') {
                return $path;
            }
        } catch (Throwable $e) {
            // Older SQL Server builds may not expose InstanceDefaultBackupPath.
        }

        try {
            $row = DB::selectOne("
                DECLARE @path nvarchar(4000);
                EXEC master.dbo.xp_instance_regread
                    N'HKEY_LOCAL_MACHINE',
                    N'Software\\Microsoft\\MSSQLServer\\MSSQLServer',
                    N'BackupDirectory',
                    @path OUTPUT,
                    'no_output';
                SELECT @path AS backup_path;
            ");
            $path = trim((string) ($row->backup_path ?? ''));
            if ($path !== '') {
                return $path;
            }
        } catch (Throwable $e) {
            // Registry read may be blocked.
        }

        return null;
    }

    private function backupMysqlDump(array $connection, string $dbDir, array &$info): bool
    {
        $mysqldump = $this->findExecutable(['mysqldump', 'mysqldump.exe']);
        if (! $mysqldump) {
            $info['errors'][] = 'mysqldump not found on PATH';

            return false;
        }

        $outFile = $dbDir . DIRECTORY_SEPARATOR . 'database.sql';
        $host = $connection['host'] ?? '127.0.0.1';
        $port = $connection['port'] ?? '3306';
        $database = $connection['database'] ?? '';
        $username = $connection['username'] ?? '';
        $password = $connection['password'] ?? '';

        $cmd = sprintf(
            '%s --host=%s --port=%s --user=%s --single-transaction --routines --triggers --result-file=%s %s',
            escapeshellarg($mysqldump),
            escapeshellarg((string) $host),
            escapeshellarg((string) $port),
            escapeshellarg((string) $username),
            escapeshellarg($outFile),
            escapeshellarg((string) $database)
        );

        $env = [];
        if ($password !== '' && $password !== null) {
            $env['MYSQL_PWD'] = (string) $password;
        }

        $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($cmd, $descriptor, $pipes, null, $env + $_ENV);
        if (! is_resource($process)) {
            return false;
        }

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        if ($code !== 0 || ! is_file($outFile)) {
            $info['errors'][] = 'mysqldump: ' . trim((string) $stderr);

            return false;
        }

        return true;
    }

    private function backupTablesAsJson(string $dbDir, array &$info): void
    {
        $tablesDir = $dbDir . DIRECTORY_SEPARATOR . 'tables';
        if (! File::isDirectory($tablesDir)) {
            File::makeDirectory($tablesDir, 0755, true);
        }

        $driver = config('database.default');
        $tables = $this->listTables($driver);
        $manifest = [];

        foreach ($tables as $table) {
            $rows = DB::table($table)->get()->map(function ($row) {
                return (array) $row;
            })->all();

            $file = $tablesDir . DIRECTORY_SEPARATOR . $table . '.json';
            File::put($file, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $manifest[$table] = count($rows);
            $this->line("  • dumped {$table} (" . count($rows) . ' rows)');
        }

        File::put(
            $dbDir . DIRECTORY_SEPARATOR . 'tables-manifest.json',
            json_encode([
                'created_at' => now()->toDateTimeString(),
                'driver' => $driver,
                'tables' => $manifest,
            ], JSON_PRETTY_PRINT)
        );

        $info['table_counts'] = $manifest;
    }

    private function listTables(string $driver): array
    {
        if ($driver === 'sqlsrv') {
            $rows = DB::select("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME");

            return collect($rows)->map(function ($row) {
                return $row->TABLE_NAME ?? $row->table_name ?? null;
            })->filter()->values()->all();
        }

        if ($driver === 'mysql') {
            $database = config('database.connections.mysql.database');
            $rows = DB::select(
                'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ? ORDER BY TABLE_NAME',
                [$database, 'BASE TABLE']
            );

            return collect($rows)->map(function ($row) {
                return $row->TABLE_NAME ?? $row->table_name ?? null;
            })->filter()->values()->all();
        }

        // sqlite / others
        $rows = DB::select('SELECT name FROM sqlite_master WHERE type = ? ORDER BY name', ['table']);

        return collect($rows)->pluck('name')->reject(function ($name) {
            return $name === 'sqlite_sequence';
        })->values()->all();
    }

    private function backupFiles(string $targetDir, array &$info): bool
    {
        $source = config('backup.files_path');
        if (! is_dir($source)) {
            $info['steps'][] = 'files: source missing (' . $source . ')';
            $this->warn("Files path not found: {$source}");

            return true;
        }

        $zipPath = $targetDir . DIRECTORY_SEPARATOR . 'files.zip';

        try {
            if (! class_exists(ZipArchive::class)) {
                $copyDir = $targetDir . DIRECTORY_SEPARATOR . 'files';
                File::copyDirectory($source, $copyDir);
                $info['steps'][] = 'files: copied directory (ZipArchive unavailable)';

                return true;
            }

            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Unable to create files.zip');
            }

            $source = realpath($source);
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            $count = 0;
            foreach ($files as $file) {
                if (! $file->isFile()) {
                    continue;
                }
                $fullPath = $file->getRealPath();
                $relative = substr($fullPath, strlen($source) + 1);
                $zip->addFile($fullPath, str_replace('\\', '/', $relative));
                $count++;
            }

            $zip->close();
            $info['steps'][] = "files: zipped {$count} file(s) -> files.zip";
            $this->info("Zipped {$count} files -> files.zip");

            return true;
        } catch (Throwable $e) {
            $info['errors'][] = 'files: ' . $e->getMessage();
            $this->error('Files backup failed: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Delete only daily dated folders (and legacy root YYYY-MM-DD). Never touch weekly/.
     */
    private function pruneOldDailyBackups(string $root, string $dailyRoot, int $retentionDays): int
    {
        if ($retentionDays < 1) {
            return 0;
        }

        $cutoff = now()->startOfDay()->subDays($retentionDays);
        $removed = 0;

        $scanRoots = [];
        if (is_dir($dailyRoot)) {
            $scanRoots[] = $dailyRoot;
        }
        // Legacy layout: {BACKUP_PATH}/YYYY-MM-DD (before daily/ subfolder existed)
        if (is_dir($root)) {
            $scanRoots[] = $root;
        }

        foreach (array_unique($scanRoots) as $scanRoot) {
            foreach (File::directories($scanRoot) as $dir) {
                $name = basename($dir);
                if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $name)) {
                    continue;
                }

                // Never prune anything under weekly/
                $normalized = str_replace('\\', '/', $dir);
                if (stripos($normalized, '/weekly/') !== false || str_ends_with(rtrim($normalized, '/'), '/weekly')) {
                    continue;
                }

                try {
                    $folderDate = \Carbon\Carbon::createFromFormat('Y-m-d', $name)->startOfDay();
                } catch (Throwable $e) {
                    continue;
                }

                if ($folderDate->lt($cutoff)) {
                    File::deleteDirectory($dir);
                    $removed++;
                    $this->line('  • pruned old daily backup: ' . $name);
                }
            }
        }

        return $removed;
    }

    private function findExecutable(array $names): ?string
    {
        foreach ($names as $name) {
            $cmd = PHP_OS_FAMILY === 'Windows'
                ? 'where ' . escapeshellarg($name)
                : 'command -v ' . escapeshellarg($name);
            $out = [];
            $code = 0;
            $null = PHP_OS_FAMILY === 'Windows' ? '2>NUL' : '2>/dev/null';
            exec($cmd . ' ' . $null, $out, $code);
            if ($code === 0 && ! empty($out[0]) && is_file(trim($out[0]))) {
                return trim($out[0]);
            }
        }

        return null;
    }

    private function formatInfo(array $info): string
    {
        $lines = [
            'ValiFlow Daily Backup Report',
            '============================',
            'Status: ' . ($info['status'] ?? ''),
            'Started: ' . ($info['started_at'] ?? ''),
            'Finished: ' . ($info['finished_at'] ?? ''),
            'App: ' . ($info['app'] ?? ''),
            'Env: ' . ($info['environment'] ?? ''),
            'Connection: ' . ($info['connection'] ?? ''),
            'Database: ' . ($info['database'] ?? ''),
            'Target: ' . ($info['target'] ?? ''),
            'Weekly: ' . ($info['weekly_target'] ?? 'n/a'),
            'BAK file: ' . ($info['bak_path'] ?? 'n/a'),
            'Weekly BAK: ' . ($info['weekly_bak_path'] ?? 'n/a'),
            '',
            'Steps:',
        ];

        foreach ($info['steps'] ?? [] as $step) {
            $lines[] = ' - ' . $step;
        }

        if (! empty($info['table_counts'])) {
            $lines[] = '';
            $lines[] = 'Table row counts:';
            foreach ($info['table_counts'] as $table => $count) {
                $lines[] = " - {$table}: {$count}";
            }
        }

        if (! empty($info['errors'])) {
            $lines[] = '';
            $lines[] = 'Errors:';
            foreach ($info['errors'] as $error) {
                $lines[] = ' - ' . $error;
            }
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }
}
