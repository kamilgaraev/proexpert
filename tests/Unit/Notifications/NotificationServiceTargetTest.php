<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use PHPUnit\Framework\TestCase;

final class NotificationServiceTargetTest extends TestCase
{
    public function test_send_contract_persists_targets_transactionally_before_dispatch(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3).'/app/BusinessModules/Features/Notifications/Services/NotificationService.php'
        );

        self::assertIsString($source);
        self::assertStringContainsString('string|array|null $interfaces = null', $source);
        self::assertStringContainsString('new NotificationDeliveryOptions(', $source);
        self::assertStringContainsString('DB::transaction(', $source);
        self::assertStringContainsString("'required_permissions' => \$options->requiredPermissions", $source);
        self::assertStringContainsString('$notification->targets()->createMany(', $source);

        self::assertStringContainsString(
            ");\n\n        \$this->dispatch(\$notification);",
            str_replace("\r\n", "\n", $source)
        );
    }
}
