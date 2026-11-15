<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

// Stores uploaded New Equipment Qualification DOCX files and uploader reference.
class NewEquipmentQualification extends Model
{
    protected $fillable = [
        'original_filename', // display name from upload
        'stored_path',       // path on public disk
        'uploaded_by',       // user who imported the file
        'source_id',         // file this edited copy was saved from
        'applied_count',     // how many Word slots Python wrote
        'edits_json',        // typed values from the preview (JSON text)
        'approval_status',   // draft, pending, returned, rejected, approved
        'display_seq',       // per-module auto-increment for list label
        'display_code',      // persisted NEQ1, NEQ2, … (roots only; not used as FK)
        'reference_document_no', // letterhead Reference Document No. (Version No.)
    ];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_id');
    }

    public function editedCopies(): HasMany
    {
        return $this->hasMany(self::class, 'source_id');
    }

    public function rootOriginal(): self
    {
        $current = $this;
        $guard = 0;
        while ($current->source_id && $guard < 25) {
            $parent = static::query()->find($current->source_id);
            if (! $parent) {
                break;
            }
            $current = $parent;
            $guard++;
        }

        return $current;
    }

    public function latestEditedCopy(): ?self
    {
        return $this->editedCopies()->orderByDesc('id')->first();
    }

    public function displayName(): string
    {
        $filename = (string) $this->original_filename;
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $ext = pathinfo($filename, PATHINFO_EXTENSION) ?: 'docx';
        $base = preg_replace('/(?:_edited(?:_\d{2}-\d{2}-\d{4}_[\w-]+|_\d{8}_\d{6})?)+$/i', '', $base);
        $base = trim((string) $base);
        if ($base === '') {
            $base = 'document';
        }

        return $base . '.' . $ext;
    }

    // Gap-free list label (NEQ1, NEQ2, …). Prefers persisted display_code on the root row.
    public function displayCode(): string
    {
        $root = empty($this->source_id) ? $this : $this->rootOriginal();
        $stored = trim((string) ($root->display_code ?? ''));
        if ($stored !== '') {
            return $stored;
        }

        $seq = (int) ($root->display_seq ?? $this->neq_seq ?? 0);
        if ($seq <= 0) {
            $seq = (int) static::query()
                ->where(function ($query) {
                    $query->whereNull('source_id')->orWhere('source_id', 0);
                })
                ->where('id', '<=', $root->id)
                ->count();
        }

        return 'NEQ' . max(1, $seq);
    }

    /**
     * Letterhead Reference Document No. / Document No. — stored on the root row.
     * Prefer Ref. Document No. (e.g. QDS-048611). Never invent from file name (PR-WBL…).
     */
    public function referenceDocumentNo(bool $persistIfFound = false): string
    {
        $root = empty($this->source_id) ? $this : $this->rootOriginal();
        $resolved = '';

        $candidates = [];
        if ($root->relationLoaded('editedCopies') && $root->editedCopies->isNotEmpty()) {
            $candidates[] = $root->editedCopies->sortByDesc('id')->first();
        } else {
            $latest = $root->latestEditedCopy();
            if ($latest) {
                $candidates[] = $latest;
            }
        }
        $candidates[] = $root;

        foreach ($candidates as $candidate) {
            if (! $candidate) {
                continue;
            }
            $resolved = static::extractReferenceDocumentNoFromEditsJson($candidate->edits_json ?? null);
            if ($resolved !== '') {
                break;
            }
        }

        // Word letterhead often already has Ref. Document No. even before a re-save.
        if ($resolved === '') {
            foreach ($candidates as $candidate) {
                if (! $candidate || empty($candidate->stored_path)) {
                    continue;
                }
                $absolute = \Illuminate\Support\Facades\Storage::disk('public')->path($candidate->stored_path);
                $resolved = static::extractReferenceDocumentNoFromDocx($absolute);
                if ($resolved !== '') {
                    break;
                }
            }
        }

        if ($resolved !== '') {
            if ($persistIfFound && $root->exists
                && trim((string) ($root->reference_document_no ?? '')) !== $resolved) {
                $root->forceFill(['reference_document_no' => $resolved])->save();
                $this->reference_document_no = $resolved;
            }

            return $resolved;
        }

        $stored = trim((string) ($root->reference_document_no ?? ''));
        if ($stored !== '' && ! static::isPlausibleDocumentNumber($stored)) {
            if ($persistIfFound && $root->exists) {
                $root->forceFill(['reference_document_no' => null])->save();
                $this->reference_document_no = null;
            }

            return '';
        }

        return $stored;
    }

    /**
     * Persist Reference Document No. from save payloads / DOCX — never from file name.
     */
    public function syncReferenceDocumentNo(?string $value = null, ?string $editsJson = null): void
    {
        $root = empty($this->source_id) ? $this : $this->rootOriginal();
        $resolved = trim((string) ($value ?? ''));
        if ($resolved === '' && $editsJson !== null) {
            $resolved = static::extractReferenceDocumentNoFromEditsJson($editsJson);
        }
        if ($resolved === '') {
            $source = $root->latestEditedCopy() ?: $root;
            if (! empty($source->stored_path)) {
                $absolute = \Illuminate\Support\Facades\Storage::disk('public')->path($source->stored_path);
                $resolved = static::extractReferenceDocumentNoFromDocx($absolute);
            }
        }
        if ($resolved === '' || ! static::isPlausibleDocumentNumber($resolved)) {
            return;
        }

        $root->forceFill(['reference_document_no' => $resolved])->save();
        $this->reference_document_no = $resolved;
    }

    /**
     * True for real letterhead Document No. values (e.g. QDS-0477 (0.1), QDS-048611).
     * Rejects checklist headers and Equipment ID style PR-WBL-048 / PR-WBL-048 (1).
     */
    public static function isPlausibleDocumentNumber(string $text): bool
    {
        $t = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($t === '' || mb_strlen($t) > 400) {
            return false;
        }
        $lower = mb_strtolower($t);
        $junk = [
            'check', 'method', 'acceptance criteria', 'observation/result',
            'observation / result', 'observation/ result', 'sign and date',
            'version', 'document no', 'document no.', 'document number',
            '—', '-', 'n/a', 'na',
        ];
        if (in_array($lower, $junk, true)) {
            return false;
        }
        if (preg_match('/^(check|method|acceptance|observation|sign\s+and\s+date|version)\b/i', $t)) {
            return false;
        }
        // The cell still says the label, not a number the user typed.
        if (static::isDocumentNumberLabelOnly($t)) {
            return false;
        }
        // Equipment ID mistaken as Document No. (filename), including "PR-WBL-048 (1)".
        if (static::looksLikeEquipmentId($t)) {
            return false;
        }

        return true;
    }

    /**
     * True when the cell still contains only the field name, such as
     * "Document No. (Version No.)". A typed value that happens to include
     * those words ("vijay document no test") is a real number.
     */
    public static function isDocumentNumberLabelOnly(string $text): bool
    {
        $t = trim(preg_replace('/\s+/u', ' ', mb_strtolower($text)) ?? '');
        $t = trim($t, " .:");

        return (bool) preg_match(
            '/^(?:reference\s+|ref\.?\s+)?document\s*(?:no\.?|number)(?:\s*\(\s*version\s*no\.?\s*\))?$/',
            $t
        );
    }

    /**
     * Equipment ID patterns: PR-WBL-048, PR-WBL-048 (1), PR11-WBL-048.
     */
    public static function looksLikeEquipmentId(string $text): bool
    {
        $t = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($t === '') {
            return false;
        }

        // Pure equipment id, optional revision in parentheses.
        if (preg_match('/^PR\d*-[A-Z0-9]+-\d+(?:\s*\(\d+\))?$/i', $t)) {
            return true;
        }
        // Filename-style leftover: only PR-… with optional (n), no QDS/ANNEX/SOP prefix.
        if (preg_match('/^PR\d*-[A-Z0-9]+-\d+/i', $t)
            && ! preg_match('/\b(QDS|ANNEX|SOP|IOQ|VAL|DOC)[-_]?\d/i', $t)) {
            return true;
        }

        return false;
    }

    /**
     * Strong protocol / annex document codes (preferred for the list column).
     */
    public static function looksLikeProtocolDocumentCode(string $text): bool
    {
        $t = trim($text);
        if ($t === '' || static::looksLikeEquipmentId($t)) {
            return false;
        }

        return (bool) preg_match('/\b(QDS|ANNEX|SOP|IOQ|VAL)[-_]?\d/i', $t);
    }

    /**
     * Weak / accidental Document No. cells (e.g. "11" from garbled edits).
     */
    public static function isWeakDocumentNumber(string $text): bool
    {
        $t = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($t === '') {
            return true;
        }
        if (static::looksLikeProtocolDocumentCode($t)) {
            return false;
        }
        // Very short numeric / fragment — not a real document number.
        if (preg_match('/^\d{1,3}$/', $t)) {
            return true;
        }
        if (mb_strlen($t) <= 2) {
            return true;
        }

        return false;
    }

    /**
     * @deprecated Filename must not supply Document No. (PR-WBL is Equipment ID).
     */
    public static function extractReferenceDocumentNoFromFilename(string $filename): string
    {
        return '';
    }

    /**
     * Read Ref. Document No. / Document No. directly from a DOCX letterhead.
     */
    public static function extractReferenceDocumentNoFromDocx(string $absolutePath): string
    {
        if ($absolutePath === '' || ! is_file($absolutePath)) {
            return '';
        }

        try {
            $zip = new \ZipArchive();
            if ($zip->open($absolutePath) !== true) {
                return '';
            }
            $headers = [];
            $others = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                $xml = $zip->getFromIndex($i);
                if (! is_string($xml) || $xml === '') {
                    continue;
                }
                if (str_starts_with($name, 'word/header')) {
                    $headers[] = $xml;
                } elseif ($name === 'word/document.xml' || str_starts_with($name, 'word/footer')) {
                    $others[] = $xml;
                }
            }
            $zip->close();
        } catch (\Throwable $e) {
            report($e);

            return '';
        }

        if ($headers === [] && $others === []) {
            return '';
        }

        $toPlain = static function (array $xmlParts): string {
            $plain = '';
            foreach ($xmlParts as $xml) {
                $chunk = preg_replace('/<\/w:p>/i', "\n", $xml) ?? $xml;
                $chunk = preg_replace('/<w:tab\b[^>]*\/>/i', ' ', $chunk) ?? $chunk;
                $chunk = strip_tags($chunk);
                $chunk = html_entity_decode($chunk, ENT_QUOTES | ENT_XML1, 'UTF-8');
                $plain .= "\n" . $chunk;
            }

            return preg_replace('/[ \t]+/u', ' ', $plain) ?? $plain;
        };

        $headerPlain = $toPlain($headers);
        $plain = $headerPlain . "\n" . $toPlain($others);

        $patterns = [
            // Attachment-6: Ref. Document No.   QDS-0486
            '/Ref\.?\s*Document\s*No\.?\s*[:\.]?\s*(QDS[-_]?\d+[A-Z0-9]*(?:\s*\([^)]+\))?)/i',
            '/Reference\s*Document\s*No\.?\s*(?:\(\s*Version\s*No\.?\s*\))?\s*[:\.]?\s*(QDS[-_]?\d+[A-Z0-9]*(?:\s*\([^)]+\))?)/i',
            '/Reference\s*Document\s*No\.?\s*(?:\(\s*Version\s*No\.?\s*\))?\s*[:\.]?\s*((?:ANNEX|SOP|IOQ|VAL)[-_]?\d+[A-Z0-9]*(?:\s*\([^)]+\))?)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $plain, $match)) {
                $value = trim(preg_replace('/\s+/u', ' ', $match[1]) ?? $match[1]);
                if (static::isPlausibleDocumentNumber($value)) {
                    return $value;
                }
            }
        }

        // Free-text value beside Ref. Document No.
        $referenceValue = static::captureLabeledHeaderValue(
            $headerPlain,
            '(?:Ref(?:erence)?\.?\s+)Document\s*No\.?'
        );
        if ($referenceValue !== '') {
            return $referenceValue;
        }

        // Attachment 6 has both labels. Document No. must not fill the list column.
        $hasReferenceLabel = (bool) preg_match('/\bref\.?\s*document\s*no\b/i', $headerPlain)
            || (bool) preg_match('/\breference\s*document\s*no\b/i', $headerPlain);
        if ($hasReferenceLabel) {
            return '';
        }

        // Attachment 4 and similar letterheads only have Document No. (Version No.).
        return static::captureLabeledHeaderValue(
            $headerPlain,
            '(?<![A-Za-z.])Document\s*No\.?'
        );
    }

    /**
     * Value on the line after a letterhead label. Skips a lone "(Version No.)" line.
     */
    private static function captureLabeledHeaderValue(string $headerPlain, string $labelBody): string
    {
        if (! preg_match('/' . $labelBody . '/i', $headerPlain, $match, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        $after = substr($headerPlain, $match[0][1] + strlen($match[0][0]));
        $lines = preg_split('/\R/u', $after) ?: [];
        foreach ($lines as $line) {
            $value = trim(preg_replace('/\s+/u', ' ', $line) ?? $line);
            $value = trim($value, " \t:.");
            if ($value === '' || preg_match('/^\(\s*version\s*no\.?\s*\)$/i', $value)) {
                continue;
            }
            if (! static::isPlausibleDocumentNumber($value)) {
                return '';
            }
            if (preg_match('/^(document\s*(?:title|no)|equipment|facilit|version|lupin|annex|check|method|ref\.?\s*document|reference\s*document)\b/i', $value)) {
                return '';
            }

            return $value;
        }

        return '';
    }

    /**
     * Pull Document No. from cell_edits / edits saved with the DOCX preview.
     */
    public static function extractReferenceDocumentNoFromEditsJson(?string $editsJson): string
    {
        if ($editsJson === null || trim($editsJson) === '') {
            return '';
        }

        $payload = json_decode($editsJson, true);
        if (! is_array($payload)) {
            return '';
        }

        return static::extractReferenceDocumentNoFromPayload($payload);
    }

    /**
     * The imported-documents column is Ref. Document No. only.
     * Document No. is a separate letterhead field and is not used here.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function extractReferenceDocumentNoFromPayload(array $payload): string
    {
        $pools = [];
        foreach (['cell_edits', 'edits'] as $key) {
            if (! empty($payload[$key]) && is_array($payload[$key])) {
                $pools[] = $payload[$key];
            }
        }
        if ($pools === [] && (isset($payload['label']) || isset($payload['text']))) {
            $pools[] = [$payload];
        }

        $referenceNo = '';

        foreach ($pools as $items) {
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $label = strtolower(trim((string) ($item['label'] ?? '')));
                if ($label === '' || str_contains($label, 'as per document')) {
                    continue;
                }
                $isReference = str_contains($label, 'reference document')
                    || (bool) preg_match('/\bref\.?\s*document\b/', $label);
                if (! $isReference) {
                    continue;
                }

                $text = trim((string) ($item['text'] ?? $item['saved_text'] ?? ''));
                $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
                if (! static::isPlausibleDocumentNumber($text) || static::isDocumentNumberLabelOnly($text)) {
                    continue;
                }
                if ($referenceNo === '') {
                    $referenceNo = $text;
                }
            }
        }

        return $referenceNo;
    }

    /**
     * Human-readable identity for notifications/emails, e.g. "QDS-0477 (NEQ44)".
     */
    public function documentIdentityLabel(): string
    {
        $root = empty($this->source_id) ? $this : $this->rootOriginal();
        $code = $root->displayCode();
        $ref = $root->referenceDocumentNo();
        if ($ref !== '') {
            return $ref . ' (' . $code . ')';
        }

        return $code;
    }

    /**
     * Assign NEQ display_code on root uploads only (edited copies keep null and inherit via displayCode()).
     */
    public function assignDisplayCodeIfNeeded(): void
    {
        if (! empty($this->source_id)) {
            return;
        }
        if (trim((string) ($this->display_code ?? '')) !== '' && (int) ($this->display_seq ?? 0) > 0) {
            return;
        }

        DB::transaction(function () {
            $locked = static::query()->whereKey($this->id)->lockForUpdate()->first();
            if (! $locked || ! empty($locked->source_id)) {
                return;
            }
            if (trim((string) ($locked->display_code ?? '')) !== '' && (int) ($locked->display_seq ?? 0) > 0) {
                $this->display_seq = $locked->display_seq;
                $this->display_code = $locked->display_code;

                return;
            }

            $next = ((int) static::query()
                ->where(function ($query) {
                    $query->whereNull('source_id')->orWhere('source_id', 0);
                })
                ->whereNotNull('display_seq')
                ->lockForUpdate()
                ->max('display_seq')) + 1;
            $code = 'NEQ' . $next;

            $locked->forceFill([
                'display_seq' => $next,
                'display_code' => $code,
            ])->save();

            $this->display_seq = $next;
            $this->display_code = $code;
        });
    }

    public function fieldValues(): HasMany
    {
        return $this->hasMany(NewEquipmentQualificationFieldValue::class, 'new_equipment_qualification_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(NewEquipmentQualificationApproval::class, 'new_equipment_qualification_id');
    }

    public function assignedApprovalSteps()
    {
        $root = $this->source_id ? $this->rootOriginal() : $this;
        $steps = $root->relationLoaded('approvals')
            ? $root->approvals
            : $root->approvals()->orderBy('step_order')->get();

        return $steps->filter(function ($step) {
            return ! empty($step->user_id);
        })->values();
    }

    public function workflowStatus(): string
    {
        $root = $this->source_id ? $this->rootOriginal() : $this;
        $stored = strtolower(trim((string) ($root->approval_status ?? '')));
        if (in_array($stored, ['draft', 'pending', 'returned', 'rejected', 'approved'], true)) {
            return $stored;
        }

        $steps = $root->assignedApprovalSteps();
        if ($steps->isEmpty()) {
            return 'draft';
        }
        if ($steps->contains(function ($step) {
            return ! is_null($step->rejected_at);
        })) {
            return 'rejected';
        }
        if ($steps->every(function ($step) {
            return ! is_null($step->approved_at);
        })) {
            return 'approved';
        }

        return 'pending';
    }

    public function listStatusKey(): string
    {
        $status = $this->workflowStatus();
        if (in_array($status, ['pending', 'returned', 'rejected', 'approved'], true)) {
            return $status;
        }

        $hasEdit = $this->relationLoaded('editedCopies')
            ? $this->editedCopies->isNotEmpty()
            : $this->editedCopies()->exists();

        return $hasEdit ? 'edited' : 'original';
    }

    public function isFullyApproved(): bool
    {
        $root = $this->source_id ? $this->rootOriginal() : $this;
        if (($root->approval_status ?? '') === 'rejected') {
            return false;
        }

        $steps = $root->assignedApprovalSteps();

        return $steps->isNotEmpty() && $steps->every(function ($step) {
            return ! is_null($step->approved_at);
        });
    }
}
