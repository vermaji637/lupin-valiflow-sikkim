<?php

namespace App\Services;

use ZipArchive;

class DocxTemplateService
{
    private const WORD_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    public function ensureProcessableDocxPath(string $absolutePath): string
    {
        $extension = strtolower((string) pathinfo($absolutePath, PATHINFO_EXTENSION));

        if ($extension === 'docx') {
            return $absolutePath;
        }

        if ($extension !== 'doc') {
            throw new \RuntimeException('Only .docx and .doc files are supported.');
        }

        return $this->convertDocToDocx($absolutePath);
    }

    /**
     * Detect placeholders from a DOCX file.
     * Supported tokens: ${field_name} and {{field_name}}
     */
    public function detectPlaceholdersFromFilePath(string $absolutePath): array
    {
        $absolutePath = $this->ensureProcessableDocxPath($absolutePath);

        $zip = new ZipArchive();
        if ($zip->open($absolutePath) !== true) {
            return [];
        }

        $names = $this->wordXmlParts($zip);
        $all = [];

        foreach ($names as $name) {
            $xml = $zip->getFromName($name);
            if (!is_string($xml) || $xml === '') {
                continue;
            }

            $all = array_merge($all, $this->extractPlaceholdersFromText($xml));
        }

        $zip->close();

        $all = array_values(array_unique($all));
        sort($all);

        return $all;
    }

    /**
     * Populate a DOCX template and save generated file on public disk.
     */
    public function populateTemplateFromPublicPath(string $templateRelativePath, array $values, string $outputRelativePath): string
    {
        $templateAbsolutePath = storage_path('app/public/' . ltrim($templateRelativePath, '/'));
        if (!is_file($templateAbsolutePath)) {
            throw new \RuntimeException('DOCX template file not found.');
        }

        $templateAbsolutePath = $this->ensureProcessableDocxPath($templateAbsolutePath);

        $outputAbsolutePath = storage_path('app/public/' . ltrim($outputRelativePath, '/'));
        $outputDir = dirname($outputAbsolutePath);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0775, true);
        }

        if (!copy($templateAbsolutePath, $outputAbsolutePath)) {
            throw new \RuntimeException('Unable to copy DOCX template.');
        }

        $zip = new ZipArchive();
        if ($zip->open($outputAbsolutePath) !== true) {
            throw new \RuntimeException('Unable to open generated DOCX file.');
        }

        foreach ($this->wordXmlParts($zip) as $name) {
            $xml = $zip->getFromName($name);
            if (!is_string($xml) || $xml === '') {
                continue;
            }

            $xml = $this->replacePlaceholders($xml, $values);
            $zip->addFromString($name, $xml);
        }

        $zip->close();

        return $outputRelativePath;
    }

    /**
     * Fallback for legacy templates:
     * convert long blank markers like "_____" or "....." into ${auto_field_n} placeholders.
     */
    public function autoInjectPlaceholdersFromBlanks(string $absolutePath): array
    {
        $absolutePath = $this->ensureProcessableDocxPath($absolutePath);

        $zip = new ZipArchive();
        if ($zip->open($absolutePath) !== true) {
            return [];
        }

        $placeholders = [];
        $counter = 1;

        foreach ($this->wordXmlParts($zip) as $name) {
            $xml = $zip->getFromName($name);
            if (!is_string($xml) || $xml === '') {
                continue;
            }

            $updated = $this->injectAutoPlaceholdersInWordXml($xml, $counter, $placeholders);
            if ($updated !== $xml) {
                $zip->addFromString($name, $updated);
            }
        }

        $zip->close();

        $placeholders = array_values(array_unique($placeholders));
        sort($placeholders);

        return $placeholders;
    }

    /**
     * Inject placeholders into empty table cells based on nearby row/column labels.
     */
    public function autoInjectPlaceholdersFromEmptyTableCells(string $absolutePath): array
    {
        $absolutePath = $this->ensureProcessableDocxPath($absolutePath);

        $zip = new ZipArchive();
        if ($zip->open($absolutePath) !== true) {
            return [];
        }

        $placeholders = [];
        $used = [];
        $counter = 1;

        foreach ($this->wordXmlParts($zip) as $name) {
            $xml = $zip->getFromName($name);
            if (!is_string($xml) || $xml === '') {
                continue;
            }

            $updated = $this->injectPlaceholdersInEmptyTableCellsXml($xml, $counter, $placeholders, $used);
            if ($updated !== $xml) {
                $zip->addFromString($name, $updated);
            }
        }

        $zip->close();

        $placeholders = array_values(array_unique($placeholders));
        sort($placeholders);

        return $placeholders;
    }

    public function convertPublicDocToHtml(string $templateRelativePath): string
    {
        $absolutePath = storage_path('app/public/' . ltrim($templateRelativePath, '/'));
        if (!is_file($absolutePath)) {
            throw new \RuntimeException('Template source file not found.');
        }

        return $this->convertDocFileToHtml($absolutePath);
    }

    public function convertDocFileToHtml(string $absolutePath): string
    {
        $absolutePath = $this->ensureProcessableDocxPath($absolutePath);

        $zip = new ZipArchive();
        if ($zip->open($absolutePath) !== true) {
            throw new \RuntimeException('Unable to open DOCX for HTML conversion.');
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if (!is_string($xml) || trim($xml) === '') {
            throw new \RuntimeException('Invalid DOCX document XML.');
        }

        return $this->wordDocumentXmlToHtml($xml);
    }

    private function wordDocumentXmlToHtml(string $xml): string
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        if (!$doc->loadXML($xml)) {
            throw new \RuntimeException('Unable to parse DOCX document XML.');
        }

        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('w', self::WORD_NS);

        $body = $xpath->query('/w:document/w:body')->item(0);
        if (!$body) {
            throw new \RuntimeException('Unable to find DOCX body for conversion.');
        }

        $htmlBlocks = [];
        foreach ($body->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }

            if ($child->localName === 'p') {
                $htmlBlocks[] = $this->renderParagraphAsHtml($xpath, $child);
                continue;
            }

            if ($child->localName === 'tbl') {
                $htmlBlocks[] = $this->renderTableAsHtml($xpath, $child);
                continue;
            }
        }

        $html = trim(implode("\n", array_filter($htmlBlocks)));
        if ($html === '') {
            $plainText = trim(preg_replace('/\s+/u', ' ', strip_tags($xml)) ?? '');
            if ($plainText === '') {
                throw new \RuntimeException('No convertible content found in DOCX.');
            }
            $html = '<p>' . htmlspecialchars($plainText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }

        return '<div class="docx-imported-html">' . $html . '</div>';
    }

    private function renderParagraphAsHtml(\DOMXPath $xpath, \DOMElement $paragraph): string
    {
        $alignmentNode = $xpath->query('./w:pPr/w:jc', $paragraph)->item(0);
        $alignment = $alignmentNode instanceof \DOMElement
            ? $alignmentNode->getAttributeNS(self::WORD_NS, 'val')
            : '';

        $alignCss = '';
        if (in_array($alignment, ['left', 'right', 'center', 'both', 'justify'], true)) {
            $alignCss = 'text-align:' . ($alignment === 'both' ? 'justify' : $alignment) . ';';
        }

        $text = $this->extractNodeText($xpath, $paragraph);
        if (trim($text) === '') {
            return '<p style="margin:0 0 8px;' . $alignCss . '">&nbsp;</p>';
        }

        $safeText = nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        return '<p style="margin:0 0 8px;' . $alignCss . '">' . $safeText . '</p>';
    }

    private function renderTableAsHtml(\DOMXPath $xpath, \DOMElement $table): string
    {
        $rowsHtml = [];
        $rows = $xpath->query('./w:tr', $table);
        if (!$rows) {
            return '';
        }

        foreach ($rows as $row) {
            if (!$row instanceof \DOMElement) {
                continue;
            }

            $cells = $xpath->query('./w:tc', $row);
            if (!$cells) {
                continue;
            }

            $cellsHtml = [];
            foreach ($cells as $cell) {
                if (!$cell instanceof \DOMElement) {
                    continue;
                }

                $cellText = trim($this->extractNodeText($xpath, $cell));
                $cellHtml = $cellText === ''
                    ? '&nbsp;'
                    : nl2br(htmlspecialchars($cellText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

                $cellsHtml[] = '<td style="border:1px solid #000; padding:6px; vertical-align:top;">' . $cellHtml . '</td>';
            }

            if (!empty($cellsHtml)) {
                $rowsHtml[] = '<tr>' . implode('', $cellsHtml) . '</tr>';
            }
        }

        if (empty($rowsHtml)) {
            return '';
        }

        return '<table style="width:100%; border-collapse:collapse; border:1px solid #000; margin:0 0 12px;">'
            . '<tbody>' . implode('', $rowsHtml) . '</tbody></table>';
    }

    private function extractNodeText(\DOMXPath $xpath, \DOMNode $contextNode): string
    {
        $tokens = [];
        $nodes = $xpath->query('.//w:t|.//w:br|.//w:tab', $contextNode);
        if (!$nodes) {
            return '';
        }

        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            if ($node->localName === 't') {
                $tokens[] = $node->textContent;
                continue;
            }

            if ($node->localName === 'tab') {
                $tokens[] = "\t";
                continue;
            }

            if ($node->localName === 'br') {
                $tokens[] = "\n";
            }
        }

        $text = implode('', $tokens);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? '';
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function wordXmlParts(ZipArchive $zip): array
    {
        $names = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!is_string($name)) {
                continue;
            }

            $isWordXml = str_starts_with($name, 'word/')
                && str_ends_with($name, '.xml');

            $isSupportedPart = $name === 'word/document.xml'
                || str_starts_with($name, 'word/header')
                || str_starts_with($name, 'word/footer');

            if ($isWordXml && $isSupportedPart) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function extractPlaceholdersFromText(string $content): array
    {
        $found = [];
        $normalized = $this->normalizeWordXmlForTokenScan($content);

        if (preg_match_all('/\$\{([A-Za-z0-9_.-]+)\}/', $normalized, $matchesA)) {
            $found = array_merge($found, $matchesA[1]);
        }

        if (preg_match_all('/\{\{([A-Za-z0-9_.-]+)\}\}/', $normalized, $matchesB)) {
            $found = array_merge($found, $matchesB[1]);
        }

        if (preg_match_all('/<<\s*([A-Za-z0-9_.-]+)\s*>>/', $normalized, $matchesC)) {
            $found = array_merge($found, $matchesC[1]);
        }

        if (preg_match_all('/«\s*([A-Za-z0-9_.-]+)\s*»/u', $normalized, $matchesD)) {
            $found = array_merge($found, $matchesD[1]);
        }

        if (preg_match_all('/MERGEFIELD\s+"?([A-Za-z0-9_.-]+)"?/i', $normalized, $matchesE)) {
            $found = array_merge($found, $matchesE[1]);
        }

        return array_values(array_unique(array_filter(array_map('trim', $found))));
    }

    private function replacePlaceholders(string $xml, array $values): string
    {
        foreach ($values as $key => $rawValue) {
            $token = trim((string) $key);
            if ($token === '') {
                continue;
            }

            $value = $this->escapeForWordXml((string) $rawValue);
            $xml = str_replace('${' . $token . '}', $value, $xml);
            $xml = str_replace('{{' . $token . '}}', $value, $xml);
            $xml = str_replace('<<' . $token . '>>', $value, $xml);
            $xml = str_replace('«' . $token . '»', $value, $xml);
        }

        return $xml;
    }

    private function escapeForWordXml(string $value): string
    {
        $value = str_replace(["\r\n", "\n", "\r"], ' ', $value);
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function convertDocToDocx(string $docPath): string
    {
        $docxPath = dirname($docPath) . DIRECTORY_SEPARATOR . pathinfo($docPath, PATHINFO_FILENAME) . '.docx';
        if (is_file($docxPath)) {
            @unlink($docxPath);
        }

        $sofficeBinary = $this->resolveSofficeBinary();
        if ($sofficeBinary === null) {
            throw new \RuntimeException('Legacy .doc upload requires LibreOffice. Install LibreOffice or upload .docx.');
        }

        $command = $this->quoteForShell($sofficeBinary)
            . ' --headless --convert-to docx --outdir '
            . $this->quoteForShell(dirname($docPath))
            . ' '
            . $this->quoteForShell($docPath)
            . ' 2>&1';

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        clearstatcache();

        if ($exitCode !== 0 || !is_file($docxPath)) {
            $tail = implode(PHP_EOL, array_slice($output, -5));
            throw new \RuntimeException(
                'Unable to convert .doc to .docx. Install LibreOffice or upload .docx. ' . $tail
            );
        }

        return $docxPath;
    }

    private function resolveSofficeBinary(): ?string
    {
        $candidates = array_filter([
            env('LIBREOFFICE_BIN'),
            'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
            'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
            'soffice',
        ]);

        foreach ($candidates as $candidate) {
            if ($candidate === 'soffice') {
                return $candidate;
            }

            if (is_string($candidate) && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function quoteForShell(string $value): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return '"' . str_replace('"', '""', $value) . '"';
        }

        return escapeshellarg($value);
    }

    private function normalizeWordXmlForTokenScan(string $xml): string
    {
        // Join split text runs so tokens like ${field} or MERGEFIELD fragments are discoverable.
        $joined = preg_replace('/<\/w:t>\s*<w:t[^>]*>/i', '', $xml);
        $joined = preg_replace('/<\/w:instrText>\s*<w:instrText[^>]*>/i', '', (string) $joined);

        // Keep only text content for safer regex token extraction.
        $text = strip_tags((string) $joined);
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function injectAutoPlaceholdersInWordXml(string $xml, int &$counter, array &$placeholders): string
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        if (!$doc->loadXML($xml)) {
            return $xml;
        }

        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $nodes = $xpath->query('//w:t');
        if (!$nodes) {
            return $xml;
        }

        foreach ($nodes as $node) {
            $text = $node->textContent;
            if (!is_string($text) || $text === '') {
                continue;
            }

            $newText = preg_replace_callback('/(_{5,}|\.{5,})/u', function () use (&$counter, &$placeholders) {
                $token = 'auto_field_' . str_pad((string) $counter, 3, '0', STR_PAD_LEFT);
                $counter++;
                $placeholders[] = $token;
                return '${' . $token . '}';
            }, $text);

            if (is_string($newText) && $newText !== $text) {
                $node->nodeValue = $newText;
            }
        }

        return $doc->saveXML() ?: $xml;
    }

    private function injectPlaceholdersInEmptyTableCellsXml(string $xml, int &$counter, array &$placeholders, array &$used): string
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        if (!$doc->loadXML($xml)) {
            return $xml;
        }

        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $tables = $xpath->query('//w:tbl');
        if (!$tables) {
            return $xml;
        }

        $tableIndex = 0;
        foreach ($tables as $table) {
            $tableIndex++;
            $headerByCol = [];
            $rows = $xpath->query('./w:tr', $table);
            if (!$rows) {
                continue;
            }

            $rowIndex = 0;
            foreach ($rows as $row) {
                $rowIndex++;
                $cells = $xpath->query('./w:tc', $row);
                if (!$cells) {
                    continue;
                }

                $rowLabel = '';
                foreach ($cells as $scanCell) {
                    $txt = $this->cellText($xpath, $scanCell);
                    if ($txt !== '') {
                        $rowLabel = $txt;
                        break;
                    }
                }

                $colIndex = 0;
                foreach ($cells as $cell) {
                    $colIndex++;
                    $text = $this->cellText($xpath, $cell);

                    if ($rowIndex === 1 && $text !== '') {
                        $headerByCol[$colIndex] = $text;
                    }

                    if ($text !== '') {
                        continue;
                    }

                    $base = $this->buildEmptyCellTokenBase(
                        $headerByCol[$colIndex] ?? '',
                        $rowLabel,
                        $tableIndex,
                        $rowIndex,
                        $colIndex
                    );

                    $token = $this->makeUniqueToken($base, $used, $counter);
                    $placeholders[] = $token;

                    $this->setCellText($doc, $xpath, $cell, '${' . $token . '}');
                }
            }
        }

        return $doc->saveXML() ?: $xml;
    }

    private function cellText(\DOMXPath $xpath, \DOMNode $cell): string
    {
        $textNodes = $xpath->query('.//w:t', $cell);
        if (!$textNodes) {
            return '';
        }

        $parts = [];
        foreach ($textNodes as $tNode) {
            $parts[] = (string) $tNode->textContent;
        }

        return trim(preg_replace('/\s+/u', ' ', implode(' ', $parts)) ?? '');
    }

    private function setCellText(\DOMDocument $doc, \DOMXPath $xpath, \DOMNode $cell, string $text): void
    {
        $ns = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $paragraph = $xpath->query('./w:p', $cell)?->item(0);

        if (!$paragraph) {
            $paragraph = $doc->createElementNS($ns, 'w:p');
            $cell->appendChild($paragraph);
        }

        $run = $doc->createElementNS($ns, 'w:r');
        $t = $doc->createElementNS($ns, 'w:t');
        $t->appendChild($doc->createTextNode($text));
        $run->appendChild($t);
        $paragraph->appendChild($run);
    }

    private function buildEmptyCellTokenBase(string $columnHeader, string $rowLabel, int $tableIndex, int $rowIndex, int $colIndex): string
    {
        $rowToken = $this->slugToken($rowLabel);
        $columnToken = $this->slugToken($columnHeader);

        if ($rowToken !== '' && $columnToken !== '') {
            return $rowToken . '_' . $columnToken;
        }

        if ($rowToken !== '') {
            return $rowToken;
        }

        if ($columnToken !== '') {
            return $columnToken;
        }

        return 'table_' . $tableIndex . '_row_' . $rowIndex . '_col_' . $colIndex;
    }

    private function slugToken(string $text): string
    {
        $text = strtolower(trim($text));
        if ($text === '') {
            return '';
        }

        $text = preg_replace('/[^a-z0-9]+/i', '_', $text) ?? '';
        $text = trim($text, '_');
        if ($text === '') {
            return '';
        }

        return substr($text, 0, 60);
    }

    private function makeUniqueToken(string $base, array &$used, int &$counter): string
    {
        $candidate = $base !== ''
            ? $base
            : 'auto_field_' . str_pad((string) $counter, 3, '0', STR_PAD_LEFT);

        if ($base === '') {
            $counter++;
        }

        if (!isset($used[$candidate])) {
            $used[$candidate] = 1;
            return $candidate;
        }

        $used[$candidate]++;
        return $candidate . '_' . $used[$candidate];
    }
}
