<?php

namespace App\Services;

use App\Models\NewEquipmentQualification;

/**
 * Audit trail for New Equipment Qualification send-back / reject (with note).
 */
class NeqAuditLogger
{
    public static function recordDecision(
        NewEquipmentQualification $root,
        string $decision,
        ?string $remark = null,
        ?string $actorName = null
    ): void {
        $root = $root->rootOriginal();
        $ref = self::documentRef($root);
        $note = trim((string) $remark);
        $isSendBack = $decision === 'sent_back';
        $action = $isSendBack ? 'sent_back' : 'rejected';
        $verb = $isSendBack ? 'Sent back' : 'Rejected';

        $description = "{$verb} {$ref}";
        if ($actorName) {
            $description .= ' by ' . $actorName;
        }
        if ($note !== '') {
            $description .= ' — Note: ' . mb_substr($note, 0, 500);
        }

        AuditLogger::record('rejected', [
            'auditable_type' => NewEquipmentQualification::class,
            'auditable_id'   => $root->id,
            'new_values'     => array_filter([
                '_audit_action'          => $action,
                '_audit_description'     => $description,
                '_audit_document_ref'    => $ref,
                '_audit_workflow_status' => $isSendBack
                    ? 'Sent Back — Returned for Correction'
                    : 'Rejected — Closed',
                'approval_status'        => $isSendBack ? 'returned' : 'rejected',
                'remark'                 => $note !== '' ? mb_substr($note, 0, 500) : null,
                'decision'               => $decision,
            ], static fn ($value) => $value !== null && $value !== ''),
        ]);
    }

    public static function documentRef(NewEquipmentQualification $root): string
    {
        $name = trim((string) ($root->original_filename ?? ''));
        if ($name === '') {
            $latest = $root->latestEditedCopy();
            $name = trim((string) ($latest?->original_filename ?? ''));
        }

        $parts = array_filter([
            $name !== '' ? $name : null,
            'NEQ #' . $root->id,
        ]);

        return implode(' — ', $parts);
    }
}
