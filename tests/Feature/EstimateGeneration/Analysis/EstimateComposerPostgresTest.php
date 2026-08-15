<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerModel;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\RunEstimateComposer;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\TimewebEstimateComposerModel;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DurableAiPhysicalResponseStore;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\EloquentAiRoleRunRepository;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Role\AiAnalysisRole;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshotResolver;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageStore;
use App\BusinessModules\Addons\EstimateGeneration\Observability\RerankWireClient;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\PostgresConnection;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('postgres-contract')]
final class EstimateComposerPostgresTest extends TestCase
{
    public function createApplication(): Application
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    #[Test]
    public function composer_role_run_is_persisted_replayed_once_and_recomputed_after_snapshot_change(): void
    {
        self::assertInstanceOf(TimewebEstimateComposerModel::class, $this->app->make(EstimateComposerModel::class));
        self::assertInstanceOf(RunEstimateComposer::class, $this->app->make(RunEstimateComposer::class));

        [$connection, $schema] = $this->fixture();
        try {
            $model = new PostgresRecordedEstimateComposerModel;
            $composer = new RunEstimateComposer(
                new EloquentAiRoleRunRepository($connection, 180),
                $model,
                'openai/gpt-5-mini',
            );
            $firstInput = $this->input(str_repeat('a', 64));

            $first = $composer->run($firstInput);
            $replay = $composer->run($firstInput);

            self::assertSame($first, $replay);
            self::assertSame(1, $model->calls);
            self::assertSame('10.2500', $firstInput->derivedQuantities[0]['value']);
            $runs = $connection->table('estimate_generation_ai_role_runs')
                ->where('organization_id', 10)
                ->where('project_id', 20)
                ->where('session_id', 30)
                ->where('role', 'estimate_composer')
                ->get();
            self::assertCount(1, $runs);
            $run = $runs->sole();
            self::assertSame('completed', $run->status);
            self::assertSame($firstInput->snapshotToken, $run->subject_version);
            self::assertSame($firstInput->fingerprint(), $run->input_fingerprint);

            $secondInput = $this->input(str_repeat('b', 64));
            self::assertNotSame($firstInput->fingerprint(), $secondInput->fingerprint());
            $composer->run($secondInput);

            self::assertSame(2, $model->calls);
            self::assertSame(2, $connection->table('estimate_generation_ai_role_runs')
                ->where('role', 'estimate_composer')->where('session_id', 30)->count());
        } finally {
            $this->cleanup($connection, $schema);
        }
    }

    #[Test]
    public function confirmed_composer_response_is_recovered_without_a_second_wire_call(): void
    {
        [$connection, $schema] = $this->fixture();
        try {
            $wire = new RecordedComposerWireClient;
            $usage = $this->createMock(AiUsageStore::class);
            $prices = $this->createMock(AiPriceSnapshotResolver::class);
            $prices->method('resolve')->willReturn(AiPriceSnapshot::fromArray([]));
            $model = new TimewebEstimateComposerModel(
                $wire,
                $usage,
                $prices,
                'openai/gpt-5-mini',
                100000,
                1000,
                30,
                new DurableAiPhysicalResponseStore($connection),
            );
            $input = $this->input(str_repeat('c', 64));
            $repository = new EloquentAiRoleRunRepository($connection, 180);
            $runInput = new AiRoleRunInput(
                organizationId: $input->organizationId,
                projectId: $input->projectId,
                sessionId: $input->sessionId,
                documentId: null,
                pageId: null,
                subjectType: 'estimate_session',
                subjectId: (string) $input->sessionId,
                subjectVersion: $input->snapshotToken,
                role: AiAnalysisRole::EstimateComposer,
                model: 'openai/gpt-5-mini',
                promptContractVersion: RunEstimateComposer::PROMPT_CONTRACT,
                inputFingerprint: $input->fingerprint(),
            );
            $owner = AiOperationContext::deterministicId('composer-recovery-owner');
            $claim = $repository->claim($runInput, $owner);
            self::assertSame('owned', $claim->disposition);
            $model->compose(
                $input,
                static fn (string $attemptId) => $repository->startPhysicalAttempt($claim->runId, $owner, $attemptId),
            );
            $connection->table('estimate_generation_ai_role_runs')->where('id', $claim->runId)->update([
                'lease_expires_at' => now()->subMinute(),
            ]);

            $result = (new RunEstimateComposer($repository, $model, 'openai/gpt-5-mini'))->run($input);

            self::assertNotEmpty($result);
            self::assertSame(1, $wire->calls);
            self::assertSame('completed', $connection->table('estimate_generation_ai_role_runs')
                ->where('id', $claim->runId)->value('status'));
            self::assertSame('completed', $connection->table('estimate_generation_vision_physical_attempts')
                ->where('attempt_id', $connection->table('estimate_generation_ai_role_runs')
                    ->where('id', $claim->runId)->value('physical_attempt_id'))
                ->value('state'));
        } finally {
            $this->cleanup($connection, $schema);
        }
    }

    /** @return array{PostgresConnection,string} */
    private function fixture(): array
    {
        $connection = $this->app->make('db')->connection();
        self::assertInstanceOf(PostgresConnection::class, $connection);
        self::assertSame('pgsql', $connection->getDriverName());
        self::assertTrue(
            $connection->getDatabaseName() === 'most_backend_testing'
                || ($connection->getDatabaseName() === 'most_ai_estimator_contract'
                    && getenv('RUN_ESTIMATE_GENERATION_POSTGRES_CONTRACT') === '1'),
        );
        $connection->statement("SET statement_timeout TO '5000ms'");
        $connection->statement("SET lock_timeout TO '5000ms'");
        $schema = 'most_ci_estimate_composer_'.bin2hex(random_bytes(8));
        $connection->unprepared('CREATE SCHEMA "'.$schema.'"');
        $connection->unprepared('SET search_path TO "'.$schema.'"');
        $connection->unprepared(<<<'SQL'
            CREATE TABLE organizations (id bigint PRIMARY KEY);
            CREATE TABLE projects (id bigint PRIMARY KEY);
            CREATE TABLE estimate_generation_sessions (id bigint PRIMARY KEY);
            CREATE TABLE estimate_generation_documents (id bigint PRIMARY KEY);
            CREATE TABLE estimate_generation_document_pages (id bigint PRIMARY KEY);
            SQL);
        (require app_path('BusinessModules/Addons/EstimateGeneration/migrations/2026_08_10_000300_create_vision_physical_attempts.php'))->up();
        (require app_path('BusinessModules/Addons/EstimateGeneration/migrations/2026_08_10_000400_add_recovery_lease_to_vision_physical_attempts.php'))->up();
        (require app_path('BusinessModules/Addons/EstimateGeneration/migrations/2026_08_15_000100_separate_vision_logical_request_from_processing_lineage.php'))->up();
        (require app_path('BusinessModules/Addons/EstimateGeneration/migrations/2026_08_14_000100_create_estimate_generation_ai_role_runs.php'))->up();
        $connection->table('organizations')->insert(['id' => 10]);
        $connection->table('projects')->insert(['id' => 20]);
        $connection->table('estimate_generation_sessions')->insert(['id' => 30]);

        return [$connection, $schema];
    }

    private function cleanup(PostgresConnection $connection, string $schema): void
    {
        if (preg_match('/^most_ci_estimate_composer_[a-f0-9]{16}$/D', $schema) !== 1) {
            return;
        }
        $connection->unprepared('SET search_path TO public');
        $connection->unprepared('DROP SCHEMA "'.$schema.'" CASCADE');
    }

    private function input(string $snapshotToken): EstimateComposerInput
    {
        return new EstimateComposerInput(
            organizationId: 10,
            projectId: 20,
            sessionId: 30,
            snapshotToken: $snapshotToken,
            facts: [['id' => 'fact:foundation', 'status' => 'confirmed']],
            derivedQuantities: [['id' => 'quantity:foundation', 'value' => '10.2500', 'unit' => 'm3']],
            decisions: [['id' => 'decision:foundation', 'version' => 1]],
            candidates: [[
                'candidate_id' => 'baseline:foundation',
                'work_key' => 'foundation',
                'name' => 'Устройство фундамента',
                'unit' => 'm3',
                'quantity' => '10.2500',
                'quantity_formula' => 'foundation.volume',
                'source_fact_ids' => ['fact:foundation'],
                'technology_package_candidate' => null,
            ]],
            missingDocuments: [['code' => 'foundation_detail', 'source_fact_ids' => ['fact:foundation']]],
            contractVersion: RunEstimateComposer::PROMPT_CONTRACT,
        );
    }
}

final class PostgresRecordedEstimateComposerModel implements EstimateComposerModel
{
    public int $calls = 0;

    public function compose(EstimateComposerInput $input, callable $onPhysicalAttemptReserved): array
    {
        $this->calls++;
        $onPhysicalAttemptReserved(AiOperationContext::deterministicId('recorded-composer|'.$input->fingerprint()));

        return ['work_intents' => array_map(static fn (array $candidate): array => [
            'kind' => 'existing',
            'candidate_id' => $candidate['candidate_id'],
            'work_key' => null,
            'name' => null,
            'derived_quantity_id' => null,
            'source_fact_ids' => $candidate['source_fact_ids'],
            'technology_package_candidate' => $candidate['technology_package_candidate'],
            'assumptions' => [],
            'exclusions' => [],
            'missing_document_recommendations' => [],
        ], $input->candidates)];
    }
}

final class RecordedComposerWireClient implements RerankWireClient
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
            'content' => json_encode(['work_intents' => [[
                'kind' => 'existing',
                'candidate_id' => 'baseline:foundation',
                'work_key' => null,
                'name' => null,
                'derived_quantity_id' => null,
                'source_fact_ids' => ['fact:foundation'],
                'technology_package_candidate' => null,
                'assumptions' => [],
                'exclusions' => [],
                'missing_document_recommendations' => [],
            ]]], JSON_THROW_ON_ERROR),
            'model' => $model,
            'usage_available' => true,
            'input_tokens' => 100,
            'output_tokens' => 50,
        ];
    }
}
