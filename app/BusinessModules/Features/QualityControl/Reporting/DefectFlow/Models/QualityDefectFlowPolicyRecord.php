<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Models;

use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Models\Concerns\RejectsQualityDefectFlowSourceMutation;
use Illuminate\Database\Eloquent\Model;

final class QualityDefectFlowPolicyRecord extends Model
{
    use RejectsQualityDefectFlowSourceMutation;

    public const UPDATED_AT = null;

    protected $table = 'quality_defect_flow_policies';

    protected $guarded = ['id'];

    protected $casts = [
        'canonical_policy' => 'array',
        'created_at' => 'immutable_datetime',
    ];
}
