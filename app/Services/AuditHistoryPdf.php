<?php

namespace App\Services;

/**
 * Plain landscape PDF for audit history.
 * DomPDF keeps a style object for every cell and runs out of memory on a large export.
 */
class AuditHistoryPdf
{
    private const PAGE_W = 841.89;
    private const PAGE_H = 595.28;

    public function render(array $rows, array $filters, int $total, string $generatedAt): string
    {
        $pages = $this->buildPages($rows, $filters, $total, $generatedAt);

        return $this->compile($pages);
    }

    private function buildPages(array $rows, array $filters, int $total, string $generatedAt): array
    {
        $marginX = 18.0;
        $marginTop = 16.0;
        $marginBottom = 20.0;
        $font = 7.0;
        $line = 9.0;
        $pad = 2.0;
        $labels = ['Date & Time', 'User', 'Action', 'Activity Performed', 'Status', 'Field Changed', 'Previous Value', 'New Value'];
        $keys = ['when', 'user', 'action', 'activity', 'status', 'fields', 'old', 'new'];
        $weights = [12, 12, 8, 18, 8, 12, 15, 15];
        $tableW = self::PAGE_W - (2 * $marginX);
        $weightSum = array_sum($weights);
        $colW = [];
        foreach ($weights as $weight) {
            $colW[] = $tableW * ($weight / $weightSum);
        }

        $headerLines = [];
        $headerH = $line + (2 * $pad);
        foreach ($labels as $index => $label) {
            $wrapped = $this->wrap($this->encode($label), $colW[$index] - (2 * $pad), $font);
            $headerLines[] = $wrapped;
            $headerH = max($headerH, (count($wrapped) * $line) + (2 * $pad));
        }

        $pages = [];
        $stream = '';
        $cursor = 0.0;
        $pageNo = 0;

        $startPage = function (bool $withIntro) use (
            &$stream,
            &$cursor,
            &$pageNo,
            &$pages,
            $marginX,
            $marginTop,
            $marginBottom,
            $font,
            $line,
            $filters,
            $total,
            $generatedAt,
            $headerLines,
            $headerH,
            $colW,
            $pad
        ) {
            if ($stream !== '') {
                $pages[] = $stream;
            }
            $pageNo++;
            $stream = "0.6 w\n";
            $cursor = self::PAGE_H - $marginTop;

            if ($withIntro) {
                $stream .= $this->text(18, $marginX, $cursor - 14, 'Audit History');
                $cursor -= 28;
                $meta = [
                    'Generated ' . $generatedAt,
                    'User: ' . ($filters['user'] ?? 'All Users')
                        . '  |  Event: ' . ($filters['event'] ?? 'All Events')
                        . '  |  From: ' . ($filters['from'] ?? 'Any')
                        . '  |  To: ' . ($filters['to'] ?? 'Any'),
                    'Matching records: ' . $total,
                ];
                if (! empty($filters['part'])) {
                    $meta[] = (string) $filters['part'];
                }
                foreach ($meta as $metaLine) {
                    $stream .= $this->text($font, $marginX, $cursor - $font, $this->encode($metaLine));
                    $cursor -= $line + 1;
                }
                $cursor -= 6;
            }

            $stream .= $this->row($marginX, $cursor, $headerH, $colW, $headerLines, $font, $line, $pad, true);
            $cursor -= $headerH;
            $this->footer($stream, $pageNo, $marginX, $marginBottom);
        };

        $startPage(true);

        if ($rows === []) {
            $emptyH = $line + (2 * $pad);
            $message = [$this->encode('No audit history found for the current filters.')];
            $lines = array_fill(0, count($colW), ['']);
            $lines[0] = $message;
            if ($cursor - $emptyH < $marginBottom + 8) {
                $startPage(false);
            }
            $stream .= $this->row($marginX, $cursor, $emptyH, $colW, $lines, $font, $line, $pad, false);
            $pages[] = $stream;

            return $this->stampPageCount($pages);
        }

        foreach ($rows as $row) {
            $cellLines = [];
            $rowH = $line + (2 * $pad);
            foreach ($keys as $index => $key) {
                $wrapped = $this->wrap($this->encode((string) ($row[$key] ?? '')), $colW[$index] - (2 * $pad), $font);
                if (count($wrapped) > 8) {
                    $wrapped = array_slice($wrapped, 0, 8);
                    $last = $wrapped[7];
                    $wrapped[7] = strlen($last) > 3 ? substr($last, 0, -3) . '...' : $last . '...';
                }
                $cellLines[] = $wrapped;
                $rowH = max($rowH, (count($wrapped) * $line) + (2 * $pad));
            }

            if ($cursor - $rowH < $marginBottom + 8) {
                $startPage(false);
            }

            $stream .= $this->row($marginX, $cursor, $rowH, $colW, $cellLines, $font, $line, $pad, false);
            $cursor -= $rowH;
        }

        $pages[] = $stream;

        return $this->stampPageCount($pages);
    }

    private function stampPageCount(array $pages): array
    {
        $count = count($pages);
        foreach ($pages as $index => $stream) {
            $pages[$index] = str_replace('%%PAGE%%', (string) ($index + 1), str_replace('%%PAGES%%', (string) $count, $stream));
        }

        return $pages;
    }

    private function footer(string &$stream, int $pageNo, float $marginX, float $marginBottom): void
    {
        $stream .= $this->text(7, self::PAGE_W - $marginX - 70, 10, $this->encode('Page %%PAGE%% of %%PAGES%%'));
    }

    private function row(
        float $x,
        float $top,
        float $height,
        array $colW,
        array $cellLines,
        float $font,
        float $line,
        float $pad,
        bool $header
    ): string {
        $ops = '';
        $bottom = $top - $height;
        $cursorX = $x;
        foreach ($colW as $index => $width) {
            if ($header) {
                $ops .= sprintf("0.93 0.94 0.96 rg %.2F %.2F %.2F %.2F re f\n", $cursorX, $bottom, $width, $height);
            }
            $ops .= sprintf("0.75 0.75 0.75 RG %.2F %.2F %.2F %.2F re S\n", $cursorX, $bottom, $width, $height);
            $ops .= "0 0 0 rg\n";
            $textY = $top - $pad - $font;
            foreach ($cellLines[$index] as $lineNo => $lineText) {
                if ($lineText === '') {
                    continue;
                }
                $ops .= $this->text($font, $cursorX + $pad, $textY - ($lineNo * $line), $lineText);
            }
            $cursorX += $width;
        }

        return $ops;
    }

    private function text(float $size, float $x, float $y, string $encoded): string
    {
        return sprintf("BT /F1 %.2F Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n", $size, $x, $y, $encoded);
    }

    private function wrap(string $encoded, float $width, float $font): array
    {
        $width = max(8, $width);
        $maxChars = max(4, (int) floor($width / ($font * 0.52)));
        if ($encoded === '') {
            return [''];
        }

        $words = explode(' ', $encoded);
        $lines = [];
        $current = '';
        foreach ($words as $word) {
            while (strlen($word) > $maxChars) {
                if ($current !== '') {
                    $lines[] = $current;
                    $current = '';
                }
                $lines[] = substr($word, 0, $maxChars);
                $word = substr($word, $maxChars);
            }
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if (strlen($candidate) <= $maxChars) {
                $current = $candidate;
                continue;
            }
            if ($current !== '') {
                $lines[] = $current;
            }
            $current = $word;
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines === [] ? [''] : $lines;
    }

    private function encode(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\n", "\t", '—', '–', '…'], [' ', ' ', ' ', ' ', '-', '-', '...'], $text);
        $converted = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        if (! is_string($converted) || $converted === '') {
            $converted = preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '';
        }
        $converted = preg_replace('/[^\x20-\xFF]/', '?', $converted) ?? '';

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $converted);
    }

    private function compile(array $pageStreams): string
    {
        if ($pageStreams === []) {
            $pageStreams = ["BT /F1 12 Tf 18 560 Td (Audit History) Tj ET\n"];
        }

        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';

        $pageIds = [];
        $nextId = 4;
        foreach ($pageStreams as $stream) {
            $pageId = $nextId;
            $contentId = $nextId + 1;
            $pageIds[] = $pageId;
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 841.89 595.28] /Contents ' . $contentId . ' 0 R /Resources << /Font << /F1 3 0 R >> >> >>';
            $objects[$contentId] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
            $nextId += 2;
        }

        $kids = implode(' ', array_map(static function ($id) {
            return $id . ' 0 R';
        }, $pageIds));
        $objects[2] = '<< /Type /Pages /Kids [' . $kids . '] /Count ' . count($pageIds) . ' >>';

        ksort($objects);
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        $maxId = max(array_keys($objects));
        for ($id = 1; $id <= $maxId; $id++) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $objects[$id] . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $size = $maxId + 1;
        $pdf .= "xref\n0 $size\n";
        $pdf .= "0000000000 65535 f \n";
        for ($id = 1; $id <= $maxId; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }
        $pdf .= "trailer\n<< /Size $size /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";

        return $pdf;
    }
}
