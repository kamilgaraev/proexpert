<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Award\Models;

use App\BusinessModules\Features\Procurement\Reporting\Cycle\Models\Concerns\RejectsProcurementSourceMutation;
use Illuminate\Database\Eloquent\Model;

final class ProcurementAwardEvidenceCandidate extends Model
{
    use RejectsProcurementSourceMutation;

    public const CREATED_AT = null;

    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $table = 'procurement_award_evidence_candidates';

    protected $guarded = [];

    protected $casts = [
        'request_line_coverage' => 'array',
        'comparable' => 'boolean',
        'exclusion_codes' => 'array',
    ];
}
