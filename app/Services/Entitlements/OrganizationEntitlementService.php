<?php

declare(strict_types=1);

namespace App\Services\Entitlements;

use App\Models\Module;
use App\Models\OrganizationModuleActivation;
use App\Models\OrganizationPackageSubscription;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Support\Collection;

class OrganizationEntitlementService
{
    public function __construct(
        private readonly PackageCatalogService $packageCatalog
    ) {}

    public function getEffectiveModuleSlugs(int $organizationId): array
    {
        $systemSlugs = Module::query()
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->where('can_deactivate', false)
                    ->orWhere('is_system_module', true);
            })
            ->pluck('slug')
            ->all();

        $directSlugs = OrganizationModuleActivation::query()
            ->join('modules', 'modules.id', '=', 'organization_module_activations.module_id')
            ->where('organization_module_activations.organization_id', $organizationId)
            ->where('organization_module_activations.status', 'active')
            ->where('organization_module_activations.is_bundled_with_plan', false)
            ->where('modules.is_active', true)
            ->where(function ($query): void {
                $query->whereNull('organization_module_activations.expires_at')
                    ->orWhere('organization_module_activations.expires_at', '>', now());
            })
            ->pluck('modules.slug')
            ->all();

        $slugs = array_values(array_unique(array_merge(
            $systemSlugs,
            $this->packageCatalog->foundationModules(),
            $this->getAlwaysOnModuleSlugs(),
            $directSlugs,
            array_keys($this->getPackageModuleSources($organizationId))
        )));

        if ($slugs === []) {
            return [];
        }

        return Module::query()
            ->where('is_active', true)
            ->whereIn('slug', $slugs)
            ->pluck('slug')
            ->all();
    }

    public function getEffectiveModules(int $organizationId): Collection
    {
        $slugs = $this->getEffectiveModuleSlugs($organizationId);

        if ($slugs === []) {
            return collect();
        }

        return Module::query()
            ->where('is_active', true)
            ->whereIn('slug', $slugs)
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();
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

        $packageSubscriptions = OrganizationPackageSubscription::query()
            ->where('organization_id', $organizationId)
            ->active()
            ->orderBy('package_slug')
            ->orderBy('id')
            ->get();

        foreach ($packageSubscriptions as $packageSubscription) {
            $this->appendPackageModuleSources($sources, $packageSubscription);
        }

        return $sources;
    }

    private function appendPackageModuleSources(
        array &$sources,
        OrganizationPackageSubscription $packageSubscription
    ): void {
        $packageSlug = $packageSubscription->package_slug;

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
