<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach (App\Models\Report::whereNotNull('docx_import_fields')->orderByDesc('id')->limit(8)->get() as $r) {
    $hits = 0;
    foreach ((array)$r->docx_import_fields as $f) {
        if (!is_array($f)) continue;
        $label = strtolower($f['label'] ?? '');
        $loc = $f['location'] ?? '';
        if (preg_match('/^\d+\.\d+/', $label) || str_contains($label, 'objective') && str_contains($loc, 'para_')) {
            echo "Report {$r->id}: " . json_encode($f) . "\n";
            $hits++;
        }
    }
    if ($hits) echo "---\n";
}
