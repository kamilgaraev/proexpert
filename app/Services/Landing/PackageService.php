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

        $entrySlug = $this->packageCatalog->entryPackageSlug();
        $retiredSlugs = $this->packageCatalog->retiredEntryPackageSlugs();
        $subscriptions = $subscriptions->mapWithKeys(function ($subscription, $slug) {
            return [$this->packageCatalog->canonicalizePackageSlug((string) $slug) => $subscription];
        });
        $entryUsed = $usedTrials->has($entrySlug) || collect($retiredSlugs)->contains(
            static fn (string $slug): bool => $usedTrials->has($slug),
        );
        $paidEntry = $subscriptions->contains(function ($subscription) use ($entrySlug): bool {
            $source = $subscription->access_source->value ?? $subscription->access_source;

            return $this->packageCatalog->canonicalizePackageSlug((string) $subscription->package_slug) === $entrySlug
                && in_array($source, ['paid_package', 'full_suite', 'corporate'], true);
        });

        return collect($this->packageCatalog->allPackages())
            ->map(function (array $package) use ($subscriptions, $usedTrials, $entrySlug, $entryUsed, $paidEntry): array {
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
                    'modules' => $this->moduleSummaries(
                        $standard['included_modules'] ?? $standard['modules'] ?? [],
                    ),
                    'highlights' => $standard['highlights'] ?? [],
                    'business_outcomes' => $package['business_outcomes'] ?? [],
                    'is_active' => $subscription !== null,
                    'status' => $subscription?->status?->value,
                    'access_source' => $subscription?->access_source?->value,
                    'current_period_start_at' => $subscription?->current_period_start_at?->toISOString(),
                    'current_period_end_at' => $subscription?->current_period_end_at?->toISOString(),
                    'trial_ends_at' => $subscription?->trial_ends_at?->toISOString(),
                    'trial_used' => $package['slug'] === $entrySlug
                        ? $entryUsed
                        : $usedTrials->has($package['slug']),
                    'trial_available' => $package['slug'] === $entrySlug
                        ? ! $entryUsed
                        : (! $usedTrials->has($package['slug']) && $paidEntry),
                ];
            })
            ->values()
            ->all();
    }

    private function moduleSummaries(array $slugs): array
    {
        $definitions = $this->packageCatalog->moduleDefinitions();

        return collect($slugs)
            ->map(static function (string $slug) use ($definitions): array {
                $definition = $definitions[$slug] ?? [];

                return [
                    'slug' => $slug,
                    'name' => trim((string) ($definition['name'] ?? 'Возможность МОСТ')),
                    'description' => trim((string) (
                        $definition['description'] ?? 'Возможность входит в состав пакета МОСТ.'
                    )),
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
