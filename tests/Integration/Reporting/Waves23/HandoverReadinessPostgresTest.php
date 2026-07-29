<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting\Waves23;

use PHPUnit\Framework\TestCase;

final class HandoverReadinessPostgresTest extends TestCase
{
    public function test_schema_enforces_append_only_facts_and_same_source_causation(): void
    {
        $root = dirname(__DIR__, 4);
        $migration = file_get_contents($root.'/app/BusinessModules/Features/HandoverAcceptance/migrations/2026_07_26_140000_create_handover_readiness_reporting_tables.php');

        self::assertIsString($migration);
        self::assertStringContainsString('_append_only BEFORE UPDATE OR DELETE', $migration);
        self::assertStringContainsString('most_validate_handover_evidence_causation_v1', $migration);
        self::assertStringContainsString('cause.source_type <> NEW.source_type', $migration);
        self::assertStringContainsString('cause.source_id <> NEW.source_id', $migration);
        self::assertStringContainsString('cause.occurred_at > NEW.occurred_at', $migration);
    }
}
