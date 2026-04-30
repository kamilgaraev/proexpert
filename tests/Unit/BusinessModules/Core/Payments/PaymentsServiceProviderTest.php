<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\Core\Payments;

use PHPUnit\Framework\TestCase;

class PaymentsServiceProviderTest extends TestCase
{
    public function test_provider_bootstrap_does_not_write_to_default_log_channel(): void
    {
        $provider = file_get_contents(
            dirname(__DIR__, 5) . '/app/BusinessModules/Core/Payments/PaymentsServiceProvider.php'
        );

        $this->assertIsString($provider);
        $this->assertStringNotContainsString('PaymentsServiceProvider booted', $provider);
        $this->assertStringNotContainsString('Log::info', $provider);
    }
}
