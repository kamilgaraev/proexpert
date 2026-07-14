<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Landing;

use App\DataTransferObjects\Billing\CreatePaymentData;
use App\DataTransferObjects\Billing\CreateSavedMethodPaymentData;
use App\DataTransferObjects\Billing\PaymentGatewayResult;
use App\DataTransferObjects\Billing\RefundGatewayResult;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Interfaces\Billing\PaymentGatewayInterface;
use App\Models\Organization;
use App\Models\OrganizationCommercialAccount;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CommercialCheckoutControllerTest extends TestCase
{
    private Organization $organization;

    private User $owner;

    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();
        $this->organization = Organization::withoutEvents(fn (): Organization => Organization::create([
            'name' => 'Checkout API organization',
            'is_active' => true,
            'is_verified' => true,
        ]));
        $this->owner = $this->createUser('owner-checkout@example.test');
        $this->attachRole($this->owner, 'organization_owner');
        $this->app->instance(PaymentGatewayInterface::class, new ControllerCheckoutGatewayFake);
    }

    public function test_owner_creates_checkout_through_protected_route(): void
    {
        $response = $this->authenticatedAs($this->owner)->postJson(
            '/api/v1/landing/billing/commercial/checkout',
            $this->payload(),
        );

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending_payment')
            ->assertJsonPath('data.amount', '7900.00')
            ->assertJsonPath('data.amount_minor', 790000)
            ->assertJsonPath('data.payment_status', 'pending')
            ->assertJsonPath('data.confirmation_url', 'https://yookassa.test/confirmation');

        $this->assertDatabaseCount('commercial_orders', 1);
        $this->assertDatabaseCount('commercial_payments', 1);
        $this->assertDatabaseCount('organization_package_subscriptions', 0);
    }

    public function test_checkout_requires_token(): void
    {
        $this->postJson('/api/v1/landing/billing/commercial/checkout', $this->payload())
            ->assertUnauthorized();
    }

    public function test_checkout_denies_view_only_member(): void
    {
        $accountant = $this->createUser('accountant-checkout@example.test');
        $this->attachRole($accountant, 'accountant');

        $this->authenticatedAs($accountant)
            ->postJson('/api/v1/landing/billing/commercial/checkout', $this->payload())
            ->assertForbidden();
    }

    public function test_checkout_does_not_accept_client_period_boundaries(): void
    {
        $this->authenticatedAs($this->owner)
            ->postJson('/api/v1/landing/billing/commercial/checkout', $this->payload() + [
                'current_period_start_at' => '2026-01-01T00:00:00Z',
            ])
            ->assertUnprocessable();
    }

    public function test_full_suite_rejects_nonempty_target_package_list(): void
    {
        $this->authenticatedAs($this->owner)
            ->postJson('/api/v1/landing/billing/commercial/checkout', array_replace($this->payload(), [
                'full_suite' => true,
            ]))
            ->assertUnprocessable();

        $this->assertDatabaseCount('commercial_orders', 0);
    }

    public function test_renewal_state_requires_token_and_returns_only_safe_fields_to_view_member(): void
    {
        $this->getJson('/api/v1/landing/billing/commercial/renewal')->assertUnauthorized();
        $accountant = $this->createUser('renewal-view@example.test');
        $this->attachRole($accountant, 'accountant');
        $this->commercialAccount();

        $response = $this->authenticatedAs($accountant)->getJson('/api/v1/landing/billing/commercial/renewal');

        $response->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.auto_renew_enabled', true)
            ->assertJsonPath('data.saved_method_available', true)
            ->assertJsonMissingPath('data.saved_payment_method_id');
        $this->assertSame([
            'status', 'auto_renew_enabled', 'saved_method_available', 'next_billing_at',
            'grace_started_at', 'grace_ends_at', 'retry_status', 'attempt_count', 'next_attempt_at',
        ], array_keys($response->json('data')));
    }

    public function test_renewal_state_denies_user_without_organization_membership(): void
    {
        $outsider = User::create([
            'name' => 'Outsider', 'email' => 'renewal-outsider@example.test', 'password' => 'password',
            'is_active' => true, 'current_organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);
        $this->authenticatedAs($outsider)->getJson('/api/v1/landing/billing/commercial/renewal')->assertForbidden();
    }

    public function test_disable_requires_manage_and_is_idempotent_without_revoking_paid_period(): void
    {
        $account = $this->commercialAccount();
        $paidEnd = $account->current_period_end_at;

        $first = $this->authenticatedAs($this->owner)->postJson('/api/v1/landing/billing/commercial/renewal/disable');
        $second = $this->authenticatedAs($this->owner)->postJson('/api/v1/landing/billing/commercial/renewal/disable');

        $first->assertOk()->assertJsonPath('data.auto_renew_enabled', false)->assertJsonPath('data.saved_method_available', false);
        $second->assertOk()->assertJsonPath('data.auto_renew_enabled', false);
        $fresh = $account->fresh();
        $this->assertSame('active', $fresh->status->value);
        $this->assertEquals($paidEnd, $fresh->current_period_end_at);
        $this->assertSame('provider-method-audit', $fresh->saved_payment_method_id);
    }

    public function test_disable_denies_view_only_member(): void
    {
        $accountant = $this->createUser('renewal-disable-view@example.test');
        $this->attachRole($accountant, 'accountant');
        $this->authenticatedAs($accountant)
            ->postJson('/api/v1/landing/billing/commercial/renewal/disable')
            ->assertForbidden();
    }

    private function commercialAccount(): OrganizationCommercialAccount
    {
        return OrganizationCommercialAccount::query()->firstOrCreate(['organization_id' => $this->organization->id], [
            'responsible_user_id' => $this->owner->id, 'status' => 'active', 'offer_type' => 'packages',
            'quote_version' => 1, 'billing_anchor_at' => now()->addDays(10),
            'current_period_start_at' => now()->subDays(20), 'current_period_end_at' => now()->addDays(10),
            'auto_renew_enabled' => true, 'saved_payment_method_id' => 'provider-method-audit',
            'saved_payment_method_active' => true,
        ]);
    }

    private function payload(): array
    {
        return [
            'target_package_slugs' => ['machinery'],
            'current_package_slugs' => [],
            'full_suite' => false,
            'quote_version' => 1,
            'client_idempotency_key' => '22222222-2222-4222-8222-222222222222',
            'auto_renew_consent' => true,
        ];
    }

    private function authenticatedAs(User $user): self
    {
        $token = JWTAuth::claims(['organization_id' => $this->organization->id])->fromUser($user);

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    private function createUser(string $email): User
    {
        $user = User::create([
            'name' => 'Checkout user',
            'email' => $email,
            'password' => 'password',
            'is_active' => true,
            'current_organization_id' => $this->organization->id,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->organizations()->attach($this->organization->id, [
            'is_owner' => false,
            'is_active' => true,
        ]);

        return $user;
    }

    private function attachRole(User $user, string $role): void
    {
        UserRoleAssignment::create([
            'user_id' => $user->id,
            'role_slug' => $role,
            'role_type' => UserRoleAssignment::TYPE_SYSTEM,
            'context_id' => AuthorizationContext::getOrganizationContext($this->organization->id)->id,
            'is_active' => true,
        ]);
    }

    private function createSchema(): void
    {
        foreach ([
            'commercial_payments', 'commercial_renewal_cycles', 'commercial_orders', 'organization_package_subscriptions',
            'organization_commercial_accounts', 'organization_module_activations', 'modules',
            'role_conditions', 'user_role_assignments',
            'organization_custom_roles', 'authorization_contexts', 'organization_user', 'users', 'organizations',
        ] as $table) {
            Schema::dropIfExists($table);
        }

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
            $table->timestamp('saved_payment_method_at')->nullable();
            $table->boolean('saved_payment_method_active')->default(false);
            $table->timestamp('auto_renew_consented_at')->nullable();
            $table->string('auto_renew_terms_version')->nullable();
            $table->timestamp('grace_started_at')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->timestamps();
        });
        Schema::create('organization_package_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('commercial_account_id');
            $table->string('package_slug');
            $table->string('status');
            $table->string('access_source');
            $table->decimal('price_paid', 12, 2);
            $table->timestamp('current_period_start_at')->nullable();
            $table->timestamp('current_period_end_at')->nullable();
            $table->timestamp('trial_started_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('cancel_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
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
        Schema::create('commercial_orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id');
            $table->foreignId('commercial_account_id');
            $table->foreignId('user_id');
            $table->string('status');
            $table->string('offer_type');
            $table->unsignedInteger('quote_version');
            $table->json('selected_package_slugs');
            $table->json('current_package_slugs');
            $table->unsignedBigInteger('amount_minor');
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3);
            $table->timestamp('period_start_at');
            $table->timestamp('period_end_at');
            $table->boolean('auto_renew_consent');
            $table->string('client_idempotency_key', 100);
            $table->timestamps();
            $table->unique(['organization_id', 'client_idempotency_key']);
        });
        Schema::create('commercial_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('commercial_order_id')->unique();
            $table->string('role')->default('initial');
            $table->unsignedSmallInteger('attempt_number')->default(1);
            $table->string('provider');
            $table->string('provider_payment_id')->nullable()->unique();
            $table->string('provider_status');
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->uuid('provider_idempotency_key')->unique();
            $table->text('confirmation_url')->nullable();
            $table->string('payment_method_id')->nullable();
            $table->boolean('payment_method_saved')->default(false);
            $table->json('safe_response')->nullable();
            $table->timestamps();
        });
        Schema::create('commercial_renewal_cycles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('commercial_account_id');
            $table->foreignId('commercial_order_id');
            $table->string('status');
            $table->timestamp('due_at');
            $table->timestamp('target_period_start_at');
            $table->timestamp('target_period_end_at');
            $table->timestamp('grace_deadline_at');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('manual_review_at')->nullable();
            $table->timestamps();
        });
    }
}

class ControllerCheckoutGatewayFake implements PaymentGatewayInterface
{
    public function createPayment(CreatePaymentData $payment): PaymentGatewayResult
    {
        return new PaymentGatewayResult(
            id: 'provider-api-payment',
            status: 'pending',
            confirmationUrl: 'https://yookassa.test/confirmation',
            paymentMethodId: null,
            paymentMethodSaved: false,
            safeResponse: ['id' => 'provider-api-payment', 'status' => 'pending'],
        );
    }

    public function createSavedMethodPayment(CreateSavedMethodPaymentData $payment): PaymentGatewayResult
    {
        throw new RuntimeException('Not used.');
    }

    public function getPayment(string $paymentId): PaymentGatewayResult
    {
        throw new \RuntimeException('Not used.');
    }

    public function getRefund(string $refundId): RefundGatewayResult
    {
        throw new \RuntimeException('Not used.');
    }
}
