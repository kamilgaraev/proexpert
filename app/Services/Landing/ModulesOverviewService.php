<?php

declare(strict_types=1);

namespace App\Services\Landing;

use App\Models\Module;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationModuleActivation;
use App\Services\Modules\PackageCatalogService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class ModulesOverviewService
{
    public function __construct(
        private readonly PackageCatalogService $packageCatalog,
        private readonly PackageService $packageService,
    ) {}

    public function build(int $organizationId): array
    {
        $solutions = $this->packageService->getAllPackages($organizationId);
        $membership = $this->packageMembership();
        $activations = $this->activations($organizationId);
        $modules = Module::query()
            ->where('is_active', true)
            ->orderBy('display_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Module $module): array => $this->moduleData($module, $membership, $activations))
            ->values();
        $standalone = $modules
            ->where('classification', 'standalone')
            ->values();
        $expiringCount = collect($solutions)
            ->filter(fn (array $solution): bool => $this->isExpiringSoon($solution['current_period_end_at'] ?? null))
            ->count();

        return [
            'summary' => [
                'active_solutions_count' => collect($solutions)->where('is_active', true)->count(),
                'total_solutions_count' => count($solutions),
                'active_standalone_count' => $standalone->where('status', 'active')->count(),
                'monthly_total' => $this->monthlyTotal($organizationId, $solutions),
                'expiring_count' => $expiringCount,
            ],
            'solutions' => $solutions,
            'standalone_modules' => $standalone->all(),
            'advanced_modules' => $modules->all(),
            'warnings' => $expiringCount === 0 ? [] : [[
                'type' => 'expiring',
                'count' => $expiringCount,
            ]],
        ];
    }

    private function packageMembership(): array
    {
        $membership = [];

        foreach ($this->packageCatalog->allPackages() as $package) {
            $standard = $package['tiers']['standard'];

            foreach ($standard['included_modules'] ?? $standard['modules'] ?? [] as $moduleSlug) {
                $membership[$moduleSlug] ??= [];
                $membership[$moduleSlug][] = $package['slug'];
            }
        }

        return array_map(
            static fn (array $slugs): array => array_values(array_unique($slugs)),
            $membership,
        );
    }

    private function activations(int $organizationId): Collection
    {
        return OrganizationModuleActivation::query()
            ->where('organization_id', $organizationId)
            ->with('module')
            ->get()
            ->filter(fn (OrganizationModuleActivation $activation): bool => $activation->module !== null)
            ->keyBy(fn (OrganizationModuleActivation $activation): string => $activation->module->slug);
    }

    private function moduleData(Module $module, array $membership, Collection $activations): array
    {
        $activation = $activations->get($module->slug);
        $packageSlugs = $membership[$module->slug] ?? [];
        $isFoundation = in_array($module->slug, $this->packageCatalog->foundationModules(), true);
        $classification = $isFoundation
            ? 'foundation'
            : ($packageSlugs === [] ? 'standalone' : 'packaged');

        return [
            'slug' => $module->slug,
            'name' => $module->name,
            'description' => $module->description,
            'classification' => $classification,
            'package_slugs' => $packageSlugs,
            'is_foundation' => $isFoundation,
            'status' => $activation?->status ?? ($isFoundation ? 'active' : 'available'),
            'activation' => $activation === null ? null : [
                'status' => $activation->status,
                'activated_at' => $activation->activated_at?->toISOString(),
                'expires_at' => $activation->expires_at?->toISOString(),
            ],
            'billing_model' => $module->billing_model,
            'development_status' => $module->getDevelopmentStatusInfo(),
            'can_deactivate' => (bool) ($module->can_deactivate ?? true),
            'icon' => $module->icon,
            'category' => $module->category,
            'features' => $module->features ?? [],
        ];
    }

    private function monthlyTotal(int $organizationId, array $solutions): string
    {
        $account = OrganizationCommercialAccount::query()
            ->where('organization_id', $organizationId)
            ->first();

        if ($account?->offer_type?->value === 'full_suite') {
            return $this->money((int) config('commercial_offers.full_suite_price', 79900) * 100);
        }

        $minor = collect($solutions)
            ->where('is_active', true)
            ->sum('price_minor');

        return $this->money((int) $minor);
    }

    private function isExpiringSoon(?string $endsAt): bool
    {
        if ($endsAt === null) {
            return false;
        }

        return CarbonImmutable::parse($endsAt)->between(now(), now()->addDays(7));
    }

    private function money(int $amountMinor): string
    {
        return sprintf('%d.%02d', intdiv($amountMinor, 100), $amountMinor % 100);
    }
}
