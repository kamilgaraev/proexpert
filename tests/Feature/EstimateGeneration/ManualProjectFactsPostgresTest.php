<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveManualProjectFacts;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class ManualProjectFactsPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_wall_openings_roof_floor_and_excavation_are_calculated_from_manual_facts_without_ai(): void
    {
        [$actor, $session] = $this->fixture();
        $input = ['entities' => [
            [...$this->entity('room', 'room-1', ['length' => '5000', 'width' => '4000'], 'mm'), 'floor' => '1', 'zone' => 'А', 'coverage' => ['room_walls' => 'covered_with_entities']],
            [...$this->entity('wall', 'wall-1', ['length' => '10', 'height' => '3']), 'parent_key' => 'room-1', 'floor' => '1', 'zone' => 'А', 'coverage' => ['wall_openings' => 'covered_with_entities']],
            [...$this->entity('opening', 'door-1', ['width' => '1', 'height' => '2']), 'parent_key' => 'wall-1', 'floor' => '1', 'zone' => 'А'],
            ['key' => 'roof-1', 'type' => 'roof', 'coverage' => ['roof_facets' => 'covered_with_entities', 'roof_openings' => 'covered_empty']],
            [...$this->entity('roof_facet', 'facet-1', ['plan_area' => '40', 'slope_rise' => '3', 'slope_run' => '4']), 'parent_key' => 'roof-1',
                'parameters' => ['plan_area' => ['value' => '40', 'unit' => 'm2', 'basis' => 'input'], 'slope_rise' => ['value' => '3', 'unit' => 'm', 'basis' => 'input'], 'slope_run' => ['value' => '4', 'unit' => 'm', 'basis' => 'input']]],
            ['key' => 'site-1', 'type' => 'site', 'parameters' => ['area' => ['value' => '100', 'unit' => 'm2', 'basis' => 'input'], 'depth' => ['value' => '0.5', 'unit' => 'm', 'basis' => 'input']]],
        ]];
        $request = (string) Str::uuid();
        $save = app(SaveManualProjectFacts::class);
        $result = $save->handle($actor, (int) $session->project_id, (int) $session->id, 0, $request, $input);
        self::assertSame('20', $result['quantities']['floor_area']['amount']);
        self::assertSame('28', $result['quantities']['net_wall_area']['amount']);
        self::assertSame('50', $result['quantities']['roof_area']['amount']);
        self::assertSame('50', $result['quantities']['earthwork_volume']['amount']);
        self::assertSame('0', $result['cost_units']);
        $replay = $save->handle($actor, (int) $session->project_id, (int) $session->id, 0, $request, $input);
        self::assertEquals($result, $replay);
        self::assertSame(1, $session->fresh()->state_version);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->count());
    }

    public function test_unconfirmed_empty_openings_block_wall_area_and_confirmation_creates_a_new_fact_revision(): void
    {
        [$actor, $session] = $this->fixture();
        $save = app(SaveManualProjectFacts::class);
        $wall = $this->entity('wall', 'wall-1', ['length' => '10', 'height' => '3']);
        $result = $save->handle($actor, (int) $session->project_id, (int) $session->id, 0, (string) Str::uuid(), ['entities' => [$wall]]);
        self::assertArrayNotHasKey('net_wall_area', $result['quantities']);
        self::assertSame('geometry_coverage_unknown', $result['warnings'][0]['inputs'][0]['code']);
        $wall['coverage'] = ['wall_openings' => 'covered_empty'];
        $wall['parameters']['length']['value'] = '12';
        $result = $save->handle($actor, (int) $session->project_id, (int) $session->id, 1, (string) Str::uuid(), ['entities' => [$wall]]);
        self::assertSame('36', $result['quantities']['net_wall_area']['amount']);
        $snapshot = app(ProjectModelRepository::class)->snapshot((int) $session->organization_id, (int) $session->project_id, (int) $session->id);
        $length = array_values(array_filter($snapshot->facts, static fn ($fact): bool => $fact->type === 'length'));
        self::assertCount(1, $length);
        self::assertSame(2, $length[0]->version);
        self::assertNotNull($length[0]->supersedesFactId);
    }

    public function test_stale_request_foreign_scope_and_wrong_unit_cannot_write_manual_facts(): void
    {
        [$actor, $session] = $this->fixture();
        $save = app(SaveManualProjectFacts::class);
        $input = ['entities' => [$this->entity('room', 'room-1', ['length' => '3'], 'm2')]];
        try {
            $save->handle($actor, (int) $session->project_id, (int) $session->id, 0, (string) Str::uuid(), $input);
            self::fail('Dimension mismatch accepted.');
        } catch (ValidationException) {
            self::addToAssertionCount(1);
        }
        self::assertSame(0, DB::table('estimate_generation_project_model_assertions')->where('session_id', $session->id)->count());
        self::assertSame(0, DB::table('estimate_generation_evidence')->where('session_id', $session->id)->count());
        try {
            $save->handle($actor, (int) $session->project_id, (int) $session->id, 9, (string) Str::uuid(), $input);
            self::fail('Stale mutation accepted.');
        } catch (StaleEstimateGenerationState) {
            self::addToAssertionCount(1);
        }
        $actor->current_organization_id = Organization::factory()->create()->id;
        $actor->save();
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $save->handle($actor, (int) $session->project_id, (int) $session->id, 0, (string) Str::uuid(), $input);
    }

    public function test_assumed_size_is_marked_as_estimated_and_flat_roof_has_no_invented_slope(): void
    {
        [$actor, $session] = $this->fixture();
        $room = $this->entity('room', 'room-1', ['length' => '5', 'width' => '4']);
        $room['parameters']['width']['basis'] = 'assumption';
        $result = app(SaveManualProjectFacts::class)->handle($actor, (int) $session->project_id, (int) $session->id, 0, (string) Str::uuid(), ['entities' => [
            $room, ['key' => 'roof-1', 'type' => 'roof', 'coverage' => ['roof_facets' => 'covered_with_entities', 'roof_openings' => 'covered_empty']],
            ['key' => 'facet-1', 'type' => 'roof_facet', 'parent_key' => 'roof-1', 'parameters' => [
                'plan_area' => ['value' => '40', 'unit' => 'm2', 'basis' => 'measurement'],
                'slope_rise' => ['value' => '0', 'unit' => 'm', 'basis' => 'measurement'],
                'slope_run' => ['value' => '4', 'unit' => 'm', 'basis' => 'measurement'],
            ]],
        ]]);
        self::assertSame('estimated', $result['quantities']['floor_area']['source']);
        self::assertCount(1, $result['quantities']['floor_area']['assumptions']);
        self::assertSame('40', $result['quantities']['roof_area']['amount']);
        self::assertSame('evidenced', $result['quantities']['roof_area']['source']);
    }

    private function entity(string $type, string $key, array $parameters, string $unit = 'm'): array
    {
        return ['key' => $key, 'type' => $type, 'parameters' => array_map(static fn (string $value): array => ['value' => $value, 'unit' => $unit, 'basis' => 'measurement'], $parameters)];
    }

    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->allows('canCurrent')->andReturn(true);
        $this->app->instance(AuthorizationService::class, $authorization);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'status' => 'draft', 'input_payload' => [], 'state_version' => 0]);

        return [$actor, $session];
    }
}
