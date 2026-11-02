<?php

namespace App\Services;

use App\Models\Report;

/**
 * Records human-readable protocol / DOCX workflow actions in the audit trail.
 */
class ProtocolAuditLogger
{
    public static function documentRef(Report $report): string
    {
        $report->loadMissing('document');

        $parts = [];

        $docxNumber = self::resolveDocxNumber($report);
        if ($docxNumber !== '') {
            $parts[] = 'DOCX No. ' . $docxNumber;
        }

        if (! empty($report->equipment_id)) {
            $parts[] = trim((string) $report->equipment_id);
        }

        if ($report->document) {
            $title = trim((string) ($report->document->doc_title ?? $report->document->title ?? ''));
            if ($title !== '') {
                $parts[] = $title;
            }
        } elseif (! empty($report->equipment_name)) {
            $parts[] = trim((string) $report->equipment_name);
        }

        $parts[] = 'Protocol #' . $report->id;

        return implode(' — ', array_values(array_unique(array_filter($parts))));
    }

    /**
     * Human-readable DOCX / document number from parsed import fields or linked document.
     */
    public static function resolveDocxNumber(Report $report): string
    {
        $fields = $report->docx_import_fields ?? [];
        if (is_array($fields)) {
            foreach ($fields as $field) {
                if (! is_array($field)) {
                    continue;
                }

                $coverField = (string) ($field['cover_field'] ?? '');
                if (in_array($coverField, ['document_no', 'header_doc_number'], true)) {
                    $value = trim((string) ($field['value'] ?? ''));
                    if ($value !== '') {
                        return $value;
                    }
                }

                $location = (string) ($field['location'] ?? '');
                if ($location === 'header_0_row_4_col_2') {
                    $value = trim((string) ($field['value'] ?? ''));
                    if ($value !== '') {
                        return $value;
                    }
                }
            }
        }

        $data = $report->data ?? [];
        if (is_string($data)) {
            $data = json_decode($data, true) ?? [];
        }
        if (is_array($data)) {
            foreach (['document_no', 'header_doc_number', 'document_id'] as $key) {
                $value = trim((string) ($data[$key] ?? ''));
                if ($value !== '' && ! is_numeric($value)) {
                    return $value;
                }
            }
        }

        $report->loadMissing('document');
        if ($report->document) {
            $title = trim((string) ($report->document->doc_title ?? $report->document->title ?? ''));
            if ($title !== '') {
                return $title;
            }
        }

        return '';
    }

    public static function workflowStatusLabel(?string $status): string
    {
        $map = [
            'post'          => 'Post-Approval Stage',
            'pre'           => 'Pre-Approval Stage',
            'post_pending'  => 'Pending Approval',
            'pre_pending'   => 'Pending Review',
            'post_returned' => 'Sent Back — Returned for Correction',
            'pre_returned'  => 'Sent Back — Returned for Correction',
            'approved'      => 'Approved — Fully Completed',
            'rejected'      => 'Rejected — Closed',
            'completed'     => 'Completed',
            'draft'         => 'Draft',
            'in_progress'   => 'In Progress',
            'pre_approved'  => 'Pre-Approved — Ready for Post Stage',
            'pending'       => 'Pending',
        ];

        $key = strtolower(trim((string) $status));

        return $map[$key] ?? ($status !== null && $status !== '' ? ucwords(str_replace('_', ' ', $key)) : '—');
    }

    public static function recordImported(Report $report, ?string $filename = null): void
    {
        $ref = self::documentRef($report);

        AuditLogger::record('imported', [
            'auditable_type' => Report::class,
            'auditable_id'   => $report->id,
            'new_values'     => self::baseMeta($report, 'imported', "Imported DOCX Document ({$ref})", [
                'workflow_status' => self::workflowStatusLabel($report->status),
                'filename'        => $filename,
            ]),
        ]);
    }

    public static function recordFormSubmitted(Report $report): void
    {
        $ref = self::documentRef($report);

        AuditLogger::record('fill_with_docx', [
            'auditable_type' => Report::class,
            'auditable_id'   => $report->id,
            'new_values'     => self::baseMeta($report, 'fill_with_docx', "Filled DOCX Form and Submitted for Approval ({$ref})", [
                'workflow_status' => self::workflowStatusLabel($report->status),
            ]),
        ]);
    }

    public static function recordApproved(Report $report, ?string $stepLabel = null, bool $isFinal = false): void
    {
        $ref = self::documentRef($report);
        $statusText = $isFinal
            ? ($report->stage === 'pre'
                ? 'Pre-Approved — Ready for Post Stage'
                : 'Approved — Fully Completed')
            : 'Approved — Moved to Next Step';

        $stepText = $stepLabel ? self::humanizeStepLabel($stepLabel) : 'Approval Step';

        AuditLogger::record('approved', [
            'auditable_type' => Report::class,
            'auditable_id'   => $report->id,
            'new_values'     => self::baseMeta($report, 'approved', "Approved {$ref} ({$stepText})", [
                'workflow_status' => $statusText,
                'step_label'      => $stepLabel,
                'is_final'        => $isFinal,
            ]),
        ]);
    }

    public static function recordRejected(Report $report, ?string $remark = null): void
    {
        $ref = self::documentRef($report);

        AuditLogger::record('rejected', [
            'auditable_type' => Report::class,
            'auditable_id'   => $report->id,
            'new_values'     => self::baseMeta($report, 'rejected', (
                in_array($report->status, ['pre_returned', 'post_returned'], true)
                    ? "Sent back {$ref}"
                    : "Rejected {$ref}"
            ), [
                'workflow_status' => self::workflowStatusLabel($report->status),
                'remark'          => $remark ? mb_substr(trim($remark), 0, 200) : null,
            ]),
        ]);
    }

    public static function recordExported(Report $report, string $format): void
    {
        $ref  = self::documentRef($report);
        $type = strtoupper($format);

        AuditLogger::record('exported', [
            'auditable_type' => Report::class,
            'auditable_id'   => $report->id,
            'new_values'     => self::baseMeta($report, 'exported', "Exported {$type} — {$ref}", [
                'export_format' => strtolower($format),
            ]),
        ]);
    }

    public static function recordFillWithDocx(Report $target, Report $source, int $filledCount): void
    {
        $targetRef  = self::documentRef($target);
        $sourceRef  = self::documentRef($source);
        $targetDocx = self::resolveDocxNumber($target);
        $sourceDocx = self::resolveDocxNumber($source);

        $fromLabel = $sourceDocx !== ''
            ? 'DOCX No. ' . $sourceDocx . ' (' . $sourceRef . ')'
            : $sourceRef;
        $intoLabel = $targetDocx !== ''
            ? 'DOCX No. ' . $targetDocx . ' (' . $targetRef . ')'
            : $targetRef;

        $description = sprintf(
            'Fill with DOCX — copied %d field(s) from %s into %s',
            $filledCount,
            $fromLabel,
            $intoLabel
        );

        AuditLogger::record('docx_autofill', [
            'auditable_type' => Report::class,
            'auditable_id'   => $target->id,
            'new_values'     => self::baseMeta($target, 'docx_autofill', $description, [
                'workflow_status'    => self::workflowStatusLabel($target->status),
                'source_report_id' => $source->id,
                'source_docx_number' => $sourceDocx !== '' ? $sourceDocx : null,
                'target_docx_number' => $targetDocx !== '' ? $targetDocx : null,
                'filled_count'       => $filledCount,
            ]),
        ]);
    }

    protected static function baseMeta(Report $report, string $action, string $description, array $extra = []): array
    {
        $docxNumber = self::resolveDocxNumber($report);

        return array_filter(array_merge([
            '_audit_action'          => $action,
            '_audit_description'     => $description,
            '_audit_document_ref'    => self::documentRef($report),
            '_audit_docx_number'     => $docxNumber !== '' ? $docxNumber : null,
            '_audit_workflow_status' => $extra['workflow_status'] ?? self::workflowStatusLabel($report->status),
            'report_id'              => $report->id,
            'equipment_id'           => $report->equipment_id,
            'equipment_name'         => $report->equipment_name,
            'stage'                  => $report->stage,
            'status'                 => $report->status,
            'docx_number'            => $docxNumber !== '' ? $docxNumber : null,
        ], $extra), static fn ($value) => $value !== null && $value !== '');
    }

    protected static function humanizeStepLabel(?string $label): string
    {
        $label = strtoupper(trim((string) $label));

        return match ($label) {
            'POST_PREPARED'    => 'Prepared By',
            'POST_ENGINEERING' => 'Engineering Review',
            'POST_PRODUCTION'  => 'Production Review',
            'POST_VALIDATION'  => 'Validation Review',
            'POST_QUALITY'     => 'Quality Assurance Approval',
            default            => ucwords(strtolower(str_replace('_', ' ', $label))),
        };
    }
}
