<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use PHPUnit\Framework\TestCase;

final class NotificationConcurrencyCiContractTest extends TestCase
{
    public function test_postgres_test_uses_database_observed_advisory_and_exact_post_cut_barriers(): void
    {
        $source = $this->source(
            'tests/Feature/Notifications/NotificationPostgresConcurrencyTest.php'
        );

        self::assertStringContainsString("waitForPostgresLock('notification-send-worker', 'advisory')", $source);
        self::assertStringNotContainsString('isReadable($secondParent, 0, 250000)', $source);
        self::assertStringContainsString('BarrierNotificationMarkAllReadGateway', $source);
        self::assertStringContainsString("'cut:'.\$sequenceCut", $source);
        self::assertStringContainsString("trim(\$command) !== 'release'", $source);
        self::assertStringContainsString('$this->terminateAndWaitChildren($children)', $source);
        self::assertStringContainsString('app(NotificationService::class)->send(', $source);
    }

    public function test_dedicated_workflow_runs_the_opt_in_postgres_test_on_notification_changes(): void
    {
        $workflow = $this->source('.github/workflows/notification-concurrency.yml');

        self::assertStringContainsString('pull_request:', $workflow);
        self::assertStringContainsString('push:', $workflow);
        self::assertStringContainsString('branches: [main]', $workflow);
        self::assertStringContainsString('postgres:', $workflow);
        self::assertStringContainsString('image: postgres:16', $workflow);
        self::assertStringContainsString('php-version: 8.2', $workflow);
        self::assertStringContainsString('RUN_NOTIFICATION_POSTGRES_TESTS: 1', $workflow);
        self::assertStringContainsString(
            'php vendor/bin/phpunit tests/Feature/Notifications/NotificationPostgresConcurrencyTest.php',
            $workflow
        );
        self::assertStringNotContainsString('secrets.', $workflow);
    }

    private function source(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/'.$path);
        self::assertIsString($source);

        return $source;
    }
}
