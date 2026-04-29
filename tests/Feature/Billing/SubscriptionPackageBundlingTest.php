<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModuleActivation;
use App\Models\OrganizationPackageSubscription;
use App\Models\OrganizationSubscription;
use App\Models\SubscriptionPlan;
use App\Services\Landing\OrganizationSubscriptionService;
use App\Services\SubscriptionModuleSyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SubscriptionPackageBundlingTest extends TestCase
{
    private Organization $organization;

    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();

        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'is_active' => true,
            'is_verified' => true,
        ]);
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('organization_module_activations');
        Schema::dropIfExists('organization_package_subscriptions');
        Schema::dropIfExists('organization_subscriptions');
        Schema::dropIfExists('subscription_plans');
        Schema::dropIfExists('modules');
        Schema::dropIfExists('organizations');

        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_verified')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->string('currency', 3)->default('RUB');
            $table->integer('duration_in_days')->default(30);
            $table->integer('max_foremen')->nullable();
            $table->integer('max_projects')->nullable();
            $table->integer('max_storage_gb')->nullable();
            $table->integer('max_users')->nullable();
            $table->json('features')->nullable();
            $table->json('included_packages')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->timestamps();
        });

        Schema::create('organization_subscriptions', function (Blueprint $table) {
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

        Schema::create('modules', function (Blueprint $table) {
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

        Schema::create('organization_module_activations', function (Blueprint $table) {
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

        Schema::create('organization_package_subscriptions', function (Blueprint $table) {
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
            $table->unique(['organization_id', 'package_slug']);
        });
    }

    public function test_subscription_activates_included_package_modules_as_bundled(): void
    {
        $plan = $this->createPlan('profi', [
            ['package_slug' => 'objects-execution', 'tier' => 'pro'],
        ]);

        $subscription = $this->createSubscription($plan);
        $modules = $this->createPackageModules('objects-execution', 'pro');

        $result = app(SubscriptionModuleSyncService::class)->syncModulesOnSubscribe($subscription);

        $this->assertSame(count($modules), $result['activated_count']);
        $this->assertSame(1, $result['packages_activated_count']);

        $this->assertDatabaseHas('organization_package_subscriptions', [
            'organization_id' => $this->organization->id,
            'package_slug' => 'objects-execution',
            'tier' => 'pro',
            'price_paid' => 0,
            'subscription_id' => $subscription->id,
            'is_bundled_with_plan' => true,
        ]);

        $this->assertSame(
            count($modules),
            OrganizationModuleActivation::query()
                ->where('organization_id', $this->organization->id)
                ->where('subscription_id', $subscription->id)
                ->where('is_bundled_with_plan', true)
                ->where('status', 'active')
                ->count()
        );
    }

    public function test_existing_standalone_package_is_converted_without_extra_charge(): void
    {
        $plan = $this->createPlan('profi', [
            ['package_slug' => 'finance-acts', 'tier' => 'pro'],
        ]);

        $subscription = $this->createSubscription($plan);
        $modules = $this->createPackageModules('finance-acts', 'pro');

        OrganizationPackageSubscription::create([
            'organization_id' => $this->organization->id,
            'package_slug' => 'finance-acts',
            'tier' => 'pro',
            'price_paid' => 6900,
            'activated_at' => now()->subDay(),
            'expires_at' => now()->addDays(10),
            'is_bundled_with_plan' => false,
        ]);

        foreach ($modules as $module) {
            OrganizationModuleActivation::create([
                'organization_id' => $this->organization->id,
                'module_id' => $module->id,
                'status' => 'active',
                'activated_at' => now()->subDay(),
                'expires_at' => now()->addDays(10),
                'paid_amount' => $module->getPrice(),
                'is_bundled_with_plan' => false,
            ]);
        }

        $result = app(SubscriptionModuleSyncService::class)->syncModulesOnSubscribe($subscription);

        $this->assertSame(0, $result['activated_count']);
        $this->assertSame(count($modules), $result['converted_count']);
        $this->assertSame(1, $result['packages_converted_count']);

        $this->assertDatabaseHas('organization_package_subscriptions', [
            'organization_id' => $this->organization->id,
            'package_slug' => 'finance-acts',
            'tier' => 'pro',
            'price_paid' => 0,
            'subscription_id' => $subscription->id,
            'is_bundled_with_plan' => true,
        ]);
    }

    public function test_plan_downgrade_suspends_only_bundled_packages(): void
    {
        $oldPlan = $this->createPlan('profi', [
            ['package_slug' => 'objects-execution', 'tier' => 'pro'],
        ]);
        $newPlan = $this->createPlan('start', []);

        $subscription = $this->createSubscription($oldPlan);
        $modules = $this->createPackageModules('objects-execution', 'pro');
        $this->createPackageModules('finance-acts', 'pro');

        app(SubscriptionModuleSyncService::class)->syncModulesOnSubscribe($subscription);

        OrganizationPackageSubscription::create([
            'organization_id' => $this->organization->id,
            'package_slug' => 'finance-acts',
            'tier' => 'pro',
            'price_paid' => 6900,
            'activated_at' => now(),
            'expires_at' => now()->addDays(30),
            'is_bundled_with_plan' => false,
        ]);

        $subscription->update(['subscription_plan_id' => $newPlan->id]);

        $result = app(SubscriptionModuleSyncService::class)->syncModulesOnPlanChange(
            $subscription->fresh('plan'),
            $oldPlan,
            $newPlan
        );

        $this->assertSame(count($modules), $result['deactivated_count']);
        $this->assertSame(1, $result['packages_deactivated_count']);

        $this->assertDatabaseHas('organization_package_subscriptions', [
            'organization_id' => $this->organization->id,
            'package_slug' => 'objects-execution',
            'is_bundled_with_plan' => true,
        ]);

        $this->assertDatabaseHas('organization_package_subscriptions', [
            'organization_id' => $this->organization->id,
            'package_slug' => 'finance-acts',
            'is_bundled_with_plan' => false,
        ]);
    }

    public function test_update_subscription_syncs_supply_warehouse_package_modules(): void
    {
        $plan = $this->createPlan('profi', [
            ['package_slug' => 'supply-warehouse', 'tier' => 'pro'],
        ], 0);

        $this->createSubscription($plan);
        $modules = $this->createPackageModules('supply-warehouse', 'pro');

        app(OrganizationSubscriptionService::class)->updateSubscription($this->organization->id, 'profi');

        $basicWarehouseModule = Module::where('slug', 'basic-warehouse')->firstOrFail();

        $this->assertDatabaseHas('organization_module_activations', [
            'organization_id' => $this->organization->id,
            'module_id' => $basicWarehouseModule->id,
            'status' => 'active',
            'is_bundled_with_plan' => true,
        ]);

        $this->assertSame(
            count($modules),
            OrganizationModuleActivation::query()
                ->where('organization_id', $this->organization->id)
                ->where('is_bundled_with_plan', true)
                ->where('status', 'active')
                ->count()
        );
    }

    private function createPlan(string $slug, array $includedPackages, int $price = 9900): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'description' => $slug,
            'price' => $price,
            'currency' => 'RUB',
            'duration_in_days' => 30,
            'features' => [],
            'included_packages' => $includedPackages,
            'is_active' => true,
            'display_order' => 1,
        ]);
    }

    private function createSubscription(SubscriptionPlan $plan): OrganizationSubscription
    {
        return OrganizationSubscription::create([
            'organization_id' => $this->organization->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => now()->addDays(30),
            'next_billing_at' => now()->addDays(30),
            'is_auto_payment_enabled' => true,
        ]);
    }

    /**
     * @return array<int, Module>
     */
    private function createPackageModules(string $packageSlug, string $tier): array
    {
        $config = json_decode(
            (string) file_get_contents(config_path("Packages/{$packageSlug}.json")),
            true
        );

        $modules = [];

        foreach ($config['tiers'][$tier]['modules'] as $slug) {
            $modules[] = Module::firstOrCreate(
                ['slug' => $slug],
                [
                    'name' => $slug,
                    'version' => '1.0.0',
                    'type' => 'feature',
                    'billing_model' => 'subscription',
                    'category' => 'landing',
                    'description' => $slug,
                    'pricing_config' => [
                        'base_price' => 1000,
                        'currency' => 'RUB',
                        'duration_days' => 30,
                    ],
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
            );
        }

        return $modules;
    }
}
