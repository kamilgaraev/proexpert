<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Models;

use Illuminate\Database\Eloquent\Model;

final class BudgetingReportSourceWatermarkRecord extends Model
{
    protected $table = 'budgeting_report_source_watermarks';

    protected $fillable = [
        'close_id',
        'source',
        'cutoff_at',
        'watermark',
        'source_schema_version',
    ];

    protected $casts = [
        'cutoff_at' => 'immutable_datetime',
    ];
}
