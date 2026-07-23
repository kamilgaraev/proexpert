<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use PHPUnit\Framework\TestCase;

final class LegalDocumentEditorFailedSaveCompletionMigrationTest extends TestCase
{
    public function test_it_allows_only_a_failed_terminal_save_to_be_superseded_on_completion(): void
    {
        $migration = file_get_contents(
            dirname(__DIR__, 3).'/database/migrations/2026_07_22_000011_reconcile_legal_document_editor_failed_save_completion.php',
        );

        self::assertIsString($migration);
        self::assertStringContainsString('currentBody', $migration);
        self::assertStringContainsString('correctedBody', $migration);
        self::assertStringContainsString("s.state IN ('reserved','processing','completed')", $migration);
        self::assertStringContainsString('legal_document_editor_save_completion_guard_descriptor_mismatch', $migration);
        self::assertStringContainsString('CREATE OR REPLACE FUNCTION legal_document_editor_save_guard()', $migration);
        self::assertStringContainsString('count($actual) !== 1', $migration);
        self::assertStringContainsString('$configuration === "search_path=pg_catalog, {$schema}"', $migration);
        self::assertStringContainsString(
            'WHERE s.editor_session_id=NEW.editor_session_id AND s.id<>NEW.id AND s.terminal\\n        AND s.save_generation < NEW.save_generation',
            $migration,
        );
        self::assertStringContainsString(
            'WHERE s.editor_session_id=NEW.editor_session_id AND s.id<>NEW.id AND s.terminal\\n        AND s.state IN (\'reserved\',\'processing\',\'completed\')\\n        AND s.save_generation < NEW.save_generation',
            $migration,
        );
        self::assertStringContainsString('$replacements !== 1', $migration);
        self::assertStringContainsString('if (! $this->matchesDescriptor($actual, $this->currentBody(), $schema))', $migration);
    }
}
