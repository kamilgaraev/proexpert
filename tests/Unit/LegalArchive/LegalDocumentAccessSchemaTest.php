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
        self::assertStringContainsString('legal_document_access_active_subject_unique', $indexes);
        self::assertStringContainsString('NOT VALID', $constraints);
        self::assertStringContainsString('FOREIGN KEY (document_id, organization_id)', $constraints);
        self::assertStringContainsString('FOREIGN KEY (document_version_id, document_id, organization_id)', $constraints);
        self::assertStringContainsString('legal_document_comments_anchor_check', $constraints);
        self::assertStringContainsString('legal_document_party_immutable_guard', $constraints);
        self::assertStringContainsString('legal_document_access_grant_guard', $constraints);
        self::assertStringContainsString('legal_document_comment_guard', $constraints);
        self::assertStringContainsString("str_replace(['=anyarray[', ']']", $constraints);
        self::assertStringContainsString('VALIDATE CONSTRAINT', $validate);
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

    public function test_read_endpoints_load_the_owner_document_before_object_authorization(): void
    {
        $root = __DIR__.'/../../../';
        $controller = file_get_contents($root.'app/Http/Controllers/Api/V1/Admin/LegalArchiveController.php');
        self::assertIsString($controller);
        self::assertSame(2, substr_count($controller, 'findForAuthorization((int) $document)'));
        self::assertStringContainsString('$this->access->authorize($actor, $found, \'view\');', $controller);
    }
}
