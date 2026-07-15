<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Contracts\Billing\CommercialWebhookProcessor;
use App\DataTransferObjects\Billing\CreatePaymentData;
use App\DataTransferObjects\Billing\CreateRefundData;
use App\DataTransferObjects\Billing\CreateSavedMethodPaymentData;
use App\DataTransferObjects\Billing\PaymentGatewayResult;
use App\DataTransferObjects\Billing\RefundGatewayResult;
use App\DataTransferObjects\Billing\YooKassaWebhookNotification;
use App\Interfaces\Billing\PaymentGatewayInterface;
use App\Models\CommercialPayment;
use App\Models\CommercialRefund;
use App\Services\Billing\CommercialReconciliationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class CommercialReconciliationServiceTest extends TestCase
{
    private ReconciliationGatewayFake $gateway;

    private ReconciliationProcessorFake $processor;

    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
        $this->gateway = new ReconciliationGatewayFake;
        $this->processor = new ReconciliationProcessorFake;
        $this->app->instance(PaymentGatewayInterface::class, $this->gateway);
        $this->app->instance(CommercialWebhookProcessor::class, $this->processor);
    }

    public function test_reconciles_lost_payment_success_and_cancellation_through_transition_path(): void
    {
        $this->payment('payment-success');
        $this->payment('payment-canceled');
        $this->gateway->payments['payment-success'] = $this->paymentResult('payment-success', 'succeeded', true);
        $this->gateway->payments['payment-canceled'] = $this->paymentResult('payment-canceled', 'canceled', false);

        $result = app(CommercialReconciliationService::class)->run(10);

        $this->assertSame(2, $result['processed']);
        $this->assertSame(['payment.reconciliation', 'payment.reconciliation'], $this->processor->events);
    }

    public function test_reconciles_succeeded_refund_and_closes_canceled_refund_idempotently(): void
    {
        $this->refund('refund-success');
        $this->refund('refund-canceled');
        $this->gateway->refunds['refund-success'] = new RefundGatewayResult('refund-success', 'payment-1', 'succeeded', 1000, 'RUB', ['id' => 'refund-success']);
        $this->gateway->refunds['refund-canceled'] = new RefundGatewayResult('refund-canceled', 'payment-1', 'canceled', 1000, 'RUB', ['id' => 'refund-canceled']);
        $this->gateway->payments['payment-1'] = $this->paymentResult('payment-1', 'succeeded', true);
        $service = app(CommercialReconciliationService::class);

        $first = $service->run(10);
        $second = $service->run(10);

        $this->assertSame(2, $first['processed']);
        $this->assertSame(0, $second['processed']);
        $this->assertContains('refund.reconciliation', $this->processor->events);
        $this->assertDatabaseHas('commercial_refunds', ['provider_refund_id' => 'refund-canceled', 'provider_status' => 'canceled', 'reconciliation_required' => false]);
    }

    public function test_payment_backlog_cannot_starve_refund_candidates(): void
    {
        foreach (range(1, 4) as $number) {
            $id = 'payment-'.$number;
            $this->payment($id);
            $this->gateway->payments[$id] = $this->paymentResult($id, 'pending', false);
        }
        $this->refund('refund-priority');
        $this->gateway->refunds['refund-priority'] = new RefundGatewayResult('refund-priority', 'payment-1', 'canceled', 1000, 'RUB', ['id' => 'refund-priority']);

        $result = app(CommercialReconciliationService::class)->run(2);

        $this->assertSame(1, $result['payments']);
        $this->assertSame(1, $result['refunds']);
        $this->assertContains('refund.reconciliation', $this->processor->events);
    }

    private function payment(string $providerId): void
    {
        CommercialPayment::query()->create([
            'commercial_order_id' => 1, 'provider' => 'yookassa', 'provider_payment_id' => $providerId,
            'provider_status' => 'pending', 'amount_minor' => 1000, 'currency' => 'RUB',
            'provider_idempotency_key' => 'key-'.$providerId, 'payment_method_saved' => false,
            'role' => 'initial', 'attempt_number' => 1, 'reconciliation_required' => false,
        ]);
    }

    private function refund(string $providerId): void
    {
        CommercialRefund::query()->create([
            'commercial_order_id' => 1, 'commercial_payment_id' => 1, 'provider' => 'yookassa',
            'provider_refund_id' => $providerId, 'provider_idempotency_key' => 'key-'.$providerId,
            'request_fingerprint' => hash('sha256', $providerId), 'provider_status' => 'pending',
            'amount_minor' => 1000, 'currency' => 'RUB', 'reconciliation_required' => true,
        ]);
    }

    private function paymentResult(string $id, string $status, bool $paid): PaymentGatewayResult
    {
        return new PaymentGatewayResult($id, $status, null, null, false, ['id' => $id], $paid, true, 1000, 'RUB', ['order_id' => 'order-1', 'organization_id' => 1]);
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('commercial_refunds');
        Schema::dropIfExists('commercial_payments');
        Schema::create('commercial_payments', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('commercial_order_id');
            $t->unsignedBigInteger('commercial_renewal_cycle_id')->nullable();
            $t->string('role');
            $t->unsignedSmallInteger('attempt_number');
            $t->string('provider');
            $t->string('provider_payment_id')->nullable();
            $t->string('provider_status');
            $t->unsignedBigInteger('amount_minor');
            $t->char('currency', 3);
            $t->string('provider_idempotency_key');
            $t->boolean('payment_method_saved');
            $t->json('safe_response')->nullable();
            $t->unsignedBigInteger('refunded_amount_minor')->default(0);
            $t->boolean('reconciliation_required')->default(false);
            $t->timestamp('last_reconciled_at')->nullable();
            $t->timestamps();
        });
        Schema::create('commercial_refunds', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('commercial_order_id');
            $t->unsignedBigInteger('commercial_payment_id');
            $t->string('provider');
            $t->string('provider_refund_id')->nullable();
            $t->string('provider_idempotency_key');
            $t->char('request_fingerprint', 64);
            $t->string('provider_status');
            $t->unsignedBigInteger('amount_minor');
            $t->char('currency', 3);
            $t->json('safe_response')->nullable();
            $t->boolean('reconciliation_required')->default(true);
            $t->timestamp('last_reconciled_at')->nullable();
            $t->timestamps();
        });
    }
}

final class ReconciliationGatewayFake implements PaymentGatewayInterface
{
    public array $payments = [];

    public array $refunds = [];

    public function getPayment(string $paymentId): PaymentGatewayResult
    {
        return $this->payments[$paymentId];
    }

    public function getRefund(string $refundId): RefundGatewayResult
    {
        return $this->refunds[$refundId];
    }

    public function createPayment(CreatePaymentData $payment): PaymentGatewayResult
    {
        throw new RuntimeException('Not used.');
    }

    public function createSavedMethodPayment(CreateSavedMethodPaymentData $payment): PaymentGatewayResult
    {
        throw new RuntimeException('Not used.');
    }

    public function createRefund(CreateRefundData $refund): RefundGatewayResult
    {
        throw new RuntimeException('Not used.');
    }
}

final class ReconciliationProcessorFake implements CommercialWebhookProcessor
{
    public array $events = [];

    public function process(YooKassaWebhookNotification $notification, string $sourceIp): string
    {
        $this->events[] = $notification->event;
        if (str_starts_with($notification->event, 'payment.')) {
            CommercialPayment::query()->where('provider_payment_id', $notification->objectId)->update([
                'provider_status' => $notification->objectState,
                'reconciliation_required' => false,
                'last_reconciled_at' => now(),
            ]);
        } else {
            CommercialRefund::query()->where('provider_refund_id', $notification->objectId)->update([
                'provider_status' => $notification->objectState,
                'reconciliation_required' => false,
                'last_reconciled_at' => now(),
            ]);
        }

        return 'processed';
    }

    public function processAuthoritativePayment(YooKassaWebhookNotification $notification, string $sourceIp, PaymentGatewayResult $payment): string
    {
        return $this->process($notification, $sourceIp);
    }

    public function processAuthoritativeRefund(YooKassaWebhookNotification $notification, string $sourceIp, RefundGatewayResult $refund, PaymentGatewayResult $payment): string
    {
        return $this->process($notification, $sourceIp);
    }
}
