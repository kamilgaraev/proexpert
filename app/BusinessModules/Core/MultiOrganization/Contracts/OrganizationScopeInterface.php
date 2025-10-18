<?php

namespace App\BusinessModules\Core\MultiOrganization\Contracts;

use Illuminate\Database\Eloquent\Builder;

interface OrganizationScopeInterface
{
    public function getOrganizationScope(int $currentOrgId): array;

    public function isInScope(int $currentOrgId, int $targetOrgId): bool;

    public function applyScopeToQuery(Builder $query, int $currentOrgId, string $column = 'organization_id'): Builder;
}

