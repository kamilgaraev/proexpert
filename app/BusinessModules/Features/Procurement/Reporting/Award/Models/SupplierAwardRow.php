<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Award\Models;

use App\BusinessModules\Features\Procurement\Reporting\Support\ImmutableReportingRecord;
use Illuminate\Database\Eloquent\Model;

final class SupplierAwardRow extends Model
{
    use ImmutableReportingRecord;

    protected $table = 'supplier_award_rows';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'project_id' => 'integer',
            'material_id' => 'integer',
            'decision_id' => 'integer',
            'decision_version' => 'integer',
            'proposal_version_id' => 'integer',
            'supplier_id' => 'integer',
            'selected_proposal_version_id' => 'integer',
            'cheapest_proposal_version_id' => 'integer',
            'median_proposal_version_id' => 'integer',
            'invited_count' => 'integer',
            'responded_count' => 'integer',
            'selected_amount_minor' => 'integer',
            'cheapest_amount_minor' => 'integer',
            'median_amount_minor' => 'integer',
            'premium_minor' => 'integer',
            'median_variance_minor' => 'integer',
            'non_lowest_selected' => 'boolean',
            'excluded_comparisons' => 'array',
            'selected_at' => 'immutable_datetime',
            'quality_warnings' => 'array',
        ];
    }
}
