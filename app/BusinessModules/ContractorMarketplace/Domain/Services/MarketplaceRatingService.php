<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Domain\Services;

use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceContractorCategory;
use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceContractorRating;
use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceHiringOfferReview;
use App\Exceptions\BusinessLogicException;
use Illuminate\Support\Collection;

class MarketplaceRatingService
{
    public function recalculateForCategory(int $profileId, int $categoryId, array $sourceSignals = []): MarketplaceContractorRating
    {
        $capability = MarketplaceContractorCategory::query()
            ->where('profile_id', $profileId)
            ->where('category_id', $categoryId)
            ->first();

        if (! $capability) {
            throw new BusinessLogicException(trans_message('contractor_marketplace.category_unavailable'));
        }

        $reviewsCount = (int) ($sourceSignals['reviews_count'] ?? $capability->ratings_count ?? 0);
        $completedOffersCount = (int) ($sourceSignals['completed_offers_count'] ?? $capability->completed_projects_count ?? 0);
        $repeatHiresCount = (int) ($sourceSignals['repeat_hires_count'] ?? 0);
        $scoreInputs = array_filter([
            'quality_score' => $sourceSignals['quality_score'] ?? $capability->rating_score,
            'deadline_score' => $sourceSignals['deadline_score'] ?? null,
            'communication_score' => $sourceSignals['communication_score'] ?? null,
            'safety_score' => $sourceSignals['safety_score'] ?? null,
            'financial_discipline_score' => $sourceSignals['financial_discipline_score'] ?? null,
        ], static fn ($value): bool => $value !== null);

        $score = $this->calculateScore($scoreInputs, $reviewsCount, $completedOffersCount, $repeatHiresCount);

        return MarketplaceContractorRating::query()->updateOrCreate(
            [
                'profile_id' => $profileId,
                'category_id' => $categoryId,
            ],
            [
                'score' => $score,
                'quality_score' => $this->normalizeScore($scoreInputs['quality_score'] ?? null),
                'deadline_score' => $this->normalizeScore($scoreInputs['deadline_score'] ?? null),
                'communication_score' => $this->normalizeScore($scoreInputs['communication_score'] ?? null),
                'safety_score' => $this->normalizeScore($scoreInputs['safety_score'] ?? null),
                'financial_discipline_score' => $this->normalizeScore($scoreInputs['financial_discipline_score'] ?? null),
                'reviews_count' => $reviewsCount,
                'completed_offers_count' => $completedOffersCount,
                'repeat_hires_count' => $repeatHiresCount,
                'last_recalculated_at' => now(),
                'source_snapshot' => [
                    'capability_id' => $capability->id,
                    'capability_rating_score' => $capability->rating_score,
                    'capability_ratings_count' => $capability->ratings_count,
                    'capability_completed_projects_count' => $capability->completed_projects_count,
                    'signals' => $sourceSignals,
                ],
            ]
        );
    }

    public function recalculateFromReviews(int $profileId, int $categoryId): MarketplaceContractorRating
    {
        $reviews = MarketplaceHiringOfferReview::query()
            ->where('contractor_profile_id', $profileId)
            ->where('category_id', $categoryId)
            ->get();

        $sourceSignals = [
            'quality_score' => $this->averageScore($reviews, 'quality_score'),
            'deadline_score' => $this->averageScore($reviews, 'deadline_score'),
            'communication_score' => $this->averageScore($reviews, 'communication_score'),
            'safety_score' => $this->averageScore($reviews, 'safety_score'),
            'financial_discipline_score' => $this->averageScore($reviews, 'financial_discipline_score'),
            'reviews_count' => $reviews->count(),
            'completed_offers_count' => $reviews->pluck('offer_id')->unique()->count(),
            'repeat_hires_count' => $this->repeatHiresCount($profileId, $categoryId),
            'source' => 'marketplace_hiring_offer_reviews',
        ];

        $rating = $this->recalculateForCategory($profileId, $categoryId, $sourceSignals);

        MarketplaceContractorCategory::query()
            ->where('profile_id', $profileId)
            ->where('category_id', $categoryId)
            ->update([
                'rating_score' => $rating->score,
                'ratings_count' => $rating->reviews_count,
                'completed_projects_count' => $rating->completed_offers_count,
                'last_completed_at' => now(),
            ]);

        return $rating;
    }

    private function calculateScore(array $scoreInputs, int $reviewsCount, int $completedOffersCount, int $repeatHiresCount): ?float
    {
        if ($scoreInputs === [] || ($reviewsCount === 0 && $completedOffersCount === 0)) {
            return null;
        }

        $base = array_sum(array_map(fn ($value): float => $this->normalizeScore($value) ?? 0.0, $scoreInputs))
            / count($scoreInputs);
        $deliveryBonus = min(0.25, $completedOffersCount * 0.03);
        $repeatHireBonus = min(0.25, $repeatHiresCount * 0.05);

        return round(min(5.0, $base + $deliveryBonus + $repeatHireBonus), 2);
    }

    private function normalizeScore(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round(max(0.0, min(5.0, (float) $value)), 2);
    }

    private function averageScore(Collection $reviews, string $column): ?float
    {
        $values = $reviews
            ->pluck($column)
            ->filter(static fn ($value): bool => $value !== null && $value !== '');

        if ($values->isEmpty()) {
            return null;
        }

        return round((float) $values->avg(static fn ($value): float => (float) $value), 2);
    }

    private function repeatHiresCount(int $profileId, int $categoryId): int
    {
        return MarketplaceHiringOfferReview::query()
            ->where('contractor_profile_id', $profileId)
            ->where('category_id', $categoryId)
            ->selectRaw('reviewer_organization_id, COUNT(DISTINCT offer_id) as offers_count')
            ->groupBy('reviewer_organization_id')
            ->get()
            ->sum(static fn ($row): int => max(0, (int) $row->offers_count - 1));
    }
}
