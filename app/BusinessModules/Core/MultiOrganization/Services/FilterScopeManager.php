<?php

namespace App\BusinessModules\Core\MultiOrganization\Services;

use Illuminate\Database\Eloquent\Builder;
use App\Models\Organization;

class FilterScopeManager
{
    public function __construct(
        private ContextAwareOrganizationScope $scope
    ) {}

    public function applyHoldingFilters(Builder $query, int $holdingId, array $filters): Builder
    {
        $availableOrgIds = $this->scope->getOrganizationScope($holdingId);

        $selectedOrgIds = $filters['organization_ids'] ?? $availableOrgIds;

        $selectedOrgIds = array_intersect($selectedOrgIds, $availableOrgIds);

        if (empty($selectedOrgIds)) {
            $selectedOrgIds = $availableOrgIds;
        }

        $query->whereIn('organization_id', $selectedOrgIds);

        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!($filters['include_archived'] ?? false)) {
            if (in_array('is_archived', $query->getModel()->getFillable()) || 
                $query->getModel()->hasAttribute('is_archived')) {
                $query->where('is_archived', false);
            }
        }

        return $query;
    }

    public function getFilterOptions(int $holdingId): array
    {
        $tree = $this->scope->getOrganizationTree($holdingId);

        return [
            'organizations' => $tree,
            'date_ranges' => [
                'today' => [now()->startOfDay(), now()->endOfDay()],
                'this_week' => [now()->startOfWeek(), now()->endOfWeek()],
                'this_month' => [now()->startOfMonth(), now()->endOfMonth()],
                'this_quarter' => [now()->startOfQuarter(), now()->endOfQuarter()],
                'this_year' => [now()->startOfYear(), now()->endOfYear()],
                'last_month' => [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()],
                'last_quarter' => [now()->subQuarter()->startOfQuarter(), now()->subQuarter()->endOfQuarter()],
            ],
        ];
    }
}

