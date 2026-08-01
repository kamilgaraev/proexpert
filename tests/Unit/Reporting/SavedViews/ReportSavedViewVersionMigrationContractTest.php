<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\SavedViews;

use PHPUnit\Framework\TestCase;

final class ReportSavedViewVersionMigrationContractTest extends TestCase
{
    public function test_migration_declares_the_storage_invariants_exercised_by_the_postgres_gate(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4).'/database/migrations/2026_08_01_000001_create_report_saved_view_versions.php');
        self::assertIsString($source);

        self::assertStringContainsString("timestampTz('created_at', 6)", $source);
        self::assertStringContainsString("unsignedSmallInteger('presentation_schema_version')", $source);
        self::assertStringContainsString("['saved_view_id', 'organization_id', 'owner_id']", $source);
        self::assertStringContainsString('BEFORE UPDATE OR DELETE', $source);
        self::assertStringContainsString("ERRCODE = '55000'", $source);
        self::assertStringContainsString('DROP TRIGGER IF EXISTS report_saved_view_versions_immutable_guard', $source);
        self::assertStringContainsString('DROP FUNCTION IF EXISTS reject_report_saved_view_version_mutation()', $source);
        self::assertStringNotContainsString('report_saved_view_versions_content_unique', $source);
    }

    public function test_postgres_gate_is_opt_in_before_any_connection_and_covers_real_persistence(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/Feature/Reporting/SavedViews/ReportSavedViewVersionPostgresTest.php');
        self::assertIsString($source);

        $guard = strpos($source, "getenv('REPORT_SAVED_VIEW_VERSION_POSTGRES_TESTS') !== '1'");
        $connection = strpos($source, 'DB::connection()');
        self::assertIsInt($guard);
        self::assertIsInt($connection);
        self::assertLessThan($connection, $guard);
        self::assertStringContainsString("preg_match('/_(?:test|testing)$/D'", $source);
        self::assertStringContainsString('test_update_and_delete_are_rejected', $source);
        self::assertStringContainsString('test_version_tenant_must_match_its_head', $source);
        self::assertStringContainsString('test_version_owner_must_match_its_head', $source);
        self::assertStringContainsString('test_restore_can_append_a_previously_seen_content_hash', $source);
        self::assertStringContainsString('test_append_and_find_preserve_microseconds', $source);
    }
}
