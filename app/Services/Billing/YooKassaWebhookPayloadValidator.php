<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DataTransferObjects\Billing\YooKassaWebhookNotification;
use InvalidArgumentException;

final class YooKassaWebhookPayloadValidator
{
    private const EVENTS = [
        'payment.succeeded',
        'payment.waiting_for_capture',
        'payment.canceled',
        'payment_method.active',
        'refund.succeeded',
    ];

    public function validate(array $payload): YooKassaWebhookNotification
    {
        $event = trim((string) ($payload['event'] ?? ''));
        $object = is_array($payload['object'] ?? null) ? $payload['object'] : [];
        $objectId = trim((string) ($object['id'] ?? ''));

        if (($payload['type'] ?? null) !== 'notification'
            || ! in_array($event, self::EVENTS, true)
            || $objectId === '') {
            throw new InvalidArgumentException('Invalid YooKassa notification.');
        }

        $stateKey = $event === 'payment_method.active' ? 'type' : 'status';
        $objectState = trim((string) ($object[$stateKey] ?? ''));

        if ($objectState === '') {
            throw new InvalidArgumentException('Invalid YooKassa notification object.');
        }

        return new YooKassaWebhookNotification(
            event: $event,
            objectId: $objectId,
            objectState: $objectState,
            safePayload: [
                'type' => 'notification',
                'event' => $event,
                'object' => [
                    'id' => $objectId,
                    $stateKey => $objectState,
                ],
            ],
        );
    }
}
