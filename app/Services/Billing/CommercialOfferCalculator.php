<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Exceptions\Billing\StaleCommercialOfferException;
use App\Services\Modules\PackageCatalogService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

class CommercialOfferCalculator
{
    private const SECONDS_PER_DAY = 86400;

    public function __construct(
        private readonly PackageCatalogService $packageCatalog,
    ) {}

    public function preview(
        array $targetPackageSlugs,
        array $currentPackageSlugs = [],
        bool $fullSuite = false,
        ?CarbonInterface $calculatedAt = null,
        ?CarbonInterface $currentPeriodStartAt = null,
        ?CarbonInterface $currentPeriodEndAt = null,
    ): array {
        $catalog = $this->catalogPrices();
        $target = $this->normalizeSlugs($targetPackageSlugs, $catalog);
        $current = $this->normalizeSlugs($currentPackageSlugs, $catalog);

        if ($fullSuite) {
            $target = array_keys($catalog);
        }

        $added = array_values(array_diff($target, $current));
        $removed = array_values(array_diff($current, $target));
        $catalogTotal = array_sum($catalog);
        $monthlyTotal = $fullSuite
            ? $this->fullSuitePrice()
            : $this->sumPrices($target, $catalog);
        $now = CarbonImmutable::instance($calculatedAt ?? now());
        [$periodStart, $periodEnd, $remainingRatio, $hasCurrentPeriod] = $this->period(
            $now,
            $currentPeriodStartAt,
            $currentPeriodEndAt,
        );

        if (! $hasCurrentPeriod) {
            $amountDueNow = $monthlyTotal;
        } elseif ($fullSuite) {
            $currentTotal = $this->sumPrices($current, $catalog);
            $amountDueNow = max(0.0, ($monthlyTotal - $currentTotal) * $remainingRatio);
        } else {
            $amountDueNow = $this->sumPrices($added, $catalog) * $remainingRatio;
        }

        $savingsAmount = $fullSuite ? $catalogTotal - $monthlyTotal : 0;

        return [
            'quote_version' => $this->quoteVersion(),
            'currency' => (string) config('commercial_offers.currency', 'RUB'),
            'billing_period_days' => $this->billingPeriodDays(),
            'offer_type' => $fullSuite ? 'full_suite' : 'packages',
            'target_package_slugs' => $target,
            'current_package_slugs' => $current,
            'added_package_slugs' => $added,
            'removed_package_slugs' => $removed,
            'monthly_total' => $this->money($monthlyTotal),
            'amount_due_now' => $this->money($amountDueNow),
            'savings_amount' => $this->money($savingsAmount),
            'savings_percent' => $catalogTotal > 0
                ? round(($savingsAmount / $catalogTotal) * 100, 2)
                : 0.0,
            'recommendation' => ! $fullSuite && count($target) >= $this->recommendationThreshold()
                ? 'full_suite'
                : null,
            'period_start_at' => $periodStart,
            'period_end_at' => $periodEnd,
        ];
    }

    public function assertCurrentQuoteVersion(int $quoteVersion): void
    {
        if ($quoteVersion !== $this->quoteVersion()) {
            throw new StaleCommercialOfferException('Commercial offer has changed.');
        }
    }

    private function catalogPrices(): array
    {
        $prices = [];

        foreach ($this->packageCatalog->allPackages() as $package) {
            $slug = $package['slug'] ?? null;
            $price = $package['tiers']['standard']['price'] ?? null;

            if (! is_string($slug) || ! is_numeric($price)) {
                throw new InvalidArgumentException('Package catalog contains an invalid price.');
            }

            $prices[$slug] = (int) $price;
        }

        return $prices;
    }

    private function normalizeSlugs(array $slugs, array $catalog): array
    {
        $selected = [];

        foreach ($slugs as $slug) {
            if (! is_string($slug)) {
                throw new InvalidArgumentException('Package slug must be a string.');
            }

            $slug = trim($slug);

            if (! array_key_exists($slug, $catalog)) {
                throw new InvalidArgumentException("Unknown package '{$slug}'.");
            }

            $selected[$slug] = true;
        }

        return array_values(array_filter(
            array_keys($catalog),
            static fn (string $slug): bool => isset($selected[$slug]),
        ));
    }

    private function sumPrices(array $slugs, array $catalog): int
    {
        return array_sum(array_map(
            static fn (string $slug): int => $catalog[$slug],
            $slugs,
        ));
    }

    private function period(
        CarbonImmutable $now,
        ?CarbonInterface $currentStart,
        ?CarbonInterface $currentEnd,
    ): array {
        if ($currentStart !== null && $currentEnd !== null) {
            $start = CarbonImmutable::instance($currentStart);
            $end = CarbonImmutable::instance($currentEnd);

            if ((int) $start->diffInSeconds($end) !== $this->billingPeriodDays() * self::SECONDS_PER_DAY) {
                throw new InvalidArgumentException('Commercial billing period must be exactly 30 days.');
            }

            if ($now->greaterThanOrEqualTo($start) && $now->lessThan($end)) {
                $periodSeconds = $start->diffInSeconds($end);
                $remainingSeconds = $now->diffInSeconds($end);

                return [$start, $end, $remainingSeconds / $periodSeconds, true];
            }
        }

        return [$now, $now->addDays($this->billingPeriodDays()), 1.0, false];
    }

    private function quoteVersion(): int
    {
        return (int) config('commercial_offers.quote_version', 1);
    }

    private function billingPeriodDays(): int
    {
        return (int) config('commercial_offers.billing_period_days', 30);
    }

    private function fullSuitePrice(): int
    {
        return (int) config('commercial_offers.full_suite_price', 79900);
    }

    private function recommendationThreshold(): int
    {
        return (int) config('commercial_offers.full_suite_recommendation_threshold', 8);
    }

    private function money(int|float $amount): float
    {
        return round((float) $amount, 2);
    }
}
