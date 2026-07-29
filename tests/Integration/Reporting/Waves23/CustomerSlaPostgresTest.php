<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting\Waves23;

use PHPUnit\Framework\TestCase;

final class CustomerSlaPostgresTest extends TestCase
{
    public function test_schema_enforces_event_causation_membership_history_and_immutability(): void
    {
        $root = dirname(__DIR__, 4);
        $migration = file_get_contents($root.'/database/migrations/2026_07_26_150000_create_customer_sla_reporting_tables.php');

        self::assertIsString($migration);
        self::assertStringContainsString('customer_membership_history', $migration);
        self::assertStringContainsString('organization_user_customer_history', $migration);
        self::assertStringContainsString('project_organization_customer_history', $migration);
        self::assertStringContainsString('most_validate_customer_workflow_causation_v1', $migration);
        self::assertStringContainsString('_append_only BEFORE UPDATE OR DELETE', $migration);
    }
}
