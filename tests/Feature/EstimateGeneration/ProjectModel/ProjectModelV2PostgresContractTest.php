<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\ProjectModel;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('postgres-contract')]
final class ProjectModelV2PostgresContractTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    #[Test]
    public function consolidated_schema_has_scoped_fact_history_conflicts_decisions_quantities_and_links(): void
    {
        $this->requireEnvironment();

        foreach ([
            'estimate_generation_project_model_entities',
            'estimate_generation_project_model_assertions',
            'estimate_generation_project_model_evidence_bindings',
            'estimate_generation_project_model_fact_evidence',
            'estimate_generation_project_model_fact_projections',
            'estimate_generation_project_model_conflicts',
            'estimate_generation_project_model_conflict_facts',
            'estimate_generation_project_model_corrections',
            'estimate_generation_project_model_derived_quantities',
            'estimate_generation_project_model_derived_operands',
            'estimate_generation_project_model_cross_document_links',
            'estimate_generation_project_model_cross_link_evidence',
        ] as $table) {
            self::assertTrue($this->exists('SELECT to_regclass(?) IS NOT NULL', [$table]), $table.' is missing.');
        }

        foreach (['fact_origin', 'fact_status', 'fact_version', 'fact_value', 'fact_unit'] as $column) {
            self::assertTrue($this->exists(
                'SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?)',
                ['estimate_generation_project_model_assertions', $column],
            ), $column.' is missing.');
        }

        foreach ([
            'eg_pm_facts_scope_uq',
            'eg_pm_fact_projection_one_current_uq',
            'eg_pm_conflict_replay_uq',
            'eg_pm_derived_replay_uq',
            'eg_pm_cross_link_replay_uq',
        ] as $index) {
            self::assertTrue($this->exists('SELECT to_regclass(?) IS NOT NULL', [$index]), $index.' is missing.');
        }

        foreach ([
            'eg_pm_fact_origin_ck',
            'eg_pm_fact_status_ck',
            'eg_pm_decision_actor_ck',
            'eg_pm_fact_projection_current_ck',
            'eg_pm_conflict_status_ck',
            'eg_pm_derived_status_ck',
            'eg_pm_cross_link_distinct_ck',
            'eg_pm_cross_link_evidence_side_ck',
        ] as $constraint) {
            self::assertTrue($this->exists(
                'SELECT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = ?)',
                [$constraint],
            ), $constraint.' is missing.');
        }

        self::assertTrue($this->exists(
            "SELECT pg_get_constraintdef(oid) LIKE '%material%' AND pg_get_constraintdef(oid) LIKE '%equipment%' FROM pg_constraint WHERE conname = 'eg_project_model_entities_kind_ck'",
        ));
    }

    #[Test]
    public function immutable_and_scope_guards_are_installed_for_evidence_conflicts_and_derived_history(): void
    {
        $this->requireEnvironment();

        foreach ([
            'eg_project_model_evidence_binding_guard_trg',
            'eg_pm_fact_evidence_scope_trg',
            'eg_pm_fact_evidence_append_trg',
            'eg_pm_cross_link_evidence_scope_trg',
            'eg_pm_conflict_append_trg',
            'eg_pm_conflict_fact_append_trg',
            'eg_pm_derived_append_trg',
            'eg_pm_derived_operand_append_trg',
        ] as $trigger) {
            self::assertTrue($this->exists(
                'SELECT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = ? AND NOT tgisinternal)',
                [$trigger],
            ), $trigger.' is missing.');
        }
    }

    private function exists(string $sql, array $bindings = []): bool
    {
        $row = DB::selectOne($sql, $bindings);

        return (bool) array_values((array) $row)[0];
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
