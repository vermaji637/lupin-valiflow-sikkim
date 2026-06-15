<?php

namespace App\Services;

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Html;
use RuntimeException;

class NewEquipmentQualificationDocxService
{
    public function createDocxFromHtml(string $html): string
    {
        $html = $this->prepareHtml($html);

        $phpWord = new PhpWord();
        $section = $phpWord->addSection();

        Html::addHtml($section, $html, false, false);

        $tempFile = tempnam(sys_get_temp_dir(), 'neq_docx_');
        if ($tempFile === false) {
            throw new RuntimeException('Unable to create a temporary file.');
        }

        $docxPath = $tempFile . '.docx';
        @unlink($tempFile);

        IOFactory::createWriter($phpWord, 'Word2007')->save($docxPath);

        return $docxPath;
    }

    private function prepareHtml(string $html): string
    {
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? $html;
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html) ?? $html;
        $html = preg_replace('/\scontenteditable="[^"]*"/i', '', $html) ?? $html;
        $html = preg_replace('/\scontenteditable/i', '', $html) ?? $html;

        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="neq-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('neq-root');
        if (! $root) {
            throw new RuntimeException('Unable to parse edited preview HTML.');
        }

        $sanitized = '';
        foreach ($root->childNodes as $child) {
            $sanitized .= $document->saveHTML($child);
        }

        return $sanitized;
    }
}
