<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\DataTransferObjects\Billing\CreatePaymentData;
use App\DataTransferObjects\Billing\CreateRefundData;
use App\DataTransferObjects\Billing\CreateSavedMethodPaymentData;
use App\DataTransferObjects\Billing\PaymentGatewayResult;
use App\DataTransferObjects\Billing\RefundGatewayResult;
use App\Exceptions\Billing\PaymentGatewayConfigurationException;
use App\Interfaces\Billing\PaymentGatewayInterface;
use App\Models\CommercialOrder;
use App\Models\CommercialPayment;
use App\Models\CommercialRefund;
use App\Models\OrganizationBalance;
use App\Services\Billing\CommercialRefundService;
use DomainException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\IsolatedPostgresTestDatabase;
use Tests\Support\AssistantRagTestSchema;
use Tests\TestCase;

final class CommercialRefundServiceTest extends TestCase
{
    private RefundGatewayFake $gateway;
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
        config()->set('services.yookassa.mode', 'yookassa_test');
        config()->set('services.yookassa.test_organization_ids', [42]);
        $this->createSchema();
        Queue::fake([\App\BusinessModules\Features\AIAssistant\Jobs\IndexRagSourceJob::class]);
        $this->gateway = new RefundGatewayFake;
        $this->app->instance(PaymentGatewayInterface::class, $this->gateway);
    }

    protected function tearDown(): void
    {
        if ($this->connectionName !== null && $this->originalConnectionConfiguration !== null) {
            DB::purge($this->connectionName);
            config()->set('database.connections.'.$this->connectionName, $this->originalConnectionConfiguration);
            DB::connection($this->connectionName);
        }
        parent::tearDown();
    }

    public function test_balance_pack_refunds_revoke_units_proportionally_once(): void
    {
        [$order, $payment] = $this->paidOrder('balance');
        $order->forceFill(['kind' => 'ai_credits', 'amount_minor' => 100000, 'amount' => '1000.00', 'selected_resource_addons' => [
            ['slug' => 'ai-credits-1000', 'units_minor' => 100000, 'amount_minor' => 100000],
        ]])->save();
        $payment->forceFill(['amount_minor' => 100000])->save();
        OrganizationBalance::query()->create(['organization_id' => 42, 'balance' => 0, 'currency' => 'RUB']);
        app(\App\Services\Credits\AICreditCommercialService::class)->settlePaidOrder($order);
        $service = app(CommercialRefundService::class);

        $first = $service->create($order->public_id, 30000, 'RUB', 'Частичный возврат пакета', 'pack-refund-partial');
        $again = $service->create($order->public_id, 30000, 'RUB', 'Частичный возврат пакета', 'pack-refund-partial');
        $this->assertSame($first->id, $again->id);
        $this->assertDatabaseHas('ai_credit_lots', ['source' => 'purchase', 'remaining_minor' => 70000]);
        $this->assertSame(30000, OrganizationBalance::query()->sole()->balance);
        $this->assertDatabaseCount('ai_credit_ledger_entries', 2);

        $service->create($order->public_id, null, 'RUB', 'Остаток возврата пакета', 'pack-refund-remainder');
        $this->assertDatabaseHas('ai_credit_lots', ['source' => 'purchase', 'remaining_minor' => 0]);
        $this->assertDatabaseHas('ai_credit_wallets', ['organization_id' => 42, 'balance_minor' => 0]);
        $this->assertSame(100000, OrganizationBalance::query()->sole()->balance);
        $this->assertDatabaseCount('ai_credit_ledger_entries', 3);
        $this->assertSame('refunded', $order->fresh()->status->value);
    }

    public function test_partial_refund_is_idempotent_and_does_not_change_entitlement_or_order(): void
    {
        [$order] = $this->paidOrder();
        $service = app(CommercialRefundService::class);

        $first = $service->create($order->public_id, 3000, 'RUB', 'Согласовано поддержкой', 'refund-key-1');
        $second = $service->create($order->public_id, 3000, 'RUB', 'Согласовано поддержкой', 'refund-key-1');

        $this->assertSame($first->id, $second->id);
        $this->assertSame('pending', $first->provider_status);
        $this->assertSame(1, $this->gateway->creates);
        $this->assertSame('paid', $order->fresh()->status->value);
        $this->assertDatabaseHas('organization_package_subscriptions', ['source_order_id' => $order->id, 'status' => 'active']);
        $this->assertDatabaseCount('commercial_refunds', 1);
    }

    public function test_balance_refund_returns_money_once_and_closes_access_after_full_refund(): void
    {
        [$order] = $this->paidOrder('balance');
        OrganizationBalance::query()->create([
            'organization_id' => 42,
            'balance' => 0,
            'currency' => 'RUB',
        ]);
        $service = app(CommercialRefundService::class);

        $first = $service->create($order->public_id, null, 'RUB', 'Возврат на баланс', 'balance-refund-key');
        $second = $service->create($order->public_id, null, 'RUB', 'Возврат на баланс', 'balance-refund-key');

        $this->assertSame($first->id, $second->id);
        $this->assertSame('succeeded', $first->provider_status);
        $this->assertSame(10000, OrganizationBalance::query()->sole()->balance);
        $this->assertDatabaseCount('balance_transactions', 1);
        $this->assertDatabaseHas('balance_transactions', ['type' => 'credit', 'amount' => 10000]);
        $this->assertSame('refunded', $order->fresh()->status->value);
        $this->assertDatabaseHas('organization_package_subscriptions', ['source_order_id' => $order->id, 'status' => 'expired']);
        $this->assertDatabaseHas('notifications', ['organization_id' => 42, 'notification_type' => 'billing']);
        $this->assertSame(0, $this->gateway->creates);
    }

    public function test_full_refund_uses_remaining_amount_and_rejects_over_refund_or_currency_mismatch(): void
    {
        [$order, $payment] = $this->paidOrder();
        CommercialRefund::query()->create([
            'commercial_order_id' => $order->id,
            'commercial_payment_id' => $payment->id,
            'provider' => 'yookassa',
            'provider_refund_id' => 'existing-refund',
            'provider_idempotency_key' => 'existing-key',
            'request_fingerprint' => hash('sha256', 'existing'),
            'provider_status' => 'succeeded',
            'amount_minor' => 2500,
            'currency' => 'RUB',
        ]);
        $service = app(CommercialRefundService::class);

        $refund = $service->create($order->public_id, null, 'RUB', 'Полный возврат остатка', 'refund-key-full');
        $this->assertSame(7500, $refund->amount_minor);

        foreach ([[1, 'RUB'], [100, 'USD']] as [$amount, $currency]) {
            try {
                $service->create($order->public_id, $amount, $currency, 'Недопустимый возврат', 'invalid-'.$currency);
                $this->fail('Invalid refund must be rejected.');
            } catch (DomainException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_provider_policy_denial_happens_before_refund_intent_or_provider_call(): void
    {
        [$order] = $this->paidOrder();
        $service = app(CommercialRefundService::class);

        foreach ([
            ['mock', [42]],
            ['yookassa_test', [41]],
            ['yookassa_live', [42]],
        ] as [$mode, $allowlist]) {
            config()->set('services.yookassa.mode', $mode);
            config()->set('services.yookassa.test_organization_ids', $allowlist);

            try {
                $service->create($order->public_id, 1000, 'RUB', 'Проверка политики', 'denied-'.$mode.'-'.count($allowlist));
                $this->fail('Provider policy must reject refund creation.');
            } catch (PaymentGatewayConfigurationException) {
                $this->assertDatabaseCount('commercial_refunds', 0);
                $this->assertSame(0, $this->gateway->creates);
            }
        }
    }

    public function test_mismatching_provider_response_is_left_for_manual_reconciliation(): void
    {
        [$order] = $this->paidOrder();
        $this->gateway->resultOverrides = ['amountMinor' => 999];

        $this->expectException(DomainException::class);

        try {
            app(CommercialRefundService::class)->create(
                $order->public_id,
                1000,
                'RUB',
                'Проверка ответа',
                'refund-mismatch',
            );
        } finally {
            $this->assertDatabaseHas('commercial_refunds', [
                'provider_status' => 'unknown',
                'reconciliation_required' => true,
            ]);
        }
    }

    public function test_confirmed_command_keeps_database_clean_when_provider_policy_denies_operation(): void
    {
        [$order] = $this->paidOrder();

        foreach ([
            ['mock', [42]],
            ['yookassa_test', [41]],
            ['yookassa_live', [42]],
        ] as $index => [$mode, $allowlist]) {
            config()->set('services.yookassa.mode', $mode);
            config()->set('services.yookassa.test_organization_ids', $allowlist);

            $this->artisan('commercial:refund', [
                'order' => $order->public_id,
                'amount' => '10.00',
                'reason' => 'Проверка команды',
                'idempotency-key' => 'command-denied-'.$index,
                '--confirm' => true,
            ])->assertExitCode(1);
            $this->assertDatabaseCount('commercial_refunds', 0);
            $this->assertSame(0, $this->gateway->creates);
        }
    }

    private function paidOrder(string $provider = 'yookassa'): array
    {
        $order = CommercialOrder::query()->create([
            'public_id' => '11111111-1111-4111-8111-111111111111',
            'organization_id' => 42,
            'commercial_account_id' => 5,
            'user_id' => 7,
            'kind' => 'purchase',
            'status' => 'paid',
            'offer_type' => 'packages',
            'quote_version' => 1,
            'selected_package_slugs' => ['machinery'],
            'current_package_slugs' => [],
            'amount_minor' => 10000,
            'amount' => '100.00',
            'currency' => 'RUB',
            'period_start_at' => now(),
            'period_end_at' => now()->addMonth(),
            'auto_renew_consent' => false,
            'client_idempotency_key' => 'checkout-key',
        ]);
        $payment = CommercialPayment::query()->create([
            'commercial_order_id' => $order->id,
            'role' => 'initial',
            'attempt_number' => 1,
            'provider' => $provider,
            'provider_payment_id' => $provider === 'yookassa' ? 'provider-payment-id' : null,
            'provider_status' => 'succeeded',
            'amount_minor' => 10000,
            'currency' => 'RUB',
            'provider_idempotency_key' => 'payment-key',
            'payment_method_saved' => false,
        ]);
        \DB::table('organization_package_subscriptions')->insert([
            'source_order_id' => $order->id,
            'organization_id' => 42,
            'status' => 'active',
        ]);

        return [$order, $payment];
    }

    private function createSchema(): void
    {
        foreach (['activity_events', 'ai_rag_expected_sources', 'ai_rag_chunks', 'ai_rag_sources', 'ai_rag_index_runs', 'ai_credit_provider_usages', 'ai_credit_ledger_entries', 'ai_credit_reservation_allocations', 'ai_credit_reservations', 'ai_credit_quotes', 'ai_credit_lots', 'ai_credit_wallets', 'users', 'notifications', 'commercial_refunds', 'commercial_payments', 'commercial_orders', 'organization_package_subscriptions', 'balance_transactions', 'organization_balances', 'organizations'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('users', function (Blueprint $table): void { $table->id(); $table->softDeletes(); });
        Schema::create('organizations', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->boolean('is_active')->default(true);
            $t->boolean('is_verified')->default(false);
            $t->timestamps();
            $t->softDeletes();
        });
        \DB::table('organizations')->insert([
            'id' => 42,
            'name' => 'Refund organization',
            'is_active' => true,
            'is_verified' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Schema::create('organization_balances', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organization_id')->unique();
            $t->bigInteger('balance')->default(0);
            $t->string('currency', 3)->default('RUB');
            $t->timestamps();
        });
        Schema::create('balance_transactions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organization_balance_id');
            $t->string('type');
            $t->bigInteger('amount');
            $t->bigInteger('balance_before');
            $t->bigInteger('balance_after');
            $t->text('description')->nullable();
            $t->json('meta')->nullable();
            $t->timestamps();
        });
        Schema::create('commercial_orders', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('commercial_account_id');
            $t->unsignedBigInteger('user_id');
            $t->string('kind');
            $t->string('status');
            $t->string('offer_type');
            $t->unsignedInteger('quote_version');
            $t->json('selected_package_slugs');
            $t->json('current_package_slugs');
            $t->json('selected_resource_addons')->nullable();
            $t->unsignedBigInteger('amount_minor');
            $t->decimal('amount', 14, 2);
            $t->char('currency', 3);
            $t->timestamp('period_start_at');
            $t->timestamp('period_end_at');
            $t->boolean('auto_renew_consent');
            $t->string('client_idempotency_key');
            $t->string('server_idempotency_key')->nullable();
            $t->timestamps();
        });
        Schema::create('commercial_payments', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('commercial_order_id');
            $t->string('role');
            $t->unsignedSmallInteger('attempt_number');
            $t->string('provider');
            $t->string('provider_payment_id')->nullable();
            $t->string('provider_status');
            $t->unsignedBigInteger('amount_minor');
            $t->char('currency', 3);
            $t->string('provider_idempotency_key');
            $t->boolean('payment_method_saved');
            $t->unsignedBigInteger('refunded_amount_minor')->default(0);
            $t->timestamps();
        });
        Schema::create('commercial_refunds', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('commercial_order_id');
            $t->unsignedBigInteger('commercial_payment_id');
            $t->string('provider');
            $t->string('provider_refund_id')->nullable()->unique();
            $t->string('provider_idempotency_key')->unique();
            $t->char('request_fingerprint', 64);
            $t->string('provider_status');
            $t->unsignedBigInteger('amount_minor');
            $t->char('currency', 3);
            $t->json('safe_response')->nullable();
            $t->boolean('reconciliation_required')->default(true);
            $t->timestamp('last_reconciled_at')->nullable();
            $t->timestamps();
        });
        Schema::create('organization_package_subscriptions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('source_order_id');
            $t->unsignedBigInteger('organization_id');
            $t->string('status');
            $t->timestamp('current_period_end_at')->nullable();
            $t->timestamp('cancel_at')->nullable();
            $t->timestamp('canceled_at')->nullable();
            $t->timestamps();
        });
        Schema::create('notifications', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('type');
            $t->string('notifiable_type');
            $t->unsignedBigInteger('notifiable_id');
            $t->unsignedBigInteger('organization_id')->nullable();
            $t->string('notification_type');
            $t->string('priority');
            $t->json('channels');
            $t->json('delivery_status');
            $t->json('data');
            $t->json('metadata')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });
        (require database_path('migrations/2026_09_29_000006_create_ai_credit_tables.php'))->up();
        AssistantRagTestSchema::create();
        (require database_path('migrations/2026_05_08_000001_create_activity_events_table.php'))->up();
    }
}

final class RefundGatewayFake implements PaymentGatewayInterface
{
    public int $creates = 0;

    public array $resultOverrides = [];

    public function createRefund(CreateRefundData $refund): RefundGatewayResult
    {
        $this->creates++;

        return new RefundGatewayResult(
            $this->resultOverrides['id'] ?? 'refund-'.$this->creates,
            $this->resultOverrides['paymentId'] ?? $refund->paymentId,
            $this->resultOverrides['status'] ?? 'pending',
            $this->resultOverrides['amountMinor'] ?? $refund->amountMinor,
            $this->resultOverrides['currency'] ?? $refund->currency,
            ['id' => 'refund-'.$this->creates, 'status' => 'pending'],
            $refund->metadata,
        );
    }

    public function createPayment(CreatePaymentData $payment): PaymentGatewayResult
    {
        throw new RuntimeException('Not used.');
    }

    public function createSavedMethodPayment(CreateSavedMethodPaymentData $payment): PaymentGatewayResult
    {
        throw new RuntimeException('Not used.');
    }

    public function getPayment(string $paymentId): PaymentGatewayResult
    {
        throw new RuntimeException('Not used.');
    }

    public function getRefund(string $refundId): RefundGatewayResult
    {
        throw new RuntimeException('Not used.');
    }
}
