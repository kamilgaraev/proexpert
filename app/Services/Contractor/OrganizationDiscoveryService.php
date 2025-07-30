<?php

namespace App\Services\Contractor;

use App\Models\Organization;
use App\Models\ContractorInvitation;
use App\Models\Contractor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class OrganizationDiscoveryService
{
    public function searchOrganizations(
        int $excludeOrganizationId,
        array $filters = [],
        int $perPage = 20,
        string $sortBy = 'relevance'
    ): LengthAwarePaginator {
        $cacheKey = $this->getSearchCacheKey($excludeOrganizationId, $filters, $perPage, $sortBy);
        
        return Cache::remember($cacheKey, 600, function () use ($excludeOrganizationId, $filters, $perPage, $sortBy) {
            $query = $this->buildSearchQuery($excludeOrganizationId, $filters);
            
            $this->applySorting($query, $sortBy, $filters);
            
            return $query->paginate($perPage);
        });
    }

    public function getRecommendedOrganizations(
        int $organizationId,
        int $limit = 10
    ): array {
        $cacheKey = "organization_recommendations:{$organizationId}:{$limit}";
        
        return Cache::remember($cacheKey, 1800, function () use ($organizationId, $limit) {
            $currentOrg = Organization::find($organizationId);
            if (!$currentOrg) {
                return [];
            }

            $query = $this->buildSearchQuery($organizationId, []);
            
            $this->applyRecommendationLogic($query, $currentOrg);
            
            return $query->limit($limit)->get()->toArray();
        });
    }

    public function getOrganizationAvailabilityStatus(int $organizationId, int $targetOrganizationId): array
    {
        $cacheKey = "org_availability:{$organizationId}:{$targetOrganizationId}";
        
        return Cache::remember($cacheKey, 300, function () use ($organizationId, $targetOrganizationId) {
            $existingInvitation = ContractorInvitation::where('organization_id', $organizationId)
                ->where('invited_organization_id', $targetOrganizationId)
                ->active()
                ->first();

            $existingContractor = Contractor::where('organization_id', $organizationId)
                ->where('source_organization_id', $targetOrganizationId)
                ->first();

            $reverseInvitation = ContractorInvitation::where('organization_id', $targetOrganizationId)
                ->where('invited_organization_id', $organizationId)
                ->active()
                ->first();

            return [
                'can_invite' => !$existingInvitation && !$existingContractor,
                'existing_invitation' => $existingInvitation?->only(['id', 'status', 'created_at']),
                'existing_contractor' => $existingContractor?->only(['id', 'name', 'connected_at']),
                'reverse_invitation' => $reverseInvitation?->only(['id', 'status', 'created_at']),
                'is_mutual' => $existingContractor && Contractor::where('organization_id', $targetOrganizationId)
                    ->where('source_organization_id', $organizationId)
                    ->exists(),
            ];
        });
    }

    public function getBulkAvailabilityStatus(int $organizationId, array $targetOrganizationIds): array
    {
        $results = [];
        $uncachedIds = [];
        
        foreach ($targetOrganizationIds as $targetId) {
            $cacheKey = "org_availability:{$organizationId}:{$targetId}";
            $cached = Cache::get($cacheKey);
            
            if ($cached !== null) {
                $results[$targetId] = $cached;
            } else {
                $uncachedIds[] = $targetId;
            }
        }

        if (!empty($uncachedIds)) {
            $bulkData = $this->getBulkAvailabilityData($organizationId, $uncachedIds);
            
            foreach ($uncachedIds as $targetId) {
                $status = $this->processAvailabilityData($bulkData, $organizationId, $targetId);
                $results[$targetId] = $status;
                
                Cache::put("org_availability:{$organizationId}:{$targetId}", $status, 300);
            }
        }

        return $results;
    }

    public function getSearchSuggestions(string $query, int $excludeOrganizationId, int $limit = 10): array
    {
        $cacheKey = "search_suggestions:" . md5($query . $excludeOrganizationId . $limit);
        
        return Cache::remember($cacheKey, 900, function () use ($query, $excludeOrganizationId, $limit) {
            return Organization::where('id', '!=', $excludeOrganizationId)
                ->where('is_active', true)
                ->where(function($q) use ($query) {
                    $q->where('name', 'LIKE', "%{$query}%")
                      ->orWhere('legal_name', 'LIKE', "%{$query}%")
                      ->orWhere('city', 'LIKE', "%{$query}%");
                })
                ->select(['id', 'name', 'city', 'is_verified'])
                ->orderByRaw("
                    CASE 
                        WHEN name LIKE '{$query}%' THEN 1
                        WHEN name LIKE '%{$query}%' THEN 2
                        WHEN legal_name LIKE '%{$query}%' THEN 3
                        ELSE 4
                    END
                ")
                ->limit($limit)
                ->get()
                ->toArray();
        });
    }

    protected function buildSearchQuery(int $excludeOrganizationId, array $filters): Builder
    {
        $query = Organization::query()
            ->select([
                'organizations.*',
                DB::raw("(SELECT COUNT(*) FROM contractor_invitations ci WHERE ci.invited_organization_id = organizations.id AND ci.status = 'accepted') as contractor_connections_count")
            ])
            ->where('id', '!=', $excludeOrganizationId)
            ->where('is_active', true);

        if (!empty($filters['search'])) {
            $searchTerm = mb_strtolower($filters['search']);
            $query->addSelect([
                DB::raw("GREATEST(similarity(lower(name), ?), similarity(lower(legal_name), ?)) as relevance_score")
            ])->setBindings([$searchTerm, $searchTerm], 'select');

            $query->where(function($q) use ($searchTerm) {
                $q->whereRaw('similarity(lower(name), ?) > 0.25', [$searchTerm])
                  ->orWhereRaw('similarity(lower(legal_name), ?) > 0.25', [$searchTerm])
                  ->orWhere('name', 'ILIKE', "%{$searchTerm}%")
                  ->orWhere('legal_name', 'ILIKE', "%{$searchTerm}%")
                  ->orWhere('description', 'ILIKE', "%{$searchTerm}%")
                  ->orWhere('city', 'ILIKE', "%{$searchTerm}%");
            });
        }

        if (!empty($filters['city'])) {
            $query->where('city', 'LIKE', "%{$filters['city']}%");
        }

        if (!empty($filters['country'])) {
            $query->where('country', $filters['country']);
        }

        if (isset($filters['verified']) && $filters['verified']) {
            $query->where('is_verified', true);
        }

        if (!empty($filters['exclude_invited'])) {
            $query->whereNotIn('id', function($subquery) use ($excludeOrganizationId) {
                $subquery->select('invited_organization_id')
                         ->from('contractor_invitations')
                         ->where('organization_id', $excludeOrganizationId)
                         ->whereIn('status', ['pending', 'accepted']);
            });
        }

        if (!empty($filters['exclude_existing_contractors'])) {
            $query->whereNotIn('id', function($subquery) use ($excludeOrganizationId) {
                $subquery->select('source_organization_id')
                         ->from('contractors')
                         ->where('organization_id', $excludeOrganizationId)
                         ->whereNotNull('source_organization_id');
            });
        }

        return $query;
    }

    protected function applySorting(Builder $query, string $sortBy, array $filters): void
    {
        switch ($sortBy) {
            case 'relevance':
                if (!empty($filters['search'])) {
                    $searchTerm = $filters['search'];
                    $query->orderByRaw("
                        CASE 
                            WHEN name LIKE '{$searchTerm}%' THEN 1
                            WHEN name LIKE '%{$searchTerm}%' THEN 2
                            WHEN legal_name LIKE '%{$searchTerm}%' THEN 3
                            ELSE 4
                        END
                    ");
                } else {
                    $query->orderByDesc('is_verified')
                          ->orderByDesc('contractor_connections_count');
                }
                break;

            case 'name':
                $query->orderBy('name');
                break;

            case 'city':
                $query->orderBy('city')->orderBy('name');
                break;

            case 'connections':
                $query->orderByDesc('contractor_connections_count')
                      ->orderBy('name');
                break;

            case 'verified':
                $query->orderByDesc('is_verified')
                      ->orderBy('name');
                break;

            default:
                $query->orderBy('name');
                break;
        }
    }

    protected function applyRecommendationLogic(Builder $query, Organization $currentOrg): void
    {
        $query->where('city', $currentOrg->city)
              ->orWhere('country', $currentOrg->country);

        $query->orderByRaw("
            CASE 
                WHEN city = ? THEN 1
                WHEN country = ? THEN 2
                ELSE 3
            END
        ", [$currentOrg->city, $currentOrg->country])
        ->orderByDesc('is_verified')
        ->orderByDesc('contractor_connections_count');
    }

    protected function getBulkAvailabilityData(int $organizationId, array $targetOrganizationIds): array
    {
        $invitations = ContractorInvitation::where('organization_id', $organizationId)
            ->whereIn('invited_organization_id', $targetOrganizationIds)
            ->active()
            ->get()
            ->keyBy('invited_organization_id');

        $contractors = Contractor::where('organization_id', $organizationId)
            ->whereIn('source_organization_id', $targetOrganizationIds)
            ->get()
            ->keyBy('source_organization_id');

        $reverseInvitations = ContractorInvitation::where('invited_organization_id', $organizationId)
            ->whereIn('organization_id', $targetOrganizationIds)
            ->active()
            ->get()
            ->keyBy('organization_id');

        $mutualContractors = Contractor::whereIn('organization_id', $targetOrganizationIds)
            ->where('source_organization_id', $organizationId)
            ->get()
            ->keyBy('organization_id');

        return [
            'invitations' => $invitations,
            'contractors' => $contractors,
            'reverse_invitations' => $reverseInvitations,
            'mutual_contractors' => $mutualContractors,
        ];
    }

    protected function processAvailabilityData(array $bulkData, int $organizationId, int $targetOrganizationId): array
    {
        $existingInvitation = $bulkData['invitations'][$targetOrganizationId] ?? null;
        $existingContractor = $bulkData['contractors'][$targetOrganizationId] ?? null;
        $reverseInvitation = $bulkData['reverse_invitations'][$targetOrganizationId] ?? null;
        $isMutual = isset($bulkData['mutual_contractors'][$targetOrganizationId]) && $existingContractor;

        return [
            'can_invite' => !$existingInvitation && !$existingContractor,
            'existing_invitation' => $existingInvitation?->only(['id', 'status', 'created_at']),
            'existing_contractor' => $existingContractor?->only(['id', 'name', 'connected_at']),
            'reverse_invitation' => $reverseInvitation?->only(['id', 'status', 'created_at']),
            'is_mutual' => $isMutual,
        ];
    }

    protected function getSearchCacheKey(int $excludeOrganizationId, array $filters, int $perPage, string $sortBy): string
    {
        $filterHash = md5(serialize($filters));
        return "org_search:{$excludeOrganizationId}:{$filterHash}:{$perPage}:{$sortBy}";
    }
}