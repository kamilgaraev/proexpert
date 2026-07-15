<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Contracts\Billing\CommercialWebhookProcessor;
use App\DataTransferObjects\Billing\YooKassaWebhookNotification;
use RuntimeException;
use Tests\TestCase;

class YooKassaWebhookControllerTest extends TestCase
{
    private WebhookProcessorFake $processor;

    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.yookassa.webhook_source_cidrs', ['185.71.76.0/27']);
        config()->set('services.yookassa.trusted_proxy_cidrs', []);
        $this->processor = new WebhookProcessorFake;
        $this->app->instance(CommercialWebhookProcessor::class, $this->processor);
    }

    public function test_public_route_accepts_valid_notification_without_authentication(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '185.71.76.4'])
            ->postJson('/api/v1/webhooks/yookassa', [
                'type' => 'notification',
                'event' => 'payment_method.active',
                'object' => ['id' => 'method-id', 'type' => 'bank_card'],
            ]);

        $response->assertOk()->assertJsonPath('data.result', 'no_op');
        $this->assertSame(1, $this->processor->calls);
    }

    public function test_forbidden_or_spoofed_source_returns_403_without_processing(): void
    {
        $payload = ['type' => 'notification', 'event' => 'payment_method.active', 'object' => ['id' => 'm', 'type' => 'bank_card']];

        $this->withServerVariables([
            'REMOTE_ADDR' => '203.0.113.7',
            'HTTP_X_FORWARDED_FOR' => '185.71.76.4',
        ])->postJson('/api/v1/webhooks/yookassa', $payload)->assertForbidden();

        $this->assertSame(0, $this->processor->calls);
    }

    public function test_invalid_payload_returns_non_200_without_processing(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '185.71.76.4'])
            ->postJson('/api/v1/webhooks/yookassa', ['type' => 'notification', 'event' => 'unknown'])
            ->assertStatus(422);

        $this->assertSame(0, $this->processor->calls);
    }

    public function test_malformed_json_returns_non_200_without_processing(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '185.71.76.4'])
            ->call('POST', '/api/v1/webhooks/yookassa', server: [
                'CONTENT_TYPE' => 'application/json',
                'REMOTE_ADDR' => '185.71.76.4',
            ], content: '{invalid')
            ->assertStatus(422);

        $this->assertSame(0, $this->processor->calls);
    }

    public function test_processing_failure_returns_non_200_for_provider_retry(): void
    {
        $this->processor->fail = true;

        $this->withServerVariables(['REMOTE_ADDR' => '185.71.76.4'])
            ->postJson('/api/v1/webhooks/yookassa', [
                'type' => 'notification',
                'event' => 'payment.succeeded',
                'object' => ['id' => 'payment-id', 'status' => 'succeeded'],
            ])->assertStatus(503);
    }
}

class WebhookProcessorFake implements CommercialWebhookProcessor
{
    public int $calls = 0;

    public bool $fail = false;

    public function process(YooKassaWebhookNotification $notification, string $sourceIp): string
    {
        $this->calls++;

        if ($this->fail) {
            throw new RuntimeException('provider unavailable');
        }

        return 'no_op';
    }

    public function processAuthoritativePayment(YooKassaWebhookNotification $notification, string $sourceIp, \App\DataTransferObjects\Billing\PaymentGatewayResult $payment): string
    {
        return $this->process($notification, $sourceIp);
    }

    public function processAuthoritativeRefund(YooKassaWebhookNotification $notification, string $sourceIp, \App\DataTransferObjects\Billing\RefundGatewayResult $refund, \App\DataTransferObjects\Billing\PaymentGatewayResult $payment): string
    {
        return $this->process($notification, $sourceIp);
    }
}
