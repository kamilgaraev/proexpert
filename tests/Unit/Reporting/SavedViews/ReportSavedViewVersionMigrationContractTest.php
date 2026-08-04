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
        self::assertStringContainsString('report_saved_view_version_json_is_php_hydratable', $source);
        self::assertStringContainsString('report_saved_view_version_json_is_php_hydratable(content_json) IS TRUE', $source);
        self::assertStringContainsString(
            "< '179769313486231580793728971405303415079934132710037826936173778980444968292764750946649017977587207096330286416692887910946555547851940402630657488671505820681908902000708383676273854845817711531764475730270069855571366959622842914819860834936475292719074168444365510704342711559699508093042880177904174497792'::numeric",
            $source,
        );
        self::assertStringContainsString('current_depth > 512', $source);
        self::assertStringContainsString("COLLATE \"C\") ~ '\\A[a-z][a-z0-9_]{0,63}\\Z'", $source);
        self::assertStringContainsString('report_saved_view_version_columns_are_valid', $source);
        self::assertStringContainsString("jsonb_typeof(column_value) <> 'string'", $source);
        self::assertStringContainsString(
            "count(DISTINCT (column_value #>> '{}') COLLATE \"C\")",
            $source,
        );
        self::assertStringContainsString('DROP FUNCTION IF EXISTS report_saved_view_version_columns_are_valid(jsonb)', $source);
        self::assertStringContainsString(
            'DROP FUNCTION IF EXISTS report_saved_view_version_json_is_php_hydratable(jsonb, integer)',
            $source,
        );
    }

    public function test_php_json_decoder_numeric_boundary_matches_the_database_contract(): void
    {
        $lastFiniteMagnitude = '179769313486231580793728971405303415079934132710037826936173778980444968292764750946649017977587207096330286416692887910946555547851940402630657488671505820681908902000708383676273854845817711531764475730270069855571366959622842914819860834936475292719074168444365510704342711559699508093042880177904174497791';
        $firstOverflowMagnitude = '179769313486231580793728971405303415079934132710037826936173778980444968292764750946649017977587207096330286416692887910946555547851940402630657488671505820681908902000708383676273854845817711531764475730270069855571366959622842914819860834936475292719074168444365510704342711559699508093042880177904174497792';
        $maximumFinite = json_decode(
            '{"nested":{"values":[1.7976931348623158e308]}}',
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $firstNegativeOverflow = json_decode(
            '{"nested":{"values":[-1.7976931348623159e308]}}',
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $exactLastFinite = json_decode(
            '{"nested":{"value":'.$lastFiniteMagnitude.'}}',
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $exactFirstNegativeOverflow = json_decode(
            '{"nested":{"value":-'.$firstOverflowMagnitude.'}}',
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($maximumFinite);
        self::assertSame(PHP_FLOAT_MAX, $maximumFinite['nested']['values'][0]);
        self::assertIsArray($firstNegativeOverflow);
        self::assertSame(-INF, $firstNegativeOverflow['nested']['values'][0]);
        self::assertIsArray($exactLastFinite);
        self::assertSame(PHP_FLOAT_MAX, $exactLastFinite['nested']['value']);
        self::assertIsArray($exactFirstNegativeOverflow);
        self::assertSame(-INF, $exactFirstNegativeOverflow['nested']['value']);
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
        self::assertStringContainsString('test_recursive_json_contract_accepts_php_hydratable_values', $source);
        self::assertStringContainsString('test_recursive_json_contract_accepts_php_maximum_finite_positive_number', $source);
        self::assertStringContainsString("yield 'empty sort field'", $source);
        self::assertStringContainsString("yield 'non-string column'", $source);
        self::assertStringContainsString("yield 'duplicate columns'", $source);
        self::assertStringContainsString("yield 'tab-only name'", $source);
        self::assertStringContainsString("yield 'newline-only contract version'", $source);
        self::assertStringContainsString("yield 'extra top-level key'", $source);
        self::assertStringContainsString("yield 'non-container filters'", $source);
        self::assertStringContainsString("yield 'huge filter number'", $source);
        self::assertStringContainsString("yield 'nested huge comparison number'", $source);
        self::assertStringContainsString("yield 'nested first negative PHP overflow'", $source);
        self::assertStringContainsString("yield 'sort field with trailing line feed'", $source);
        self::assertStringContainsString("yield 'non ASCII sort field'", $source);
        self::assertStringContainsString("yield 'non ASCII column identifier'", $source);
        self::assertStringContainsString("yield 'non ASCII report code'", $source);
    }

}
