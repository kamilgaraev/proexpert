<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunResult;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DurableAiPhysicalResponseStore;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\EloquentAiRoleRunRepository;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Role\AiAnalysisRole;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis\ProjectSynthesisInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis\RunProjectSynthesis;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis\TimewebProjectSynthesisModel;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\EloquentProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshotResolver;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageStore;
use App\BusinessModules\Addons\EstimateGeneration\Observability\RerankWireClient;
use App\BusinessModules\Addons\EstimateGeneration\Observability\UsageInvariantViolation;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('postgres-contract')]
final class ProjectSynthesisPostgresTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    #[Test]
    public function project_role_reserves_the_physical_attempt_before_binding_the_foreign_key(): void
    {
        self::assertSame('pgsql', DB::getDriverName());
        $this->ensureSchema();
        DB::beginTransaction();
        try {
            [, $scope] = $this->fixture();
            $runs = new EloquentAiRoleRunRepository(DB::connection(), 180);
            $input = new AiRoleRunInput(
                $scope[0],
                $scope[1],
                $scope[2],
                null,
                null,
                'estimate_session',
                (string) $scope[2],
                $scope[3],
                AiAnalysisRole::ProjectEngineer,
                'openai/gpt-5-mini',
                'project-synthesis:v1',
                hash('sha256', 'project-synthesis-input'),
            );
            $owner = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
            $attemptId = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
            $claim = $runs->claim($input, $owner);

            $runs->startPhysicalAttempt($claim->runId, $owner, $attemptId);

            $attempt = DB::table('estimate_generation_vision_physical_attempts')
                ->where('attempt_id', $attemptId)
                ->first();
            self::assertNotNull($attempt);
            self::assertSame($scope[0], (int) $attempt->organization_id);
            self::assertSame($scope[1], (int) $attempt->project_id);
            self::assertSame($scope[2], (int) $attempt->session_id);
            self::assertSame('wire_started', $attempt->state);
            self::assertSame($attemptId, DB::table('estimate_generation_ai_role_runs')
                ->where('id', $claim->runId)
                ->value('physical_attempt_id'));
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function physical_attempt_cannot_be_rebound_to_a_different_logical_input(): void
    {
        self::assertSame('pgsql', DB::getDriverName());
        $this->ensureSchema();
        DB::beginTransaction();
        try {
            [, $scope] = $this->fixture();
            $runs = new EloquentAiRoleRunRepository(DB::connection(), 180);
            $owner = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
            $attemptId = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
            $first = $runs->claim($this->runInput($scope, hash('sha256', 'first')), $owner);
            $runs->startPhysicalAttempt($first->runId, $owner, $attemptId);
            $runs->fail($first->runId, $owner, new \App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunFailure(
                'test_failure',
                false,
            ));
            $secondOwner = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
            $second = $runs->claim($this->runInput($scope, hash('sha256', 'second')), $secondOwner);

            $this->expectException(UsageInvariantViolation::class);
            $runs->startPhysicalAttempt($second->runId, $secondOwner, $attemptId);
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function confirmed_synthesis_response_is_recovered_without_a_second_wire_call(): void
    {
        self::assertSame('pgsql', DB::getDriverName());
        $this->ensureSchema();
        DB::beginTransaction();
        try {
            [, $scope] = $this->fixture();
            $wire = new RecordedSynthesisWireClient;
            $usage = $this->createMock(AiUsageStore::class);
            $prices = $this->createMock(AiPriceSnapshotResolver::class);
            $prices->method('resolve')->willReturn(AiPriceSnapshot::fromArray([]));
            $model = new TimewebProjectSynthesisModel(
                $wire,
                $usage,
                $prices,
                'openai/gpt-5-mini',
                100000,
                1000,
                30,
                new DurableAiPhysicalResponseStore(DB::connection()),
            );
            $input = new ProjectSynthesisInput(
                $scope[0],
                $scope[1],
                $scope[2],
                [$scope[3]],
                [],
                [],
                [],
                ['arbiter' => [], 'geometry_expert' => []],
                RunProjectSynthesis::PROMPT_CONTRACT,
            );
            $runs = new EloquentAiRoleRunRepository(DB::connection(), 180);
            $runInput = $this->runInput($scope, $input->fingerprint(), $input->aggregateSourceVersion());
            $owner = AiOperationContext::deterministicId('synthesis-recovery-owner');
            $claim = $runs->claim($runInput, $owner);
            self::assertSame('owned', $claim->disposition);
            $model->synthesize(
                $input,
                [],
                [],
                static fn (string $attemptId) => $runs->startPhysicalAttempt($claim->runId, $owner, $attemptId),
            );
            DB::table('estimate_generation_ai_role_runs')->where('id', $claim->runId)->update([
                'lease_expires_at' => now()->subMinute(),
            ]);

            $selection = (new RunProjectSynthesis($runs, $model, 'openai/gpt-5-mini'))->run($input, [], []);

            self::assertSame([], $selection->acceptedLinkIds);
            self::assertSame(1, $wire->calls);
            self::assertSame('completed', DB::table('estimate_generation_ai_role_runs')
                ->where('id', $claim->runId)->value('status'));
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function synthesis_projection_is_atomic_replayable_and_fenced_by_exact_snapshot(): void
    {
        self::assertSame('pgsql', DB::getDriverName());
        self::assertTrue(
            DB::getDatabaseName() === 'most_backend_testing'
                || (DB::getDatabaseName() === 'most_ai_estimator_contract'
                    && getenv('RUN_ESTIMATE_GENERATION_POSTGRES_CONTRACT') === '1'),
        );
        $this->ensureSchema();
        DB::beginTransaction();
        try {
            [$models, $scope] = $this->fixture();
            $runs = new EloquentAiRoleRunRepository(DB::connection(), 180);
            $arbiterFingerprint = $this->completeRole($runs, $scope, AiAnalysisRole::Arbiter, 'arbiter');
            $geometryFingerprint = $this->completeRole($runs, $scope, AiAnalysisRole::GeometryExpert, 'geometry');
            $dependencies = $models->completedSynthesisRoleFingerprints(...[...array_slice($scope, 0, 3), [$scope[3]]]);

            self::assertSame([$arbiterFingerprint], $dependencies['arbiter']);
            self::assertSame([$geometryFingerprint], $dependencies['geometry_expert']);

            $capture = $models->snapshotForUnderstanding($scope[0], $scope[1], $scope[2], 100);
            $inputFingerprint = hash('sha256', json_encode([
                'snapshot' => $capture['token'],
                'dependencies' => $dependencies,
                'contract' => 'project-synthesis:v1',
            ], JSON_THROW_ON_ERROR));
            self::assertTrue($models->replaceUnderstanding(
                $scope[0],
                $scope[1],
                $scope[2],
                $scope[3],
                $inputFingerprint,
                $capture['token'],
                [],
                [],
                [],
                [],
                1,
            ));
            self::assertNotNull($models->replayUnderstanding(
                $scope[0],
                $scope[1],
                $scope[2],
                $scope[3],
                $inputFingerprint,
                $capture['token'],
            ));
            self::assertSame($inputFingerprint, $models->currentUnderstanding($scope[0], $scope[1], $scope[2])['input_fingerprint']);
            self::assertSame(1, DB::table('estimate_generation_project_understanding_runs')
                ->where('organization_id', $scope[0])
                ->where('project_id', $scope[1])
                ->where('session_id', $scope[2])
                ->count());

            $models->saveSourceModel([
                new Entity('roof:2', $scope[0], $scope[1], $scope[2], $scope[3], 'quantity', 'roof:2', ['value' => 2, 'unit' => 'm2']),
            ], [
                new Fact(
                    'fact:roof:2',
                    $scope[0],
                    $scope[1],
                    $scope[2],
                    $scope[3],
                    'roof:2',
                    'material',
                    'unknown',
                    null,
                    0,
                    'unresolved',
                    'unresolved',
                    [],
                ),
            ], []);

            self::assertFalse($models->replaceUnderstanding(
                $scope[0],
                $scope[1],
                $scope[2],
                $scope[3],
                hash('sha256', 'stale-successor'),
                $capture['token'],
                [],
                [],
                [],
                [],
                1,
            ));
            self::assertSame($inputFingerprint, $models->currentUnderstanding($scope[0], $scope[1], $scope[2])['input_fingerprint']);
            self::assertSame(1, DB::table('estimate_generation_project_understanding_runs')
                ->where('organization_id', $scope[0])
                ->where('project_id', $scope[1])
                ->where('session_id', $scope[2])
                ->count());
        } finally {
            DB::rollBack();
        }
    }

    private function ensureSchema(): void
    {
        if (! Schema::hasTable('estimate_generation_sessions')) {
            (require app_path('BusinessModules/Addons/EstimateGeneration/migrations/2026_03_24_100000_create_estimate_generation_sessions_table.php'))->up();
        }
        if (! Schema::hasTable('estimate_generation_project_model_derived_quantities')) {
            if (Schema::hasTable('estimate_generation_project_model_entities')) {
                (require app_path('BusinessModules/Addons/EstimateGeneration/migrations/2026_08_01_000200_create_estimate_generation_project_model_tables.php'))->down();
            }
            foreach ([
                '2026_08_01_000200_create_estimate_generation_project_model_tables.php',
                '2026_08_10_000600_consolidate_estimate_project_model_v2.php',
                '2026_08_10_000610_secure_estimate_project_model_v2_schema.php',
                '2026_08_12_000100_add_derived_quantity_current_projection.php',
            ] as $file) {
                (require app_path('BusinessModules/Addons/EstimateGeneration/migrations/'.$file))->up();
            }
        }
        if (! Schema::hasTable('estimate_generation_ai_role_runs')) {
            if (! Schema::hasTable('estimate_generation_documents')) {
                DB::statement('CREATE TABLE estimate_generation_documents (id bigint PRIMARY KEY)');
            }
            if (! Schema::hasTable('estimate_generation_document_pages')) {
                DB::statement('CREATE TABLE estimate_generation_document_pages (id bigint PRIMARY KEY)');
            }
            if (! Schema::hasTable('estimate_generation_vision_physical_attempts')) {
                DB::statement('CREATE TABLE estimate_generation_vision_physical_attempts (attempt_id uuid PRIMARY KEY)');
            }
            (require app_path('BusinessModules/Addons/EstimateGeneration/migrations/2026_08_14_000100_create_estimate_generation_ai_role_runs.php'))->up();
        }
        (require app_path('BusinessModules/Addons/EstimateGeneration/migrations/2026_08_14_000200_detach_project_model_from_building_model.php'))->up();
    }

    /** @return array{EloquentProjectModelRepository,array{int,int,int,string}} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => User::factory()->create()->id,
            'status' => 'draft',
            'processing_stage' => 'draft',
            'processing_progress' => 0,
            'input_payload' => [],
        ]);
        $repository = new EloquentProjectModelRepository(app('db'));
        $scope = [
            (int) $organization->id,
            (int) $project->id,
            (int) $session->id,
            'sha256:'.str_repeat('7', 64),
        ];
        $repository->saveSourceModel([
            new Entity('roof:1', $scope[0], $scope[1], $scope[2], $scope[3], 'quantity', 'roof:1', ['value' => 1, 'unit' => 'm2']),
        ], [], []);

        return [$repository, $scope];
    }

    /** @param array{int,int,int,string} $scope */
    private function completeRole(
        EloquentAiRoleRunRepository $runs,
        array $scope,
        AiAnalysisRole $role,
        string $seed,
    ): string {
        $fingerprint = hash('sha256', $seed);
        $input = new AiRoleRunInput(
            $scope[0],
            $scope[1],
            $scope[2],
            null,
            null,
            'estimate_session',
            (string) $scope[2],
            $scope[3],
            $role,
            'openai/gpt-5-mini',
            $role->value.':v1',
            $fingerprint,
        );
        $owner = $seed === 'arbiter'
            ? 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'
            : 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        $claim = $runs->claim($input, $owner);
        $runs->complete($claim->runId, $owner, new AiRoleRunResult(['result' => $seed], null));

        return $fingerprint;
    }

    /** @param array{int,int,int,string} $scope */
    private function runInput(array $scope, string $fingerprint, ?string $subjectVersion = null): AiRoleRunInput
    {
        return new AiRoleRunInput(
            $scope[0],
            $scope[1],
            $scope[2],
            null,
            null,
            'estimate_session',
            (string) $scope[2],
            $subjectVersion ?? $scope[3],
            AiAnalysisRole::ProjectEngineer,
            'openai/gpt-5-mini',
            RunProjectSynthesis::PROMPT_CONTRACT,
            $fingerprint,
        );
    }
}

final class RecordedSynthesisWireClient implements RerankWireClient
{
    public int $calls = 0;

    public function provider(): string
    {
        return 'recorded';
    }

    public function call(string $model, array $messages, array $options): array
    {
        $this->calls++;

        return [
            'content' => json_encode([
                'accepted_link_ids' => [],
                'question_conflict_ids' => [],
            ], JSON_THROW_ON_ERROR),
            'model' => $model,
            'usage_available' => true,
            'input_tokens' => 100,
            'output_tokens' => 50,
        ];
    }
}
