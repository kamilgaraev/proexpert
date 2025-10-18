<?php

namespace App\BusinessModules\Core\MultiOrganization\Services;

use App\BusinessModules\Core\MultiOrganization\Contracts\OrganizationScopeInterface;
use Illuminate\Database\Eloquent\Builder;

class SingleOrganizationScope implements OrganizationScopeInterface
{
    public function getOrganizationScope(int $currentOrgId): array
    {
        return [$currentOrgId];
    }

    public function isInScope(int $currentOrgId, int $targetOrgId): bool
    {
        return $currentOrgId === $targetOrgId;
    }

    public function applyScopeToQuery(Builder $query, int $currentOrgId, string $column = 'organization_id'): Builder
    {
        return $query->where($column, $currentOrgId);
    }
}

