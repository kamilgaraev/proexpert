<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Domain\Services;

use App\BusinessModules\ContractorMarketplace\Domain\Enums\MarketplaceProfileStatus;
use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceContractorProfile;
use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceContractorRating;
use App\Exceptions\BusinessLogicException;
use App\Models\Contractor;
use App\Models\ContractorInvitation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class MarketplaceSearchService
{
    public function search(int $organizationId, array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $networkOrganizationIds = $this->networkOrganizationIds($organizationId);

        $query = MarketplaceContractorProfile::query()
            ->visible()
            ->whereIn('organization_id', $networkOrganizationIds)
            ->with(['organization', 'categories.category', 'ratings.category', 'regions']);

        $categoryId = isset($filters['category_id']) ? (int) $filters['category_id'] : null;

        if ($categoryId !== null) {
            $query->whereHas('categories', static function ($categoryQuery) use ($categoryId, $filters): void {
                $categoryQuery->where('category_id', $categoryId);

                if (isset($filters['team_capacity_min'])) {
                    $categoryQuery->where('team_capacity', '>=', (int) $filters['team_capacity_min']);
                }

                if (isset($filters['budget_min'])) {
                    $categoryQuery->where(function ($budgetQuery) use ($filters): void {
                        $budgetQuery->whereNull('max_project_budget')
                            ->orWhere('max_project_budget', '>=', (float) $filters['budget_min']);
                    });
                }

                if (isset($filters['budget_max'])) {
                    $categoryQuery->where(function ($budgetQuery) use ($filters): void {
                        $budgetQuery->whereNull('min_project_budget')
                            ->orWhere('min_project_budget', '<=', (float) $filters['budget_max']);
                    });
                }
            });
        }

        if (isset($filters['min_rating'])) {
            $query->whereHas('ratings', static function ($ratingQuery) use ($categoryId, $filters): void {
                if ($categoryId !== null) {
                    $ratingQuery->where('category_id', $categoryId);
                }

                $ratingQuery->where('score', '>=', (float) $filters['min_rating']);
            });
        }

        if (! empty($filters['city'])) {
            $city = (string) $filters['city'];
            $cityLike = "%{$city}%";
            $normalizedCityLike = '%' . mb_strtolower($city) . '%';
            $query->where(function ($cityQuery) use ($cityLike, $normalizedCityLike): void {
                $cityQuery->whereRaw('(base_city LIKE ? OR lower(base_city) LIKE ?)', [$cityLike, $normalizedCityLike])
                    ->orWhereHas('regions', static function ($regionQuery) use ($cityLike, $normalizedCityLike): void {
                        $regionQuery->whereRaw('(city LIKE ? OR lower(city) LIKE ?)', [$cityLike, $normalizedCityLike]);
                    });
            });
        }

        if (! empty($filters['availability_status'])) {
            $query->where('availability_status', $filters['availability_status']);
        }

        if (! empty($filters['verification_level'])) {
            $query->where('verification_level', $filters['verification_level']);
        }

        if (! empty($filters['search'])) {
            $term = mb_strtolower((string) $filters['search']);
            $like = "%{$term}%";
            $query->where(function ($searchQuery) use ($like): void {
                $searchQuery->whereRaw('lower(display_name) LIKE ?', [$like])
                    ->orWhereRaw('lower(short_description) LIKE ?', [$like])
                    ->orWhereRaw('lower(description) LIKE ?', [$like]);
            });
        }

        $this->applySorting($query, $filters['sort_by'] ?? 'relevance', $filters, $categoryId);

        return $query->paginate($perPage);
    }

    public function showVisibleProfile(
        int $organizationId,
        MarketplaceContractorProfile $profile
    ): MarketplaceContractorProfile {
        $networkOrganizationIds = $this->networkOrganizationIds($organizationId);

        if (
            ! in_array((int) $profile->organization_id, $networkOrganizationIds, true)
            || ! $profile->is_visible_in_marketplace
            || $profile->status !== MarketplaceProfileStatus::ACTIVE
        ) {
            throw new BusinessLogicException(
                trans_message('contractor_marketplace.contractor_profile_not_found'),
                404
            );
        }

        return $profile->load([
            'organization',
            'categories.category',
            'ratings.category',
            'regions',
            'portfolioItems.category',
            'documents',
        ]);
    }

    /**
     * @return array<int>
     */
    public function networkOrganizationIds(int $organizationId): array
    {
        $contractorIds = Contractor::query()
            ->where('organization_id', $organizationId)
            ->whereNotNull('source_organization_id')
            ->pluck('source_organization_id');

        $acceptedSentIds = ContractorInvitation::query()
            ->where('organization_id', $organizationId)
            ->where('status', ContractorInvitation::STATUS_ACCEPTED)
            ->pluck('invited_organization_id');

        $acceptedReceivedIds = ContractorInvitation::query()
            ->where('invited_organization_id', $organizationId)
            ->where('status', ContractorInvitation::STATUS_ACCEPTED)
            ->pluck('organization_id');

        return $contractorIds
            ->merge($acceptedSentIds)
            ->merge($acceptedReceivedIds)
            ->map(static fn ($id): int => (int) $id)
            ->reject(static fn (int $id): bool => $id === $organizationId)
            ->unique()
            ->values()
            ->all();
    }

    private function applySorting(Builder $query, string $sortBy, array $filters, ?int $categoryId): void
    {
        if ($sortBy === 'category_rating' && $categoryId !== null) {
            $query->addSelect([
                'selected_category_rating' => MarketplaceContractorRating::query()
                    ->select('score')
                    ->whereColumn('profile_id', 'marketplace_contractor_profiles.id')
                    ->where('category_id', $categoryId)
                    ->limit(1),
            ])->orderByDesc('selected_category_rating')
                ->orderBy('display_name');
            return;
        }

        if ($sortBy === 'name') {
            $query->orderBy('display_name');
            return;
        }

        if (! empty($filters['search'])) {
            $term = mb_strtolower((string) $filters['search']);
            $query->orderByRaw(
                '
                CASE
                    WHEN lower(display_name) LIKE ? THEN 1
                    WHEN lower(display_name) LIKE ? THEN 2
                    WHEN lower(short_description) LIKE ? THEN 3
                    ELSE 4
                END
                ',
                ["{$term}%", "%{$term}%", "%{$term}%"]
            )->orderBy('display_name');
            return;
        }

        $query->orderByDesc('published_at')->orderBy('display_name');
    }
}
