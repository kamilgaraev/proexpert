<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Models;

use App\Support\Reporting\ImmutableOwnerRecord;
use Illuminate\Database\Eloquent\Model;

final class ContractorScorecardRow extends Model
{
    use ImmutableOwnerRecord;

    protected $table = 'contractor_scorecard_rows';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'organization_id' => 'integer',
        'profile_id' => 'integer',
        'category_id' => 'integer',
        'project_id' => 'integer',
        'component_mean' => 'decimal:8',
        'sample_size' => 'integer',
        'eligible_count' => 'integer',
        'coverage' => 'decimal:8',
        'evidence_refs' => 'array',
    ];
}
