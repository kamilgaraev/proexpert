<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Award\Models;

use App\BusinessModules\Features\Procurement\Reporting\Award\Enums\ProcurementAwardCompleteness;
use App\BusinessModules\Features\Procurement\Reporting\Award\Enums\ProcurementAwardEventType;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Models\Concerns\RejectsProcurementSourceMutation;
use Illuminate\Database\Eloquent\Model;

final class ProcurementAwardEvidenceEvent extends Model
{
    use RejectsProcurementSourceMutation;

    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $table = 'procurement_award_evidence_events';

    protected $guarded = [];

    protected $casts = [
        'event_type' => ProcurementAwardEventType::class,
        'completeness' => ProcurementAwardCompleteness::class,
        'occurred_at' => 'immutable_datetime',
        'quarantine_codes' => 'array',
        'reason_present' => 'boolean',
        'created_at' => 'immutable_datetime',
    ];
}
