<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('postgres-contention')]
final class PipelineArtifactBudgetPostgresContentionTest extends TestCase
{
    public function test_two_connections_serialize_aggregate_completion_and_only_one_commits(): void
    {
        if (getenv('RUN_POSTGRES_CONTENTION_TESTS') !== '1' || DB::getDriverName() !== 'pgsql'
            || ! function_exists('pcntl_fork') || ! function_exists('stream_socket_pair')) {
            self::markTestSkipped('Requires explicit isolated PostgreSQL contention environment.');
        }
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $user = User::factory()->create();
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id, 'project_id' => $project->id, 'user_id' => $user->id,
            'status' => 'generating', 'processing_stage' => 'documents_understanding', 'processing_progress' => 1,
            'input_payload' => ['generation_attempt_id' => $attempt = (string) Str::uuid()], 'state_version' => 1,
        ]);
        $first = $this->connection('pipeline_budget_a');
        $secondName = 'pipeline_budget_b';
        $ids = [
            $first->table('estimate_generation_pipeline_checkpoints')->insertGetId($this->running($session, $attempt, 'understand_documents', 'a')),
            $first->table('estimate_generation_pipeline_checkpoints')->insertGetId($this->running($session, $attempt, 'understand_object', 'b')),
        ];
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($sockets);
        try {
            $first->beginTransaction();
            self::assertSame(1, $first->table('estimate_generation_pipeline_checkpoints')->where('id', $ids[0])->update($this->completed('a')));
            $pid = pcntl_fork();
            if ($pid === 0) {
                fclose($sockets[0]);
                try {
                    $second = $this->connection($secondName);
                    fwrite($sockets[1], "before\n");
                    $second->table('estimate_generation_pipeline_checkpoints')->where('id', $ids[1])->update($this->completed('b'));
                    fwrite($sockets[1], "unexpected_success\n");
                } catch (\Throwable $error) {
                    fwrite($sockets[1], str_contains($error->getMessage(), 'pipeline_artifact_budget_exceeded') ? "budget_rejected\n" : "wrong_error\n");
                }
                exit(0);
            }
            fclose($sockets[1]);
            self::assertSame("before\n", fgets($sockets[0]));
            $read = [$sockets[0]];
            $write = $except = null;
            self::assertSame(0, stream_select($read, $write, $except, 0, 500_000));
            $first->commit();
            self::assertSame("budget_rejected\n", fgets($sockets[0]));
            pcntl_waitpid($pid, $status);
            self::assertLessThanOrEqual(8_388_608, (int) DB::table('estimate_generation_pipeline_checkpoints')
                ->where('session_id', $session->id)->where('generation_attempt_id', $attempt)->where('status', 'completed')->sum('artifact_bytes'));
        } finally {
            if ($first->transactionLevel() > 0) {
                $first->rollBack();
            }
            DB::table('estimate_generation_sessions')->where('id', $session->id)->delete();
            $project->delete();
            $organization->delete();
            $user->delete();
            DB::disconnect('pipeline_budget_a');
            DB::disconnect($secondName);
        }
    }

    private function connection(string $name): Connection
    {
        $base = config('database.default');
        config(["database.connections.{$name}" => config("database.connections.{$base}")]);
        DB::purge($name);

        return DB::connection($name);
    }

    private function running(EstimateGenerationSession $session, string $attempt, string $stage, string $salt): array
    {
        $version = 'sha256:'.hash('sha256', $salt);
        $now = now();

        return ['session_id' => $session->id, 'organization_id' => $session->organization_id, 'project_id' => $session->project_id,
            'generation_attempt_id' => $attempt, 'base_input_version' => $version, 'stage' => $stage, 'input_version' => $version,
            'dependency_versions' => '{}', 'status' => 'running', 'metrics' => '{}', 'warnings' => '[]', 'attempt_count' => 1,
            'claim_token' => (string) Str::uuid(), 'lease_expires_at' => $now->addHour(), 'started_at' => $now, 'created_at' => $now, 'updated_at' => $now];
    }

    private function completed(string $salt): array
    {
        $version = 'sha256:'.hash('sha256', 'out-'.$salt);
        $now = now();

        return ['status' => 'completed', 'output_version' => $version, 'output_payload' => json_encode(['stage' => $salt]),
            'artifact_bytes' => 5_000_000, 'claim_token' => null, 'lease_expires_at' => null, 'completed_at' => $now, 'updated_at' => $now];
    }
}
