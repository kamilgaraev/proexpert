<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use PHPUnit\Framework\TestCase;

final class LegalDocumentSourceLifecycleMigrationTest extends TestCase
{
    public function test_lifecycle_and_fingerprint_are_added_online_with_forward_validation(): void
    {
        $schema = $this->migration('2026_07_19_000140_add_legal_document_source_create_lifecycle.php');
        $validation = $this->migration('2026_07_19_000141_validate_legal_document_source_create_lifecycle.php');

        self::assertStringContainsString("->string('source_create_status', 16)->nullable()->default('completed')", $schema);
        self::assertStringContainsString("->char('source_request_fingerprint', 64)->nullable()", $schema);
        self::assertStringContainsString('NOT VALID', $schema);
        self::assertStringContainsString('legal_docs_source_create_status_not_null', $schema);
        self::assertStringContainsString('legal_docs_source_create_coherence_check', $schema);
        self::assertStringContainsString('public $withinTransaction = false;', $validation);
        self::assertStringContainsString('LIMIT 1000', $validation);
        self::assertStringContainsString('VALIDATE CONSTRAINT legal_docs_source_create_status_check', $validation);
        self::assertStringContainsString('ALTER COLUMN source_create_status SET NOT NULL', $validation);
    }

    public function test_legacy_source_index_is_replaced_by_exact_pg14_descriptors(): void
    {
        $legacy = $this->migration('2026_07_19_000110_create_legal_document_profile_indexes.php');
        $replacement = $this->migration('2026_07_19_000150_replace_legal_document_source_indexes.php');
        $access = $this->migration('2026_07_19_000510_create_legal_document_access_indexes.php');

        self::assertStringContainsString('legal_docs_source_idempotency_unique', $legacy);
        self::assertStringContainsString('DROP INDEX CONCURRENTLY IF EXISTS legal_docs_source_idempotency_unique', $replacement);
        self::assertStringContainsString('legal_documents_source_identity_unique', $replacement);
        self::assertStringContainsString('legal_documents_source_command_unique', $replacement);
        self::assertStringContainsString('COALESCE(created_by_user_id, 0::bigint)', $replacement);
        self::assertStringContainsString('pg_index', $replacement);
        self::assertStringContainsString('indnullsnotdistinct', $replacement);
        self::assertStringContainsString('legal_documents_source_command_unique', $access);
        self::assertLessThan(
            strpos($replacement, 'DROP INDEX CONCURRENTLY IF EXISTS legal_docs_source_idempotency_unique'),
            strpos($replacement, 'foreach ($this->descriptors()'),
        );
    }

    private function migration(string $name): string
    {
        $source = file_get_contents(__DIR__.'/../../../database/migrations/'.$name);
        self::assertIsString($source);

        return $source;
    }
}
