<?php

namespace App\BusinessModules\Core\MultiOrganization\Services;

use App\BusinessModules\Core\MultiOrganization\Contracts\OrganizationScopeInterface;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

class ContextAwareOrganizationScope implements OrganizationScopeInterface
{
    private const CACHE_TTL = 3600;

    public function getOrganizationScope(int $currentOrgId): array
    {
        $route = request()->route()?->getName() ?? '';

        if ($this->isHoldingPanel($route)) {
            return $this->getFullHoldingScope($currentOrgId);
        }

        return [$currentOrgId];
    }

    private function isHoldingPanel(string $route): bool
    {
        return str_starts_with($route, 'multiOrganization.') 
            || str_contains($route, '.multiOrganization.');
    }

    private function getFullHoldingScope(int $currentOrgId): array
    {
        return Cache::remember("org_scope_full:{$currentOrgId}", self::CACHE_TTL, function() use ($currentOrgId) {
            $org = Organization::find($currentOrgId);

            if (!$org) {
                return [$currentOrgId];
            }

            if (!$org->is_holding) {
                abort(403, 'Access to holding panel is restricted to holding organizations');
            }

            $childIds = Organization::where('parent_organization_id', $currentOrgId)
                ->where('is_active', true)
                ->pluck('id')
                ->toArray();

            return array_merge([$currentOrgId], $childIds);
        });
    }

    public function isInScope(int $currentOrgId, int $targetOrgId): bool
    {
        return in_array($targetOrgId, $this->getOrganizationScope($currentOrgId));
    }

    public function applyScopeToQuery(Builder $query, int $currentOrgId, string $column = 'organization_id'): Builder
    {
        $orgIds = $this->getOrganizationScope($currentOrgId);
        return $query->whereIn($column, $orgIds);
    }

    public function getOrganizationTree(int $currentOrgId): array
    {
        $org = Organization::with('childOrganizations')->find($currentOrgId);

        if (!$org || !$org->is_holding) {
            return [
                'current' => [
                    'id' => $currentOrgId,
                    'name' => $org?->name ?? 'Unknown',
                    'type' => 'single'
                ],
                'children' => []
            ];
        }

        return [
            'current' => [
                'id' => $org->id,
                'name' => $org->name,
                'type' => 'holding',
                'is_holding' => true,
                'projects_count' => $org->projects()->count(),
            ],
            'children' => $org->childOrganizations->map(fn($child) => [
                'id' => $child->id,
                'name' => $child->name,
                'type' => 'child',
                'parent_id' => $org->id,
                'projects_count' => $child->projects()->count(),
                'is_active' => $child->is_active,
            ])->toArray()
        ];
    }

    public function invalidateCache(int $organizationId): void
    {
        Cache::forget("org_scope_full:{$organizationId}");
    }
}

