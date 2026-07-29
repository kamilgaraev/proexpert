<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Models;

use App\Support\Reporting\ImmutableOwnerRecord;
use Illuminate\Database\Eloquent\Model;

final class ContractorScorecardPolicyVersion extends Model
{
    use ImmutableOwnerRecord;

    protected $table = 'contractor_scorecard_policy_versions';

    protected $guarded = [];

    protected $casts = [
        'organization_id' => 'integer',
        'components' => 'array',
        'cohort_rules' => 'array',
        'minimum_coverage' => 'decimal:8',
        'minimum_sample_size' => 'integer',
        'effective_from' => 'immutable_datetime',
        'effective_to' => 'immutable_datetime',
    ];
}
