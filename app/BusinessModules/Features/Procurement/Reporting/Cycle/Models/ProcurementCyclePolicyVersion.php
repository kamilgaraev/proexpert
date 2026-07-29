<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Models;

use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementCyclePolicy;
use App\BusinessModules\Features\Procurement\Reporting\Support\ImmutableReportingRecord;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;

final class ProcurementCyclePolicyVersion extends Model
{
    use ImmutableReportingRecord;

    protected $table = 'procurement_cycle_policy_versions';

    protected $guarded = [];

    public $timestamps = false;

    public function policy(DateTimeImmutable $asOf): ProcurementCyclePolicy
    {
        return new ProcurementCyclePolicy(
            $asOf,
            (array) $this->getAttribute('stage_sla_seconds'),
            (string) $this->getAttribute('timezone'),
            (array) $this->getAttribute('business_weekdays'),
            (string) $this->getAttribute('business_day_start'),
            (string) $this->getAttribute('business_day_end'),
        );
    }

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'policy_version' => 'integer',
            'business_weekdays' => 'array',
            'stage_sla_seconds' => 'array',
            'effective_from' => 'immutable_datetime',
            'effective_to' => 'immutable_datetime',
            'freshness_ttl_seconds' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
