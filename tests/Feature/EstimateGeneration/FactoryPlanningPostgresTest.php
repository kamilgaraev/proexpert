<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Factory\FactoryInspectionClaim;
use App\BusinessModules\Addons\EstimateGeneration\Application\Factory\FactoryInspectionInput;
use App\BusinessModules\Addons\EstimateGeneration\Application\Factory\FactoryInspectionStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\Factory\FactoryPlanningInput;
use App\BusinessModules\Addons\EstimateGeneration\Application\Factory\FactoryPlanningInspector;
use App\BusinessModules\Addons\EstimateGeneration\Application\Factory\InspectFactoryInputs;
use App\BusinessModules\Addons\EstimateGeneration\Application\Factory\InspectFactoryPlanning;
use App\BusinessModules\Addons\EstimateGeneration\Application\Review\UpdateEstimatePrerequisites;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveManualProjectFacts;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationFactoryController;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\EloquentGenerationPipelineDataGateway;
use App\BusinessModules\Addons\EstimateGeneration\Planning\CompletenessRuleCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologySystemCatalog;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class FactoryPlanningPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    private int $outboundBefore = 0;

    public function test_separate_wall_and_equipment_quantities_allow_only_a_partial_unpriced_draft_without_a_global_aggregate(): void
    {
        $entities = [];
        foreach (['wall-a' => '3', 'wall-b' => '4'] as $key => $length) {
            $entities[] = ['key' => $key, 'type' => 'wall', 'coverage' => ['wall_openings' => 'covered_empty'], 'parameters' => [
                'length' => ['value' => $length, 'unit' => 'm', 'basis' => 'measurement'],
                'height' => ['value' => '2', 'unit' => 'm', 'basis' => 'measurement'],
                'thickness' => ['value' => '0.14', 'unit' => 'm', 'basis' => 'measurement'],
            ]];
        }
        foreach (['bath', 'basin'] as $key) {
            $entities[] = ['key' => $key, 'type' => 'equipment', 'parameters' => [
                'equipment_count' => ['value' => '1', 'unit' => 'count', 'basis' => 'assumption'],
            ]];
        }
        [$actor, $session, $prefix, $source] = $this->fixture(['building_type' => 'Одноэтажный жилой дом', 'floors' => 1,
            'selected_sections' => ['structures', 'finishing', 'equipment']], $entities);
        $this->withToken(auth('api_admin')->login($actor));
        $plan = $this->postJson($prefix.'/planning', ['request_id' => (string) Str::uuid(), 'state_version' => 1,
            'source_checkpoint_id' => $source['checkpoint']['id']])->assertOk()->json('data');
        $this->postJson($prefix.'/materialize', ['request_id' => (string) Str::uuid(), 'state_version' => 2,
            'source_checkpoint_id' => $plan['checkpoint']['id']])->assertOk()->assertJsonPath('data.checkpoint.status', 'draft_materialized');
        $draft = $session->fresh()->draft_payload;
        self::assertFalse($draft['is_complete']);
        self::assertFalse($draft['evaluation_result']['can_confirm_for_apply']);
        $warnings = array_column($draft['quantity_coverage_warnings'], 'formula');
        self::assertContains('wall_net_area', $warnings);
        self::assertContains('equipment_count', $warnings);
        $unknownWalls = $equipment = [];
        foreach ($draft['local_estimates'] as $local) {
            foreach ($local['sections'] as $section) {
                foreach ($section['work_items'] as $item) {
                    self::assertNull($item['unit_price']);
                    self::assertNull($item['total_cost']);
                    self::assertEmpty($item['resources'] ?? []);
                    $work = $item['metadata']['composition_work_key'] ?? null;
                    if (in_array($work, ['rough.walls', 'finish.paint', 'walls.internal', 'walls.external_volume'], true)) {
                        self::assertNull($item['quantity']);
                        self::assertSame('quantity_unknown', $item['pricing_blocker']);
                        self::assertArrayNotHasKey('quantity_evidence_id', $item);
                        $unknownWalls[] = $item;
                    }
                    if (in_array($work, ['equipment.purchase', 'equipment.install'], true)) {
                        self::assertSame('1', $item['quantity']);
                        self::assertNotEmpty($item['metadata']['scope_entity_id']);
                        $equipment[] = $item;
                    }
                }
            }
        }
        self::assertNotEmpty($unknownWalls);
        self::assertCount(4, $equipment);
        self::assertSame(500, $session->fresh()->input_payload['qa_limit_units']);
        $this->assertNoPaidCalls($session);
    }

    public static function materializationLimits(): array
    {
        return ['source bytes' => ['source_bytes', 'input_budget_exceeded'], 'work rows' => ['work_rows', 'planning_budget_exceeded']];
    }

    #[DataProvider('materializationLimits')]
    public function test_materialization_input_limits_finish_once_without_draft_evidence_state_or_paid_changes(string $limit, string $limitation): void
    {
        [$actor, $session, $prefix, $source] = $this->fixture(['building_type' => 'Одноэтажный жилой дом', 'floors' => 1]);
        $text = $limit === 'source_bytes' ? str_repeat('x', EloquentGenerationPipelineDataGateway::MAX_SOURCE_BYTES + 1)
            : str_repeat("ФЕР 08-02-001-01 Кладка стен 10 м2\n", 1000);
        EstimateGenerationDocument::query()->create([
            'organization_id' => $session->organization_id, 'project_id' => $session->project_id,
            'session_id' => $session->id, 'user_id' => $actor->id, 'filename' => 'oversized.pdf',
            'mime_type' => 'application/pdf', 'status' => 'ready', 'quality_level' => 'good',
            'checksum_sha256' => hash('sha256', $text), 'source_version' => 'sha256:'.hash('sha256', $text),
            'extracted_text' => $text,
            'facts_summary' => ['document_understanding' => ['role_for_estimation' => 'quantity_source']],
        ]);
        $this->withToken(auth('api_admin')->login($actor));
        $planning = $this->postJson($prefix.'/planning', ['request_id' => (string) Str::uuid(), 'state_version' => 1,
            'source_checkpoint_id' => $source['checkpoint']['id']])->assertOk()->json('data');
        self::assertTrue($planning['fresh']);
        $before = $session->fresh()->getRawOriginal();
        $evidence = DB::table('estimate_generation_evidence')->where('session_id', $session->id)->count();
        $input = ['request_id' => (string) Str::uuid(), 'state_version' => 2, 'source_checkpoint_id' => $planning['checkpoint']['id']];
        $result = $this->postJson($prefix.'/materialize', $input)->assertOk()->json('data');
        self::assertSame('unavailable', $result['checkpoint']['status']);
        self::assertSame([$limitation], $result['checkpoint']['result']['limitations']);
        self::assertNull($result['checkpoint']['result']['draft_refs']);
        self::assertSame(2, $result['checkpoint']['result']['result_state_version']);
        self::assertSame(3, $result['run']['step_count']);
        self::assertFalse($result['fresh']);
        self::assertFalse($result['paid_actions_enabled']);
        self::assertSame('0', $result['cost_units']);
        self::assertSame($before, $session->fresh()->getRawOriginal());
        self::assertSame($evidence, DB::table('estimate_generation_evidence')->where('session_id', $session->id)->count());
        self::assertSame(0, DB::table('estimate_generation_evaluation_revisions')->where('session_id', $session->id)->count());
        $this->postJson($prefix.'/materialize', $input)->assertOk()->assertJsonPath('data', $result);
        self::assertSame(1, DB::table('estimate_generation_factory_checkpoints')->where('id', $result['checkpoint']['id'])->value('attempt_count'));
        $this->assertNoPaidCalls($session);
    }

    public function test_free_materialization_creates_the_first_sealed_draft_and_replays_without_paid_attempts_or_duplicate_revision(): void
    {
        [$actor, $session, $prefix, $source] = $this->fixture(['building_type' => 'Одноэтажный жилой дом', 'floors' => 1,
            'feedback_receipts' => ['historic' => ['result' => str_repeat('x', 2800000)]],
        ], [[
            'key' => 'slab', 'type' => 'foundation_slab', 'parameters' => [
                'area' => ['value' => '4', 'unit' => 'm2', 'basis' => 'measurement'],
                'thickness' => ['value' => '0.25', 'unit' => 'm', 'basis' => 'assumption'],
            ],
        ]]);
        $this->withToken(auth('api_admin')->login($actor));
        $planning = $this->postJson($prefix.'/planning', ['request_id' => (string) Str::uuid(), 'state_version' => 1,
            'source_checkpoint_id' => $source['checkpoint']['id']])->assertOk()->json('data');
        $before = $session->fresh()->input_payload;
        self::assertEmpty($session->fresh()->draft_payload);
        $input = ['request_id' => (string) Str::uuid(), 'state_version' => 2, 'source_checkpoint_id' => $planning['checkpoint']['id']];
        $this->postJson($prefix.'/materialize', [...$input, 'source_checkpoint_id' => $source['checkpoint']['id']])->assertStatus(409);
        $this->postJson($prefix.'/materialize', [...$input, 'source_checkpoint_id' => (string) Str::uuid()])->assertStatus(409);
        $this->postJson($prefix.'/materialize', [...$input, 'state_version' => 1])->assertStatus(409);
        self::assertSame(2, DB::table('estimate_generation_factory_runs')->where('session_id', $session->id)->value('step_count'));
        DB::transaction(function () use ($session): void {
            $capture = app(FactoryPlanningInput::class)->capture($session->fresh());
            $capture['completeness']['input_fingerprint'] = str_repeat('f', 64);
            try {
                app(\App\BusinessModules\Addons\EstimateGeneration\Application\Factory\FactoryDraftBuilder::class)->build($session->fresh(), $capture);
                self::fail('A blocked quantity projection created a draft.');
            } catch (ValidationException) {
                self::assertEmpty($session->fresh()->draft_payload);
            }
        });
        $result = $this->postJson($prefix.'/materialize', $input)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');
        self::assertSame(3, $result['state_version']);
        self::assertSame(1048576, $result['processing_limits']['maximum_input_bytes']);
        self::assertSame(strlen(\App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode($session->fresh()->input_payload)), $result['processing_limits']['input_bytes']);
        self::assertSame('materialize_draft', $result['checkpoint']['result']['step']);
        self::assertSame('draft_materialized', $result['checkpoint']['status']);
        self::assertSame(3, $result['run']['step_count']);
        self::assertTrue($result['fresh']);
        self::assertSame('0', $result['cost_units']);
        self::assertFalse($result['paid_actions_enabled']);
        $draft = $session->fresh()->draft_payload;
        self::assertSame(\App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector::CONTRACT, $draft['generation_contract']);
        self::assertTrue(app(\App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft::class)->verifyArtifact($draft));
        self::assertFalse($draft['is_complete']);
        self::assertGreaterThan(0, $draft['evaluation_rows_count']);
        $concrete = [];
        foreach ($draft['local_estimates'] as $local) {
            foreach ($local['sections'] as $section) {
                foreach ($section['work_items'] as $item) {
                    if (($item['metadata']['composition_work_key'] ?? null) === 'foundation.concrete') {
                        $concrete[] = $item;
                    }
                    self::assertNull($item['unit_price']);
                    self::assertNull($item['total_cost']);
                }
            }
        }
        self::assertCount(2, $concrete);
        self::assertContains('canonical_quantity_aggregate_unresolved', array_column($draft['quantity_coverage_warnings'] ?? [], 'code'));
        self::assertSame('1', $concrete[0]['quantity']);
        self::assertSame('1', $concrete[1]['quantity']);
        self::assertSame('assumed', $concrete[0]['quantity_status']);
        self::assertNotEmpty($concrete[0]['metadata']['scope_entity_id']);
        $revisionCount = DB::table('estimate_generation_evaluation_revisions')->where('session_id', $session->id)->count();
        self::assertSame(1, $revisionCount);
        foreach (['artifact_hash' => null, 'revision_id' => null, 'rows_count' => 1001] as $field => $value) {
            $invalid = $result['checkpoint']['result'];
            $invalid['draft_refs'][$field] = $value;
            try {
                DB::transaction(fn () => DB::table('estimate_generation_factory_checkpoints')->where('id', $result['checkpoint']['id'])
                    ->update(['result' => json_encode($invalid, JSON_THROW_ON_ERROR)]));
                self::fail('Invalid materialization reference was stored.');
            } catch (\Illuminate\Database\QueryException $exception) {
                self::assertSame('23514', $exception->getCode());
            }
        }
        $this->postJson($prefix.'/materialize', $input)->assertOk()->assertJsonPath('data', $result);
        self::assertSame($revisionCount, DB::table('estimate_generation_evaluation_revisions')->where('session_id', $session->id)->count());
        self::assertSame($before['qa_run_id'], $session->fresh()->input_payload['qa_run_id']);
        self::assertSame(500, $session->fresh()->input_payload['qa_limit_units']);
        $saved = app(SaveManualProjectFacts::class)->handle($actor, $session->project_id, $session->id, 3, (string) Str::uuid(), ['entities' => [[
            'key' => 'beam', 'type' => 'foundation_beam', 'parameters' => [
                'height' => ['value' => '0.4', 'unit' => 'm', 'basis' => 'assumption'],
            ],
        ]]]);
        self::assertTrue($saved['recalculation_required']);
        $this->assertNoPaidCalls($session);
    }

    public function test_materialization_rolls_back_draft_evidence_revision_and_state_then_recovers_the_same_finite_checkpoint(): void
    {
        [$actor, $session, , $source] = $this->fixture(['building_type' => 'Одноэтажный жилой дом', 'floors' => 1]);
        $store = app(FactoryInspectionStore::class);
        $plan = app(InspectFactoryPlanning::class)->handle($actor, $session->project_id, $session->id, 1, (string) Str::uuid(), $source['checkpoint']['id']);
        $request = (string) Str::uuid();
        $claim = $store->claim($actor, $session->project_id, $session->id, 2, $request, 'materialize_draft', $plan['checkpoint']['id']);
        self::assertInstanceOf(FactoryInspectionClaim::class, $claim);
        $before = $session->fresh()->getRawOriginal();
        $evidence = DB::table('estimate_generation_evidence')->where('session_id', $session->id)->count();
        $armed = true;
        DB::listen(static function (QueryExecuted $query) use (&$armed): void {
            if ($armed && str_starts_with($query->sql, 'update "estimate_generation_factory_checkpoints"')
                && str_contains($query->sql, '"result_state_version"')) {
                $armed = false;
                throw new RuntimeException('test_materialization_response_interrupted');
            }
        });
        try {
            $store->completeMaterialization($actor, $claim);
            self::fail('Interrupted materialization was committed.');
        } catch (RuntimeException $exception) {
            self::assertSame('test_materialization_response_interrupted', $exception->getMessage());
        }
        self::assertSame($before, $session->fresh()->getRawOriginal());
        self::assertSame($evidence, DB::table('estimate_generation_evidence')->where('session_id', $session->id)->count());
        self::assertSame(0, DB::table('estimate_generation_evaluation_revisions')->where('session_id', $session->id)->count());
        self::assertSame('running', DB::table('estimate_generation_factory_checkpoints')->where('id', $claim->checkpointId)->value('status'));
        try {
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));
            $retry = $store->claim($actor, $session->project_id, $session->id, 2, $request, 'materialize_draft', $plan['checkpoint']['id']);
            self::assertInstanceOf(FactoryInspectionClaim::class, $retry);
            self::assertSame($claim->checkpointId, $retry->checkpointId);
            self::assertSame(2, $retry->attempt);
            try {
                $store->completeMaterialization($actor, $claim);
                self::fail('An expired materialization owner changed the session.');
            } catch (StaleEstimateGenerationState) {
                self::assertSame($before, $session->fresh()->getRawOriginal());
            }
            $store->completeMaterialization($actor, $retry);
            self::assertTrue($store->current($actor, $session->project_id, $session->id, $request)['fresh']);
            self::assertSame(3, $session->fresh()->state_version);
            self::assertSame(1, DB::table('estimate_generation_evaluation_revisions')->where('session_id', $session->id)->count());
            self::assertSame(3, DB::table('estimate_generation_factory_runs')->where('session_id', $session->id)->value('step_count'));
        } finally {
            CarbonImmutable::setTestNow();
        }
        $this->assertNoPaidCalls($session);
    }

    public function test_http_free_planning_advances_once_and_replays_the_original_request_after_lost_response_and_state_change(): void
    {
        [$actor, $session, $prefix, $source] = $this->fixture();
        $this->withToken(auth('api_admin')->login($actor));
        $before = $session->fresh()->input_payload;
        $input = ['request_id' => (string) Str::uuid(), 'state_version' => 1, 'source_checkpoint_id' => $source['checkpoint']['id']];
        $result = $this->postJson($prefix.'/planning', $input)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');
        self::assertSame(FactoryPlanningInput::CONTRACT, $result['contract_version']);
        self::assertSame(2, $result['state_version']);
        self::assertSame(1, $result['checkpoint']['expected_state_version']);
        self::assertSame(2, $result['checkpoint']['result']['result_state_version']);
        self::assertSame('inspect_plan', $result['checkpoint']['result']['step']);
        self::assertTrue($result['fresh']);
        self::assertSame($source['run']['id'], $result['run']['id']);
        self::assertSame(2, $result['run']['step_count']);
        self::assertSame('0', $result['cost_units']);
        self::assertFalse($result['paid_actions_enabled']);
        self::assertFalse($result['checkpoint']['result']['estimate_complete']);
        self::assertContains('material_coverage_not_checked', $result['checkpoint']['result']['limitations']);
        self::assertNotNull($result['checkpoint']['result']['planning_refs']);
        $this->postJson($prefix.'/planning', $input)->assertOk()->assertJsonPath('data', $result);
        self::assertCount(1, $session->fresh()->input_payload['prerequisite_receipts']);
        self::assertSame($before['qa_run_id'], $session->fresh()->input_payload['qa_run_id']);
        self::assertSame(500, $session->fresh()->input_payload['qa_limit_units']);
        $session->update(['state_version' => 3]);
        $replay = $this->postJson($prefix.'/planning', $input)->assertOk()->json('data');
        self::assertSame($result['checkpoint'], $replay['checkpoint']);
        self::assertFalse($replay['fresh']);
        self::assertSame(3, $session->fresh()->state_version);
        self::assertSame(2, DB::table('estimate_generation_factory_checkpoints')->where('session_id', $session->id)->count());
        $this->assertNoPaidCalls($session);
    }

    public function test_failure_after_terminal_update_rolls_back_refresh_and_retry_preserves_checkpoint_and_fences_the_old_owner(): void
    {
        [$actor, $session, , $source] = $this->fixture();
        $store = app(FactoryInspectionStore::class);
        $request = (string) Str::uuid();
        $claim = $store->claim($actor, $session->project_id, $session->id, 1, $request, 'inspect_plan', $source['checkpoint']['id']);
        self::assertInstanceOf(FactoryInspectionClaim::class, $claim);
        $before = $session->fresh()->getRawOriginal();
        $armed = true;
        DB::listen(static function (QueryExecuted $query) use (&$armed): void {
            if ($armed && str_starts_with($query->sql, 'update "estimate_generation_factory_checkpoints"')
                && str_contains($query->sql, '"result_state_version"')) {
                $armed = false;
                throw new RuntimeException('test_terminal_response_interrupted');
            }
        });
        try {
            $store->completePlanning($actor, $claim);
            self::fail('Interrupted planning was committed.');
        } catch (RuntimeException $exception) {
            self::assertSame('test_terminal_response_interrupted', $exception->getMessage());
        }
        self::assertSame($before, $session->fresh()->getRawOriginal());
        self::assertSame(0, DB::table('estimate_generation_technology_planning_runs')->where('session_id', $session->id)->count());
        self::assertSame('running', DB::table('estimate_generation_factory_checkpoints')->where('id', $claim->checkpointId)->value('status'));
        try {
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));
            $retry = $store->claim($actor, $session->project_id, $session->id, 1, $request, 'inspect_plan', $source['checkpoint']['id']);
            self::assertInstanceOf(FactoryInspectionClaim::class, $retry);
            self::assertSame($claim->checkpointId, $retry->checkpointId);
            self::assertSame(2, $retry->attempt);
            try {
                $store->completePlanning($actor, $claim);
                self::fail('Expired owner changed the session.');
            } catch (StaleEstimateGenerationState) {
                self::assertSame($before, $session->fresh()->getRawOriginal());
            }
            $store->completePlanning($actor, $retry);
            self::assertTrue($store->current($actor, $session->project_id, $session->id, $request)['fresh']);
            self::assertSame(2, $session->fresh()->state_version);
            self::assertSame(2, DB::table('estimate_generation_factory_runs')->where('session_id', $session->id)->value('step_count'));
        } finally {
            CarbonImmutable::setTestNow();
        }
        $this->assertNoPaidCalls($session);
    }

    public function test_stale_or_foreign_source_and_invalid_http_payload_cannot_refresh_planning(): void
    {
        [$actor, $session, $prefix, $source] = $this->fixture();
        $this->withToken(auth('api_admin')->login($actor));
        $input = ['request_id' => (string) Str::uuid(), 'state_version' => 1, 'source_checkpoint_id' => $source['checkpoint']['id']];
        $this->postJson($prefix.'/planning', [...$input, 'source_checkpoint_id' => 'bad'])->assertUnprocessable();
        $this->postJson($prefix.'/planning', [...$input, 'source_checkpoint_id' => (string) Str::uuid()])->assertConflict();
        $session->update(['state_version' => 2]);
        $this->postJson($prefix.'/planning', [...$input, 'state_version' => 2])->assertConflict();
        $actor->update(['current_organization_id' => Organization::factory()->create()->id]);
        $this->withToken(auth('api_admin')->login($actor));
        $this->postJson($prefix.'/planning', $input)->assertNotFound();
        self::assertSame(1, DB::table('estimate_generation_factory_checkpoints')->where('session_id', $session->id)->count());
        self::assertArrayNotHasKey('prerequisite_receipts', $session->fresh()->input_payload);
        $this->assertNoPaidCalls($session);
    }

    public function test_planning_byte_preflight_stops_before_any_snapshot_getter(): void
    {
        [$actor, $session, , $source] = $this->fixture();
        app(InspectFactoryPlanning::class)->handle($actor, $session->project_id, $session->id, 1, (string) Str::uuid(), $source['checkpoint']['id']);
        $run = DB::table('estimate_generation_completeness_runs')->where('session_id', $session->id)->where('is_current', true)->first();
        self::assertNotNull($run);
        $finding = DB::table('estimate_generation_completeness_findings')->where('completeness_run_id', $run->id)->first();
        self::assertNotNull($finding);
        $oversized = (array) $finding;
        unset($oversized['id']);
        $oversized['rule_id'] .= '-budget';
        $oversized['impact'] = str_repeat('x', 8388609);
        DB::table('estimate_generation_completeness_findings')->insert($oversized);
        $models = Mockery::mock(ProjectModelRepository::class);
        $models->shouldNotReceive('snapshotForPlanning');
        $models->shouldNotReceive('currentTechnologyRecommendations');
        $models->shouldNotReceive('currentCompleteness');
        $reader = new FactoryPlanningInput(DB::connection(), new FactoryInspectionInput(DB::connection(), $models), $models,
            app(TechnologySystemCatalog::class), app(CompletenessRuleCatalog::class));
        DB::enableQueryLog();
        DB::flushQueryLog();
        $capture = DB::transaction(static fn (): array => $reader->capture($session->fresh()));
        self::assertFalse($capture['within_budget']);
        self::assertSame(['planning_budget_exceeded'], $capture['limitations']);
        foreach (DB::getQueryLog() as $query) {
            self::assertStringNotContainsString('row_to_json', $query['query']);
        }
        DB::disableQueryLog();
        $this->assertNoPaidCalls($session);
    }

    public function test_planning_keeps_three_attempts_and_uses_the_last_lifetime_step_without_reset(): void
    {
        [$actor, $session, , $source] = $this->fixture();
        $store = app(FactoryInspectionStore::class);
        $request = (string) Str::uuid();
        try {
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                $claim = $store->claim($actor, $session->project_id, $session->id, 1, $request, 'inspect_plan', $source['checkpoint']['id']);
                self::assertInstanceOf(FactoryInspectionClaim::class, $claim);
                self::assertSame($attempt, $claim->attempt);
                CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));
            }
            $exhausted = $store->claim($actor, $session->project_id, $session->id, 1, $request, 'inspect_plan', $source['checkpoint']['id']);
            self::assertSame('exhausted', $exhausted['checkpoint']['status']);
            self::assertSame(2, $exhausted['run']['step_count']);
            self::assertSame(1, $session->fresh()->state_version);
            $next = app(InspectFactoryInputs::class)->handle($actor, $session->project_id, $session->id, 1, (string) Str::uuid());
            DB::table('estimate_generation_factory_checkpoints')->where('id', $next['checkpoint']['id'])->update(['step_number' => 63]);
            DB::table('estimate_generation_factory_runs')->where('id', $next['run']['id'])->update(['step_count' => 63]);
            $result = app(InspectFactoryPlanning::class)->handle($actor, $session->project_id, $session->id, 1, (string) Str::uuid(), $next['checkpoint']['id']);
            self::assertSame(64, $result['run']['step_count']);
            self::assertSame(64, $result['checkpoint']['step_number']);
            try {
                app(InspectFactoryInputs::class)->handle($actor, $session->project_id, $session->id, 2, (string) Str::uuid());
                self::fail('A new request reset the lifetime limit.');
            } catch (ValidationException) {
                self::assertSame(64, DB::table('estimate_generation_factory_runs')->where('session_id', $session->id)->value('step_count'));
            }
        } finally {
            CarbonImmutable::setTestNow();
        }
        $this->assertNoPaidCalls($session);
    }

    public function test_catalog_identity_change_makes_the_saved_planning_result_stale_without_writes(): void
    {
        [$actor, $session, , $source] = $this->fixture();
        $result = app(InspectFactoryPlanning::class)->handle($actor, $session->project_id, $session->id, 1, (string) Str::uuid(), $source['checkpoint']['id']);
        self::assertTrue($result['fresh']);
        $before = $session->fresh()->getRawOriginal();
        $catalog = app(TechnologySystemCatalog::class);
        $changed = new TechnologySystemCatalog($catalog->version, hash('sha256', $catalog->contentHash.'changed'), $catalog->systems, $catalog->requiredFacts);
        $reader = new FactoryPlanningInput(DB::connection(), app(FactoryInspectionInput::class), app(ProjectModelRepository::class), $changed, app(CompletenessRuleCatalog::class));
        $store = new FactoryInspectionStore(DB::connection(), app(FactoryInspectionInput::class), app(EstimateGenerationActionAuthorization::class),
            $reader, app(FactoryPlanningInspector::class), app(UpdateEstimatePrerequisites::class));
        $current = $store->current($actor, $session->project_id, $session->id);
        self::assertFalse($current['fresh']);
        self::assertSame($result['checkpoint'], $current['checkpoint']);
        self::assertSame($before, $session->fresh()->getRawOriginal());
        $this->assertNoPaidCalls($session);
    }

    private function assertNoPaidCalls(EstimateGenerationSession $session): void
    {
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        self::assertSame(0, DB::table('estimate_generation_credit_operations')->where('session_id', $session->id)->count());
        self::assertCount($this->outboundBefore, Http::recorded(), json_encode(Http::recorded()->map(
            static fn (array $pair): array => [parse_url($pair[0]->url(), PHP_URL_HOST), parse_url($pair[0]->url(), PHP_URL_PATH)],
        )->all(), JSON_THROW_ON_ERROR));
    }

    private function fixture(array $inputOverrides = [], array $extraEntities = []): array
    {
        Http::preventStrayRequests();
        Http::fake();
        config(['estimate-generation.universal_enabled' => true, 'jwt.secret' => str_repeat('test-key', 8), 'jwt.blacklist_enabled' => false]);
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->allows('canCurrent')->andReturnTrue();
        $authorization->allows('can')->andReturnTrue();
        $this->app->instance(AuthorizationService::class, $authorization);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'status' => 'draft', 'state_version' => 0, 'input_payload' => array_replace([
                'evaluation_mode' => 'universal', 'selected_sections' => ['structures'],
                'qa_run_id' => '5ac504c5-5ca3-41dc-95b0-f3c8745ccc44', 'qa_limit_units' => 500,
            ], $inputOverrides)]);
        $saved = app(SaveManualProjectFacts::class)->handle($actor, $project->id, $session->id, 0, (string) Str::uuid(), ['entities' => [[
            'key' => 'beam', 'type' => 'foundation_beam', 'parameters' => [
                'length' => ['value' => '10', 'unit' => 'm', 'basis' => 'measurement'],
                'width' => ['value' => '250', 'unit' => 'mm', 'basis' => 'measurement'],
                'height' => ['value' => '400', 'unit' => 'mm', 'basis' => 'assumption'],
            ],
        ], ...$extraEntities]]);
        self::assertFalse($saved['recalculation_required']);
        $source = app(InspectFactoryInputs::class)->handle($actor, $project->id, $session->id, 1, (string) Str::uuid());
        self::assertSame('planning_required', $source['checkpoint']['status']);
        $this->outboundBefore = count(Http::recorded());
        Route::bind('project', static fn (): Project => $project);
        Route::bind('session', static fn (): EstimateGenerationSession => $session->fresh());
        $route = '/_contract/factory-planning/projects/{project}/sessions/{session}/factory';
        Route::post($route.'/planning', [EstimateGenerationFactoryController::class, 'planning'])->middleware(['auth:api_admin', SubstituteBindings::class]);
        Route::post($route.'/materialize', [EstimateGenerationFactoryController::class, 'materialize'])->middleware(['auth:api_admin', SubstituteBindings::class]);

        return [$actor, $session, str_replace(['{project}', '{session}'], [(string) $project->id, (string) $session->id], $route), $source];
    }
}
