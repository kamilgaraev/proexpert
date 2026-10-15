<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\DataTransferObjects\Billing\CreatePaymentData;
use App\DataTransferObjects\Billing\CreateSavedMethodPaymentData;
use App\DataTransferObjects\Billing\PaymentGatewayResult;
use App\DataTransferObjects\Billing\RefundGatewayResult;
use App\DataTransferObjects\Billing\YooKassaWebhookNotification;
use App\Exceptions\Billing\CommercialCheckoutConflictException;
use App\Exceptions\Billing\CorporateSelfServiceMutationException;
use App\Exceptions\Billing\PaymentGatewayConfigurationException;
use App\Interfaces\Billing\PaymentGatewayInterface;
use App\Models\CommercialOrder;
use App\Models\CommercialPayment;
use App\Models\Organization;
use App\Models\OrganizationBalance;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use App\Models\OrganizationResourceAllocation;
use App\Models\User;
use App\Services\Billing\CommercialCheckoutService;
use App\Services\Billing\CommercialWebhookService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\AssistantRagTestSchema;
use Tests\Support\IsolatedPostgresTestDatabase;
use Tests\TestCase;

class CommercialCheckoutServiceTest extends TestCase
{
    private Organization $organization;

    private User $user;

    private CheckoutGatewayFake $gateway;

    private ?string $creditApprovalPath = null;

    private ?string $connectionName = null;

    private ?array $originalConnectionConfiguration = null;

    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectionName = DB::getDefaultConnection();
        $this->originalConnectionConfiguration = config('database.connections.'.$this->connectionName);
        config()->set('database.connections.'.$this->connectionName, IsolatedPostgresTestDatabase::configuration());
        DB::purge($this->connectionName);
        DB::connection($this->connectionName);

        config()->set('services.yookassa.mode', 'mock');

        $this->createSchema();
        Queue::fake([\App\BusinessModules\Features\AIAssistant\Jobs\IndexRagSourceJob::class]);
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

    public function test_credit_checkout_uses_server_pack_price_and_retries_without_granting_units(): void
    {
        config(['ai-assistant-credits.enforce' => true]);
        $this->creditApprovalPath = tempnam(sys_get_temp_dir(), 'most-checkout-credit-readiness-');
        config(['ai-assistant-credits.readiness_approval_path' => $this->creditApprovalPath]);
        \Tests\Support\AssistantCreditReadinessFixture::write($this->creditApprovalPath, (array) config('ai-assistant-credits'), (string) config('app.key'));
        $this->user->forceFill(['current_organization_id' => $this->organization->id])->save();
        \Illuminate\Support\Facades\DB::table('organization_user')->insert([
            'organization_id' => $this->organization->id, 'user_id' => $this->user->id, 'is_active' => true,
        ]);
        $authorization = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $authorization->shouldReceive('canCurrent')->with($this->user, 'billing.manage', ['organization_id' => $this->organization->id])->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $authorization);
        $service = app(CommercialCheckoutService::class);
        $first = $service->checkoutCredits($this->organization, $this->user, 'ai-credits-5000', '33333333-3333-4333-8333-333333333333');
        $again = $service->checkoutCredits($this->organization, $this->user, 'ai-credits-5000', '33333333-3333-4333-8333-333333333333');

        $this->assertSame($first, $again);
        $this->assertSame('pending_payment', $first['status']);
        $this->assertSame(450000, $first['amount_minor']);
        $this->assertSame('ai_credits', CommercialOrder::query()->sole()->kind);
        $this->assertSame(500000, CommercialOrder::query()->sole()->selected_resource_addons[0]['units_minor']);
        $this->assertCount(1, $this->gateway->payments);
        $this->assertDatabaseCount('ai_credit_lots', 0);
        $this->assertDatabaseCount('organization_package_subscriptions', 0);
    }

    protected function tearDown(): void
    {
        if ($this->creditApprovalPath !== null && is_file($this->creditApprovalPath)) {
            unlink($this->creditApprovalPath);
        }
        if ($this->connectionName !== null && $this->originalConnectionConfiguration !== null) {
            DB::purge($this->connectionName);
            config()->set('database.connections.'.$this->connectionName, $this->originalConnectionConfiguration);
            DB::connection($this->connectionName);
        }
        parent::tearDown();
    }

    public function test_creates_pending_order_from_server_price_without_granting_access(): void
    {
        config(['ai-assistant-credits.enforce' => false]);
        $result = $this->checkout(['machinery']);

        $this->assertSame('pending_payment', $result['status']);
        $this->assertSame('45800.00', $result['amount']);
        $this->assertSame(4580000, $result['amount_minor']);
        $this->assertSame('https://yookassa.test/confirmation', $result['confirmation_url']);
        $this->assertSame('pending', $result['payment_status']);
        $this->assertTrue($result['auto_renew_consent']);
        $this->assertSame(1, CommercialOrder::query()->count());
        $this->assertSame(1, CommercialPayment::query()->count());
        $this->assertSame(0, OrganizationPackageSubscription::query()->count());
        $this->assertSame(4580000, $this->gateway->payments[0]->amountMinor);
        $this->assertSame($this->organization->id, $this->gateway->payments[0]->metadata['organization_id']);
        $this->assertSame(
            trans_message('billing.checkout.payment_description'),
            $this->gateway->payments[0]->description,
        );
    }

    public function test_checkout_preserves_server_assistant_allocation_on_idempotent_retry(): void
    {
        $at = CarbonImmutable::parse('2026-09-29 03:00:00', 'UTC');
        CarbonImmutable::setTestNow($at);
        try {
            $input = [
                'target_package_slugs' => ['working-entry'],
                'client_idempotency_key' => '99999999-9999-4999-8999-999999999999',
                'assistant_revenue_allocation' => ['amount_minor' => 99_999_999],
            ];
            $first = $this->checkoutPayload($input);
            $order = CommercialOrder::query()->sole();
            $allocation = $order->assistant_revenue_allocation;

            $this->assertSame('allocated', $allocation['status']);
            $this->assertSame('declared_internal_allocation', $allocation['basis']);
            $this->assertSame(399_000, $allocation['amount_minor']);
            $this->assertSame(399_000, \App\Services\Credits\AssistantRevenueAllocation::verify($allocation, $first['amount_minor'], $order->amount_minor));
            $this->assertArrayNotHasKey('assistant_revenue_allocation', $first);

            CarbonImmutable::setTestNow($at->addDay());
            $again = $this->checkoutPayload($input);

            $this->assertSame(true, $first['_created']);
            $this->assertSame(false, $again['_created']);
            $firstPayload = $first;
            $againPayload = $again;
            unset($firstPayload['_created'], $againPayload['_created']);
            $this->assertSame($firstPayload, $againPayload);
            $this->assertSame($allocation, $order->fresh()->assistant_revenue_allocation);
            $this->assertDatabaseCount('commercial_orders', 1);
            $this->assertDatabaseCount('commercial_payments', 1);
            $this->assertCount(1, $this->gateway->payments);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_pays_fully_from_balance_without_creating_yookassa_payment(): void
    {
        OrganizationBalance::query()->create([
            'organization_id' => $this->organization->id,
            'balance' => 5_000_000,
            'currency' => 'RUB',
        ]);

        $result = $this->checkoutPayload([
            'target_package_slugs' => ['machinery'],
            'client_idempotency_key' => '22222222-2222-4222-8222-222222222222',
            'use_balance' => true,
            'auto_renew_consent' => false,
        ]);

        $this->assertSame('paid', $result['status']);
        $this->assertSame('succeeded', $result['payment_status']);
        $this->assertSame('balance', $result['payment_source']);
        $this->assertNull($result['confirmation_url']);
        $this->assertSame(420_000, OrganizationBalance::query()->sole()->balance);
        $this->assertDatabaseHas('balance_transactions', [
            'type' => 'debit',
            'amount' => 4_580_000,
        ]);
        $this->assertDatabaseHas('commercial_payments', [
            'provider' => 'balance',
            'provider_status' => 'succeeded',
        ]);
        $this->assertDatabaseHas('organization_package_subscriptions', [
            'package_slug' => 'machinery',
            'status' => 'active',
        ]);
        $this->assertCount(0, $this->gateway->payments);
    }

    public function test_checkout_combines_package_and_resource_addons_in_one_order(): void
    {
        $result = $this->checkoutPayload([
            'target_package_slugs' => ['machinery'],
            'resources' => [
                ['slug' => 'extra_users', 'quantity' => 10],
            ],
            'client_idempotency_key' => '55555555-5555-4555-8555-555555555555',
        ]);

        $order = CommercialOrder::query()->sole();

        $this->assertSame(4_880_000, $result['amount_minor']);
        $this->assertSame(4_880_000, $order->amount_minor);
        $this->assertSame([
            [
                'slug' => 'extra_users',
                'limit_key' => 'users',
                'quantity' => 10,
                'amount_minor' => 300_000,
                'amount' => '3000.00',
                'currency' => 'RUB',
                'status' => 'ok',
                'requires_package' => null,
                'requires_module' => null,
            ],
        ], $order->selected_resource_addons);
        $this->assertSame(4_880_000, $this->gateway->payments[0]->amountMinor);
    }

    public function test_checkout_allows_resource_unlocked_by_package_bought_in_same_order(): void
    {
        $result = $this->checkoutPayload([
            'target_package_slugs' => ['sales-contractors'],
            'resources' => [
                ['slug' => 'extra_contractors', 'quantity' => 10],
            ],
            'client_idempotency_key' => '77777777-7777-4777-8777-777777777777',
        ]);

        $order = CommercialOrder::query()->sole();

        $this->assertSame(4_880_000, $result['amount_minor']);
        $this->assertSame(4_880_000, $order->amount_minor);
        $this->assertSame([
            [
                'slug' => 'extra_contractors',
                'limit_key' => 'contractors',
                'quantity' => 10,
                'amount_minor' => 100_000,
                'amount' => '1000.00',
                'currency' => 'RUB',
                'status' => 'ok',
                'requires_package' => 'sales-contractors',
                'requires_module' => null,
            ],
        ], $order->selected_resource_addons);
    }

    public function test_balance_payment_activates_package_and_resource_addons(): void
    {
        OrganizationBalance::query()->create([
            'organization_id' => $this->organization->id,
            'balance' => 6_000_000,
            'currency' => 'RUB',
        ]);

        $result = $this->checkoutPayload([
            'target_package_slugs' => ['machinery'],
            'resources' => [
                ['slug' => 'extra_users', 'quantity' => 10],
            ],
            'client_idempotency_key' => '66666666-6666-4666-8666-666666666666',
            'use_balance' => true,
            'auto_renew_consent' => false,
        ]);

        $this->assertSame('paid', $result['status']);
        $this->assertSame(1_120_000, OrganizationBalance::query()->sole()->balance);
        $this->assertDatabaseHas('organization_package_subscriptions', [
            'package_slug' => 'machinery',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('organization_resource_allocations', [
            'resource_slug' => 'extra_users',
            'limit_key' => 'users',
            'quantity' => '10.00',
            'source' => 'paid_addon',
            'status' => 'active',
        ]);
    }

    public function test_test_store_allowlist_denial_creates_no_checkout_state_or_provider_call(): void
    {
        config()->set('services.yookassa.mode', 'yookassa_test');
        config()->set('services.yookassa.test_organization_ids', [$this->organization->id + 1]);

        $this->expectException(PaymentGatewayConfigurationException::class);

        try {
            $this->checkout(['machinery']);
        } finally {
            $this->assertDatabaseCount('commercial_orders', 0);
            $this->assertDatabaseCount('commercial_payments', 0);
            $this->assertCount(0, $this->gateway->payments);
        }
    }

    public function test_corporate_account_cannot_create_self_service_checkout(): void
    {
        OrganizationCommercialAccount::query()->create([
            'organization_id' => $this->organization->id,
            'responsible_user_id' => $this->user->id,
            'status' => 'corporate',
            'offer_type' => 'corporate',
            'quote_version' => 1,
            'auto_renew_enabled' => false,
        ]);

        $this->expectException(CorporateSelfServiceMutationException::class);

        try {
            $this->checkout(['machinery']);
        } finally {
            $this->assertDatabaseCount('commercial_orders', 0);
            $this->assertDatabaseCount('commercial_payments', 0);
            $this->assertCount(0, $this->gateway->payments);
        }
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
            'package_slug' => 'working-entry',
            'status' => 'active',
            'access_source' => 'paid_package',
            'price_paid' => 39900,
            'current_period_start_at' => $now->subDays(15),
            'current_period_end_at' => $now->addDays(15),
        ]);

        $result = $this->checkoutPayload([
            'target_package_slugs' => ['working-entry', 'supply-warehouse'],
            'current_package_slugs' => ['working-entry'],
            'client_idempotency_key' => fake()->uuid(),
        ]);

        $this->assertSame(495000, $result['amount_minor']);
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

        $this->assertSame(4580000, $result['amount_minor']);
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
            'package_slug' => 'working-entry',
            'status' => 'active',
            'access_source' => 'paid_package',
            'price_paid' => 39900,
            'current_period_start_at' => $now->subDays(15),
            'current_period_end_at' => $now->addDays(15),
        ]);
        $this->gateway->failNext = true;
        $payload = [
            'target_package_slugs' => ['working-entry', 'supply-warehouse'],
            'current_package_slugs' => ['working-entry'],
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

    public function test_annual_balance_purchase_and_prepaid_renewal_extend_from_paid_expiry_once(): void
    {
        $at = CarbonImmutable::parse('2026-10-15 09:00:00', 'UTC');
        $this->travelTo($at);
        OrganizationBalance::query()->create(['organization_id' => $this->organization->id, 'balance' => 100000000, 'currency' => 'RUB']);
        $initial = $this->checkoutPayload(['target_package_slugs' => ['working-entry'], 'use_balance' => true, 'auto_renew_consent' => false]);
        $this->assertSame('paid', $initial['status']);
        $oldEnd = $at->addDays(30);
        $payload = ['target_package_slugs' => ['working-entry'], 'current_package_slugs' => ['working-entry'], 'billing_period' => '12_months', 'renew_subscription' => true, 'use_balance' => true, 'auto_renew_consent' => false, 'client_idempotency_key' => fake()->uuid()];
        $paid = $this->checkoutPayload($payload);
        $again = $this->checkoutPayload($payload);
        $this->assertSame($paid['order_id'], $again['order_id']);
        $this->assertSame(43092000, $paid['amount_minor']);
        $account = OrganizationCommercialAccount::query()->sole();
        $this->assertSame('12_months', $account->billing_period);
        $this->assertTrue($oldEnd->addMonthsNoOverflow(12)->equalTo($account->current_period_end_at));
        $this->assertTrue(OrganizationPackageSubscription::query()->sole()->isActive());
        $this->assertSame(52918000, OrganizationBalance::query()->sole()->balance);
    }

    public function test_resources_bought_after_prepayment_are_quoted_consistently_and_available_immediately(): void
    {
        $at = CarbonImmutable::parse('2026-10-15 09:00:00', 'UTC');
        $this->travelTo($at);
        OrganizationBalance::query()->create(['organization_id' => $this->organization->id, 'balance' => 100000000, 'currency' => 'RUB']);
        $current = ['target_package_slugs' => ['working-entry'], 'use_balance' => true, 'auto_renew_consent' => false];
        $this->checkoutPayload($current);
        $this->checkoutPayload($current + ['current_package_slugs' => ['working-entry'], 'billing_period' => '6_months', 'renew_subscription' => true]);
        $account = OrganizationCommercialAccount::query()->sole();
        $this->assertTrue($account->current_period_start_at->isFuture());
        $resources = [['slug' => 'extra_users', 'quantity' => 10]];
        $queries = app(\App\Services\Billing\CommercialBillingQueryService::class);
        $resourceQuote = $queries->quoteResourceAddons($this->organization, $resources);
        $combined = $queries->quote($this->organization, ['target_package_slugs' => ['working-entry'], 'full_suite' => false, 'resources' => $resources]);
        $this->assertSame($combined['resource_addons_quote']['amount_minor'], $resourceQuote['amount_minor']);
        $this->assertGreaterThan(1800000, $resourceQuote['amount_minor']);
        $paid = $this->checkoutPayload($current + ['current_package_slugs' => ['working-entry'], 'resources' => $resources]);
        $this->assertSame('paid', $paid['status']);
        $this->assertSame($resourceQuote['amount_minor'], $paid['amount_minor']);
        $allocation = OrganizationResourceAllocation::query()->active()->sole();
        $this->assertTrue($at->equalTo($allocation->period_start_at));
        $this->assertTrue($account->current_period_end_at->equalTo($allocation->period_end_at));
    }

    public function test_checkout_idempotency_key_cannot_be_reused_for_another_billing_period(): void
    {
        $key = fake()->uuid();
        $this->checkoutPayload(['billing_period' => '6_months', 'client_idempotency_key' => $key]);
        $this->expectException(CommercialCheckoutConflictException::class);
        $this->checkoutPayload(['billing_period' => '12_months', 'client_idempotency_key' => $key]);
    }

    public function test_annual_checkout_does_not_grant_access_until_provider_confirms_payment(): void
    {
        $result = $this->checkoutPayload(['billing_period' => '12_months']);
        $this->assertSame(49464000, $result['amount_minor']);
        $this->assertDatabaseCount('organization_package_subscriptions', 0);
        $this->assertSame('12_months', CommercialOrder::query()->sole()->billing_period);
    }

    public function test_pending_annual_order_conflicts_with_a_monthly_default_retry(): void
    {
        $key = fake()->uuid();
        $this->checkoutPayload(['billing_period' => '12_months', 'client_idempotency_key' => $key]);
        $this->expectException(CommercialCheckoutConflictException::class);
        $this->checkoutPayload(['client_idempotency_key' => $key]);
    }

    private function checkout(array $targets): array
    {
        return $this->checkoutPayload([
            'target_package_slugs' => $targets,
            'client_idempotency_key' => '11111111-1111-4111-8111-111111111111',
        ]);
    }

    public function test_bank_invoice_partials_overpayment_and_replay_activate_only_once(): void
    {
        $this->prepareBankBilling();
        $first = $this->checkout(['machinery']);
        $again = $this->checkout(['machinery']);
        $this->assertSame($first['bank_invoice']['id'], $again['bank_invoice']['id']);
        $this->assertNull($first['confirmation_url']);
        $this->assertSame('tochka', $first['payment_source']);
        $this->assertSame('awaiting_payment', $first['bank_invoice']['status']);
        $this->assertSame('RUB', $first['bank_invoice']['currency']);
        $this->assertSame(0, $first['bank_invoice']['paid_minor']);
        $this->assertFalse($first['auto_renew_consent']);
        $this->assertCount(0, $this->gateway->payments);
        $invoice = \App\Models\CommercialBankInvoice::query()->sole();
        $service = app(\App\Services\Billing\Bank\BankSettlementService::class);
        $firstPart = $this->bankTransaction($invoice, 'first-part', 10000);
        $service->ingest($firstPart);
        $this->assertSame('partially_paid', $invoice->refresh()->status);
        $this->assertDatabaseCount('organization_package_subscriptions', 0);
        $lastPart = $this->bankTransaction($invoice, 'last-part', $invoice->amount_minor - 10000 + 1000);
        $service->ingest($lastPart);
        $activatedAt = $invoice->refresh()->activated_at->toJSON();
        $packageRows = OrganizationPackageSubscription::query()->count();
        $service->ingest($lastPart);
        $service->ingest($firstPart);
        $service->activate((int) $invoice->id);
        $this->assertSame($activatedAt, $invoice->refresh()->activated_at->toJSON());
        $this->assertSame('paid', $invoice->status);
        $this->assertSame($invoice->amount_minor, $invoice->paid_minor);
        $this->assertDatabaseCount('commercial_bank_operations', 2);
        $this->assertDatabaseCount('commercial_bank_allocations', 2);
        $this->assertDatabaseCount('commercial_bank_documents', 2);
        $this->assertSame(1000, app(\App\Services\Billing\Bank\BankInvoiceService::class)->payload($invoice)['overpayment_minor']);
        $this->assertSame('active', OrganizationCommercialAccount::query()->sole()->status->value);
        $this->assertFalse(OrganizationCommercialAccount::query()->sole()->auto_renew_enabled);
        $this->assertSame($packageRows, OrganizationPackageSubscription::query()->count());
        $this->assertSame(1, OrganizationPackageSubscription::query()->where('package_slug', 'machinery')->count());
    }

    public function test_bank_payer_mismatch_pending_and_changed_operations_never_activate(): void
    {
        $this->prepareBankBilling();
        $this->checkout(['machinery']);
        $invoice = \App\Models\CommercialBankInvoice::query()->sole();
        $service = app(\App\Services\Billing\Bank\BankSettlementService::class);
        $transaction = $this->bankTransaction($invoice, 'wrong-payer', $invoice->amount_minor);
        $transaction['status'] = 'Pending';
        $this->assertNull($service->ingest($transaction));
        $transaction['status'] = 'Booked';
        $transaction['DebtorParty']['inn'] = '7736050003';
        $operation = $service->ingest($transaction);
        $this->assertSame('payer_mismatch', $operation->review_reason);
        $transaction['Amount']['amount'] = '1.00';
        $this->assertSame('bank_operation_changed', $service->ingest($transaction)->review_reason);
        $this->assertNull($invoice->refresh()->activated_at);
        $this->assertSame(0, $invoice->paid_minor);
        $this->assertDatabaseCount('organization_package_subscriptions', 0);
    }

    public function test_bank_refund_splits_original_payments_and_requires_approved_outgoing_statement(): void
    {
        $this->prepareBankBilling();
        $this->checkout(['machinery']);
        $invoice = \App\Models\CommercialBankInvoice::query()->sole();
        $settlement = app(\App\Services\Billing\Bank\BankSettlementService::class);
        $settlement->ingest($this->bankTransaction($invoice, 'part-one', 10000));
        $settlement->ingest($this->bankTransaction($invoice, 'part-two', $invoice->amount_minor - 10000));
        $refunds = app(\App\Services\Billing\Bank\BankRefundService::class);
        $key = '88888888-8888-4888-8888-888888888888';
        $result = $refunds->request($this->user, (int) $this->organization->id, $invoice->public_id, $invoice->amount_minor, 'payment', 'Возврат полной оплаты по запросу клиента', $key);
        $this->assertCount(2, $result['items']);
        $this->assertSame($result, $refunds->request($this->user, (int) $this->organization->id, $invoice->public_id, $invoice->amount_minor, 'payment', 'Возврат полной оплаты по запросу клиента', $key));
        $operator = new \App\Models\SystemAdmin(['role' => 'super_admin', 'is_active' => true]);
        $operator->id = 100;
        foreach (\App\Models\CommercialBankRefund::query()->get() as $refund) {
            $transaction = $this->bankTransaction($invoice, 'refund-'.$refund->id, $refund->amount_minor);
            $transaction['creditDebitIndicator'] = 'Debit';
            $transaction['description'] = $refund->reference;
            $transaction['DebtorAccount']['identification'] = config('bank_billing.seller_account');
            $transaction['CreditorAccount']['identification'] = $refund->operation->payer_account;
            $settlement->ingest($transaction);
            $this->assertSame('requested', $refund->refresh()->status);
            $refunds->decide($operator, (int) $refund->id, true);
            $settlement->ingest($transaction);
            $settlement->ingest($transaction);
            $this->assertSame('completed', $refund->refresh()->status);
        }
        $this->assertSame('refunded', $invoice->refresh()->status);
        $this->assertSame(0, $invoice->paid_minor);
        $this->assertSame($invoice->amount_minor, $invoice->refunded_minor);
        $this->assertFalse(OrganizationPackageSubscription::query()->where('status', '!=', 'expired')->exists());
    }

    public function test_bank_documents_and_cancellation_are_scoped_to_the_buying_organization(): void
    {
        $this->prepareBankBilling();
        $this->checkout(['machinery']);
        $invoice = \App\Models\CommercialBankInvoice::query()->sole();
        $other = Organization::withoutEvents(fn () => Organization::create(['name' => 'Other', 'is_active' => true]));
        DB::table('organization_user')->insert(['user_id' => $this->user->id, 'organization_id' => $other->id, 'is_active' => true]);
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(\App\Services\Billing\Bank\BankBillingService::class)->document($this->user, (int) $other->id, $invoice->documents()->sole()->public_id);
    }

    public function test_yookassa_disabled_refuses_new_payment_without_calling_the_gateway(): void
    {
        config(['services.yookassa.payments_enabled' => false]);
        $this->expectException(PaymentGatewayConfigurationException::class);
        try {
            $this->checkout(['machinery']);
        } finally {
            $this->assertCount(0, $this->gateway->payments);
        }
    }

    public function test_disabled_yookassa_does_not_expose_a_saved_payment_url_on_replay(): void
    {
        $request = ['auto_renew_consent' => false, 'client_idempotency_key' => fake()->uuid()];
        $first = $this->checkoutPayload($request);
        $this->assertNotEmpty($first['confirmation_url']);
        config(['bank_billing.default_provider' => 'tochka', 'services.yookassa.payments_enabled' => false]);
        $replayed = $this->checkoutPayload($request);
        $this->assertSame($first['order_id'], $replayed['order_id']);
        $this->assertNull($replayed['confirmation_url']);
        $this->assertFalse($replayed['_created']);
        $this->assertCount(1, $this->gateway->payments);
    }

    public function test_bank_ai_credit_purchase_opens_the_configured_cabinet_invoice_without_card_charging(): void
    {
        $this->prepareBankBilling();
        config(['ai-assistant-credits.enforce' => true, 'app.frontend_url' => 'https://cabinet.example.test/']);
        $this->creditApprovalPath = tempnam(sys_get_temp_dir(), 'most-bank-credit-readiness-');
        config(['ai-assistant-credits.readiness_approval_path' => $this->creditApprovalPath]);
        \Tests\Support\AssistantCreditReadinessFixture::write($this->creditApprovalPath, (array) config('ai-assistant-credits'), (string) config('app.key'));
        $credits = app(\App\Services\Credits\AICreditService::class);
        $key = '33333333-3333-4333-8333-333333333333';
        $first = $credits->purchase($this->organization, $this->user, 'ai-credits-5000', $key);
        $again = $credits->purchase($this->organization, $this->user, 'ai-credits-5000', $key);
        $this->assertEquals($first, $again);
        $this->assertSame('tochka', $first['payment_source']);
        $this->assertSame('https://cabinet.example.test/dashboard/billing?bank_invoice='.$first['bank_invoice']['id'], $first['confirmation_url']);
        $this->assertSame(450000, $first['amount_minor']);
        $this->assertCount(0, $this->gateway->payments);
        $this->assertDatabaseCount('commercial_bank_invoices', 1);
        $this->assertDatabaseCount('ai_credit_lots', 0);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('legacyYooKassaPaymentStates')]
    public function test_bank_checkout_renewal_ignores_legacy_yookassa_orders(string $status, bool $sandbox): void
    {
        $legacy = $this->prepareLegacyYooKassaPurchase($status, $sandbox);
        $account = OrganizationCommercialAccount::query()->sole();
        $anchor = $account->current_period_end_at->toJSON();
        $request = ['target_package_slugs' => ['machinery'], 'current_package_slugs' => ['working-entry', 'machinery'],
            'renew_subscription' => true, 'client_idempotency_key' => fake()->uuid()];

        $result = $this->checkoutPayload($request);
        $again = $this->checkoutPayload($request);

        $this->assertSame('tochka', $result['payment_source']);
        $this->assertSame($result['bank_invoice']['id'], $again['bank_invoice']['id']);
        $this->assertSame($anchor, CommercialOrder::query()->where('public_id', $result['order_id'])->sole()->period_start_at->toJSON());
        $this->assertSame($anchor, $account->refresh()->current_period_end_at->toJSON());
        $this->assertSame('pending_payment', $legacy->refresh()->status->value);
        $this->assertSame($status, $legacy->latestPayment->provider_status);
        $this->assertCount(1, $this->gateway->payments);
        $this->assertDatabaseCount('commercial_bank_invoices', 2);

        try {
            $this->checkoutPayload(array_replace($request, ['client_idempotency_key' => fake()->uuid()]));
            $this->fail('A second pending bank checkout must still be rejected');
        } catch (CommercialCheckoutConflictException) {
            $this->assertDatabaseCount('commercial_bank_invoices', 2);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('legacyYooKassaPaymentStates')]
    public function test_bank_manual_renewal_ignores_legacy_yookassa_purchases(string $status, bool $sandbox): void
    {
        $legacy = $this->prepareLegacyYooKassaPurchase($status, $sandbox);
        $account = OrganizationCommercialAccount::query()->sole();
        $anchor = $account->current_period_end_at->toJSON();
        $service = app(\App\Services\Billing\CommercialManualPaymentService::class);
        $key = fake()->uuid();

        $result = $service->create($this->organization, $this->user, $key);
        $again = $service->create($this->organization, $this->user, $key);

        $this->assertSame('tochka', $result['payment_source']);
        $this->assertSame('renewal', CommercialOrder::query()->where('public_id', $result['order_id'])->sole()->kind);
        $this->assertSame($result['bank_invoice']['id'], $again['bank_invoice']['id']);
        $this->assertSame($anchor, $result['period_start_at']);
        $this->assertSame('pending_payment', $legacy->refresh()->status->value);
        $this->assertSame($status, $legacy->latestPayment->provider_status);
        $this->assertCount(1, $this->gateway->payments);
        $this->assertDatabaseCount('commercial_bank_invoices', 2);
    }

    public static function legacyYooKassaPaymentStates(): array
    {
        return ['pending' => ['pending', false], 'canceled' => ['canceled', false], 'sandbox_pending' => ['pending', true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unresolvedYooKassaPayments')]
    public function test_bank_renewal_blocks_unresolved_yookassa_funds(string $path, string $kind, string $status, bool $reconciliation): void
    {
        $legacy = $this->prepareLegacyYooKassaPurchase('pending');
        $legacy->forceFill(['kind' => $kind])->save();
        $legacy->latestPayment->forceFill(['provider_status' => $status, 'reconciliation_required' => $reconciliation])->save();
        $account = OrganizationCommercialAccount::query()->sole();
        $anchor = $account->current_period_end_at->toJSON();

        if ($path === 'automatic') {
            $account->forceFill(['bank_auto_invoice' => true])->save();
            $this->travelTo($account->current_period_end_at->subDays(5));
            try {
                $result = app(\App\Services\Billing\Bank\BankRenewalService::class)->process(now());
                $this->assertTrue($result['ready']);
                $this->assertSame(0, $result['failed']);
            } finally {
                $this->travelBack();
            }
        } else {
            try {
                if ($path === 'manual') {
                    app(\App\Services\Billing\CommercialManualPaymentService::class)
                        ->create($this->organization, $this->user, fake()->uuid());
                } else {
                    $this->checkoutPayload(['target_package_slugs' => ['machinery'],
                        'current_package_slugs' => ['working-entry', 'machinery'], 'renew_subscription' => true]);
                }
                $this->fail('Unresolved card funds must block another invoice');
            } catch (CommercialCheckoutConflictException) {
                $this->assertSame('pending_payment', $legacy->refresh()->status->value);
            }
        }

        $this->assertDatabaseCount('commercial_bank_invoices', 1);
        $this->assertDatabaseCount('commercial_orders', 2);
        $this->assertSame($anchor, $account->refresh()->current_period_end_at->toJSON());
        $this->assertSame($status, $legacy->latestPayment->refresh()->provider_status);
        $this->assertSame($reconciliation, $legacy->latestPayment->reconciliation_required);
        $this->assertCount(1, $this->gateway->payments);
    }

    public static function unresolvedYooKassaPayments(): array
    {
        $cases = [];
        foreach (['checkout', 'manual', 'automatic'] as $path) {
            foreach (['purchase', 'renewal'] as $kind) {
                foreach (['succeeded' => ['succeeded', false], 'capture' => ['waiting_for_capture', false], 'reconcile' => ['pending', true]] as $state => [$status, $reconciliation]) {
                    $cases[$path.'-'.$kind.'-'.$state] = [$path, $kind, $status, $reconciliation];
                }
            }
        }

        return $cases;
    }

    public function test_bank_renewal_prepayment_waits_for_the_period_and_never_restores_card_charging(): void
    {
        $this->prepareBankBilling();
        $this->checkout(['machinery']);
        $settlement = app(\App\Services\Billing\Bank\BankSettlementService::class);
        $first = \App\Models\CommercialBankInvoice::query()->sole();
        $settlement->ingest($this->bankTransaction($first, 'initial', $first->amount_minor));
        $account = OrganizationCommercialAccount::query()->sole();
        $this->travelTo($account->current_period_end_at->subDays(5));
        try {
            $account->forceFill(['auto_renew_enabled' => true, 'saved_payment_method_active' => true, 'saved_payment_method_id' => 'old-card'])->save();
            $renewal = app(\App\Services\Billing\Bank\BankRenewalService::class)->create($account);
            $this->assertSame('renewal', $renewal->order->kind);
            $this->assertSame('initial', $renewal->payment->role);
            $this->assertNull($renewal->payment->commercial_renewal_cycle_id);
            $this->assertSame(app(\App\Services\Legal\LegalDocumentService::class)->hash('offer'), $renewal->snapshot['legal_sha256']);
            $again = app(\App\Services\Billing\Bank\BankRenewalService::class)->create($account);
            $this->assertSame($renewal->id, $again->id);
            $settlement->ingest($this->bankTransaction($renewal, 'renewal', $renewal->amount_minor));
            $this->assertSame('paid', $renewal->refresh()->status);
            $this->assertNull($renewal->activated_at);
            $this->assertSame($renewal->id, app(\App\Services\Billing\Bank\BankRenewalService::class)->create($account, '99999999-9999-4999-8999-999999999999')->id);
            $this->travelTo($renewal->order->period_start_at);
            $settlement->activate((int) $renewal->id);
            $account->refresh();
            $this->assertNotNull($renewal->refresh()->activated_at);
            $this->assertSame($renewal->order->period_end_at->toJSON(), $account->current_period_end_at->toJSON());
            $this->assertFalse($account->auto_renew_enabled);
            $this->assertFalse($account->saved_payment_method_active);
            $this->assertCount(0, $this->gateway->payments);
        } finally {
            $this->travelBack();
        }
    }

    public function test_bank_renewal_defers_until_purchase_is_paid_and_can_reissue_a_canceled_invoice(): void
    {
        $this->prepareBankBilling();
        $this->checkout(['machinery']);
        $first = \App\Models\CommercialBankInvoice::query()->sole();
        app(\App\Services\Billing\Bank\BankSettlementService::class)->ingest($this->bankTransaction($first, 'purchase-before-renewal', $first->amount_minor));
        $account = OrganizationCommercialAccount::query()->sole();
        $this->checkoutPayload(['target_package_slugs' => ['machinery', 'finance-contracts'], 'current_package_slugs' => ['working-entry', 'machinery'],
            'client_idempotency_key' => '33333333-3333-4333-8333-333333333333']);
        $purchase = \App\Models\CommercialBankInvoice::query()->latest('id')->firstOrFail();
        $renewals = app(\App\Services\Billing\Bank\BankRenewalService::class);
        try {
            $renewals->create($account, '44444444-4444-4444-8444-444444444444');
            $this->fail('Renewal was created before the pending purchase was resolved');
        } catch (CommercialCheckoutConflictException) {
            $this->assertDatabaseCount('commercial_bank_invoices', 2);
        }
        app(\App\Services\Billing\Bank\BankSettlementService::class)->ingest($this->bankTransaction($purchase, 'paid-before-renewal', $purchase->amount_minor));
        $renewal = $renewals->create($account, '44444444-4444-4444-8444-444444444444');
        $this->assertNotSame($purchase->id, $renewal->id);
        $this->assertSame('renewal', $renewal->order->kind);
        $this->assertEqualsCanonicalizing(['working-entry', 'machinery', 'finance-contracts'], $renewal->order->selected_package_slugs);
        app(\App\Services\Billing\Bank\BankBillingService::class)->cancel($this->user, (int) $this->organization->id, $renewal->public_id);
        $reissued = $renewals->create($account, '55555555-5555-4555-8555-555555555555');
        $this->assertNotSame($renewal->id, $reissued->id);
        $this->assertSame('canceled', $renewal->refresh()->status);
        $this->assertSame('awaiting_payment', $reissued->status);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bankCheckoutSources')]
    public function test_bank_checkout_replaces_unfunded_renewal_without_disabling_automatic_invoices(bool $balance): void
    {
        $this->prepareBankBilling();
        $this->checkout(['machinery']);
        $initial = \App\Models\CommercialBankInvoice::query()->sole();
        $settlement = app(\App\Services\Billing\Bank\BankSettlementService::class);
        $settlement->ingest($this->bankTransaction($initial, 'initial-before-upgrade', $initial->amount_minor));
        $account = OrganizationCommercialAccount::query()->sole();
        $this->travelTo($account->current_period_end_at->subDays(5));
        try {
            $renewals = app(\App\Services\Billing\Bank\BankRenewalService::class);
            $old = $renewals->create($account);
            if ($balance) {
                OrganizationBalance::query()->create(['organization_id' => $this->organization->id, 'balance' => 10000000, 'currency' => 'RUB']);
            }
            $request = ['target_package_slugs' => ['machinery', 'finance-contracts'],
                'current_package_slugs' => ['working-entry', 'machinery'], 'use_balance' => $balance,
                'client_idempotency_key' => fake()->uuid()];
            $result = $this->checkoutPayload($request);
            $this->assertSame('canceled', $old->refresh()->status);
            $this->assertSame('canceled', $old->order->status->value);
            $this->assertTrue($account->refresh()->bank_auto_invoice);
            if (! $balance) {
                $counts = $renewals->process(now(), 100);
                $this->assertSame(0, $counts['failed']);
                $this->assertSame(1, \App\Models\CommercialBankInvoice::query()->whereHas('order', fn ($query) => $query->where('kind', 'renewal'))->count());
                $purchase = \App\Models\CommercialBankInvoice::query()->where('public_id', $result['bank_invoice']['id'])->firstOrFail();
                $settlement->ingest($this->bankTransaction($purchase, 'upgraded-before-renewal', $purchase->amount_minor));
            }
            $new = $renewals->create($account->refresh());
            $this->assertNotSame($old->id, $new->id);
            $this->assertEqualsCanonicalizing(['working-entry', 'machinery', 'finance-contracts'], $new->order->selected_package_slugs);
            $this->assertSame($old->order->period_start_at->toJSON(), $new->order->period_start_at->toJSON());
            $this->assertSame($new->id, $renewals->create($account)->id);
            $replay = $this->checkoutPayload($request);
            $this->assertSame($result['order_id'], $replay['order_id']);
            $this->assertSame('awaiting_payment', $new->refresh()->status);
        } finally {
            $this->travelBack();
        }
    }

    public static function bankCheckoutSources(): array
    {
        return ['bank' => [false], 'balance' => [true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('changedBankOperationFields')]
    public function test_bank_corrected_operation_identity_or_date_is_quarantined_without_reactivation(string $field): void
    {
        $this->prepareBankBilling();
        $this->checkout(['machinery']);
        $invoice = \App\Models\CommercialBankInvoice::query()->sole();
        $settlement = app(\App\Services\Billing\Bank\BankSettlementService::class);
        $transaction = $this->bankTransaction($invoice, 'corrected-operation', $invoice->amount_minor);
        $originalDate = $transaction['documentProcessDate'];
        $operation = $settlement->ingest($transaction);
        $activated = $invoice->refresh()->activated_at->toJSON();
        if ($field === 'paymentId') {
            $settlement->ingest($this->bankTransaction($invoice, 'corrected-payment-id', 1000));
        }
        $transaction[$field] = $field === 'paymentId' ? 'corrected-payment-id' : now('Europe/Moscow')->addDay()->toDateString();
        $changed = $settlement->ingest($transaction);
        $this->assertSame($operation->id, $changed->id);
        $this->assertSame('bank_operation_changed', $changed->review_reason);
        $this->assertSame('corrected-operation', $changed->payment_id);
        $this->assertSame($originalDate, $changed->booked_on->toDateString());
        $this->assertSame($activated, $invoice->refresh()->activated_at->toJSON());
        $this->assertDatabaseCount('commercial_bank_allocations', 1);
        $this->assertDatabaseCount('commercial_bank_operations', $field === 'paymentId' ? 2 : 1);
    }

    public static function changedBankOperationFields(): array
    {
        return ['date' => ['documentProcessDate'], 'identity' => ['paymentId']];
    }

    public function test_bank_existing_invoice_replays_when_the_bank_or_buyer_configuration_changes(): void
    {
        $this->prepareBankBilling();
        $first = $this->checkout(['machinery']);
        $client = \Mockery::mock(\App\Services\Billing\Bank\TochkaClient::class);
        $client->shouldReceive('ready')->andReturn(false);
        $this->app->instance(\App\Services\Billing\Bank\TochkaClient::class, $client);
        $this->organization->forceFill(['tax_number' => null])->save();
        $again = $this->checkout(['machinery']);
        $this->assertSame($first['bank_invoice']['id'], $again['bank_invoice']['id']);
        $this->assertSame('7707083893', $again['bank_invoice']['buyer']['inn']);
        $this->assertFalse($again['_created']);
        $this->assertDatabaseCount('commercial_bank_invoices', 1);
    }

    public function test_bank_manual_renewal_preserves_the_existing_response_contract_and_created_flag(): void
    {
        $this->prepareBankBilling();
        $this->checkout(['machinery']);
        $invoice = \App\Models\CommercialBankInvoice::query()->sole();
        app(\App\Services\Billing\Bank\BankSettlementService::class)->ingest($this->bankTransaction($invoice, 'manual-contract-source', $invoice->amount_minor));
        $service = app(\App\Services\Billing\CommercialManualPaymentService::class);
        $first = $service->create($this->organization, $this->user, '44444444-4444-4444-8444-444444444444');
        $client = \Mockery::mock(\App\Services\Billing\Bank\TochkaClient::class);
        $client->shouldReceive('ready')->andReturn(false);
        $this->app->instance(\App\Services\Billing\Bank\TochkaClient::class, $client);
        $again = $service->create($this->organization, $this->user, '44444444-4444-4444-8444-444444444444');
        $this->assertTrue($first['_created']);
        $this->assertFalse($again['_created']);
        $this->assertSame($first['order_id'], $again['order_id']);
        foreach (['selected_package_slugs', 'target_package_slugs', 'current_package_slugs', 'paid_package_slugs',
            'selected_resource_addons', 'paid_composition_items', 'period_start_at', 'period_end_at', 'grace_deadline_at'] as $field) {
            $this->assertArrayHasKey($field, $first);
        }
        $this->assertNotEmpty($first['paid_composition_items']);
        $this->assertNotNull($first['grace_deadline_at']);
        $this->assertNull($first['confirmation_url']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('fundedBankRenewalCases')]
    public function test_bank_funded_future_renewal_blocks_external_and_balance_contour_changes(bool $full): void
    {
        $this->prepareBankBilling();
        $this->checkout(['machinery']);
        $invoice = \App\Models\CommercialBankInvoice::query()->sole();
        $settlement = app(\App\Services\Billing\Bank\BankSettlementService::class);
        $settlement->ingest($this->bankTransaction($invoice, 'before-prepaid-change', $invoice->amount_minor));
        $renewal = app(\App\Services\Billing\Bank\BankRenewalService::class)->create(OrganizationCommercialAccount::query()->sole());
        $settlement->ingest($this->bankTransaction($renewal, 'prepaid-change', $full ? $renewal->amount_minor : 1000));
        foreach ([false, true] as $balance) {
            try {
                $this->checkoutPayload(['target_package_slugs' => ['machinery', 'finance-contracts'],
                    'current_package_slugs' => ['working-entry', 'machinery'], 'use_balance' => $balance,
                    'client_idempotency_key' => $balance ? '77777777-7777-4777-8777-777777777777' : '66666666-6666-4666-8666-666666666666']);
                $this->fail('Funded renewal allowed a composition change');
            } catch (CommercialCheckoutConflictException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertDatabaseCount('commercial_orders', 2);
        $this->assertNull($renewal->refresh()->activated_at);
    }

    public static function fundedBankRenewalCases(): array
    {
        return ['partial' => [false], 'full' => [true]];
    }

    public function test_bank_legacy_paid_purchase_waits_for_the_conflicting_future_prepayment_to_be_resolved(): void
    {
        $this->prepareBankBilling();
        $this->checkout(['machinery']);
        $initial = \App\Models\CommercialBankInvoice::query()->sole();
        $settlement = app(\App\Services\Billing\Bank\BankSettlementService::class);
        $settlement->ingest($this->bankTransaction($initial, 'initial-conflict', $initial->amount_minor));
        $this->checkoutPayload(['target_package_slugs' => ['machinery', 'finance-contracts'],
            'current_package_slugs' => ['working-entry', 'machinery'], 'client_idempotency_key' => '33333333-3333-4333-8333-333333333333']);
        $purchase = \App\Models\CommercialBankInvoice::query()->latest('id')->firstOrFail();
        $purchase->order->forceFill(['status' => 'canceled'])->save();
        $renewal = app(\App\Services\Billing\Bank\BankRenewalService::class)->create(OrganizationCommercialAccount::query()->sole());
        $purchase->order->forceFill(['status' => 'pending_payment'])->save();
        $settlement->ingest($this->bankTransaction($renewal, 'funded-conflict', $renewal->amount_minor));
        $settlement->ingest($this->bankTransaction($purchase, 'purchase-conflict', $purchase->amount_minor));
        $this->assertNull($purchase->refresh()->activated_at);
        $this->assertSame('paid', $purchase->status);
        $this->assertSame('funded_renewal_conflict', $purchase->operations()->sole()->review_reason);
        $this->assertFalse($purchase->documents()->where('kind', 'closing')->exists());
        $this->assertDatabaseMissing('organization_package_subscriptions', ['package_slug' => 'finance-contracts', 'status' => 'active']);
    }

    public function test_bank_changed_composition_is_quarantined_before_renewal_activation(): void
    {
        $this->prepareBankBilling();
        $this->checkout(['machinery']);
        $initial = \App\Models\CommercialBankInvoice::query()->sole();
        $settlement = app(\App\Services\Billing\Bank\BankSettlementService::class);
        $settlement->ingest($this->bankTransaction($initial, 'initial-snapshot', $initial->amount_minor));
        $account = OrganizationCommercialAccount::query()->sole();
        $renewal = app(\App\Services\Billing\Bank\BankRenewalService::class)->create($account);
        $settlement->ingest($this->bankTransaction($renewal, 'future-snapshot', $renewal->amount_minor));
        OrganizationPackageSubscription::query()->where('package_slug', 'machinery')->update(['package_slug' => 'finance-contracts']);
        $this->travelTo($renewal->order->period_start_at);
        try {
            $settlement->activate((int) $renewal->id);
            $this->assertSame('review_required', $renewal->refresh()->status);
            $this->assertNull($renewal->activated_at);
            $this->assertSame('commercial_composition_changed', $renewal->operations()->sole()->review_reason);
            $this->assertFalse($renewal->documents()->where('kind', 'closing')->exists());
        } finally {
            $this->travelBack();
        }
    }

    public function test_bank_grace_renewal_preserves_the_original_billing_anchor(): void
    {
        $this->prepareBankBilling();
        $this->checkout(['machinery']);
        $first = \App\Models\CommercialBankInvoice::query()->sole();
        $settlement = app(\App\Services\Billing\Bank\BankSettlementService::class);
        $settlement->ingest($this->bankTransaction($first, 'before-grace', $first->amount_minor));
        $account = OrganizationCommercialAccount::query()->sole();
        $anchor = CarbonImmutable::instance($account->current_period_end_at);
        $this->travelTo($anchor->addDays(2));
        try {
            $renewal = app(\App\Services\Billing\Bank\BankRenewalService::class)->create($account);
            $this->assertSame($anchor->toJSON(), $renewal->order->period_start_at->toJSON());
            $this->assertSame($anchor->addDays(30)->toJSON(), $renewal->order->period_end_at->toJSON());
            $settlement->ingest($this->bankTransaction($renewal, 'in-grace', $renewal->amount_minor));
            $this->assertNotNull($renewal->refresh()->activated_at);
            $this->assertSame($anchor->addDays(30)->toJSON(), $account->refresh()->current_period_end_at->toJSON());
        } finally {
            $this->travelBack();
        }
    }

    public function test_bank_funding_holds_the_account_lock_before_updating_the_invoice(): void
    {
        $this->prepareBankBilling();
        $this->checkout(['machinery']);
        $initial = \App\Models\CommercialBankInvoice::query()->sole();
        $settlement = app(\App\Services\Billing\Bank\BankSettlementService::class);
        $settlement->ingest($this->bankTransaction($initial, 'before-lock-probe', $initial->amount_minor));
        $account = OrganizationCommercialAccount::query()->sole();
        $invoice = app(\App\Services\Billing\Bank\BankRenewalService::class)->create($account);
        config(['database.connections.bank_lock_probe' => config('database.connections.'.$this->connectionName)]);
        $probe = DB::connection('bank_lock_probe');
        $probed = false;
        DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query) use ($probe, $account, &$probed): void {
            if ($probed || ! str_starts_with($query->sql, 'update "commercial_bank_invoices"')) {
                return;
            }
            $probed = true;
            try {
                $probe->select('select id from organization_commercial_accounts where id = ? for update nowait', [$account->id]);
                $this->fail('Bank funding did not serialize with balance checkout');
            } catch (\Illuminate\Database\QueryException $exception) {
                $this->assertSame('55P03', $exception->errorInfo[0]);
            }
        });
        try {
            $settlement->ingest($this->bankTransaction($invoice, 'funding-lock-probe', 1000));
            $this->assertTrue($probed);
            $this->assertSame(1000, $invoice->refresh()->paid_minor);
        } finally {
            DB::purge('bank_lock_probe');
        }
    }

    public function test_bank_renewal_batches_rotate_past_prepared_accounts(): void
    {
        $this->prepareBankBilling();
        $this->checkout(['machinery']);
        $initial = \App\Models\CommercialBankInvoice::query()->sole();
        app(\App\Services\Billing\Bank\BankSettlementService::class)->ingest($this->bankTransaction($initial, 'before-batches', $initial->amount_minor));
        $account = OrganizationCommercialAccount::query()->sole();
        $periodEnd = CarbonImmutable::now()->addDays(2);
        $account->forceFill(['current_period_start_at' => $periodEnd->subDays(30), 'current_period_end_at' => $periodEnd])->save();
        $rows = $account->packageSubscriptions()->get();
        for ($index = 0; $index < 2; $index++) {
            $organization = $this->organization->replicate();
            $organization->saveQuietly();
            $replica = $account->replicate()->fill(['organization_id' => $organization->id]);
            $replica->save();
            foreach ($rows as $row) {
                $row->replicate()->fill(['organization_id' => $organization->id, 'commercial_account_id' => $replica->id])->save();
            }
        }
        $service = app(\App\Services\Billing\Bank\BankRenewalService::class);
        for ($index = 0; $index < 3; $index++) {
            $result = $service->process(CarbonImmutable::now(), 1);
            $this->assertSame(1, $result['processed']);
            $this->assertSame(0, $result['failed']);
        }
        $this->assertSame(3, CommercialOrder::query()->where('kind', 'renewal')->distinct()->count('commercial_account_id'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('fundedBankRenewalCases')]
    public function test_bank_manual_invoice_preserves_grace_when_automatic_invoices_are_disabled(bool $partial): void
    {
        $this->prepareBankBilling();
        $this->checkout(['machinery']);
        $initial = \App\Models\CommercialBankInvoice::query()->sole();
        $settlement = app(\App\Services\Billing\Bank\BankSettlementService::class);
        $settlement->ingest($this->bankTransaction($initial, 'before-manual-grace', $initial->amount_minor));
        $account = OrganizationCommercialAccount::query()->sole();
        $account->forceFill(['bank_auto_invoice' => false])->save();
        $anchor = CarbonImmutable::instance($account->current_period_end_at);
        $this->travelTo($anchor->subHour());
        try {
            $service = app(\App\Services\Billing\Bank\BankRenewalService::class);
            $service->manual($this->organization, $this->user, '55555555-5555-4555-8555-555555555555');
            $invoice = \App\Models\CommercialBankInvoice::query()->latest('id')->firstOrFail();
            if ($partial) {
                $settlement->ingest($this->bankTransaction($invoice, 'manual-grace-partial', 1000));
            }
            $this->travelTo($anchor->addDay());
            $service->process(CarbonImmutable::now());
            $this->assertSame('grace', $account->refresh()->status->value);
            $this->assertFalse($account->bank_auto_invoice);
            $this->assertSame($anchor->addDays(7)->toJSON(), $account->grace_ends_at->toJSON());
            $this->travelTo($anchor->addDays(7)->addMinute());
            $service->process(CarbonImmutable::now());
            $this->assertSame('suspended', $account->refresh()->status->value);
        } finally {
            $this->travelBack();
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('longBankBillingPeriods')]
    public function test_bank_long_period_purchase_and_renewal_preserve_amount_dates_and_documents(string $period): void
    {
        $this->prepareBankBilling();
        $term = \App\Enums\Billing\CommercialBillingPeriod::from($period);
        $this->checkoutPayload(['billing_period' => $period]);
        $initial = \App\Models\CommercialBankInvoice::query()->sole();
        $this->assertSame($term->amountMinor(4580000), $initial->amount_minor);
        $this->assertSame($period, $initial->snapshot['billing_period']);
        $settlement = app(\App\Services\Billing\Bank\BankSettlementService::class);
        $settlement->ingest($this->bankTransaction($initial, 'long-initial', $initial->amount_minor));
        $account = OrganizationCommercialAccount::query()->sole();
        $this->assertSame($period, $account->billing_period);
        $this->assertTrue($term->endAt(CarbonImmutable::instance($account->current_period_start_at))->equalTo($account->current_period_end_at));
        $anchor = CarbonImmutable::instance($account->current_period_end_at);
        $this->travelTo($anchor->subDay());
        try {
            $renewal = app(\App\Services\Billing\Bank\BankRenewalService::class)->create($account);
            $this->assertSame($period, $renewal->order->billing_period);
            $this->assertSame($initial->amount_minor, $renewal->amount_minor);
            $this->assertTrue($anchor->equalTo($renewal->order->period_start_at));
            $this->assertTrue($term->endAt($anchor)->equalTo($renewal->order->period_end_at));
            $settlement->ingest($this->bankTransaction($renewal, 'long-renewal', $renewal->amount_minor));
            $this->assertNull($renewal->refresh()->activated_at);
            $this->travelTo($anchor);
            $settlement->activate((int) $renewal->id);
            $this->assertNotNull($renewal->refresh()->activated_at);
            $this->assertSame($period, $account->refresh()->billing_period);
            $this->assertTrue($term->endAt($anchor)->equalTo($account->current_period_end_at));
            $closing = $renewal->documents()->where('kind', 'closing')->sole();
            $this->assertSame($period, $closing->snapshot['billing_period']);
            $this->assertSame($renewal->order->period_end_at->toIso8601String(), $closing->snapshot['period_end_at']);
        } finally {
            $this->travelBack();
        }
    }

    public static function longBankBillingPeriods(): array
    {
        return ['half_year' => ['6_months'], 'year' => ['12_months']];
    }

    public function test_bank_paid_extension_uses_the_common_period_snapshot_and_refund_restore(): void
    {
        $this->prepareBankBilling();
        $this->checkout(['machinery']);
        $initial = \App\Models\CommercialBankInvoice::query()->sole();
        $settlement = app(\App\Services\Billing\Bank\BankSettlementService::class);
        $settlement->ingest($this->bankTransaction($initial, 'before-paid-extension', $initial->amount_minor));
        $account = OrganizationCommercialAccount::query()->sole();
        $anchor = CarbonImmutable::instance($account->current_period_end_at);
        $this->checkoutPayload(['billing_period' => '12_months', 'renew_subscription' => true,
            'current_package_slugs' => ['working-entry', 'machinery']]);
        $invoice = \App\Models\CommercialBankInvoice::query()->latest('id')->firstOrFail();
        $settlement->ingest($this->bankTransaction($invoice, 'paid-extension', $invoice->amount_minor));
        $this->assertNotNull($invoice->refresh()->activated_at);
        $this->assertSame('12_months', $account->refresh()->billing_period);
        $this->assertTrue($anchor->addMonthsNoOverflow(12)->equalTo($account->current_period_end_at));
        $this->assertSame($anchor->toJSON(), $invoice->order->paid_period_snapshot['end_at']);
        $refunds = app(\App\Services\Billing\Bank\BankRefundService::class);
        $refunds->request($this->user, (int) $this->organization->id, $invoice->public_id, $invoice->amount_minor, 'payment',
            'Отказ от предоплаченного будущего периода', '66666666-6666-4666-8666-666666666666');
        $refund = \App\Models\CommercialBankRefund::query()->sole();
        $operator = new \App\Models\SystemAdmin(['role' => 'super_admin', 'is_active' => true]);
        $operator->id = 100;
        $refunds->decide($operator, (int) $refund->id, true);
        $outgoing = $this->bankTransaction($invoice, 'paid-extension-return', $invoice->amount_minor);
        $outgoing['creditDebitIndicator'] = 'Debit';
        $outgoing['description'] = $refund->reference;
        $outgoing['DebtorAccount']['identification'] = config('bank_billing.seller_account');
        $outgoing['CreditorAccount']['identification'] = $refund->operation->payer_account;
        $settlement->ingest($outgoing);
        $this->assertSame('30_days', $account->refresh()->billing_period);
        $this->assertTrue($anchor->equalTo($account->current_period_end_at));
        $this->assertSame(2, OrganizationPackageSubscription::query()->where('status', 'active')->count());
    }

    public function test_bank_refunded_prepayment_can_be_completed_and_activated_once(): void
    {
        $this->prepareBankBilling();
        $this->checkout(['machinery']);
        $initial = \App\Models\CommercialBankInvoice::query()->sole();
        $settlement = app(\App\Services\Billing\Bank\BankSettlementService::class);
        $settlement->ingest($this->bankTransaction($initial, 'before-refunded-prepayment', $initial->amount_minor));
        $account = OrganizationCommercialAccount::query()->sole();
        $anchor = CarbonImmutable::instance($account->current_period_end_at);
        $invoice = app(\App\Services\Billing\Bank\BankRenewalService::class)->create($account);
        $settlement->ingest($this->bankTransaction($invoice, 'prepayment-before-refund', $invoice->amount_minor));
        $refunds = app(\App\Services\Billing\Bank\BankRefundService::class);
        $operator = new \App\Models\SystemAdmin(['role' => 'super_admin', 'is_active' => true]);
        $operator->id = 100;
        $returnPart = function (int $amount, string $id) use ($invoice, $settlement, $refunds, $operator): void {
            $refunds->request($this->user, (int) $this->organization->id, $invoice->public_id, $amount, 'payment',
                'Частичный возврат предоплаты по заявлению', fake()->uuid());
            $refund = \App\Models\CommercialBankRefund::query()->latest('id')->firstOrFail();
            $refunds->decide($operator, (int) $refund->id, true);
            $outgoing = $this->bankTransaction($invoice, $id, $amount);
            $outgoing['creditDebitIndicator'] = 'Debit';
            $outgoing['description'] = $refund->reference;
            $outgoing['DebtorAccount']['identification'] = config('bank_billing.seller_account');
            $outgoing['CreditorAccount']['identification'] = $refund->operation->payer_account;
            $settlement->ingest($outgoing);
            $settlement->ingest($outgoing);
        };
        $returnPart(1000, 'prepayment-partial-return');
        $this->assertSame('partially_paid', $invoice->refresh()->status);
        $this->assertNull($invoice->paid_at);
        $this->assertSame('pending_payment', $invoice->order->status->value);
        $this->assertSame(1000, app(\App\Services\Billing\Bank\BankInvoiceService::class)->payload($invoice)['remaining_minor']);
        $settlement->ingest($this->bankTransaction($invoice, 'prepayment-top-up', 1000));
        $this->assertSame('paid', $invoice->refresh()->status);
        $this->assertSame($invoice->amount_minor, $invoice->paid_minor);
        $this->assertSame(1000, $invoice->refunded_minor);
        $this->assertSame(0, $invoice->payment->refunded_amount_minor);
        $this->assertNull($invoice->activated_at);
        $this->travelTo($anchor);
        try {
            $settlement->activate((int) $invoice->id);
            $settlement->activate((int) $invoice->id);
            $this->assertNotNull($invoice->refresh()->activated_at);
            $this->assertSame($anchor->addDays(30)->toJSON(), $account->refresh()->current_period_end_at->toJSON());
            $this->assertSame(1, $invoice->documents()->where('kind', 'closing')->count());
            $returnPart(500, 'after-activation-partial-return');
            $this->assertSame(1500, $invoice->refresh()->refunded_minor);
            $this->assertSame(500, $invoice->payment->refunded_amount_minor);
            $this->assertSame('active', $account->refresh()->status->value);
        } finally {
            $this->travelBack();
        }
    }

    public function test_bank_refund_waits_for_executed_payment_order_instead_of_a_memorial_order(): void
    {
        $this->prepareBankBilling();
        $this->checkout(['machinery']);
        $invoice = \App\Models\CommercialBankInvoice::query()->sole();
        $settlement = app(\App\Services\Billing\Bank\BankSettlementService::class);
        $settlement->ingest($this->bankTransaction($invoice, 'before-memorial-return', $invoice->amount_minor));
        $refunds = app(\App\Services\Billing\Bank\BankRefundService::class);
        $refunds->request($this->user, (int) $this->organization->id, $invoice->public_id, $invoice->amount_minor, 'payment',
            'Возврат полной оплаты по заявлению покупателя', fake()->uuid());
        $refund = \App\Models\CommercialBankRefund::query()->sole();
        $operator = new \App\Models\SystemAdmin(['role' => 'super_admin', 'is_active' => true]);
        $operator->id = 100;
        $refunds->decide($operator, (int) $refund->id, true);
        $outgoing = $this->bankTransaction($invoice, 'memorial-return', $invoice->amount_minor);
        $outgoing['creditDebitIndicator'] = 'Debit';
        $outgoing['description'] = $refund->reference;
        $outgoing['DebtorAccount']['identification'] = config('bank_billing.seller_account');
        $outgoing['CreditorAccount']['identification'] = $refund->operation->payer_account;
        $outgoing['transactionTypeCode'] = 'Мемориальный ордер';
        $operation = $settlement->ingest($outgoing);
        $this->assertSame('awaiting_bank', $refund->refresh()->status);
        $this->assertSame('refund_execution_pending', $operation->review_reason);
        $this->assertSame('paid', $invoice->refresh()->status);
        $this->assertSame(2, OrganizationPackageSubscription::query()->where('status', 'active')->count());
        $outgoing['transactionTypeCode'] = 'Неопределенное значение';
        $settlement->ingest($outgoing);
        $this->assertSame('awaiting_bank', $refund->refresh()->status);
        $outgoing['transactionTypeCode'] = 'Платежное поручение';
        $settlement->ingest($outgoing);
        $settlement->ingest($outgoing);
        $this->assertSame('completed', $refund->refresh()->status);
        $this->assertSame('refunded', $invoice->refresh()->status);
        $this->assertNull($operation->refresh()->review_reason);
        $this->assertSame(2, $invoice->operations()->count());
    }

    public function test_bank_sync_reports_a_busy_lock_and_recovers_after_release(): void
    {
        $this->prepareBankBilling();
        $client = \Mockery::mock(\App\Services\Billing\Bank\TochkaClient::class);
        $client->shouldReceive('ready')->andReturn(false);
        $this->app->instance(\App\Services\Billing\Bank\TochkaClient::class, $client);
        $lock = \Illuminate\Support\Facades\Cache::lock('billing:tochka:sync', 1800);
        $this->assertTrue($lock->get());
        $service = app(\App\Services\Billing\Bank\BankSyncService::class);
        try {
            $this->assertSame(['ready' => false, 'processed' => 0, 'failed' => 0, 'locked' => true], $service->process());
            $this->assertDatabaseCount('commercial_bank_statements', 0);
        } finally {
            $lock->release();
        }
        $this->assertSame(['ready' => false, 'processed' => 0, 'failed' => 0], $service->process());
    }

    public function test_bank_statement_polling_activates_without_webhooks_and_replays_safely(): void
    {
        $this->prepareBankBilling();
        $this->checkout(['machinery']);
        $invoice = \App\Models\CommercialBankInvoice::query()->sole();
        $date = now('Europe/Moscow')->toDateString();
        config(['bank_billing.start_date' => $date]);
        $client = \Mockery::mock(\App\Services\Billing\Bank\TochkaClient::class);
        $client->shouldReceive('ready')->andReturn(true);
        $client->shouldReceive('initializeStatement')->with($date)->once()->andReturn('statement-1');
        $client->shouldReceive('statement')->with('statement-1')->once()->andReturn(['Data' => ['Statement' => [[
            'accountId' => config('bank_billing.account_id'), 'statementId' => 'statement-1', 'status' => 'Ready',
            'startDateTime' => $date, 'endDateTime' => $date, 'Transaction' => [$this->bankTransaction($invoice, 'statement-payment', $invoice->amount_minor)],
        ]]]]);
        $this->app->instance(\App\Services\Billing\Bank\TochkaClient::class, $client);
        $documents = \Mockery::mock(\App\Services\Billing\Bank\BankDocumentService::class)->makePartial();
        $documents->shouldReceive('store')->andReturnUsing(fn ($document) => $document);
        $this->app->instance(\App\Services\Billing\Bank\BankDocumentService::class, $documents);
        $first = app(\App\Services\Billing\Bank\BankSyncService::class)->process();
        $again = app(\App\Services\Billing\Bank\BankSyncService::class)->process();
        $this->assertSame(0, $first['failed']);
        $this->assertSame(1, $first['processed']);
        $this->assertSame(0, $again['processed']);
        $this->assertNotNull($invoice->refresh()->activated_at);
        $this->assertDatabaseCount('commercial_bank_allocations', 1);
    }

    public function test_bank_unexpected_pagination_is_visible_and_never_marks_an_incomplete_statement_done(): void
    {
        $this->prepareBankBilling();
        $this->checkout(['machinery']);
        $invoice = \App\Models\CommercialBankInvoice::query()->sole();
        $date = now('Europe/Moscow')->toDateString();
        config(['bank_billing.start_date' => $date]);
        $client = \Mockery::mock(\App\Services\Billing\Bank\TochkaClient::class);
        $client->shouldReceive('ready')->andReturn(true);
        $client->shouldReceive('initializeStatement')->once()->andReturn('unsupported-pages');
        $client->shouldReceive('statement')->andReturn(['Meta' => ['totalPages' => 2], 'Data' => ['Statement' => [[
            'accountId' => config('bank_billing.account_id'), 'statementId' => 'unsupported-pages', 'status' => 'Ready',
            'startDateTime' => $date, 'endDateTime' => $date,
            'Transaction' => [$this->bankTransaction($invoice, 'incomplete-statement', $invoice->amount_minor)],
        ]]]]);
        $this->app->instance(\App\Services\Billing\Bank\TochkaClient::class, $client);
        $documents = \Mockery::mock(\App\Services\Billing\Bank\BankDocumentService::class)->makePartial();
        $documents->shouldReceive('store')->andReturnUsing(fn ($document) => $document);
        $this->app->instance(\App\Services\Billing\Bank\BankDocumentService::class, $documents);
        $result = app(\App\Services\Billing\Bank\BankSyncService::class)->process();
        $this->assertSame(1, $result['failed']);
        $this->assertSame(0, $result['processed']);
        $this->assertDatabaseHas('commercial_bank_statements', ['statement_id' => 'unsupported-pages', 'status' => 'error',
            'failure_code' => 'statement_pagination_unsupported', 'processed_at' => null]);
        $this->assertDatabaseCount('commercial_bank_operations', 0);
        $this->assertNull($invoice->refresh()->activated_at);
    }

    public function test_bank_malformed_statement_record_does_not_hide_later_payments(): void
    {
        $this->prepareBankBilling();
        $this->checkout(['machinery']);
        $invoice = \App\Models\CommercialBankInvoice::query()->sole();
        $date = now('Europe/Moscow')->toDateString();
        config(['bank_billing.start_date' => $date]);
        $client = \Mockery::mock(\App\Services\Billing\Bank\TochkaClient::class);
        $client->shouldReceive('ready')->andReturn(true);
        $client->shouldReceive('initializeStatement')->once()->andReturn('malformed-record');
        $client->shouldReceive('statement')->twice()->andReturn(['Data' => ['Statement' => [[
            'accountId' => config('bank_billing.account_id'), 'statementId' => 'malformed-record', 'status' => 'Ready',
            'startDateTime' => $date, 'endDateTime' => $date,
            'Transaction' => ['invalid', $this->bankTransaction($invoice, 'valid-after-malformed', $invoice->amount_minor)],
        ]]]]);
        $this->app->instance(\App\Services\Billing\Bank\TochkaClient::class, $client);
        $documents = \Mockery::mock(\App\Services\Billing\Bank\BankDocumentService::class)->makePartial();
        $documents->shouldReceive('store')->andReturnUsing(fn ($document) => $document);
        $this->app->instance(\App\Services\Billing\Bank\BankDocumentService::class, $documents);
        $sync = app(\App\Services\Billing\Bank\BankSyncService::class);
        $this->assertSame(1, $sync->process()['failed']);
        $this->assertSame(1, $sync->process()['failed']);
        $this->assertNotNull($invoice->refresh()->activated_at);
        $this->assertDatabaseCount('commercial_bank_allocations', 1);
        $this->assertDatabaseHas('commercial_bank_statements', ['statement_id' => 'malformed-record', 'status' => 'error']);
    }

    public function test_bank_document_retry_does_not_starve_later_documents(): void
    {
        $this->prepareBankBilling();
        $this->checkout(['machinery']);
        $invoice = \App\Models\CommercialBankInvoice::query()->sole();
        for ($index = 1; $index <= 5; $index++) {
            app(\App\Services\Billing\Bank\BankDocumentService::class)->prepare($invoice, 'correction', (string) $index);
        }
        \App\Models\CommercialBankDocument::query()->update(['updated_at' => now()->subMinute()]);
        $lastId = (int) \App\Models\CommercialBankDocument::query()->max('id');
        $attempts = [];
        $documents = \Mockery::mock(\App\Services\Billing\Bank\BankDocumentService::class)->makePartial();
        $documents->shouldReceive('store')->andReturnUsing(function ($document) use (&$attempts, $lastId) {
            $attempts[] = (int) $document->id;
            if ((int) $document->id !== $lastId) {
                throw new \RuntimeException('temporary_document_failure');
            }

            return $document;
        });
        $this->app->instance(\App\Services\Billing\Bank\BankDocumentService::class, $documents);
        $client = \Mockery::mock(\App\Services\Billing\Bank\TochkaClient::class);
        $client->shouldReceive('ready')->andReturn(false);
        $this->app->instance(\App\Services\Billing\Bank\TochkaClient::class, $client);
        $sync = app(\App\Services\Billing\Bank\BankSyncService::class);
        $sync->process();
        $this->assertNotContains($lastId, $attempts);
        $sync->process();
        $this->assertContains($lastId, $attempts);
    }

    public function test_bank_signed_notification_is_deduplicated_and_never_grants_access_by_itself(): void
    {
        $this->prepareBankBilling();
        config(['bank_billing.customer_code' => 'test-customer']);
        $payload = ['webhookType' => 'incomingPayment', 'customerCode' => 'test-customer', 'paymentId' => 'incoming-1',
            'date' => now('Europe/Moscow')->toDateString(), 'SideRecipient' => ['account' => config('bank_billing.seller_account'), 'bankCode' => config('bank_billing.seller_bik')]];
        $verifier = \Mockery::mock(\App\Services\Billing\Bank\TochkaWebhookVerifier::class);
        $verifier->shouldReceive('verify')->with('signed-notification')->andReturn($payload);
        $service = new \App\Services\Billing\Bank\TochkaWebhookService($verifier);
        $service->receive('signed-notification');
        $service->receive('signed-notification');
        $this->app->instance(\App\Services\Billing\Bank\TochkaWebhookVerifier::class, $verifier);
        config(['bank_billing.enabled' => true]);
        $this->call('POST', '/api/v1/webhooks/tochka', [], [], [], ['CONTENT_TYPE' => 'text/plain'], 'signed-notification')
            ->assertOk()->assertSee('OK');
        $this->assertDatabaseCount('commercial_bank_webhook_events', 1);
        $this->assertDatabaseCount('commercial_bank_operations', 0);
        $this->assertDatabaseCount('organization_package_subscriptions', 0);
        $event = \App\Models\CommercialBankWebhookEvent::query()->sole();
        $this->assertSame(hash('sha256', 'signed-notification'), $event->fingerprint);
        $this->assertNull($event->processed_at);
    }

    public function test_bank_backfill_does_not_skip_dates_after_a_newer_webhook_statement(): void
    {
        $this->prepareBankBilling();
        $today = \Carbon\CarbonImmutable::now('Europe/Moscow')->startOfDay();
        config(['bank_billing.start_date' => $today->subDays(35)->toDateString()]);
        \App\Models\CommercialBankStatement::query()->create(['account_id' => config('bank_billing.account_id'),
            'statement_date' => $today->toDateString(), 'status' => 'done', 'processed_at' => now()]);
        $client = \Mockery::mock(\App\Services\Billing\Bank\TochkaClient::class);
        $client->shouldReceive('ready')->andReturn(true);
        $client->shouldReceive('initializeStatement')->andReturnUsing(fn ($date) => $date);
        $client->shouldReceive('statement')->andReturnUsing(fn ($id): array => ['Data' => ['Statement' => [[
            'accountId' => config('bank_billing.account_id'), 'statementId' => $id, 'status' => 'Processing',
        ]]]]);
        $this->app->instance(\App\Services\Billing\Bank\TochkaClient::class, $client);
        app(\App\Services\Billing\Bank\BankSyncService::class)->process();
        app(\App\Services\Billing\Bank\BankSyncService::class)->process();
        $this->assertSame(36, \App\Models\CommercialBankStatement::query()->count());
        $this->assertSame(36, \App\Models\CommercialBankStatement::query()->where('is_periodic', true)->count());
    }

    public function test_bank_manual_matching_requires_operator_authority_and_records_the_basis(): void
    {
        $this->prepareBankBilling();
        $this->checkout(['machinery']);
        $invoice = \App\Models\CommercialBankInvoice::query()->sole();
        $service = app(\App\Services\Billing\Bank\BankSettlementService::class);
        $transaction = $this->bankTransaction($invoice, 'third-party', $invoice->amount_minor);
        $transaction['DebtorParty']['inn'] = '7736050003';
        $operation = $service->ingest($transaction);
        $this->assertSame('payer_mismatch', $operation->review_reason);
        $observer = new \App\Models\SystemAdmin(['role' => 'security_auditor', 'is_active' => true]);
        $observer->id = 101;
        try {
            $service->allocateManually($observer, (int) $operation->id, (int) $invoice->id, 'Письмо о платеже третьего лица');
            $this->fail('Observer approved payment');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        $operator = new \App\Models\SystemAdmin(['role' => 'super_admin', 'is_active' => true]);
        $operator->id = 100;
        $service->allocateManually($operator, (int) $operation->id, (int) $invoice->id, 'Письмо о платеже третьего лица');
        $this->assertSame(100, (int) $operation->refresh()->reviewed_by);
        $this->assertSame('Письмо о платеже третьего лица', $operation->review_note);
        $this->assertNotNull($invoice->refresh()->activated_at);
    }

    public function test_bank_documents_are_immutable_private_objects_with_repeatable_downloads(): void
    {
        $this->prepareBankBilling();
        $this->checkout(['machinery']);
        $document = \App\Models\CommercialBankDocument::query()->sole();
        $path = 'org-'.$this->organization->id.'/billing/documents/'.$document->invoice->public_id.'/'.$document->public_id.'.pdf';
        $content = '%PDF-1.4 bank invoice test';
        $files = \Mockery::mock(\App\Services\Storage\FileService::class);
        $files->shouldReceive('existsCurrent')->once()->with($path)->andReturn(false);
        $files->shouldReceive('putPrivate')->once()->with($path, $content, 'application/pdf', hash('sha256', $content))
            ->andReturn(new \App\Services\Storage\DTO\CurrentStoredFile($path, 'etag', strlen($content), hash('sha256', $content), 'application/pdf'));
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $content);
        rewind($stream);
        $files->shouldReceive('readCurrent')->once()->with($path)->andReturn($stream);
        $service = new class($files, $content) extends \App\Services\Billing\Bank\BankDocumentService
        {
            public function __construct(\App\Services\Storage\FileService $files, private readonly string $content)
            {
                parent::__construct($files);
            }

            public function render(\App\Models\CommercialBankDocument $document): string
            {
                return $this->content;
            }
        };
        $stored = $service->store($document);
        $this->assertSame('ready', $stored->status);
        $this->assertSame(hash('sha256', $content), $stored->sha256);
        $this->assertNotNull($stored->notified_at);
        $read = $service->read($stored);
        $this->assertSame($content, stream_get_contents($read));
        fclose($read);
        $this->assertDatabaseCount('commercial_bank_documents', 1);
        $this->assertDatabaseCount('commercial_billing_notification_keys', 1);
    }

    public function test_bank_personal_account_cannot_be_allocated_even_by_an_operator(): void
    {
        $this->prepareBankBilling();
        $this->checkout(['machinery']);
        $invoice = \App\Models\CommercialBankInvoice::query()->sole();
        $service = app(\App\Services\Billing\Bank\BankSettlementService::class);
        $transaction = $this->bankTransaction($invoice, 'personal', $invoice->amount_minor);
        $transaction['DebtorAccount']['identification'] = '40817810000000000002';
        $operation = $service->ingest($transaction);
        $this->assertSame('payer_not_b2b', $operation->review_reason);
        $operator = new \App\Models\SystemAdmin(['role' => 'super_admin', 'is_active' => true]);
        $operator->id = 100;
        $this->expectException(\InvalidArgumentException::class);
        try {
            $service->allocateManually($operator, (int) $operation->id, (int) $invoice->id, 'Попытка зачесть личный перевод');
        } finally {
            $this->assertNull($invoice->refresh()->activated_at);
        }
    }

    public function test_bank_overpayment_refund_does_not_reduce_the_paid_subscription(): void
    {
        $this->prepareBankBilling();
        $this->checkout(['machinery']);
        $invoice = \App\Models\CommercialBankInvoice::query()->sole();
        $settlement = app(\App\Services\Billing\Bank\BankSettlementService::class);
        $settlement->ingest($this->bankTransaction($invoice, 'excess', $invoice->amount_minor + 1000));
        $refunds = app(\App\Services\Billing\Bank\BankRefundService::class);
        $refunds->request($this->user, (int) $this->organization->id, $invoice->public_id, 1000, 'overpayment', 'Просим вернуть сумму переплаты', '77777777-7777-4777-8777-777777777777');
        $refund = \App\Models\CommercialBankRefund::query()->sole();
        $operator = new \App\Models\SystemAdmin(['role' => 'super_admin', 'is_active' => true]);
        $operator->id = 100;
        $refunds->decide($operator, (int) $refund->id, true);
        $transaction = $this->bankTransaction($invoice, 'excess-refund', 1000);
        $transaction['creditDebitIndicator'] = 'Debit';
        $transaction['description'] = $refund->reference;
        $transaction['DebtorAccount']['identification'] = config('bank_billing.seller_account');
        $transaction['CreditorAccount']['identification'] = $refund->operation->payer_account;
        $settlement->ingest($transaction);
        $settlement->ingest($transaction);
        $this->assertSame('completed', $refund->refresh()->status);
        $this->assertSame($invoice->amount_minor, $invoice->refresh()->paid_minor);
        $this->assertSame(0, $invoice->refunded_minor);
        $this->assertSame(0, app(\App\Services\Billing\Bank\BankInvoiceService::class)->payload($invoice)['overpayment_minor']);
        $this->assertSame('active', OrganizationCommercialAccount::query()->sole()->status->value);
    }

    public function test_bank_refunding_all_partial_funds_cancels_the_unactivated_order(): void
    {
        $this->prepareBankBilling();
        $this->checkout(['machinery']);
        $invoice = \App\Models\CommercialBankInvoice::query()->sole();
        $settlement = app(\App\Services\Billing\Bank\BankSettlementService::class);
        $settlement->ingest($this->bankTransaction($invoice, 'partial-refund-source', 1000));
        $refunds = app(\App\Services\Billing\Bank\BankRefundService::class);
        $refunds->request($this->user, (int) $this->organization->id, $invoice->public_id, 1000, 'payment', 'Отказ от покупки после частичной оплаты', '66666666-6666-4666-8666-666666666666');
        $refund = \App\Models\CommercialBankRefund::query()->sole();
        $operator = new \App\Models\SystemAdmin(['role' => 'super_admin', 'is_active' => true]);
        $operator->id = 100;
        $refunds->decide($operator, (int) $refund->id, true);
        $transaction = $this->bankTransaction($invoice, 'partial-refund-outgoing', 1000);
        $transaction['creditDebitIndicator'] = 'Debit';
        $transaction['description'] = $refund->reference;
        $transaction['DebtorAccount']['identification'] = config('bank_billing.seller_account');
        $transaction['CreditorAccount']['identification'] = $refund->operation->payer_account;
        $settlement->ingest($transaction);
        $this->assertSame('canceled', $invoice->refresh()->status);
        $this->assertSame('canceled', $invoice->order->status->value);
        $this->assertNull($invoice->activated_at);
        $this->assertDatabaseCount('organization_package_subscriptions', 0);
    }

    private function prepareLegacyYooKassaPurchase(string $status, bool $sandbox = false): CommercialOrder
    {
        $this->prepareBankBilling();
        $this->checkout(['machinery']);
        $initial = \App\Models\CommercialBankInvoice::query()->sole();
        app(\App\Services\Billing\Bank\BankSettlementService::class)->ingest($this->bankTransaction($initial, 'before-legacy-purchase', $initial->amount_minor));
        config(['bank_billing.default_provider' => 'yookassa', 'services.yookassa.payments_enabled' => true]);
        try {
            $result = $this->checkoutPayload(['target_package_slugs' => ['machinery', 'finance-contracts'],
                'current_package_slugs' => ['working-entry', 'machinery']]);
        } finally {
            config(['bank_billing.default_provider' => 'tochka', 'services.yookassa.payments_enabled' => false]);
        }
        $legacy = CommercialOrder::query()->where('public_id', $result['order_id'])->sole();
        if ($status === 'canceled') {
            $legacy->latestPayment->forceFill(['provider_status' => 'canceled', 'terminal_at' => now()])->save();
        }
        if ($sandbox) {
            $legacy->latestPayment->forceFill(['reconciliation_required' => true,
                'safe_response' => ['status' => $status, 'test' => true, 'paid' => false]])->save();
        }

        return $legacy;
    }

    private function prepareBankBilling(): void
    {
        config(['bank_billing.default_provider' => 'tochka', 'services.yookassa.payments_enabled' => false,
            'ai-assistant-credits.enforce' => false, 'bank_billing.seller_account' => '40802810000000000001',
            'bank_billing.seller_bik' => '044525104', 'bank_billing.seller_bank' => 'Тестовый банк',
            'bank_billing.seller_correspondent_account' => '30101810000000000001',
            'bank_billing.account_id' => '40802810000000000001/044525104',
            'legal.provider' => ['name' => 'ИП Тестовый', 'inn' => '500100732259', 'registration_number' => '326500100000001', 'address' => 'Москва']]);
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('legal_name')->nullable();
            $table->string('tax_number')->nullable();
            $table->string('registration_number')->nullable();
            $table->string('address')->nullable();
        });
        Schema::table('commercial_orders', fn (Blueprint $table) => $table->string('server_idempotency_key', 100)->nullable());
        DB::statement("ALTER TABLE commercial_payments ADD CONSTRAINT commercial_payment_role_cycle_check CHECK ((role = 'initial' AND commercial_renewal_cycle_id IS NULL) OR (role = 'renewal' AND commercial_renewal_cycle_id IS NOT NULL))");
        Schema::create('commercial_billing_notification_keys', function (Blueprint $table): void {
            $table->string('idempotency_key')->primary();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('commercial_account_id');
            $table->timestamps();
        });
        (require database_path('migrations/2026_10_15_230000_create_commercial_bank_billing.php'))->up();
        $this->organization->forceFill(['legal_name' => 'ООО Покупатель', 'tax_number' => '7707083893', 'registration_number' => '1027700132195', 'address' => 'Москва'])->save();
        $this->user->forceFill(['current_organization_id' => $this->organization->id])->save();
        DB::table('organization_user')->insert(['organization_id' => $this->organization->id, 'user_id' => $this->user->id, 'is_active' => true]);
        $authorization = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $authorization->shouldReceive('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $authorization);
        $client = \Mockery::mock(\App\Services\Billing\Bank\TochkaClient::class);
        $client->shouldReceive('ready')->andReturn(true);
        $this->app->instance(\App\Services\Billing\Bank\TochkaClient::class, $client);
    }

    private function bankTransaction(\App\Models\CommercialBankInvoice $invoice, string $id, int $amount): array
    {
        return ['transactionId' => $id, 'paymentId' => $id, 'status' => 'Booked', 'creditDebitIndicator' => 'Credit', 'transactionTypeCode' => 'Платежное поручение',
            'documentProcessDate' => now('Europe/Moscow')->toDateString(), 'description' => $invoice->snapshot['purpose'],
            'Amount' => ['amount' => \App\Services\Billing\Bank\BankMoney::decimal($amount), 'currency' => 'RUB'],
            'DebtorParty' => ['inn' => $this->organization->tax_number], 'DebtorAccount' => ['identification' => '40702810000000000002'],
            'CreditorParty' => ['inn' => config('legal.provider.inn')], 'CreditorAccount' => ['identification' => config('bank_billing.seller_account')]];
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
                'quote_version' => 4,
                'client_idempotency_key' => fake()->uuid(),
                'auto_renew_consent' => true,
                'use_balance' => false,
            ], $override),
        );
    }

    private function createSchema(): void
    {
        foreach ([
            'commercial_bank_documents', 'commercial_bank_refunds', 'commercial_bank_webhook_events', 'commercial_bank_statements',
            'commercial_bank_allocations', 'commercial_bank_operations', 'commercial_bank_invoices',
            'commercial_contour_changes', 'commercial_billing_notification_keys',
            'ai_rag_expected_sources', 'ai_rag_chunks', 'ai_rag_sources', 'ai_rag_index_runs',
            'ai_credit_provider_usages', 'ai_credit_ledger_entries', 'ai_credit_reservation_allocations', 'ai_credit_reservations', 'ai_credit_quotes', 'ai_credit_lots', 'ai_credit_wallets', 'organization_user',
            'notifications', 'commercial_webhook_events', 'commercial_payments', 'commercial_orders', 'organization_resource_allocations', 'organization_package_subscriptions',
            'balance_transactions', 'organization_balances', 'organization_commercial_accounts', 'modules', 'users', 'organizations',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('modules', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(true);
            $table->boolean('can_deactivate')->default(true);
            $table->boolean('is_system_module')->default(false);
            $table->integer('display_order')->default(0);
        });
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
            $table->unsignedBigInteger('current_organization_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('organization_commercial_accounts', function (Blueprint $table): void {
            $table->unique(['id', 'organization_id']);
            $table->string('billing_period', 20)->default('30_days');
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
        Schema::create('organization_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->unique();
            $table->bigInteger('balance')->default(0);
            $table->string('currency', 3)->default('RUB');
            $table->timestamps();
        });
        Schema::create('balance_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_balance_id');
            $table->string('type');
            $table->bigInteger('amount');
            $table->bigInteger('balance_before');
            $table->bigInteger('balance_after');
            $table->text('description')->nullable();
            $table->json('meta')->nullable();
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
        Schema::create('organization_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('user_id');
            $table->boolean('is_owner')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('project_access_mode')->default(\App\Enums\UserProjectAccessMode::ALL_PROJECTS->value);
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'organization_id']);
        });
        $this->createCommercialCheckoutTables();
        (require database_path('migrations/2026_09_29_000006_create_ai_credit_tables.php'))->up();
        AssistantRagTestSchema::create();
    }

    private function createCommercialCheckoutTables(): void
    {
        Schema::create('commercial_orders', function (Blueprint $table): void {
            $table->unique(['id', 'organization_id']);
            $table->string('billing_period', 20)->default('30_days');
            $table->boolean('renew_subscription')->default(false);
            $table->jsonb('paid_period_snapshot')->nullable();
            $table->id();
            $table->uuid('public_id')->unique();
            $table->jsonb('assistant_revenue_allocation')->nullable();
            $table->foreignId('organization_id');
            $table->foreignId('commercial_account_id')->nullable();
            $table->foreignId('user_id');
            $table->string('kind')->default('purchase');
            $table->string('status');
            $table->string('offer_type');
            $table->unsignedInteger('quote_version');
            $table->json('selected_package_slugs');
            $table->json('current_package_slugs');
            $table->json('selected_resource_addons')->nullable();
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
        Schema::create('organization_resource_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('commercial_account_id')->nullable();
            $table->string('resource_slug', 100);
            $table->string('limit_key', 100);
            $table->decimal('quantity', 14, 2)->nullable();
            $table->string('source', 50);
            $table->string('status', 50);
            $table->timestamp('period_start_at')->nullable();
            $table->timestamp('period_end_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
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
            $table->boolean('reconciliation_required')->default(false);
            $table->timestamp('last_reconciled_at')->nullable();
            $table->timestamp('terminal_at')->nullable();
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
        (require database_path('migrations/2026_07_14_000004_create_commercial_contour_changes_table.php'))->up();
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
