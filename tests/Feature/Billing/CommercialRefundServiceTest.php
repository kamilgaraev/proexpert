<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\DataTransferObjects\Billing\CreatePaymentData;
use App\DataTransferObjects\Billing\CreateRefundData;
use App\DataTransferObjects\Billing\CreateSavedMethodPaymentData;
use App\DataTransferObjects\Billing\PaymentGatewayResult;
use App\DataTransferObjects\Billing\RefundGatewayResult;
use App\Interfaces\Billing\PaymentGatewayInterface;
use App\Models\CommercialOrder;
use App\Models\CommercialPayment;
use App\Models\CommercialRefund;
use App\Services\Billing\CommercialRefundService;
use DomainException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class CommercialRefundServiceTest extends TestCase
{
    private RefundGatewayFake $gateway;

    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.yookassa.mode', 'yookassa_test');
        config()->set('services.yookassa.test_organization_ids', [42]);
        $this->createSchema();
        $this->gateway = new RefundGatewayFake;
        $this->app->instance(PaymentGatewayInterface::class, $this->gateway);
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

    private function paidOrder(): array
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
            'provider' => 'yookassa',
            'provider_payment_id' => 'provider-payment-id',
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
        foreach (['commercial_refunds', 'commercial_payments', 'commercial_orders', 'organization_package_subscriptions'] as $table) {
            Schema::dropIfExists($table);
        }
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
        });
    }
}

final class RefundGatewayFake implements PaymentGatewayInterface
{
    public int $creates = 0;

    public function createRefund(CreateRefundData $refund): RefundGatewayResult
    {
        $this->creates++;

        return new RefundGatewayResult('refund-'.$this->creates, $refund->paymentId, 'pending', $refund->amountMinor, $refund->currency, ['id' => 'refund-'.$this->creates, 'status' => 'pending']);
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
