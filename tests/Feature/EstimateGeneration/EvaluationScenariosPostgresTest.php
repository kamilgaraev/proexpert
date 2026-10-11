<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationScenarioSet;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalEvaluationBuilder;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class EvaluationScenariosPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_free_scenario_choices_use_real_facts_evidence_and_decisions_and_replay_once(): void
    {
        [$actor, $session, $definitions] = $this->fixture();
        Queue::fake();
        $input = ['request_id' => (string) Str::uuid(), 'state_version' => 0, 'definitions' => $definitions];
        $service = app(SaveEvaluationScenarioSet::class);
        $result = $service->handle($actor, (int) $session->project_id, (int) $session->id, $input);
        self::assertSame('0', $result['cost_units']);
        self::assertTrue($result['recalculation_required']);
        self::assertSame(1, $result['state_version']);
        self::assertEquals($result, $service->handle($actor, (int) $session->project_id, (int) $session->id, $input));
        $session = $session->fresh();
        $models = app(ProjectModelRepository::class);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $snapshot = $models->snapshot(...$scope);
        $decisions = $models->decisionsForSelectedFacts(...[...$scope, array_column($snapshot->facts, 'id')]);
        self::assertCount(1, $snapshot->facts);
        self::assertSame('evaluation_scenario', $snapshot->facts[0]->type);
        self::assertCount(1, $decisions);
        $draft = $session->draft_payload;
        $draft['evaluation_policy']['scenario_selections'] = $session->input_payload['scenario_selections'];
        $evaluation = (new UniversalEvaluationBuilder)->build($draft, $snapshot, $decisions);
        self::assertSame(['RUB' => ['minimum' => '1200.00', 'maximum' => '3000.00']], $evaluation['scenario_range']);
        self::assertSame('assumed', $evaluation['scenarios'][0]['positions'][0]['quantity_status']);
        self::assertNull($session->draft_payload['local_estimates'][0]['sections'][0]['work_items'][0]['quantity']);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->count());
        self::assertSame(0, DB::table('ai_credit_reservations')->count());
        Queue::assertNothingPushed();
    }

    public function test_foreign_organization_cannot_save_scenarios(): void
    {
        [$actor, $session, $definitions] = $this->fixture();
        $actor->current_organization_id = Organization::factory()->create()->id;
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        try {
            app(SaveEvaluationScenarioSet::class)->handle($actor, (int) $session->project_id, (int) $session->id,
                ['request_id' => (string) Str::uuid(), 'state_version' => 0, 'definitions' => $definitions]);
        } finally {
            self::assertSame(0, $session->fresh()->state_version);
        }
    }

    private function fixture(): array
    {
        config(['estimate-generation.universal_enabled' => true]);
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $actor = User::factory()->create(['is_active' => true, 'current_organization_id' => $organization->id]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        $session = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id, 'project_id' => $project->id, 'user_id' => $actor->id,
            'status' => 'needs_input', 'state_version' => 0,
            'input_payload' => ['evaluation_mode' => 'universal', 'selected_sections' => ['finishing'], 'price_policy' => 'catalog']]);
        $draft = ['generation_contract' => UniversalDraftProjector::CONTRACT,
            'evaluation_policy' => ['selected_sections' => ['finishing'], 'price_policy' => 'catalog'],
            'local_estimates' => [['key' => 'rough_finishing', 'sections' => [['work_items' => [['key' => 'floor', 'name' => 'Пол',
                'item_type' => 'priced_work', 'quantity' => null, 'unit' => 'm2',
                'commercial_price_snapshot' => ['unit_price' => '100', 'unit' => 'm2', 'currency' => 'RUB', 'verified' => true,
                    'source_type' => 'catalog', 'source_reference' => 'catalog:1', 'as_of_date' => '2026-10-10']]]]]]]];
        $draft['evaluation_result'] = (new UniversalEvaluationBuilder)->build($draft,
            new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot([], [], [], []));
        $session->update(['draft_payload' => (new BuildMostEstimateDraft)->seal($draft)]);
        $definitions = array_map(static fn (string $id, string $quantity): array => ['id' => $id,
            'basis' => 'Явно выбранная пользователем предполагаемая площадь',
            'quantities' => [['work_item_key' => 'floor', 'value' => $quantity, 'unit' => 'm2']]],
            ['minimum', 'base', 'maximum'], ['12', '20', '30']);

        return [$actor, $session, $definitions];
    }
}
