<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\ConfirmEvaluationScenario;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationInputFingerprint;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetCurrentEvaluation;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\RecalculateUniversalEvaluation;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationPriceSelection;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationScenarioSet;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationScopeSelection;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalEvaluationBuilder;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\CataloguePriceSnapshotReader;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\EstimatePositionCatalog;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class EvaluationScenarioConfirmationPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_confirmation_is_a_real_free_choice_of_one_current_scenario_and_replays_once(): void
    {
        [$actor, $session, $catalog, $input] = $this->fixture();
        Queue::fake();
        $service = app(ConfirmEvaluationScenario::class);
        $result = $service->handle($actor, (int) $session->project_id, (int) $session->id, $input);
        self::assertEquals($result, $service->handle($actor, (int) $session->project_id, (int) $session->id, $input));
        self::assertSame('0', $result['cost_units']);
        $current = app(GetCurrentEvaluation::class)->handle($actor, (int) $session->project_id, (int) $session->id);
        self::assertFalse($current['result_stale']);
        self::assertSame('base', $current['confirmed_scenario']['scenario_id']);
        self::assertSame(['RUB' => '2100.00'], $current['confirmed_scenario']['total_by_currency']);
        self::assertNotEmpty($current['confirmed_scenario']['assumptions']);
        $payload = $session->fresh()->input_payload;
        $forged = $payload;
        $forged['confirmed_evaluation']['scenario_id'] = 'maximum';
        $session->update(['input_payload' => $forged]);
        self::assertNull(app(GetCurrentEvaluation::class)->handle($actor, (int) $session->project_id, (int) $session->id)['confirmed_scenario']);
        $session->update(['input_payload' => $payload]);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $facts = app(ProjectModelRepository::class)->snapshot(...$scope)->facts;
        self::assertCount(1, array_filter($facts, static fn ($fact): bool => $fact->type === 'evaluation_scenario' && ($fact->value['answer_kind'] ?? null) === 'selected_scenario'));
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        self::assertSame(0, DB::table('ai_credit_reservations')->where('organization_id', $session->organization_id)->count());
        self::assertNull($session->fresh()->draft_payload['local_estimates'][0]['sections'][0]['work_items'][0]['quantity']);
        Queue::assertNothingPushed();
        $catalog->update(['unit_price' => '200']);
        try {
            $service->handle($actor, (int) $session->project_id, (int) $session->id,
                [...$input, 'request_id' => (string) Str::uuid(), 'state_version' => $result['state_version']]);
            self::fail('Changed catalog price was silently confirmed.');
        } catch (ValidationException) {
            self::assertSame($result['state_version'], $session->fresh()->state_version);
        }
        $session->update(['input_payload' => [...$payload, 'description' => 'Новый состав после подтверждения']]);
        $stale = app(GetCurrentEvaluation::class)->handle($actor, (int) $session->project_id, (int) $session->id);
        self::assertTrue($stale['result_stale']);
        self::assertNull($stale['confirmed_scenario']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('confirmationDenials')]
    public function test_confirmation_rejects_unaccepted_assumptions_old_revisions_and_changed_scope(string $change): void
    {
        [$actor, $session, , $input] = $this->fixture();
        if ($change === 'assumptions') {
            $input['assumptions_accepted'] = false;
        } elseif ($change === 'revision') {
            $input['revision_id'] = (string) Str::uuid();
        } elseif ($change === 'scope') {
            $session->update(['input_payload' => [...$session->input_payload, 'selected_sections' => ['roofing']]]);
        } elseif ($change === 'actor') {
            $actor->update(['is_active' => false]);
        }
        $before = $session->fresh()->state_version;
        try {
            app(ConfirmEvaluationScenario::class)->handle($actor, (int) $session->project_id, (int) $session->id, $input);
            self::fail('An invalid scenario confirmation was accepted.');
        } catch (ValidationException|\Illuminate\Auth\Access\AuthorizationException) {
            self::assertSame($before, $session->fresh()->state_version);
            self::assertArrayNotHasKey('confirmed_evaluation', $session->fresh()->input_payload);
        }
    }

    public static function confirmationDenials(): array
    {
        return array_map(static fn (string $change): array => [$change], ['assumptions', 'revision', 'scope', 'actor']);
    }

    private function fixture(): array
    {
        config(['estimate-generation.universal_enabled' => true]);
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $actor = User::factory()->create(['is_active' => true, 'current_organization_id' => $organization->id]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $authorization = \Mockery::mock(AuthorizationService::class);
        $authorization->allows('canCurrent')->andReturn(true);
        $this->app->instance(AuthorizationService::class, $authorization);
        $unit = MeasurementUnit::query()->create(['organization_id' => $organization->id, 'name' => 'Квадратный метр', 'short_name' => 'м²', 'type' => 'area']);
        $catalog = EstimatePositionCatalog::query()->create(['organization_id' => $organization->id, 'name' => 'Работы по полу', 'code' => 'P-'.Str::random(6),
            'item_type' => 'work', 'measurement_unit_id' => $unit->id, 'unit_price' => '100', 'is_active' => true, 'created_by_user_id' => $actor->id,
            'metadata' => ['currency' => 'RUB', 'price_as_of_date' => '2026-10-01', 'price_region' => 'Москва', 'vat_mode' => 'not_applicable', 'delivery_included' => true]]);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id, 'user_id' => $actor->id,
            'status' => 'estimate_review_required', 'state_version' => 0,
            'input_payload' => ['evaluation_mode' => 'universal', 'price_policy' => 'catalog', 'selected_sections' => ['finishing']]]);
        $models = app(ProjectModelRepository::class);
        $scope = [(int) $organization->id, (int) $project->id, (int) $session->id];
        $entity = new Entity('room:fixture', ...[...$scope, 'sha256:'.hash('sha256', 'scope-without-photo-dimensions'), 'room', 'room:fixture',
            ['semantic_type' => 'room', 'identity' => ['room_name' => 'Комната']]]);
        $roomName = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact('fact:room-name',
            ...[...$scope, $entity->sourceVersion, $entity->id, 'room_name', 'Комната', null, 0.0, 'user_assumption', 'confirmed', []]);
        $models->saveSourceModel([$entity], [$roomName], []);
        $models->applyDecision(new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision('decision:room-name',
            ...[...$scope, $entity->sourceVersion, 'fact', $roomName->id, $roomName->id, 'user', (string) $actor->id,
                'Пользователь задаёт помещение сценария без утверждения его размеров.', 1, []]), $roomName);
        $items = [];
        foreach (['preparation', 'finishing'] as $key) {
            $items[] = ['key' => $key, 'name' => $key, 'work_key' => $key, 'item_type' => 'priced_work', 'unit' => 'm2',
                'quantity' => null, 'quantity_status' => 'unknown', 'metadata' => ['entity_id' => $entity->id]];
        }
        $draft = ['generation_contract' => UniversalDraftProjector::CONTRACT,
            'source_input_version' => 'sha256:'.app(EvaluationInputFingerprint::class)->fromSession($session),
            'evaluation_policy' => ['selected_sections' => ['finishing'], 'price_policy' => 'catalog'],
            'local_estimates' => [['key' => 'finishing', 'sections' => [['key' => 'floor', 'work_items' => $items]]]]];
        $draft['evaluation_result'] = (new UniversalEvaluationBuilder)->build($draft, $models->snapshot(...$scope));
        $session->update(['draft_payload' => (new BuildMostEstimateDraft)->seal((new UniversalReviewSummaryProjector)->project($draft))]);
        app(SaveEvaluationScopeSelection::class)->handle($actor, $scope[1], $scope[2], ['request_id' => (string) Str::uuid(), 'state_version' => 0,
            'selected_sections' => ['finishing'], 'profile_id' => 'construction', 'construction_type' => 'new_construction', 'price_policy' => 'catalog']);
        $source = app(CataloguePriceSnapshotReader::class)->read($scope[0], 'catalog:'.$catalog->id);
        foreach (['preparation', 'finishing'] as $key) {
            app(SaveEvaluationPriceSelection::class)->handle($actor, $scope[1], $scope[2], ['request_id' => (string) Str::uuid(),
                'state_version' => $session->fresh()->state_version, 'work_item_key' => $key,
                'source_reference' => $source['source_reference'], 'source_hash' => $source['source_hash']]);
        }
        $definitions = [];
        foreach (['minimum' => '12', 'base' => '20', 'maximum' => '30'] as $id => $value) {
            $definitions[] = ['id' => $id, 'basis' => 'Явно заданный пользователем сценарный объём без подтверждения размеров фотографии',
                'quantities' => [['work_item_key' => 'preparation', 'value' => '1', 'unit' => 'm2'], ['work_item_key' => 'finishing', 'value' => $value, 'unit' => 'm2']]];
        }
        app(SaveEvaluationScenarioSet::class)->handle($actor, $scope[1], $scope[2], ['request_id' => (string) Str::uuid(),
            'state_version' => $session->fresh()->state_version, 'definitions' => $definitions]);
        $revision = app(RecalculateUniversalEvaluation::class)->handle($actor, $scope[1], $scope[2], $session->fresh()->state_version, (string) Str::uuid());
        self::assertTrue($revision['result']['scope_confirmed']);
        self::assertTrue($revision['result']['can_confirm_for_apply'], json_encode([
            'requirements' => $revision['result']['missing_requirements'],
            'unpriced' => $revision['result']['scenarios'][1]['unpriced_positions'],
            'conditions' => $revision['result']['scenarios'][1]['price_conditions_unresolved'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $input = ['request_id' => (string) Str::uuid(), 'state_version' => $session->fresh()->state_version,
            'revision_id' => $revision['revision_id'], 'content_hash' => $revision['content_hash'], 'scenario_id' => 'base', 'assumptions_accepted' => true];

        return [$actor, $session->fresh(), $catalog, $input];
    }
}
