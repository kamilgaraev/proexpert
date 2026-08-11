<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Planning;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Stage5PlanningMigrationContractTest extends TestCase
{
    #[DataProvider('migrations')]
    public function test_forward_only_concurrent_indexes_are_schema_safe_and_retryable(string $file): void
    {
        $source = file_get_contents($file);

        self::assertIsString($source);
        self::assertStringContainsString('implements ForwardOnlyMigration', $source);
        self::assertStringContainsString('public $withinTransaction = false', $source);
        self::assertStringContainsString('indisvalid', $source);
        self::assertStringContainsString('indisready', $source);
        self::assertStringContainsString('pg_get_indexdef', $source);
        self::assertStringContainsString('pg_get_constraintdef', $source);
        self::assertStringContainsString('JOIN pg_class AS constraint_table', $source);
        self::assertStringContainsString('JOIN pg_namespace AS constraint_schema', $source);
        self::assertStringContainsString("constraint_state.contype = 'c'", $source);
        self::assertStringContainsString('canonicalConstraint', $source);
        self::assertStringContainsString('DROP INDEX CONCURRENTLY IF EXISTS', $source);
        self::assertStringContainsString('CREATE INDEX CONCURRENTLY', $source);
        self::assertStringContainsString('CREATE UNIQUE INDEX CONCURRENTLY', $source);
        self::assertStringContainsString('SET search_path TO ', $source);
        self::assertStringContainsString('RESET lock_timeout', $source);
        self::assertStringContainsString('RESET statement_timeout', $source);
        self::assertStringContainsString('RESET search_path', $source);
        self::assertStringContainsString('try {', $source);
        self::assertStringContainsString('} finally {', $source);
        self::assertStringContainsString('throw new RuntimeException', $source);
        self::assertStringNotContainsString('->whereRaw("', $source);
        self::assertStringNotContainsString("SELECT 1 FROM pg_constraint WHERE conname = '", $source);
        self::assertStringContainsString('no destructive rollback', $source);
    }

    public function test_runtime_and_schema_contracts_cover_applicability_and_finding_identity(): void
    {
        $technology = file_get_contents(self::migrations()[0][0]);
        $completeness = file_get_contents(self::migrations()[1][0]);

        self::assertStringContainsString("applicability_status IN ('applicable', 'conditional', 'unavailable')", $technology);
        self::assertStringContainsString('applicability_reasons', $technology);
        self::assertStringContainsString('applicability_evidence', $technology);
        self::assertStringContainsString('finding_stable_key', $completeness);
        self::assertStringContainsString('finding_version', $completeness);
        self::assertStringContainsString("'technology_conditional'", $completeness);
        self::assertStringContainsString("'unresolved'", $completeness);
    }

    public static function migrations(): array
    {
        $root = dirname(__DIR__, 4);

        return [
            [$root.'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_08_11_000700_create_technology_planning_projections.php'],
            [$root.'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_08_11_000710_create_completeness_planning_projections.php'],
        ];
    }
}
