<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Evidence\EloquentEvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceData;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceParent;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRecorder;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRelation;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Throwable;

#[Group('postgres-contention')]
final class EvidencePostgresContentionTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    #[Test]
    public function two_connections_record_one_node_and_parent_edge_without_deadlock(): void
    {
        $this->requireEnvironment();
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            throw new RuntimeException('Unable to create evidence contention barrier.');
        }
        $firstName = 'evidence_contention_a_'.strtolower(Str::random(8));
        $secondName = 'evidence_contention_b_'.strtolower(Str::random(8));
        $fixture = null;
        $first = null;
        $pid = null;
        try {
            $fixture = $this->fixture();
            $suffix = bin2hex(random_bytes(8));
            $parentData = $this->data($fixture, $suffix, EvidenceType::SourceFact);
            $childData = $this->data($fixture, $suffix, EvidenceType::Extracted);
            $parent = (new EvidenceRecorder(app(EloquentEvidenceRepository::class)))->record($parentData);
            $first = $this->independentConnection($firstName);
            $first->beginTransaction();
            $firstNode = (new EvidenceRecorder(new EloquentEvidenceRepository($first)))->record($childData, [
                new EvidenceParent($parent->id, EvidenceRelation::DerivedFrom),
            ]);
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Unable to fork evidence recorder.');
            }
            if ($pid === 0) {
                fclose($sockets[0]);
                $this->runChild($sockets[1], $secondName, $childData, $parent->id);
            }
            fclose($sockets[1]);
            unset($sockets[1]);
            self::assertSame("before_record\n", fgets($sockets[0]));
            self::assertFalse($this->readable($sockets[0], 0, 750_000), 'Second record did not wait for the first transaction.');
            $first->commit();
            self::assertTrue($this->readable($sockets[0], 5, 0));
            $line = fgets($sockets[0]);
            pcntl_waitpid($pid, $status);
            $pid = null;
            if ($line === false) {
                self::fail(sprintf('Child closed socket without result: exited=%s status=%d signaled=%s', pcntl_wifexited($status) ? 'yes' : 'no', pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -1, pcntl_wifsignaled($status) ? 'yes' : 'no'));
            }
            try {
                $result = json_decode(trim($line), true, flags: JSON_THROW_ON_ERROR);
            } catch (Throwable $error) {
                self::fail(sprintf('Invalid child payload %s; status=%d; parse=%s', substr(trim($line), 0, 500), pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -1, $error->getMessage()));
            }
            self::assertIsArray($result);
            if (isset($result['error'])) {
                self::fail(sprintf('Child evidence record failed: %s: %s [%s]', $result['error'], $result['message'] ?? '', $result['code'] ?? ''));
            }
            self::assertTrue(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0);
            self::assertSame($firstNode->id, $result['id'] ?? null);
            self::assertSame(1, DB::table('estimate_generation_evidence')->where('fingerprint', $childData->fingerprint())->count());
            self::assertSame(1, DB::table('estimate_generation_evidence_edges')->where('parent_id', $parent->id)->where('child_id', $firstNode->id)->count());
        } finally {
            if ($first instanceof Connection && $first->transactionLevel() > 0) {
                $first->rollBack();
            }
            foreach ($sockets as $socket) {
                if (is_resource($socket)) {
                    fclose($socket);
                }
            }
            if ($pid !== null) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
            DB::disconnect($firstName);
            if (is_array($fixture)) {
                $cleanupErrors = [];
                foreach ([
                    ['estimate_generation_sessions', $fixture['session_id']], ['projects', $fixture['project_id']],
                    ['organizations', $fixture['organization_id']], ['users', $fixture['user_id']],
                    ['organizations', $fixture['organization_id']],
                ] as [$table, $id]) {
                    try {
                        DB::table($table)->where('id', $id)->delete();
                    } catch (Throwable $error) {
                        $cleanupErrors[] = [$table, $id, $error::class, $error->getMessage()];
                    }
                }
                if ($cleanupErrors !== []) {
                    fwrite(STDERR, 'Evidence contention cleanup diagnostics: '.json_encode($cleanupErrors, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR).PHP_EOL);
                }
            }
        }
    }

    private function runChild(mixed $socket, string $connectionName, EvidenceData $data, int $parentId): never
    {
        $exitCode = 0;
        try {
            fwrite($socket, "before_record\n");
            $node = (new EvidenceRecorder(new EloquentEvidenceRepository($this->independentConnection($connectionName))))->record($data, [
                new EvidenceParent($parentId, EvidenceRelation::DerivedFrom),
            ]);
            fwrite($socket, json_encode(['id' => $node->id], JSON_THROW_ON_ERROR)."\n");
        } catch (Throwable $error) {
            $exitCode = 1;
            fwrite($socket, json_encode(['error' => $error::class, 'message' => $error->getMessage(), 'code' => $error->getCode()], JSON_THROW_ON_ERROR)."\n");
        } finally {
            DB::disconnect($connectionName);
            fclose($socket);
        }
        exit($exitCode);
    }

    private function requireEnvironment(): void
    {
        if (getenv('RUN_ESTIMATE_GENERATION_POSTGRES_CONTRACT') !== '1' || DB::getDriverName() !== 'pgsql'
            || ! function_exists('pcntl_fork') || ! function_exists('stream_socket_pair') || ! function_exists('posix_kill')) {
            self::markTestSkipped('Requires RUN_ESTIMATE_GENERATION_POSTGRES_CONTRACT=1, PostgreSQL and pcntl on Linux CI.');
        }
    }

    private function independentConnection(string $name): Connection
    {
        $base = config('database.default');
        config(["database.connections.{$name}" => config("database.connections.{$base}")]);
        DB::purge($name);

        return DB::connection($name);
    }

    private function readable(mixed $socket, int $seconds, int $microseconds): bool
    {
        $read = [$socket];
        $write = null;
        $except = null;

        return stream_select($read, $write, $except, $seconds, $microseconds) === 1;
    }

    private function fixture(): array
    {
        return DB::transaction(function (): array {
            $organization = Organization::factory()->create();
            $project = Project::factory()->for($organization)->create();
            $user = User::factory()->create();
            $session = EstimateGenerationSession::query()->create([
                'organization_id' => $organization->id, 'project_id' => $project->id, 'user_id' => $user->id,
                'status' => 'draft', 'processing_stage' => 'draft', 'processing_progress' => 0, 'input_payload' => [], 'state_version' => 0,
            ]);

            return ['organization_id' => (int) $organization->id, 'project_id' => (int) $project->id, 'user_id' => (int) $user->id, 'session_id' => (int) $session->id];
        }, 3);
    }

    private function data(array $fixture, string $suffix, EvidenceType $type): EvidenceData
    {
        return new EvidenceData(
            $fixture['organization_id'], $fixture['project_id'], $fixture['session_id'], $type,
            EvidenceSourceType::Document, 'document:'.$fixture['session_id'], 'test:'.$suffix,
            ['document_id' => $fixture['session_id']],
            $type === EvidenceType::SourceFact ? ['fact_key' => 'area', 'fact_value' => 1] : ['field_key' => 'area', 'field_value' => 1],
            1, 'contract', 'contract:'.$suffix,
        );
    }
}
