<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\EloquentFailureStore;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureNormalizer;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
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
final class EstimateGenerationFailureLedgerPostgresTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    #[Test]
    public function committed_winner_unblocks_duplicate_event_without_double_occurrence(): void
    {
        $this->requireEnvironment(true);
        $fixture = $this->fixture();
        $eventId = (string) Str::uuid();
        $firstName = 'failure_contention_a_'.strtolower(Str::random(8));
        $secondName = 'failure_contention_b_'.strtolower(Str::random(8));
        $first = $this->independentConnection($firstName);
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            throw new RuntimeException('Unable to create failure contention barrier.');
        }
        $pid = null;
        try {
            $failure = $this->failure($fixture, $eventId);
            $first->beginTransaction();
            (new EloquentFailureStore($first))->record($failure, new \DateTimeImmutable('2026-07-11T10:00:00+00:00'));
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Unable to fork failure contention recorder.');
            }
            if ($pid === 0) {
                fclose($sockets[0]);
                $exit = 0;
                try {
                    fwrite($sockets[1], "before_record\n");
                    (new EloquentFailureStore($this->independentConnection($secondName)))
                        ->record($failure, new \DateTimeImmutable('2026-07-11T10:00:01+00:00'));
                    fwrite($sockets[1], "recorded\n");
                } catch (Throwable $error) {
                    $exit = 1;
                    fwrite($sockets[1], json_encode(['error' => $error::class], JSON_THROW_ON_ERROR)."\n");
                }
                fclose($sockets[1]);
                exit($exit);
            }
            fclose($sockets[1]);
            self::assertSame("before_record\n", fgets($sockets[0]));
            self::assertFalse($this->readable($sockets[0], 0, 750_000));
            $first->commit();
            self::assertTrue($this->readable($sockets[0], 5, 0));
            self::assertSame("recorded\n", fgets($sockets[0]));
            pcntl_waitpid($pid, $status);
            $pid = null;
            self::assertSame(1, DB::table('estimate_generation_failures')->where('fingerprint', $failure->fingerprint)->value('occurrence_count'));
            self::assertSame(1, DB::table('estimate_generation_failure_events')->where('event_id', $eventId)->count());
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
            DB::disconnect($secondName);
            $this->cleanup($fixture);
        }
    }

    #[Test]
    public function postgres_enforces_tenant_privacy_collision_resolution_and_immutability(): void
    {
        $this->requireEnvironment(false);
        $fixture = $this->fixture();
        $store = new EloquentFailureStore(DB::connection());
        $first = $this->failure($fixture, (string) Str::uuid());
        DB::beginTransaction();
        try {
            $store->record($first, new \DateTimeImmutable('2026-07-11T10:00:00+00:00'));
            $collision = (new FailureNormalizer)->normalize(new RuntimeException('another private document'), new FailureContext(
                organizationId: $fixture['organization_id'], projectId: $fixture['project_id'], sessionId: $fixture['session_id'],
                stage: ProcessingStage::BuildDraft, operation: 'process_unit', attempt: 1,
                correlationId: $first->context->correlationId, eventId: $first->context->eventId,
                documentId: $fixture['document_id'], unitId: $fixture['unit_id'],
            ));
            try {
                $store->record($collision, new \DateTimeImmutable('2026-07-11T10:00:01+00:00'));
                self::fail('Event UUID collision with another semantic identity was accepted.');
            } catch (\App\BusinessModules\Addons\EstimateGeneration\Observability\FailureStoreInvariantViolation) {
            }
            $store->record($this->failure($fixture, (string) Str::uuid()), new \DateTimeImmutable('2026-07-11T10:01:00+00:00'));
            self::assertSame(2, DB::table('estimate_generation_failures')->where('fingerprint', $first->fingerprint)->value('occurrence_count'));
            self::assertTrue($store->resolve($first->context, $first->fingerprint, 'retry_succeeded', new \DateTimeImmutable('2026-07-11T10:02:00+00:00')));
            self::assertNotNull(DB::table('estimate_generation_failures')->where('fingerprint', $first->fingerprint)->value('resolved_at'));
            $store->record($this->failure($fixture, (string) Str::uuid()), new \DateTimeImmutable('2026-07-11T10:03:00+00:00'));
            self::assertNull(DB::table('estimate_generation_failures')->where('fingerprint', $first->fingerprint)->value('resolved_at'));
            self::assertSame(3, DB::table('estimate_generation_failures')->where('fingerprint', $first->fingerprint)->value('occurrence_count'));

            $identityId = DB::table('estimate_generation_failure_identities')->where('fingerprint', $first->fingerprint)->value('id');
            $eventSequence = DB::table('estimate_generation_failure_events')->where('fingerprint', $first->fingerprint)->where('event_type', 'occurred')->max('sequence');
            $this->assertRejected(fn () => DB::table('estimate_generation_failure_identities')->where('id', $identityId)->update(['code' => 'changed']));
            $this->assertRejected(fn () => DB::table('estimate_generation_failure_identities')->where('id', $identityId)->delete());
            $this->assertRejected(fn () => DB::table('estimate_generation_failure_events')->where('sequence', $eventSequence)->update(['attempt' => 2]));
            $this->assertRejected(fn () => DB::table('estimate_generation_failure_events')->where('sequence', $eventSequence)->delete());
            self::assertNull(DB::selectOne("SELECT current_setting('app.eg_failure_mutation', true) AS value")?->value);
            self::assertSame(0, DB::table('pg_proc as p')
                ->join('pg_namespace as n', 'n.oid', '=', 'p.pronamespace')
                ->where('n.nspname', 'public')
                ->whereIn('p.proname', ['prevent_estimate_generation_failure_history_mutation', 'validate_estimate_generation_failure_resolution'])
                ->whereRaw("EXISTS (SELECT 1 FROM aclexplode(p.proacl) acl WHERE acl.grantee = 0 AND acl.privilege_type = 'EXECUTE')")
                ->count());
            $functionConfigs = DB::table('pg_proc as p')->join('pg_namespace as n', 'n.oid', '=', 'p.pronamespace')
                ->where('n.nspname', 'public')
                ->whereIn('p.proname', ['prevent_estimate_generation_failure_history_mutation', 'validate_estimate_generation_failure_resolution'])
                ->pluck('p.proconfig');
            self::assertCount(2, $functionConfigs);
            foreach ($functionConfigs as $config) {
                self::assertStringContainsString('search_path=pg_catalog, public', (string) $config);
            }
            foreach ($this->invalidEventRows($fixture, (string) $identityId, $first->fingerprint, (int) $eventSequence) as $name => $row) {
                $this->assertRejected(fn () => DB::table('estimate_generation_failure_events')->insert($row), $name);
            }

            DB::table('estimate_generation_sessions')->where('id', $fixture['session_id'])->delete();
            self::assertSame(0, DB::table('estimate_generation_failures')->where('fingerprint', $first->fingerprint)->count());
            self::assertSame(0, DB::table('estimate_generation_failure_events')->where('fingerprint', $first->fingerprint)->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->cleanup($fixture);
        }
    }

    private function failure(array $fixture, string $eventId): \App\BusinessModules\Addons\EstimateGeneration\Observability\FailureData
    {
        return (new FailureNormalizer)->normalize(new RuntimeException('private document'), new FailureContext(
            organizationId: $fixture['organization_id'], projectId: $fixture['project_id'], sessionId: $fixture['session_id'],
            stage: ProcessingStage::UnderstandDocuments, operation: 'process_unit', attempt: 1,
            correlationId: $eventId, documentId: $fixture['document_id'], unitId: $fixture['unit_id'],
            eventId: $eventId,
        ));
    }

    /** @return array<string, array<string, mixed>> */
    private function invalidEventRows(array $fixture, string $failureId, string $fingerprint, int $occurredSequence): array
    {
        $base = [
            'event_id' => (string) Str::uuid(), 'correlation_id' => (string) Str::uuid(),
            'failure_id' => $failureId, 'fingerprint' => $fingerprint,
            'organization_id' => $fixture['organization_id'], 'project_id' => $fixture['project_id'],
            'session_id' => $fixture['session_id'], 'event_type' => 'occurred', 'attempt' => 1,
            'safe_context' => '{}', 'resolution_code' => null, 'resolves_through_sequence' => null,
            'recorded_at' => now(),
        ];

        return [
            'unknown event type' => [...$base, 'event_id' => (string) Str::uuid(), 'event_type' => 'unknown'],
            'zero attempt' => [...$base, 'event_id' => (string) Str::uuid(), 'attempt' => 0],
            'tenant mismatch' => [...$base, 'event_id' => (string) Str::uuid(), 'organization_id' => $fixture['organization_id'] + 999999],
            'fingerprint mismatch' => [...$base, 'event_id' => (string) Str::uuid(), 'fingerprint' => 'sha256:'.str_repeat('b', 64)],
            'unsafe prompt' => [...$base, 'event_id' => (string) Str::uuid(), 'safe_context' => json_encode(['provider_code' => 'prompt'], JSON_THROW_ON_ERROR)],
            'invalid http code' => [...$base, 'event_id' => (string) Str::uuid(), 'safe_context' => json_encode(['http_code' => 700], JSON_THROW_ON_ERROR)],
            'unknown context key' => [...$base, 'event_id' => (string) Str::uuid(), 'safe_context' => json_encode(['unknown' => 'safe'], JSON_THROW_ON_ERROR)],
            'occurred with resolution' => [...$base, 'event_id' => (string) Str::uuid(), 'resolution_code' => 'retry_succeeded'],
            'resolved without target' => [...$base, 'event_id' => (string) Str::uuid(), 'event_type' => 'resolved', 'resolution_code' => 'retry_succeeded'],
            'resolved future target' => [...$base, 'event_id' => (string) Str::uuid(), 'event_type' => 'resolved', 'resolution_code' => 'retry_succeeded', 'resolves_through_sequence' => $occurredSequence + 999999],
            'nil event uuid' => [...$base, 'event_id' => '00000000-0000-0000-0000-000000000000'],
            'nil correlation uuid' => [...$base, 'event_id' => (string) Str::uuid(), 'correlation_id' => '00000000-0000-0000-0000-000000000000'],
        ];
    }

    private function assertRejected(callable $operation, string $name = 'mutation'): void
    {
        DB::statement('SAVEPOINT failure_contract');
        try {
            $operation();
            self::fail('Invalid failure ledger '.$name.' was accepted.');
        } catch (QueryException) {
            DB::statement('ROLLBACK TO SAVEPOINT failure_contract');
        } finally {
            DB::statement('RELEASE SAVEPOINT failure_contract');
        }
    }

    private function fixture(): array
    {
        $fixture = ['organization_id' => null, 'project_id' => null, 'user_id' => null, 'session_id' => null, 'document_id' => null, 'unit_id' => null];
        try {
            $organization = Organization::factory()->create();
            $fixture['organization_id'] = (int) $organization->id;
            $project = Project::factory()->for($organization)->create();
            $fixture['project_id'] = (int) $project->id;
            $user = User::factory()->create();
            $fixture['user_id'] = (int) $user->id;
            $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
                'user_id' => $user->id, 'status' => 'processing_documents', 'processing_stage' => 'processing_documents',
                'processing_progress' => 10, 'input_payload' => [], 'state_version' => 1]);
            $fixture['session_id'] = (int) $session->id;
            $document = EstimateGenerationDocument::query()->create(['session_id' => $session->id, 'organization_id' => $organization->id,
                'project_id' => $project->id, 'user_id' => $user->id, 'filename' => 'contract.pdf', 'mime_type' => 'application/pdf']);
            $fixture['document_id'] = (int) $document->id;
            $unit = EstimateGenerationProcessingUnit::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
                'session_id' => $session->id, 'document_id' => $document->id, 'unit_type' => 'pdf_page', 'unit_index' => 1,
                'source_version' => 'contract-v1', 'status' => 'pending', 'locator' => [], 'metadata' => []]);
            $fixture['unit_id'] = (int) $unit->id;

            return $fixture;
        } catch (Throwable $error) {
            $this->cleanup($fixture);
            throw $error;
        }
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
}
