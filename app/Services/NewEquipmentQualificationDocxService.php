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

    // Return body text targets with DOCX indexes used to map browser edits safely
    public function listTextTargets(string $sourcePath): array
    {
        if (! is_file($sourcePath)) {
            throw new RuntimeException('Source DOCX file not found.');
        }

        try {
            return $this->listTextTargetsViaCli($sourcePath);
        } catch (\Throwable $cliError) {
            Log::warning('NEQ CLI text-targets failed, falling back to HTTP', [
                'message' => $cliError->getMessage(),
            ]);
        }

        return $this->listTextTargetsViaHttp($sourcePath);
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
        $pythonUrl = rtrim(config('services.python_nlp.url', env('PYTHON_NLP_URL', 'http://127.0.0.1:8001')), '/');

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
