<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use App\Services\Auth\JwtTokenIssuer;
use App\Services\Modules\PackageCatalogService;

final readonly class MobileProjectRoleTestContext
{
    private function __construct(
        public Organization $organization,
        public User $user,
        public Project $project,
        private string $token,
    ) {}

    public static function create(string $roleSlug): self
    {
        $organization = Organization::factory()->verified()->create();
        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
        ]);
        $organization->users()->attach($user->id, [
            'is_owner' => false,
            'is_active' => true,
            'settings' => null,
        ]);
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
        ]);
        $user->assignedProjects()->attach($project->id, [
            'is_active' => true,
            'role' => 'member',
            'assigned_by_user_id' => $user->id,
            'assigned_at' => now(),
        ]);
        UserRoleAssignment::assignRole(
            $user,
            $roleSlug,
            AuthorizationContext::getProjectContext((int) $project->id, (int) $organization->id),
        );
        $mobileAccessRole = OrganizationCustomRole::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Mobile test access without project permissions',
            'slug' => 'mobile_test_access',
            'description' => 'Allows the mobile route guard without granting organization permissions.',
            'system_permissions' => [],
            'module_permissions' => [],
            'interface_access' => ['mobile'],
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        UserRoleAssignment::assignRole(
            $user,
            $mobileAccessRole->slug,
            AuthorizationContext::getOrganizationContext((int) $organization->id),
            UserRoleAssignment::TYPE_CUSTOM,
        );
        $token = app(JwtTokenIssuer::class)->issue($user, [
            'guard' => 'api_mobile',
            'organization_id' => $organization->id,
        ]);

        $context = new self($organization, $user, $project, $token);
        $context->activateFoundationModules();

        return $context;
    }

    public function headers(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ];
    }

    /** @param list<string> $packageSlugs */
    public function activatePackages(array $packageSlugs): void
    {
        $now = now();
        $account = OrganizationCommercialAccount::query()->create([
            'organization_id' => $this->organization->id,
            'responsible_user_id' => $this->user->id,
            'status' => 'active',
            'offer_type' => 'packages',
            'quote_version' => 1,
            'current_period_start_at' => $now,
            'current_period_end_at' => $now->copy()->addDays(30),
        ]);
        $catalog = app(PackageCatalogService::class);
        $moduleSlugs = [];

        foreach ($packageSlugs as $packageSlug) {
            foreach ($catalog->tierModules($packageSlug, 'standard') as $moduleSlug) {
                $moduleSlugs[$moduleSlug] = true;
            }

            OrganizationPackageSubscription::query()->create([
                'organization_id' => $this->organization->id,
                'commercial_account_id' => $account->id,
                'package_slug' => $packageSlug,
                'status' => 'active',
                'access_source' => 'paid_package',
                'price_paid' => 0,
                'current_period_start_at' => $now,
                'current_period_end_at' => $now->copy()->addDays(30),
            ]);
        }

        $definitions = $catalog->moduleDefinitions();
        foreach (array_keys($moduleSlugs) as $moduleSlug) {
            if (isset($definitions[$moduleSlug])) {
                $this->registerModuleDefinition($moduleSlug, $definitions[$moduleSlug]);
            }
        }

        $access = app(AccessController::class);
        $access->clearAccessCache((int) $this->organization->id);
        foreach (array_keys($moduleSlugs) as $moduleSlug) {
            if (! $access->hasModuleAccess((int) $this->organization->id, $moduleSlug)) {
                throw new \RuntimeException("Package did not activate module {$moduleSlug}.");
            }
        }
    }

    /** @param list<string> $moduleSlugs */
    public function activateAutoModules(array $moduleSlugs): void
    {
        $definitions = app(PackageCatalogService::class)->moduleDefinitions();
        foreach ($moduleSlugs as $moduleSlug) {
            $definition = $definitions[$moduleSlug] ?? null;
            if ($definition === null || ! ($definition['auto_activate'] ?? false)) {
                throw new \InvalidArgumentException("Module {$moduleSlug} is not configured for auto activation.");
            }

            $this->registerModuleDefinition($moduleSlug, $definition);
        }

        $access = app(AccessController::class);
        $access->clearAccessCache((int) $this->organization->id);
        foreach ($moduleSlugs as $moduleSlug) {
            if (! $access->hasModuleAccess((int) $this->organization->id, $moduleSlug)) {
                throw new \RuntimeException("Auto activation did not enable module {$moduleSlug}.");
            }
        }
    }

    private function activateFoundationModules(): void
    {
        $catalog = app(PackageCatalogService::class);
        $definitions = $catalog->moduleDefinitions();
        foreach ($catalog->foundationModules() as $moduleSlug) {
            if (isset($definitions[$moduleSlug])) {
                $this->registerModuleDefinition($moduleSlug, $definitions[$moduleSlug]);
            }
        }

        app(AccessController::class)->clearAccessCache((int) $this->organization->id);
    }

    /** @param array<string, mixed> $definition */
    private function registerModuleDefinition(string $slug, array $definition): void
    {
        Module::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'name' => $definition['name'],
                'version' => $definition['version'] ?? '1.0.0',
                'type' => $definition['type'],
                'billing_model' => $definition['billing_model'] ?? 'free',
                'category' => $definition['category'] ?? 'general',
                'description' => $definition['description'] ?? null,
                'pricing_config' => $definition['pricing'] ?? null,
                'features' => $definition['features'] ?? null,
                'permissions' => $definition['permissions'] ?? [],
                'dependencies' => $definition['dependencies'] ?? [],
                'conflicts' => $definition['conflicts'] ?? [],
                'limits' => $definition['limits'] ?? [],
                'class_name' => $definition['class_name'] ?? null,
                'config_file' => $definition['_config_file'] ?? null,
                'icon' => $definition['icon'] ?? null,
                'display_order' => $definition['display_order'] ?? 0,
                'is_active' => true,
                'is_system_module' => $definition['is_system_module'] ?? false,
                'can_deactivate' => $definition['can_deactivate'] ?? true,
            ],
        );
    }
}
