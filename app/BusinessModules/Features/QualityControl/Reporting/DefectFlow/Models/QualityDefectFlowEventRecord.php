<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Models;

use App\BusinessModules\Features\QualityControl\Enums\QualityDefectStatusEnum;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Enums\QualityDefectFlowEventKind;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Enums\QualityDefectFlowTerminalReason;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Models\Concerns\RejectsQualityDefectFlowSourceMutation;
use Illuminate\Database\Eloquent\Model;

final class QualityDefectFlowEventRecord extends Model
{
    use RejectsQualityDefectFlowSourceMutation;

    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $primaryKey = 'event_id';

    protected $keyType = 'string';

    protected $table = 'quality_defect_flow_events';

    protected $guarded = ['event_id'];

    protected $casts = [
        'event_kind' => QualityDefectFlowEventKind::class,
        'from_status' => QualityDefectStatusEnum::class,
        'to_status' => QualityDefectStatusEnum::class,
        'terminal_reason' => QualityDefectFlowTerminalReason::class,
        'occurred_at_utc' => 'immutable_datetime',
        'business_snapshot' => 'array',
        'source_identity' => 'array',
        'created_at' => 'immutable_datetime',
    ];
}
