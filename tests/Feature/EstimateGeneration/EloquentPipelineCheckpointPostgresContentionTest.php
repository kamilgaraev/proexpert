<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CheckpointClaimStatus;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\EloquentPipelineCheckpointStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineFailureDetails;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Throwable;

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
    public function concurrent_unique_claim_blocks_until_first_transaction_resolves(
        bool $commitFirst,
        CheckpointClaimStatus $expectedChildStatus,
    ): void {
        $this->requirePostgresContentionEnvironment();
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            throw new RuntimeException('Unable to create contention barrier.');
        }

        $firstName = 'checkpoint_contention_a_'.strtolower(Str::random(8));
        $secondName = 'checkpoint_contention_b_'.strtolower(Str::random(8));
        $first = null;
        $identity = null;
        $fixture = null;
        $pid = null;
        $childReaped = false;

        try {
            [$context, $fixture] = $this->uniqueContext($commitFirst ? 'commit' : 'rollback');
            $identity = [
                'session_id' => $context->sessionId,
                'stage' => ProcessingStage::UnderstandObject->value,
                'input_version' => $context->inputVersion,
            ];
            $first = $this->independentConnection($firstName);
            $now = new DateTimeImmutable('2026-07-11T10:00:00+00:00');
            $first->beginTransaction();
            $first->table('estimate_generation_pipeline_checkpoints')->insert([
                ...$identity,
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
            $parentStatus = CheckpointClaimStatus::Acquired;

            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Unable to fork contention claimant.');
            }

            if ($pid === 0) {
                fclose($sockets[0]);
                $this->runChildClaim($sockets[1], $secondName, $context, $now);
            }

            fclose($sockets[1]);
            unset($sockets[1]);

            self::assertTrue($this->waitUntilReadable($sockets[0], 5, 0), 'Child did not reach claim barrier.');
            self::assertSame("before_claim\n", fgets($sockets[0]));
            self::assertFalse(
                $this->waitUntilReadable($sockets[0], 0, 750_000),
                'Second claim returned before the first transaction resolved.',
            );

            $commitFirst ? $first->commit() : $first->rollBack();

            self::assertTrue($this->waitUntilReadable($sockets[0], 5, 0), 'Child claim timed out after release.');
            $childResult = json_decode(trim((string) fgets($sockets[0])), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($childResult);
            if (isset($childResult['error'])) {
                self::fail(sprintf(
                    'Child claim failed: %s [%s]',
                    (string) $childResult['error'],
                    (string) ($childResult['fingerprint'] ?? 'no-fingerprint'),
                ));
            }

            self::assertSame(CheckpointClaimStatus::Acquired, $parentStatus);
            self::assertSame($expectedChildStatus->value, $childResult['status'] ?? null);
            self::assertSame(
                1,
                DB::table('estimate_generation_pipeline_checkpoints')->where($identity)->count(),
            );

            $childReaped = $this->reapChildWithin($pid, 5);
            self::assertTrue($childReaped, 'Child claimant did not exit cleanly.');
        } finally {
            if ($first instanceof Connection && $first->transactionLevel() > 0) {
                $first->rollBack();
            }

            foreach ($sockets as $socket) {
                if (is_resource($socket)) {
                    fclose($socket);
                }
            }

            if ($pid !== null && $pid > 0 && ! $childReaped) {
                $this->terminateAndReapChild($pid);
            }

            DB::disconnect($firstName);
            if ($identity !== null) {
                DB::table('estimate_generation_pipeline_checkpoints')->where($identity)->delete();
            }
            if ($fixture !== null) {
                DB::table('estimate_generation_sessions')->where('id', $fixture['session_id'])->delete();
                DB::table('projects')->where('id', $fixture['project_id'])->delete();
                DB::table('organizations')->where('id', $fixture['organization_id'])->delete();
                DB::table('users')->where('id', $fixture['user_id'])->delete();
            }
        }
    }

    public static function firstTransactionOutcomes(): array
    {
        return [
            'first commits' => [true, CheckpointClaimStatus::Busy],
            'first rolls back' => [false, CheckpointClaimStatus::Acquired],
        ];
    }

    /** @param resource $socket */
    private function runChildClaim(
        mixed $socket,
        string $connectionName,
        PipelineContext $context,
        DateTimeImmutable $now,
    ): never {
        try {
            $second = $this->independentConnection($connectionName);
            fwrite($socket, "before_claim\n");
            $claim = (new EloquentPipelineCheckpointStore($second))->claim(
                $context,
                ProcessingStage::UnderstandObject,
                $now,
                $now->modify('+1 minute'),
            );
            fwrite($socket, json_encode(['status' => $claim->status->value], JSON_THROW_ON_ERROR)."\n");
        } catch (Throwable $error) {
            $failure = PipelineFailureDetails::from($error);
            fwrite($socket, json_encode([
                'error' => $error::class,
                'fingerprint' => $failure->fingerprint,
            ], JSON_THROW_ON_ERROR)."\n");
        } finally {
            DB::disconnect($connectionName);
            fclose($socket);
        }

        exit(0);
    }

    /** @param resource $socket */
    private function waitUntilReadable(mixed $socket, int $seconds, int $microseconds): bool
    {
        $read = [$socket];
        $write = null;
        $except = null;

        return stream_select($read, $write, $except, $seconds, $microseconds) === 1;
    }

    private function reapChildWithin(int $pid, int $timeoutSeconds): bool
    {
        $deadline = hrtime(true) + ($timeoutSeconds * 1_000_000_000);

        do {
            $result = pcntl_waitpid($pid, $status, WNOHANG);
            if ($result === $pid) {
                return pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0;
            }

            usleep(10_000);
        } while (hrtime(true) < $deadline);

        return false;
    }

    private function terminateAndReapChild(int $pid): void
    {
        if (function_exists('posix_kill')) {
            posix_kill($pid, SIGKILL);
        }

        pcntl_waitpid($pid, $status);
    }

    private function requirePostgresContentionEnvironment(): void
    {
        if (
            getenv('RUN_POSTGRES_CONTENTION_TESTS') !== '1'
            || DB::connection()->getDriverName() !== 'pgsql'
            || ! function_exists('pcntl_fork')
            || ! function_exists('stream_socket_pair')
            || ! function_exists('posix_kill')
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

    /**
     * @return array{
     *     PipelineContext,
     *     array{session_id: int, project_id: int, organization_id: int, user_id: int}
     * }
     */
    private function uniqueContext(string $case): array
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
        $context = new PipelineContext(
            $session->id,
            $organization->id,
            $project->id,
            0,
            sprintf('contention:%s:%s', $case, strtolower((string) Str::uuid())),
            'draft',
        );

        return [$context, [
            'session_id' => $session->id,
            'project_id' => $project->id,
            'organization_id' => $organization->id,
            'user_id' => $user->id,
        ]];
    }
}
