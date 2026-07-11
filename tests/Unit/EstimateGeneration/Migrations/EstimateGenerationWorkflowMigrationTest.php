<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Migrations;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EstimateGenerationWorkflowMigrationTest extends TestCase
{
    #[Test]
    public function migration_never_mutates_ordinary_estimate_tables(): void
    {
        $source = $this->migrationSource();

        self::assertStringContainsString("Schema::table('estimate_generation_sessions'", $source);
        self::assertStringNotContainsString("Schema::table('estimates'", $source);
        self::assertStringNotContainsString("DB::table('estimates'", $source);
        self::assertStringNotContainsString("DB::table('estimate_items'", $source);
    }

    #[Test]
    public function migration_adds_the_complete_session_workflow_schema(): void
    {
        $source = $this->migrationSource();

        self::assertStringContainsString("unsignedBigInteger('state_version')->default(0)", $source);
        self::assertStringContainsString("unique('applied_estimate_id')", $source);
        self::assertStringContainsString("timestampTz('applied_at')->nullable()", $source);
        self::assertStringContainsString("timestampTz('state_changed_at')->nullable()", $source);
        self::assertStringContainsString("string('failure_code', 100)->nullable()", $source);
        self::assertStringContainsString("string('resume_status', 40)->nullable()", $source);
    }

    #[Test]
    public function migration_cleans_only_ai_workflow_tables_in_child_first_order(): void
    {
        $source = $this->migrationSource();
        $cleanupStatements = [
            "DB::table('estimate_generation_feedback')->delete();",
            "DB::table('estimate_generation_audit_events')->delete();",
            "DB::table('estimate_generation_package_items')->delete();",
            "DB::table('estimate_generation_packages')->delete();",
            "DB::table('estimate_generation_document_facts')->delete();",
            "DB::table('estimate_generation_document_pages')->delete();",
            "DB::table('estimate_generation_documents')->delete();",
            "DB::table('estimate_generation_sessions')->delete();",
        ];

        $previousPosition = -1;

        foreach ($cleanupStatements as $statement) {
            $position = strpos($source, $statement);

            self::assertNotFalse($position, sprintf('Missing cleanup statement: %s', $statement));
            self::assertGreaterThan($previousPosition, $position, sprintf('Cleanup is not child-first at: %s', $statement));

            $previousPosition = $position;
        }

        self::assertStringNotContainsString("DB::table('estimate_generation_learning_examples'", $source);
        self::assertStringNotContainsString("DB::table('estimate_dataset_", $source);
        self::assertStringNotContainsString("DB::table('estimate_norm", $source);
        self::assertStringNotContainsString("DB::table('estimate_regional_price", $source);
    }

    private function migrationSource(): string
    {
        return (string) file_get_contents($this->migrationPath());
    }

    private function migrationPath(): string
    {
        return dirname(__DIR__, 4)
            . '/app/BusinessModules/Addons/EstimateGeneration/migrations/'
            . '2026_07_11_000001_rebuild_estimate_generation_session_workflow.php';
    }
}
