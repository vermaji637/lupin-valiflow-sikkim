<?php

namespace App\Services;

use ZipArchive;

// Puts Word "Restrict Editing" on a copy of a .docx before it is downloaded.
// The file on disk that the app still edits is not changed.
class DocxDownloadProtection
{
    public function protectedCopy(string $sourcePath): string
    {
        $password = (string) config('services.docx_protection_password', '');
        if ($password === '' || ! is_file($sourcePath)) {
            return $sourcePath;
        }
        if (strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION)) !== 'docx') {
            return $sourcePath;
        }

        $dir = storage_path('app/temp');
        if (! is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $copy = $dir . DIRECTORY_SEPARATOR . 'locked-' . uniqid('', true) . '.docx';
        if (! @copy($sourcePath, $copy)) {
            return $sourcePath;
        }
        if (! $this->applyReadOnly($copy, $password)) {
            @unlink($copy);

            return $sourcePath;
        }

        return $copy;
    }

    private function applyReadOnly(string $docxPath, string $password): bool
    {
        $zip = new ZipArchive();
        if ($zip->open($docxPath) !== true) {
            return false;
        }

        $settings = $zip->getFromName('word/settings.xml');
        if (! is_string($settings) || $settings === '') {
            $zip->close();

            return false;
        }

        $salt = random_bytes(16);
        $spinCount = 100000;
        $hash = hash('sha512', $salt . mb_convert_encoding($password, 'UTF-16LE', 'UTF-8'), true);
        for ($i = 0; $i < $spinCount; $i++) {
            $hash = hash('sha512', $hash . pack('V', $i), true);
        }

        $tag = '<w:documentProtection'
            . ' w:edit="readOnly"'
            . ' w:enforcement="1"'
            . ' w:cryptProviderType="rsaAES"'
            . ' w:cryptAlgorithmClass="hash"'
            . ' w:cryptAlgorithmType="typeAny"'
            . ' w:cryptAlgorithmSid="14"'
            . ' w:cryptSpinCount="' . $spinCount . '"'
            . ' w:hash="' . base64_encode($hash) . '"'
            . ' w:salt="' . base64_encode($salt) . '"/>';

        $updated = preg_replace(
            '/<w:documentProtection\b[^>]*\/>/s',
            $tag,
            $settings,
            1,
            $replaced
        );
        if (! $replaced) {
            $updated = preg_replace(
                '/<w:documentProtection\b[^>]*>.*?<\/w:documentProtection>/s',
                $tag,
                $settings,
                1,
                $replaced
            );
        }
        if (! $replaced) {
            $updated = preg_replace('/<w:settings\b([^>]*)>/', '<w:settings$1>' . $tag, $settings, 1, $replaced);
        }
        if (! is_string($updated) || ! $replaced) {
            $zip->close();

            return false;
        }

        $zip->addFromString('word/settings.xml', $updated);
        $zip->close();

        return true;
    }
}
