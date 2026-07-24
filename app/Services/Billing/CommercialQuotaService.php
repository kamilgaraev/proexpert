<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Exceptions\Billing\CommercialQuotaExceededException;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use App\Models\OrganizationResourceAllocation;
use App\Models\Project;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

use function trans_message;

class CommercialQuotaService
{
    public function __construct(
        private readonly PackageCatalogService $packageCatalog,
    ) {}

    public function getEffectiveLimits(Organization $organization): array
    {
        $summary = $this->buildLimitSummary($organization, $this->getUsage($organization));

        return array_combine(
            array_column($summary, 'key'),
            array_column($summary, 'limit'),
        );
    }

    public function getUsage(Organization $organization): array
    {
        $organizationId = (int) $organization->getKey();

        return [
            'users' => $this->activeUsers($organizationId),
            'projects' => $this->tableCount(Project::query()->where('organization_id', $organizationId), 'projects'),
            'storage_gb' => round(((float) ($organization->storage_used_mb ?? 0)) / 1024, 2),
            'contractors' => class_exists(Contractor::class)
                ? $this->tableCount(Contractor::query()->where('organization_id', $organizationId), 'contractors')
                : 0,
            'holding_organizations' => Schema::hasColumn('organizations', 'parent_organization_id')
                ? Organization::query()->where('parent_organization_id', $organizationId)->count()
                : 0,
            'ai_requests_month' => 0,
            'ai_estimates_month' => 0,
            'document_pages_month' => 0,
            'exports_month' => 0,
            'commercial_proposals_month' => 0,
        ];
    }

    public function getQuotaSummary(Organization $organization): array
    {
        $account = $this->commercialAccount($organization);
        $packageMonthlyMinor = $this->packageMonthlyAmountMinor($organization);
        $resourceMonthlyMinor = $this->paidAddonMonthlyAmountMinor($organization);

        return [
            'account_status' => $account?->status->value ?? 'free',
            'offer_type' => $account?->offer_type->value ?? 'packages',
            'monthly_package_amount_minor' => $packageMonthlyMinor,
            'monthly_package_amount' => $this->money($packageMonthlyMinor),
            'monthly_resource_amount_minor' => $resourceMonthlyMinor,
            'monthly_resource_amount' => $this->money($resourceMonthlyMinor),
            'currency' => (string) config('commercial_limits.currency', 'RUB'),
            'period' => [
                'start_at' => $account?->current_period_start_at?->toJSON(),
                'end_at' => $account?->current_period_end_at?->toJSON(),
            ],
            'limits' => $this->buildLimitSummary($organization, $this->getUsage($organization)),
            'resource_addons' => $this->resourceAddons($organization),
        ];
    }

    public function assertCanUse(Organization $organization, string $limitKey, int|float $delta = 1): void
    {
        $summary = $this->getQuotaSummary($organization);
        $limit = $this->findLimit($summary['limits'], $limitKey);

        if ($limit === null || $limit['enforcement'] !== 'hard' || $limit['limit'] === null) {
            return;
        }

        if (((float) $limit['used'] + $delta) > (float) $limit['limit']) {
            throw new CommercialQuotaExceededException(
                $limitKey,
                $limit['used'],
                $limit['limit'],
                $delta,
            );
        }
    }

    public function calculateResourceAddonQuote(
        Organization $organization,
        array $requestedResources,
        ?array $packageSlugs = null,
    ): array
    {
        $resources = $this->configuredResources();
        $availablePackageSlugs = $packageSlugs === null
            ? $this->activePackageSlugs((int) $organization->getKey())
            : $this->normalizePackageSlugs($packageSlugs);
        $items = [];
        $totalMinor = 0;
        $requiresManager = false;

        foreach ($requestedResources as $requested) {
            $slug = is_array($requested) ? (string) ($requested['slug'] ?? '') : '';
            $quantity = is_array($requested) && is_numeric($requested['quantity'] ?? null)
                ? (float) $requested['quantity']
                : -1;

            if (! isset($resources[$slug])) {
                throw new InvalidArgumentException('Resource is not configured.');
            }

            $resource = $resources[$slug];
            $status = 'ok';
            $amountMinor = 0;

            if ($quantity < (float) $resource['min'] || fmod($quantity, (float) $resource['step']) !== 0.0) {
                throw new InvalidArgumentException('Resource quantity is invalid.');
            }

            if ($resource['requires_package'] !== null && ! in_array($resource['requires_package'], $availablePackageSlugs, true)) {
                $status = 'package_required';
                $requiresManager = true;
            } elseif ($quantity > (float) $resource['max_self_service']) {
                $status = 'requires_manager';
                $requiresManager = true;
            } else {
                $amountMinor = (int) round($quantity * (int) $resource['price_minor']);
                $totalMinor += $amountMinor;
            }

            $items[] = [
                'slug' => $slug,
                'limit_key' => $resource['limit_key'],
                'quantity' => $this->number($quantity),
                'amount_minor' => $amountMinor,
                'amount' => $this->money($amountMinor),
                'currency' => (string) config('commercial_limits.currency', 'RUB'),
                'status' => $status,
                'requires_package' => $resource['requires_package'],
            ];
        }

        return [
            'amount_minor' => $totalMinor,
            'amount' => $this->money($totalMinor),
            'currency' => (string) config('commercial_limits.currency', 'RUB'),
            'items' => $items,
            'requires_manager' => $requiresManager,
            'quote_version' => (int) config('commercial_limits.quote_version', 1),
        ];
    }

    private function buildLimitSummary(Organization $organization, array $usage): array
    {
        $organizationId = (int) $organization->getKey();
        $free = config('commercial_limits.free', []);
        $metadata = config('commercial_limits.limits', []);
        $packageLimits = $this->packageLimits($organizationId);
        $paidAddons = $this->allocationTotals($organizationId, 'paid_addon');
        $manualGrants = $this->allocationTotals($organizationId, 'manual_grant');
        $corporateOverrides = $this->corporateOverrides($organizationId);
        $limits = [];

        foreach ($metadata as $key => $definition) {
            $base = $this->number((float) ($free[$key] ?? 0));
            $fromPackages = $this->number((float) ($packageLimits[$key] ?? 0));
            $fromPaid = $this->number((float) (($paidAddons[$key] ?? 0) + ($manualGrants[$key] ?? 0)));
            $overrideExists = array_key_exists($key, $corporateOverrides);
            $override = $overrideExists ? $corporateOverrides[$key] : null;
            $limit = $overrideExists
                ? ($override === null ? null : $this->number((float) $override))
                : $this->number((float) $base + (float) $fromPackages + (float) $fromPaid);
            $used = $this->number((float) ($usage[$key] ?? 0));
            $remaining = $limit === null ? null : $this->number(max(0, (float) $limit - (float) $used));

            $limits[] = [
                'key' => $key,
                'name' => trans_message((string) $definition['name_key']),
                'unit' => $definition['unit'],
                'used' => $used,
                'limit' => $limit,
                'remaining' => $remaining,
                'percent' => $limit === null || (float) $limit <= 0 ? 0 : (int) min(100, round(((float) $used / (float) $limit) * 100)),
                'status' => $this->status($used, $limit),
                'enforcement' => $definition['enforcement'],
                'sources' => [
                    'free_base' => $base,
                    'packages' => $fromPackages,
                    'paid_addons' => $fromPaid,
                    'corporate_override' => $overrideExists ? ($override === null ? null : $this->number((float) $override)) : null,
                ],
            ];
        }

        return $limits;
    }

    private function resourceAddons(Organization $organization): array
    {
        $organizationId = (int) $organization->getKey();
        $activePackages = $this->activePackageSlugs($organizationId);
        $currentQuantities = $this->allocationTotals($organizationId, 'paid_addon');
        $resources = array_values($this->configuredResources());

        usort($resources, static fn (array $a, array $b): int => $a['sort_order'] <=> $b['sort_order']);

        return array_map(function (array $resource) use ($activePackages, $currentQuantities): array {
            $requiresPackage = $resource['requires_package'];

            return [
                'slug' => $resource['slug'],
                'limit_key' => $resource['limit_key'],
                'name' => trans_message((string) $resource['name_key']),
                'unit' => $resource['unit'],
                'current_quantity' => $this->number((float) ($currentQuantities[$resource['limit_key']] ?? 0)),
                'step' => $this->number((float) $resource['step']),
                'min' => $this->number((float) $resource['min']),
                'max_self_service' => $this->number((float) $resource['max_self_service']),
                'requires_package' => $requiresPackage,
                'available' => $requiresPackage === null || in_array($requiresPackage, $activePackages, true),
                'pricing' => [
                    'model' => $resource['pricing_model'],
                    'currency' => (string) config('commercial_limits.currency', 'RUB'),
                    'price_minor' => (int) $resource['price_minor'],
                    'amount' => $this->money((int) $resource['price_minor']),
                ],
            ];
        }, $resources);
    }

    private function configuredResources(): array
    {
        $configured = config('commercial_limits.resources', []);
        $resources = [];

        foreach ($configured as $slug => $resource) {
            if (! is_string($slug) || ! is_array($resource)) {
                continue;
            }

            $resources[$slug] = $resource + ['slug' => $slug];
        }

        return $resources;
    }

    private function packageLimits(int $organizationId): array
    {
        $limits = [];

        foreach ($this->activePackageSlugs($organizationId) as $slug) {
            $package = $this->packageCatalog->package($slug);

            foreach (($package['limits'] ?? []) as $key => $value) {
                $limits[$key] = ($limits[$key] ?? 0) + (float) $value;
            }
        }

        return $limits;
    }

    private function normalizePackageSlugs(array $packageSlugs): array
    {
        $available = [];

        foreach ($this->packageCatalog->allPackages() as $package) {
            if (is_string($package['slug'] ?? null)) {
                $available[$package['slug']] = true;
            }
        }

        $normalized = [];

        foreach ($packageSlugs as $slug) {
            if (is_string($slug) && isset($available[$slug])) {
                $normalized[$slug] = true;
            }
        }

        return array_keys($normalized);
    }

    private function activePackageSlugs(int $organizationId): array
    {
        if (! Schema::hasTable('organization_package_subscriptions')) {
            return [];
        }

        return OrganizationPackageSubscription::query()
            ->where('organization_id', $organizationId)
            ->where(function ($query): void {
                $query->where(function ($period): void {
                    $period->whereIn('status', ['active', 'scheduled_for_removal', 'grace'])
                        ->where(function ($dates): void {
                            $dates->whereNull('current_period_end_at')
                                ->orWhere('current_period_end_at', '>', now());
                        });
                })->orWhere(function ($trial): void {
                    $trial->where('status', 'trialing')
                        ->whereNotNull('trial_ends_at')
                        ->where('trial_ends_at', '>', now());
                });
            })
            ->orderBy('package_slug')
            ->pluck('package_slug')
            ->all();
    }

    private function allocationTotals(int $organizationId, string $source): array
    {
        if (! Schema::hasTable('organization_resource_allocations')) {
            return [];
        }

        return OrganizationResourceAllocation::query()
            ->where('organization_id', $organizationId)
            ->where('source', $source)
            ->active()
            ->select('limit_key', DB::raw('SUM(quantity) as quantity'))
            ->groupBy('limit_key')
            ->pluck('quantity', 'limit_key')
            ->map(static fn (mixed $quantity): float => (float) $quantity)
            ->all();
    }

    private function corporateOverrides(int $organizationId): array
    {
        if (! Schema::hasTable('organization_resource_allocations')) {
            return [];
        }

        return OrganizationResourceAllocation::query()
            ->where('organization_id', $organizationId)
            ->where('source', 'corporate_override')
            ->active()
            ->orderByDesc('id')
            ->get(['limit_key', 'quantity'])
            ->unique('limit_key')
            ->mapWithKeys(static fn (OrganizationResourceAllocation $allocation): array => [
                $allocation->limit_key => $allocation->quantity === null ? null : (float) $allocation->quantity,
            ])
            ->all();
    }

    private function commercialAccount(Organization $organization): ?OrganizationCommercialAccount
    {
        if (! Schema::hasTable('organization_commercial_accounts')) {
            return null;
        }

        return OrganizationCommercialAccount::query()
            ->where('organization_id', $organization->getKey())
            ->first();
    }

    private function packageMonthlyAmountMinor(Organization $organization): int
    {
        $total = 0;

        foreach ($this->activePackageSlugs((int) $organization->getKey()) as $slug) {
            $package = $this->packageCatalog->package($slug);
            $total += (int) (($package['tiers']['standard']['price'] ?? 0) * 100);
        }

        return $total;
    }

    private function paidAddonMonthlyAmountMinor(Organization $organization): int
    {
        $resourcesByLimit = [];

        foreach ($this->configuredResources() as $resource) {
            $resourcesByLimit[$resource['limit_key']] = $resource;
        }

        $total = 0;

        foreach ($this->allocationTotals((int) $organization->getKey(), 'paid_addon') as $limitKey => $quantity) {
            $total += (int) round($quantity * (int) ($resourcesByLimit[$limitKey]['price_minor'] ?? 0));
        }

        return $total;
    }

    private function activeUsers(int $organizationId): int
    {
        if (! Schema::hasTable('organization_user')) {
            return 0;
        }

        return DB::table('organization_user')
            ->join('users', 'users.id', '=', 'organization_user.user_id')
            ->where('organization_user.organization_id', $organizationId)
            ->where('organization_user.is_active', true)
            ->where('users.is_active', true)
            ->whereNull('users.deleted_at')
            ->count();
    }

    private function tableCount($query, string $table): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        return $query->count();
    }

    private function findLimit(array $limits, string $key): ?array
    {
        foreach ($limits as $limit) {
            if ($limit['key'] === $key) {
                return $limit;
            }
        }

        return null;
    }

    private function status(int|float $used, int|float|null $limit): string
    {
        if ($limit === null || (float) $limit <= 0) {
            return $limit === 0 && (float) $used > 0 ? 'exceeded' : 'ok';
        }

        $percent = ((float) $used / (float) $limit) * 100;

        return match (true) {
            $percent >= 100 => 'exceeded',
            $percent >= 90 => 'critical',
            $percent >= 50 => 'warning',
            default => 'ok',
        };
    }

    private function number(float $value): int|float
    {
        return fmod($value, 1.0) === 0.0 ? (int) $value : $value;
    }

    private function money(int $amountMinor): string
    {
        return sprintf('%d.%02d', intdiv($amountMinor, 100), $amountMinor % 100);
    }
}
