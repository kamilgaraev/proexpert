<?php

declare(strict_types=1);

namespace App\Services\Entitlements;

use App\Models\Module;
use App\Models\OrganizationPackageSubscription;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class OrganizationEntitlementService
{
    public function __construct(
        private readonly PackageCatalogService $packageCatalog
    ) {}

    public function getEffectiveModuleSlugs(int $organizationId): array
    {
        return $this->effectiveModuleQuery($organizationId)->pluck('slug')->all();
    }

    public function getEffectiveModuleSlugsMatching(int $organizationId, string $moduleSlug): array
    {
        if (get_class($this) !== self::class
            || strlen($moduleSlug) > 1024
            || str_contains($moduleSlug, "\0")
            || preg_match('//u', $moduleSlug) !== 1) {
            $slugs = $this->getEffectiveModuleSlugs($organizationId);
        } else {
            $slugs = $this->effectiveModuleQuery($organizationId)->where('modules.slug', $moduleSlug)->pluck('slug')->all();
        }

        return array_values(array_filter($slugs, static fn (mixed $slug): bool => $slug === $moduleSlug));
    }

    public function getEffectiveModules(int $organizationId): Collection
    {
        return $this->effectiveModuleQuery($organizationId)
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();
    }

    private function effectiveModuleQuery(int $organizationId): Builder
    {
        $slugs = array_values(array_unique(array_merge(
            $this->packageCatalog->foundationModules(),
            $this->getAlwaysOnModuleSlugs(),
        )));
        foreach (app(\App\Services\Billing\Bank\BankPaymentProofAccess::class)->packageSlugs($organizationId) as $packageSlug) {
            $slugs = array_merge($slugs, $this->packageCatalog->tierModules($packageSlug, 'standard'));
        }

        $packages = [];
        $subscriptionSlugs = array_unique(array_merge(
            array_column($this->packageCatalog->allPackages(), 'slug'),
            $this->packageCatalog->retiredEntryPackageSlugs(),
        ));
        foreach ($subscriptionSlugs as $subscriptionSlug) {
            $packageSlug = $this->packageCatalog->canonicalizePackageSlug($subscriptionSlug);
            if ($this->packageCatalog->package($packageSlug) === null) {
                continue;
            }
            $modules = $this->packageCatalog->tierModules($packageSlug, 'standard');
            if ($modules === []) {
                continue;
            }
            $packages[$packageSlug]['slugs'][] = $subscriptionSlug;
            $packages[$packageSlug]['modules'] = $modules;
        }
        $subscriptions = $packages === [] ? null : $this->activePackageSubscriptionQuery($organizationId)
            ->selectRaw('1')->where(static function (Builder $query) use ($packages): void {
                foreach ($packages as $package) {
                    $query->orWhere(static function (Builder $subscription) use ($package): void {
                        $subscription->whereIn('organization_package_subscriptions.package_slug', $package['slugs'])
                            ->whereIn('modules.slug', $package['modules']);
                    });
                }
            });

        return Module::query()->where('is_active', true)->where(static function (Builder $query) use ($slugs, $subscriptions): void {
            $query->where('can_deactivate', false)->orWhere('is_system_module', true);
            if ($slugs !== []) {
                $query->orWhereIn('slug', $slugs);
            }
            if ($subscriptions !== null) {
                $query->orWhereExists($subscriptions->toBase());
            }
        });
    }

    public function hasModuleAccess(int $organizationId, string $moduleSlug): bool
    {
        return in_array($moduleSlug, $this->getEffectiveModuleSlugs($organizationId), true);
    }

    public function hasModulePermission(int $organizationId, string $permission): bool
    {
        foreach ($this->getEffectiveModules($organizationId) as $module) {
            foreach ((array) $module->permissions as $modulePermission) {
                if ($modulePermission === $permission) {
                    return true;
                }
            }
        }

        return false;
    }

    public function getPackageModuleSources(int $organizationId): array
    {
        $sources = [];

        $packageSubscriptions = $this->activePackageSubscriptionQuery($organizationId)
            ->orderBy('package_slug')
            ->orderBy('id')
            ->get();

        foreach ($packageSubscriptions as $packageSubscription) {
            $this->appendPackageModuleSources($sources, $packageSubscription);
        }

        foreach (app(\App\Services\Billing\Bank\BankPaymentProofAccess::class)->grants([$organizationId]) as $proof) {
            foreach ($proof->package_slugs as $packageSlug) {
                foreach ($this->packageCatalog->tierModules($packageSlug, 'standard') as $moduleSlug) {
                    $candidate = ['module_slug' => $moduleSlug, 'package_slug' => $packageSlug,
                        'commercial_account_id' => $proof->commercial_account_id, 'expires_at' => $proof->temporary_expires_at,
                        'access_source' => 'bank_payment_pending'];
                    if ($this->shouldUseSource($sources[$moduleSlug] ?? null, $candidate)) {
                        $sources[$moduleSlug] = $candidate;
                    }
                }
            }
        }

        return $sources;
    }

    private function activePackageSubscriptionQuery(int $organizationId): Builder
    {
        return OrganizationPackageSubscription::query()
            ->where('organization_id', $organizationId)
            ->whereHas('commercialAccount', function ($account): void {
                $account->whereColumn(
                    'organization_commercial_accounts.organization_id',
                    'organization_package_subscriptions.organization_id',
                );
            })
            ->active();
    }

    private function appendPackageModuleSources(
        array &$sources,
        OrganizationPackageSubscription $packageSubscription
    ): void {
        $packageSlug = $this->packageCatalog->canonicalizePackageSlug($packageSubscription->package_slug);

        if ($this->packageCatalog->package($packageSlug) === null) {
            return;
        }

        foreach ($this->packageCatalog->tierModules($packageSlug, 'standard') as $moduleSlug) {
            $candidate = [
                'module_slug' => $moduleSlug,
                'package_slug' => $packageSlug,
                'commercial_account_id' => $packageSubscription->commercial_account_id,
                'expires_at' => $packageSubscription->status->value === 'trialing'
                    ? $packageSubscription->trial_ends_at
                    : $packageSubscription->current_period_end_at,
                'access_source' => $packageSubscription->access_source->value,
            ];

            if ($this->shouldUseSource($sources[$moduleSlug] ?? null, $candidate)) {
                $sources[$moduleSlug] = $candidate;
            }
        }
    }

    private function shouldUseSource(?array $current, array $candidate): bool
    {
        if ($current === null) {
            return true;
        }

        $currentExpiresAt = $current['expires_at'] ?? null;
        $candidateExpiresAt = $candidate['expires_at'] ?? null;

        if ($currentExpiresAt === null) {
            return false;
        }

        if ($candidateExpiresAt === null) {
            return true;
        }

        return $candidateExpiresAt->greaterThan($currentExpiresAt);
    }

    private function getAlwaysOnModuleSlugs(): array
    {
        return collect($this->packageCatalog->moduleDefinitions())
            ->filter(static fn (array $module): bool => ($module['auto_activate'] ?? false) === true
                || ($module['is_system_module'] ?? false) === true
                || ($module['can_deactivate'] ?? true) === false)
            ->keys()
            ->values()
            ->all();
    }
}
