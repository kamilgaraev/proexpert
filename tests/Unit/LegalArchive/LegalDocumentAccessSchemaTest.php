<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use App\Services\LegalArchive\Sources\LegalDocumentSourceType;
use PHPUnit\Framework\TestCase;

final class LegalDocumentAccessSchemaTest extends TestCase
{
    public function test_supported_source_types_are_closed_and_cover_operational_origins(): void
    {
        self::assertSame([
            'project',
            'contract',
            'supplementary_agreement',
            'performance_act',
            'purchase_order',
            'payment_document',
            'commercial_proposal',
            'crm_deal',
            'estimate',
            'executive_document',
        ], array_column(LegalDocumentSourceType::cases(), 'value'));
    }

    public function test_schema_is_online_safe_and_enforces_tenant_owned_composite_references(): void
    {
        $root = __DIR__.'/../../../';
        $create = file_get_contents($root.'database/migrations/2026_07_19_000500_create_legal_document_parties_access_and_comments.php');
        $indexes = file_get_contents($root.'database/migrations/2026_07_19_000510_create_legal_document_access_indexes.php');
        $constraints = file_get_contents($root.'database/migrations/2026_07_19_000520_add_legal_document_access_constraints.php');
        $validate = file_get_contents($root.'database/migrations/2026_07_19_000530_validate_legal_document_access_constraints.php');

        foreach ([$create, $indexes, $constraints, $validate] as $source) {
            self::assertIsString($source);
            self::assertStringContainsString('legal_document_access_migrations_are_forward_only', $source);
        }
        self::assertStringContainsString('$withinTransaction = false', $indexes);
        self::assertStringContainsString('CREATE UNIQUE INDEX CONCURRENTLY', $indexes);
        self::assertStringContainsString('legal_documents_source_identity_unique', $indexes);
        self::assertStringContainsString('legal_documents_source_command_unique', $indexes);
        self::assertStringContainsString('COALESCE(created_by_user_id, 0::bigint)', $indexes);
        self::assertStringContainsString('legal_document_access_active_subject_unique', $indexes);
        self::assertStringContainsString('counterparties_ownership_unique', $indexes);
        self::assertStringContainsString('legal_document_party_snapshot_sets_version_unique', $indexes);
        self::assertStringContainsString('legal_document_party_snapshot_sets_ownership_unique', $indexes);
        self::assertStringContainsString('NOT VALID', $constraints);
        self::assertStringContainsString('FOREIGN KEY (document_id, organization_id)', $constraints);
        self::assertStringContainsString('FOREIGN KEY (document_version_id, document_id, organization_id)', $constraints);
        self::assertStringContainsString(
            'FOREIGN KEY (counterparty_id, organization_id) REFERENCES counterparties (id, organization_id)',
            $constraints,
        );
        self::assertStringContainsString(
            'FOREIGN KEY (snapshot_set_id, document_version_id, document_id, organization_id)',
            $constraints,
        );
        self::assertStringContainsString('legal_document_party_snapshot_set_immutable_guard', $constraints);
        self::assertStringContainsString('legal_document_comments_anchor_check', $constraints);
        self::assertStringContainsString('legal_document_party_immutable_guard', $constraints);
        self::assertStringContainsString('legal_document_access_grant_guard', $constraints);
        self::assertStringContainsString('legal_document_owner_principal_guard', $constraints);
        self::assertStringContainsString('OLD.created_by_user_id IS DISTINCT FROM NEW.created_by_user_id', $constraints);
        self::assertStringContainsString('OLD.owner_user_id IS DISTINCT FROM NEW.owner_user_id', $constraints);
        self::assertStringContainsString('BEFORE UPDATE OF created_by_user_id, owner_user_id', $constraints);
        self::assertStringContainsString('pg_get_triggerdef', $constraints);
        self::assertStringContainsString('legal_document_access_trigger_descriptor_mismatch', $constraints);
        self::assertStringContainsString('"manage"', $constraints);
        self::assertStringContainsString("subject_kind = 'internal_user'", $constraints);
        self::assertStringContainsString('legal_document_comment_guard', $constraints);
        self::assertStringContainsString("str_replace(['=anyarray[', ']']", $constraints);
        self::assertStringContainsString('VALIDATE CONSTRAINT', $validate);
    }

    public function test_forward_access_ability_contract_includes_every_grant_ability(): void
    {
        $root = __DIR__.'/../../../';
        $ability = file_get_contents($root.'app/Services/LegalArchive/Access/LegalDocumentAbility.php');
        $migration = file_get_contents($root.'database/migrations/2026_07_21_000010_add_edit_legal_document_access_ability.php');
        $validation = file_get_contents($root.'database/migrations/2026_07_21_000020_validate_legal_document_access_edit_ability.php');

        self::assertIsString($ability);
        self::assertIsString($migration);
        self::assertIsString($validation);
        self::assertStringContainsString("case EDIT = 'edit';", $ability);
        self::assertStringContainsString('"edit"', $migration);
        self::assertStringContainsString('legal_document_access_abilities_predecessor_mismatch', $migration);
        self::assertStringContainsString('legal_document_access_ability_migrations_are_forward_only', $migration);
        self::assertStringContainsString('VALIDATE CONSTRAINT legal_document_access_abilities_check', $validation);
        $postgres = file_get_contents(__DIR__.'/../../Integration/LegalArchive/LegalDocumentAccessPostgresIntegrationTest.php');
        self::assertIsString($postgres);
        self::assertStringContainsString('test_forward_access_ability_migration_accepts_owner_edit_grant', $postgres);
    }

    public function test_postgres_contract_covers_version_history_descriptor_drift_invalid_recovery_and_races(): void
    {
        $source = file_get_contents(__DIR__.'/../../Integration/LegalArchive/LegalDocumentAccessPostgresIntegrationTest.php');

        self::assertIsString($source);
        self::assertStringContainsString("getenv('LEGAL_ARCHIVE_PG_ACCESS_CONTRACT') !== '1'", $source);
        self::assertStringContainsString('server_version_num', $source);
        self::assertStringContainsString('140000', $source);
        self::assertStringContainsString('test_cross_tenant_counterparty_and_snapshot_version_references_are_rejected', $source);
        self::assertStringContainsString('test_party_and_snapshot_set_history_is_immutable', $source);
        self::assertStringContainsString('test_descriptor_drift_fails_closed', $source);
        self::assertStringContainsString('test_counterparty_fk_descriptor_drift_fails_closed', $source);
        self::assertStringContainsString('test_invalid_concurrent_index_is_recovered', $source);
        self::assertStringContainsString('test_parallel_active_grants_preserve_unique_subject_invariant', $source);
        self::assertStringContainsString('test_parallel_source_commands_preserve_actor_tenant_idempotency_namespace', $source);
        self::assertStringContainsString('test_owner_principals_are_immutable_after_document_creation', $source);
        self::assertStringContainsString('test_owner_principal_foreign_keys_are_restrictive_and_descriptor_checked', $source);
        self::assertStringContainsString('test_owner_principal_foreign_key_rollout_resumes_from_unvalidated_constraint', $source);
        self::assertStringContainsString('test_parallel_manager_revocation_preserves_one_active_manager', $source);
        self::assertStringContainsString('test_index_descriptor_drift_variants_fail_closed', $source);
        self::assertStringContainsString('NULLS NOT DISTINCT', $source);
        self::assertStringContainsString('text_pattern_ops', $source);
        self::assertStringContainsString('INCLUDE (id)', $source);
        self::assertStringContainsString('test_parallel_comment_create_and_resolve_preserve_idempotency_and_transition_invariants', $source);
        self::assertStringContainsString('test_parallel_expired_regrant_keeps_one_active_grant_and_history', $source);
    }

    public function test_requests_and_registry_use_typed_source_resolver_instead_of_free_source_types(): void
    {
        $root = __DIR__.'/../../../';
        $store = file_get_contents($root.'app/Http/Requests/Api/V1/Admin/LegalArchive/StoreLegalArchiveDocumentRequest.php');
        $update = file_get_contents($root.'app/Http/Requests/Api/V1/Admin/LegalArchive/UpdateLegalArchiveDocumentRequest.php');
        $registry = file_get_contents($root.'app/Services/LegalArchive/LegalArchiveRegistryService.php');
        self::assertIsString($store);
        self::assertStringContainsString('LegalDocumentSourceType::values()', $store);
        self::assertIsString($update);
        self::assertStringNotContainsString("'source_type'", $update);
        self::assertStringNotContainsString("'source_id'", $update);
        self::assertIsString($registry);
        self::assertStringContainsString('LegalDocumentSourceResolver', $registry);
        self::assertStringContainsString('assertOwnedSource', $registry);
    }

    public function test_management_recovery_uses_document_first_lock_order_and_post_lock_authorization(): void
    {
        $source = file_get_contents(
            __DIR__.'/../../../app/Services/LegalArchive/Access/LegalDocumentAccessService.php',
        );

        self::assertIsString($source);
        $recoveryStart = strpos($source, 'public function recoverOwnerManagement(');
        $securityStart = strpos($source, 'public function recoverManagementAsSecurityAdministrator(');
        $boundaryStart = strpos($source, 'private function belongsToOrganization(');
        self::assertIsInt($recoveryStart);
        self::assertIsInt($securityStart);
        self::assertIsInt($boundaryStart);
        $ownerRecovery = substr($source, $recoveryStart, $securityStart - $recoveryStart);
        $securityRecovery = substr($source, $securityStart, $boundaryStart - $securityStart);

        self::assertLessThan(
            strpos($ownerRecovery, '$lockedActor = $this->reloadActiveUser('),
            strpos($ownerRecovery, '$this->lock()->lockDocument('),
        );
        self::assertLessThan(
            strpos($ownerRecovery, '$this->authorizeManagementBoundary($lockedActor, $lockedDocument)'),
            strpos($ownerRecovery, '$lockedActor = $this->reloadActiveUser('),
        );
        self::assertLessThan(
            strpos($securityRecovery, '$lockedActor = $this->reloadActiveUser('),
            strpos($securityRecovery, '$this->lock()->lockDocument('),
        );
        self::assertLessThan(
            strpos($securityRecovery, '$this->authorizeSecurityRecovery($lockedActor, $lockedDocument)'),
            strpos($securityRecovery, '$lockedActor = $this->reloadActiveUser('),
        );
    }

    public function test_read_endpoints_load_the_owner_document_before_object_authorization(): void
    {
        $root = __DIR__.'/../../../';
        $documentController = file_get_contents($root.'app/Http/Controllers/Api/V1/Admin/LegalArchive/LegalArchiveDocumentController.php');
        $fileController = file_get_contents($root.'app/Http/Controllers/Api/V1/Admin/LegalArchive/LegalArchiveFileController.php');
        self::assertIsString($documentController);
        self::assertIsString($fileController);
        self::assertStringContainsString('findForAuthorization((int) $legalDocument)', $documentController);
        self::assertStringContainsString("\$this->access->authorize(\$actor, \$found, 'view');", $documentController);
        self::assertStringContainsString('currentVersionWithUrl($this->document($request, $legalDocument), $this->actor($request))', $fileController);
    }

    public function test_contract_document_routes_are_scoped_to_the_project_contract_and_linked_document(): void
    {
        $root = __DIR__.'/../../../';
        $routes = file_get_contents($root.'routes/api/v1/admin/project-based.php');
        $documentController = file_get_contents($root.'app/Http/Controllers/Api/V1/Admin/LegalArchive/LegalArchiveDocumentController.php');
        $fileController = file_get_contents($root.'app/Http/Controllers/Api/V1/Admin/LegalArchive/LegalArchiveFileController.php');
        $downloads = file_get_contents($root.'app/Services/LegalArchive/Files/LegalDocumentDownloadService.php');

        self::assertIsString($routes);
        self::assertIsString($documentController);
        self::assertIsString($fileController);
        self::assertIsString($downloads);
        self::assertStringContainsString("Route::get('/{contract}/documents/{legalDocument}'", $routes);
        self::assertStringContainsString("Route::get('/{contract}/documents/{legalDocument}/versions/{documentVersion}/{purpose}'", $routes);
        self::assertStringContainsString("->middleware('authorize:contracts.view,project,project')", $routes);
        self::assertStringContainsString("->whereIn('purpose', ['preview', 'download'])", $routes);
        self::assertStringContainsString("where('project_id', \$project)", $documentController);
        self::assertStringContainsString('legal_archive_document_id !== (int) $legalDocument', $documentController);
        self::assertStringContainsString('primary_project_id !== $project', $documentController);
        self::assertStringContainsString('legal_archive_document_id !== (int) $legalDocument', $fileController);
        self::assertStringContainsString('found->document_id !== (int) $legalDocument', $fileController);
        self::assertStringContainsString("where('primary_project_id', \$project)", $fileController);
        self::assertStringContainsString('temporaryUrlForContract', $fileController);
        self::assertStringContainsString('temporaryUrlForContract', $downloads);
        self::assertStringContainsString('version->document_id !== (int) $contract->legal_archive_document_id', $downloads);
    }
}
