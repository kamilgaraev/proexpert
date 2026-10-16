<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Application\Review\RecordEstimateGenerationFeedback;
use App\BusinessModules\Addons\EstimateGeneration\Application\Review\SelectNormativeCandidate;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\RecalculateUniversalEvaluation;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationWorkQuantity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\SessionBaseInputVersionResolver;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationReviewItemService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidateManualSearchService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class CandidateOnlyDraftReviewPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    #[DataProvider('candidateOrigins')]
    public function test_published_candidates_support_review_quantity_search_selection_and_free_strict_recalculation(bool $legacyLocation): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake();
        config(['cache.default' => 'array']);
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->createQuietly();
        $actor = User::factory()->create(['is_active' => true, 'current_organization_id' => $organization->id]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $permissions = Mockery::mock(AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(AuthorizationService::class, $permissions);
        $catalog = $this->catalog();
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'status' => 'estimate_review_required', 'state_version' => 0,
            'input_payload' => ['regional_context' => $catalog['context']]]);
        $base = app(SessionBaseInputVersionResolver::class)->resolve($session);
        $scope = [(int) $organization->id, (int) $project->id, (int) $session->id];
        $capture = app(ProjectModelRepository::class)->snapshotForPlanning(...[...$scope, 10001]);
        $candidate = ['key' => 'work:floor', 'name' => 'Устройство пола', 'item_type' => 'quantity_review', 'quantity' => null, 'unit' => 'm2',
            'total_cost' => null, 'pricing_status' => 'not_calculated', 'pricing_blocker' => 'quantity_review_required',
            'quantity_basis' => '', 'source_refs' => [], 'confidence' => 0.5, 'validation_flags' => ['quantity_review_required'],
            'metadata' => ['quantity_key' => 'floor_area'], 'normative_candidates' => [['norm_id' => $catalog['norm_id']]]];
        $other = [...$candidate, 'key' => 'work:unresolved', 'name' => 'Неподтверждённая другая работа', 'normative_candidates' => []];
        $builder = new BuildMostEstimateDraft;
        $draft = $builder->build(['title' => 'Проверка кандидатов', 'source_input_version' => $base, 'input_snapshot_hash' => $capture['token'],
            'scope_identity' => ['organization_id' => $scope[0], 'project_id' => $scope[1], 'session_id' => $scope[2], 'source_version' => $base],
            'catalog_identity' => ['version' => $catalog['context']['normative_dataset_version'], 'hash' => hash('sha256', 'catalog'), 'status' => 'current'],
            'technology_identity' => ['version' => 'test:technology', 'hash' => hash('sha256', 'technology'), 'status' => 'current'],
            'rule_identity' => ['version' => 'test:rules', 'hash' => hash('sha256', 'rules'), 'status' => 'current'],
            'regional_context' => $catalog['context'], 'local_estimates' => [['key' => 'local:floor', 'title' => 'Работы на проверке',
                'source_refs' => [], 'sections' => [['key' => 'floor', 'title' => 'Полы', 'source_refs' => [], 'work_items' => [$candidate, $other]]]]]]);
        if ($legacyLocation) {
            foreach ($draft['stage6_candidate_rows'] as &$row) {
                unset($row['metadata']['stage6_review_origin']);
            }
            unset($row);
        }
        $draft = $builder->seal(app(\App\BusinessModules\Addons\EstimateGeneration\Services\EstimateValidationService::class)->validate($draft));
        $session->update(['draft_payload' => $draft]);
        self::assertCount(2, $draft['stage6_candidate_rows']);
        $queue = app(EstimateGenerationReviewItemService::class)->forSession($session->fresh());
        $review = array_column($queue['items'], null, 'work_item_key')['work:floor'];
        self::assertSame('Устройство пола', $review['work_item']['name']);
        self::assertSame('confirm_quantity', $review['required_action']);
        self::assertSame($legacyLocation ? 'stage6-review' : 'local:floor', $review['local_estimate_key']);
        $search = app(NormativeCandidateManualSearchService::class)->search($session->fresh(), 'work:floor', 'Устройство пола');
        self::assertSame('work:floor', $search['work_item_key']);
        self::assertContains($catalog['norm_id'], array_column($search['candidates'], 'norm_id'));
        $command = ['request_id' => (string) Str::uuid(), 'feedback_type' => 'quantity_confirmation', 'work_item_key' => 'work:floor',
            'payload' => ['quantity' => '1', 'unit' => 'm2', 'quantity_basis' => 'Измеренная и подтверждённая площадь 1 м2.']];
        try {
            DB::transaction(function () use ($scope, $actor, $command): void {
                app(RecordEstimateGenerationFeedback::class)->handle($scope[2], $scope[0], $scope[1], $actor, 0, $command);
                throw new \RuntimeException('rollback-candidate-feedback');
            });
            self::fail('The injected failure must roll back the complete review action.');
        } catch (\RuntimeException $exception) {
            self::assertSame('rollback-candidate-feedback', $exception->getMessage());
        }
        self::assertSame(0, (int) $session->fresh()->state_version);
        self::assertSame([], app(ProjectModelRepository::class)->currentFacts(...$scope));
        self::assertSame(0, DB::table('estimate_generation_feedback')->where('session_id', $session->id)->count());
        $feedbackId = app(RecordEstimateGenerationFeedback::class)->handle($scope[2], $scope[0], $scope[1], $actor, 0, $command);
        self::assertSame($feedbackId, app(RecordEstimateGenerationFeedback::class)->handle($scope[2], $scope[0], $scope[1], $actor, 0, $command));
        $session->refresh();
        self::assertSame(1, (int) $session->state_version);
        self::assertArrayHasKey('work:floor', $session->input_payload['quantity_selections']);
        self::assertTrue($builder->verifyArtifact($session->draft_payload));
        self::assertSame(0, $session->packages()->count());
        $partialRequest = (string) Str::uuid();
        $partial = app(RecalculateUniversalEvaluation::class)->handle($actor, $scope[1], $scope[2], 1, $partialRequest);
        self::assertSame('0', $partial['cost_units']);
        self::assertEquals($partial, app(RecalculateUniversalEvaluation::class)->handle($actor, $scope[1], $scope[2], 1, $partialRequest));
        $session->refresh();
        self::assertCount(2, $session->draft_payload['stage6_candidate_rows']);
        self::assertSame(0, $session->packages()->count());
        $positions = array_column($session->draft_payload['evaluation_result']['scenarios'][0]['positions'], null, 'key');
        self::assertSame('1', $positions['work:floor']['quantity']);
        self::assertNull($positions['work:unresolved']['quantity']);
        app(SelectNormativeCandidate::class)->handle($scope[2], $scope[0], $scope[1], $actor, 2, 'work:floor', $catalog['norm_id'], false);
        $session->refresh();
        self::assertSame(3, (int) $session->state_version);
        self::assertSame(0, $session->packages()->count());
        self::assertTrue($builder->verifyArtifact($session->draft_payload));
        $receipt = app(RecalculateUniversalEvaluation::class)->handle($actor, $scope[1], $scope[2], 3, (string) Str::uuid());
        self::assertSame('0', $receipt['cost_units']);
        $session->refresh();
        self::assertTrue($builder->verifyArtifact($session->draft_payload));
        self::assertCount(1, $session->draft_payload['stage6_candidate_rows']);
        self::assertSame('work:unresolved', $session->draft_payload['stage6_candidate_rows'][0]['key']);
        $accepted = [];
        foreach ($session->draft_payload['local_estimates'] as $estimate) {
            foreach ($estimate['sections'] as $section) {
                array_push($accepted, ...$section['work_items']);
            }
        }
        self::assertCount(1, $accepted);
        self::assertSame('work:floor', $accepted[0]['key']);
        self::assertSame('30.00', $accepted[0]['total_cost']);
        self::assertSame(1, $session->packages()->withCount('items')->get()->sum('items_count'));
        $quantity = app(SaveEvaluationWorkQuantity::class)->handle($actor, $scope[1], $scope[2], ['request_id' => (string) Str::uuid(),
            'state_version' => (int) $session->state_version, 'work_item_key' => 'work:unresolved', 'value' => '2', 'unit' => 'm2',
            'reason' => 'Подтверждён только объём второй работы; расценка ещё не выбрана.']);
        self::assertSame('0', $quantity['cost_units']);
        self::assertArrayHasKey('work:unresolved', $session->fresh()->input_payload['quantity_selections']);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        Http::assertNothingSent();
    }

    public static function candidateOrigins(): array
    {
        return ['current_origin' => [false], 'legacy_without_location' => [true]];
    }

    private function catalog(): array
    {
        $now = now();
        $key = strtolower((string) Str::ulid());
        $dataset = DB::table('estimate_dataset_versions')->insertGetId(['source_type' => 'fsnb_2022', 'version_key' => $key, 'bucket' => 'test', 'prefix' => $key, 'status' => 'parsed', 'created_at' => $now, 'updated_at' => $now]);
        $prices = DB::table('estimate_dataset_versions')->insertGetId(['source_type' => 'fgis_labor_prices', 'version_key' => $key.'-prices', 'bucket' => 'test', 'prefix' => $key.'-prices', 'status' => 'parsed', 'created_at' => $now, 'updated_at' => $now]);
        $collection = DB::table('estimate_norm_collections')->insertGetId(['dataset_version_id' => $dataset, 'code' => $key, 'name' => 'Проверенная тестовая норма', 'norm_type' => 'gesn', 'source_file' => 'recorded-test', 'created_at' => $now, 'updated_at' => $now]);
        $section = DB::table('estimate_norm_sections')->insertGetId(['collection_id' => $collection, 'code' => '11', 'name' => 'Полы', 'path' => '11/floors', 'created_at' => $now, 'updated_at' => $now]);
        $norm = DB::table('estimate_norms')->insertGetId(['collection_id' => $collection, 'code' => '11-01-001-01', 'section_code' => '11', 'section_name' => 'Полы',
            'section_id' => $section, 'name' => 'Устройство пола', 'unit' => 'м2', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('estimate_norm_resources')->insert(['estimate_norm_id' => $norm, 'resource_code' => $key, 'resource_name' => 'Труд рабочих', 'unit' => 'h', 'quantity' => '1.000000', 'resource_type' => 'labor', 'created_at' => $now, 'updated_at' => $now]);
        $region = DB::table('estimate_regions')->insertGetId(['code' => 'T-'.$key, 'name' => 'Тестовый регион', 'fgiscs_subject_id' => random_int(100000, 999999), 'created_at' => $now, 'updated_at' => $now]);
        $zone = DB::table('estimate_price_zones')->insertGetId(['estimate_region_id' => $region, 'name' => 'Тестовая зона', 'fgiscs_price_zone_id' => random_int(1000000, 1999999), 'created_at' => $now, 'updated_at' => $now]);
        $period = DB::table('estimate_price_periods')->where('year', 2088)->where('quarter', 4)->value('id')
            ?? DB::table('estimate_price_periods')->insertGetId(['fgiscs_period_id' => random_int(2000000, 2999999), 'name' => $key, 'year' => 2088, 'quarter' => 4, 'created_at' => $now, 'updated_at' => $now]);
        $version = DB::table('estimate_regional_price_versions')->insertGetId(['source' => 'fgiscs', 'region_id' => $region, 'price_zone_id' => $zone, 'period_id' => $period, 'version_key' => $key, 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('estimate_resource_prices')->insert(['dataset_version_id' => $prices, 'regional_price_version_id' => $version, 'region_id' => $region, 'price_zone_id' => $zone, 'period_id' => $period, 'resource_code' => $key, 'resource_name' => 'Труд рабочих', 'unit' => 'h', 'base_price' => '30.0000', 'price_type' => 'labor', 'raw_payload' => '{}', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('estimate_regional_price_versions')->where('id', $version)->update(['status' => 'active']);

        return ['norm_id' => $norm, 'context' => ['region_id' => $region, 'price_zone_id' => $zone, 'period_id' => $period,
            'estimate_regional_price_version_id' => $version, 'normative_dataset_version' => $key]];
    }
}
