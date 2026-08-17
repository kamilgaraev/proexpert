<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Planning;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class Stage5PlanningMigrationContractTest extends TestCase
{
    #[DataProvider('migrations')]
    public function test_forward_only_concurrent_indexes_are_schema_safe_and_retryable(string $file): void
    {
        $source = file_get_contents($file);

        self::assertIsString($source);
        self::assertStringContainsString('implements ForwardOnlyMigration', $source);
        self::assertStringContainsString('public $withinTransaction = false', $source);
        self::assertStringContainsString('indisvalid', $source);
        self::assertStringContainsString('indisready', $source);
        self::assertStringContainsString('pg_get_indexdef', $source);
        self::assertStringContainsString('pg_get_constraintdef', $source);
        self::assertStringContainsString('JOIN pg_class AS constraint_table', $source);
        self::assertStringContainsString('JOIN pg_namespace AS constraint_schema', $source);
        self::assertStringContainsString("constraint_state.contype = 'c'", $source);
        self::assertStringContainsString('canonicalConstraint', $source);
        self::assertStringContainsString('DROP INDEX CONCURRENTLY IF EXISTS', $source);
        self::assertStringContainsString('CREATE INDEX CONCURRENTLY', $source);
        self::assertStringContainsString('CREATE UNIQUE INDEX CONCURRENTLY', $source);
        self::assertStringContainsString('SET search_path TO ', $source);
        self::assertStringContainsString('RESET lock_timeout', $source);
        self::assertStringContainsString('RESET statement_timeout', $source);
        self::assertStringContainsString('RESET search_path', $source);
        self::assertStringContainsString('try {', $source);
        self::assertStringContainsString('} finally {', $source);
        self::assertStringContainsString('throw new RuntimeException', $source);
        self::assertStringNotContainsString('->whereRaw("', $source);
        self::assertStringNotContainsString("SELECT 1 FROM pg_constraint WHERE conname = '", $source);
        self::assertStringContainsString('no destructive rollback', $source);
    }

    public function test_runtime_and_schema_contracts_cover_applicability_and_finding_identity(): void
    {
        $technology = file_get_contents(self::migrations()[0][0]);
        $completeness = file_get_contents(self::migrations()[1][0]);

        self::assertStringContainsString("applicability_status IN ('applicable', 'conditional', 'unavailable')", $technology);
        self::assertStringContainsString('applicability_reasons', $technology);
        self::assertStringContainsString('applicability_evidence', $technology);
        self::assertStringContainsString('finding_stable_key', $completeness);
        self::assertStringContainsString('finding_version', $completeness);
        self::assertStringContainsString("'technology_conditional'", $completeness);
        self::assertStringContainsString("'unresolved'", $completeness);
    }

    #[DataProvider('migrations')]
    public function test_check_comparison_accepts_realistic_postgres_deparsed_in_form(string $file): void
    {
        $expected = "CHECK (applicability_status IN ('applicable', 'conditional', 'unavailable'))";
        $deparsed = <<<'SQL'
CHECK (((applicability_status)::text = ANY ((ARRAY['applicable'::character varying, 'conditional'::character varying, 'unavailable'::character varying])::text[])))
SQL;

        self::assertSame($this->canonicalConstraint($file, $expected), $this->canonicalConstraint($file, $deparsed));
    }

    #[DataProvider('migrations')]
    public function test_check_comparison_handles_quotes_casts_whitespace_parentheses_and_rejects_wrong_definition(string $file): void
    {
        $expected = "CHECK (status IN ('unknown', 'satisfied') AND jsonb_typeof(\"limitations\") = 'array')";
        $deparsed = " CHECK ( (status)::character varying = ANY (ARRAY['unknown'::text, 'satisfied'::text]::character varying[]) AND jsonb_typeof(limitations) = 'array'::text ) ";

        self::assertSame($this->canonicalConstraint($file, $expected), $this->canonicalConstraint($file, $deparsed));
        self::assertNotSame(
            $this->canonicalConstraint($file, $expected),
            $this->canonicalConstraint($file, "CHECK (status IN ('unknown', 'excluded'))"),
        );
    }

    #[DataProvider('migrations')]
    public function test_check_comparison_accepts_postgres_boolean_precedence_deparsing(string $file): void
    {
        $expected = 'CHECK (((is_current AND invalidated_at IS NULL) OR (NOT is_current AND invalidated_at IS NOT NULL)))';
        $deparsed = 'CHECK ((is_current AND invalidated_at IS NULL OR NOT is_current AND invalidated_at IS NOT NULL))';

        self::assertSame(
            $this->canonicalConstraint($file, $expected),
            $this->canonicalConstraint($file, $deparsed),
        );
    }

    #[DataProvider('migrations')]
    public function test_check_comparison_accepts_postgres_between_deparsing(string $file): void
    {
        $expected = 'CHECK (length(btrim(question)) BETWEEN 1 AND 4000)';
        $deparsed = 'CHECK (length(btrim(question)) >= 1 AND length(btrim(question)) <= 4000)';

        self::assertSame(
            $this->canonicalConstraint($file, $expected),
            $this->canonicalConstraint($file, $deparsed),
        );
    }

    #[DataProvider('migrations')]
    public function test_check_comparison_accepts_postgres_signed_integer_deparsing(string $file): void
    {
        $expected = 'CHECK (score BETWEEN -1000 AND 1000)';
        $deparsed = "CHECK (score >= '-1000'::integer AND score <= 1000)";

        self::assertSame(
            $this->canonicalConstraint($file, $expected),
            $this->canonicalConstraint($file, $deparsed),
        );
    }

    #[DataProvider('migrations')]
    public function test_check_comparison_accepts_postgres_atomic_boolean_grouping(string $file): void
    {
        $expected = "CHECK (status IN ('ready', 'blocked') AND score >= 0)";
        $deparsed = "CHECK ((status IN ('ready', 'blocked')) AND score >= 0)";

        self::assertSame(
            $this->canonicalConstraint($file, $expected),
            $this->canonicalConstraint($file, $deparsed),
        );
    }

    #[DataProvider('migrations')]
    public function test_check_comparison_accepts_postgres_numeric_constant_casts(string $file): void
    {
        $expected = 'CHECK (confidence BETWEEN 0 AND 1)';
        $deparsed = 'CHECK (confidence >= 0::numeric AND confidence <= 1::numeric)';

        self::assertSame(
            $this->canonicalConstraint($file, $expected),
            $this->canonicalConstraint($file, $deparsed),
        );
    }

    #[DataProvider('migrations')]
    public function test_check_comparison_preserves_string_literal_bytes(string $file): void
    {
        $expected = "CHECK (status IN ('unknown', 'owner''s review', E'line\\\\nitem'))";

        self::assertSame(
            $this->canonicalConstraint($file, $expected),
            $this->canonicalConstraint(
                $file,
                " check (((status)::text = any ((array['unknown'::varchar, 'owner''s review'::text, E'line\\\\nitem'::text])::text[]))) ",
            ),
        );
        self::assertNotSame(
            $this->canonicalConstraint($file, $expected),
            $this->canonicalConstraint($file, "CHECK (status IN ('UNKNOWN', 'owner''s review', E'line\\\\nitem'))"),
        );
        self::assertNotSame(
            $this->canonicalConstraint($file, $expected),
            $this->canonicalConstraint($file, "CHECK (status IN ('unknown', 'owner''sreview', E'line\\\\nitem'))"),
        );
        self::assertNotSame(
            $this->canonicalConstraint($file, $expected),
            $this->canonicalConstraint($file, "CHECK (status IN ('unknown', 'owner''s review', E'line\\nitem'))"),
        );
    }

    #[DataProvider('migrations')]
    public function test_check_comparison_preserves_semantically_observable_structure(string $file): void
    {
        $expected = "CHECK (status IN ('unknown', 'satisfied'))";
        $different = [
            "CHECK (status IN ('satisfied', 'unknown'))",
            "CHECK (status IN ('unknown', 'unknown', 'satisfied'))",
            "CHECK (status IN ('unknown', 'satisfied', NULL))",
            "CHECK (NOT status IN ('unknown', 'satisfied'))",
            "CHECK (status IN ('unknown', 'satisfied') AND enabled)",
            "CHECK (status IN ('unknown', 'satisfied') OR enabled)",
            "CHECK (status NOT IN ('unknown', 'satisfied'))",
            "CHECK ((status::integer) IN ('unknown', 'satisfied'))",
        ];

        foreach ($different as $definition) {
            self::assertNotSame(
                $this->canonicalConstraint($file, $expected),
                $this->canonicalConstraint($file, $definition),
                $definition,
            );
        }
    }

    #[DataProvider('migrations')]
    public function test_check_comparison_fails_closed_for_malformed_or_unsupported_sql(string $file): void
    {
        $expected = "CHECK (status IN ('unknown', 'satisfied'))";

        foreach ([
            "CHECK (status IN ('unknown', 'satisfied')",
            'CHECK (status IN ($$unknown$$, \'satisfied\'))',
            "CHECK (status IN ('unknown' /* hidden */, 'satisfied'))",
            "status IN ('unknown', 'satisfied')",
        ] as $definition) {
            self::assertStringStartsWith('incompatible:', $this->canonicalConstraint($file, $definition));
            self::assertNotSame(
                $this->canonicalConstraint($file, $expected),
                $this->canonicalConstraint($file, $definition),
                $definition,
            );
        }
    }

    public static function migrations(): array
    {
        $root = dirname(__DIR__, 4);

        return [
            [$root.'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_08_11_000700_create_technology_planning_projections.php'],
            [$root.'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_08_11_000710_create_completeness_planning_projections.php'],
        ];
    }

    private function canonicalConstraint(string $file, string $definition): string
    {
        $migration = require $file;
        $method = new ReflectionMethod($migration, 'canonicalConstraint');

        return $method->invoke($migration, $definition);
    }
}
