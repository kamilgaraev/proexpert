<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use PHPUnit\Framework\TestCase;

final class LegalWorkflowArchitectureTest extends TestCase
{
    public function test_index_descriptor_rejects_semantic_catalog_drift_without_database(): void
    {
        $migration = require __DIR__.'/../../../database/migrations/2026_07_19_000410_create_legal_document_workflow_indexes.php';
        $indexesMethod = new \ReflectionMethod($migration, 'indexes');
        $sameDescriptorMethod = new \ReflectionMethod($migration, 'sameDescriptor');
        $expected = $indexesMethod->invoke($migration)['legal_workflow_steps_actor_queue_idx'];
        $actual = (object) [
            'table_name' => 'legal_workflow_steps',
            'access_method' => 'btree',
            'indisunique' => false,
            'indisvalid' => true,
            'indisready' => true,
            'indislive' => true,
            'indimmediate' => true,
            'indisexclusion' => false,
            'indisprimary' => false,
            'indnkeyatts' => 4,
            'indnatts' => 4,
            'indnullsnotdistinct' => false,
            'key_definitions' => json_encode([
                'organization_id',
                'actor_type',
                'actor_reference',
                'due_at',
            ], JSON_THROW_ON_ERROR),
            'include_definitions' => '[]',
            'predicate' => "status = 'active'::text",
        ];

        self::assertTrue($sameDescriptorMethod->invoke($migration, $actual, $expected));
        foreach ([
            ['access_method' => 'hash'],
            ['key_definitions' => '["organization_id","actor_type","actor_reference","due_at DESC NULLS FIRST"]'],
            ['indnatts' => 5, 'include_definitions' => '["step_key"]'],
            ['indnullsnotdistinct' => true],
            ['indimmediate' => false],
            ['indisexclusion' => true],
            ['indisprimary' => true],
        ] as $drift) {
            self::assertFalse($sameDescriptorMethod->invoke($migration, (object) [
                ...get_object_vars($actual),
                ...$drift,
            ], $expected));
        }
    }

    public function test_schema_is_multi_phase_online_and_enforces_exact_tenant_hash_ownership(): void
    {
        $root = __DIR__.'/../../../';
        $schema = file_get_contents($root.'database/migrations/2026_07_19_000400_create_legal_document_workflows.php');
        $indexes = file_get_contents($root.'database/migrations/2026_07_19_000410_create_legal_document_workflow_indexes.php');
        $constraints = file_get_contents($root.'database/migrations/2026_07_19_000420_add_legal_document_workflow_constraints.php');
        $constraintDescriptors = file_get_contents($root.'app/Services/LegalArchive/Workflow/Schema/LegalWorkflowPostgresConstraints.php');
        $validation = file_get_contents($root.'database/migrations/2026_07_19_000430_validate_legal_document_workflow_constraints.php');
        foreach ([$schema, $indexes, $constraints, $constraintDescriptors, $validation] as $source) {
            self::assertIsString($source);
        }
        self::assertStringContainsString('$withinTransaction = false', $indexes);
        self::assertStringContainsString('CREATE UNIQUE INDEX CONCURRENTLY', $indexes);
        self::assertStringContainsString('pg_get_expr(i.indpred', $indexes);
        self::assertStringContainsString('pg_get_indexdef(i.indexrelid, position, true)', $indexes);
        self::assertStringContainsString('JOIN pg_am access_method', $indexes);
        self::assertStringContainsString('i.indnkeyatts', $indexes);
        self::assertStringContainsString('i.indnatts', $indexes);
        self::assertStringContainsString("COALESCE((to_jsonb(i)->>'indnullsnotdistinct')::boolean, false)::integer", $indexes);
        self::assertStringNotContainsString('i.indnullsnotdistinct', $indexes);
        self::assertStringContainsString('i.indimmediate', $indexes);
        self::assertStringContainsString('i.indisexclusion', $indexes);
        self::assertStringContainsString('i.indisprimary', $indexes);
        self::assertStringContainsString('key_definitions', $indexes);
        self::assertStringContainsString('include_definitions', $indexes);
        self::assertStringContainsString('i.indisready', $indexes);
        self::assertStringContainsString('i.indisunique', $indexes);
        self::assertStringContainsString('legal_workflow_index_descriptor_mismatch', $indexes);
        self::assertStringContainsString('legal_workflow_instances_active_unique', $indexes);
        self::assertStringContainsString('legal_workflow_decisions_terminal_unique', $indexes);
        self::assertStringContainsString('legal_workflow_templates_exact_version_unique', $indexes);
        self::assertStringContainsString('legal_workflow_decisions_reassign_revision_unique', $indexes);
        self::assertStringContainsString('document_content_hash', $constraintDescriptors);
        self::assertStringContainsString('template_definition_hash', $constraintDescriptors);
        self::assertStringContainsString('client_request_hash', $constraintDescriptors);
        self::assertStringContainsString('NOT VALID', $constraints);
        self::assertStringContainsString('pg_get_constraintdef', $constraints);
        self::assertStringContainsString('c.conrelid', $constraints);
        self::assertStringContainsString('c.condeferrable', $constraints);
        self::assertStringContainsString('legal_workflow_constraint_descriptor_mismatch', $constraints);
        self::assertStringContainsString('legal_workflow_immutable_guard', $constraints);
        self::assertStringContainsString("current_setting('app.legal_workflow_reassign_decision_id', true)", $constraints);
        self::assertStringContainsString('legal_workflow_steps_last_reassign_fk', $constraintDescriptors);
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
            self::assertStringNotContainsString('legal_archive.workflow.manage', $source);
        }
        foreach (['submit', 'approve', 'reject', 'return', 'reassign', 'cancel'] as $action) {
            self::assertStringContainsString("legal_archive.workflow.{$action}", $sources[0]);
        }
        $actorResolver = file_get_contents($root.'app/Services/LegalArchive/Workflow/LegalWorkflowActorResolver.php');
        self::assertStringContainsString('AuthorizationContext::findProjectContext', $actorResolver);
        self::assertStringContainsString('AuthorizationContext::findOrganizationContext', $actorResolver);
        self::assertStringNotContainsString('AuthorizationContext::getProjectContext', $actorResolver);
        self::assertStringNotContainsString('AuthorizationContext::getOrganizationContext', $actorResolver);
        self::assertStringContainsString('->hasRole(', $actorResolver);
        self::assertStringNotContainsString('getUserRoleSlugs', $actorResolver);
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
        self::assertStringContainsString('pcntl_fork', $source);
        self::assertStringContainsString('race_barriers', $source);
        self::assertStringContainsString('LegalWorkflowTemplateService', $source);
        self::assertStringContainsString('LegalDocumentWorkflowService', $source);
        self::assertStringContainsString('LegalWorkflowRecoveryService', $source);
        self::assertStringContainsString('legal_workflow_unrelated_probe_check', $source);
        self::assertStringNotContainsString('LEGAL_ARCHIVE_PG_WORKFLOW_INSTANCE_ID', $source);
        foreach (['template_head', 'submit_replay', 'parallel_decisions', 'terminal_uniqueness', 'document_aggregate'] as $scenario) {
            self::assertStringContainsString($scenario, $source);
        }
        self::assertStringContainsString('2026_06_23_000001_create_legal_archive_tables', $source);
        self::assertStringContainsString('2026_07_19_000100_create_legal_document_profiles_and_extend_dossiers', $source);
    }

    public function test_document_aggregate_mutations_share_one_global_lock_order(): void
    {
        $root = __DIR__.'/../../../';
        $lock = file_get_contents($root.'app/Services/LegalArchive/LegalDocumentAggregateLock.php');
        $workflow = file_get_contents($root.'app/Services/LegalArchive/Workflow/LegalDocumentWorkflowService.php');
        $files = file_get_contents($root.'app/Services/LegalArchive/Files/LegalDocumentFileService.php');
        $editGuard = file_get_contents($root.'app/Services/LegalArchive/Editor/LegalDocumentEditGuard.php');
        self::assertIsString($lock);
        self::assertIsString($workflow);
        self::assertIsString($files);
        self::assertIsString($editGuard);
        self::assertStringContainsString('pg_advisory_xact_lock', $lock);
        self::assertStringContainsString('lockDocument', $workflow);
        self::assertStringContainsString('lockDocument', $files);
        self::assertStringContainsString('lockFile', $files);
        self::assertStringContainsString('lockVersion', $workflow);
        self::assertStringContainsString('lockVersion', $files);
        self::assertStringContainsString('LegalDocumentEditGuard', $files);
        self::assertStringContainsString('legal_document_active_workflow_exists', $editGuard);
        self::assertStringNotContainsString('private function documents()', $workflow);
        self::assertStringNotContainsString('private function versions()', $workflow);
        $recovery = file_get_contents($root.'app/Services/LegalArchive/Workflow/LegalWorkflowRecoveryService.php');
        self::assertIsString($recovery);
        self::assertStringNotContainsString('private function documents()', $recovery);
        self::assertStringNotContainsString('private function versions()', $recovery);
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
