<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Models;

use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Enums\QualityDefectFlowGapCode;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Models\Concerns\RejectsQualityDefectFlowSourceMutation;
use Illuminate\Database\Eloquent\Model;

final class QualityDefectFlowGapRecord extends Model
{
    use RejectsQualityDefectFlowSourceMutation;

    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $primaryKey = 'gap_id';

    protected $keyType = 'string';

    protected $table = 'quality_defect_flow_gaps';

    protected $guarded = ['gap_id'];

    protected $casts = [
        'gap_code' => QualityDefectFlowGapCode::class,
        'detected_at_utc' => 'immutable_datetime',
        'source_identity' => 'array',
        'created_at' => 'immutable_datetime',
    ];
}
