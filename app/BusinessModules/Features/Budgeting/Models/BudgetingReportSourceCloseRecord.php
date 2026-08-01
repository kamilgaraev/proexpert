<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

final class BudgetingReportSourceCloseRecord extends Model
{
    protected $table = 'budgeting_report_source_closes';

    protected $fillable = [
        'close_id',
        'organization_id',
        'period_start',
        'period_end',
        'scenario_identity',
        'plan_identity',
        'formula_version',
        'source_manifest',
        'content_hash',
        'approved_by',
        'approved_at',
        'retained_until',
        'status',
        'restates_close_id',
        'restated_by',
        'restated_at',
        'restated_by_close_id',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'period_start' => 'immutable_date',
        'period_end' => 'immutable_date',
        'source_manifest' => 'array',
        'approved_by' => 'integer',
        'approved_at' => 'immutable_datetime',
        'retained_until' => 'immutable_datetime',
        'restated_by' => 'integer',
        'restated_at' => 'immutable_datetime',
    ];

    public function watermarks(): HasMany
    {
        return $this->hasMany(BudgetingReportSourceWatermarkRecord::class, 'close_id', 'close_id');
    }
}
