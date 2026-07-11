<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocumentPage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiCostCalculator;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageData;
use App\BusinessModules\Addons\EstimateGeneration\Observability\EloquentAiUsageStore;
use App\BusinessModules\Addons\EstimateGeneration\Observability\UsageInvariantViolation;
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
final class EstimateGenerationUsageLedgerPostgresTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    #[Test]
    public function committed_winner_unblocks_second_connection_as_idempotent_noop(): void
    {
        $this->requireEnvironment(true);
        $fixture = $this->fixture();
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            throw new RuntimeException('Unable to create usage contention barrier.');
        }
        $attempt = (string) Str::uuid();
        $firstName = 'usage_contention_a_'.strtolower(Str::random(8));
        $secondName = 'usage_contention_b_'.strtolower(Str::random(8));
        $first = $this->independentConnection($firstName);
        $pid = null;
        try {
            $first->beginTransaction();
            self::assertTrue($first->table('estimate_generation_ai_usage')->insert($this->row($fixture, $attempt)));
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Unable to fork usage contention recorder.');
            }
            if ($pid === 0) {
                fclose($sockets[0]);
                $this->runChild($sockets[1], $secondName, $this->row($fixture, $attempt));
            }
            fclose($sockets[1]);
            unset($sockets[1]);
            self::assertSame("before_insert\n", fgets($sockets[0]));
            self::assertFalse($this->readable($sockets[0], 0, 750_000));
            $first->commit();
            self::assertTrue($this->readable($sockets[0], 5, 0));
            $result = json_decode(trim((string) fgets($sockets[0])), true, flags: JSON_THROW_ON_ERROR);
            pcntl_waitpid($pid, $status);
            $pid = null;
            self::assertSame(0, $result['affected'] ?? null);
            self::assertSame(1, DB::table('estimate_generation_ai_usage')->where('attempt_id', $attempt)->count());
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
    public function postgres_enforces_scope_measurement_pricing_and_immutability_matrix(): void
    {
        $this->requireEnvironment(false);
        $fixture = $this->fixture();
        $attempt = (string) Str::uuid();
        DB::beginTransaction();
        try {
            DB::table('estimate_generation_ai_usage')->insert($this->row($fixture, $attempt));
            $priced = [...$this->row($fixture, (string) Str::uuid()), 'usage_status' => 'measured', 'status' => 'succeeded',
                'input_tokens' => 1, 'pricing_status' => 'available', 'cost_amount' => '0.00000050', 'currency' => 'USD',
                'price_snapshot' => json_encode(['input_per_million' => '0.50', 'cached_input_per_million' => '0.10',
                    'output_per_million' => '2.00', 'reasoning_mode' => 'excluded_from_output', 'currency' => 'USD',
                    'source' => 'fixture', 'version' => 'v1', 'effective_at' => '2026-07-11T00:00:00+00:00'], JSON_THROW_ON_ERROR)];
            self::assertTrue(DB::table('estimate_generation_ai_usage')->insert($priced));
            $collisionAttempt = (string) Str::uuid();
            $store = new EloquentAiUsageStore(new AiCostCalculator, DB::connection());
            $store->record($this->usage($fixture, $collisionAttempt, 1));
            try {
                $store->record($this->usage($fixture, $collisionAttempt, 2));
                self::fail('Same attempt with a different immutable fingerprint was accepted.');
            } catch (UsageInvariantViolation) {
            }
            $variants = [
                'attempt collision' => ['attempt_id' => $attempt, 'immutable_fingerprint' => 'sha256:'.str_repeat('b', 64)],
                'tenant mismatch' => ['organization_id' => $fixture['organization_id'] + 999999],
                'page nullable bypass' => ['document_id' => null, 'page_id' => $fixture['page_id']],
                'unit nullable bypass' => ['document_id' => null, 'unit_id' => $fixture['unit_id']],
                'cached exceeds input' => ['usage_status' => 'measured', 'input_tokens' => 1, 'cached_input_tokens' => 2],
                'negative counter' => ['input_tokens' => -1],
                'zero ordinal' => ['attempt_ordinal' => 0],
                'http failure without code' => ['status' => 'http_failed', 'http_code' => null],
                'connection with code' => ['status' => 'connection_failed', 'http_code' => 500],
                'image without detail' => ['image_count' => 1, 'image_detail' => null],
                'available pricing without snapshot' => ['usage_status' => 'measured', 'pricing_status' => 'available', 'cost_amount' => '1.00000000', 'currency' => 'USD'],
                'unsafe snapshot' => ['price_snapshot' => json_encode(['prompt' => 'secret'], JSON_THROW_ON_ERROR)],
                'numeric decimal snapshot' => ['price_snapshot' => json_encode(['input_per_million' => 1, 'cached_input_per_million' => '0', 'output_per_million' => '1', 'currency' => 'USD', 'source' => 'fixture', 'version' => 'v1', 'effective_at' => '2026-07-11T00:00:00+00:00'], JSON_THROW_ON_ERROR)],
                'invalid optional decimal' => ['price_snapshot' => json_encode(['input_per_million' => '1', 'cached_input_per_million' => '0', 'output_per_million' => '1', 'reasoning_per_million' => '1.123456789', 'currency' => 'USD', 'source' => 'fixture', 'version' => 'v1', 'effective_at' => '2026-07-11T00:00:00+00:00'], JSON_THROW_ON_ERROR)],
                'nil uuid' => ['attempt_id' => '00000000-0000-0000-0000-000000000000'],
                'nil correlation uuid' => ['correlation_id' => '00000000-0000-0000-0000-000000000000'],
            ];
            foreach ($variants as $name => $changes) {
                $this->assertRejected($name, [...$this->row($fixture, (string) Str::uuid()), ...$changes]);
            }
            $this->assertMutationRejected(fn () => DB::table('estimate_generation_ai_usage')->where('attempt_id', $attempt)->update(['duration_ms' => 2]));
            $this->assertMutationRejected(fn () => DB::table('estimate_generation_ai_usage')->where('attempt_id', $attempt)->delete());

            DB::table('estimate_generation_sessions')->where('id', $fixture['session_id'])->delete();
            self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('attempt_id', $attempt)->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->cleanup($fixture);
        }
    }

    private function runChild(mixed $socket, string $connectionName, array $row): never
    {
        $exit = 0;
        try {
            fwrite($socket, "before_insert\n");
            $affected = $this->independentConnection($connectionName)->table('estimate_generation_ai_usage')->insertOrIgnore($row);
            fwrite($socket, json_encode(['affected' => $affected], JSON_THROW_ON_ERROR)."\n");
        } catch (Throwable $error) {
            $exit = 1;
            fwrite($socket, json_encode(['error' => $error::class, 'code' => $error->getCode()], JSON_THROW_ON_ERROR)."\n");
        } finally {
            DB::disconnect($connectionName);
            fclose($socket);
        }
        exit($exit);
    }

    private function assertRejected(string $name, array $row): void
    {
        DB::statement('SAVEPOINT usage_constraint');
        try {
            DB::table('estimate_generation_ai_usage')->insert($row);
            self::fail($name.' was accepted.');
        } catch (QueryException) {
            DB::statement('ROLLBACK TO SAVEPOINT usage_constraint');
        } finally {
            DB::statement('RELEASE SAVEPOINT usage_constraint');
        }
    }

    private function assertMutationRejected(callable $mutation): void
    {
        DB::statement('SAVEPOINT usage_mutation');
        try {
            $mutation();
            self::fail('Immutable usage mutation was accepted.');
        } catch (QueryException) {
            DB::statement('ROLLBACK TO SAVEPOINT usage_mutation');
        } finally {
            DB::statement('RELEASE SAVEPOINT usage_mutation');
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

    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $user = User::factory()->create();
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $user->id, 'status' => 'draft', 'processing_stage' => 'draft', 'processing_progress' => 0,
            'input_payload' => [], 'state_version' => 0]);
        $document = EstimateGenerationDocument::query()->create(['session_id' => $session->id, 'organization_id' => $organization->id,
            'project_id' => $project->id, 'user_id' => $user->id, 'filename' => 'contract.pdf', 'mime_type' => 'application/pdf']);
        $page = EstimateGenerationDocumentPage::query()->create(['document_id' => $document->id, 'organization_id' => $organization->id,
            'project_id' => $project->id, 'session_id' => $session->id, 'page_number' => 1]);
        $unit = EstimateGenerationProcessingUnit::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'session_id' => $session->id, 'document_id' => $document->id, 'unit_type' => 'pdf_page', 'unit_index' => 1,
            'source_version' => 'contract-v1', 'status' => 'pending', 'locator' => [], 'metadata' => []]);

        return ['organization_id' => (int) $organization->id, 'project_id' => (int) $project->id, 'user_id' => (int) $user->id,
            'session_id' => (int) $session->id, 'document_id' => (int) $document->id, 'page_id' => (int) $page->id, 'unit_id' => (int) $unit->id];
    }

    private function row(array $fixture, string $attempt): array
    {
        return ['attempt_id' => $attempt, 'correlation_id' => (string) Str::uuid(), 'immutable_fingerprint' => 'sha256:'.str_repeat('a', 64),
            'organization_id' => $fixture['organization_id'], 'project_id' => $fixture['project_id'], 'session_id' => $fixture['session_id'],
            'document_id' => $fixture['document_id'], 'page_id' => $fixture['page_id'], 'unit_id' => $fixture['unit_id'],
            'stage' => 'understand_documents', 'operation' => 'ocr', 'attempt_ordinal' => 1, 'provider' => 'timeweb',
            'requested_model' => 'fixture-model', 'reported_model' => null, 'usage_status' => 'unavailable', 'status' => 'connection_failed',
            'http_code' => null, 'input_tokens' => 0, 'cached_input_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0,
            'image_count' => 0, 'image_detail' => null, 'page_count' => 0, 'duration_ms' => 1, 'price_snapshot' => '{}',
            'cost_amount' => null, 'currency' => null, 'pricing_status' => 'unavailable', 'created_at' => now()];
    }

    private function usage(array $fixture, string $attempt, int $durationMs): AiUsageData
    {
        return new AiUsageData(
            context: new AiOperationContext((string) Str::uuid(), $attempt, $fixture['organization_id'], $fixture['project_id'],
                $fixture['session_id'], 'understand_documents', 'ocr', 1, $fixture['document_id'], $fixture['page_id'], $fixture['unit_id']),
            provider: 'timeweb', requestedModel: 'fixture-model', status: 'connection_failed', durationMs: $durationMs,
        );
    }

    private function cleanup(array $fixture): void
    {
        if (($fixture['session_id'] ?? null) !== null) {
            DB::table('estimate_generation_sessions')->where('id', $fixture['session_id'])->delete();
        }
        DB::table('projects')->where('id', $fixture['project_id'])->delete();
        DB::table('organizations')->where('id', $fixture['organization_id'])->delete();
        DB::table('users')->where('id', $fixture['user_id'])->delete();
    }
}
