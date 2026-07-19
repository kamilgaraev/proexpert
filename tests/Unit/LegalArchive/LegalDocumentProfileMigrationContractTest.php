<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use PHPUnit\Framework\TestCase;

final class LegalDocumentProfileMigrationContractTest extends TestCase
{
    private const MIGRATION_DIRECTORY = __DIR__.'/../../../database/migrations';

    public function test_online_rollout_is_split_into_ordered_idempotent_phases(): void
    {
        $schema = $this->migration('2026_07_19_000100_create_legal_document_profiles_and_extend_dossiers.php');
        $indexes = $this->migration('2026_07_19_000110_create_legal_document_profile_indexes.php');
        $ownership = $this->migration('2026_07_19_000120_add_legal_document_profile_constraints.php');
        $validation = $this->migration('2026_07_19_000130_validate_legal_document_profile_constraints.php');

        self::assertStringContainsString("->unsignedInteger('lock_version')->nullable()", $schema);
        self::assertStringContainsString("->jsonb('structured_fields')->nullable()", $schema);
        self::assertStringContainsString('NOT VALID', $schema);
        self::assertStringNotContainsString('->index(', $schema);
        self::assertStringNotContainsString('->unique(', $schema);

        self::assertStringContainsString('public $withinTransaction = false;', $indexes);
        self::assertStringContainsString('CREATE INDEX CONCURRENTLY IF NOT EXISTS', $indexes);
        self::assertStringContainsString('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS', $indexes);
        self::assertStringContainsString('DROP INDEX CONCURRENTLY IF EXISTS', $indexes);
        self::assertStringContainsString('pg_index', $indexes);
        self::assertStringContainsString('indisvalid', $indexes);

        self::assertStringContainsString('NOT VALID', $ownership);
        self::assertStringNotContainsString('VALIDATE CONSTRAINT', $ownership);
        self::assertStringContainsString('VALIDATE CONSTRAINT', $validation);
    }

    public function test_current_primary_version_has_composite_document_and_tenant_ownership_invariant(): void
    {
        $indexes = $this->migration('2026_07_19_000110_create_legal_document_profile_indexes.php');
        $ownership = $this->migration('2026_07_19_000120_add_legal_document_profile_constraints.php');
        $model = file_get_contents(__DIR__.'/../../../app/BusinessModules/Features/LegalArchive/Models/LegalArchiveDocument.php');

        self::assertIsString($model);
        self::assertStringContainsString(
            'ON legal_archive_document_versions (id, document_id, organization_id)',
            $indexes,
        );
        self::assertStringContainsString(
            'FOREIGN KEY (current_primary_version_id, id, organization_id)',
            $ownership,
        );
        self::assertStringContainsString(
            'REFERENCES legal_archive_document_versions (id, document_id, organization_id)',
            $ownership,
        );
        self::assertStringContainsString('ON DELETE SET NULL (current_primary_version_id) NOT VALID', $ownership);
        self::assertStringContainsString(
            "whereColumn('owner.organization_id', 'legal_archive_document_versions.organization_id')",
            $model,
        );
        self::assertStringContainsString(
            "whereColumn('owner.current_primary_version_id', 'legal_archive_document_versions.id')",
            $model,
        );
        self::assertStringNotContainsString('$this->organization_id', $model);
    }

    public function test_confidentiality_constraint_allows_legacy_null_and_whitelists_values(): void
    {
        $schema = $this->migration('2026_07_19_000100_create_legal_document_profiles_and_extend_dossiers.php');

        self::assertStringContainsString('legal_docs_confidentiality_check', $schema);
        self::assertStringContainsString('confidentiality_level IS NULL OR confidentiality_level IN', $schema);
        self::assertStringContainsString("'public', 'internal', 'restricted', 'secret'", $schema);
    }

    private function migration(string $filename): string
    {
        $source = file_get_contents(self::MIGRATION_DIRECTORY.'/'.$filename);

        self::assertIsString($source, $filename);

        return $source;
    }
}
