<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationInputFingerprint;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\RecalculateUniversalEvaluation;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveManualProjectFacts;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalEvaluationBuilder;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\RerankWireClient;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\CataloguePriceSnapshotReader;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\ConvertEvaluationPriceUnit;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\EstimatePositionCatalog;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class EvaluationArithmeticRecalculationPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_one_existing_wall_height_answer_updates_both_work_volumes_with_a_free_revision(): void
    {
        config(['estimate-generation.universal_enabled' => true]);
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $actor = User::factory()->create(['is_active' => true, 'current_organization_id' => $organization->id]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $permissions = Mockery::mock(AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(AuthorizationService::class, $permissions);
        $wire = $this->createMock(RerankWireClient::class);
        $wire->expects(self::never())->method('call');
        $this->app->instance(RerankWireClient::class, $wire);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'status' => 'draft', 'state_version' => 0, 'input_payload' => ['evaluation_mode' => 'universal',
                'price_policy' => 'catalog', 'profile_id' => 'construction', 'construction_type' => 'new_construction', 'selected_sections' => ['finishing']]]);
        $scope = [(int) $organization->id, (int) $project->id, (int) $session->id];
        $manual = app(SaveManualProjectFacts::class);
        $saved = $manual->handle($actor, $scope[1], $scope[2], 0, (string) Str::uuid(), ['entities' => [
            ['key' => 'wall-1', 'type' => 'wall', 'parameters' => ['length' => ['value' => '10', 'unit' => 'm', 'basis' => 'measurement']],
                'coverage' => ['wall_openings' => 'covered_empty']],
        ]]);
        $models = app(ProjectModelRepository::class);
        $snapshot = $models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        $wall = $snapshot->entities[0];
        $draft = ['generation_contract' => UniversalDraftProjector::CONTRACT,
            'source_input_version' => 'sha256:'.app(EvaluationInputFingerprint::class)->fromSession($session->fresh()),
            'evaluation_policy' => ['selected_sections' => ['finishing'], 'price_policy' => 'catalog', 'profile_id' => 'construction'],
            'quantity_coverage_warnings' => $saved['warnings'],
            'local_estimates' => [['key' => 'finishing', 'title' => 'Отделка', 'sections' => [['key' => 'walls', 'title' => 'Стены', 'work_items' => [
                ['key' => 'paint', 'work_key' => 'finish.paint', 'name' => 'Окраска стен', 'item_type' => 'priced_work', 'unit' => 'm2',
                    'quantity' => null, 'metadata' => ['scope_entity_id' => $wall->id, 'requirement_key' => 'finishing']],
                ['key' => 'plaster', 'work_key' => 'rough.walls', 'name' => 'Подготовка стен', 'item_type' => 'priced_work', 'unit' => 'm2',
                    'quantity' => null, 'metadata' => ['scope_entity_id' => $wall->id, 'requirement_key' => 'finishing']],
            ]]]]]];
        $draft['evaluation_result'] = app(UniversalEvaluationBuilder::class)->build($draft, $snapshot);
        self::assertSame('height', $draft['evaluation_result']['priority_questions'][0]['answer']['parameter']);
        self::assertSame(['paint', 'plaster'], $draft['evaluation_result']['priority_questions'][0]['affected_position_keys']);
        $session->update(['draft_payload' => app(BuildMostEstimateDraft::class)->seal(app(UniversalReviewSummaryProjector::class)->project($draft)),
            'status' => 'estimate_review_required']);
        $manual->handle($actor, $scope[1], $scope[2], 1, (string) Str::uuid(), ['entities' => [
            ['key' => 'parameter-answer', 'type' => 'wall', 'existing_entity_id' => $wall->id,
                'parameters' => ['height' => ['value' => '3', 'unit' => 'm', 'basis' => 'measurement']]],
        ]]);
        $request = (string) Str::uuid();
        $recalculate = app(RecalculateUniversalEvaluation::class);
        $result = $recalculate->handle($actor, $scope[1], $scope[2], 2, $request);
        $positions = $result['result']['scenarios'][0]['positions'];
        self::assertSame(['30', '30'], array_column($positions, 'quantity'));
        self::assertSame([$wall->id, $wall->id], array_column($positions, 'entity_id'));
        self::assertSame(['supported', 'supported'], array_column($positions, 'quantity_status'));
        self::assertSame([], $result['result']['scenarios'][0]['known_subtotals']);
        self::assertNotContains('parameter:'.$wall->id.':height', array_column($result['result']['priority_questions'], 'key'));
        self::assertSame('0', $result['cost_units']);
        self::assertSame(CanonicalPipelineJson::encode($result), CanonicalPipelineJson::encode($recalculate->handle($actor, $scope[1], $scope[2], 2, $request)));
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        $staleDraft = $session->fresh()->draft_payload;
        $staleItem = &$staleDraft['local_estimates'][0]['sections'][0]['work_items'][0];
        $staleItem['quantity_basis_details']['model_version'] = 'stale-measurement';
        $staleItem['pricing_status'] = 'calculated';
        $staleItem['pricing_blocker'] = null;
        $staleItem['evaluation_price_basis_quantity'] = '30';
        $staleItem['materials'] = [['name' => 'Ресурс прежнего основания', 'quantity' => '30', 'total_cost' => '30.00']];
        $staleItem['price_snapshot'] = ['source_type' => 'regional_resource_aggregate', 'final_amount' => '30.00',
            'source_reference' => 'old-resource-price', 'currency' => 'RUB', 'region_id' => 1, 'period_id' => 1, 'version_id' => 1,
            'coefficients' => ['work_cost' => '0.00', 'resource_evidence' => [['source_type' => 'normative_rate_base',
                'final_amount' => '30.00', 'source_reference' => 'old-resource', 'version_id' => 1, 'coefficients' => ['base_year' => 2001]]]]];
        unset($staleItem);
        $staleDraft['evaluation_result'] = app(UniversalEvaluationBuilder::class)->build($staleDraft,
            $models->snapshotForPlanning(...[...$scope, 10001])['snapshot']);
        self::assertNotNull($staleDraft['evaluation_result']['scenarios'][0]['positions'][0]['price_snapshot']);
        $session->update(['draft_payload' => app(BuildMostEstimateDraft::class)->seal(app(UniversalReviewSummaryProjector::class)->project($staleDraft))]);
        $refreshed = $recalculate->handle($actor, $scope[1], $scope[2], 3, (string) Str::uuid());
        self::assertSame('30', $refreshed['result']['scenarios'][0]['positions'][0]['quantity']);
        self::assertNull($refreshed['result']['scenarios'][0]['positions'][0]['price_snapshot']);
        self::assertSame([], $refreshed['result']['scenarios'][0]['known_subtotals']);
        $refreshedItem = $session->fresh()->draft_payload['local_estimates'][0]['sections'][0]['work_items'][0];
        self::assertArrayNotHasKey('price_snapshot', $refreshedItem);
        self::assertSame([], $refreshedItem['materials']);
    }

    public function test_changed_geometry_creates_a_new_exact_money_revision_and_replays_without_an_ai_call(): void
    {
        config(['estimate-generation.universal_enabled' => true]);
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $actor = User::factory()->create(['is_active' => true, 'current_organization_id' => $organization->id]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $permissions = Mockery::mock(AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(AuthorizationService::class, $permissions);
        $wire = $this->createMock(RerankWireClient::class);
        $wire->expects(self::never())->method('call');
        $this->app->instance(RerankWireClient::class, $wire);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'status' => 'draft', 'state_version' => 0, 'input_payload' => ['evaluation_mode' => 'universal',
                'price_policy' => 'catalog', 'profile_id' => 'construction', 'construction_type' => 'new_construction', 'selected_sections' => ['finishing']]]);
        $scope = [(int) $organization->id, (int) $project->id, (int) $session->id];
        $manual = app(SaveManualProjectFacts::class);
        $input = ['entities' => [['key' => 'room-1', 'type' => 'room', 'parameters' => ['area' => ['value' => '20', 'unit' => 'm2', 'basis' => 'input']]]]];
        $saved = $manual->handle($actor, $scope[1], $scope[2], 0, (string) Str::uuid(), $input);
        $quantity = $saved['quantities']['floor_area'];
        $priceUnit = MeasurementUnit::query()->create(['organization_id' => $organization->id, 'name' => 'Сто квадратных метров', 'short_name' => '100 м²', 'type' => 'area']);
        $catalog = EstimatePositionCatalog::query()->create(['organization_id' => $organization->id, 'created_by_user_id' => $actor->id,
            'name' => 'Отделка пола', 'code' => 'ARITH-'.Str::random(8), 'item_type' => 'work', 'measurement_unit_id' => $priceUnit->id,
            'unit_price' => '1234.57', 'is_active' => true, 'metadata' => ['currency' => 'RUB', 'price_as_of_date' => '2026-10-01', 'price_region' => 'Москва', 'vat_mode' => 'not_applicable', 'delivery_included' => true]]);
        $price = app(ConvertEvaluationPriceUnit::class)->convert(app(CataloguePriceSnapshotReader::class)->read($scope[0], 'catalog:'.$catalog->id), 'm2');
        $item = ['key' => 'floor:1', 'name' => 'Отделка пола', 'work_key' => 'finishing', 'unit' => 'm2', 'quantity' => '20',
            'quantity_status' => 'supported', 'quantity_basis_details' => $quantity, 'quantity_evidence' => $quantity,
            'metadata' => ['quantity_key' => 'floor_area'], 'commercial_price_snapshot' => $price];
        $draft = ['generation_contract' => UniversalDraftProjector::CONTRACT,
            'source_input_version' => 'sha256:'.app(EvaluationInputFingerprint::class)->fromSession($session->fresh()),
            'evaluation_policy' => ['selected_sections' => ['finishing'], 'profile_id' => 'construction', 'construction_type' => 'new_construction'],
            'local_estimates' => [['key' => 'finishing', 'sections' => [['key' => 'floor', 'work_items' => [$item]]]]]];
        $snapshot = app(ProjectModelRepository::class)->snapshot(...$scope);
        $draft['evaluation_result'] = (new UniversalEvaluationBuilder)->build($draft, $snapshot);
        self::assertSame('246.91', $draft['evaluation_result']['scenarios'][0]['known_subtotals']['RUB']);
        $session->refresh()->update(['draft_payload' => (new BuildMostEstimateDraft)->seal((new UniversalReviewSummaryProjector)->project($draft)), 'status' => 'estimate_review_required']);
        $input['entities'][0]['parameters']['area']['value'] = '25';
        $manual->handle($actor, $scope[1], $scope[2], 1, (string) Str::uuid(), $input);
        $request = (string) Str::uuid();
        $result = app(RecalculateUniversalEvaluation::class)->handle($actor, $scope[1], $scope[2], 2, $request);
        self::assertSame('308.64', $result['result']['scenarios'][0]['known_subtotals']['RUB']);
        self::assertSame('25', $result['result']['scenarios'][0]['positions'][0]['quantity']);
        self::assertSame($quantity['formula_inputs']['entity_id'], $result['result']['scenarios'][0]['positions'][0]['entity_id']);
        self::assertSame('0', $result['cost_units']);
        self::assertSame('estimate_review_required', $session->fresh()->status->value);
        self::assertSame(CanonicalPipelineJson::encode($result), CanonicalPipelineJson::encode(app(RecalculateUniversalEvaluation::class)->handle($actor, $scope[1], $scope[2], 2, $request)));
        self::assertSame(1, DB::table('estimate_generation_evaluation_revisions')->where('session_id', $session->id)->count());
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        $catalog->update(['unit_price' => '2000']);
        $changedSource = app(RecalculateUniversalEvaluation::class)->handle($actor, $scope[1], $scope[2], 3, (string) Str::uuid());
        self::assertSame('25', $changedSource['result']['scenarios'][0]['positions'][0]['quantity']);
        self::assertNull($changedSource['result']['scenarios'][0]['positions'][0]['unit_price']);
        self::assertNull($changedSource['result']['scenarios'][0]['positions'][0]['total_cost']);
        self::assertSame([], $changedSource['result']['scenarios'][0]['known_subtotals']);
    }
}
