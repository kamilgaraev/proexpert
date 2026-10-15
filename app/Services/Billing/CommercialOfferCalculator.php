<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Billing\CommercialBillingPeriod;
use App\Exceptions\Billing\StaleCommercialOfferException;
use App\Services\Modules\PackageCatalogService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

class CommercialOfferCalculator
{
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
        string $billingPeriod = '30_days',
        string $currentBillingPeriod = '30_days',
        bool $renewSubscription = false,
    ): array {
        $term = CommercialBillingPeriod::tryFrom($billingPeriod)
            ?? throw new InvalidArgumentException('Unsupported commercial billing period.');
        $currentTerm = CommercialBillingPeriod::tryFrom($currentBillingPeriod)
            ?? throw new InvalidArgumentException('Unsupported current commercial billing period.');
        $catalog = $this->catalogPrices();
        $target = $this->normalizeSlugs($targetPackageSlugs, $catalog, true);
        $current = $this->normalizeSlugs($currentPackageSlugs, $catalog, false);

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
        [$periodStart, $periodEnd, $remainingSeconds, $periodSeconds, $hasCurrentPeriod] = $this->period(
            $now,
            $currentPeriodStartAt,
            $currentPeriodEndAt,
            $currentTerm,
            $term,
        );

        $periodTotal = $term->amountMinor($monthlyTotal);
        if ($hasCurrentPeriod && $renewSubscription) {
            if ($target !== $current || $target === []) {
                throw new InvalidArgumentException('Renewal requires the unchanged paid package composition.');
            }
            $periodStart = $periodEnd;
            $periodEnd = $term->endAt($periodStart);
            $amountDueNow = $periodTotal;
        } elseif (! $hasCurrentPeriod) {
            $amountDueNow = $periodTotal;
        } elseif ($term !== $currentTerm) {
            throw new InvalidArgumentException('Change the billing period when renewing the subscription.');
        } elseif ($fullSuite) {
            $currentTotal = $this->sumPrices($current, $catalog);
            $amountDueNow = $this->prorate(
                $term->amountMinor(max(0, $monthlyTotal - $currentTotal)),
                $remainingSeconds,
                $periodSeconds,
            );
        } else {
            $amountDueNow = $this->prorate(
                $term->amountMinor($this->sumPrices($added, $catalog)),
                $remainingSeconds,
                $periodSeconds,
            );
        }

        $savingsAmount = $fullSuite ? $catalogTotal - $monthlyTotal : 0;

        return [
            'quote_version' => $this->quoteVersion(),
            'currency' => (string) config('commercial_offers.currency', 'RUB'),
            'billing_period' => $term->value,
            'billing_period_days' => (int) $periodStart->diffInDays($periodEnd),
            'billing_period_months' => $term->months(),
            'renew_subscription' => $renewSubscription,
            'period_total_minor' => $periodTotal,
            'period_total' => $this->money($periodTotal),
            'period_discount_percent' => $term->discountPercent(),
            'period_savings_minor' => $monthlyTotal * $term->months() - $periodTotal,
            'offer_type' => $fullSuite ? 'full_suite' : 'packages',
            'target_package_slugs' => $target,
            'current_package_slugs' => $current,
            'added_package_slugs' => $added,
            'removed_package_slugs' => $removed,
            'monthly_total' => $this->money($monthlyTotal),
            'monthly_total_minor' => $monthlyTotal,
            'amount_due_now' => $this->money($amountDueNow),
            'amount_due_now_minor' => $amountDueNow,
            'savings_amount' => $this->money($savingsAmount),
            'savings_amount_minor' => $savingsAmount,
            'savings_percent' => $catalogTotal > 0
                ? round(($savingsAmount / $catalogTotal) * 100, 2)
                : 0.0,
            'recommendation' => ! $fullSuite && intdiv($monthlyTotal, 100) >= $this->recommendationThreshold()
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

    public function resolveTargetSlugs(array $slugs, bool $fullSuite): array
    {
        $catalog = $this->catalogPrices();

        if ($fullSuite) {
            return array_keys($catalog);
        }

        return $this->normalizeSlugs($slugs, $catalog, true);
    }

    public function resolveCurrentSlugs(array $slugs): array
    {
        return $this->normalizeSlugs($slugs, $this->catalogPrices(), false);
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

            $prices[$slug] = (int) $price * 100;
        }

        return $prices;
    }

    private function normalizeSlugs(array $slugs, array $catalog, bool $attachEntry): array
    {
        $entry = $this->packageCatalog->entryPackageSlug();
        $selected = [];

        foreach ($slugs as $slug) {
            if (! is_string($slug)) {
                throw new InvalidArgumentException('Package slug must be a string.');
            }

            $slug = $this->packageCatalog->canonicalizePackageSlug(trim($slug));

            if (! array_key_exists($slug, $catalog)) {
                throw new InvalidArgumentException("Unknown package '{$slug}'.");
            }

            $selected[$slug] = true;
        }

        if ($attachEntry && $this->containsContour($selected, $entry) && array_key_exists($entry, $catalog)) {
            $selected[$entry] = true;
        }

        return array_values(array_filter(
            array_keys($catalog),
            static fn (string $slug): bool => isset($selected[$slug]),
        ));
    }

    private function containsContour(array $selected, string $entry): bool
    {
        foreach ($selected as $slug => $present) {
            if ($present && $slug !== $entry) {
                return true;
            }
        }

        return false;
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
        CommercialBillingPeriod $currentTerm,
        CommercialBillingPeriod $term,
    ): array {
        if (($currentStart === null) !== ($currentEnd === null)) {
            throw new InvalidArgumentException('Both commercial billing period boundaries are required.');
        }

        if ($currentStart !== null && $currentEnd !== null) {
            $start = CarbonImmutable::instance($currentStart);
            $end = CarbonImmutable::instance($currentEnd);

            if (! $end->greaterThan($start)) {
                throw new InvalidArgumentException('Commercial billing period end must follow its start.');
            }

            if (! $currentTerm->endAt($start)->equalTo($end)) {
                throw new InvalidArgumentException('Commercial billing period does not match its duration.');
            }

            if ($now->lessThan($end)) {
                $periodSeconds = (int) $start->diffInSeconds($end);
                $remainingSeconds = (int) $now->diffInSeconds($end);

                return [$start, $end, $remainingSeconds, $periodSeconds, true];
            }
        }

        $end = $term->endAt($now);
        $periodSeconds = (int) $now->diffInSeconds($end);

        return [$now, $end, $periodSeconds, $periodSeconds, false];
    }

    private function quoteVersion(): int
    {
        return (int) config('commercial_offers.quote_version', 1);
    }

    private function fullSuitePrice(): int
    {
        return (int) config('commercial_offers.full_suite_price', 79900) * 100;
    }

    private function recommendationThreshold(): int
    {
        return (int) config('commercial_offers.full_suite_recommendation_threshold', 64000);
    }

    private function prorate(int $amountMinor, int $remainingSeconds, int $periodSeconds): int
    {
        if ($amountMinor === 0) {
            return 0;
        }

        return intdiv(($amountMinor * $remainingSeconds) + intdiv($periodSeconds, 2), $periodSeconds);
    }

    private function money(int $amountMinor): string
    {
        return sprintf('%d.%02d', intdiv($amountMinor, 100), $amountMinor % 100);
    }
}
