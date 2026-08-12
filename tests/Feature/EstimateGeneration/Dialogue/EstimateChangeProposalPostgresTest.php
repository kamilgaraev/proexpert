<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Dialogue;

use App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\ApplyEstimateChangeProposal;
use App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\EstimateChangeProposal;
use App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\EstimateProposalMutationExecutor;
use App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\EstimateProposalVersionFence;
use App\BusinessModules\Addons\EstimateGeneration\Infrastructure\Dialogue\EstimateChangeProposalRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
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
        $migration = require database_path('migrations/2026_08_12_000002_create_estimate_change_proposals.php');
        if (Schema::hasTable('estimate_change_proposals')) {
            $migration->down();
        }
        $migration->up();
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
            $service = new ApplyEstimateChangeProposal($repository, $versions, $executor);

            $id = (string) \Illuminate\Support\Str::uuid();
            $repository->create($this->scopedProposal($id, $session, $actor, $versions->capture($session), now()->addMinutes(30)), []);
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
        $scope = random_int(100000, 900000);
        $repository->create($this->proposal($id, $scope, 'race-'.$id), []);
        $barrier = (string) (microtime(true) + 0.8);
        $script = base_path('tests/Runtime/race-estimate-change-proposal.php');
        $environment = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => (string) config('database.connections.pgsql.host'), 'DB_PORT' => (string) config('database.connections.pgsql.port'),
            'DB_DATABASE' => (string) config('database.connections.pgsql.database'), 'DB_USERNAME' => (string) config('database.connections.pgsql.username'),
            'DB_PASSWORD' => (string) config('database.connections.pgsql.password'),
        ];
        $apply = new Process([PHP_BINARY, $script, $id, 'applying', (string) $scope, $barrier], base_path(), $environment);
        $cancel = new Process([PHP_BINARY, $script, $id, 'cancelled', (string) $scope, $barrier], base_path(), $environment);
        $apply->start();
        $cancel->start();
        $apply->wait();
        $cancel->wait();

        self::assertTrue($apply->isSuccessful(), $apply->getErrorOutput());
        self::assertTrue($cancel->isSuccessful(), $cancel->getErrorOutput());
        $outcomes = array_values(array_unique([$apply->getOutput(), $cancel->getOutput()]));
        sort($outcomes);
        self::assertSame(['0', '1'], $outcomes);
        self::assertContains(DB::table('estimate_change_proposal_states')->where('proposal_id', $id)->value('status'), ['applying', 'cancelled']);
        self::assertSame(2, DB::table('estimate_change_proposal_transitions')->where('proposal_id', $id)->count());
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

        return $payload;
    }
}
