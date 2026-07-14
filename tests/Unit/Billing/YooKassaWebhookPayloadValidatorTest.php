<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Services\Billing\YooKassaWebhookPayloadValidator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class YooKassaWebhookPayloadValidatorTest extends TestCase
{
    public function test_accepts_supported_payment_refund_and_payment_method_events(): void
    {
        $validator = new YooKassaWebhookPayloadValidator;

        $payment = $validator->validate([
            'type' => 'notification',
            'event' => 'payment.succeeded',
            'object' => ['id' => 'payment-id', 'status' => 'succeeded', 'card' => ['number' => 'secret']],
        ]);
        $refund = $validator->validate([
            'type' => 'notification',
            'event' => 'refund.succeeded',
            'object' => ['id' => 'refund-id', 'status' => 'succeeded'],
        ]);
        $method = $validator->validate([
            'type' => 'notification',
            'event' => 'payment_method.active',
            'object' => ['id' => 'method-id', 'type' => 'bank_card'],
        ]);

        $this->assertSame('payment-id', $payment->objectId);
        $this->assertSame('refund-id', $refund->objectId);
        $this->assertSame('bank_card', $method->objectState);
        $this->assertArrayNotHasKey('card', $payment->safePayload['object']);
    }

    #[DataProvider('invalidPayloads')]
    public function test_rejects_invalid_or_unsupported_payload(array $payload): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new YooKassaWebhookPayloadValidator)->validate($payload);
    }

    public static function invalidPayloads(): array
    {
        return [
            'wrong type' => [['type' => 'event', 'event' => 'payment.succeeded', 'object' => ['id' => 'p', 'status' => 'succeeded']]],
            'unknown event' => [['type' => 'notification', 'event' => 'payment.pending', 'object' => ['id' => 'p', 'status' => 'pending']]],
            'missing object id' => [['type' => 'notification', 'event' => 'payment.canceled', 'object' => ['status' => 'canceled']]],
            'missing payment status' => [['type' => 'notification', 'event' => 'payment.succeeded', 'object' => ['id' => 'p']]],
            'missing method type' => [['type' => 'notification', 'event' => 'payment_method.active', 'object' => ['id' => 'm']]],
        ];
    }
}
