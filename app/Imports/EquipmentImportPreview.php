<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class EquipmentImportPreview implements ToCollection, WithHeadingRow
{
    public Collection $rows;

    public function __construct()
    {
        $this->rows = collect();
    }

    public function collection(Collection $rows): void
    {
        $this->rows = $rows;
    }
}
