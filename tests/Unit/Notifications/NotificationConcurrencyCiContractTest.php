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

    public function test_opt_in_postgres_test_fails_instead_of_skipping_when_runtime_requirements_are_missing(): void
    {
        $source = $this->source(
            'tests/Feature/Notifications/NotificationPostgresConcurrencyTest.php'
        );

        self::assertStringContainsString("if (getenv('RUN_NOTIFICATION_POSTGRES_TESTS') !== '1')", $source);
        self::assertStringContainsString("\$this->markTestSkipped('PostgreSQL concurrency tests are not enabled.')", $source);
        self::assertStringContainsString("self::fail('PostgreSQL concurrency environment is incomplete: '.implode(', ', \$missingRequirements))", $source);
        self::assertStringNotContainsString("|| ! function_exists('pcntl_fork')", $source);
    }

    public function test_postgres_concurrency_test_keeps_the_prepared_schema_outside_refresh_database_transactions(): void
    {
        $source = $this->source(
            'tests/Feature/Notifications/NotificationPostgresConcurrencyTest.php'
        );

        self::assertStringContainsString('public function refreshDatabase(): void', $source);
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
        self::assertDoesNotMatchRegularExpression(
            '/^\s*run:\s+php artisan migrate --force\s*$/m',
            $workflow
        );
        self::assertStringContainsString(
            'php artisan migrate --force --path=database/migrations/0001_01_01_000000_create_users_table.php',
            $workflow
        );
        self::assertStringContainsString(
            'php artisan migrate --force --path=database/migrations/2025_01_01_000010_create_organizations_table.php',
            $workflow
        );
        self::assertStringContainsString(
            'php artisan migrate --force --path=database/migrations/2025_05_03_161553_add_fields_to_users_table.php',
            $workflow
        );
        self::assertStringContainsString(
            'php artisan migrate --force --path=app/BusinessModules/Features/Notifications/migrations/2025_10_10_100001_extend_notifications_table.php',
            $workflow
        );
        self::assertStringContainsString(
            'php artisan migrate --force --path=app/BusinessModules/Features/Notifications/migrations/2026_07_15_000001_create_notification_targets_table.php',
            $workflow
        );
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
