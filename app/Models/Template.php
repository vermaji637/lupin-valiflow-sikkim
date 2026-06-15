<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\LogsActivity;

class Template extends Model
{
    use LogsActivity;

    protected $fillable = [
        'name',
        'template_type',
        'title',
        'body_html',
        'docx_template_path',
        'docx_placeholders',
        'starter_html',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'docx_placeholders' => 'array',
    ];

    protected $appends = [
        'template_type_label',
    ];

    /**
     * A template has many reports
     */
    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    /**
     * Optional: creator relationship (if using users table)
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getTemplateTypeLabelAttribute(): string
    {
        return match ($this->template_type) {
            'dq' => 'DQ',
            'ioq' => 'IOQ',
            'pq' => 'PQ',
            'facility' => 'Facility',
            'pqr' => 'Protocol Report',
            'docx' => 'DOCX Dynamic',
            default => 'Legacy',
        };
    }
}
