<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\LogsActivity;

class FormTemplate extends Model
{
    use LogsActivity;

    protected $fillable = [
        'form_key',
        'schema_json',
    ];

    protected $casts = [
        'schema_json' => 'array',
    ];

    public function schema(): array
    {
        return $this->schema_json ?? [];
    }
}
