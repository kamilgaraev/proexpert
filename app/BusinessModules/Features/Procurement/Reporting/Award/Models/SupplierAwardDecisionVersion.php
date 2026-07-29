<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Award\Models;

use App\BusinessModules\Features\Procurement\Reporting\Support\ImmutableReportingRecord;
use Illuminate\Database\Eloquent\Model;

final class SupplierAwardDecisionVersion extends Model
{
    use ImmutableReportingRecord;

    protected $table = 'supplier_award_decision_versions';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'decision_id' => 'integer',
            'decision_version' => 'integer',
            'purchase_request_id' => 'integer',
            'supplier_request_id' => 'integer',
            'selected_proposal_version_id' => 'integer',
            'cheapest_proposal_version_id' => 'integer',
            'median_proposal_version_id' => 'integer',
            'invited_supplier_ids' => 'array',
            'comparable_proposal_version_ids' => 'array',
            'excluded_comparisons' => 'array',
            'is_lowest_price_selected' => 'boolean',
            'selected_by' => 'integer',
            'selected_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
        ];
    }
}
