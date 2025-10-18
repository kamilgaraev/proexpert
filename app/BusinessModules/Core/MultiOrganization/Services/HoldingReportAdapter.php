<?php

namespace App\BusinessModules\Core\MultiOrganization\Services;

use Illuminate\Database\Eloquent\Builder;
use App\Models\Organization;

class HoldingReportAdapter
{
    public function __construct(
        private FilterScopeManager $filterManager
    ) {}

    public function applyReportScope(Builder $query, int $organizationId, array $config): Builder
    {
        $org = Organization::find($organizationId);

        if (!$org || !$org->is_holding) {
            return $query->where('organization_id', $organizationId);
        }

        $filters = $config['holding_filters'] ?? [];
        return $this->filterManager->applyHoldingFilters($query, $organizationId, $filters);
    }

    public function shouldApplyMultiOrgScope(int $organizationId): bool
    {
        $org = Organization::find($organizationId);
        return $org && $org->is_holding;
    }
}

