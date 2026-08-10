<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\ProjectModel;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelLocatorFingerprint;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelValueFingerprint;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
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
            'estimate_generation_project_understanding_runs',
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
            'eg_pm_understanding_replay_uq',
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
            "SELECT pg_get_constraintdef(oid) LIKE '%material%' AND pg_get_constraintdef(oid) LIKE '%equipment%' FROM pg_constraint WHERE conname = 'eg_pm_entities_kind_v2_ck'",
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

    #[Test]
    public function entity_guard_rejects_invalid_payload_for_every_supported_kind_and_unknown_or_oversized_fields(): void
    {
        $this->requireEnvironment();
        $model = DB::table('estimate_generation_building_models')->orderBy('id')->first();
        if ($model === null) {
            self::markTestSkipped('Requires one isolated building-model fixture.');
        }
        $cases = [
            ['room', ['kind' => 'room', 'polygon' => [[0, 0], [1, 1]]]],
            ['room', ['kind' => 'room', 'area_m2' => -1]],
            ['wall', ['kind' => 'wall', 'start' => [0], 'end' => [1, 1]]],
            ['opening', ['kind' => 'opening', 'wall_key' => 'wall:1', 'type' => 'door', 'width_m' => 1, 'height_m' => 0]],
            ['dimension', ['kind' => 'dimension', 'value' => 0, 'unit' => 'm']],
            ['quantity', ['kind' => 'quantity', 'value' => 1, 'unit' => 'unknown']],
            ['material', ['kind' => 'material', 'name' => 'Paint', 'properties' => []]],
            ['equipment', ['kind' => 'equipment', 'name' => 'Pump', 'properties' => []]],
            ['table', ['kind' => 'table', 'columns' => [''], 'rows' => [[]]]],
            ['structural_element', ['kind' => 'structural_element', 'type' => 'column', 'location' => [0]]],
            ['room', ['kind' => 'room', 'area_m2' => 10, 'unknown' => true]],
            ['material', ['kind' => 'material', 'material_code' => 'M-1', 'name' => str_repeat('x', 1_048_577), 'properties' => ['grade' => 'A']]],
        ];

        DB::beginTransaction();
        try {
            foreach ($cases as $index => [$kind, $payload]) {
                $key = $kind.':negative-'.$index;
                $payload['key'] = $key;
                try {
                    DB::transaction(static fn () => DB::table('estimate_generation_project_model_entities')->insert([
                        'building_model_id' => $model->id,
                        'organization_id' => $model->organization_id,
                        'project_id' => $model->project_id,
                        'session_id' => $model->session_id,
                        'source_version' => $model->content_version,
                        'stable_key' => $key,
                        'entity_kind' => $kind,
                        'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                        'confidence' => 1,
                        'created_at' => now(),
                    ]));
                    self::fail('Invalid '.$kind.' payload was accepted.');
                } catch (QueryException) {
                    self::addToAssertionCount(1);
                }
            }
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function historical_rows_backfill_current_projection_conflict_and_evidence_idempotently(): void
    {
        $this->requireEnvironment();
        $model = DB::table('estimate_generation_building_models')->orderByDesc('id')->first();
        $link = $model === null ? null : DB::table('estimate_generation_building_model_evidence')
            ->where('building_model_id', $model->id)->orderBy('evidence_id')->first();
        $evidence = $link === null ? null : DB::table('estimate_generation_evidence')->where('id', $link->evidence_id)->first();
        $actorId = DB::table('users')->orderBy('id')->value('id');
        if ($model === null || $link === null || $evidence === null || $actorId === null) {
            self::markTestSkipped('Requires one isolated building-model evidence fixture.');
        }

        DB::beginTransaction();
        try {
            $entityKey = 'room:backfill-contract';
            $entityId = DB::table('estimate_generation_project_model_entities')->insertGetId([
                'building_model_id' => $model->id,
                'organization_id' => $model->organization_id,
                'project_id' => $model->project_id,
                'session_id' => $model->session_id,
                'source_version' => $model->content_version,
                'stable_key' => $entityKey,
                'entity_kind' => 'room',
                'payload' => json_encode(['kind' => 'room', 'key' => $entityKey, 'area_m2' => 7.94], JSON_THROW_ON_ERROR),
                'confidence' => 1,
                'created_at' => now(),
            ]);
            $createdAt = now();
            $historicalFactId = null;
            foreach ([7.94, 8.10] as $index => $value) {
                $factValue = ['value' => $value, 'unit' => 'm2'];
                $factId = DB::table('estimate_generation_project_model_assertions')->insertGetId([
                    'building_model_id' => $model->id,
                    'organization_id' => $model->organization_id,
                    'project_id' => $model->project_id,
                    'session_id' => $model->session_id,
                    'source_version' => $model->content_version,
                    'stable_key' => 'fact:backfill-'.$index,
                    'entity_id' => $entityId,
                    'assertion_type' => 'area',
                    'payload' => json_encode(['source' => 'explicit_dimension', ...$factValue], JSON_THROW_ON_ERROR),
                    'confidence' => 1,
                    'fact_value' => null,
                    'created_at' => $createdAt,
                ]);
                $historicalFactId ??= $factId;
                $locator = is_string($evidence->locator) ? json_decode($evidence->locator, true, 512, JSON_THROW_ON_ERROR) : $evidence->locator;
                DB::table('estimate_generation_project_model_evidence_bindings')->insert([
                    'building_model_id' => $model->id,
                    'organization_id' => $model->organization_id,
                    'project_id' => $model->project_id,
                    'session_id' => $model->session_id,
                    'source_version' => $model->content_version,
                    'entity_id' => $entityId,
                    'assertion_id' => $factId,
                    'correction_id' => null,
                    'evidence_id' => $evidence->id,
                    'candidate_source' => 'explicit_dimension',
                    'candidate_value_fingerprint' => ProjectModelValueFingerprint::for($factValue),
                    'candidate_locator_fingerprint' => ProjectModelLocatorFingerprint::for($locator),
                    'evidence_source_version' => $evidence->source_version,
                    'evidence_invalidation_version' => $evidence->invalidation_version,
                    'created_at' => now(),
                ]);
            }
            $decisionHash = hash('sha256', 'historical-decision-contract');
            $correctionKey = 'correction:'.$decisionHash;
            DB::table('estimate_generation_project_model_corrections')->insert([
                'building_model_id' => $model->id,
                'organization_id' => $model->organization_id,
                'project_id' => $model->project_id,
                'session_id' => $model->session_id,
                'source_version' => $model->content_version,
                'stable_key' => $correctionKey,
                'assertion_id' => $historicalFactId,
                'correction_type' => 'manual',
                'payload' => json_encode(['canonical_value' => ['value' => 8.25, 'unit' => 'm2']], JSON_THROW_ON_ERROR),
                'reason' => 'Историческое подтверждение',
                'actor_id' => $actorId,
                'created_at' => $createdAt,
            ]);

            $migration = require dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_08_10_000620_backfill_estimate_project_model_v2.php';
            $migration->up();
            $firstProjectionCount = DB::table('estimate_generation_project_model_fact_projections')
                ->where('organization_id', $model->organization_id)->where('project_id', $model->project_id)
                ->where('session_id', $model->session_id)->where('entity_stable_key', $entityKey)->where('is_current', true)->count();
            $firstConflictCount = DB::table('estimate_generation_project_model_conflicts')
                ->where('organization_id', $model->organization_id)->where('project_id', $model->project_id)
                ->where('session_id', $model->session_id)->where('status', 'unresolved')->count();
            self::assertSame(1, $firstProjectionCount);
            self::assertGreaterThanOrEqual(1, $firstConflictCount);
            self::assertSame(2, DB::table('estimate_generation_project_model_assertions')->where('entity_id', $entityId)->where('fact_status', 'conflicted')->count());
            $decision = DB::table('estimate_generation_project_model_corrections')->where('stable_key', $correctionKey)->first();
            self::assertNotNull($decision);
            self::assertSame('fact:decision:'.substr($decisionHash, 0, 48), $decision->selected_fact_stable_key);
            self::assertNotNull($decision->target_conflict_key);
            $lineage = is_string($decision->evidence_lineage)
                ? json_decode($decision->evidence_lineage, true, 512, JSON_THROW_ON_ERROR)
                : (array) $decision->evidence_lineage;
            self::assertNotSame([], $lineage);
            $selectedFact = DB::table('estimate_generation_project_model_assertions')
                ->where('stable_key', $decision->selected_fact_stable_key)->first();
            self::assertNotNull($selectedFact);
            self::assertTrue(DB::table('estimate_generation_project_model_fact_evidence')
                ->where('fact_id', $selectedFact->id)->where('evidence_id', $evidence->id)->exists());
            self::assertTrue(DB::table('estimate_generation_project_model_fact_projections')
                ->where('fact_id', $selectedFact->id)->where('is_current', true)->exists());

            $migration->up();
            self::assertSame($firstProjectionCount, DB::table('estimate_generation_project_model_fact_projections')
                ->where('organization_id', $model->organization_id)->where('project_id', $model->project_id)
                ->where('session_id', $model->session_id)->where('entity_stable_key', $entityKey)->where('is_current', true)->count());
            self::assertSame($firstConflictCount, DB::table('estimate_generation_project_model_conflicts')
                ->where('organization_id', $model->organization_id)->where('project_id', $model->project_id)
                ->where('session_id', $model->session_id)->where('status', 'unresolved')->count());
            self::assertSame(1, DB::table('estimate_generation_project_model_assertions')
                ->where('stable_key', $decision->selected_fact_stable_key)->count());
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function backfill_crosses_multiple_keyset_batches_with_identical_timestamps_and_retries_idempotently(): void
    {
        $this->requireEnvironment();
        $model = DB::table('estimate_generation_building_models')->orderByDesc('id')->first();
        if ($model === null) {
            self::markTestSkipped('Requires one isolated building-model fixture.');
        }

        DB::beginTransaction();
        try {
            $entityKey = 'room:bounded-backfill-contract';
            $entityId = DB::table('estimate_generation_project_model_entities')->insertGetId([
                'building_model_id' => $model->id,
                'organization_id' => $model->organization_id,
                'project_id' => $model->project_id,
                'session_id' => $model->session_id,
                'source_version' => $model->content_version,
                'stable_key' => $entityKey,
                'entity_kind' => 'room',
                'payload' => json_encode(['kind' => 'room', 'key' => $entityKey, 'area_m2' => 10], JSON_THROW_ON_ERROR),
                'confidence' => 1,
                'created_at' => now(),
            ]);
            $createdAt = now();
            $rows = [];
            for ($index = 0; $index < 1_001; $index++) {
                $rows[] = [
                    'building_model_id' => $model->id,
                    'organization_id' => $model->organization_id,
                    'project_id' => $model->project_id,
                    'session_id' => $model->session_id,
                    'source_version' => $model->content_version,
                    'stable_key' => 'fact:bounded-'.$index,
                    'entity_id' => $entityId,
                    'assertion_type' => 'quantity',
                    'payload' => json_encode(['source' => 'explicit_dimension', 'value' => 1, 'unit' => 'pcs'], JSON_THROW_ON_ERROR),
                    'confidence' => 1,
                    'fact_value' => null,
                    'created_at' => $createdAt,
                ];
            }
            foreach (array_chunk($rows, 250) as $chunk) {
                DB::table('estimate_generation_project_model_assertions')->insert($chunk);
            }

            $migration = require dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_08_10_000620_backfill_estimate_project_model_v2.php';
            $migration->up();
            self::assertSame(1_001, DB::table('estimate_generation_project_model_assertions')
                ->where('entity_id', $entityId)->whereNotNull('fact_value')->count());

            $migration->up();
            self::assertSame(1_001, DB::table('estimate_generation_project_model_assertions')
                ->where('entity_id', $entityId)->whereNotNull('fact_value')->count());
        } finally {
            DB::rollBack();
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
