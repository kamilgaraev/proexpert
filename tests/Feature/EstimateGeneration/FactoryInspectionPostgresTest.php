<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Factory\FactoryInputInspector;
use App\BusinessModules\Addons\EstimateGeneration\Application\Factory\FactoryInspectionClaim;
use App\BusinessModules\Addons\EstimateGeneration\Application\Factory\FactoryInspectionStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\Factory\InspectFactoryInputs;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveManualProjectFacts;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationFactoryController;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class FactoryInspectionPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_native_http_inspection_persists_a_free_receipt_without_changing_session_model_or_finances(): void
    {
        [$actor, $session, $prefix] = $this->fixture();
        $this->withToken(auth('api_admin')->login($actor));
        $before = $session->fresh()->getRawOriginal();
        $outbound = count(Http::recorded());
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson($prefix)->assertOk()->assertJsonPath('data.run', null)->assertJsonPath('data.cost_units', '0');
        self::assertSame(0, DB::table('estimate_generation_factory_runs')->where('session_id', $session->id)->count());
        $input = ['state_version' => 1, 'request_id' => (string) Str::uuid()];
        $result = $this->postJson($prefix.'/inspections', $input)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');
        self::assertSame('awaiting_input', $result['checkpoint']['status']);
        self::assertTrue($result['fresh']);
        self::assertSame('height', $result['checkpoint']['result']['question_refs'][0]['parameter']);
        self::assertFalse($result['checkpoint']['result']['estimate_complete']);
        self::assertFalse($result['paid_actions_enabled']);
        $this->postJson($prefix.'/inspections', $input)->assertOk()->assertJsonPath('data', $result);
        self::assertSame($before, $session->fresh()->getRawOriginal());
        self::assertSame(1, DB::table('estimate_generation_factory_runs')->where('session_id', $session->id)->count());
        self::assertSame(1, DB::table('estimate_generation_factory_checkpoints')->where('session_id', $session->id)->count());
        foreach (DB::getQueryLog() as $query) {
            if (preg_match('/^(insert|update|delete)\s/i', $query['query'])) {
                self::assertStringContainsString('estimate_generation_factory_', $query['query']);
            }
        }
        DB::disableQueryLog();
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        self::assertCount($outbound, Http::recorded());
    }

    public function test_completed_receipt_survives_new_input_but_same_uuid_rejects_changed_state_or_actor(): void
    {
        [$actor, $session] = $this->fixture();
        $inspect = app(InspectFactoryInputs::class);
        $request = (string) Str::uuid();
        $first = $inspect->handle($actor, $session->project_id, $session->id, 1, $request);
        $session->update(['state_version' => 2]);
        $replay = $inspect->handle($actor, $session->project_id, $session->id, 1, $request);
        self::assertSame($first['checkpoint'], $replay['checkpoint']);
        self::assertFalse($replay['fresh']);
        $other = User::factory()->create(['current_organization_id' => $session->organization_id, 'is_active' => true]);
        $other->organizations()->attach($session->organization_id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        foreach ([$actor, $other] as $who) {
            try {
                $inspect->handle($who, $session->project_id, $session->id, $who->id === $actor->id ? 2 : 1, $request);
                self::fail('Changed request identity was replayed.');
            } catch (ValidationException) {
                self::assertSame(1, DB::table('estimate_generation_factory_checkpoints')->where('session_id', $session->id)->count());
            }
        }
        $next = $inspect->handle($actor, $session->project_id, $session->id, 2, (string) Str::uuid());
        self::assertSame($first['run']['id'], $next['run']['id']);
        self::assertSame(2, $next['run']['step_count']);
        self::assertTrue($next['fresh']);
    }

    public function test_busy_checkpoint_and_expired_recovery_fence_the_previous_owner_and_attempt(): void
    {
        [$actor, $session] = $this->fixture();
        $store = app(FactoryInspectionStore::class);
        $request = (string) Str::uuid();
        $first = $store->claim($actor, $session->project_id, $session->id, 1, $request);
        self::assertInstanceOf(FactoryInspectionClaim::class, $first);
        foreach ([$request, (string) Str::uuid()] as $id) {
            $busy = $store->claim($actor, $session->project_id, $session->id, 1, $id);
            self::assertSame($request, $busy['checkpoint']['request_id']);
            self::assertMatchesRegularExpression('/T.*(?:Z|[+-]\d{2}:\d{2})$/', $busy['checkpoint']['lease_expires_at']);
            self::assertSame(1, $busy['run']['step_count']);
        }
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));
        try {
            self::assertFalse($store->complete($first, $this->inspectionResult($first)));
            $second = $store->claim($actor, $session->project_id, $session->id, 1, $request);
            self::assertInstanceOf(FactoryInspectionClaim::class, $second);
            self::assertSame(2, $second->attempt);
            self::assertNotSame($first->ownerToken, $second->ownerToken);
            self::assertFalse($store->complete($first, $this->inspectionResult($first)));
            $wrongAttempt = new FactoryInspectionClaim($second->runId, $second->checkpointId, $second->ownerToken,
                1, $second->inputFingerprint, $second->modelToken, $second->session, $second->snapshot);
            self::assertFalse($store->complete($wrongAttempt, $this->inspectionResult($second)));
            $wrongOwner = new FactoryInspectionClaim($second->runId, $second->checkpointId, (string) Str::uuid(),
                $second->attempt, $second->inputFingerprint, $second->modelToken, $second->session, $second->snapshot);
            self::assertFalse($store->complete($wrongOwner, $this->inspectionResult($second)));
            $wrongModel = new FactoryInspectionClaim($second->runId, $second->checkpointId, $second->ownerToken,
                $second->attempt, $second->inputFingerprint, 'sha256:'.str_repeat('0', 64), $second->session, $second->snapshot);
            self::assertFalse($store->complete($wrongModel, $this->inspectionResult($second)));
            self::assertTrue($store->complete($second, $this->inspectionResult($second)));
            self::assertSame(2, $store->current($actor, $session->project_id, $session->id)['checkpoint']['attempt_count']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_recovery_is_finite_and_does_not_replace_the_run_or_reset_steps(): void
    {
        [$actor, $session] = $this->fixture();
        $store = app(FactoryInspectionStore::class);
        $request = (string) Str::uuid();
        $first = $store->claim($actor, $session->project_id, $session->id, 1, $request);
        try {
            for ($attempt = 2; $attempt <= 3; $attempt++) {
                CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));
                $claim = $store->claim($actor, $session->project_id, $session->id, 1, $request);
                self::assertSame($attempt, $claim->attempt);
            }
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));
            $stopped = $store->claim($actor, $session->project_id, $session->id, 1, $request);
            self::assertSame('exhausted', $stopped['checkpoint']['status']);
            self::assertFalse($stopped['fresh']);
            self::assertFalse($store->complete($claim, $this->inspectionResult($claim)));
            $next = app(InspectFactoryInputs::class)->handle($actor, $session->project_id, $session->id, 1, (string) Str::uuid());
            self::assertSame($first->runId, $next['run']['id']);
            self::assertSame(2, $next['run']['step_count']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_changed_session_input_without_state_bump_cannot_publish_and_recovery_stops_as_stale(): void
    {
        [$actor, $session] = $this->fixture();
        $store = app(FactoryInspectionStore::class);
        $request = (string) Str::uuid();
        $claim = $store->claim($actor, $session->project_id, $session->id, 1, $request);
        $session->update(['input_payload' => [...$session->fresh()->input_payload, 'selected_sections' => ['roofing']]]);
        self::assertFalse($store->complete($claim, $this->inspectionResult($claim)));
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));
        try {
            $recovered = $store->claim($actor, $session->project_id, $session->id, 1, $request);
            self::assertSame('stale', $recovered['checkpoint']['status']);
            self::assertFalse($recovered['fresh']);
            self::assertSame(1, $recovered['run']['step_count']);
            self::assertSame(1, $session->fresh()->state_version);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_an_expired_free_checkpoint_can_be_superseded_without_resetting_history_or_allowing_late_completion(): void
    {
        [$actor, $session] = $this->fixture();
        $store = app(FactoryInspectionStore::class);
        $oldRequest = (string) Str::uuid();
        $old = $store->claim($actor, $session->project_id, $session->id, 1, $oldRequest);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));
        try {
            $next = $store->claim($actor, $session->project_id, $session->id, 1, (string) Str::uuid());
            self::assertInstanceOf(FactoryInspectionClaim::class, $next);
            self::assertSame($old->runId, $next->runId);
            self::assertNotSame($old->checkpointId, $next->checkpointId);
            self::assertFalse($store->complete($old, $this->inspectionResult($old)));
            self::assertTrue($store->complete($next, $this->inspectionResult($next)));
            $replay = $store->claim($actor, $session->project_id, $session->id, 1, $oldRequest);
            self::assertSame('stale', $replay['checkpoint']['status']);
            self::assertSame(['expired_checkpoint_superseded'], $replay['checkpoint']['result']['limitations']);
            self::assertSame(2, $replay['run']['step_count']);
            self::assertSame(2, DB::table('estimate_generation_factory_checkpoints')->where('session_id', $session->id)->count());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_changed_project_model_without_state_bump_cannot_publish(): void
    {
        [$actor, $session] = $this->fixture();
        $store = app(FactoryInspectionStore::class);
        $claim = $store->claim($actor, $session->project_id, $session->id, 1, (string) Str::uuid());
        app(SaveManualProjectFacts::class)->handle($actor, $session->project_id, $session->id, 1, (string) Str::uuid(), [
            'entities' => [['key' => 'additional-beam', 'type' => 'foundation_beam', 'name' => 'Вторая балка']],
        ]);
        $session->update(['state_version' => 1, 'input_payload' => $claim->session->input_payload]);
        self::assertFalse($store->complete($claim, $this->inspectionResult($claim)));
        self::assertSame('running', $store->current($actor, $session->project_id, $session->id)['checkpoint']['status']);
    }

    public function test_input_byte_limit_stops_before_token_read_and_preserves_budget_metadata(): void
    {
        [$actor, $session] = $this->fixture();
        $payload = [...$session->fresh()->input_payload, 'oversized_test_note' => str_repeat('x', 1048577)];
        $session->update(['input_payload' => $payload]);
        $models = Mockery::mock(ProjectModelRepository::class);
        $models->shouldNotReceive('snapshotForPlanning');
        $this->app->instance(ProjectModelRepository::class, $models);
        $result = app(InspectFactoryInputs::class)->handle($actor, $session->project_id, $session->id, 1, (string) Str::uuid());
        self::assertSame('unavailable', $result['checkpoint']['status']);
        self::assertSame(['input_budget_exceeded'], $result['checkpoint']['result']['limitations']);
        self::assertFalse($result['fresh']);
        self::assertEquals($payload, $session->fresh()->input_payload);
        self::assertSame(1, $session->fresh()->state_version);
    }

    public function test_lifetime_step_limit_cannot_be_reset_with_new_requests(): void
    {
        [$actor, $session] = $this->fixture();
        $inspect = app(InspectFactoryInputs::class);
        $runId = null;
        for ($step = 1; $step <= 64; $step++) {
            $result = $inspect->handle($actor, $session->project_id, $session->id, 1, (string) Str::uuid());
            $runId ??= $result['run']['id'];
            self::assertSame($runId, $result['run']['id']);
            self::assertSame($step, $result['run']['step_count']);
        }
        try {
            $inspect->handle($actor, $session->project_id, $session->id, 1, (string) Str::uuid());
            self::fail('Lifetime step limit was reset.');
        } catch (ValidationException) {
            self::assertSame(64, DB::table('estimate_generation_factory_checkpoints')->where('session_id', $session->id)->count());
            self::assertSame(1, DB::table('estimate_generation_factory_runs')->where('session_id', $session->id)->count());
            self::assertSame(1, $session->fresh()->state_version);
        }
    }

    public function test_http_validation_and_tenant_scope_and_service_authorization_prevent_writes(): void
    {
        [$actor, $session, $prefix] = $this->fixture();
        $this->getJson($prefix)->assertUnauthorized();
        $this->withToken(auth('api_admin')->login($actor));
        $this->postJson($prefix.'/inspections', ['state_version' => -1, 'request_id' => 'bad'])->assertUnprocessable();
        $this->postJson($prefix.'/inspections', ['state_version' => 0, 'request_id' => (string) Str::uuid()])->assertConflict();
        $actor->update(['current_organization_id' => Organization::factory()->create()->id]);
        $this->withToken(auth('api_admin')->login($actor));
        $this->getJson($prefix)->assertNotFound();
        $this->postJson($prefix.'/inspections', ['state_version' => 1, 'request_id' => (string) Str::uuid()])->assertNotFound();
        $actor->update(['current_organization_id' => $session->organization_id]);
        $authorization = Mockery::mock(EstimateGenerationActionAuthorization::class);
        $authorization->shouldReceive('authorize')->with($actor, Mockery::type(EstimateGenerationSession::class), 'estimate_generation.review')
            ->once()->andThrow(new AuthorizationException);
        $this->app->instance(EstimateGenerationActionAuthorization::class, $authorization);
        $this->app->forgetInstance(FactoryInspectionStore::class);
        try {
            app(InspectFactoryInputs::class)->handle($actor, $session->project_id, $session->id, 1, (string) Str::uuid());
            self::fail('Service authorization was bypassed.');
        } catch (AuthorizationException) {
            self::assertSame(0, DB::table('estimate_generation_factory_runs')->where('session_id', $session->id)->count());
            self::assertSame(0, DB::table('estimate_generation_factory_checkpoints')->where('session_id', $session->id)->count());
        }
    }

    private function inspectionResult(FactoryInspectionClaim $claim): array
    {
        return app(FactoryInputInspector::class)->inspect($claim->session, $claim->snapshot, $claim->modelToken);
    }

    private function fixture(): array
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
            'user_id' => $actor->id, 'status' => 'draft', 'state_version' => 0, 'input_payload' => [
                'evaluation_mode' => 'universal', 'selected_sections' => ['structures'],
                'qa_run_id' => '5ac504c5-5ca3-41dc-95b0-f3c8745ccc44', 'qa_limit_units' => 500,
            ]]);
        app(SaveManualProjectFacts::class)->handle($actor, $project->id, $session->id, 0, (string) Str::uuid(), ['entities' => [[
            'key' => 'beam', 'type' => 'foundation_beam', 'parameters' => [
                'length' => ['value' => '10', 'unit' => 'm', 'basis' => 'measurement'],
                'width' => ['value' => '250', 'unit' => 'mm', 'basis' => 'measurement'],
            ],
        ]]]);
        Route::bind('project', static fn (): Project => $project);
        Route::bind('session', static fn (): EstimateGenerationSession => $session->fresh());
        $route = '/_contract/factory/projects/{project}/sessions/{session}/factory';
        Route::get($route, [EstimateGenerationFactoryController::class, 'show'])->middleware(['auth:api_admin', SubstituteBindings::class]);
        Route::post($route.'/inspections', [EstimateGenerationFactoryController::class, 'inspect'])->middleware(['auth:api_admin', SubstituteBindings::class]);

        return [$actor, $session, str_replace(['{project}', '{session}'], [(string) $project->id, (string) $session->id], $route)];
    }
}
