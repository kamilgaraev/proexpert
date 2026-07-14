<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Landing;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\Organization;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use App\Models\OrganizationPackageTrialUsage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class OrganizationPackageControllerTest extends TestCase
{
    private Organization $organization;

    private User $user;

    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();
        $this->organization = Organization::withoutEvents(static fn (): Organization => Organization::create([
            'name' => 'Runtime package organization',
            'is_active' => true,
            'is_verified' => true,
        ]));
        $this->user = User::create([
            'name' => 'Runtime package user',
            'email' => 'runtime-packages@example.com',
            'password' => 'password',
            'is_active' => true,
            'current_organization_id' => $this->organization->id,
        ]);
        $this->user->forceFill(['email_verified_at' => now()])->save();
        $this->user->organizations()->attach($this->organization->id, [
            'is_owner' => true,
            'is_active' => true,
        ]);

        $context = AuthorizationContext::getOrganizationContext($this->organization->id);
        UserRoleAssignment::create([
            'user_id' => $this->user->id,
            'role_slug' => 'organization_owner',
            'role_type' => UserRoleAssignment::TYPE_SYSTEM,
            'context_id' => $context->id,
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_get_packages_returns_new_catalog_and_active_account_state(): void
    {
        $account = OrganizationCommercialAccount::create([
            'organization_id' => $this->organization->id,
            'status' => 'active',
            'offer_type' => 'packages',
            'quote_version' => 1,
            'current_period_start_at' => now(),
            'current_period_end_at' => now()->addDays(30),
            'auto_renew_enabled' => true,
        ]);
        OrganizationPackageSubscription::create([
            'organization_id' => $this->organization->id,
            'commercial_account_id' => $account->id,
            'package_slug' => 'estimates-norms',
            'status' => 'active',
            'access_source' => 'paid_package',
            'price_paid' => 12900,
            'current_period_start_at' => now(),
            'current_period_end_at' => now()->addDays(30),
        ]);

        $token = JWTAuth::claims(['organization_id' => $this->organization->id])
            ->fromUser($this->user);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/landing/packages');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(10, 'data')
            ->assertJsonFragment([
                'slug' => 'estimates-norms',
                'price' => '12900.00',
                'price_minor' => 1290000,
                'is_active' => true,
                'status' => 'active',
                'access_source' => 'paid_package',
            ]);

        $payload = $response->json('data');
        $active = collect($payload)->firstWhere('slug', 'estimates-norms');
        $this->assertIsArray($active);
        $this->assertArrayNotHasKey('tier', $active);
        $this->assertArrayNotHasKey('is_bundled_with_plan', $active);
    }

    public function test_get_packages_returns_authoritative_trial_availability_after_reload(): void
    {
        OrganizationPackageTrialUsage::query()->create([
            'organization_id' => $this->organization->id,
            'package_slug' => 'machinery',
            'started_at' => now()->subDays(5),
            'ends_at' => now()->subDays(2),
        ]);
        $token = JWTAuth::claims(['organization_id' => $this->organization->id])->fromUser($this->user);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/landing/packages');

        $response->assertOk()
            ->assertJsonFragment([
                'slug' => 'machinery',
                'trial_available' => false,
                'trial_used' => true,
            ])
            ->assertJsonFragment([
                'slug' => 'planning-schedules',
                'trial_available' => true,
                'trial_used' => false,
            ]);
    }

    public function test_get_packages_marks_trial_used_from_expired_subscription_history(): void
    {
        $account = OrganizationCommercialAccount::query()->create([
            'organization_id' => $this->organization->id,
            'status' => 'free',
            'offer_type' => 'packages',
            'quote_version' => 1,
            'auto_renew_enabled' => false,
        ]);
        OrganizationPackageSubscription::query()->create([
            'organization_id' => $this->organization->id,
            'commercial_account_id' => $account->id,
            'package_slug' => 'machinery',
            'status' => 'expired',
            'access_source' => 'trial',
            'price_paid' => 0,
            'trial_started_at' => now()->subDays(5),
            'trial_ends_at' => now()->subDays(2),
        ]);
        $token = JWTAuth::claims(['organization_id' => $this->organization->id])->fromUser($this->user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/landing/packages')
            ->assertOk()
            ->assertJsonFragment([
                'slug' => 'machinery',
                'trial_available' => false,
                'trial_used' => true,
            ]);
    }

    public function test_get_packages_requires_bearer_token(): void
    {
        $this->getJson('/api/v1/landing/packages')
            ->assertUnauthorized();
    }

    public function test_get_packages_denies_user_without_organization_membership(): void
    {
        $user = User::create([
            'name' => 'User without organization',
            'email' => 'without-organization@example.com',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        UserRoleAssignment::create([
            'user_id' => $user->id,
            'role_slug' => 'organization_owner',
            'role_type' => UserRoleAssignment::TYPE_SYSTEM,
            'context_id' => AuthorizationContext::getSystemContext()->id,
            'is_active' => true,
        ]);

        $token = JWTAuth::claims(['organization_id' => $this->organization->id])
            ->fromUser($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/landing/packages')
            ->assertForbidden()
            ->assertJsonPath('message', trans_message('landing.organization_context_missing'));
    }

    public function test_owner_can_start_trial_with_server_dates_and_without_payment_fields(): void
    {
        $now = CarbonImmutable::parse('2026-07-14 12:00:00', 'UTC');
        CarbonImmutable::setTestNow($now);
        $token = JWTAuth::claims(['organization_id' => $this->organization->id])
            ->fromUser($this->user);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/landing/packages/machinery/trial', [
                'trial_started_at' => '2030-01-01T00:00:00Z',
                'trial_ends_at' => '2031-01-01T00:00:00Z',
                'auto_renew_enabled' => true,
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.package_slug', 'machinery')
            ->assertJsonPath('data.status', 'trialing')
            ->assertJsonPath('data.access_source', 'trial')
            ->assertJsonPath('data.duration_hours', 72)
            ->assertJsonPath('data.trial_started_at', $now->toISOString())
            ->assertJsonPath('data.trial_ends_at', $now->addHours(72)->toISOString());

        $this->assertDatabaseHas('organization_package_subscriptions', [
            'organization_id' => $this->organization->id,
            'package_slug' => 'machinery',
            'status' => 'trialing',
            'access_source' => 'trial',
            'price_paid' => 0,
            'current_period_start_at' => null,
            'current_period_end_at' => null,
        ]);
        $this->assertSame(1, OrganizationPackageTrialUsage::query()->count());

        CarbonImmutable::setTestNow();
    }

    public function test_start_trial_requires_token(): void
    {
        $this->postJson('/api/v1/landing/packages/machinery/trial')
            ->assertUnauthorized();
    }

    public function test_start_trial_denies_user_with_billing_view_only(): void
    {
        $accountant = User::create([
            'name' => 'Accountant',
            'email' => 'accountant-trial@example.com',
            'password' => 'password',
            'is_active' => true,
            'current_organization_id' => $this->organization->id,
        ]);
        $accountant->forceFill(['email_verified_at' => now()])->save();
        $accountant->organizations()->attach($this->organization->id, [
            'is_owner' => false,
            'is_active' => true,
        ]);
        UserRoleAssignment::create([
            'user_id' => $accountant->id,
            'role_slug' => 'accountant',
            'role_type' => UserRoleAssignment::TYPE_SYSTEM,
            'context_id' => AuthorizationContext::getOrganizationContext($this->organization->id)->id,
            'is_active' => true,
        ]);
        $token = JWTAuth::claims(['organization_id' => $this->organization->id])
            ->fromUser($accountant);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/landing/packages/machinery/trial')
            ->assertForbidden();
    }

    public function test_start_trial_denies_user_without_organization_membership(): void
    {
        $user = User::create([
            'name' => 'Foreign user',
            'email' => 'foreign-trial@example.com',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        UserRoleAssignment::create([
            'user_id' => $user->id,
            'role_slug' => 'organization_owner',
            'role_type' => UserRoleAssignment::TYPE_SYSTEM,
            'context_id' => AuthorizationContext::getSystemContext()->id,
            'is_active' => true,
        ]);
        $token = JWTAuth::claims(['organization_id' => $this->organization->id])
            ->fromUser($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/landing/packages/machinery/trial')
            ->assertForbidden();
    }

    public function test_start_trial_maps_unknown_and_repeat_requests_to_business_errors(): void
    {
        $token = JWTAuth::claims(['organization_id' => $this->organization->id])
            ->fromUser($this->user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/landing/packages/unknown-package/trial')
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', trans_message('landing.packages.trial_package_not_found'));

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/landing/packages/machinery/trial')
            ->assertCreated();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/landing/packages/machinery/trial')
            ->assertConflict()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', trans_message('landing.packages.trial_already_used'));
    }

    public function test_old_commercial_routes_are_not_registered(): void
    {
        $routes = app('router')->getRoutes();
        $packageRoute = $routes->getByName('api.v1.landing.packages.index');

        $this->assertNotNull($packageRoute);
        $this->assertContains('interface:lk', $packageRoute->gatherMiddleware());
        $this->assertContains('authorize:billing.view', $packageRoute->gatherMiddleware());
        $trialRoute = $routes->getByName('api.v1.landing.packages.trial');
        $this->assertNotNull($trialRoute);
        $this->assertContains('interface:lk', $trialRoute->gatherMiddleware());
        $this->assertContains('authorize:billing.manage', $trialRoute->gatherMiddleware());
        $this->assertNull($routes->getByName('api.v1.landing.packages.subscribe'));
        $this->assertNull($routes->getByName('api.v1.landing.packages.unsubscribe'));
        $this->assertNull($routes->getByName('api.v1.landing.billing.subscription.subscribe'));
        $this->assertNull($routes->getByName('api.v1.landing.billing.subscription.change_plan'));
        $this->assertNull($routes->getByName('api.v1.landing.billing.enterprise_constructor.checkout'));
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('organization_package_trial_usages');
        Schema::dropIfExists('organization_module_activations');
        Schema::dropIfExists('modules');
        Schema::dropIfExists('organization_package_subscriptions');
        Schema::dropIfExists('organization_commercial_accounts');
        Schema::dropIfExists('role_conditions');
        Schema::dropIfExists('user_role_assignments');
        Schema::dropIfExists('organization_custom_roles');
        Schema::dropIfExists('authorization_contexts');
        Schema::dropIfExists('organization_user');
        Schema::dropIfExists('users');
        Schema::dropIfExists('organizations');

        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_verified')->default(false);
            $table->boolean('is_holding')->default(false);
            $table->foreignId('parent_organization_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('organization_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('user_id');
            $table->boolean('is_owner')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->string('project_access_mode')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'organization_id']);
        });

        Schema::create('authorization_contexts', function (Blueprint $table): void {
            $table->id();
            $table->string('type')->index();
            $table->unsignedBigInteger('resource_id')->nullable()->index();
            $table->unsignedBigInteger('parent_context_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('organization_custom_roles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('name');
            $table->string('slug', 100);
            $table->text('description')->nullable();
            $table->json('system_permissions');
            $table->json('module_permissions');
            $table->json('interface_access');
            $table->json('conditions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });

        Schema::create('user_role_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('role_slug', 100)->index();
            $table->string('role_type')->default('system');
            $table->unsignedBigInteger('context_id');
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->unique(['user_id', 'role_slug', 'context_id'], 'unique_user_role_context');
        });

        Schema::create('role_conditions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('assignment_id');
            $table->string('condition_type')->index();
            $table->json('condition_data');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->foreignId('current_organization_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('organization_commercial_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->unique();
            $table->foreignId('responsible_user_id')->nullable();
            $table->string('status');
            $table->string('offer_type');
            $table->unsignedInteger('quote_version');
            $table->timestamp('billing_anchor_at')->nullable();
            $table->timestamp('current_period_start_at')->nullable();
            $table->timestamp('current_period_end_at')->nullable();
            $table->boolean('auto_renew_enabled');
            $table->string('saved_payment_method_id')->nullable();
            $table->boolean('saved_payment_method_active')->default(false);
            $table->timestamp('grace_started_at')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
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
            $table->integer('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system_module')->default(false);
            $table->boolean('can_deactivate')->default(true);
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
            $table->decimal('paid_amount', 10, 2)->default(0);
            $table->json('module_settings')->nullable();
            $table->timestamps();
        });

        Schema::create('organization_package_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('commercial_account_id');
            $table->string('package_slug');
            $table->string('status');
            $table->string('access_source');
            $table->decimal('price_paid', 10, 2);
            $table->timestamp('current_period_start_at')->nullable();
            $table->timestamp('current_period_end_at')->nullable();
            $table->timestamp('trial_started_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('cancel_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'package_slug']);
        });

        Schema::create('organization_package_trial_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->string('package_slug', 100);
            $table->timestamp('started_at');
            $table->timestamp('ends_at');
            $table->timestamps();
            $table->unique(['organization_id', 'package_slug']);
        });
    }
}
