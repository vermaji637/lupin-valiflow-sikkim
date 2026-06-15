<?php

namespace App\Imports;

use App\Models\Equipment;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithUpserts;

class EquipmentImport implements ToModel, WithHeadingRow, WithUpserts
{
    public function __construct(
        private ?int $approverUserId = null
    ) {}

    public function uniqueBy()
    {
        return 'equipment_id';
    }

    public function model(array $row)
    {
        if (Equipment::where('equipment_id', $row['equipment_id'])->exists()) {
            return null;
        }

        $approverValue = null;

        if ($this->approverUserId !== null) {
            $approverValue = (string) $this->approverUserId;
        } else {
            $approverRaw = trim((string) ($row['approver_username'] ?? ''));
            $approverValue = $approverRaw;

            if ($approverRaw !== '') {
                if (is_numeric($approverRaw) && User::query()->whereKey((int) $approverRaw)->exists()) {
                    $approverValue = (string) ((int) $approverRaw);
                } elseif (filter_var($approverRaw, FILTER_VALIDATE_EMAIL)) {
                    $matchedUserId = User::query()
                        ->whereRaw('LOWER(email) = ?', [strtolower($approverRaw)])
                        ->value('id');
                    if ($matchedUserId) {
                        $approverValue = (string) $matchedUserId;
                    }
                }
            }
        }

        return new Equipment([
            'equipment_name'    => $row['equipment_name'] ?? null,
            'equipment_id'      => $row['equipment_id'] ?? null,
            'facility'          => $row['facility'] ?? null,
            'approver_username' => $approverValue !== '' ? $approverValue : null,
            'created_by'        => Auth::id(),
            'approval_status'   => 'pending',
        ]);
    }
}
