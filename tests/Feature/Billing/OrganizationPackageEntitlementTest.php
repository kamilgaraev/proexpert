<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModuleActivation;
use App\Models\OrganizationPackageSubscription;
use App\Models\OrganizationSubscription;
use App\Models\SubscriptionPlan;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Modules\PackageCatalogService;
use App\Services\SubscriptionModuleSyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrganizationPackageEntitlementTest extends TestCase
{
    private Organization $organization;

    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();
        $this->organization = $this->createOrganization('Основная организация');
    }

    public function test_foundation_is_available_without_paid_packages(): void
    {
        $foundation = app(PackageCatalogService::class)->foundationModules();
        $this->createModules($foundation);

        $this->assertEqualsCanonicalizing(
            $foundation,
            $this->entitlements()->getEffectiveModuleSlugs($this->organization->id)
        );
    }

    public function test_active_direct_package_uses_standard_catalog_regardless_of_stored_tier(): void
    {
        $packageModules = $this->createPackageModules('estimates-norms');
        $this->createPackageSubscription('estimates-norms', 'legacy-enterprise');

        $this->assertEqualsCanonicalizing(
            $packageModules,
            array_values(array_intersect(
                $packageModules,
                $this->entitlements()->getEffectiveModuleSlugs($this->organization->id)
            ))
        );

        $sources = $this->entitlements()->getPackageModuleSources($this->organization->id);

        $this->assertSame('paid_package', $sources['budget-estimates']['access_source']);
        $this->assertNull($sources['budget-estimates']['subscription_id']);
        $this->assertArrayNotHasKey('tier', $sources['budget-estimates']);
        $this->assertArrayNotHasKey('is_bundled_with_plan', $sources['budget-estimates']);
    }

    public function test_legacy_plan_and_bundled_rows_do_not_grant_package_access(): void
    {
        $this->createPackageModules('estimates-norms');
        $plan = $this->createPlan([
            ['package_slug' => 'estimates-norms', 'tier' => 'standard'],
        ]);
        $subscription = $this->createLegacySubscription($plan);

        OrganizationPackageSubscription::create([
            'organization_id' => $this->organization->id,
            'subscription_id' => $subscription->id,
            'is_bundled_with_plan' => true,
            'package_slug' => 'estimates-norms',
            'tier' => 'standard',
            'price_paid' => 0,
            'activated_at' => now(),
            'expires_at' => now()->addMonth(),
        ]);

        $budgetModule = Module::where('slug', 'budget-estimates')->firstOrFail();
        OrganizationModuleActivation::create([
            'organization_id' => $this->organization->id,
            'module_id' => $budgetModule->id,
            'subscription_id' => $subscription->id,
            'is_bundled_with_plan' => true,
            'status' => 'active',
            'activated_at' => now(),
            'expires_at' => now()->addMonth(),
            'paid_amount' => 0,
        ]);

        $this->assertNotContains(
            'budget-estimates',
            $this->entitlements()->getEffectiveModuleSlugs($this->organization->id)
        );
        $this->assertSame([], $this->entitlements()->getPackageModuleSources($this->organization->id));
    }

    public function test_expired_unknown_and_foreign_packages_do_not_grant_access(): void
    {
        $this->createPackageModules('estimates-norms');
        $foreignOrganization = $this->createOrganization('Другая организация');

        $this->createPackageSubscription('estimates-norms', 'standard', now()->subDay());
        $this->createPackageSubscription('unknown-package', 'standard', now()->addMonth());
        $this->createPackageSubscription(
            'estimates-norms',
            'standard',
            now()->addMonth(),
            $foreignOrganization->id
        );

        $this->assertNotContains(
            'budget-estimates',
            $this->entitlements()->getEffectiveModuleSlugs($this->organization->id)
        );
        $this->assertContains(
            'budget-estimates',
            $this->entitlements()->getEffectiveModuleSlugs($foreignOrganization->id)
        );
    }

    public function test_shared_module_remains_available_until_all_direct_packages_expire(): void
    {
        $this->createModules(['budget-estimates']);
        $estimates = $this->createPackageSubscription('estimates-norms', 'standard', now()->addDays(5));
        $finance = $this->createPackageSubscription('finance-contracts', 'standard', now()->addDays(20));

        $this->assertContains(
            'budget-estimates',
            $this->entitlements()->getEffectiveModuleSlugs($this->organization->id)
        );
        $this->assertSame(
            'finance-contracts',
            $this->entitlements()->getPackageModuleSources($this->organization->id)['budget-estimates']['package_slug']
        );

        $estimates->update(['expires_at' => now()->subDay()]);

        $this->assertContains(
            'budget-estimates',
            $this->entitlements()->getEffectiveModuleSlugs($this->organization->id)
        );

        $finance->update(['expires_at' => now()->subDay()]);

        $this->assertNotContains(
            'budget-estimates',
            $this->entitlements()->getEffectiveModuleSlugs($this->organization->id)
        );
    }

    public function test_direct_module_activation_remains_an_independent_access_source(): void
    {
        $module = $this->createModules(['budget-estimates'])[0];

        OrganizationModuleActivation::create([
            'organization_id' => $this->organization->id,
            'module_id' => $module->id,
            'is_bundled_with_plan' => false,
            'status' => 'active',
            'activated_at' => now(),
            'expires_at' => null,
            'paid_amount' => 0,
        ]);

        $this->assertContains(
            'budget-estimates',
            $this->entitlements()->getEffectiveModuleSlugs($this->organization->id)
        );
    }

    public function test_repair_materializes_only_active_direct_package_modules(): void
    {
        $this->createPackageModules('estimates-norms');
        $this->createPackageSubscription('estimates-norms', 'obsolete-tier');

        $bundledOrganization = $this->createOrganization('Организация со старым пакетом');
        $plan = $this->createPlan([]);
        $legacySubscription = $this->createLegacySubscription($plan, $bundledOrganization->id);
        OrganizationPackageSubscription::create([
            'organization_id' => $bundledOrganization->id,
            'subscription_id' => $legacySubscription->id,
            'is_bundled_with_plan' => true,
            'package_slug' => 'estimates-norms',
            'tier' => 'standard',
            'price_paid' => 0,
            'activated_at' => now(),
            'expires_at' => now()->addMonth(),
        ]);

        $result = app(SubscriptionModuleSyncService::class)->repairPackageModuleActivations();

        $this->assertSame(2, $result['created_count']);
        $this->assertSame(0, $result['restored_count']);
        $this->assertSame(0, OrganizationModuleActivation::where('organization_id', $bundledOrganization->id)->count());

        $this->assertSame(
            2,
            OrganizationModuleActivation::query()
                ->where('organization_id', $this->organization->id)
                ->whereNull('subscription_id')
                ->where('is_bundled_with_plan', false)
                ->where('status', 'active')
                ->count()
        );
    }

    private function entitlements(): OrganizationEntitlementService
    {
        return app(OrganizationEntitlementService::class);
    }

    private function createOrganization(string $name): Organization
    {
        return Organization::withoutEvents(static fn (): Organization => Organization::create([
            'name' => $name,
            'is_active' => true,
            'is_verified' => true,
        ]));
    }

    private function createPlan(array $includedPackages): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'name' => 'Старый тариф',
            'slug' => 'legacy-plan-'.SubscriptionPlan::count(),
            'description' => 'Старый тариф',
            'price' => 1000,
            'currency' => 'RUB',
            'duration_in_days' => 30,
            'features' => [],
            'included_packages' => $includedPackages,
            'is_active' => true,
            'display_order' => 1,
        ]);
    }

    private function createLegacySubscription(
        SubscriptionPlan $plan,
        ?int $organizationId = null
    ): OrganizationSubscription {
        return OrganizationSubscription::create([
            'organization_id' => $organizationId ?? $this->organization->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
            'next_billing_at' => now()->addMonth(),
            'is_auto_payment_enabled' => true,
        ]);
    }

    private function createPackageSubscription(
        string $packageSlug,
        string $tier,
        mixed $expiresAt = null,
        ?int $organizationId = null
    ): OrganizationPackageSubscription {
        return OrganizationPackageSubscription::create([
            'organization_id' => $organizationId ?? $this->organization->id,
            'subscription_id' => null,
            'is_bundled_with_plan' => false,
            'package_slug' => $packageSlug,
            'tier' => $tier,
            'price_paid' => 1000,
            'activated_at' => now(),
            'expires_at' => $expiresAt ?? now()->addMonth(),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function createPackageModules(string $packageSlug): array
    {
        $slugs = app(PackageCatalogService::class)->tierModules($packageSlug, 'standard');
        $this->createModules($slugs);

        return $slugs;
    }

    /**
     * @param  array<int, string>  $slugs
     * @return array<int, Module>
     */
    private function createModules(array $slugs): array
    {
        return array_map(
            static fn (string $slug): Module => Module::firstOrCreate(
                ['slug' => $slug],
                [
                    'name' => $slug,
                    'version' => '1.0.0',
                    'type' => 'feature',
                    'billing_model' => 'subscription',
                    'category' => 'billing-test',
                    'description' => $slug,
                    'pricing_config' => [],
                    'features' => [],
                    'permissions' => [],
                    'dependencies' => [],
                    'conflicts' => [],
                    'limits' => [],
                    'display_order' => 1,
                    'is_active' => true,
                    'is_system_module' => false,
                    'can_deactivate' => true,
                ]
            ),
            $slugs
        );
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('organization_module_activations');
        Schema::dropIfExists('organization_package_subscriptions');
        Schema::dropIfExists('organization_subscriptions');
        Schema::dropIfExists('subscription_plans');
        Schema::dropIfExists('modules');
        Schema::dropIfExists('organizations');

        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_verified')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('subscription_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->string('currency', 3)->default('RUB');
            $table->integer('duration_in_days')->default(30);
            $table->integer('max_projects')->nullable();
            $table->integer('max_storage_gb')->nullable();
            $table->integer('max_users')->nullable();
            $table->json('features')->nullable();
            $table->json('included_packages')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->timestamps();
        });

        Schema::create('organization_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('subscription_plan_id');
            $table->string('status')->default('active');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('next_billing_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('payment_failure_notified_at')->nullable();
            $table->string('payment_gateway_subscription_id')->nullable();
            $table->string('payment_gateway_customer_id')->nullable();
            $table->boolean('is_auto_payment_enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('modules', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('version')->default('1.0.0');
            $table->string('type')->default('feature');
            $table->string('billing_model')->default('subscription');
            $table->string('category')->nullable();
            $table->text('description')->nullable();
            $table->json('pricing_config')->nullable();
            $table->json('features')->nullable();
            $table->json('permissions')->nullable();
            $table->json('dependencies')->nullable();
            $table->json('conflicts')->nullable();
            $table->json('limits')->nullable();
            $table->string('class_name')->nullable();
            $table->string('config_file')->nullable();
            $table->string('icon')->nullable();
            $table->integer('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system_module')->default(false);
            $table->boolean('can_deactivate')->default(true);
            $table->string('development_status')->nullable();
            $table->timestamps();
        });

        Schema::create('organization_module_activations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('module_id');
            $table->foreignId('subscription_id')->nullable();
            $table->boolean('is_bundled_with_plan')->default(false);
            $table->string('status')->default('active');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->decimal('paid_amount', 10, 2)->default(0);
            $table->json('payment_details')->nullable();
            $table->timestamp('next_billing_date')->nullable();
            $table->json('module_settings')->nullable();
            $table->json('usage_stats')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->boolean('is_auto_renew_enabled')->default(false);
            $table->timestamps();
            $table->unique(['organization_id', 'module_id']);
        });

        Schema::create('organization_package_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('subscription_id')->nullable();
            $table->boolean('is_bundled_with_plan')->default(false);
            $table->string('package_slug', 100);
            $table->string('tier', 50);
            $table->decimal('price_paid', 10, 2)->default(0);
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'package_slug', 'is_bundled_with_plan']);
        });
    }
}
