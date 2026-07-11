<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\BuildingModel;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\BuildingModelContentCollision;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\BuildingModelOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\BuildingModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\FloorData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\NormalizedBuildingModelData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\EloquentBuildingModelStore;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EloquentEvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceData;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Throwable;

#[Group('postgres-contract')]
final class EstimateGenerationBuildingModelPostgresTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    #[Test]
    public function committed_winner_makes_concurrent_same_content_idempotent_and_different_content_collides(): void
    {
        $this->requireEnvironment(true);
        $fixture = $this->fixture();
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            throw new RuntimeException('Unable to create building model contention barrier.');
        }
        $firstName = 'building_model_a_'.strtolower(Str::random(8));
        $secondName = 'building_model_b_'.strtolower(Str::random(8));
        $first = $this->independentConnection($firstName);
        $pid = null;
        try {
            $first->beginTransaction();
            $winner = $this->repository($first)->store($fixture['context'], $this->model($fixture['evidence_id'], 2.8));
            self::assertTrue($winner->created);
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Unable to fork building model contention writer.');
            }
            if ($pid === 0) {
                fclose($sockets[0]);
                $this->runChild($sockets[1], $secondName, $fixture);
            }
            fclose($sockets[1]);
            unset($sockets[1]);
            self::assertSame("before_store\n", fgets($sockets[0]));
            self::assertFalse($this->readable($sockets[0], 0, 750_000));
            $first->commit();
            self::assertTrue($this->readable($sockets[0], 5, 0));
            $result = json_decode(trim((string) fgets($sockets[0])), true, flags: JSON_THROW_ON_ERROR);
            pcntl_waitpid($pid, $status);
            $pid = null;
            self::assertSame(false, $result['created'] ?? null);
            self::assertSame($winner->id, $result['id'] ?? null);
            self::assertSame(1, DB::table('estimate_generation_building_models')->where('session_id', $fixture['session_id'])->count());

            $this->expectException(BuildingModelContentCollision::class);
            $this->repository(DB::connection())->store($fixture['context'], $this->model($fixture['evidence_id'], 3.0));
        } finally {
            if ($first->transactionLevel() > 0) {
                $first->rollBack();
            }
            if ($pid !== null) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
            foreach ($sockets as $socket) {
                if (is_resource($socket)) {
                    fclose($socket);
                }
            }
            DB::disconnect($firstName);
            $this->cleanup($fixture);
        }
    }

    #[Test]
    public function postgres_enforces_tenant_evidence_json_scale_immutability_and_session_cascade(): void
    {
        $this->requireEnvironment(false);
        $fixture = $this->fixture();
        $foreign = $this->fixture();
        try {
            $stored = $this->repository(DB::connection())->store($fixture['context'], $this->model($fixture['evidence_id'], 2.8));
            DB::beginTransaction();
            $row = (array) DB::table('estimate_generation_building_models')->where('id', $stored->id)->first();

            $this->assertRejected('tenant mismatch', [...$row, 'id' => null, 'input_version' => 'sha256:'.str_repeat('c', 64), 'organization_id' => $foreign['organization_id']]);
            $this->assertRejected('unknown scale nullable bypass', [...$row, 'id' => null, 'input_version' => 'sha256:'.str_repeat('d', 64), 'scale_status' => 'unknown']);
            $this->assertRejected('invalid model version', [...$row, 'id' => null, 'input_version' => 'sha256:'.str_repeat('e', 64), 'model_version' => 'building-model:v2']);
            $this->assertRejected('open model json', [...$row, 'id' => null, 'input_version' => 'sha256:'.str_repeat('f', 64), 'model' => json_encode([...json_decode((string) $row['model'], true, flags: JSON_THROW_ON_ERROR), 'prompt' => 'secret'], JSON_THROW_ON_ERROR)]);
            $this->assertRejectedLink($stored->id, $foreign['evidence_id'], $fixture);
            $this->assertMutationRejected(fn () => DB::table('estimate_generation_building_models')->where('id', $stored->id)->update(['scale_status' => 'estimated']));
            $this->assertMutationRejected(fn () => DB::table('estimate_generation_building_models')->where('id', $stored->id)->delete());
            $this->assertMutationRejected(fn () => DB::table('estimate_generation_building_model_evidence')->where('building_model_id', $stored->id)->delete());

            DB::table('estimate_generation_sessions')->where('id', $fixture['session_id'])->delete();
            self::assertSame(0, DB::table('estimate_generation_building_models')->where('id', $stored->id)->count());
            self::assertSame(0, DB::table('estimate_generation_building_model_evidence')->where('building_model_id', $stored->id)->count());
            $fixture['session_id'] = null;
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->cleanup($foreign);
            $this->cleanup($fixture);
        }
    }

    private function runChild(mixed $socket, string $connectionName, array $fixture): never
    {
        $exit = 0;
        try {
            fwrite($socket, "before_store\n");
            $connection = $this->independentConnection($connectionName);
            $stored = $this->repository($connection)->store($fixture['context'], $this->model($fixture['evidence_id'], 2.8));
            fwrite($socket, json_encode(['id' => $stored->id, 'created' => $stored->created], JSON_THROW_ON_ERROR)."\n");
        } catch (Throwable $error) {
            $exit = 1;
            fwrite($socket, json_encode(['error' => $error::class, 'code' => $error->getCode()], JSON_THROW_ON_ERROR)."\n");
        } finally {
            DB::disconnect($connectionName);
            fclose($socket);
        }
        exit($exit);
    }

    private function repository(Connection $connection): BuildingModelRepository
    {
        return new BuildingModelRepository(new EloquentBuildingModelStore($connection), new EloquentEvidenceRepository($connection));
    }

    private function model(int $evidenceId, float $height): NormalizedBuildingModelData
    {
        return new NormalizedBuildingModelData('m', 'confirmed', 0.01, [
            new FloorData('floor-1', 0, $height, [], [], [], [], [$evidenceId], 1, 'confirmed'),
        ], [], 'building-model:v1');
    }

    private function fixture(): array
    {
        $fixture = ['organization_id' => null, 'project_id' => null, 'user_id' => null, 'session_id' => null, 'evidence_id' => null, 'context' => null];
        try {
            $organization = Organization::factory()->create();
            $fixture['organization_id'] = (int) $organization->id;
            $project = Project::factory()->for($organization)->create();
            $fixture['project_id'] = (int) $project->id;
            $user = User::factory()->create();
            $fixture['user_id'] = (int) $user->id;
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
            $fixture['session_id'] = (int) $session->id;
            $evidence = (new EloquentEvidenceRepository(DB::connection()))->insertOrGet(new EvidenceData(
                (int) $organization->id,
                (int) $project->id,
                (int) $session->id,
                EvidenceType::Extracted,
                EvidenceSourceType::Document,
                'document:1',
                'sha256:'.str_repeat('a', 64),
                ['document_id' => 1],
                ['field_key' => 'floor_height', 'field_value' => 2.8, 'unit' => 'm'],
                1,
                'contract',
                'contract:abcdef',
            ));
            $fixture['evidence_id'] = $evidence->id;
            $fixture['context'] = new BuildingModelOperationContext((int) $organization->id, (int) $project->id, (int) $session->id, 'sha256:'.str_repeat('b', 64));

            return $fixture;
        } catch (Throwable $error) {
            $this->cleanup($fixture);
            throw $error;
        }
    }

    private function assertRejected(string $name, array $row): void
    {
        unset($row['id']);
        DB::statement('SAVEPOINT building_model_constraint');
        try {
            DB::table('estimate_generation_building_models')->insert($row);
            self::fail($name.' was accepted.');
        } catch (QueryException) {
            DB::statement('ROLLBACK TO SAVEPOINT building_model_constraint');
        } finally {
            DB::statement('RELEASE SAVEPOINT building_model_constraint');
        }
    }

    private function assertRejectedLink(int $modelId, int $foreignEvidenceId, array $scope): void
    {
        DB::statement('SAVEPOINT building_model_link');
        try {
            DB::table('estimate_generation_building_model_evidence')->insert([
                'building_model_id' => $modelId,
                'evidence_id' => $foreignEvidenceId,
                'organization_id' => $scope['organization_id'],
                'project_id' => $scope['project_id'],
                'session_id' => $scope['session_id'],
                'created_at' => now(),
            ]);
            self::fail('Cross-tenant evidence link was accepted.');
        } catch (QueryException) {
            DB::statement('ROLLBACK TO SAVEPOINT building_model_link');
        } finally {
            DB::statement('RELEASE SAVEPOINT building_model_link');
        }
    }

    private function assertMutationRejected(callable $mutation): void
    {
        DB::statement('SAVEPOINT building_model_mutation');
        try {
            $mutation();
            self::fail('Immutable building model mutation was accepted.');
        } catch (QueryException) {
            DB::statement('ROLLBACK TO SAVEPOINT building_model_mutation');
        } finally {
            DB::statement('RELEASE SAVEPOINT building_model_mutation');
        }
    }

    private function requireEnvironment(bool $contention): void
    {
        if (getenv('RUN_ESTIMATE_GENERATION_POSTGRES_CONTRACT') !== '1' || DB::getDriverName() !== 'pgsql'
            || ($contention && (! function_exists('pcntl_fork') || ! function_exists('stream_socket_pair') || ! function_exists('posix_kill')))) {
            self::markTestSkipped('Requires explicit isolated PostgreSQL contract environment.');
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

    private function cleanup(array $fixture): void
    {
        if (($fixture['session_id'] ?? null) !== null) {
            DB::table('estimate_generation_sessions')->where('id', $fixture['session_id'])->delete();
        }
        if (($fixture['project_id'] ?? null) !== null) {
            DB::table('projects')->where('id', $fixture['project_id'])->delete();
        }
        if (($fixture['organization_id'] ?? null) !== null) {
            DB::table('organizations')->where('id', $fixture['organization_id'])->delete();
        }
        if (($fixture['user_id'] ?? null) !== null) {
            DB::table('users')->where('id', $fixture['user_id'])->delete();
        }
    }
}
