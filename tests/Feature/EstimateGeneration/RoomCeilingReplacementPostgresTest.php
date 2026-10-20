<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\CurrentDraftQuantityProofGuard;
use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\AcceptRoomCeilingReplacement;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\BindEvaluationWorkEntity;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\CurrentRoomIdentitySources;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\CurrentWorkEntityBindingProof;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationInputFingerprint;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\InitialProjectFactsWriter;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\RecalculateUniversalEvaluation;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\RoomCeilingReplacementProof;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveManualProjectFacts;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationCeilingController;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\RoomCeilingReplacement;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class RoomCeilingReplacementPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_whole_building_area_is_preserved_without_becoming_an_extra_ceiling_room(): void
    {
        [$actor, $session, $url] = $this->fixture(includeBuildingArea: true);
        $outbound = count(Http::recorded());
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $models = app(ProjectModelRepository::class);
        $snapshot = $models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        $aggregate = array_values(array_filter($snapshot->entities, static fn ($entity): bool => ($entity->attributes['document_role'] ?? null) === 'building_floor'));
        self::assertCount(1, $aggregate);
        $before = $models->currentFacts(...[...$scope, $aggregate[0]->id]);
        self::assertCount(1, $before);
        self::assertSame('72.19', $before[0]->value);
        self::assertCount(6, array_filter($snapshot->entities, static fn ($entity): bool => $entity->type === 'room'));

        $preview = $this->getJson($url.'?parent_work_item_key=ceiling:rough')->assertOk()->json('data');
        self::assertCount(5, $preview['rooms']);
        self::assertNotContains($aggregate[0]->id, array_column($preview['rooms'], 'entity_id'));
        self::assertSame('34.6044', $preview['replacement_total']);
        $this->postJson($url, $this->input($preview))->assertOk()->assertJsonCount(5, 'data.added_work_item_keys');
        self::assertEquals($before, $models->currentFacts(...[...$scope, $aggregate[0]->id]));
        self::assertSame(500, $session->fresh()->input_payload['qa_limit_units']);
        self::assertSame($outbound, count(Http::recorded()));
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public function test_native_replacement_preserves_old_quantity_price_materials_and_receipts_without_copying_them_to_room_rows(): void
    {
        [$actor, $session, $url] = $this->fixture();
        $before = $session->fresh();
        $parent = RoomCeilingReplacement::rows($before->draft_payload)[0];
        $outbound = count(Http::recorded());
        $preview = $this->getJson($url.'?parent_work_item_key=ceiling:rough')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');
        self::assertCount(5, $preview['rooms']);
        self::assertSame('34.6044', $preview['replacement_total']);
        self::assertSame('40', $preview['previous_quantity']);
        self::assertSame($before->draft_payload, $session->fresh()->draft_payload);
        $input = $this->input($preview);
        $response = $this->postJson($url, $input)->assertOk()->json('data');
        $saved = $session->fresh();
        self::assertSame($before->state_version + 1, $saved->state_version);
        self::assertSame('0', $response['cost_units']);
        self::assertSame('ceiling:rough', $response['removed_work_item_key']);
        self::assertCount(5, $response['added_work_item_keys']);
        self::assertSame($parent, $saved->input_payload['room_ceiling_replacement_history'][$input['request_id']]['parent_item']);
        self::assertArrayNotHasKey('ceiling:rough', $saved->input_payload['quantity_selections']);
        self::assertArrayNotHasKey('ceiling:rough', $saved->input_payload['price_selections']);
        self::assertArrayNotHasKey('confirmed_evaluation', $saved->input_payload);
        self::assertArrayNotHasKey('scope_selection', $saved->input_payload);
        self::assertArrayNotHasKey('scenario_selections', $saved->input_payload);
        self::assertSame(500, $saved->input_payload['qa_limit_units']);
        self::assertSame('5ac504c5-5ca3-41dc-95b0-f3c8745ccc44', $saved->input_payload['qa_run_id']);
        $rows = RoomCeilingReplacement::rows($saved->draft_payload);
        self::assertCount(10, $rows);
        $children = array_values(array_filter($rows, static fn (array $row): bool => isset($row['metadata']['room_ceiling_replacement'])));
        foreach ($children as $child) {
            self::assertNull($child['unit_price']);
            self::assertNull($child['total_cost']);
            self::assertSame([], $child['materials']);
            self::assertArrayNotHasKey('normative_match', $child);
            self::assertArrayNotHasKey('commercial_price_snapshot', $child['metadata']);
            self::assertSame('estimated', $child['quantity_basis_details']['source']);
            self::assertSame('room_ceiling_area', $child['quantity_basis_details']['formula_key']);
        }
        self::assertSame('34.6044', (string) array_reduce($children, static fn (BigDecimal $sum, array $row): BigDecimal => $sum->plus($row['quantity']), BigDecimal::zero())->stripTrailingZeros());
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $snapshot = app(ProjectModelRepository::class)->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        self::assertNotEmpty(app(CurrentWorkEntityBindingProof::class)->evidenceIds($saved, $children, $snapshot, true));
        self::assertNotEmpty(app(RoomCeilingReplacementProof::class)->evidenceIds($saved, $rows, $snapshot));
        $ceilingDraft = $saved->draft_payload;
        $ceilingDraft['local_estimates'][0]['sections'][0]['work_items'] = $children;
        app(CurrentDraftQuantityProofGuard::class)->assertCurrent(...[...$scope, $ceilingDraft]);
        $alternative = array_values(array_filter(app(CurrentRoomIdentitySources::class)->rows($saved, true),
            static fn (array $source): bool => $source['entity_id'] !== $children[0]['entity_id']))[0];
        try {
            app(BindEvaluationWorkEntity::class)->handle($actor, $session->project_id, $session->id,
                ['request_id' => (string) Str::uuid(), 'state_version' => $saved->state_version, 'work_item_key' => $children[0]['key'],
                    'source_fact_id' => $alternative['source_fact_id'], 'source_fact_version' => $alternative['source_fact_version'],
                    'source_value_hash' => $alternative['source_value_hash'], 'reason' => 'Перенести потолок в другое помещение нельзя без пересмотра разложения.']);
            self::fail('A room ceiling child was rebound to another room');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('entity', $exception->errors());
        }
        $withoutMetadata = $children;
        unset($withoutMetadata[0]['metadata']['room_ceiling_replacement']);
        foreach ([[$parent, ...$children], [...$children, $children[0]], $withoutMetadata] as $invalid) {
            try {
                app(RoomCeilingReplacementProof::class)->evidenceIds($saved, $invalid, $snapshot);
                self::fail('An invalid room ceiling replacement was accepted');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('ceiling', $exception->errors());
            }
        }
        $raw = $saved->getRawOriginal();
        $factCount = DB::table('estimate_generation_project_model_assertions')->where('session_id', $session->id)->count();
        self::assertSame(CanonicalPipelineJson::encode($response), CanonicalPipelineJson::encode($this->postJson($url, $input)->assertOk()->json('data')));
        self::assertSame($raw, $session->fresh()->getRawOriginal());
        self::assertSame($factCount, DB::table('estimate_generation_project_model_assertions')->where('session_id', $session->id)->count());
        $this->postJson($url, [...$input, 'reason' => 'Изменённое решение с прежним номером операции'])->assertUnprocessable();
        self::assertSame($outbound, count(Http::recorded()));
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public function test_all_five_continuous_work_types_cover_rooms_once_and_never_expand_hatch_or_grab_rails(): void
    {
        [$actor, $session] = $this->fixture();
        $handler = app(AcceptRoomCeilingReplacement::class);
        foreach (['rough', 'finish', 'brandering', 'insulation', 'cornice'] as $key) {
            $preview = $handler->show($actor, $session->project_id, $session->id, 'ceiling:'.$key);
            self::assertSame($key === 'cornice' ? '50.66' : '34.6044', $preview['replacement_total']);
            $handler->handle($actor, $session->project_id, $session->id, $this->input($preview));
        }
        $rows = RoomCeilingReplacement::rows($session->fresh()->draft_payload);
        self::assertCount(26, $rows);
        self::assertCount(26, array_unique(array_column($rows, 'key')));
        self::assertSame('ceiling:hatch', $rows[25]['key']);
        self::assertSame('1', $rows[25]['quantity']);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $snapshot = app(ProjectModelRepository::class)->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        self::assertNotEmpty(app(RoomCeilingReplacementProof::class)->evidenceIds($session->fresh(), $rows, $snapshot));
        $this->expectException(ValidationException::class);
        $handler->show($actor, $session->project_id, $session->id, 'ceiling:hatch');
    }

    public function test_stale_or_tampered_preview_changes_nothing(): void
    {
        [$actor, $session, $url] = $this->fixture();
        $preview = app(AcceptRoomCeilingReplacement::class)->show($actor, $session->project_id, $session->id, 'ceiling:rough');
        $raw = $session->fresh()->getRawOriginal();
        $this->postJson($url, [...$this->input($preview), 'preview_fingerprint' => str_repeat('f', 64)])->assertUnprocessable();
        $this->postJson($url, [...$this->input($preview), 'state_version' => 0])->assertConflict();
        $this->postJson($url, [...$this->input($preview), 'method' => 'copy_floor_area'])->assertUnprocessable();
        self::assertSame($raw, $session->fresh()->getRawOriginal());
    }

    public function test_new_generation_keeps_replay_history_and_does_not_silently_reuse_the_previous_replacement(): void
    {
        [$actor, $session] = $this->fixture();
        $handler = app(AcceptRoomCeilingReplacement::class);
        $parentDraft = $session->fresh()->draft_payload;
        $preview = $handler->show($actor, $session->project_id, $session->id, 'ceiling:rough');
        $input = $this->input($preview);
        $receipt = $handler->handle($actor, $session->project_id, $session->id, $input);
        $saved = $session->fresh();
        $payload = $saved->input_payload;
        $payload['composition_generation_id'] = 'generation:two';
        $saved->input_payload = $payload;
        $parentDraft['source_input_version'] = 'sha256:'.app(EvaluationInputFingerprint::class)->fromSession($saved);
        $saved->update(['input_payload' => $payload, 'draft_payload' => app(BuildMostEstimateDraft::class)->seal(app(UniversalReviewSummaryProjector::class)->project($parentDraft)),
            'state_version' => $saved->state_version + 1]);
        $raw = $saved->fresh()->getRawOriginal();
        self::assertSame(CanonicalPipelineJson::encode($receipt), CanonicalPipelineJson::encode($handler->handle($actor, $session->project_id, $session->id, $input)));
        self::assertSame($raw, $saved->fresh()->getRawOriginal());
        $newPreview = $handler->show($actor, $session->project_id, $session->id, 'ceiling:rough');
        self::assertNotSame($preview['preview_fingerprint'], $newPreview['preview_fingerprint']);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $snapshot = app(ProjectModelRepository::class)->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        self::assertSame([], app(RoomCeilingReplacementProof::class)->evidenceIds($saved->fresh(), RoomCeilingReplacement::rows($parentDraft), $snapshot));
    }

    public function test_invalidated_real_replacement_evidence_cannot_support_apply(): void
    {
        [$actor, $session] = $this->fixture();
        $handler = app(AcceptRoomCeilingReplacement::class);
        $preview = $handler->show($actor, $session->project_id, $session->id, 'ceiling:rough');
        $handler->handle($actor, $session->project_id, $session->id, $this->input($preview));
        $saved = $session->fresh();
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $snapshot = app(ProjectModelRepository::class)->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        $facts = array_column($snapshot->facts, null, 'id');
        $replacement = $saved->input_payload['room_ceiling_replacements']['ceiling:rough'];
        $ids = array_map(static fn (string $id): int => (int) substr($id, 9), $facts[$replacement['fact_id']]->evidenceIds);
        app(EvidenceRepository::class)->invalidate(...[...$scope, $ids, 'source_changed']);
        $fresh = app(ProjectModelRepository::class)->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        $this->expectException(ValidationException::class);
        app(RoomCeilingReplacementProof::class)->evidenceIds($saved, RoomCeilingReplacement::rows($saved->draft_payload), $fresh);
    }

    public function test_single_room_quantity_alias_keeps_the_explicit_ceiling_formula_through_apply_validation(): void
    {
        [$actor, $session] = $this->fixture(1);
        $draft = $session->fresh()->draft_payload;
        foreach ($draft['local_estimates'][0]['sections'][0]['work_items'] as &$item) {
            if (in_array($item['key'], ['ceiling:brandering', 'ceiling:insulation', 'ceiling:cornice'], true)) {
                $item['quantity'] = null;
            }
        }
        unset($item);
        $session->update(['draft_payload' => app(BuildMostEstimateDraft::class)->seal(app(UniversalReviewSummaryProjector::class)->project($draft))]);
        $handler = app(AcceptRoomCeilingReplacement::class);
        $preview = $handler->show($actor, $session->project_id, $session->id, 'ceiling:rough');
        self::assertSame('8.5118', $preview['replacement_total']);
        $handler->handle($actor, $session->project_id, $session->id, $this->input($preview));
        $draft = $session->fresh()->draft_payload;
        $children = array_values(array_filter(RoomCeilingReplacement::rows($draft), static fn (array $row): bool => isset($row['metadata']['room_ceiling_replacement'])));
        self::assertCount(1, $children);
        self::assertSame('room_ceiling_area', $children[0]['quantity_evidence']['formula_key']);
        $draft['local_estimates'][0]['sections'][0]['work_items'] = $children;
        app(CurrentDraftQuantityProofGuard::class)->assertCurrent((int) $session->organization_id, (int) $session->project_id, (int) $session->id, $draft);
        $saved = $session->fresh();
        app(RecalculateUniversalEvaluation::class)->handle($actor, $session->project_id, $session->id, $saved->state_version, (string) Str::uuid());
        $rows = array_column(RoomCeilingReplacement::rows($session->fresh()->draft_payload), null, 'key');
        foreach (['ceiling:brandering', 'ceiling:insulation', 'ceiling:cornice'] as $unaccepted) {
            self::assertNull($rows[$unaccepted]['quantity']);
            self::assertArrayNotHasKey('room_ceiling_replacement', $rows[$unaccepted]['metadata']);
        }
        self::assertSame('8.5118', $rows[$children[0]['key']]['quantity']);
        self::assertSame('room_ceiling_area', $rows[$children[0]['key']]['quantity_evidence']['formula_key']);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public function test_a_different_seven_room_plan_uses_its_own_dimensions_and_covers_every_room_once(): void
    {
        [$actor, $session, $url] = $this->fixture(roomValues: [
            ['annex', '1.25', '3.2'], ['hall', '4.125', '2.4'], ['studio', '7.8', '3.05'],
            ['store', '0.95', '1.6'], ['wash', '2.135', '1.025'], ['nursery', '5.4', '4.8'], ['landing', '2.2', '2.75'],
        ]);
        $area = $this->getJson($url.'?parent_work_item_key=ceiling:rough')->assertOk()->json('data');
        self::assertCount(7, $area['rooms']);
        self::assertSame('73.368375', $area['replacement_total']);
        self::assertCount(7, array_unique(array_column($area['rooms'], 'entity_id')));
        $this->postJson($url, $this->input($area))->assertOk();
        $perimeter = $this->getJson($url.'?parent_work_item_key=ceiling:cornice')->assertOk()->json('data');
        self::assertCount(7, $perimeter['rooms']);
        self::assertSame('85.37', $perimeter['replacement_total']);
        self::assertSame('m', $perimeter['replacement_unit']);
        $this->postJson($url, $this->input($perimeter))->assertOk();
        $saved = $session->fresh();
        $rows = RoomCeilingReplacement::rows($saved->draft_payload);
        self::assertCount(18, $rows);
        self::assertCount(18, array_unique(array_column($rows, 'key')));
        $children = array_values(array_filter($rows, static fn (array $row): bool => isset($row['metadata']['room_ceiling_replacement'])));
        self::assertCount(14, $children);
        foreach ($children as $child) {
            self::assertSame('estimated', $child['quantity_basis_details']['source']);
            self::assertNotEmpty($child['quantity_basis_details']['assumptions']);
            self::assertNull($child['unit_price']);
        }
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $snapshot = app(ProjectModelRepository::class)->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        self::assertNotEmpty(app(RoomCeilingReplacementProof::class)->evidenceIds($saved, $rows, $snapshot));
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public function test_missing_width_in_one_room_prevents_a_partial_ceiling_replacement(): void
    {
        [$actor, $session, $url] = $this->fixture(roomValues: [['complete', '3', '2'], ['incomplete', '4', null]]);
        $before = $session->fresh()->getRawOriginal();
        $this->getJson($url.'?parent_work_item_key=ceiling:rough')->assertUnprocessable()
            ->assertJsonPath('errors.ceiling.0', trans_message('estimate_ceiling.room_dimensions'));
        self::assertSame($before, $session->fresh()->getRawOriginal());
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    private function fixture(int $roomCount = 5, ?array $roomValues = null, bool $includeBuildingArea = false): array
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
        $rows = [];
        foreach (['rough' => 'rough.ceiling', 'finish' => 'finish.ceiling', 'brandering' => 'ceiling.brandering', 'insulation' => 'ceiling.insulation', 'cornice' => 'ceiling.cornice', 'hatch' => 'ceiling.hatch'] as $key => $work) {
            $rows[] = ['key' => 'ceiling:'.$key, 'name' => $work, 'work_key' => $work, 'item_type' => 'priced_work',
                'unit' => $key === 'hatch' ? 'count' : 'm2', 'quantity' => $key === 'hatch' ? '1' : '40', 'quantity_status' => 'assumed',
                'unit_price' => '100', 'total_cost' => '4000', 'pricing_status' => 'priced',
                'materials' => [['key' => 'old-material', 'quantity' => '42', 'unit_price' => '10']], 'normative_match' => ['norm_id' => 17],
                'metadata' => ['composition_work_key' => $work, 'quantity_key' => $work, 'commercial_price_snapshot' => ['old' => true]]];
        }
        $draft = app(BuildMostEstimateDraft::class)->seal(['generation_contract' => UniversalDraftProjector::CONTRACT,
            'local_estimates' => [['key' => 'finishing', 'sections' => [['key' => 'ceilings', 'work_items' => $rows]]]], 'is_complete' => false]);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'status' => 'estimate_review_required', 'state_version' => 1,
            'input_payload' => ['evaluation_mode' => 'universal', 'price_policy' => 'normative', 'composition_generation_id' => 'generation:one',
                ...($includeBuildingArea ? ['area' => '72.19'] : []),
                'qa_run_id' => '5ac504c5-5ca3-41dc-95b0-f3c8745ccc44', 'qa_limit_units' => 500,
                'quantity_selections' => ['ceiling:rough' => ['old' => true]], 'price_selections' => ['ceiling:rough' => ['old' => true]],
                'scope_selection' => ['old' => true], 'scenario_selections' => ['old' => true], 'confirmed_evaluation' => ['old' => true]], 'draft_payload' => $draft]);
        if ($includeBuildingArea) {
            app(InitialProjectFactsWriter::class)->write($session, $actor);
        }
        $entities = [];
        foreach ($roomValues ?? array_slice([['bedroom-one', '2.92', '2.915'], ['bedroom-two', '2.92', '2.915'], ['bathroom', '1.8', '1.85'], ['entry', '1.12', '1.85'], ['living', '3.06', '3.98']], 0, $roomCount) as [$key, $length, $width]) {
            $parameters = ['length' => ['value' => $length, 'unit' => 'm', 'basis' => 'measurement']];
            if ($width !== null) {
                $parameters['width'] = ['value' => $width, 'unit' => 'm', 'basis' => 'measurement'];
            }
            $entities[] = ['key' => $key, 'type' => 'room', 'name' => $key, 'parameters' => $parameters];
        }
        app(SaveManualProjectFacts::class)->handle($actor, $project->id, $session->id, 1, (string) Str::uuid(), ['entities' => $entities]);
        $session->refresh();
        $draft = $session->draft_payload;
        $draft['source_input_version'] = 'sha256:'.app(EvaluationInputFingerprint::class)->fromSession($session);
        $session->update(['status' => 'estimate_review_required',
            'draft_payload' => app(BuildMostEstimateDraft::class)->seal(app(UniversalReviewSummaryProjector::class)->project($draft))]);
        Route::bind('project', static fn (): Project => $project);
        Route::bind('session', static fn (): EstimateGenerationSession => $session->fresh());
        $route = '/_contract/ceilings/projects/{project}/sessions/{session}/evaluation/room-ceilings';
        Route::get($route, [EstimateGenerationCeilingController::class, 'show'])->middleware(['auth:api_admin', SubstituteBindings::class]);
        Route::post($route, [EstimateGenerationCeilingController::class, 'accept'])->middleware(['auth:api_admin', SubstituteBindings::class]);
        $this->withToken(auth('api_admin')->login($actor));

        return [$actor, $session, str_replace(['{project}', '{session}'], [(string) $project->id, (string) $session->id], $route)];
    }

    private function input(array $preview): array
    {
        return ['request_id' => (string) Str::uuid(), 'state_version' => $preview['state_version'], 'parent_work_item_key' => $preview['parent_work_item_key'],
            'preview_fingerprint' => $preview['preview_fingerprint'], 'method' => $preview['method'],
            'reason' => 'Для сметного расчёта принят плоский прямоугольный потолок каждого помещения по подтверждённым внутренним размерам.'];
    }
}
