<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use PHPUnit\Framework\TestCase;

final class WebSocketProductionConfigurationTest extends TestCase
{
    public function test_reverb_has_restricted_origins_and_a_finite_connection_limit(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/config/reverb.php');

        self::assertIsString($source);
        self::assertStringContainsString('REVERB_ALLOWED_ORIGINS', $source);
        self::assertStringContainsString('xn--1-xtbgmf.xn--p1ai', $source);
        self::assertStringNotContainsString("'allowed_origins' => ['*']", $source);
        self::assertStringContainsString("env('REVERB_APP_MAX_CONNECTIONS', 5000)", $source);
    }

    public function test_horizon_owns_the_broadcast_queue(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/config/horizon.php');

        self::assertIsString($source);
        self::assertStringContainsString("'redis:broadcast' => 60", $source);
        self::assertStringContainsString("'queue' => ['broadcast', 'notifications', 'default']", $source);
    }

    public function test_backend_containers_publish_to_the_reverb_service(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/docker-compose.yml');

        self::assertIsString($source);
        self::assertStringContainsString('REVERB_INTERNAL_HOST: websockets', $source);
        self::assertStringContainsString('REVERB_INTERNAL_PORT: 8080', $source);
        self::assertStringContainsString('REVERB_INTERNAL_SCHEME: http', $source);
        self::assertSame(5, substr_count($source, 'environment: *reverb-client-environment'));
    }

    public function test_deployment_retires_legacy_workers_and_checks_reverb_health(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 3).'/.github/workflows/deploy-backend.yml');

        self::assertIsString($workflow);
        self::assertStringContainsString("grep -Eq '^laravel-worker(_[0-9]+|:)'", $workflow);
        self::assertStringContainsString("supervisorctl stop 'laravel-worker:*'", $workflow);
        self::assertStringContainsString("pgrep -af '^php /var/www/prohelper/artisan queue:work( |$)'", $workflow);
        self::assertStringContainsString('REVERB_APP_KEY="${{ secrets.REVERB_APP_KEY }}"', $workflow);
        self::assertStringContainsString('s|^REVERB_APP_KEY=.*|REVERB_APP_KEY=${REVERB_APP_KEY}|', $workflow);
        self::assertStringContainsString('@fsockopen', $workflow);
    }
}
