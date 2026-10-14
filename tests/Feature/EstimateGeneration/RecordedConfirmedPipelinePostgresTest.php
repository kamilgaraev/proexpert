<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimate;
use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimateCommand;
use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\BindEvaluationWorkEntity;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\ConfirmEvaluationScenario;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetRoomIdentitySources;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\RecalculateUniversalEvaluation;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationPriceSelection;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationScenarioSet;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationScopeSelection;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveManualProjectFacts;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkDatasetType;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPredictionCaseData;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\LocalBenchmarkObjectReader;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\CataloguePriceSnapshotReader;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateCalculationService;
use App\BusinessModules\Features\BudgetEstimates\Services\Export\EstimateExportService;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\Estimate;
use App\Models\EstimatePositionCatalog;
use App\Models\MeasurementUnit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;
use Tests\Support\EstimateGeneration\RecordedUniversalPipelineAdapter;

final class RecordedConfirmedPipelinePostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_recorded_composer_result_reaches_confirmation_apply_recalculation_and_export_without_replacing_its_work_items(): void
    {
        $root = base_path('tests/Fixtures/EstimateGeneration/universal-replay');
        $name = 'text-confirmable-finishing-v1';
        $fixture = $root.'/'.$name.'.json';
        $input = $name.'-input.txt';
        $adapter = new RecordedUniversalPipelineAdapter($this->app, new LocalBenchmarkObjectReader($root), $fixture, hash_file('sha256', $fixture));
        $run = $adapter->run(new BenchmarkPredictionCaseData($name, BenchmarkDatasetType::Regression, BenchmarkSourceType::TextDescription,
            $input, hash_file('sha256', $root.'/'.$input), ['joined_apply_regression'], []), 300000);
        self::assertSame('success', $run->status);
        $session = EstimateGenerationSession::query()->findOrFail($adapter->lastRun['session_id']);
        $actor = User::query()->findOrFail($session->user_id);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $signature = app(BuildMostEstimateDraft::class);
        self::assertTrue($signature->verifyArtifact($session->draft_payload));
        self::assertContains('preparation', array_column($adapter->lastRun['evaluation']['scenarios'][0]['positions'], 'work_key'));
        self::assertContains('finishing', array_column($adapter->lastRun['evaluation']['scenarios'][0]['positions'], 'work_key'));
        self::assertCount(count(ProcessingStage::cases()), $adapter->lastRun['completed_stages']);
        self::assertSame(2, $adapter->lastRun['provider_requests']);
        $originalKeys = array_column($session->draft_payload['evaluation_result']['scenarios'][0]['positions'], 'key');

        // Use ordinary free user actions on the actual composer draft, never substitute fixture items.
        app(SaveManualProjectFacts::class)->handle($actor, $scope[1], $scope[2], (int) $session->state_version, (string) Str::uuid(),
            ['entities' => [['key' => 'room', 'type' => 'room', 'name' => 'Комната отделки']]]);
        self::assertSame([], app(GetRoomIdentitySources::class)->handle($actor, $scope[1], $scope[2])['sources'], 'Legacy clients only receive document sources.');
        $manualSources = app(GetRoomIdentitySources::class)->handle($actor, $scope[1], $scope[2], includeManual: true)['sources'];
        self::assertCount(1, $manualSources);
        $source = $manualSources[0];
        self::assertSame('Комната отделки', $source['name']);
        self::assertSame('confirmed', $source['status']);
        self::assertSame('user_input', $source['source_kind']);
        self::assertNull($source['evidence']['document_id']);
        self::assertNull($source['evidence']['page']);
        self::assertFalse($source['dimensions_confirmed']);
        app(SaveManualProjectFacts::class)->handle($actor, $scope[1], $scope[2], (int) $session->fresh()->state_version, (string) Str::uuid(),
            ['entities' => [['key' => 'room', 'type' => 'room', 'name' => 'Комната после уточнения']]]);
        $currentVersion = (int) $session->fresh()->state_version;
        try {
            app(BindEvaluationWorkEntity::class)->handle($actor, $scope[1], $scope[2], ['request_id' => (string) Str::uuid(),
                'state_version' => $currentVersion, 'work_item_key' => $originalKeys[0],
                'source_fact_id' => $source['source_fact_id'], 'source_fact_version' => $source['source_fact_version'],
                'source_value_hash' => $source['source_value_hash'], 'reason' => 'Попытка использовать прежнее подтверждение после переименования.']);
            self::fail('The old manual identity must not be accepted with the current session version.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('entity', $exception->errors());
        }
        self::assertSame($currentVersion, (int) $session->fresh()->state_version);
        $currentSources = app(GetRoomIdentitySources::class)->handle($actor, $scope[1], $scope[2], includeManual: true)['sources'];
        self::assertCount(1, $currentSources);
        self::assertSame($source['entity_id'], $currentSources[0]['entity_id']);
        self::assertNotSame($source['source_fact_id'], $currentSources[0]['source_fact_id']);
        self::assertNotSame($source['source_value_hash'], $currentSources[0]['source_value_hash']);
        self::assertSame('Комната после уточнения', $currentSources[0]['name']);
        $source = $currentSources[0];
        app(SaveEvaluationScopeSelection::class)->handle($actor, $scope[1], $scope[2], ['request_id' => (string) Str::uuid(),
            'state_version' => $session->fresh()->state_version, 'selected_sections' => ['finishing'], 'profile_id' => 'construction',
            'construction_type' => 'new_construction', 'price_policy' => 'catalog']);
        $quantities = [];
        foreach ($adapter->lastRun['evaluation']['scenarios'][0]['positions'] as $position) {
            app(BindEvaluationWorkEntity::class)->handle($actor, $scope[1], $scope[2], ['request_id' => (string) Str::uuid(),
                'state_version' => $session->fresh()->state_version, 'work_item_key' => $position['key'],
                'source_fact_id' => $source['source_fact_id'], 'source_fact_version' => $source['source_fact_version'],
                'source_value_hash' => $source['source_value_hash'], 'reason' => 'Подтверждена применимость этой работы к выбранной комнате.']);
            $unit = MeasurementUnit::query()->create(['organization_id' => $scope[0], 'name' => 'Единица позиции '.$position['key'],
                'short_name' => $position['unit'], 'type' => 'area']);
            $catalog = EstimatePositionCatalog::query()->create(['organization_id' => $scope[0], 'name' => $position['name'],
                'code' => 'JOIN-'.Str::random(6), 'item_type' => 'work', 'measurement_unit_id' => $unit->id, 'unit_price' => '0.03',
                'is_active' => true, 'created_by_user_id' => $actor->id, 'metadata' => ['currency' => 'RUB', 'price_as_of_date' => '2026-10-01',
                    'price_region' => 'Москва', 'vat_mode' => 'included', 'vat_rate' => '20', 'delivery_included' => true]]);
            $price = app(CataloguePriceSnapshotReader::class)->read($scope[0], 'catalog:'.$catalog->id);
            app(SaveEvaluationPriceSelection::class)->handle($actor, $scope[1], $scope[2], ['request_id' => (string) Str::uuid(),
                'state_version' => $session->fresh()->state_version, 'work_item_key' => $position['key'],
                'source_reference' => $price['source_reference'], 'source_hash' => $price['source_hash']]);
            $quantities[] = ['work_item_key' => $position['key'], 'value' => '1', 'unit' => $position['unit']];
        }
        $refreshed = app(RecalculateUniversalEvaluation::class)->handle($actor, $scope[1], $scope[2],
            (int) $session->fresh()->state_version, (string) Str::uuid());
        self::assertSame('0', $refreshed['cost_units']);
        self::assertSame($originalKeys, array_column($refreshed['result']['scenarios'][0]['positions'], 'key'));
        self::assertTrue($signature->verifyArtifact($session->fresh()->draft_payload));
        app(SaveEvaluationScenarioSet::class)->handle($actor, $scope[1], $scope[2], ['request_id' => (string) Str::uuid(),
            'state_version' => $session->fresh()->state_version, 'definitions' => [['id' => 'base',
                'basis' => 'Пользователь задаёт сценарный объём одной единицы каждой выбранной работы, без утверждения измеренных размеров.',
                'quantities' => $quantities]]]);
        $revision = app(RecalculateUniversalEvaluation::class)->handle($actor, $scope[1], $scope[2], $session->fresh()->state_version, (string) Str::uuid());
        self::assertSame($originalKeys, array_column($revision['result']['scenarios'][0]['positions'], 'key'));
        self::assertTrue($revision['result']['can_confirm_for_apply'], json_encode($revision['result']['missing_requirements'], JSON_UNESCAPED_UNICODE));
        self::assertTrue($signature->verifyArtifact($session->fresh()->draft_payload));
        $expected = number_format(count($quantities) * 3 / 100, 2, '.', '');
        self::assertSame($expected, $revision['result']['scenarios'][0]['total_by_currency']['RUB']);
        $confirmation = app(ConfirmEvaluationScenario::class)->handle($actor, $scope[1], $scope[2], ['request_id' => (string) Str::uuid(),
            'state_version' => $session->fresh()->state_version, 'revision_id' => $revision['revision_id'], 'content_hash' => $revision['content_hash'],
            'scenario_id' => 'base', 'assumptions_accepted' => true]);
        $assignment = UserRoleAssignment::query()->where('user_id', $actor->id)->firstOrFail();
        $role = OrganizationCustomRole::query()->where('organization_id', $scope[0])->where('slug', $assignment->role_slug)->firstOrFail();
        $permissions = $role->module_permissions;
        $permissions['ai-estimates'][] = 'estimate_generation.apply';
        $role->update(['module_permissions' => $permissions]);
        $command = new ApplyGeneratedEstimateCommand($scope[2], $scope[0], $scope[1], $confirmation['state_version'],
            idempotencyKey: (string) Str::uuid(), artifactHash: $session->fresh()->draft_payload['artifact_hash'], actor: $actor);
        $apply = app(ApplyGeneratedEstimate::class);
        $published = $apply->handle($command);
        self::assertSame($published, $apply->handle($command));
        $estimate = Estimate::query()->findOrFail($published['estimate_id']);
        self::assertSame('analog', $estimate->calculation_method);
        self::assertSame($expected, $estimate->total_amount_with_vat);
        self::assertSame(count($quantities), $estimate->items()->whereNull('parent_work_id')->count());
        for ($iteration = 0; $iteration < 2; $iteration++) {
            app(EstimateCalculationService::class)->recalculateAll($estimate->fresh());
            self::assertSame($expected, $estimate->fresh()->total_amount_with_vat);
        }
        $export = (new \ReflectionMethod(EstimateExportService::class, 'prepareTotalsData'))->invoke(app(EstimateExportService::class), $estimate->fresh());
        self::assertSame($expected, (string) $export['total_amount_with_vat']);
        self::assertSame(2, DB::table('estimate_generation_ai_usage')->where('session_id', $scope[2])->count());
        self::assertSame(count(ProcessingStage::cases()), DB::table('estimate_generation_pipeline_checkpoints')->where('session_id', $scope[2])
            ->where('status', 'completed')->distinct()->count('stage'));
    }
}
