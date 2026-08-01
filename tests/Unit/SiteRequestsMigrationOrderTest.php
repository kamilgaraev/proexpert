<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SiteRequestsMigrationOrderTest extends TestCase
{
    public function test_site_requests_table_runs_after_its_foreign_key_targets(): void
    {
        $migration = '2025_01_01_000031_create_site_requests_table.php';

        self::assertFileExists(
            dirname(__DIR__, 2).'/app/BusinessModules/Features/SiteRequests/migrations/'.$migration,
        );

        foreach ([
            '2025_01_01_000010_create_organizations_table.php',
            '2025_01_01_000020_create_projects_table.php',
        ] as $prerequisite) {
            self::assertLessThan($migration, $prerequisite);
        }
    }

    public function test_site_request_module_migrations_preserve_foreign_key_order(): void
    {
        self::assertLessThan(
            '2025_01_01_000033_create_site_request_status_transitions_table.php',
            '2025_01_01_000032_create_site_request_statuses_table.php',
        );
        self::assertLessThan(
            '2025_01_01_000035_create_site_request_history_table.php',
            '2025_01_01_000031_create_site_requests_table.php',
        );
        self::assertLessThan(
            '2025_01_01_000036_create_site_request_calendar_events_table.php',
            '2025_01_01_000031_create_site_requests_table.php',
        );
        self::assertLessThan(
            '2025_11_21_000003_add_payment_document_id_to_site_requests.php',
            '2025_11_21_000002_create_payment_documents_table.php',
        );
    }

    public function test_renamed_migrations_do_not_recreate_existing_production_schema(): void
    {
        $migrations = [
            '2025_01_01_000032_create_site_request_statuses_table.php' => "Schema::hasTable('site_request_statuses')",
            '2025_01_01_000033_create_site_request_status_transitions_table.php' => "Schema::hasTable('site_request_status_transitions')",
            '2025_01_01_000034_create_site_request_templates_table.php' => "Schema::hasTable('site_request_templates')",
            '2025_01_01_000035_create_site_request_history_table.php' => "Schema::hasTable('site_request_history')",
            '2025_01_01_000036_create_site_request_calendar_events_table.php' => "Schema::hasTable('site_request_calendar_events')",
            '2025_11_21_000003_add_payment_document_id_to_site_requests.php' => "Schema::hasColumn('site_requests', 'payment_document_id')",
        ];

        $directory = dirname(__DIR__, 2).'/app/BusinessModules/Features/SiteRequests/migrations/';

        foreach ($migrations as $migration => $guard) {
            self::assertStringContainsString($guard, (string) file_get_contents($directory.$migration));
        }

        self::assertStringContainsString(
            "Schema::hasTable('payment_document_estimate_splits')",
            (string) file_get_contents(
                dirname(__DIR__, 2).'/database/migrations/2025_11_21_000004_create_payment_document_estimate_splits_table.php',
            ),
        );
    }
}
