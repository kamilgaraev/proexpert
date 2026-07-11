<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Migrations;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EstimateGenerationWorkflowMigrationTest extends TestCase
{
    private const WORKFLOW_STATUSES = [
        'draft',
        'processing_documents',
        'input_review_required',
        'ready_to_generate',
        'generating',
        'estimate_review_required',
        'ready_to_apply',
        'applying',
        'applied',
        'failed',
        'cancelled',
        'archived',
    ];

    private const CLEANUP_TABLES = [
        'estimate_generation_feedback',
        'estimate_generation_audit_events',
        'estimate_generation_package_items',
        'estimate_generation_packages',
        'estimate_generation_drawing_elements',
        'estimate_generation_quantity_takeoffs',
        'estimate_generation_scope_inferences',
        'estimate_generation_document_facts',
        'estimate_generation_document_pages',
        'estimate_generation_documents',
        'estimate_generation_sessions',
    ];

    #[Test]
    public function migration_never_mutates_ordinary_estimate_tables(): void
    {
        $source = $this->migrationSource();

        self::assertStringContainsString("Schema::table('estimate_generation_sessions'", $source);
        self::assertStringNotContainsString("Schema::table('estimates'", $source);
        self::assertStringNotContainsString("Schema::table('estimate_items'", $source);
        self::assertStringNotContainsString("Schema::table('estimate_sections'", $source);
        self::assertStringNotContainsString("DB::table('estimates'", $source);
        self::assertStringNotContainsString("DB::table('estimate_items'", $source);
        self::assertStringNotContainsString("DB::table('estimate_sections'", $source);
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
        $cleanupStatements = array_map(
            static fn (string $table): string => "DB::table('{$table}')->delete();",
            self::CLEANUP_TABLES
        );

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

        preg_match_all("/DB::table\\('([^']+)'\\)/", $source, $matches);

        self::assertSame(self::CLEANUP_TABLES, $matches[1]);
    }

    #[Test]
    public function migration_replaces_the_legacy_default_with_the_complete_status_constraint(): void
    {
        $source = $this->migrationSource();

        self::assertStringContainsString("string('status', 50)->default('draft')->change()", $source);
        self::assertStringContainsString('ADD CONSTRAINT estimate_generation_sessions_status_check', $source);
        self::assertStringContainsString('DROP CONSTRAINT estimate_generation_sessions_status_check', $source);
        self::assertStringContainsString("string('status', 50)->default('created')->change()", $source);
        self::assertStringNotContainsString('->insert(', $source);
        self::assertStringNotContainsString('->upsert(', $source);

        preg_match(
            '/ADD CONSTRAINT estimate_generation_sessions_status_check\\s+CHECK \\(status IN \\((.*?)\\)\\)/s',
            $source,
            $matches
        );
        preg_match_all("/'([^']+)'/", $matches[1] ?? '', $statusMatches);

        self::assertSame(self::WORKFLOW_STATUSES, $statusMatches[1]);
        self::assertNotContains('created', $statusMatches[1]);
    }

    #[Test]
    public function runtime_session_creation_uses_the_draft_workflow_status(): void
    {
        $controllerSource = (string) file_get_contents(
            dirname(__DIR__, 4)
            . '/app/BusinessModules/Addons/EstimateGeneration/Http/Controllers/EstimateGenerationController.php'
        );

        self::assertStringContainsString('EstimateGenerationStatus::Draft->value', $controllerSource);

        $legacyWriters = [];
        $modulePath = dirname(__DIR__, 4) . '/app/BusinessModules/Addons/EstimateGeneration';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($modulePath));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $runtimeSource = (string) file_get_contents($file->getPathname());

            if (preg_match("/['\"]status['\"]\\s*=>\\s*['\"]created['\"]/", $runtimeSource) === 1) {
                $legacyWriters[] = $file->getPathname();
            }
        }

        self::assertSame([], $legacyWriters, 'Runtime writers still use the legacy created status.');
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
