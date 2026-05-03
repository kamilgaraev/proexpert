<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ReportTemplate extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const REPORT_TYPE_MATERIAL_USAGE = 'material_usage';
    public const REPORT_TYPE_WORK_COMPLETION = 'work_completion';
    public const REPORT_TYPE_FOREMAN_ACTIVITY = 'foreman_activity';
    public const REPORT_TYPE_PROJECT_STATUS_SUMMARY = 'project_status_summary';
    public const REPORT_TYPE_CONTRACTOR_SUMMARY = 'contractor_summary';
    public const REPORT_TYPE_CONTRACTOR_DETAIL = 'contractor_detail';

    protected $table = 'report_templates';

    protected $fillable = [
        'name',
        'report_type',
        'organization_id',
        'user_id',
        'columns_config',
        'is_default',
    ];

    protected $casts = [
        'columns_config' => 'array',
        'is_default' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public static function validReportTypes(): array
    {
        return [
            self::REPORT_TYPE_MATERIAL_USAGE,
            self::REPORT_TYPE_WORK_COMPLETION,
            self::REPORT_TYPE_FOREMAN_ACTIVITY,
            self::REPORT_TYPE_PROJECT_STATUS_SUMMARY,
            self::REPORT_TYPE_CONTRACTOR_SUMMARY,
            self::REPORT_TYPE_CONTRACTOR_DETAIL,
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
