<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\BusinessModules\Features\Notifications\Services\NotificationPayloadNormalizer;
use PHPUnit\Framework\TestCase;

final class NotificationPayloadNormalizerTest extends TestCase
{
    public function test_normalization_does_not_invent_a_delivery_interface(): void
    {
        $payload = (new NotificationPayloadNormalizer)->normalize('system.notice', [], 'system');

        self::assertArrayNotHasKey('interface', $payload);
    }

    public function test_normalization_preserves_a_legacy_interface(): void
    {
        $payload = (new NotificationPayloadNormalizer)->normalize(
            'system.notice',
            ['interface' => ' lk '],
            'system'
        );

        self::assertSame('lk', $payload['interface']);
    }
}
