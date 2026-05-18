<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use PHPUnit\Framework\TestCase;

class WebSocketChannelLoggingTest extends TestCase
{
    public function test_websocket_delivery_failures_are_not_logged_as_errors(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3).'/app/BusinessModules/Features/Notifications/Channels/WebSocketChannel.php'
        );

        self::assertIsString($source);
        self::assertStringNotContainsString("Log::error('WebSocket notification failed'", $source);
        self::assertStringNotContainsString("Log::error('[WebSocket] Reverb HTTP failed'", $source);
    }
}
