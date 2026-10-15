<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetEvaluationTechnologies;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationTechnologySelection;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalEvaluationBuilder;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceData;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceProducer;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Planning\OrganizationPreferenceContext;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyRecommendationService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class EvaluationTechnologySelectionPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_catalog_technology_is_free_replayable_scoped_and_does_not_rewrite_the_old_composition(): void
    {
        [$actor, $session, $models] = $this->fixture();
        Http::preventStrayRequests();
        Http::fake();
        $choices = app(GetEvaluationTechnologies::class)->handle($actor, (int) $session->project_id, (int) $session->id);
        self::assertTrue($choices['is_current']);
        self::assertSame('0', $choices['cost_units']);
        $recommendation = $choices['recommendations'][0];
        self::assertSame('applicable', array_column($recommendation['options'], null, 'system_id')['pitched_roof.metal_tile']['applicability']);
        $input = ['request_id' => (string) Str::uuid(), 'state_version' => 0, 'planning_run_id' => (int) $recommendation['planning_run_id'],
            'decision_key' => $recommendation['decision_key'], 'system_id' => 'pitched_roof.metal_tile', 'reason' => 'Подтверждённый вариант комплектации кровли'];
        $before = $session->fresh()->draft_payload;
        $service = app(SaveEvaluationTechnologySelection::class);
        try {
            $service->handle($actor, (int) $session->project_id, (int) $session->id, [...$input, 'reason' => str_repeat('Я', 501)]);
            self::fail('An oversized UTF-8 decision reason was accepted.');
        } catch (ValidationException) {
            self::assertSame(0, $session->fresh()->state_version);
            self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        }
        $first = $service->handle($actor, (int) $session->project_id, (int) $session->id, $input);
        $replay = $service->handle($actor, (int) $session->project_id, (int) $session->id, $input);
        self::assertSame(CanonicalPipelineJson::encode($first), CanonicalPipelineJson::encode($replay));
        self::assertSame('0', $first['cost_units']);
        self::assertTrue($first['composition_refresh_required']);
        self::assertSame($before, $session->fresh()->draft_payload);
        self::assertSame(1, $session->fresh()->state_version);
        self::assertSame('pitched_roof.metal_tile', $session->fresh()->input_payload['technology_selections']['roofing']['roof:1']);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $selected = $models->fact(...[...$scope, $first['fact_id']]);
        self::assertSame('user_assumption', $selected->origin);
        self::assertSame('pitched_roof.metal_tile', $selected->value['system_id']);
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $history = $models->factsByIds(...[...$scope, ['fact:roof-material', $first['fact_id'], 'fact:roof-type']]);
            self::assertLessThanOrEqual(3, count(DB::getQueryLog()));
        } finally {
            DB::disableQueryLog();
        }
        self::assertEqualsCanonicalizing(['fact:roof-material', $first['fact_id'], 'fact:roof-type'], array_column($history, 'id'));
        $history = array_column($history, null, 'id');
        self::assertSame('unresolved', $history['fact:roof-material']->status);
        self::assertSame('fact:roof-material', $history[$first['fact_id']]->supersedesFactId);
        self::assertNotEmpty($history['fact:roof-type']->evidenceIds);
        self::assertSame([], $models->factsByIds($scope[0] + 1000, $scope[1], $scope[2], ['fact:roof-material', $first['fact_id']]));
        self::assertSame([], $models->factsByIds($scope[0], $scope[1] + 1000, $scope[2], ['fact:roof-material', $first['fact_id']]));
        self::assertSame([], $models->factsByIds($scope[0], $scope[1], $scope[2] + 1000, ['fact:roof-material', $first['fact_id']]));
        $review = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Review\ListEstimateReviewExceptions::class)
            ->handle($session->fresh(), ['type' => 'technology_recommendation']);
        $technologyItems = array_values(array_filter($review['items'], static fn (array $item): bool => $item['type'] === 'technology_recommendation'));
        self::assertCount(1, $technologyItems, 'The saved technology must remain visible after its question is superseded.');
        $technology = $technologyItems[0]['recommendation'];
        self::assertSame('pitched_roof.metal_tile', $technology['selected_option']);
        self::assertSame('selected', $technology['selection_state']);
        self::assertArrayHasKey('pitched_roof.metal_tile', array_column($technology['alternatives'], null, 'id'));
        self::assertSame(['other', 'leave_unresolved'], $technology['response_options']);
        self::assertCount(1, $models->decisions(...[...$scope, [$first['decision_id']]]));
        self::assertTrue(app(GetEvaluationTechnologies::class)->handle($actor, (int) $session->project_id, (int) $session->id)['composition_refresh_required']);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        Http::assertNothingSent();
        $draft = [...$before, 'evaluation_policy' => [...$before['evaluation_policy'], 'required_technology_revision' => 1]];
        $snapshot = $models->snapshot(...[...$scope, 10001]);
        $result = (new UniversalEvaluationBuilder)->build($draft, $snapshot);
        self::assertFalse($result['can_confirm_for_apply']);
        self::assertContains('technology_composition_refresh', array_column($result['missing_requirements'], 'requirement'));
        self::assertSame($before['local_estimates'][0]['sections'][0]['work_items'][0]['key'], $result['scenarios'][0]['positions'][0]['key']);
        try {
            $service->handle($actor, (int) $session->project_id, (int) $session->id, [...$input, 'system_id' => 'pitched_roof.standing_seam']);
            self::fail('Conflicting replay was accepted.');
        } catch (ValidationException) {
            self::assertSame(1, $session->fresh()->state_version);
        }
        $actor->update(['is_active' => false]);
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $service->handle($actor, (int) $session->project_id, (int) $session->id, $input);
    }

    public function test_reselection_checks_the_current_fact_and_decision_and_keeps_both_choices_in_history(): void
    {
        [$actor, $session, $models] = $this->fixture();
        Http::preventStrayRequests();
        Http::fake();
        $projectId = (int) $session->project_id;
        $sessionId = (int) $session->id;
        $recommendation = app(GetEvaluationTechnologies::class)->handle($actor, $projectId, $sessionId)['recommendations'][0];
        $service = app(SaveEvaluationTechnologySelection::class);
        $first = $service->handle($actor, $projectId, $sessionId, ['request_id' => (string) Str::uuid(), 'state_version' => 0,
            'planning_run_id' => (int) $recommendation['planning_run_id'], 'decision_key' => $recommendation['decision_key'],
            'system_id' => 'pitched_roof.metal_tile', 'reason' => 'Комплектация первого варианта']);
        $choices = app(GetEvaluationTechnologies::class)->handle($actor, $projectId, $sessionId);
        self::assertCount(1, $choices['selected']);
        self::assertSame($first['fact_id'], $choices['selected'][0]['fact_id']);
        self::assertTrue($choices['selected'][0]['catalog_current']);
        self::assertNotEmpty($choices['selected'][0]['label']);
        self::assertStringNotContainsString('estimate_generation.', $choices['selected'][0]['label']);
        self::assertSame('applicable', array_column($choices['selected'][0]['options'], null, 'system_id')['pitched_roof.standing_seam']['applicability']);
        $input = ['request_id' => (string) Str::uuid(), 'state_version' => 1, 'current_fact_id' => $first['fact_id'],
            'current_decision_id' => $first['decision_id'], 'system_id' => 'pitched_roof.standing_seam', 'reason' => 'Подтверждена другая комплектация'];
        $draft = $session->fresh()->draft_payload;
        $second = $service->handle($actor, $projectId, $sessionId, $input);
        self::assertSame(CanonicalPipelineJson::encode($second), CanonicalPipelineJson::encode($service->handle($actor, $projectId, $sessionId, $input)));
        self::assertSame(2, $second['state_version']);
        self::assertSame('0', $second['cost_units']);
        self::assertSame($draft, $session->fresh()->draft_payload);
        $scope = [(int) $session->organization_id, $projectId, $sessionId];
        $selected = $models->fact(...[...$scope, $second['fact_id']]);
        self::assertSame($first['fact_id'], $selected->supersedesFactId);
        self::assertSame('pitched_roof.standing_seam', $selected->value['system_id']);
        $review = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Review\ListEstimateReviewExceptions::class)
            ->handle($session->fresh(), ['type' => 'technology_recommendation']);
        $technologyItems = array_values(array_filter($review['items'], static fn (array $item): bool => $item['type'] === 'technology_recommendation'));
        self::assertCount(1, $technologyItems);
        self::assertSame('pitched_roof.standing_seam', $technologyItems[0]['recommendation']['selected_option']);
        self::assertSame('selected', $technologyItems[0]['recommendation']['selection_state']);
        self::assertCount(2, $models->decisions(...[...$scope, [$first['decision_id'], $second['decision_id']]]));
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $sessionId)->count());
        Http::assertNothingSent();
        $this->expectException(ValidationException::class);
        $service->handle($actor, $projectId, $sessionId, [...$input, 'request_id' => (string) Str::uuid(), 'state_version' => 2]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unverifiedReviewSelectionContexts')]
    public function test_review_does_not_present_a_saved_choice_as_current_after_catalog_or_mirror_changes(string $change): void
    {
        [$actor, $session] = $this->fixture();
        Http::preventStrayRequests();
        Http::fake();
        $recommendation = app(GetEvaluationTechnologies::class)->handle($actor, (int) $session->project_id, (int) $session->id)['recommendations'][0];
        app(SaveEvaluationTechnologySelection::class)->handle($actor, (int) $session->project_id, (int) $session->id, [
            'request_id' => (string) Str::uuid(), 'state_version' => 0, 'planning_run_id' => (int) $recommendation['planning_run_id'],
            'decision_key' => $recommendation['decision_key'], 'system_id' => 'pitched_roof.metal_tile', 'reason' => 'Сохранённый вариант']);
        if ($change === 'input_mirror') {
            $input = $session->fresh()->input_payload;
            $input['technology_selections']['roofing']['roof:1'] = 'pitched_roof.standing_seam';
            $session->forceFill(['input_payload' => $input])->save();
        } else {
            $catalog = app(\App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologySystemCatalog::class);
            $catalog = new \App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologySystemCatalog(
                $change === 'catalog_version' ? 'changed' : $catalog->version,
                $change === 'catalog_hash' ? str_repeat('0', 64) : $catalog->contentHash, $catalog->systems, $catalog->requiredFacts);
            $this->app->instance(\App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologySystemCatalog::class, $catalog);
            $this->app->instance(TechnologyRecommendationService::class, new TechnologyRecommendationService($catalog));
        }
        $review = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Review\ListEstimateReviewExceptions::class)
            ->handle($session->fresh(), ['type' => 'technology_recommendation']);
        $technologyItems = array_values(array_filter($review['items'], static fn (array $item): bool => $item['type'] === 'technology_recommendation'));
        self::assertCount(1, $technologyItems);
        self::assertNull($technologyItems[0]['recommendation']['selected_option']);
        self::assertSame('requires_review', $technologyItems[0]['recommendation']['selection_state']);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        Http::assertNothingSent();
    }

    public static function unverifiedReviewSelectionContexts(): array
    {
        return [['catalog_version'], ['catalog_hash'], ['input_mirror']];
    }

    private function fixture(): array
    {
        config(['estimate-generation.universal_enabled' => true, 'cache.default' => 'array']);
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $actor = User::factory()->create(['is_active' => true, 'current_organization_id' => $organization->id]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $permissions = Mockery::mock(AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $permissions->allows('can')->andReturn(true);
        $this->app->instance(AuthorizationService::class, $permissions);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'status' => 'draft', 'state_version' => 0, 'input_payload' => ['evaluation_mode' => 'universal', 'selected_sections' => ['roofing']],
            'draft_payload' => ['evaluation_policy' => ['mode' => 'universal', 'profile_id' => 'construction', 'price_policy' => 'catalog', 'selected_sections' => ['roofing']],
                'local_estimates' => [['key' => 'roofing', 'sections' => [['key' => 'roof', 'work_items' => [['key' => 'roof:cover', 'name' => 'Покрытие кровли', 'unit' => 'm2', 'quantity' => null, 'unit_price' => null]]]]]]]]);
        $source = 'sha256:'.str_repeat('a', 64);
        $scope = [(int) $organization->id, (int) $project->id, (int) $session->id, $source, 'roof:1'];
        $target = new Fact('fact:roof-material', ...[...$scope, 'material', null, null, 0, 'unresolved', 'unresolved', []]);
        $document = EstimateGenerationDocument::query()->create(['session_id' => $session->id, 'organization_id' => $organization->id,
            'project_id' => $project->id, 'user_id' => $actor->id, 'filename' => 'roof-specification.pdf', 'mime_type' => 'application/pdf',
            'storage_path' => 'org-'.$organization->id.'/fixture-roof-specification.pdf', 'file_size_bytes' => 100,
            'checksum_sha256' => substr($source, 7), 'status' => 'ready', 'page_count' => 1]);
        $facts = [$target];
        $evidence = [];
        foreach ([['type', 'roof_type', 'pitched', null], ['slope', 'roof_slope_degrees', '28', 'degree'],
            ['geometry', 'roof_geometry', 'simple_gable', null], ['area', 'roof_area', '120', 'm2']] as [$id, $type, $value, $unit]) {
            $attribute = match ($type) {
                'roof_slope_degrees' => 'roof_slope', 'roof_area' => 'roof_area', default => 'element_type_code'
            };
            $sourceValue = ['fact_key' => $attribute, 'fact_value' => match ($type) {
                'roof_type' => 'roof_type:pitched', 'roof_geometry' => 'roof_type:gable', default => $value,
            }];
            if ($unit === 'm2') {
                $sourceValue['unit'] = $unit;
            }
            $node = app(EvidenceRepository::class)->insertOrGet(new EvidenceData((int) $organization->id, (int) $project->id, (int) $session->id,
                EvidenceType::SourceFact, EvidenceSourceType::Document, 'document:'.$document->id, $source,
                ['document_id' => (int) $document->id, 'page' => 1],
                $sourceValue, 0, EvidenceProducer::DrawingAnalyzer->value, 'sha256:'.str_repeat('b', 64)));
            $proof = new Evidence('evidence:'.$node->id, (int) $organization->id, (int) $project->id, (int) $session->id, $source, 'document:'.$document->id, 'document', page: 1);
            $evidence[] = $proof;
            $facts[] = new Fact('fact:roof-'.$id, ...[...$scope, $type, $value, $unit, 0, 'document', 'confirmed', [$proof->id]]);
        }
        $models = app(ProjectModelRepository::class);
        $models->saveSourceModel([new Entity('roof:1', (int) $organization->id, (int) $project->id, (int) $session->id, $source, 'material', 'roof:1',
            ['document_role' => 'roof_plan', 'material_code' => 'roof-system-unresolved', 'name' => 'Кровля корпуса', 'properties' => ['scope' => 'roofing']])], $facts,
            $evidence);
        $ids = [(int) $organization->id, (int) $project->id, (int) $session->id];
        $snapshot = $models->snapshotForPlanning(...[...$ids, 10001]);
        $recommendation = app(TechnologyRecommendationService::class)->recommend($snapshot['snapshot'], $target, new OrganizationPreferenceContext((int) $organization->id, []));
        self::assertTrue($models->replaceTechnologyRecommendations(...[...$ids, $source, $snapshot['token'], $recommendation->catalogVersion,
            $recommendation->catalogHash, [$recommendation], []]));

        return [$actor, $session, $models];
    }
}
