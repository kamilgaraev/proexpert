<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\DataTransferObjects\Billing\CreatePaymentData;
use App\DataTransferObjects\Billing\CreateSavedMethodPaymentData;
use App\DataTransferObjects\Billing\PaymentGatewayResult;
use App\DataTransferObjects\Billing\RefundGatewayResult;
use App\DataTransferObjects\Billing\YooKassaWebhookNotification;
use App\Exceptions\Billing\CommercialCheckoutConflictException;
use App\Interfaces\Billing\PaymentGatewayInterface;
use App\Models\CommercialOrder;
use App\Models\CommercialPayment;
use App\Models\Organization;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use App\Models\User;
use App\Services\Billing\CommercialCheckoutService;
use App\Services\Billing\CommercialWebhookService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class CommercialCheckoutServiceTest extends TestCase
{
    private Organization $organization;

    private User $user;

    private CheckoutGatewayFake $gateway;

    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.yookassa.mode', 'mock');

        $this->createSchema();
        $this->organization = Organization::withoutEvents(fn (): Organization => Organization::create([
            'name' => 'Checkout organization',
            'is_active' => true,
            'is_verified' => true,
        ]));
        $this->user = User::withoutEvents(fn (): User => User::create([
            'name' => 'Checkout owner',
            'email' => 'checkout@example.test',
            'password' => 'password',
            'is_active' => true,
        ]));
        $this->gateway = new CheckoutGatewayFake;
        $this->app->instance(PaymentGatewayInterface::class, $this->gateway);
    }

    public function test_creates_pending_order_from_server_price_without_granting_access(): void
    {
        $result = $this->checkout(['machinery']);

        $this->assertSame('pending_payment', $result['status']);
        $this->assertSame('7900.00', $result['amount']);
        $this->assertSame(790000, $result['amount_minor']);
        $this->assertSame('https://yookassa.test/confirmation', $result['confirmation_url']);
        $this->assertSame('pending', $result['payment_status']);
        $this->assertTrue($result['auto_renew_consent']);
        $this->assertSame(1, CommercialOrder::query()->count());
        $this->assertSame(1, CommercialPayment::query()->count());
        $this->assertSame(0, OrganizationPackageSubscription::query()->count());
        $this->assertSame(790000, $this->gateway->payments[0]->amountMinor);
        $this->assertSame($this->organization->id, $this->gateway->payments[0]->metadata['organization_id']);
        $this->assertSame(
            trans_message('billing.checkout.payment_description'),
            $this->gateway->payments[0]->description,
        );
    }

    public function test_same_idempotency_and_payload_reuses_order_and_provider_payment(): void
    {
        $first = $this->checkout(['machinery']);
        $second = $this->checkout(['machinery']);

        $this->assertSame($first['order_id'], $second['order_id']);
        $this->assertSame(1, CommercialOrder::query()->count());
        $this->assertCount(1, $this->gateway->payments);
    }

    public function test_same_idempotency_with_changed_payload_conflicts(): void
    {
        $this->checkout(['machinery']);

        $this->expectException(CommercialCheckoutConflictException::class);

        $this->checkout(['planning-schedules']);
    }

    public function test_full_suite_idempotency_does_not_accept_changed_nonempty_target_payload(): void
    {
        $key = '44444444-4444-4444-8444-444444444444';
        $this->checkoutPayload([
            'target_package_slugs' => [],
            'full_suite' => true,
            'client_idempotency_key' => $key,
        ]);

        $this->expectException(CommercialCheckoutConflictException::class);

        $this->checkoutPayload([
            'target_package_slugs' => ['machinery'],
            'full_suite' => true,
            'client_idempotency_key' => $key,
        ]);
    }

    public function test_provider_failure_keeps_retryable_intent_and_repeat_uses_same_keys(): void
    {
        $this->gateway->failNext = true;

        try {
            $this->checkout(['machinery']);
            $this->fail('Provider failure must be propagated.');
        } catch (RuntimeException $exception) {
            $this->assertSame('provider unavailable', $exception->getMessage());
        }

        $order = CommercialOrder::query()->sole();
        $payment = CommercialPayment::query()->sole();
        $this->assertSame('pending_payment', $order->status->value);
        $this->assertSame('created', $payment->provider_status);

        $result = $this->checkout(['machinery']);

        $this->assertSame($order->public_id, $result['order_id']);
        $this->assertCount(2, $this->gateway->payments);
        $this->assertSame(
            $this->gateway->payments[0]->idempotenceKey,
            $this->gateway->payments[1]->idempotenceKey,
        );
    }

    public function test_retry_of_existing_purchase_intent_during_grace_has_no_provider_side_effect(): void
    {
        $this->gateway->failNext = true;

        try {
            $this->checkout(['machinery']);
            $this->fail('Provider failure must be propagated.');
        } catch (RuntimeException) {
        }

        $account = OrganizationCommercialAccount::query()->sole();
        $account->forceFill([
            'status' => 'grace',
            'grace_started_at' => now()->subHour(),
            'grace_ends_at' => now()->addDays(7),
        ])->save();

        try {
            $this->checkout(['machinery']);
            $this->fail('Purchase retry during grace must be rejected.');
        } catch (CommercialCheckoutConflictException) {
            $this->assertCount(1, $this->gateway->payments);
            $this->assertNull(CommercialPayment::query()->sole()->provider_payment_id);
            $this->assertSame('pending_payment', CommercialOrder::query()->sole()->status->value);
        }
    }

    public function test_late_checkout_response_does_not_downgrade_success_processed_during_provider_call(): void
    {
        $this->gateway->onCreate = function (CreatePaymentData $data): void {
            $this->gateway->authoritativePayment = new PaymentGatewayResult(
                id: 'provider-1', status: 'succeeded', confirmationUrl: null,
                paymentMethodId: null, paymentMethodSaved: false,
                safeResponse: ['id' => 'provider-1', 'status' => 'succeeded'],
                paid: true, test: true, amountMinor: $data->amountMinor, currency: $data->currency,
                metadata: $data->metadata, refundedAmountMinor: 0,
            );
            app(CommercialWebhookService::class)->process(
                new YooKassaWebhookNotification(
                    'payment.succeeded',
                    'provider-1',
                    'succeeded',
                    ['type' => 'notification', 'event' => 'payment.succeeded', 'object' => ['id' => 'provider-1', 'status' => 'succeeded']],
                ),
                '185.71.76.1',
            );
        };

        $result = $this->checkout(['machinery']);

        $this->assertSame('paid', $result['status']);
        $this->assertSame('succeeded', $result['payment_status']);
        $this->assertSame('paid', CommercialOrder::query()->sole()->status->value);
        $this->assertSame('succeeded', CommercialPayment::query()->sole()->provider_status);
    }

    public function test_rejects_stale_quote_unknown_package_zero_due_and_client_current_mismatch(): void
    {
        foreach ([
            ['quote_version' => 999],
            ['target_package_slugs' => ['unknown']],
            ['target_package_slugs' => []],
            ['current_package_slugs' => ['machinery']],
        ] as $override) {
            try {
                $this->checkoutPayload($override + ['client_idempotency_key' => fake()->uuid()]);
                $this->fail('Invalid checkout must be rejected.');
            } catch (\DomainException|\InvalidArgumentException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }

        $this->assertSame(0, CommercialOrder::query()->count());
        $this->assertCount(0, $this->gateway->payments);
    }

    public function test_uses_server_account_period_for_proration(): void
    {
        $now = CarbonImmutable::parse('2026-07-15T00:00:00Z');
        CarbonImmutable::setTestNow($now);
        $account = OrganizationCommercialAccount::create([
            'organization_id' => $this->organization->id,
            'status' => 'active',
            'offer_type' => 'packages',
            'quote_version' => 1,
            'current_period_start_at' => $now->subDays(15),
            'current_period_end_at' => $now->addDays(15),
            'auto_renew_enabled' => true,
        ]);
        OrganizationPackageSubscription::create([
            'organization_id' => $this->organization->id,
            'commercial_account_id' => $account->id,
            'package_slug' => 'machinery',
            'status' => 'active',
            'access_source' => 'paid_package',
            'price_paid' => 7900,
            'current_period_start_at' => $now->subDays(15),
            'current_period_end_at' => $now->addDays(15),
        ]);

        $result = $this->checkoutPayload([
            'target_package_slugs' => ['machinery', 'planning-schedules'],
            'current_package_slugs' => ['machinery'],
            'client_idempotency_key' => fake()->uuid(),
        ]);

        $this->assertSame(395000, $result['amount_minor']);
        CarbonImmutable::setTestNow();
    }

    public function test_trial_is_not_current_paid_contour_and_purchase_uses_full_price(): void
    {
        $account = OrganizationCommercialAccount::create([
            'organization_id' => $this->organization->id,
            'status' => 'free',
            'offer_type' => 'packages',
            'quote_version' => 1,
            'auto_renew_enabled' => false,
        ]);
        OrganizationPackageSubscription::create([
            'organization_id' => $this->organization->id,
            'commercial_account_id' => $account->id,
            'package_slug' => 'machinery',
            'status' => 'trialing',
            'access_source' => 'trial',
            'price_paid' => 0,
            'trial_started_at' => now(),
            'trial_ends_at' => now()->addHours(72),
        ]);

        $result = $this->checkout(['machinery']);

        $this->assertSame(790000, $result['amount_minor']);
        $this->assertSame(1, OrganizationPackageSubscription::query()->count());
        $this->assertSame('trialing', OrganizationPackageSubscription::query()->sole()->status->value);
    }

    public function test_retry_of_prorated_order_after_time_passes_reuses_saved_amount_and_order(): void
    {
        $now = CarbonImmutable::parse('2026-07-15T00:00:00Z');
        CarbonImmutable::setTestNow($now);
        $account = OrganizationCommercialAccount::create([
            'organization_id' => $this->organization->id,
            'status' => 'active',
            'offer_type' => 'packages',
            'quote_version' => 1,
            'current_period_start_at' => $now->subDays(15),
            'current_period_end_at' => $now->addDays(15),
            'auto_renew_enabled' => true,
        ]);
        OrganizationPackageSubscription::create([
            'organization_id' => $this->organization->id,
            'commercial_account_id' => $account->id,
            'package_slug' => 'machinery',
            'status' => 'active',
            'access_source' => 'paid_package',
            'price_paid' => 7900,
            'current_period_start_at' => $now->subDays(15),
            'current_period_end_at' => $now->addDays(15),
        ]);
        $this->gateway->failNext = true;
        $payload = [
            'target_package_slugs' => ['machinery', 'planning-schedules'],
            'current_package_slugs' => ['machinery'],
            'client_idempotency_key' => '33333333-3333-4333-8333-333333333333',
        ];

        try {
            $this->checkoutPayload($payload);
        } catch (RuntimeException) {
        }
        $order = CommercialOrder::query()->sole();
        $savedAmount = $order->amount_minor;
        CarbonImmutable::setTestNow($now->addDay());

        $result = $this->checkoutPayload($payload);

        $this->assertSame($order->public_id, $result['order_id']);
        $this->assertSame($savedAmount, $result['amount_minor']);
        $this->assertSame(1, CommercialOrder::query()->count());
        CarbonImmutable::setTestNow();
    }

    private function checkout(array $targets): array
    {
        return $this->checkoutPayload([
            'target_package_slugs' => $targets,
            'client_idempotency_key' => '11111111-1111-4111-8111-111111111111',
        ]);
    }

    private function checkoutPayload(array $override): array
    {
        return app(CommercialCheckoutService::class)->checkout(
            $this->organization,
            $this->user,
            array_replace([
                'target_package_slugs' => ['machinery'],
                'current_package_slugs' => [],
                'full_suite' => false,
                'quote_version' => 1,
                'client_idempotency_key' => fake()->uuid(),
                'auto_renew_consent' => true,
            ], $override),
        );
    }

    private function createSchema(): void
    {
        foreach ([
            'notifications', 'commercial_webhook_events', 'commercial_payments', 'commercial_orders', 'organization_package_subscriptions',
            'organization_commercial_accounts', 'users', 'organizations',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_verified')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
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
            $table->foreignId('source_order_id')->nullable();
            $table->timestamps();
        });
        $this->createCommercialCheckoutTables();
    }

    private function createCommercialCheckoutTables(): void
    {
        Schema::create('commercial_orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id');
            $table->foreignId('commercial_account_id')->nullable();
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
            $table->foreignId('commercial_order_id');
            $table->foreignId('commercial_renewal_cycle_id')->nullable();
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
            $table->unsignedBigInteger('refunded_amount_minor')->default(0);
            $table->timestamps();
        });
        Schema::create('commercial_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider');
            $table->string('event_name');
            $table->string('object_id');
            $table->string('authoritative_status')->nullable();
            $table->string('processing_result');
            $table->string('source_ip');
            $table->string('fingerprint')->unique();
            $table->json('safe_payload')->nullable();
            $table->timestamp('processed_at');
            $table->timestamps();
        });
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->string('notifiable_type');
            $table->unsignedBigInteger('notifiable_id');
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->string('notification_type');
            $table->string('priority');
            $table->json('channels');
            $table->json('delivery_status');
            $table->json('data');
            $table->json('metadata')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }
}

class CheckoutGatewayFake implements PaymentGatewayInterface
{
    public array $payments = [];

    public bool $failNext = false;

    public mixed $onCreate = null;

    public ?PaymentGatewayResult $authoritativePayment = null;

    public function createPayment(CreatePaymentData $payment): PaymentGatewayResult
    {
        $this->payments[] = $payment;

        if ($this->failNext) {
            $this->failNext = false;

            throw new RuntimeException('provider unavailable');
        }

        if (is_callable($this->onCreate)) {
            ($this->onCreate)($payment);
        }

        return new PaymentGatewayResult(
            id: 'provider-'.count($this->payments),
            status: 'pending',
            confirmationUrl: 'https://yookassa.test/confirmation',
            paymentMethodId: null,
            paymentMethodSaved: false,
            safeResponse: ['id' => 'provider-'.count($this->payments), 'status' => 'pending'],
        );
    }

    public function createSavedMethodPayment(CreateSavedMethodPaymentData $payment): PaymentGatewayResult
    {
        throw new RuntimeException('Not used.');
    }

    public function getPayment(string $paymentId): PaymentGatewayResult
    {
        return $this->authoritativePayment ?? throw new RuntimeException('Not used.');
    }

    public function getRefund(string $refundId): RefundGatewayResult
    {
        throw new RuntimeException('Not used.');
    }

    public function createRefund(\App\DataTransferObjects\Billing\CreateRefundData $refund): RefundGatewayResult
    {
        throw new RuntimeException('Not used.');
    }
}
