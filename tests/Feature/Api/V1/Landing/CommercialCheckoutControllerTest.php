<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Landing;

use App\DataTransferObjects\Billing\CreatePaymentData;
use App\DataTransferObjects\Billing\PaymentGatewayResult;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Interfaces\Billing\PaymentGatewayInterface;
use App\Models\Organization;
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
            'commercial_payments', 'commercial_orders', 'organization_package_subscriptions',
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
            $table->string('status');
            $table->string('offer_type');
            $table->unsignedInteger('quote_version');
            $table->timestamp('billing_anchor_at')->nullable();
            $table->timestamp('current_period_start_at')->nullable();
            $table->timestamp('current_period_end_at')->nullable();
            $table->boolean('auto_renew_enabled');
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

    public function getPayment(string $paymentId): PaymentGatewayResult
    {
        throw new \RuntimeException('Not used.');
    }
}
