<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use PHPUnit\Framework\TestCase;

final class LegalDocumentEditorGuardReconciliationMigrationTest extends TestCase
{
    public function test_it_reconciles_only_the_known_legacy_terminal_save_guard(): void
    {
        $migration = file_get_contents(
            dirname(__DIR__, 3).'/database/migrations/2026_07_22_000010_reconcile_legal_document_editor_save_guard.php',
        );

        self::assertIsString($migration);
        self::assertStringContainsString('legacyBody', $migration);
        self::assertStringContainsString("s.editor_session_id=NEW.editor_session_id AND s.terminal)", $migration);
        self::assertStringContainsString("s.state IN ('reserved','processing','completed')", $migration);
        self::assertStringContainsString('CREATE OR REPLACE FUNCTION legal_document_editor_save_guard()', $migration);
        self::assertStringContainsString('legal_document_editor_save_guard_descriptor_mismatch', $migration);
        self::assertStringContainsString('count($actual) !== 1', $migration);
        self::assertStringContainsString('$configuration === "search_path=pg_catalog, {$schema}"', $migration);
    }
}
