<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Models;

use Illuminate\Database\Eloquent\Model;

final class QualityDefectFlowSnapshot extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'quality_defect_flow_snapshots';

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'as_of' => 'immutable_datetime',
        'source_watermark' => 'immutable_datetime',
        'generated_at' => 'immutable_datetime',
        'stale_at' => 'immutable_datetime',
        'row_count' => 'integer',
        'opening_count' => 'integer',
        'created_count' => 'integer',
        'reopened_count' => 'integer',
        'closed_count' => 'integer',
        'closing_count' => 'integer',
        'eligible_count' => 'integer',
        'projected_count' => 'integer',
        'gap_count' => 'integer',
        'unknown_count' => 'integer',
    ];
}
