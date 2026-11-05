<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use App\Traits\LogsActivity;

// Eloquent model for protocol reports, approval workflow state, and DOCX import metadata.
class Report extends Model
{
    use LogsActivity;

    protected $fillable = [
        // Template and rendered content
        'template_id',
        'templatedata',
        'data',
        'rendered_html',
        'created_by',
        // Document / equipment metadata
        'facility_section',
        'document_no',
        'version',
        'equipment_name',
        'equipment_id',
        'attachments',
        // Approval workflow state
        'stage',
        'status',
        'current_step_order',
        // DOCX import paths and parsed field payload
        'docx_import_path',
        'docx_import_fields',
        'docx_filled_path',
        'docx_import_status',
        // Persisted list label (NEQ1 / REV2 / REQ3) — never used as FK
        'display_seq',
        'display_code',
    ];

    protected $casts = [
        'templatedata' => 'array',       // snapshot of form data at creation
        'data' => 'array',               // live form values including approval stamps
        'attachments'  => 'array',
        'docx_import_fields' => 'array', // parsed DOCX cell map from Python service
    ];

    // Ordered PRE/POST approval steps assigned to users
    public function approvalSteps()
    {
        return $this->hasMany(\App\Models\ReportApprovalStep::class);

    }
    /**
     * A report belongs to a template
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }
    // Pending steps for the report's current stage, in workflow order
     public function activeStepQuery()
    {
        return $this->approvalSteps()
            ->where('stage', $this->stage)
            ->whereNull('approved_at')
            ->whereNull('rejected_at')
            ->orderBy('step_order');
    }

    /**
     * Optional: submitted by user
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
    // Linked approved document master record (document_no stores document id)
   public function document()
{
    return $this->belongsTo(\App\Models\Document::class, 'document_no', 'id');
}

    /**
     * Sidebar import source shown on dashboard instead of "VIVA DOCX Import".
     */
    public static function importSourceLabels(): array
    {
        return [
            'new_equipment_qualification' => 'New Equipment Qualification',
            'revalidation' => 'Revalidation',
            'requalification' => 'Requalification',
        ];
    }

    /**
     * Sidebar / create-page section titles (fill entry points).
     */
    public static function importSourceSectionTitles(): array
    {
        return [
            'new_equipment_qualification' => 'Fill New Equipment Qualification',
            'revalidation' => 'Revalidation',
            'requalification' => 'Requalification',
        ];
    }

    public static function importSourceLabel(?string $source): string
    {
        $source = strtolower(trim((string) $source));

        return self::importSourceLabels()[$source] ?? '';
    }

    public static function importSourceSectionTitle(?string $source): string
    {
        $source = strtolower(trim((string) $source));

        return self::importSourceSectionTitles()[$source]
            ?? self::importSourceLabel($source);
    }

    /** List ID prefix by import source: NEQ / REV / REQ. */
    public static function importSourceDisplayPrefix(?string $source): string
    {
        $source = strtolower(trim((string) $source));

        return match ($source) {
            'new_equipment_qualification' => 'NEQ',
            'revalidation' => 'REV',
            'requalification' => 'REQ',
            default => '',
        };
    }

    /**
     * Gap-free display code for All Protocol list (NEQ1, REV2, REQ1, …).
     * Prefers persisted display_code; falls back to runtime rank for legacy rows.
     */
    public function displayCode(): string
    {
        $stored = trim((string) ($this->display_code ?? ''));
        if ($stored !== '') {
            return $stored;
        }

        $source = $this->import_source;
        $prefix = self::importSourceDisplayPrefix($source);
        if ($prefix === '') {
            return (string) $this->id;
        }

        $seq = (int) ($this->display_seq ?? $this->protocol_display_seq ?? 0);
        if ($seq <= 0) {
            $driver = $this->getConnection()->getDriverName();
            $query = static::query()->where('id', '<=', $this->id);
            if ($driver === 'sqlsrv') {
                $query->whereRaw(
                    "LOWER(JSON_VALUE(CAST([data] AS nvarchar(max)), '$.import_source')) = ?",
                    [strtolower($source)]
                );
            } else {
                $query->where('data->import_source', $source);
            }
            $seq = (int) $query->count();
        }

        return $prefix . max(1, $seq);
    }

    /**
     * Replace legacy "Report #13" style text with persisted display_code (REQ5 / REV2 / …).
     */
    public static function rewriteIdsInText(string $text, ?self $report = null, ?string $displayCode = null, ?int $reportId = null): string
    {
        $code = trim((string) $displayCode);
        if ($code === '' && $report) {
            $code = $report->displayCode();
            $reportId = $reportId ?: (int) $report->id;
        }
        if ($code === '' && $reportId) {
            $found = static::query()->find($reportId);
            if ($found) {
                $code = $found->displayCode();
            }
        }
        if ($code === '') {
            return $text;
        }

        $out = preg_replace('/Report\s*#\s*\d+/i', $code, $text) ?? $text;
        if ($reportId) {
            $out = preg_replace('/\bReport\s*#?\s*' . preg_quote((string) $reportId, '/') . '\b/i', $code, $out) ?? $out;
        }

        return $out;
    }

    /**
     * Assign and persist NEQ/REV/REQ display_code once (safe no-op if already set or no import source).
     */
    public function assignDisplayCodeIfNeeded(): void
    {
        if (trim((string) ($this->display_code ?? '')) !== '' && (int) ($this->display_seq ?? 0) > 0) {
            return;
        }

        $source = strtolower(trim((string) $this->import_source));
        $prefix = self::importSourceDisplayPrefix($source);
        if ($prefix === '') {
            return;
        }

        DB::transaction(function () use ($source, $prefix) {
            $locked = static::query()->whereKey($this->id)->lockForUpdate()->first();
            if (! $locked) {
                return;
            }
            if (trim((string) ($locked->display_code ?? '')) !== '' && (int) ($locked->display_seq ?? 0) > 0) {
                $this->display_seq = $locked->display_seq;
                $this->display_code = $locked->display_code;

                return;
            }

            $driver = $this->getConnection()->getDriverName();
            $maxQuery = static::query()->whereNotNull('display_seq');
            if ($driver === 'sqlsrv') {
                $maxQuery->whereRaw(
                    "LOWER(JSON_VALUE(CAST([data] AS nvarchar(max)), '$.import_source')) = ?",
                    [$source]
                );
            } else {
                $maxQuery->where('data->import_source', $source);
            }
            $next = ((int) $maxQuery->lockForUpdate()->max('display_seq')) + 1;
            $code = $prefix . $next;

            $locked->forceFill([
                'display_seq' => $next,
                'display_code' => $code,
            ])->save();

            $this->display_seq = $next;
            $this->display_code = $code;
        });
    }

    public function getImportSourceAttribute(): string
    {
        $data = $this->data ?? [];
        if (is_string($data)) {
            $data = json_decode($data, true) ?? [];
        }
        if (! is_array($data)) {
            $data = [];
        }

        return strtolower(trim((string) ($data['import_source'] ?? '')));
    }

    /**
     * Protocol name for lists: fill Protocol/Document Title first, then import source, then template.
     */
    public function getProtocolTitleAttribute(): string
    {
        $data = $this->data ?? [];
        if (is_string($data)) {
            $data = json_decode($data, true) ?? [];
        }
        if (! is_array($data)) {
            $data = [];
        }

        $title = trim((string) ($data['document_title'] ?? ''));
        if ($title !== '') {
            return $title;
        }

        $sourceLabel = self::importSourceLabel($this->import_source);
        if ($sourceLabel !== '') {
            return $sourceLabel;
        }

        return trim((string) ($this->template->name ?? $this->template->title ?? ''));
    }

    /**
     * Check if report belongs to Requalification or Revalidation flow.
     */
    public function isRequalification(): bool
    {
        return in_array($this->import_source, ['requalification', 'revalidation'], true);
    }
}
