<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\CreateEstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\DerivedQuantityFactory;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class InitialProjectFactsPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_initial_numbers_are_atomic_user_facts_with_evidence_and_decisions_and_area_is_calculable(): void
    {
        [$actor, $attributes] = $this->fixture();
        $session = app(CreateEstimateGenerationSession::class)->handle($attributes, $actor);
        $models = app(ProjectModelRepository::class);
        $capture = $models->snapshotForUnderstanding((int) $session->organization_id, (int) $session->project_id, (int) $session->id, 100);
        $snapshot = $capture['snapshot'];
        self::assertCount(3, $snapshot->facts);
        self::assertSame(['user_input'], array_values(array_unique(array_column($snapshot->facts, 'origin'))));
        $area = array_values(array_filter($snapshot->facts, static fn ($fact): bool => $fact->type === 'area'))[0];
        self::assertSame('72.19', $area->value);
        self::assertSame('confirmed', $area->status);
        self::assertCount(3, $snapshot->evidence);
        self::assertSame(['user_input'], array_values(array_unique(array_column($snapshot->evidence, 'sourceType'))));
        $decisions = $models->decisionsForSelectedFacts((int) $session->organization_id, (int) $session->project_id,
            (int) $session->id, array_column($snapshot->facts, 'id'));
        self::assertCount(3, $decisions);
        self::assertSame([(string) $actor->id], array_values(array_unique(array_column($decisions, 'actorId'))));
        $request = [
            'quantity_id' => 'quantity:initial-area', 'formula_identity' => 'direct_floor_area', 'formula_version' => '1',
            'entity_id' => $area->entityId, 'operands' => ['measurement' => $area->id],
            'output_unit' => 'm2', 'rounding_mode' => 'half_up', 'rounding_scale' => 2,
            'snapshot' => ['input_fingerprint' => str_repeat('b', 64), 'artifact_hash' => str_repeat('c', 64),
                'catalog_version' => 'technology:v1', 'catalog_hash' => str_repeat('d', 64),
                'rule_version' => 'completeness:v1', 'rule_hash' => str_repeat('e', 64)],
        ];
        $quantity = (new DerivedQuantityFactory)->derive($snapshot, $decisions, $request);
        self::assertTrue($quantity->isReady(), json_encode($quantity->unresolvedInputs, JSON_THROW_ON_ERROR));
        self::assertSame('72.19', $quantity->quantity?->value);
        self::assertSame($decisions[0]->actorId, $quantity->quantity?->operands[0]['decision_actor_id'] ?? null);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->count());
    }

    public function test_failed_initial_projection_rolls_back_session_evidence_and_partial_facts(): void
    {
        [$actor, $attributes] = $this->fixture();
        $before = EstimateGenerationSession::query()->count();
        $evidenceBefore = DB::table('estimate_generation_evidence')->count();
        $attributes['input_payload']['height'] = -2;
        try {
            app(CreateEstimateGenerationSession::class)->handle($attributes, $actor);
            self::fail('Invalid initial facts were accepted.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('initial_parameter_number_invalid', $exception->getMessage());
        }
        self::assertSame($before, EstimateGenerationSession::query()->count());
        self::assertSame($evidenceBefore, DB::table('estimate_generation_evidence')->count());
    }

    public function test_revoked_create_permission_cannot_leave_a_session_or_initial_facts(): void
    {
        [$actor, $attributes] = $this->fixture(false);
        $before = EstimateGenerationSession::query()->count();
        try {
            app(CreateEstimateGenerationSession::class)->handle($attributes, $actor);
            self::fail('Revoked creation permission was accepted.');
        } catch (AuthorizationException) {
            self::addToAssertionCount(1);
        }
        self::assertSame($before, EstimateGenerationSession::query()->count());
    }

    public function test_database_failure_after_writing_facts_rolls_back_every_part_of_initial_creation(): void
    {
        [$actor, $attributes] = $this->fixture();
        $tables = ['estimate_generation_sessions', 'estimate_generation_evidence',
            'estimate_generation_project_model_entities', 'estimate_generation_project_model_assertions'];
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = DB::table($table)->count();
        }
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION pg_temp.initial_decision_failure() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN RAISE EXCEPTION 'initial_decision_failure'; END $$;
            CREATE TRIGGER initial_decision_failure BEFORE INSERT ON estimate_generation_project_model_corrections
            FOR EACH ROW EXECUTE FUNCTION pg_temp.initial_decision_failure();
            SQL);
        try {
            app(CreateEstimateGenerationSession::class)->handle($attributes, $actor);
            self::fail('Injected failure did not stop initial creation.');
        } catch (\Illuminate\Database\QueryException $exception) {
            self::assertStringContainsString('initial_decision_failure', $exception->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER initial_decision_failure ON estimate_generation_project_model_corrections');
        }
        foreach ($counts as $table => $count) {
            self::assertSame($count, DB::table($table)->count());
        }
        self::assertSame(0, DB::transactionLevel());
    }

    private function fixture(bool $canCreate = true): array
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->allows('canCurrent')->andReturn($canCreate);
        $this->app->instance(AuthorizationService::class, $authorization);

        return [$actor, ['organization_id' => $organization->id, 'project_id' => $project->id, 'user_id' => $actor->id,
            'processing_stage' => 'draft', 'processing_progress' => 0,
            'input_payload' => ['area' => '72.19', 'floors' => 2, 'height' => '3.1', 'parameters' => []]]];
    }
}
