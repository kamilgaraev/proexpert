<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Planning;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

#[Group('postgres-contract')]
final class Stage5PlanningPostgresContractTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    #[Test]
    public function unpublished_stage_five_migrations_are_retry_safe_and_indexes_are_ready(): void
    {
        $this->requireEnvironment();
        foreach (['000700_create_technology_planning_projections', '000710_create_completeness_planning_projections'] as $suffix) {
            $file = glob(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/*_'.$suffix.'.php');
            self::assertCount(1, $file);
            (require $file[0])->up();
            (require $file[0])->up();
        }
        foreach ([
            'eg_tech_plan_current_idx',
            'eg_tech_plan_one_current_uq',
            'eg_completeness_current_idx',
            'eg_completeness_one_current_uq',
        ] as $index) {
            $state = DB::selectOne(<<<'SQL'
SELECT index_state.indisvalid, index_state.indisready, pg_get_indexdef(index_state.indexrelid) AS definition
FROM pg_index AS index_state
JOIN pg_class AS index_class ON index_class.oid = index_state.indexrelid
JOIN pg_namespace AS index_schema ON index_schema.oid = index_class.relnamespace
WHERE index_schema.nspname = current_schema() AND index_class.relname = ?
SQL, [$index]);
            self::assertNotNull($state, $index.' is missing.');
            self::assertTrue((bool) $state->indisvalid, $index.' is invalid.');
            self::assertTrue((bool) $state->indisready, $index.' is not ready.');
            self::assertStringContainsString('estimate_generation_', (string) $state->definition);
        }
        $constraint = DB::selectOne(<<<'SQL'
SELECT pg_get_constraintdef(constraint_state.oid, true) AS definition
FROM pg_constraint AS constraint_state
JOIN pg_class AS constraint_table ON constraint_table.oid = constraint_state.conrelid
JOIN pg_namespace AS constraint_schema ON constraint_schema.oid = constraint_table.relnamespace
WHERE constraint_schema.nspname = current_schema()
  AND constraint_table.relname = 'estimate_generation_technology_recommendation_options'
  AND constraint_state.conname = 'eg_tech_option_payload_ck'
  AND constraint_state.contype = 'c'
SQL);
        self::assertNotNull($constraint);
        self::assertStringContainsString('ANY (ARRAY[', (string) $constraint->definition);
    }

    #[Test]
    public function partial_unique_indexes_reject_two_current_projections_in_the_same_scope(): void
    {
        $this->requireEnvironment();
        DB::beginTransaction();
        try {
            $base = [
                'organization_id' => 910001,
                'project_id' => 920001,
                'session_id' => 930001,
                'source_version' => 'sha256:'.str_repeat('a', 64),
                'input_fingerprint' => str_repeat('b', 64),
                'catalog_version' => 'contract-v1',
                'catalog_hash' => str_repeat('c', 64),
                'result_fingerprint' => str_repeat('d', 64),
                'limitations' => '[]',
                'is_current' => true,
                'created_at' => now(),
            ];
            DB::table('estimate_generation_technology_planning_runs')->insert($base);
            $duplicate = $base;
            $duplicate['input_fingerprint'] = str_repeat('e', 64);
            $duplicate['result_fingerprint'] = str_repeat('f', 64);

            $this->expectException(QueryException::class);
            DB::table('estimate_generation_technology_planning_runs')->insert($duplicate);
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function same_named_constraint_in_another_schema_does_not_hide_the_target_constraint(): void
    {
        $this->requireEnvironment();
        DB::beginTransaction();
        try {
            DB::statement('CREATE SCHEMA IF NOT EXISTS stage5_collision');
            DB::statement('CREATE TABLE IF NOT EXISTS stage5_collision.constraint_holder (value integer)');
            DB::statement('ALTER TABLE stage5_collision.constraint_holder DROP CONSTRAINT IF EXISTS eg_tech_plan_scope_ck');
            DB::statement('ALTER TABLE stage5_collision.constraint_holder ADD CONSTRAINT eg_tech_plan_scope_ck CHECK (value >= 0)');
            DB::statement('CREATE TABLE IF NOT EXISTS constraint_holder (value integer)');
            DB::statement('ALTER TABLE constraint_holder DROP CONSTRAINT IF EXISTS eg_tech_plan_scope_ck');
            DB::statement('ALTER TABLE constraint_holder ADD CONSTRAINT eg_tech_plan_scope_ck CHECK (value < 0)');
            DB::statement('ALTER TABLE estimate_generation_technology_planning_runs DROP CONSTRAINT IF EXISTS eg_tech_plan_scope_ck');

            $file = glob(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/*_000700_create_technology_planning_projections.php');
            self::assertCount(1, $file);
            (require $file[0])->up();

            self::assertNotNull(DB::selectOne(<<<'SQL'
SELECT constraint_state.oid
FROM pg_constraint AS constraint_state
JOIN pg_class AS constraint_table ON constraint_table.oid = constraint_state.conrelid
JOIN pg_namespace AS constraint_schema ON constraint_schema.oid = constraint_table.relnamespace
WHERE constraint_schema.nspname = current_schema()
  AND constraint_table.relname = 'estimate_generation_technology_planning_runs'
  AND constraint_state.conname = 'eg_tech_plan_scope_ck'
  AND constraint_state.contype = 'c'
SQL));
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function wrong_target_constraint_definition_fails_fast(): void
    {
        $this->requireEnvironment();
        DB::beginTransaction();
        try {
            DB::statement('ALTER TABLE estimate_generation_technology_planning_runs DROP CONSTRAINT IF EXISTS eg_tech_plan_scope_ck');
            DB::statement('ALTER TABLE estimate_generation_technology_planning_runs ADD CONSTRAINT eg_tech_plan_scope_ck CHECK (organization_id > 0)');
            $file = glob(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/*_000700_create_technology_planning_projections.php');
            self::assertCount(1, $file);

            $this->expectException(RuntimeException::class);
            (require $file[0])->up();
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    #[DataProvider('wrongLiteralDefinitions')]
    public function wrong_literal_bytes_in_target_constraint_fail_fast(string $check): void
    {
        $this->requireEnvironment();
        DB::beginTransaction();
        try {
            DB::statement('ALTER TABLE estimate_generation_completeness_findings '
                .'DROP CONSTRAINT IF EXISTS eg_completeness_finding_ck');
            DB::statement('ALTER TABLE estimate_generation_completeness_findings '
                .'ADD CONSTRAINT eg_completeness_finding_ck CHECK ('.$check.')');
            $file = glob(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/'
                .'*_000710_create_completeness_planning_projections.php');
            self::assertCount(1, $file);

            $this->expectException(RuntimeException::class);
            (require $file[0])->up();
        } finally {
            DB::rollBack();
        }
    }

    public static function wrongLiteralDefinitions(): array
    {
        $template = <<<'SQL'
finding_stable_key ~ '^[a-f0-9]{64}$' AND finding_version BETWEEN 1 AND 65535
AND classification IN (%s, 'technology_required', 'optional_recommendation', 'technology_conditional', 'not_applicable')
AND status IN (%s, 'unresolved', 'proven_missing', 'satisfied', 'not_applicable', 'excluded')
AND confidence BETWEEN 0 AND 1 AND octet_length(evidence_fact_ids::text) <= 65536
AND octet_length(related_entity_ids::text) <= 32768 AND octet_length(related_fact_types::text) <= 32768
AND jsonb_typeof(applicability) = 'object' AND octet_length(applicability::text) <= 65536
AND octet_length(exclusion_policy::text) <= 32768
AND (exclusion_decision IS NULL OR octet_length(exclusion_decision::text) <= 32768)
SQL;

        return [
            'literal case' => [sprintf($template, "'document_missing'", "'UNKNOWN'")],
            'literal space' => [sprintf($template, "'document missing'", "'unknown'")],
        ];
    }

    private function requireEnvironment(): void
    {
        if (getenv('RUN_ESTIMATE_GENERATION_POSTGRES_CONTRACT') !== '1'
            || DB::getDriverName() !== 'pgsql'
            || ! str_ends_with((string) DB::getDatabaseName(), '_contract')) {
            self::markTestSkipped('Requires explicit isolated PostgreSQL contract environment.');
        }
    }
}
