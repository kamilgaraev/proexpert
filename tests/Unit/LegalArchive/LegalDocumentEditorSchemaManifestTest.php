<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use PHPUnit\Framework\TestCase;

final class LegalDocumentEditorSchemaManifestTest extends TestCase
{
    public function test_schema_covers_sessions_participants_and_append_only_save_ledger(): void
    {
        $migration = $this->migration('000700_create_legal_document_editor_sessions');

        foreach (['legal_document_editor_sessions', 'legal_document_editor_participants', 'legal_document_editor_saves'] as $table) {
            self::assertStringContainsString("Schema::hasTable('{$table}')", $migration);
            self::assertStringContainsString("'{$table}' => [", $migration);
        }
        foreach (['next_save_generation', 'last_applied_generation', 'final_generation', 'actor_key',
            'required_ability', 'save_generation', 'callback_status', 'replay_hash', 'supersedes_save_id',
            'operation_id', 'lease_owner_hash', 'saved_version_id', 'content_hash', 'terminal'] as $column) {
            self::assertStringContainsString("'{$column}'", $migration);
        }
        self::assertStringContainsString('owner_name !== $relation->expected_owner', $migration);
        self::assertStringContainsString('identity_count', $migration);
        self::assertStringContainsString('sequence_manifest_mismatch', $migration);
        self::assertStringContainsString('primary_key_manifest_mismatch', $migration);
    }

    public function test_index_manifest_is_exact_and_concurrent(): void
    {
        $migration = $this->migration('000710_create_legal_document_editor_session_indexes');

        foreach (['legal_archive_versions_editor_file_ownership_unique', 'legal_editor_sessions_binding_unique',
            'legal_editor_participants_session_unique', 'legal_editor_saves_generation_unique',
            'legal_editor_saves_replay_unique', 'legal_editor_saves_supersedes_unique',
            'legal_editor_saves_saved_version_unique'] as $index) {
            self::assertStringContainsString("'{$index}'", $migration);
        }
        self::assertStringContainsString('CREATE UNIQUE INDEX CONCURRENTLY', $migration);
        self::assertStringContainsString('legal_document_editor_index_set_mismatch', $migration);
        self::assertStringContainsString('pg_get_indexdef', $migration);
        self::assertStringContainsString('indnullsnotdistinct', $migration);
        self::assertStringContainsString('USING btree (editor_session_id)', $migration);
    }

    public function test_constraints_bind_every_version_to_the_same_file_document_and_tenant(): void
    {
        $migration = $this->migration('000720_add_legal_document_editor_session_constraints');
        $binding = '(source_version_id, document_file_id, document_id, organization_id)';
        $saved = '(saved_version_id, document_file_id, document_id, organization_id)';

        self::assertGreaterThanOrEqual(2, substr_count($migration, $binding));
        self::assertGreaterThanOrEqual(2, substr_count($migration, $saved));
        self::assertStringContainsString('(editor_session_id, organization_id, document_id, source_version_id, document_file_id)', $migration);
        self::assertStringContainsString('callback_status IN (2,4,6)', $migration);
        self::assertStringContainsString('terminal = (callback_status IN (2,4))', $migration);
        self::assertStringContainsString("state='completed' AND callback_status=4", $migration);
        self::assertStringContainsString('legal_document_editor_save_terminal_immutable', $migration);
        self::assertStringContainsString('legal_document_editor_save_generation_stale', $migration);
        self::assertStringContainsString('legal_document_editor_save_after_terminal', $migration);
        self::assertStringContainsString("s.state IN ('reserved','processing','completed')", $migration);
        self::assertStringContainsString('legal_document_editor_save_supersession_invalid', $migration);
        self::assertStringContainsString('last_applied_generation', $migration);
        self::assertStringContainsString('final_generation', $migration);
        self::assertStringContainsString("required_ability IN ('view','comment','edit')", $migration);
        self::assertStringContainsString('assertFunctionPredecessor', $migration);
        self::assertStringContainsString('legal_document_editor_constraint_set_mismatch', $migration);
        self::assertStringNotContainsString('$indexMigration->up()', $migration);
        self::assertStringNotContainsString('CONCURRENTLY', $migration);
    }

    public function test_validation_phase_only_verifies_and_validates_existing_objects(): void
    {
        $migration = $this->migration('000730_validate_legal_document_editor_session_constraints');

        self::assertStringContainsString('verifySchemaManifest()', $migration);
        self::assertStringContainsString('assertIndexManifest()', $migration);
        self::assertStringContainsString('assertInstalledManifest()', $migration);
        self::assertStringContainsString('VALIDATE CONSTRAINT', $migration);
        self::assertStringNotContainsString('->up()', $migration);
        self::assertStringContainsString('constraint_missing', $migration);
        self::assertStringContainsString('constraint_not_validated', $migration);
    }

    private function migration(string $suffix): string
    {
        $source = file_get_contents(dirname(__DIR__, 3)."/database/migrations/2026_07_19_{$suffix}.php");
        self::assertIsString($source);

        return $source;
    }
}
