<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Dialogue;

use App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\ApplyEstimateChangeProposal;
use App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\EstimateChangeProposal;
use App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\EstimateChangeSimulation;
use App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\EstimateCommandContextBuilder;
use App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\EstimateCommandInterpretation;
use App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\EstimateCommandInterpreter;
use App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\EstimateProposalMutationExecutor;
use App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\EstimateProposalVersionFence;
use App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\InterpretEstimateCommand;
use App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\InterpretEstimateCommandFailure;
use App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\PreviewEstimateChange;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelValueFingerprint;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ApplyProjectModelDecision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\EloquentProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EloquentEvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceData;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use App\BusinessModules\Addons\EstimateGeneration\Infrastructure\Dialogue\EstimateChangeProposalRepository;
use App\BusinessModules\Addons\EstimateGeneration\Infrastructure\Dialogue\EstimateInterpretationAttemptRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Questions\ProjectModelEstimateClarificationAnswerRegistry;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Process\Process;

final class EstimateChangeProposalPostgresTest extends TestCase
{
    public function createApplication(): Application
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('pgsql', DB::getDriverName());
        if (! Schema::hasColumn('estimate_generation_sessions', 'state_version')) {
            DB::statement('ALTER TABLE estimate_generation_sessions ADD COLUMN state_version bigint NOT NULL DEFAULT 0');
        }
        if (! Schema::hasTable('estimate_generation_documents')) {
            Schema::create('estimate_generation_documents', function ($table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('session_id');
                $table->string('source_version')->nullable();
                $table->string('checksum_sha256', 64)->nullable();
                $table->timestampTz('updated_at')->nullable();
            });
        }
        if (! Schema::hasTable('estimate_generation_technology_planning_runs')) {
            (require app_path('BusinessModules/Addons/EstimateGeneration/migrations/2026_08_11_000700_create_technology_planning_projections.php'))->up();
        }
        if (! Schema::hasTable('estimate_generation_completeness_runs')) {
            (require app_path('BusinessModules/Addons/EstimateGeneration/migrations/2026_08_11_000710_create_completeness_planning_projections.php'))->up();
        }
        (require app_path('BusinessModules/Addons/EstimateGeneration/migrations/2026_08_14_000200_detach_project_model_from_building_model.php'))->up();
        $migration = require database_path('migrations/2026_08_12_000002_create_estimate_change_proposals.php');
        $hardening = require database_path('migrations/2026_08_12_000003_add_interpretation_attempts_and_cost_state.php');
        if (Schema::hasTable('estimate_change_proposals')) {
            if (Schema::hasColumn('estimate_change_proposals', 'cost_state')) {
                $hardening->down();
            } else {
                Schema::dropIfExists('estimate_interpretation_attempts');
            }
            $migration->down();
        }
        $migration->up();
        $hardening->up();
    }

    public function test_postgres_contract_enforces_immutable_history_scope_idempotency_decimal_rollback_and_indexes(): void
    {
        $repository = app(EstimateChangeProposalRepository::class);
        $id = (string) \Illuminate\Support\Str::uuid();
        $scope = random_int(100000, 900000);
        $proposal = $this->proposal($id, $scope, 'request-'.$id);

        $items = [];
        for ($index = 1; $index <= 1205; $index++) {
            $items[] = [
                'stable_key' => 'row:'.$index, 'kind' => 'estimate_row', 'before' => ['quantity' => '10.0000'],
                'after' => ['quantity' => '11.2500'], 'locator' => ['artifact_id' => 17, 'page' => $index === 1 ? 4 : 5],
            ];
        }
        $created = $repository->create($proposal, $items);
        self::assertSame('proposed', $created->payload['status']);
        self::assertSame('1234567890123456.1234', (string) $created->payload['cost_delta']);
        $firstPage = $repository->items($id, 100, null);
        self::assertCount(100, $firstPage['items']);
        self::assertNotNull($firstPage['next_cursor']);
        self::assertSame(4, $firstPage['items'][0]['locator']['page']);
        self::assertSame(1205, DB::table('estimate_change_proposal_items')->where('proposal_id', $id)->count());
        $history = $repository->history($scope, $scope, $scope, 1, null);
        self::assertSame($id, $history['items'][0]->id());
        self::assertNull($history['next_cursor']);
        self::assertSame([], $repository->history($scope + 1, $scope, $scope, 10, null)['items']);

        try {
            $repository->find($id, $scope + 1, $scope, $scope);
            self::fail('Cross-tenant proposal must be invisible.');
        } catch (RuntimeException $exception) {
            self::assertSame('estimate_generation.proposal_not_found', $exception->getMessage());
        }

        $duplicate = $this->proposal((string) \Illuminate\Support\Str::uuid(), $scope, 'request-'.$id);
        $this->expectException(QueryException::class);
        try {
            $repository->create($duplicate, []);
        } finally {
            try {
                DB::table('estimate_change_proposals')->where('id', $id)->update(['command_excerpt' => 'изменено']);
                self::fail('Proposal history must be immutable.');
            } catch (QueryException) {
                self::assertTrue(true);
            }

            $winner = $repository->transition($id, 'proposed', 'cancelled', $scope);
            $loser = $repository->transition($id, 'proposed', 'applying', $scope);
            self::assertTrue($winner);
            self::assertFalse($loser);
            self::assertSame(2, DB::table('estimate_change_proposal_transitions')->where('proposal_id', $id)->count());

            $staleId = (string) \Illuminate\Support\Str::uuid();
            $repository->create($this->proposal($staleId, $scope + 2, 'stale-'.$staleId), []);
            self::assertTrue($repository->transition($staleId, 'proposed', 'stale', $scope));

            $rolledBack = (string) \Illuminate\Support\Str::uuid();
            try {
                DB::transaction(function () use ($repository, $rolledBack, $scope): void {
                    $repository->create($this->proposal($rolledBack, $scope + 1, 'rollback-'.$rolledBack), []);
                    throw new RuntimeException('rollback');
                });
            } catch (RuntimeException) {
                self::assertDatabaseMissing('estimate_change_proposals', ['id' => $rolledBack]);
            }

            DB::statement('SET LOCAL enable_seqscan = off');
            $scopePlan = DB::select('EXPLAIN SELECT id FROM estimate_change_proposals WHERE organization_id = ? AND project_id = ? AND session_id = ? ORDER BY created_at LIMIT 50', [$scope, $scope, $scope]);
            $currentPlan = DB::select('EXPLAIN SELECT proposal_id FROM estimate_change_proposal_states WHERE proposal_id = ?', [$id]);
            self::assertStringContainsString('Index', implode(' ', array_map(fn ($row): string => (string) $row->{'QUERY PLAN'}, $scopePlan)));
            self::assertStringContainsString('Index', implode(' ', array_map(fn ($row): string => (string) $row->{'QUERY PLAN'}, $currentPlan)));
            self::assertMatchesRegularExpression('/PostgreSQL 16\./', (string) DB::scalar('select version()'));
        }
    }

    public function test_clarification_answer_is_one_idempotent_user_decision_in_real_postgres(): void
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $actor->id,
            'status' => 'ready_to_generate',
            'processing_stage' => 'analysis',
            'processing_progress' => 50,
            'input_payload' => [],
            'analysis_payload' => [],
            'draft_payload' => [],
            'problem_flags' => [],
            'state_version' => 1,
        ]);
        $sourceVersion = 'sha256:'.hash('sha256', 'clarification-source');
        $models = app(EloquentProjectModelRepository::class);
        $models->saveSourceModel(
            [new Entity(
                'wall:clarification',
                (int) $organization->id,
                (int) $project->id,
                (int) $session->id,
                $sourceVersion,
                'wall',
                'wall:clarification',
                ['start' => [0, 0], 'end' => [1, 0]],
            )],
            [new Fact(
                'fact:clarification:wall-material',
                (int) $organization->id,
                (int) $project->id,
                (int) $session->id,
                $sourceVersion,
                'wall:clarification',
                'wall_material',
                null,
                null,
                0.0,
                'unresolved',
                'unresolved',
                [],
            )],
            [],
        );
        $decisions = new ApplyProjectModelDecision($models);
        $arguments = [
            (int) $organization->id,
            (int) $project->id,
            (int) $session->id,
            $sourceVersion,
            'fact:clarification:wall-material',
            'wall_material_required',
            'selected',
            'select:gas-concrete',
            'Газобетон',
            null,
            hash('sha256', 'question-fingerprint'),
            ['document_id' => 7, 'page_number' => 4],
            (string) $actor->id,
            'Выбран материал по основной спецификации',
            'decision:clarification:'.hash('sha256', 'answer-request'),
        ];

        $first = $decisions->applyClarificationChoice(...$arguments);
        $second = $decisions->applyClarificationChoice(...$arguments);

        self::assertSame($first->id, $second->id);
        self::assertSame(1, DB::table('estimate_generation_project_model_corrections')
            ->where('stable_key', $first->id)
            ->count());
        $registry = new ProjectModelEstimateClarificationAnswerRegistry($models, 100);
        self::assertSame(
            ['wall_material_required'],
            $registry->answeredKeys((int) $organization->id, (int) $project->id, (int) $session->id),
        );
        self::assertSame([], $registry->answeredKeys(
            (int) $organization->id,
            (int) $project->id + 1,
            (int) $session->id,
        ));
    }

    public function test_apply_is_atomic_idempotent_version_fenced_and_marks_stale_expired_or_failed(): void
    {
        DB::beginTransaction();
        try {
            $organization = Organization::factory()->create();
            $actor = User::factory()->create(['current_organization_id' => $organization->id]);
            $project = Project::factory()->create(['organization_id' => $organization->id]);
            $session = EstimateGenerationSession::query()->create([
                'organization_id' => $organization->id, 'project_id' => $project->id, 'user_id' => $actor->id,
                'status' => 'ready_to_apply', 'processing_stage' => 'quality_check', 'processing_progress' => 100,
                'input_payload' => [], 'analysis_payload' => ['stage' => 6], 'draft_payload' => ['rows' => [['quantity' => '10.0000']]],
                'problem_flags' => [], 'state_version' => 9,
            ]);
            $repository = app(EstimateChangeProposalRepository::class);
            $versions = app(EstimateProposalVersionFence::class);
            $executor = new class implements EstimateProposalMutationExecutor
            {
                public int $calls = 0;

                public bool $fail = false;

                public function apply(User $actor, EstimateGenerationSession $session, EstimateChangeProposal $proposal): array
                {
                    \PHPUnit\Framework\Assert::assertGreaterThan(0, DB::transactionLevel());
                    $this->calls++;
                    if ($this->fail) {
                        throw new RuntimeException('domain failure');
                    }

                    return ['ordinary_estimate_changed' => true];
                }
            };
            $service = new ApplyEstimateChangeProposal(
                $repository,
                $versions,
                $executor,
                app(EstimateChangeSimulation::class),
            );

            $id = (string) \Illuminate\Support\Str::uuid();
            $repository->create($this->scopedProposal($id, $session, $actor, $versions->capture($session), now()->addMinutes(30)), []);
            $stored = $repository->find($id, (int) $organization->id, (int) $project->id, (int) $session->id);
            $recalculated = app(EstimateChangeSimulation::class)->calculate(
                $session->fresh(),
                new EstimateCommandInterpretation($stored->payload['simulation_input']),
            );
            self::assertSame(
                $stored->payload['simulation_fingerprint'],
                $recalculated['fingerprint'],
                json_encode([$stored->payload['simulation_input'], $recalculated], JSON_UNESCAPED_UNICODE),
            );
            self::assertSame('applied', $service->handle($actor, (int) $organization->id, (int) $project->id, (int) $session->id, $id, 9)->payload['status']);
            self::assertSame('applied', $service->handle($actor, (int) $organization->id, (int) $project->id, (int) $session->id, $id, 9)->payload['status']);
            self::assertSame(1, $executor->calls);

            $staleId = (string) \Illuminate\Support\Str::uuid();
            $repository->create($this->scopedProposal($staleId, $session, $actor, $versions->capture($session), now()->addMinutes(30)), []);
            $session->forceFill(['state_version' => 10, 'draft_payload' => ['rows' => [['quantity' => '12.0000']]]])->save();
            self::assertSame('stale', $service->handle($actor, (int) $organization->id, (int) $project->id, (int) $session->id, $staleId, 9)->payload['status']);
            self::assertSame(1, $executor->calls);

            $expiredId = (string) \Illuminate\Support\Str::uuid();
            $repository->create($this->scopedProposal($expiredId, $session->fresh(), $actor, $versions->capture($session->fresh()), now()->subSecond()), []);
            self::assertSame('expired', $service->handle($actor, (int) $organization->id, (int) $project->id, (int) $session->id, $expiredId, 10)->payload['status']);

            $failedId = (string) \Illuminate\Support\Str::uuid();
            $repository->create($this->scopedProposal($failedId, $session->fresh(), $actor, $versions->capture($session->fresh()), now()->addMinutes(30)), []);
            $executor->fail = true;
            try {
                $service->handle($actor, (int) $organization->id, (int) $project->id, (int) $session->id, $failedId, 10);
                self::fail('Domain failure must escape after rollback.');
            } catch (RuntimeException) {
                self::assertSame('failed', $repository->find($failedId, (int) $organization->id, (int) $project->id, (int) $session->id)->payload['status']);
                self::assertSame('12.0000', EstimateGenerationSession::query()->findOrFail($session->id)->draft_payload['rows'][0]['quantity']);
            }
        } finally {
            DB::rollBack();
        }
    }

    public function test_real_concurrent_apply_and_cancel_have_one_terminal_winner(): void
    {
        $repository = app(EstimateChangeProposalRepository::class);
        $id = (string) \Illuminate\Support\Str::uuid();
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id, 'project_id' => $project->id, 'user_id' => $actor->id,
            'status' => 'ready_to_apply', 'processing_stage' => 'quality_check', 'processing_progress' => 100,
            'input_payload' => [], 'analysis_payload' => [], 'draft_payload' => ['rows' => [['quantity' => '10.0000']]],
            'problem_flags' => [], 'state_version' => 7,
        ]);
        $sourceVersion = 'sha256:'.str_repeat('e', 64);
        $evidenceRepository = new EloquentEvidenceRepository(DB::connection());
        $evidence = $evidenceRepository->insertOrGet(new EvidenceData(
            (int) $organization->id,
            (int) $project->id,
            (int) $session->id,
            EvidenceType::Measured,
            EvidenceSourceType::DocumentUnit,
            'document:1',
            'sha256:'.str_repeat('a', 64),
            ['document_id' => 1, 'unit_index' => 1, 'page' => 1],
            ['quantity' => 2.8, 'unit' => 'm'],
            1,
            'pdf_geometry',
            'extractor:v1',
        ));
        $modelId = (int) $session->id;
        $assertionKey = 'assertion:race-height';
        $domainEvidence = new Evidence(
            'evidence:'.$evidence->id,
            (int) $organization->id,
            (int) $project->id,
            (int) $session->id,
            $sourceVersion,
            'document:1',
            'cad',
            1,
        );
        (new EloquentProjectModelRepository(app('db')))->saveSourceModel(
            [new Entity('dimension:race-height', (int) $organization->id, (int) $project->id, (int) $session->id, $sourceVersion, 'dimension', 'dimension:race-height', ['value' => 2.8, 'unit' => 'm'])],
            [new Fact($assertionKey, (int) $organization->id, (int) $project->id, (int) $session->id, $sourceVersion, 'dimension:race-height', 'dimension', 2.8, 'm', 1.0, 'document', 'confirmed', [$domainEvidence->id])],
            [$domainEvidence],
        );
        $payload = $this->scopedProposal(
            $id,
            $session,
            $actor,
            app(EstimateProposalVersionFence::class)->capture($session),
            now()->addMinutes(30),
        );
        $payload['idempotency_key'] = 'race-'.$id;
        $payload['after_payload'] = [
            'source_version' => $sourceVersion,
            'value_fingerprint' => ProjectModelValueFingerprint::for(['value' => 2.8, 'unit' => 'm']),
            'assertion_stable_key' => $assertionKey,
            'value' => ['value' => 3.0, 'unit' => 'm'],
            'reason' => 'Проверка конкурентного применения',
            'decision_version' => 0,
        ];
        $repository->create($payload, []);
        $barrier = (string) (microtime(true) + 0.8);
        $script = base_path('tests/Runtime/race-estimate-change-proposal.php');
        $environment = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => (string) config('database.connections.pgsql.host'), 'DB_PORT' => (string) config('database.connections.pgsql.port'),
            'DB_DATABASE' => (string) config('database.connections.pgsql.database'), 'DB_USERNAME' => (string) config('database.connections.pgsql.username'),
            'DB_PASSWORD' => (string) config('database.connections.pgsql.password'),
        ];
        $apply = new Process([PHP_BINARY, $script, 'apply', (string) $actor->id, (string) $organization->id, (string) $project->id, (string) $session->id, $id, '7', $barrier], base_path(), $environment);
        $cancel = new Process([PHP_BINARY, $script, 'cancel', (string) $actor->id, (string) $organization->id, (string) $project->id, (string) $session->id, $id, '7', $barrier], base_path(), $environment);
        $apply->start();
        $cancel->start();
        $apply->wait();
        $cancel->wait();

        self::assertTrue($apply->isSuccessful(), $apply->getErrorOutput());
        self::assertTrue($cancel->isSuccessful(), $cancel->getErrorOutput());
        $outcomes = array_map($this->raceOutcome(...), [$apply, $cancel]);
        $status = DB::table('estimate_change_proposal_states')->where('proposal_id', $id)->value('status');
        self::assertContains($status, ['applied', 'cancelled']);
        self::assertSame([$status, $status], array_column($outcomes, 'status'));
        $correctionsBeforeReplay = $status === 'applied' ? 1 : 0;
        self::assertSame($correctionsBeforeReplay, DB::table('estimate_generation_project_model_corrections')->where('building_model_id', $modelId)->count());

        $replayId = (string) \Illuminate\Support\Str::uuid();
        $currentValue = $status === 'applied'
            ? ['value' => 3.0, 'unit' => 'm']
            : ['value' => 2.8, 'unit' => 'm'];
        $replayPayload = $this->scopedProposal(
            $replayId,
            $session->fresh(),
            $actor,
            app(EstimateProposalVersionFence::class)->capture($session->fresh()),
            now()->addMinutes(30),
        );
        $replayPayload['idempotency_key'] = 'race-replay-'.$replayId;
        $replayPayload['after_payload'] = [
            'source_version' => $sourceVersion,
            'value_fingerprint' => ProjectModelValueFingerprint::for($currentValue),
            'assertion_stable_key' => $assertionKey,
            'value' => ['value' => 3.2, 'unit' => 'm'],
            'reason' => 'Проверка идемпотентного конкурентного применения',
            'decision_version' => $correctionsBeforeReplay,
        ];
        $repository->create($replayPayload, []);
        $replayBarrier = (string) (microtime(true) + 0.8);
        $firstApply = new Process([PHP_BINARY, $script, 'apply', (string) $actor->id, (string) $organization->id, (string) $project->id, (string) $session->id, $replayId, '7', $replayBarrier], base_path(), $environment);
        $secondApply = new Process([PHP_BINARY, $script, 'apply', (string) $actor->id, (string) $organization->id, (string) $project->id, (string) $session->id, $replayId, '7', $replayBarrier], base_path(), $environment);
        $firstApply->start();
        $secondApply->start();
        $firstApply->wait();
        $secondApply->wait();

        self::assertTrue($firstApply->isSuccessful(), $firstApply->getErrorOutput());
        self::assertTrue($secondApply->isSuccessful(), $secondApply->getErrorOutput());
        self::assertSame(['applied', 'applied'], array_column([
            $this->raceOutcome($firstApply),
            $this->raceOutcome($secondApply),
        ], 'status'));
        self::assertSame($correctionsBeforeReplay + 1, DB::table('estimate_generation_project_model_corrections')->where('building_model_id', $modelId)->count());
    }

    public function test_two_concurrent_interpret_requests_reserve_once_before_provider_wire(): void
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $actor->id,
            'status' => 'ready_to_apply',
            'processing_stage' => 'quality_check',
            'processing_progress' => 100,
            'input_payload' => [],
            'analysis_payload' => ['facts' => [[
                'stable_key' => 'fact:room:101:area',
                'value' => '40.0000',
                'unit' => 'м²',
                'source_version' => 'source-v1',
                'value_fingerprint' => 'fingerprint-v1',
            ]]],
            'draft_payload' => ['local_estimates' => [['sections' => [['work_items' => [[
                'key' => 'row:context', 'name' => 'Контекстная строка', 'quantity' => '1.0000',
                'unit' => 'шт', 'total_cost' => '1.0000', 'pricing_status' => 'calculated',
            ]]]]]]],
            'problem_flags' => [],
            'state_version' => 9,
        ]);
        Schema::dropIfExists('estimate_stage7_provider_spy');
        Schema::create('estimate_stage7_provider_spy', function ($table): void {
            $table->bigIncrements('id');
            $table->string('request_key', 128);
            $table->timestampTz('created_at');
        });

        try {
            $barrier = (string) (microtime(true) + 0.8);
            $script = base_path('tests/Runtime/interpret-estimate-command.php');
            $environment = [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'pgsql',
                'DB_HOST' => (string) config('database.connections.pgsql.host'),
                'DB_PORT' => (string) config('database.connections.pgsql.port'),
                'DB_DATABASE' => (string) config('database.connections.pgsql.database'),
                'DB_USERNAME' => (string) config('database.connections.pgsql.username'),
                'DB_PASSWORD' => (string) config('database.connections.pgsql.password'),
            ];
            $arguments = [
                PHP_BINARY,
                $script,
                (string) $session->id,
                (string) $actor->id,
                'Исправить площадь помещения',
                'parallel-request',
                $barrier,
            ];
            $first = new Process($arguments, base_path(), $environment);
            $second = new Process($arguments, base_path(), $environment);
            $first->start();
            $second->start();
            $first->wait();
            $second->wait();

            self::assertTrue($first->isSuccessful(), $first->getErrorOutput());
            self::assertTrue($second->isSuccessful(), $second->getErrorOutput());
            self::assertSame(1, DB::table('estimate_stage7_provider_spy')->where('request_key', 'parallel-request')->count(), $first->getOutput().' | '.$second->getOutput());
            self::assertSame(0, DB::table('estimate_change_proposals')->where('session_id', $session->id)->count());
            self::assertSame(
                'completed',
                DB::table('estimate_interpretation_attempts')
                    ->where('session_id', $session->id)
                    ->where('idempotency_key', 'parallel-request')
                    ->value('state'),
            );
        } finally {
            Schema::dropIfExists('estimate_stage7_provider_spy');
        }
    }

    public function test_preview_ignores_cached_and_provider_cost_and_uses_proposed_value(): void
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $actor->id,
            'status' => 'ready_to_apply',
            'processing_stage' => 'quality_check',
            'processing_progress' => 100,
            'input_payload' => [],
            'analysis_payload' => [],
            'draft_payload' => [
                'local_estimates' => [[
                    'sections' => [[
                        'work_items' => [[
                            'key' => 'row:room:101',
                            'quantity' => '40.0000',
                            'total_cost' => '1000.0000',
                            'metadata' => ['dependency_keys' => ['fact:room:101:area']],
                        ]],
                    ]],
                ]],
                'preview_simulations' => [
                    'fact:room:101:area' => ['after_total' => '1250.5000'],
                ],
            ],
            'problem_flags' => [],
            'state_version' => 4,
        ]);
        $interpretation = new EstimateCommandInterpretation([
            'kind' => 'correct_fact',
            'version' => 'dialogue:v1',
            'target_key' => 'fact:room:101:area',
            'value' => '50.0000',
            'before' => ['value' => '40.0000'],
            'dependency_keys' => ['fact:room:101:area'],
            'affected' => [['stable_key' => 'row:room:101', 'kind' => 'estimate_row']],
            'cost_delta_known' => true,
            'cost_delta' => '999999.0000',
        ]);

        $proposal = app(PreviewEstimateChange::class)->handle(
            $session,
            (int) $actor->id,
            'Исправить площадь',
            'cost-request',
            $interpretation,
        );

        self::assertFalse($proposal->payload['cost_delta_known']);
        self::assertNull($proposal->payload['cost_delta']);
        self::assertContains(
            'canonical_project_model_target_missing',
            $proposal->payload['cost_blockers'],
        );

        $largerProposal = app(PreviewEstimateChange::class)->handle(
            $session,
            (int) $actor->id,
            'Исправить площадь до 60',
            'cost-request-60',
            new EstimateCommandInterpretation([
                'kind' => 'correct_fact',
                'version' => 'dialogue:v1',
                'target_key' => 'fact:room:101:area',
                'value' => '60.0000',
                'before' => ['value' => '40.0000'],
                'dependency_keys' => ['fact:room:101:area'],
                'affected' => [['stable_key' => 'row:room:101', 'kind' => 'estimate_row']],
                'cost_delta_known' => true,
                'cost_delta' => '999999.0000',
            ]),
        );

        self::assertFalse($largerProposal->payload['cost_delta_known']);
        self::assertNotSame(
            (string) $proposal->payload['simulation_fingerprint'],
            (string) $largerProposal->payload['simulation_fingerprint'],
        );
        self::assertEquals($session->draft_payload, $session->fresh()->draft_payload);
        self::assertSame('proposed', DB::table('estimate_change_proposal_states')->where('proposal_id', $proposal->id())->value('status'));
    }

    public function test_interpretation_attempt_lease_collision_recovery_replay_tenant_scope_and_index(): void
    {
        $repository = app(EstimateInterpretationAttemptRepository::class);
        $scope = random_int(100000, 900000);
        $fingerprint = hash('sha256', 'attempt-payload');
        $firstOwner = (string) \Illuminate\Support\Str::uuid();
        $secondOwner = (string) \Illuminate\Support\Str::uuid();

        self::assertSame('owned', $repository->claim($scope, $scope, $scope, 'lease-key', $fingerprint, $firstOwner)['action']);
        self::assertSame('busy', $repository->claim($scope, $scope, $scope, 'lease-key', $fingerprint, $secondOwner)['action']);
        try {
            $repository->claim($scope, $scope, $scope, 'lease-key', hash('sha256', 'collision'), $secondOwner);
            self::fail('Payload collision must fail before provider wire.');
        } catch (RuntimeException $exception) {
            self::assertSame('estimate_generation.proposal_idempotency_collision', $exception->getMessage());
        }

        DB::table('estimate_interpretation_attempts')->where('idempotency_key', 'lease-key')->update(['lease_expires_at' => now()->subSecond()]);
        self::assertSame('owned', $repository->claim($scope, $scope, $scope, 'lease-key', $fingerprint, $secondOwner)['action']);
        $repository->markWireStarted($scope, $scope, $scope, 'lease-key', $fingerprint, $secondOwner);
        DB::table('estimate_interpretation_attempts')->where('idempotency_key', 'lease-key')->update(['lease_expires_at' => now()->subSecond()]);
        self::assertSame('ambiguous', $repository->claim($scope, $scope, $scope, 'lease-key', $fingerprint, (string) \Illuminate\Support\Str::uuid())['action']);
        self::assertSame('ambiguous', DB::table('estimate_interpretation_attempts')->where('idempotency_key', 'lease-key')->value('state'));

        $owner = (string) \Illuminate\Support\Str::uuid();
        self::assertSame('owned', $repository->claim($scope, $scope, $scope, 'replay-key', $fingerprint, $owner)['action']);
        $repository->markWireStarted($scope, $scope, $scope, 'replay-key', $fingerprint, $owner);
        $repository->storeResponse($scope, $scope, $scope, 'replay-key', $fingerprint, $owner, ['kind' => 'explain']);
        self::assertSame('busy', $repository->claim($scope, $scope, $scope, 'replay-key', $fingerprint, (string) \Illuminate\Support\Str::uuid())['action']);
        DB::table('estimate_interpretation_attempts')->where('idempotency_key', 'replay-key')->update(['lease_expires_at' => now()->subSecond()]);
        $resumeOwner = (string) \Illuminate\Support\Str::uuid();
        $resume = $repository->claim($scope, $scope, $scope, 'replay-key', $fingerprint, $resumeOwner);
        self::assertSame('resume', $resume['action']);
        self::assertSame(['kind' => 'explain'], $resume['interpretation']);
        $repository->complete($scope, $scope, $scope, 'replay-key', $fingerprint, $resumeOwner, ['kind' => 'explanation', 'read_only' => true]);
        $replay = $repository->claim($scope, $scope, $scope, 'replay-key', $fingerprint, (string) \Illuminate\Support\Str::uuid());
        self::assertSame('replay', $replay['action']);
        self::assertSame(['kind' => 'explanation', 'read_only' => true], $replay['result']);
        self::assertSame('owned', $repository->claim($scope + 1, $scope + 1, $scope + 1, 'replay-key', $fingerprint, (string) \Illuminate\Support\Str::uuid())['action']);

        DB::statement('SET LOCAL enable_seqscan = off');
        $plan = DB::select('EXPLAIN SELECT * FROM estimate_interpretation_attempts WHERE organization_id = ? AND project_id = ? AND session_id = ? AND idempotency_key = ?', [$scope, $scope, $scope, 'replay-key']);
        self::assertStringContainsString('estimate_interpretation_attempt_scope_key_unique', implode("\n", array_map(static fn (object $row): string => (string) $row->{'QUERY PLAN'}, $plan)));
    }

    public function test_response_received_failure_retries_same_attempt_without_second_provider_call(): void
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $actor->id,
            'status' => 'ready_to_apply',
            'processing_stage' => 'quality_check',
            'processing_progress' => 100,
            'input_payload' => [],
            'analysis_payload' => ['facts' => [[
                'stable_key' => 'fact:area',
                'label' => 'Площадь',
                'type' => 'area',
                'value' => '9',
                'unit' => 'm2',
                'status' => 'confirmed',
                'version' => 1,
            ]]],
            'draft_payload' => [],
            'problem_flags' => [],
            'state_version' => 1,
        ]);
        $provider = new class implements EstimateCommandInterpreter
        {
            public int $calls = 0;

            public function interpret(EstimateGenerationSession $session, int $actorId, string $command, ?array $context = null): EstimateCommandInterpretation
            {
                $this->calls++;

                return new EstimateCommandInterpretation([
                    'kind' => 'correct_fact',
                    'version' => 'test:v1',
                    'target_key' => 'fact:area',
                    'value' => '10',
                    'dependency_keys' => ['fact:area'],
                ]);
            }
        };
        $simulation = new class implements EstimateChangeSimulation
        {
            public int $calls = 0;

            public function calculate(EstimateGenerationSession $session, EstimateCommandInterpretation $interpretation): array
            {
                $this->calls++;
                if ($this->calls === 1) {
                    throw new RuntimeException('simulation publication failed');
                }

                return [
                    'state' => 'unknown',
                    'delta' => null,
                    'blockers' => ['canonical_project_model_target_missing'],
                    'affected' => [],
                    'fingerprint' => hash('sha256', 'recovered'),
                    'version_fence' => ['state_version' => 1],
                ];
            }
        };
        $repository = app(EstimateChangeProposalRepository::class);
        $command = new InterpretEstimateCommand(
            $provider,
            new PreviewEstimateChange($repository, $simulation),
            $repository,
            app(EstimateInterpretationAttemptRepository::class),
            new EstimateCommandContextBuilder,
        );
        $key = 'response-recovery-'.\Illuminate\Support\Str::uuid();

        try {
            $command->handle($session, (int) $actor->id, 'Исправить площадь', $key);
            self::fail('Post-provider failure must expose a safe retry disposition.');
        } catch (\Throwable $exception) {
            self::assertInstanceOf(
                InterpretEstimateCommandFailure::class,
                $exception,
                $exception::class.': '.$exception->getMessage(),
            );
            self::assertSame('retry_same_attempt', $exception->retryDisposition());
        }
        DB::table('estimate_interpretation_attempts')
            ->where('idempotency_key', $key)
            ->update(['lease_expires_at' => now()->subSecond()]);

        $result = $command->handle($session, (int) $actor->id, 'Исправить площадь', $key);

        self::assertSame('proposal', $result['kind']);
        self::assertSame(1, $provider->calls);
        self::assertSame(2, $simulation->calls);
        self::assertSame('completed', DB::table('estimate_interpretation_attempts')->where('idempotency_key', $key)->value('state'));
    }

    /** @return array<string, mixed> */
    private function proposal(string $id, int $scope, string $idempotencyKey): array
    {
        return [
            'id' => $id, 'organization_id' => $scope, 'project_id' => $scope, 'session_id' => $scope, 'actor_id' => $scope,
            'idempotency_key' => $idempotencyKey, 'payload_fingerprint' => hash('sha256', $id), 'intent' => 'correct_fact',
            'interpretation_version' => 'test:v1', 'command_excerpt' => 'Исправить площадь', 'before_payload' => ['area' => '10.0000'],
            'after_payload' => ['area' => '11.2500'], 'affected_payload' => ['count' => 1], 'dependency_keys' => ['quantity:room'],
            'assumptions' => [], 'questions' => [], 'evidence' => [], 'version_fence' => ['state_version' => 7],
            'cost_delta_known' => true, 'cost_delta' => '1234567890123456.1234', 'expires_at' => now()->addMinutes(30), 'created_at' => now(),
        ];
    }

    /** @param array<string, mixed> $fence @return array<string, mixed> */
    private function scopedProposal(string $id, EstimateGenerationSession $session, User $actor, array $fence, mixed $expiresAt): array
    {
        $payload = $this->proposal($id, (int) $session->organization_id, 'request-'.$id);
        $payload['project_id'] = (int) $session->project_id;
        $payload['session_id'] = (int) $session->id;
        $payload['actor_id'] = (int) $actor->id;
        $payload['version_fence'] = $fence;
        $payload['expires_at'] = $expiresAt;
        $payload['cost_delta_known'] = false;
        $payload['cost_delta'] = null;
        $payload['cost_state'] = 'unknown';
        $payload['cost_blockers'] = ['affected_rows_not_found'];
        $payload['simulation_input'] = [
            'kind' => 'correct_fact',
            'version' => 'test:v1',
            'before' => $payload['before_payload'],
            'after' => $payload['after_payload'],
            'value' => null,
            'dependency_keys' => $payload['dependency_keys'],
        ];
        $payload['simulation_fingerprint'] = app(EstimateChangeSimulation::class)->calculate(
            $session,
            new EstimateCommandInterpretation($payload['simulation_input']),
        )['fingerprint'];

        return $payload;
    }

    /** @return array{status:string} */
    private function raceOutcome(Process $process): array
    {
        $output = $process->getOutput();
        $start = strrpos($output, '{"status"');
        $end = $start === false ? false : strpos($output, '}', $start);
        self::assertIsInt($start, $output);
        self::assertIsInt($end, $output);

        return json_decode(substr($output, $start, $end - $start + 1), true, 512, JSON_THROW_ON_ERROR);
    }
}
