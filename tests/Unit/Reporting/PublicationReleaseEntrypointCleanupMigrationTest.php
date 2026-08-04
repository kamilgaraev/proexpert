<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;

final class PublicationReleaseEntrypointCleanupMigrationTest extends TestCase
{
    public function test_migration_removes_only_release_write_entrypoints_and_preserves_read_side_tables(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3).'/database/migrations/2026_08_05_000001_remove_report_publication_release_entrypoints.php',
        );
        self::assertIsString($source);

        foreach ([
            'report_publication_promote',
            'report_publication_disable',
            'report_publication_configure_feature',
            'report_publication_mark_outbox_delivered',
        ] as $function) {
            self::assertStringContainsString('DROP FUNCTION IF EXISTS public.'.$function, $source);
        }

        self::assertStringNotContainsString('DROP TABLE', $source);
        self::assertStringNotContainsString('report_publications_immutable_guard', $source);
        self::assertStringNotContainsString('report_publication_events_append_only', $source);
    }
}
