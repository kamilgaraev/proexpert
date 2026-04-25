<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Landing;

use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModuleActivation;
use App\Models\OrganizationPackageSubscription;
use App\Models\OrganizationSubscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ModulesOverviewControllerTest extends TestCase
{
    private Organization $organization;
    private User $user;

    public function refreshDatabase(): void
    {
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
        $this->createSchema();

        $this->organization = Organization::factory()->create();
        $this->user = User::factory()->create([
            'current_organization_id' => $this->organization->id,
        ]);

        $this->organization->users()->attach($this->user->id, [
            'is_owner' => true,
            'is_active' => true,
        ]);
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('organization_module_activations');
        Schema::dropIfExists('organization_package_subscriptions');
        Schema::dropIfExists('organization_subscriptions');
        Schema::dropIfExists('subscription_plans');
        Schema::dropIfExists('modules');
        Schema::dropIfExists('organization_user');
        Schema::dropIfExists('users');
        Schema::dropIfExists('organizations');

        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('tax_number')->nullable();
            $table->string('registration_number')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_verified')->default(false);
            $table->string('verification_status')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->foreignId('current_organization_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('organization_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->foreignId('organization_id');
            $table->boolean('is_owner')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('version')->default('1.0.0');
            $table->string('type')->default('feature');
            $table->string('billing_model')->default('free');
            $table->string('category')->nullable();
            $table->text('description')->nullable();
            $table->json('pricing_config')->nullable();
            $table->json('features')->nullable();
            $table->json('permissions')->nullable();
            $table->json('dependencies')->nullable();
            $table->json('conflicts')->nullable();
            $table->json('limits')->nullable();
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
            $table->decimal('paid_amount', 10, 2)->nullable();
            $table->json('payment_details')->nullable();
            $table->timestamp('next_billing_date')->nullable();
            $table->json('module_settings')->nullable();
            $table->json('usage_stats')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->boolean('is_auto_renew_enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('organization_package_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('subscription_id')->nullable();
            $table->boolean('is_bundled_with_plan')->default(false);
            $table->string('package_slug');
            $table->string('tier');
            $table->decimal('price_paid', 10, 2)->default(0);
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->string('currency')->default('RUB');
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
            $table->boolean('is_auto_payment_enabled')->default(false);
            $table->timestamps();
        });
    }

    public function test_overview_classifies_packaged_standalone_and_system_modules(): void
    {
        $projectModule = $this->createModule('project-management', 'Управление проектами', 'free', true, false, 0);
        $brigadesModule = $this->createModule('brigades', 'Бригады', 'free', true, false, 0);
        $this->createModule('video-monitoring', 'Видеонаблюдение', 'subscription', true, false, 1900);
        $usersModule = $this->createModule('users', 'Пользователи', 'free', false, true, 0);
        $organizationsModule = $this->createModule('organizations', 'Организации', 'free', false, true, 0);

        foreach ([$projectModule, $brigadesModule, $usersModule, $organizationsModule] as $module) {
            OrganizationModuleActivation::create([
                'organization_id' => $this->organization->id,
                'module_id' => $module->id,
                'status' => 'active',
                'activated_at' => now(),
                'expires_at' => null,
                'is_bundled_with_plan' => $module->slug === 'project-management',
                'is_auto_renew_enabled' => false,
            ]);
        }

        $response = $this->actingAs($this->user, 'api_landing')
            ->getJson('/api/v1/landing/modules/overview');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.summary.total_solutions_count', 6)
            ->assertJsonFragment(['slug' => 'objects-execution'])
            ->assertJsonFragment(['slug' => 'brigades'])
            ->assertJsonFragment(['slug' => 'video-monitoring'])
            ->assertJsonFragment(['slug' => 'users', 'is_system' => true])
            ->assertJsonFragment(['slug' => 'organizations', 'is_system' => true]);

        $standaloneSlugs = collect($response->json('data.standalone_modules'))->pluck('slug')->all();

        $this->assertContains('brigades', $standaloneSlugs);
        $this->assertContains('video-monitoring', $standaloneSlugs);
        $this->assertNotContains('users', $standaloneSlugs);
        $this->assertNotContains('organizations', $standaloneSlugs);

        $advancedModules = collect($response->json('data.advanced_modules'));

        $this->assertTrue($advancedModules->firstWhere('slug', 'users')['is_system']);
        $this->assertSame('packaged', $advancedModules->firstWhere('slug', 'project-management')['classification']);
        $this->assertSame('standalone', $advancedModules->firstWhere('slug', 'brigades')['classification']);
    }

    public function test_monthly_total_counts_subscription_and_excludes_bundled_package_prices(): void
    {
        $projectModule = $this->createModule('project-management', 'Управление проектами', 'free', true, false, 0);
        $this->createModule('video-monitoring', 'Видеонаблюдение', 'subscription', true, false, 1900);

        $plan = SubscriptionPlan::create([
            'name' => 'Profi',
            'slug' => 'profi',
            'description' => 'Профессиональный тариф',
            'price' => 19900,
            'currency' => 'RUB',
            'duration_in_days' => 30,
            'included_packages' => [
                ['package_slug' => 'objects-execution', 'tier' => 'base'],
            ],
            'features' => [],
            'is_active' => true,
            'display_order' => 1,
        ]);

        $subscription = OrganizationSubscription::create([
            'organization_id' => $this->organization->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'next_billing_at' => now()->addMonth(),
            'is_auto_payment_enabled' => false,
        ]);

        OrganizationPackageSubscription::create([
            'organization_id' => $this->organization->id,
            'subscription_id' => $subscription->id,
            'is_bundled_with_plan' => true,
            'package_slug' => 'objects-execution',
            'tier' => 'base',
            'price_paid' => 0,
            'activated_at' => now(),
            'expires_at' => now()->addMonth(),
        ]);

        OrganizationModuleActivation::create([
            'organization_id' => $this->organization->id,
            'module_id' => $projectModule->id,
            'subscription_id' => $subscription->id,
            'status' => 'active',
            'activated_at' => now(),
            'expires_at' => now()->addMonth(),
            'is_bundled_with_plan' => true,
            'is_auto_renew_enabled' => false,
        ]);

        $response = $this->actingAs($this->user, 'api_landing')
            ->getJson('/api/v1/landing/modules/overview');

        $response->assertOk()
            ->assertJsonPath('data.summary.monthly_total', 19900);
    }

    private function createModule(
        string $slug,
        string $name,
        string $billingModel,
        bool $canDeactivate,
        bool $isSystem,
        int $price
    ): Module {
        return Module::create([
            'name' => $name,
            'slug' => $slug,
            'version' => '1.0.0',
            'type' => $isSystem ? 'core' : 'feature',
            'billing_model' => $billingModel,
            'category' => $isSystem ? 'core' : 'operations',
            'description' => $name,
            'pricing_config' => [
                'base_price' => $price,
                'currency' => 'RUB',
                'duration_days' => 30,
            ],
            'features' => [$name],
            'permissions' => [],
            'dependencies' => [],
            'conflicts' => [],
            'limits' => [],
            'icon' => 'puzzle-piece',
            'display_order' => 1,
            'is_active' => true,
            'is_system_module' => $isSystem,
            'can_deactivate' => $canDeactivate,
        ]);
    }
}
