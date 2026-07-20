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
        self::assertStringContainsString('legal_workflow_templates_exact_version_unique', $indexes);
        self::assertStringContainsString('legal_workflow_decisions_reassign_revision_unique', $indexes);
        self::assertStringContainsString('document_content_hash', $constraints);
        self::assertStringContainsString('template_definition_hash', $constraints);
        self::assertStringContainsString('client_request_hash', $constraints);
        self::assertStringContainsString('NOT VALID', $constraints);
        self::assertStringContainsString('legal_workflow_immutable_guard', $constraints);
        self::assertStringContainsString("current_setting('app.legal_workflow_reassign_decision_id', true)", $constraints);
        self::assertStringContainsString('legal_workflow_steps_last_reassign_fk', $constraints);
        self::assertStringContainsString('legal_workflow_reassign_chain_guard', $constraints);
        self::assertStringContainsString('DEFERRABLE INITIALLY DEFERRED', $constraints);
        self::assertStringContainsString('VALIDATE CONSTRAINT', $validation);
        foreach ([$schema, $indexes, $constraints, $validation] as $source) {
            self::assertStringContainsString('legal_workflow_migrations_are_forward_only', $source);
        }
    }

    public function test_workflow_permissions_are_exact_and_combined_decide_is_absent(): void
    {
        $root = __DIR__.'/../../../';
        $sources = [
            file_get_contents($root.'app/Services/LegalArchive/Workflow/LegalWorkflowPermissions.php'),
            file_get_contents($root.'app/Services/LegalArchive/Workflow/LegalDocumentWorkflowService.php'),
            file_get_contents($root.'app/Services/LegalArchive/Workflow/LegalWorkflowActionResolver.php'),
            file_get_contents($root.'config/RoleDefinitions/admin/web_admin.json'),
            file_get_contents($root.'config/RoleDefinitions/admin/finance_admin.json'),
            file_get_contents($root.'lang/ru/permissions.php'),
        ];
        foreach ($sources as $source) {
            self::assertIsString($source);
            self::assertStringNotContainsString('legal_archive.workflow.decide', $source);
        }
        foreach (['submit', 'approve', 'reject', 'return', 'reassign', 'cancel'] as $action) {
            self::assertStringContainsString("legal_archive.workflow.{$action}", $sources[0]);
        }
        self::assertStringContainsString('AuthorizationContext::getProjectContext', file_get_contents($root.'app/Services/LegalArchive/Workflow/LegalWorkflowActorResolver.php'));
        self::assertStringContainsString('AuthorizationContext::getOrganizationContext', file_get_contents($root.'app/Services/LegalArchive/Workflow/LegalWorkflowActorResolver.php'));
        self::assertStringContainsString('->hasRole(', file_get_contents($root.'app/Services/LegalArchive/Workflow/LegalWorkflowActorResolver.php'));
        self::assertStringNotContainsString('getUserRoleSlugs', file_get_contents($root.'app/Services/LegalArchive/Workflow/LegalWorkflowActorResolver.php'));
    }

    public function test_postgresql_concurrency_contract_is_real_and_explicitly_opt_in(): void
    {
        $source = file_get_contents(__DIR__.'/../../Integration/LegalArchive/LegalWorkflowPostgresConcurrencyTest.php');
        self::assertIsString($source);
        self::assertStringContainsString("getenv('LEGAL_ARCHIVE_PG_WORKFLOW_CONCURRENCY') !== '1'", $source);
        self::assertStringContainsString("preg_match('/(?:_test|_testing)$/D'", $source);
        self::assertStringContainsString('CREATE SCHEMA', $source);
        self::assertStringContainsString('workflow_first', $source);
        self::assertStringContainsString('workflow_second', $source);
        self::assertStringContainsString('pg_try_advisory_xact_lock', $source);
        self::assertStringContainsString('LegalWorkflowTemplateService', $source);
        self::assertStringContainsString('LegalDocumentWorkflowService', $source);
        self::assertStringContainsString('LegalWorkflowRecoveryService', $source);
        self::assertStringNotContainsString('LEGAL_ARCHIVE_PG_WORKFLOW_INSTANCE_ID', $source);
        foreach (['template_head', 'submit_replay', 'parallel_decisions', 'terminal_uniqueness'] as $scenario) {
            self::assertStringContainsString($scenario, $source);
        }
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
