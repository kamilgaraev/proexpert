<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CheckpointClaimStatus;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\EloquentPipelineCheckpointStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

#[Group('postgres-contention')]
final class EloquentPipelineCheckpointPostgresContentionTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    #[Test]
    #[DataProvider('firstTransactionOutcomes')]
    public function concurrent_unique_claim_waits_for_first_transaction_and_resolves_without_duplicate(
        bool $commitFirst,
        CheckpointClaimStatus $expectedSecondStatus,
    ): void {
        $this->requirePostgresContentionEnvironment();
        $context = $this->context();
        $first = $this->independentConnection('checkpoint_contention_a');
        $now = new DateTimeImmutable('2026-07-11T10:00:00+00:00');
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($sockets === false) {
            throw new RuntimeException('Unable to create contention barrier.');
        }

        $first->beginTransaction();
        $first->table('estimate_generation_pipeline_checkpoints')->insert([
            'session_id' => $context->sessionId,
            'stage' => ProcessingStage::UnderstandObject->value,
            'input_version' => $context->inputVersion,
            'status' => 'running',
            'metrics' => '{}',
            'warnings' => '[]',
            'attempt_count' => 1,
            'claim_token' => '550e8400-e29b-41d4-a716-446655440000',
            'lease_expires_at' => $now->modify('+1 minute'),
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork contention claimant.');
        }

        if ($pid === 0) {
            fclose($sockets[0]);
            fwrite($sockets[1], "ready\n");
            $second = $this->independentConnection('checkpoint_contention_b');
            $claim = (new EloquentPipelineCheckpointStore($second))->claim(
                $context,
                ProcessingStage::UnderstandObject,
                $now,
                $now->modify('+1 minute'),
            );
            fwrite($sockets[1], $claim->status->value."\n");
            fclose($sockets[1]);
            exit(0);
        }

        fclose($sockets[1]);
        self::assertSame("ready\n", fgets($sockets[0]));
        usleep(250_000);
        $commitFirst ? $first->commit() : $first->rollBack();
        $secondStatus = trim((string) fgets($sockets[0]));
        fclose($sockets[0]);
        pcntl_waitpid($pid, $childStatus);

        self::assertTrue(pcntl_wifexited($childStatus));
        self::assertSame(0, pcntl_wexitstatus($childStatus));
        self::assertSame($expectedSecondStatus->value, $secondStatus);
        self::assertSame(1, DB::table('estimate_generation_pipeline_checkpoints')->count());
    }

    public static function firstTransactionOutcomes(): array
    {
        return [
            'first commits' => [true, CheckpointClaimStatus::Busy],
            'first rolls back' => [false, CheckpointClaimStatus::Acquired],
        ];
    }

    private function requirePostgresContentionEnvironment(): void
    {
        if (
            getenv('RUN_POSTGRES_CONTENTION_TESTS') !== '1'
            || DB::connection()->getDriverName() !== 'pgsql'
            || ! function_exists('pcntl_fork')
            || ! function_exists('stream_socket_pair')
        ) {
            self::markTestSkipped(
                'Requires RUN_POSTGRES_CONTENTION_TESTS=1, PostgreSQL and pcntl on Linux CI.',
            );
        }
    }

    private function independentConnection(string $name): Connection
    {
        $base = config('database.default');
        config(["database.connections.{$name}" => config("database.connections.{$base}")]);
        DB::purge($name);

        return DB::connection($name);
    }

    private function context(): PipelineContext
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $user = User::factory()->create();
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'status' => 'draft',
            'processing_stage' => 'draft',
            'processing_progress' => 0,
            'input_payload' => [],
            'state_version' => 0,
        ]);

        return new PipelineContext($session->id, $organization->id, $project->id, 0, 'sha256:contention');
    }
}
