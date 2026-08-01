<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\SavedViews;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

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
        self::assertStringContainsString("(jsonb_typeof(content_json) = 'object') IS TRUE", $source);
        self::assertStringContainsString("content_json ?& ARRAY['schema_version', 'report_code', 'contract_version'", $source);
        self::assertStringContainsString("(jsonb_typeof(content_json -> 'schema_version') = 'number') IS TRUE", $source);
        self::assertStringContainsString("((content_json ->> 'schema_version') = presentation_schema_version::text) IS TRUE", $source);
        self::assertStringContainsString("(jsonb_typeof(content_json -> 'report_code') = 'string') IS TRUE", $source);
        self::assertStringContainsString("((content_json ->> 'report_code') = report_code) IS TRUE", $source);
        self::assertStringContainsString("(jsonb_typeof(content_json -> 'contract_version') = 'string') IS TRUE", $source);
        self::assertStringContainsString("((content_json ->> 'contract_version') = contract_version) IS TRUE", $source);
        self::assertStringContainsString("btrim(contract_version, ' ' || chr(9) || chr(10) || chr(13) || chr(11)) <> ''", $source);
        self::assertStringContainsString("btrim(content_json ->> 'name', ' ' || chr(9) || chr(10) || chr(13) || chr(11)) <> ''", $source);
        self::assertStringContainsString("(content_json -> 'sort' ->> 'field') ~ '^[a-z][a-z0-9_]{0,63}$'", $source);
        self::assertStringContainsString('report_saved_view_version_columns_are_valid', $source);
        self::assertStringContainsString("jsonb_typeof(column_value) <> 'string'", $source);
        self::assertStringContainsString("count(DISTINCT column_value #>> '{}')", $source);
        self::assertStringContainsString('DROP FUNCTION IF EXISTS report_saved_view_version_columns_are_valid(jsonb)', $source);
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
        self::assertStringContainsString('test_invalid_content_binding_is_rejected_before_it_can_be_frozen', $source);
        self::assertStringContainsString("yield 'empty sort field'", $source);
        self::assertStringContainsString("yield 'non-string column'", $source);
        self::assertStringContainsString("yield 'duplicate columns'", $source);
        self::assertStringContainsString("yield 'tab-only name'", $source);
        self::assertStringContainsString("yield 'newline-only contract version'", $source);
        self::assertStringContainsString("yield 'extra top-level key'", $source);
        self::assertStringContainsString("yield 'non-container filters'", $source);
    }

    public function test_existing_postgres_workflow_executes_the_saved_view_version_gate_fail_closed(): void
    {
        $workflow = Yaml::parseFile(dirname(__DIR__, 4).'/.github/workflows/notification-concurrency.yml');
        self::assertIsArray($workflow);

        $job = $workflow['jobs']['report-saved-view-version-postgres-contract'] ?? null;
        self::assertIsArray($job);
        self::assertSame(
            'most_report_saved_view_version_testing',
            $job['services']['postgres']['env']['POSTGRES_DB'] ?? null,
        );
        self::assertSame('testing', $job['env']['APP_ENV'] ?? null);
        self::assertSame(true, $job['env']['CI'] ?? null);
        self::assertSame(
            'most_report_saved_view_version_testing',
            $job['env']['DB_DATABASE'] ?? null,
        );
        self::assertSame(1, $job['env']['REPORT_SAVED_VIEW_VERSION_POSTGRES_TESTS'] ?? null);

        $steps = $job['steps'] ?? null;
        self::assertIsArray($steps);
        $commands = implode("\n", array_map(
            static fn (array $step): string => is_string($step['run'] ?? null) ? $step['run'] : '',
            $steps,
        ));

        self::assertStringContainsString('git rev-parse HEAD', $commands);
        self::assertStringContainsString('$GITHUB_SHA', $commands);
        self::assertStringContainsString('php artisan migrate:fresh --force', $commands);
        self::assertStringContainsString('ReportSavedViewVersionPostgresTest.php', $commands);
        self::assertStringContainsString('--group=postgresql', $commands);
        self::assertStringContainsString('--fail-on-skipped', $commands);
    }
}
