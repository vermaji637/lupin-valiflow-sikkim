<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

// Applies inline text edits to a NEQ DOCX via Python CLI or HTTP fallback.
class NewEquipmentQualificationDocxService
{
    // Entry point: try local Python runner first, then HTTP service
    public function applyEditsToDocx(
        string $sourcePath,
        array $edits,
        array $tableOps = [],
        array $cellEdits = [],
        array $checkboxOps = [],
        array $structureOps = [],
        array $tocLines = []
    ): array {
        if (! is_file($sourcePath)) {
            throw new RuntimeException('Source DOCX file not found.');
        }

        $edits = array_values($edits);
        $tableOps = array_values($tableOps);
        $cellEdits = array_values($cellEdits);
        $checkboxOps = array_values($checkboxOps);
        $structureOps = array_values($structureOps);

        try {
            return $this->applyEditsViaCli($sourcePath, $edits, $tableOps, $cellEdits, $checkboxOps, $structureOps, $tocLines);
        } catch (\Throwable $cliError) {
            Log::warning('NEQ CLI save failed, falling back to HTTP', [
                'message' => $cliError->getMessage(),
            ]);
        }

        return $this->applyEditsViaHttp($sourcePath, $edits, $tableOps, $cellEdits, $checkboxOps, $structureOps, $tocLines);
    }

    /**
     * Build the same fill HTML Requalification uses, from a DOCX path on disk.
     *
     * @return array{html: string, fields: array<int, array<string, mixed>>, header_table_count: int, para_target_map: array<string, int>}
     */
    public function buildFillPreview(string $sourcePath): array
    {
        if (! is_file($sourcePath)) {
            throw new RuntimeException('Source DOCX file not found.');
        }

        $cli = base_path('python-service/cli.py');
        if (! is_file($cli)) {
            throw new RuntimeException('Python preview script not found.');
        }

        $pythonBin = $this->resolvePythonBinary();
        $parsedRaw = $this->runPythonCli($pythonBin, $cli, ['parse', $sourcePath]);
        $parsed = json_decode($parsedRaw, true);
        if (! is_array($parsed) || ($parsed['status'] ?? '') !== 'success') {
            $message = is_array($parsed) ? (string) ($parsed['message'] ?? '') : '';
            throw new RuntimeException($message !== '' ? $message : 'Unable to parse the Word document.');
        }

        $fields = array_values(is_array($parsed['fields'] ?? null) ? $parsed['fields'] : []);
        $fieldsFile = tempnam(sys_get_temp_dir(), 'neq_prev_');
        if ($fieldsFile === false) {
            throw new RuntimeException('Unable to create a temporary preview file.');
        }

        try {
            file_put_contents($fieldsFile, json_encode($fields, JSON_UNESCAPED_UNICODE));
            $html = $this->runPythonCli($pythonBin, $cli, ['preview', $sourcePath, $fieldsFile, 'edit']);
        } finally {
            if (is_file($fieldsFile)) {
                @unlink($fieldsFile);
            }
        }

        $start = strpos($html, '<div class="docx-preview"');
        if ($start !== false) {
            $html = substr($html, $start);
        }
        $html = trim($html);
        if ($html === '') {
            throw new RuntimeException('Preview HTML was empty.');
        }

        $headerIndexes = [];
        foreach ($fields as $field) {
            $location = (string) ($field['location'] ?? '');
            if (preg_match('/^header_table_(\d+)/', $location, $match)) {
                $headerIndexes[(int) $match[1]] = true;
            }
        }

        return [
            'html' => $html,
            'fields' => $fields,
            'header_table_count' => max(1, count($headerIndexes)),
            'para_target_map' => [],
        ];
    }

    /**
     * @param  array<int, string>  $args
     */
    private function runPythonCli(string $pythonBin, string $script, array $args): string
    {
        $parts = [escapeshellarg($pythonBin), escapeshellarg($script)];
        foreach ($args as $arg) {
            $parts[] = escapeshellarg($arg);
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(implode(' ', $parts), $descriptorSpec, $pipes, base_path('python-service'));
        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start Python.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new RuntimeException(trim((string) $stderr) !== '' ? trim((string) $stderr) : 'Python preview failed.');
        }

        return (string) $stdout;
    }

    // Return body text targets with DOCX indexes used to map browser edits safely
    public function listTextTargets(string $sourcePath, ?int $recordId = null): array
    {
        if (! is_file($sourcePath)) {
            throw new RuntimeException('Source DOCX file not found.');
        }

        $mtime = (int) (@filemtime($sourcePath) ?: 0);
        $size = (int) (@filesize($sourcePath) ?: 0);
        $cachePath = $this->targetCachePath($sourcePath, $mtime, $recordId);

        if (is_file($cachePath)) {
            $raw = @file_get_contents($cachePath);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (
                    is_array($decoded)
                    && isset($decoded['targets'])
                    && is_array($decoded['targets'])
                    && (string) ($decoded['source_path'] ?? '') === $sourcePath
                    && (int) ($decoded['mtime'] ?? -1) === $mtime
                    && (int) ($decoded['size'] ?? -1) === $size
                ) {
                    return array_values($decoded['targets']);
                }
            }
        }

        try {
            $targets = $this->listTextTargetsViaHttp($sourcePath);
        } catch (\Throwable $httpError) {
            Log::warning('NEQ HTTP text-targets failed, falling back to CLI', [
                'message' => $httpError->getMessage(),
            ]);

            $targets = $this->listTextTargetsViaCli($sourcePath);
        }

        $this->writeTargetCache($cachePath, $sourcePath, $mtime, $size, $targets);

        return $targets;
    }

    public function invalidateTargetCache(?int $recordId = null, ?string $sourcePath = null): void
    {
        $cacheDir = storage_path('app/neq-cache');
        if (! is_dir($cacheDir)) {
            return;
        }

        $patterns = [];
        if ($recordId !== null && $recordId > 0) {
            $patterns[] = $cacheDir . DIRECTORY_SEPARATOR . $recordId . '-*-targets.json';
        }
        if ($sourcePath !== null && $sourcePath !== '') {
            $patterns[] = $cacheDir . DIRECTORY_SEPARATOR . 'path-' . sha1($sourcePath) . '-*-targets.json';
        }

        foreach ($patterns as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }

    private function targetCachePath(string $sourcePath, int $mtime, ?int $recordId = null): string
    {
        $cacheDir = storage_path('app/neq-cache');
        $prefix = ($recordId !== null && $recordId > 0)
            ? (string) $recordId
            : ('path-' . sha1($sourcePath));

        return $cacheDir . DIRECTORY_SEPARATOR . $prefix . '-' . $mtime . '-targets.json';
    }

    private function writeTargetCache(
        string $cachePath,
        string $sourcePath,
        int $mtime,
        int $size,
        array $targets
    ): void {
        try {
            $dir = dirname($cachePath);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $encoded = json_encode([
                'source_path' => $sourcePath,
                'mtime' => $mtime,
                'size' => $size,
                'targets' => array_values($targets),
            ], JSON_UNESCAPED_UNICODE);
            if (is_string($encoded)) {
                @file_put_contents($cachePath, $encoded, LOCK_EX);
            }
        } catch (\Throwable $e) {
            Log::warning('Unable to write NEQ text-targets cache', [
                'cache_path' => $cachePath,
                'message' => $e->getMessage(),
            ]);
        }
    }

    // Spawn python-service/run_neq_targets.py and decode JSON target list from stdout
    private function listTextTargetsViaCli(string $sourcePath): array
    {
        $pythonBin = $this->resolvePythonBinary();
        $runner = base_path('python-service/run_neq_targets.py');

        if (! is_file($runner)) {
            throw new RuntimeException('Python NEQ targets script not found.');
        }

        $command = sprintf(
            '%s %s %s',
            escapeshellarg($pythonBin),
            escapeshellarg($runner),
            escapeshellarg($sourcePath)
        );

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, base_path('python-service'));

        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start Python NEQ targets process.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new RuntimeException(trim($stderr ?: 'Python NEQ targets process failed.'));
        }

        $decoded = json_decode((string) $stdout, true);
        if (! is_array($decoded) || ! isset($decoded['targets']) || ! is_array($decoded['targets'])) {
            throw new RuntimeException(trim($stderr ?: 'Python NEQ targets returned invalid JSON.'));
        }

        return array_values($decoded['targets']);
    }

    // POST source file to Python /neq/text-targets and return targets array
    private function listTextTargetsViaHttp(string $sourcePath): array
    {
        $pythonUrl = rtrim((string) (config('services.python_nlp.url') ?: env('PYTHON_NLP_URL', 'http://127.0.0.1:8001')), '/');

        $response = Http::timeout(120)
            ->attach(
                'file',
                file_get_contents($sourcePath),
                basename($sourcePath)
            )
            ->post($pythonUrl . '/neq/text-targets');

        if (! $response->ok()) {
            $detail = $response->json('detail') ?? trim($response->body());
            throw new RuntimeException(
                'Python text-targets failed (HTTP ' . $response->status() . '): ' . ($detail ?: 'No response body')
            );
        }

        $targets = $response->json('targets');
        if (! is_array($targets)) {
            throw new RuntimeException('Python text-targets returned no target list.');
        }

        return array_values($targets);
    }

    // Spawn python-service/run_neq_save.py and capture edited DOCX bytes from stdout
    private function applyEditsViaCli(
        string $sourcePath,
        array $edits,
        array $tableOps = [],
        array $cellEdits = [],
        array $checkboxOps = [],
        array $structureOps = [],
        array $tocLines = []
    ): array {
        $pythonBin = $this->resolvePythonBinary();
        $runner = base_path('python-service/run_neq_save.py');

        if (! is_file($runner)) {
            throw new RuntimeException('Python NEQ runner script not found.');
        }

        $editsFile = tempnam(sys_get_temp_dir(), 'neq_edits_');
        if ($editsFile === false) {
            throw new RuntimeException('Unable to create temporary edits file.');
        }

        $outputFile = tempnam(sys_get_temp_dir(), 'neq_out_');
        if ($outputFile === false) {
            @unlink($editsFile);
            throw new RuntimeException('Unable to create temporary output file.');
        }

        $docxPath = $outputFile . '.docx';
        @unlink($outputFile);

        $docxDir = dirname($docxPath);
        if (! is_dir($docxDir)) {
            mkdir($docxDir, 0755, true);
        }

        file_put_contents($editsFile, json_encode([
            'edits' => $edits,
            'table_ops' => $tableOps,
            'cell_edits' => $cellEdits,
            'checkbox_ops' => $checkboxOps,
            'structure_ops' => $structureOps,
            'toc_lines' => array_values($tocLines),
        ], JSON_UNESCAPED_UNICODE));

        $command = sprintf(
            '%s %s %s %s',
            escapeshellarg($pythonBin),
            escapeshellarg($runner),
            escapeshellarg($sourcePath),
            escapeshellarg($editsFile)
        );

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, base_path('python-service'));

        if (! is_resource($process)) {
            @unlink($editsFile);
            throw new RuntimeException('Unable to start Python NEQ save process.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        @unlink($editsFile);

        if ($exitCode !== 0) {
            throw new RuntimeException(trim($stderr ?: 'Python NEQ save process failed.'));
        }

        if ($stdout === false || $stdout === '') {
            throw new RuntimeException(trim($stderr ?: 'Python NEQ save returned empty output.'));
        }

        if (file_put_contents($docxPath, $stdout) === false) {
            throw new RuntimeException('Unable to write edited DOCX to disk.');
        }

        Log::info('NEQ save via CLI succeeded', [
            'stderr' => trim((string) $stderr),
            'bytes' => strlen($stdout),
        ]);

        $stats = [];
        $stderrText = trim((string) $stderr);
        if ($stderrText !== '') {
            $decoded = json_decode($stderrText, true);
            if (is_array($decoded) && isset($decoded['stats']) && is_array($decoded['stats'])) {
                $stats = $decoded['stats'];
            }
        }

        return [
            'path' => $docxPath,
            'stats' => $stats,
        ];
    }

    // POST edits JSON + source file to Python /neq/save-as and decode base64 output
    private function applyEditsViaHttp(
        string $sourcePath,
        array $edits,
        array $tableOps = [],
        array $cellEdits = [],
        array $checkboxOps = [],
        array $structureOps = [],
        array $tocLines = []
    ): array {
        $pythonUrl = rtrim(config('services.python_nlp.url', env('PYTHON_NLP_URL', 'http://127.0.0.1:8001')), '/');

        $response = Http::timeout(180)
            ->attach(
                'file',
                file_get_contents($sourcePath),
                basename($sourcePath)
            )
            ->post($pythonUrl . '/neq/save-as', [
                'edits' => json_encode($edits, JSON_UNESCAPED_UNICODE),
                'table_ops' => json_encode($tableOps, JSON_UNESCAPED_UNICODE),
                'cell_edits' => json_encode($cellEdits, JSON_UNESCAPED_UNICODE),
                'checkbox_ops' => json_encode($checkboxOps, JSON_UNESCAPED_UNICODE),
                'structure_ops' => json_encode($structureOps, JSON_UNESCAPED_UNICODE),
                'toc_lines' => json_encode(array_values($tocLines), JSON_UNESCAPED_UNICODE),
            ]);

        if (! $response->ok()) {
            $detail = $response->json('detail') ?? trim($response->body());
            throw new RuntimeException(
                'Python save failed (HTTP ' . $response->status() . '): ' . ($detail ?: 'No response body')
            );
        }

        $encoded = $response->json('output_base64');
        if (! is_string($encoded) || $encoded === '') {
            throw new RuntimeException('Python save returned no DOCX output.');
        }

        $binary = base64_decode($encoded, true);
        if ($binary === false || $binary === '') {
            throw new RuntimeException('Python save returned invalid DOCX data.');
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'neq_docx_');
        if ($tempFile === false) {
            throw new RuntimeException('Unable to create a temporary file.');
        }

        $docxPath = $tempFile . '.docx';
        @unlink($tempFile);

        $docxDir = dirname($docxPath);
        if (! is_dir($docxDir)) {
            mkdir($docxDir, 0755, true);
        }

        if (file_put_contents($docxPath, $binary) === false) {
            throw new RuntimeException('Unable to write edited DOCX to disk.');
        }

        $stats = $response->json('stats');
        if (! is_array($stats)) {
            $stats = [];
        }

        return [
            'path' => $docxPath,
            'stats' => $stats,
        ];
    }

    // Prefer project venv Python, then system python/python3
    private function resolvePythonBinary(): string
    {
        $candidates = [
            base_path('python-service/venv/Scripts/python.exe'),
            base_path('python-service/venv/bin/python'),
            'python',
            'python3',
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === 'python' || $candidate === 'python3') {
                return $candidate;
            }

            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return 'python';
    }
}
