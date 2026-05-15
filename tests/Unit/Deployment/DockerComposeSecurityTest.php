<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use PHPUnit\Framework\TestCase;

final class DockerComposeSecurityTest extends TestCase
{
    public function test_api_octane_port_is_bound_to_host_loopback(): void
    {
        $compose = file_get_contents(dirname(__DIR__, 3) . '/docker-compose.yml');

        self::assertIsString($compose);
        self::assertStringContainsString('"127.0.0.1:8000:8000"', $compose);
        self::assertStringNotContainsString('"8000:8000"', $compose);
    }
}
