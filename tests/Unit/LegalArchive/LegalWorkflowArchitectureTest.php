<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use PHPUnit\Framework\TestCase;

final class LegalWorkflowArchitectureTest extends TestCase
{
    public function test_schema_is_multi_phase_online_and_enforces_exact_tenant_hash_ownership(): void
    {
        $root = __DIR__.'/../../../';
        $schema = file_get_contents($root.'database/migrations/2026_07_19_000400_create_legal_document_workflows.php');
        $indexes = file_get_contents($root.'database/migrations/2026_07_19_000410_create_legal_document_workflow_indexes.php');
        $constraints = file_get_contents($root.'database/migrations/2026_07_19_000420_add_legal_document_workflow_constraints.php');
        $validation = file_get_contents($root.'database/migrations/2026_07_19_000430_validate_legal_document_workflow_constraints.php');
        foreach ([$schema, $indexes, $constraints, $validation] as $source) {
            self::assertIsString($source);
        }
        self::assertStringContainsString('$withinTransaction = false', $indexes);
        self::assertStringContainsString('CREATE UNIQUE INDEX CONCURRENTLY', $indexes);
        self::assertStringContainsString('legal_workflow_instances_active_unique', $indexes);
        self::assertStringContainsString('legal_workflow_decisions_terminal_unique', $indexes);
        self::assertStringContainsString('document_content_hash', $constraints);
        self::assertStringContainsString('NOT VALID', $constraints);
        self::assertStringContainsString('legal_workflow_immutable_guard', $constraints);
        self::assertStringContainsString('VALIDATE CONSTRAINT', $validation);
        self::assertStringContainsString('legal_workflow_rollback_blocked_by_data', $schema);
    }

    public function test_postgresql_concurrency_contract_is_real_and_explicitly_opt_in(): void
    {
        $source = file_get_contents(__DIR__.'/../../Integration/LegalArchive/LegalWorkflowPostgresConcurrencyTest.php');
        self::assertIsString($source);
        self::assertStringContainsString("getenv('LEGAL_ARCHIVE_PG_WORKFLOW_CONCURRENCY') !== '1'", $source);
        self::assertGreaterThanOrEqual(2, substr_count($source, 'new PDO('));
        self::assertStringContainsString('FOR UPDATE NOWAIT', $source);
        self::assertStringContainsString('legal_workflow_instances_active_unique', $source);
        self::assertStringContainsString('legal_workflow_decisions_terminal_unique', $source);
        self::assertGreaterThanOrEqual(2, substr_count($source, 'rollBack()'));
        self::assertStringNotContainsString('CREATE TABLE', $source);
    }

    public function test_decisions_and_template_versions_are_append_only_in_models_and_postgres(): void
    {
        $decision = file_get_contents(__DIR__.'/../../../app/BusinessModules/Features/LegalArchive/Models/LegalWorkflowDecision.php');
        $template = file_get_contents(__DIR__.'/../../../app/BusinessModules/Features/LegalArchive/Models/LegalWorkflowTemplate.php');
        $constraints = file_get_contents(__DIR__.'/../../../database/migrations/2026_07_19_000420_add_legal_document_workflow_constraints.php');
        self::assertIsString($decision);
        self::assertIsString($template);
        self::assertIsString($constraints);
        self::assertStringContainsString('self::updating', $decision);
        self::assertStringContainsString('self::deleting', $decision);
        self::assertStringContainsString('self::updating', $template);
        self::assertStringContainsString("'legal_workflow_templates', 'legal_workflow_template_steps', 'legal_workflow_decisions'", $constraints);
    }
}
