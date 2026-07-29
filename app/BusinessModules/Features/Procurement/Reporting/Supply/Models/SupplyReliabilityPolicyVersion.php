<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Supply\Models;

use App\BusinessModules\Features\Procurement\Reporting\Supply\DTO\SupplyReliabilityPolicy;
use App\BusinessModules\Features\Procurement\Reporting\Support\ImmutableReportingRecord;
use Illuminate\Database\Eloquent\Model;

final class SupplyReliabilityPolicyVersion extends Model
{
    use ImmutableReportingRecord;

    protected $table = 'supply_reliability_policy_versions';

    protected $guarded = [];

    public $timestamps = false;

    public function policy(): SupplyReliabilityPolicy
    {
        return new SupplyReliabilityPolicy(
            (string) $this->getAttribute('quantity_tolerance'),
            (int) $this->getAttribute('on_time_cutoff_seconds'),
            (bool) $this->getAttribute('exclude_cancellation_before_send'),
            (array) $this->getAttribute('post_send_exclusion_reason_codes'),
        );
    }

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'policy_version' => 'integer',
            'on_time_cutoff_seconds' => 'integer',
            'exclude_cancellation_before_send' => 'boolean',
            'post_send_exclusion_reason_codes' => 'array',
            'freshness_ttl_seconds' => 'integer',
            'effective_from' => 'immutable_datetime',
            'effective_to' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
