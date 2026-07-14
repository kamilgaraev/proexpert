<?php

declare(strict_types=1);

namespace App\Services\Landing;

use App\Models\OrganizationPackageSubscription;
use App\Models\OrganizationPackageTrialUsage;
use App\Services\Modules\PackageCatalogService;

class PackageService
{
    public function __construct(
        private readonly PackageCatalogService $packageCatalog,
    ) {}

    public function getAllPackages(int $organizationId): array
    {
        $subscriptions = OrganizationPackageSubscription::query()
            ->where('organization_id', $organizationId)
            ->whereHas('commercialAccount', function ($account): void {
                $account->whereColumn(
                    'organization_commercial_accounts.organization_id',
                    'organization_package_subscriptions.organization_id',
                );
            })
            ->active()
            ->get()
            ->keyBy('package_slug');
        $usedTrials = OrganizationPackageTrialUsage::query()
            ->where('organization_id', $organizationId)
            ->pluck('package_slug')
            ->merge(OrganizationPackageSubscription::query()
                ->where('organization_id', $organizationId)
                ->pluck('package_slug'))
            ->unique()
            ->flip();

        return collect($this->packageCatalog->allPackages())
            ->map(function (array $package) use ($subscriptions, $usedTrials): array {
                $subscription = $subscriptions->get($package['slug']);
                $standard = $package['tiers']['standard'];
                $priceMinor = (int) $standard['price'] * 100;

                return [
                    'slug' => $package['slug'],
                    'name' => $package['name'],
                    'description' => $package['description'],
                    'sort_order' => $package['sort_order'] ?? 99,
                    'price' => $this->money($priceMinor),
                    'price_minor' => $priceMinor,
                    'currency' => (string) config('commercial_offers.currency', 'RUB'),
                    'billing_period_days' => (int) config('commercial_offers.billing_period_days', 30),
                    'modules' => $standard['included_modules'] ?? $standard['modules'] ?? [],
                    'highlights' => $standard['highlights'] ?? [],
                    'business_outcomes' => $package['business_outcomes'] ?? [],
                    'is_active' => $subscription !== null,
                    'status' => $subscription?->status?->value,
                    'access_source' => $subscription?->access_source?->value,
                    'current_period_start_at' => $subscription?->current_period_start_at?->toISOString(),
                    'current_period_end_at' => $subscription?->current_period_end_at?->toISOString(),
                    'trial_ends_at' => $subscription?->trial_ends_at?->toISOString(),
                    'trial_used' => $usedTrials->has($package['slug']),
                    'trial_available' => ! $usedTrials->has($package['slug']),
                ];
            })
            ->values()
            ->all();
    }

    private function money(int $amountMinor): string
    {
        return sprintf('%d.%02d', intdiv($amountMinor, 100), $amountMinor % 100);
    }
}
