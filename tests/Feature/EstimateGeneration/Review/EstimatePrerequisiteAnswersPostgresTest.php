<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Review;

use App\BusinessModules\Addons\EstimateGeneration\Application\Review\GetEstimatePrerequisites;
use App\BusinessModules\Addons\EstimateGeneration\Application\Review\UpdateEstimatePrerequisites;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationPrerequisiteController;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Planning\CompletenessRuleCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Planning\ProjectCompletenessAnalyzer;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologySystemCatalog;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use RuntimeException;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class EstimatePrerequisiteAnswersPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    private const SOURCE = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function test_legacy_projection_refresh_and_real_answer_close_the_question_and_replay_without_ai_or_reserve(): void
    {
        [$actor, $session] = $this->fixture();
        $this->persistProjection($session, true);
        $get = app(GetEstimatePrerequisites::class);
        $update = app(UpdateEstimatePrerequisites::class);
        $legacy = $get->handle($actor, (int) $session->project_id, (int) $session->id);
        self::assertTrue($legacy['needs_refresh']);
        self::assertFalse($this->foundation($legacy)['questions'][0]['can_answer']);
        $request = (string) Str::uuid();
        $refresh = $update->refresh($actor, (int) $session->project_id, (int) $session->id, 0, $request);
        self::assertSame(CanonicalPipelineJson::encode($refresh), CanonicalPipelineJson::encode($update->refresh($actor, (int) $session->project_id, (int) $session->id, 0, $request)));
        self::assertSame('0', $refresh['cost_units']);
        self::assertSame('failed', $session->fresh()->status->value);
        self::assertSame(['retained' => 'draft'], $session->fresh()->draft_payload);
        self::assertSame('5ac504c5-5ca3-41dc-95b0-f3c8745ccc44', $session->fresh()->input_payload['qa_run_id']);
        self::assertSame(500, $session->fresh()->input_payload['qa_limit_units']);
        $ready = $get->handle($actor, (int) $session->project_id, (int) $session->id);
        self::assertFalse($ready['needs_refresh']);
        $group = $this->foundation($ready);
        self::assertTrue($group['questions'][0]['can_answer']);
        self::assertSame(4, $group['questions'][0]['answer_contract']['decimal_places']);
        self::assertSame('1000000000000', $group['questions'][0]['answer_contract']['maximum']);
        $answer = $this->answer($group);
        $answerRequest = (string) Str::uuid();
        $result = $update->answer($actor, (int) $session->project_id, (int) $session->id, 1, $answerRequest, $answer);
        self::assertSame(CanonicalPipelineJson::encode($result), CanonicalPipelineJson::encode($update->answer($actor, (int) $session->project_id, (int) $session->id, 1, $answerRequest, $answer)));
        self::assertSame('40', $result['value']);
        self::assertSame(2, $result['state_version']);
        self::assertSame(self::SOURCE, $result['entity_source_version']);
        $fact = app(ProjectModelRepository::class)->fact(...[...$this->scope($session), $result['fact_id']]);
        self::assertSame('entity:foundation', $fact->entityId);
        self::assertSame('foundation_area', $fact->type);
        self::assertSame('user_assumption', $fact->origin);
        $decision = app(ProjectModelRepository::class)->decisions(...[...$this->scope($session), [$result['decision_id']]])[0];
        self::assertSame((string) $actor->id, $decision->actorId);
        self::assertSame($answer['reason'], $decision->reason);
        self::assertSame($fact->id, $decision->selectedFactId);
        $proof = DB::table('estimate_generation_evidence')->where('session_id', $session->id)->latest('id')->first();
        self::assertSame('source_fact', $proof->type);
        self::assertSame('user_input', $proof->source_type);
        self::assertSame(self::SOURCE, $proof->source_version);
        self::assertSame('40', json_decode($proof->value, true)['fact_value']);
        $after = $get->handle($actor, (int) $session->project_id, (int) $session->id);
        self::assertSame([], array_filter($after['groups'], static fn (array $row): bool => $row['finding_key'] === $group['finding_key']));
        self::assertFalse($session->fresh()->input_payload['input_confirmed']);
        self::assertArrayNotHasKey('confirmed_evaluation', $session->fresh()->input_payload);
        self::assertSame(['retained' => 'draft'], $session->fresh()->draft_payload);
        self::assertCount(2, $session->fresh()->input_payload['prerequisite_receipts']);
        $this->assertNoPaidEffects();
    }

    public function test_measurement_has_real_evidence_and_source_replacement_invalidates_its_projection(): void
    {
        [$actor, $session] = $this->fixture();
        $this->persistProjection($session);
        $group = $this->foundation(app(GetEstimatePrerequisites::class)->handle($actor, (int) $session->project_id, (int) $session->id));
        $input = [...$this->answer($group), 'basis' => 'measurement', 'value' => '40.1234'];
        $result = app(UpdateEstimatePrerequisites::class)->answer($actor, (int) $session->project_id, (int) $session->id, 0, (string) Str::uuid(), $input);
        $models = app(ProjectModelRepository::class);
        $fact = $models->fact(...[...$this->scope($session), $result['fact_id']]);
        self::assertSame('user_input', $fact->origin);
        self::assertSame('40.1234', $fact->value);
        $proof = DB::table('estimate_generation_evidence')->where('session_id', $session->id)->latest('id')->first();
        self::assertSame('measured', $proof->type);
        self::assertSame('40.1234', json_decode($proof->value, true)['quantity']);
        $replacement = 'sha256:'.str_repeat('b', 64);
        $models->invalidateSourceVersion(...[...$this->scope($session), self::SOURCE, $replacement]);
        self::assertSame([], array_filter($models->snapshotForPlanning(...[...$this->scope($session), 10001])['snapshot']->facts,
            static fn (Fact $candidate): bool => $candidate->id === $fact->id));
        self::assertNotNull($models->fact(...[...$this->scope($session), $fact->id]));
        $this->assertNoPaidEffects();
    }

    public function test_refresh_reopens_input_review_from_nonfailed_states_and_replays_without_a_second_transition(): void
    {
        foreach (['draft', 'input_review_required', 'ready_to_generate', 'estimate_review_required', 'ready_to_apply'] as $status) {
            [$actor, $session] = $this->fixture();
            $session->update(['status' => $status, 'processing_stage' => $status]);
            $requestId = (string) Str::uuid();
            $update = app(UpdateEstimatePrerequisites::class);
            $result = $update->refresh($actor, (int) $session->project_id, (int) $session->id, 0, $requestId);
            $after = $session->fresh();
            self::assertSame('input_review_required', $after->status->value);
            self::assertSame('input_review_required', $after->processing_stage);
            self::assertFalse($after->input_payload['input_confirmed']);
            self::assertArrayNotHasKey('confirmed_evaluation', $after->input_payload);
            self::assertSame(['retained' => 'draft'], $after->draft_payload);
            self::assertSame(1, $after->state_version);
            self::assertSame(CanonicalPipelineJson::encode($result), CanonicalPipelineJson::encode(
                $update->refresh($actor, (int) $session->project_id, (int) $session->id, 0, $requestId)));
            self::assertSame(1, $session->fresh()->state_version);
            $this->assertNoPaidEffects();
        }
    }

    public function test_refresh_preserves_failed_recovery_status_and_retained_draft(): void
    {
        foreach (['generating' => 'validate_draft', 'applying' => 'applying', 'processing_documents' => 'processing_documents'] as $resume => $stage) {
            [$actor, $session] = $this->fixture();
            $session->update(['resume_status' => $resume, 'processing_stage' => $stage]);
            app(UpdateEstimatePrerequisites::class)->refresh($actor, (int) $session->project_id, (int) $session->id, 0, (string) Str::uuid());
            $after = $session->fresh();
            self::assertSame('failed', $after->status->value);
            self::assertSame($resume, $after->getRawOriginal('resume_status'));
            self::assertSame($stage, $after->processing_stage);
            self::assertSame(['retained' => 'draft'], $after->draft_payload);
            self::assertFalse($after->input_payload['input_confirmed']);
            self::assertArrayNotHasKey('confirmed_evaluation', $after->input_payload);
            self::assertSame(1, $after->state_version);
            $this->assertNoPaidEffects();
        }
    }

    public function test_measurement_keeps_the_large_source_decimal_without_float_rounding(): void
    {
        [$actor, $session] = $this->fixture();
        $this->persistProjection($session);
        $group = $this->foundation(app(GetEstimatePrerequisites::class)->handle($actor, (int) $session->project_id, (int) $session->id));
        $input = [...$this->answer($group), 'basis' => 'measurement', 'value' => '999999999999.0003'];
        $result = app(UpdateEstimatePrerequisites::class)->answer($actor, (int) $session->project_id, (int) $session->id, 0, (string) Str::uuid(), $input);
        $fact = app(ProjectModelRepository::class)->fact(...[...$this->scope($session), $result['fact_id']]);
        $proof = DB::table('estimate_generation_evidence')->where('session_id', $session->id)->latest('id')->first();
        self::assertSame('999999999999.0003', $fact->value);
        self::assertSame($fact->value, json_decode($proof->value, true)['quantity']);
        $this->assertNoPaidEffects();
    }

    public function test_database_guard_keeps_exact_measurements_bounded_and_separate_from_document_producers(): void
    {
        [$actor, $session] = $this->fixture();
        $this->persistProjection($session);
        $group = $this->foundation(app(GetEstimatePrerequisites::class)->handle($actor, (int) $session->project_id, (int) $session->id));
        app(UpdateEstimatePrerequisites::class)->answer($actor, (int) $session->project_id, (int) $session->id, 0, (string) Str::uuid(),
            [...$this->answer($group), 'basis' => 'measurement', 'value' => '999999999999.0003']);
        $proof = DB::table('estimate_generation_evidence')->where('session_id', $session->id)->latest('id')->first();
        foreach ([['quantity' => '1e2'], ['quantity' => '1000000000000.0001'], ['producer_name' => 'pdf_geometry']] as $index => $change) {
            $row = (array) $proof;
            unset($row['id']);
            $row['fingerprint'] = hash('sha256', 'invalid-exact-measurement:'.$session->id.':'.$index);
            if (isset($change['quantity'])) {
                $row['value'] = json_encode([...json_decode($row['value'], true), 'quantity' => $change['quantity']], JSON_THROW_ON_ERROR);
            } else {
                $row['producer_name'] = $change['producer_name'];
            }
            try {
                DB::connection()->transaction(static fn () => DB::table('estimate_generation_evidence')->insert($row));
                self::fail('Database accepted an invalid exact measurement.');
            } catch (\Illuminate\Database\QueryException $error) {
                self::assertStringContainsString('estimate_generation.evidence_value_invalid', $error->getMessage());
            }
        }
        $migration = require base_path('app/BusinessModules/Addons/EstimateGeneration/migrations/2026_10_19_000100_allow_exact_user_measurement_evidence.php');
        $migration->up();
        self::assertSame(1, DB::table('estimate_generation_evidence')->where('session_id', $session->id)->count());
        self::assertSame($proof->value, DB::table('estimate_generation_evidence')->where('id', $proof->id)->value('value'));
        $this->assertNoPaidEffects();
    }

    public function test_numeric_unit_question_and_session_fences_reject_without_partial_writes(): void
    {
        [$actor, $session] = $this->fixture();
        $this->persistProjection($session);
        $group = $this->foundation(app(GetEstimatePrerequisites::class)->handle($actor, (int) $session->project_id, (int) $session->id));
        $valid = $this->answer($group);
        $cases = [['value' => '0'], ['value' => '-1'], ['value' => '1e2'], ['value' => 40], ['value' => '1.00001'],
            ['value' => '1000000000000.0001'], ['unit' => 'm3'], ['basis' => 'document'], ['reason' => ''],
            ['entity_id' => 'entity:foreign'], ['question_key' => 'prerequisite:'.str_repeat('f', 64)],
            ['backlog_version' => 'sha256:'.str_repeat('f', 64)]];
        $count = DB::table('estimate_generation_project_model_assertions')->where('session_id', $session->id)->count();
        foreach ($cases as $change) {
            try {
                app(UpdateEstimatePrerequisites::class)->answer($actor, (int) $session->project_id, (int) $session->id, 0,
                    (string) Str::uuid(), [...$valid, ...$change]);
                self::fail('Invalid prerequisite answer was accepted.');
            } catch (ValidationException|StaleEstimateGenerationState) {
                self::assertSame(0, $session->fresh()->state_version);
                self::assertSame($count, DB::table('estimate_generation_project_model_assertions')->where('session_id', $session->id)->count());
                self::assertSame(0, DB::table('estimate_generation_evidence')->where('session_id', $session->id)->count());
            }
        }
        $this->assertNoPaidEffects();
    }

    public function test_replay_collision_permission_and_tenant_scope_remain_enforced(): void
    {
        [$actor, $session] = $this->fixture();
        $this->persistProjection($session);
        $get = app(GetEstimatePrerequisites::class);
        $input = $this->answer($this->foundation($get->handle($actor, (int) $session->project_id, (int) $session->id)));
        $request = (string) Str::uuid();
        $update = app(UpdateEstimatePrerequisites::class);
        $result = $update->answer($actor, (int) $session->project_id, (int) $session->id, 0, $request, $input);
        foreach (['value', 'reason', 'action', 'actor'] as $case) {
            $caller = $actor;
            $change = $input;
            if ($case === 'value') {
                $change['value'] = '41';
            }
            if ($case === 'reason') {
                $change['reason'] = 'Другое основание';
            }
            if ($case === 'actor') {
                $caller = User::factory()->create(['current_organization_id' => $session->organization_id]);
                $caller->organizations()->attach($session->organization_id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
            }
            try {
                $case === 'action' ? $update->refresh($caller, (int) $session->project_id, (int) $session->id, 0, $request)
                    : $update->answer($caller, (int) $session->project_id, (int) $session->id, 0, $request, $change);
                self::fail('Replay collision accepted.');
            } catch (ValidationException) {
                self::assertSame(1, $session->fresh()->state_version);
            }
        }
        $foreign = Organization::factory()->create();
        $actor->current_organization_id = $foreign->id;
        try {
            $get->handle($actor, (int) $session->project_id, (int) $session->id);
            self::fail('Cross-tenant read accepted.');
        } catch (ModelNotFoundException) {
            self::assertSame(1, $session->fresh()->state_version);
        }
        $actor->current_organization_id = $session->organization_id;
        $denied = Mockery::mock(AuthorizationService::class);
        $denied->allows('canCurrent')->andReturn(false);
        $this->app->instance(AuthorizationService::class, $denied);
        try {
            app(UpdateEstimatePrerequisites::class)->answer($actor, (int) $session->project_id, (int) $session->id, 0, $request, $input);
            self::fail('Replay bypassed current authorization.');
        } catch (AuthorizationException) {
            self::assertSame($result['fact_id'], app(ProjectModelRepository::class)->fact(...[...$this->scope($session), $result['fact_id']])->id);
        }
        $this->assertNoPaidEffects();
    }

    public function test_changed_source_snapshot_and_ambiguous_objects_cannot_accept_an_old_or_invented_target(): void
    {
        [$actor, $session] = $this->fixture();
        $this->persistProjection($session);
        $get = app(GetEstimatePrerequisites::class);
        $input = $this->answer($this->foundation($get->handle($actor, (int) $session->project_id, (int) $session->id)));
        $models = app(ProjectModelRepository::class);
        $scope = $this->scope($session);
        $models->saveSourceModel([
            new Entity('entity:second-foundation', ...[...$scope, self::SOURCE, 'quantity', 'entity:second-foundation', ['semantic_type' => 'quantity']]),
        ], [new Fact('fact:second-foundation-type', ...[...$scope, self::SOURCE, 'entity:second-foundation', 'foundation_type',
            'slab', null, 0.0, 'user_assumption', 'confirmed', []])], []);
        self::assertTrue($get->handle($actor, $scope[1], $scope[2])['needs_refresh']);
        try {
            app(UpdateEstimatePrerequisites::class)->answer($actor, $scope[1], $scope[2], 0, (string) Str::uuid(), $input);
            self::fail('Changed snapshot was accepted.');
        } catch (StaleEstimateGenerationState) {
            self::assertSame(0, $session->fresh()->state_version);
        }
        $this->persistProjection($session);
        $ambiguous = $this->foundation($get->handle($actor, $scope[1], $scope[2]));
        self::assertFalse($ambiguous['questions'][0]['can_answer']);
        self::assertArrayNotHasKey('target', $ambiguous['questions'][0]);
        self::assertSame(0, DB::table('estimate_generation_evidence')->where('session_id', $session->id)->count());
        $this->assertNoPaidEffects();
    }

    public function test_pile_count_requires_an_integer_and_preserves_the_count_unit(): void
    {
        [$actor, $session] = $this->fixture();
        $models = app(ProjectModelRepository::class);
        $models->saveSourceModel([], [new Fact('fact:foundation-pile-type', ...[...$this->scope($session), self::SOURCE,
            'entity:foundation', 'foundation_type', 'pile', null, 0.0, 'user_assumption', 'confirmed', [], 2, 'fact:foundation-type'])], []);
        $this->persistProjection($session);
        $backlog = app(GetEstimatePrerequisites::class)->handle($actor, (int) $session->project_id, (int) $session->id);
        $group = array_values(array_filter($backlog['groups'], static fn (array $row): bool => in_array('pile_count', array_column($row['questions'], 'input_fact_type'), true)))[0];
        $question = $group['questions'][0];
        self::assertSame(0, $question['answer_contract']['decimal_places']);
        $input = ['finding_key' => $group['finding_key'], 'backlog_version' => $group['version'], 'question_key' => $question['key'],
            'value' => '3.5', 'unit' => 'item', 'basis' => 'input', 'reason' => 'Количество свай по ведомости'];
        $update = app(UpdateEstimatePrerequisites::class);
        try {
            $update->answer($actor, (int) $session->project_id, (int) $session->id, 0, (string) Str::uuid(), $input);
            self::fail('Fractional pile count accepted.');
        } catch (ValidationException) {
            self::assertSame(0, $session->fresh()->state_version);
        }
        $result = $update->answer($actor, (int) $session->project_id, (int) $session->id, 0, (string) Str::uuid(), [...$input, 'value' => '4']);
        self::assertSame('4', $result['value']);
        self::assertSame('item', $models->fact(...[...$this->scope($session), $result['fact_id']])->unit);
        self::assertSame('pcs', json_decode(DB::table('estimate_generation_evidence')->where('session_id', $session->id)->latest('id')->value('value'), true)['unit']);
        $this->assertNoPaidEffects();
    }

    public function test_processing_terminal_stale_and_receipt_limit_are_rejected(): void
    {
        [$actor, $session] = $this->fixture();
        $update = app(UpdateEstimatePrerequisites::class);
        foreach (['processing_documents', 'generating', 'applying', 'applied', 'cancelled', 'archived'] as $status) {
            $session->update(['status' => $status]);
            try {
                $update->refresh($actor, (int) $session->project_id, (int) $session->id, 0, (string) Str::uuid());
                self::fail('Non-editable session accepted.');
            } catch (StaleEstimateGenerationState) {
                self::assertSame(0, $session->fresh()->state_version);
            }
        }
        $session->update(['status' => 'failed']);
        try {
            $update->refresh($actor, (int) $session->project_id, (int) $session->id, 9, (string) Str::uuid());
            self::fail('Stale version accepted.');
        } catch (StaleEstimateGenerationState) {
            self::assertSame(0, $session->fresh()->state_version);
        }
        $session->update(['input_payload' => ['prerequisite_receipts' => array_fill(0, 256, ['historical' => true])]]);
        $this->expectException(ValidationException::class);
        $update->refresh($actor, (int) $session->project_id, (int) $session->id, 0, (string) Str::uuid());
    }

    public function test_failed_reanalysis_rolls_back_fact_evidence_decision_and_receipt(): void
    {
        [$actor, $session] = $this->fixture();
        $this->persistProjection($session);
        $input = $this->answer($this->foundation(app(GetEstimatePrerequisites::class)->handle($actor, (int) $session->project_id, (int) $session->id)));
        $before = DB::table('estimate_generation_project_model_assertions')->where('session_id', $session->id)->count();
        DB::listen(static function (\Illuminate\Database\Events\QueryExecuted $query): void {
            if (str_contains(strtolower($query->sql), 'insert into "estimate_generation_project_understanding_runs"')) {
                throw new RuntimeException('simulated_reanalysis_failure');
            }
        });
        try {
            app(UpdateEstimatePrerequisites::class)->answer($actor, (int) $session->project_id, (int) $session->id, 0, (string) Str::uuid(), $input);
            self::fail('Write failure not observed.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('simulated_reanalysis_failure', $error->getMessage());
        }
        self::assertSame(0, $session->fresh()->state_version);
        self::assertArrayNotHasKey('prerequisite_receipts', $session->fresh()->input_payload);
        self::assertSame($before, DB::table('estimate_generation_project_model_assertions')->where('session_id', $session->id)->count());
        self::assertSame(0, DB::table('estimate_generation_evidence')->where('session_id', $session->id)->count());
        self::assertSame(0, DB::table('estimate_generation_project_model_corrections')->where('session_id', $session->id)->count());
        $this->assertNoPaidEffects();
    }

    public function test_http_uses_real_form_request_and_standard_envelope(): void
    {
        [$actor, $session, $project] = $this->fixture();
        $this->persistProjection($session);
        $this->app->rebinding('request', static function ($app, \Illuminate\Http\Request $request) use ($actor): void {
            $request->setUserResolver(static fn (): User => $actor);
        });
        Route::bind('project', static fn (): Project => $project);
        Route::bind('session', static fn (): EstimateGenerationSession => $session);
        Route::get('/test/prerequisites/{project}/{session}', [EstimateGenerationPrerequisiteController::class, 'index'])
            ->middleware(\Illuminate\Routing\Middleware\SubstituteBindings::class);
        Route::post('/test/prerequisites/{project}/{session}/answer', [EstimateGenerationPrerequisiteController::class, 'answer'])
            ->middleware(\Illuminate\Routing\Middleware\SubstituteBindings::class);
        $url = '/test/prerequisites/'.$project->id.'/'.$session->id;
        $response = $this->getJson($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('success', true);
        $group = $this->foundation($response->json('data'));
        $input = [...$this->answer($group), 'state_version' => 0, 'request_id' => (string) Str::uuid()];
        $this->postJson($url.'/answer', [...$input, 'value' => 40])->assertUnprocessable()->assertJsonValidationErrors('value');
        $this->postJson($url.'/answer', [...$input, 'reason' => str_repeat('я', 600)])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson($url.'/answer', $input)->assertOk()->assertJsonPath('data.cost_units', '0')->assertJsonPath('data.state_version', 1);
        $this->postJson($url.'/answer', [...$input, 'request_id' => (string) Str::uuid()])->assertConflict();
        $this->assertNoPaidEffects();
    }

    private function fixture(): array
    {
        Http::fake([]);
        Http::preventStrayRequests();
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $actor = User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->allows('canCurrent')->andReturn(true);
        $authorization->allows('can')->andReturn(true);
        $this->app->instance(AuthorizationService::class, $authorization);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'status' => 'failed', 'state_version' => 0, 'draft_payload' => ['retained' => 'draft'],
            'input_payload' => ['qa_run_id' => '5ac504c5-5ca3-41dc-95b0-f3c8745ccc44', 'qa_limit_units' => 500,
                'input_confirmed' => true, 'confirmed_evaluation' => ['old' => true]]]);
        app(ProjectModelRepository::class)->saveSourceModel([
            new Entity('entity:foundation', ...[...$this->scope($session), self::SOURCE, 'quantity', 'entity:foundation', ['semantic_type' => 'quantity']]),
        ], [new Fact('fact:foundation-type', ...[...$this->scope($session), self::SOURCE, 'entity:foundation', 'foundation_type',
            'slab', null, 0.0, 'user_assumption', 'confirmed', []])], []);

        return [$actor, $session, $project];
    }

    private function persistProjection(EstimateGenerationSession $session, bool $legacy = false): void
    {
        $models = app(ProjectModelRepository::class);
        $capture = $models->snapshotForPlanning(...[...$this->scope($session), 10001]);
        $catalog = app(TechnologySystemCatalog::class);
        $rules = app(CompletenessRuleCatalog::class);
        $projection = ['source_version' => self::SOURCE, 'input_fingerprint' => $capture['token'],
            'catalog_version' => $catalog->version, 'catalog_hash' => $catalog->contentHash,
            'rule_catalog_version' => $legacy ? 'old-v1' : $rules->version, 'rule_catalog_hash' => $legacy ? str_repeat('f', 64) : $rules->contentHash];
        $result = app(ProjectCompletenessAnalyzer::class)->analyze($capture['snapshot'], [], [], $projection);
        self::assertTrue($models->replaceCompleteness(...[...$this->scope($session), self::SOURCE, $capture['token'],
            $projection['catalog_version'], $projection['catalog_hash'], $projection['rule_catalog_version'], $projection['rule_catalog_hash'],
            $result->findings, $result->limitations]));
    }

    private function foundation(array $backlog): array
    {
        foreach ($backlog['groups'] as $group) {
            if (in_array('foundation_area', array_column($group['questions'], 'input_fact_type'), true)) {
                return $group;
            }
        }
        self::fail('Expected foundation prerequisite.');
    }

    private function answer(array $group): array
    {
        $question = array_values(array_filter($group['questions'], static fn (array $row): bool => $row['input_fact_type'] === 'foundation_area'))[0];

        return ['finding_key' => $group['finding_key'], 'backlog_version' => $group['version'], 'question_key' => $question['key'],
            'value' => '40.0000', 'unit' => 'm2', 'basis' => 'assumption', 'reason' => 'Принята площадь основания по проверенному плану'];
    }

    private function scope(EstimateGenerationSession $session): array
    {
        return [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
    }

    private function assertNoPaidEffects(): void
    {
        Http::assertNothingSent();
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->count());
        self::assertSame(0, DB::table('estimate_generation_qa_expense_attempts')->count());
    }
}
