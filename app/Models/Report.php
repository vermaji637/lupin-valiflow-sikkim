<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\LogsActivity;

class Report extends Model
{
    use LogsActivity;

    protected $fillable = [
        'template_id',
        'templatedata',
        'data',
        'rendered_html',
        'created_by',
        'facility_section',
        'document_no',
        'version',
        'equipment_name',
        'equipment_id',
        'attachments',
        'stage',
        'status',
        'current_step_order',
        'docx_import_path',
        'docx_import_fields',
        'docx_filled_path',
        'docx_import_status'
    ];

    protected $casts = [
        'templatedata' => 'array',
        'data' => 'array',
        'attachments'  => 'array', // automatically convert JSON to array
        'docx_import_fields' => 'array',
    ];

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
   public function document()
{
    return $this->belongsTo(\App\Models\Document::class, 'document_no', 'id');
}
}
