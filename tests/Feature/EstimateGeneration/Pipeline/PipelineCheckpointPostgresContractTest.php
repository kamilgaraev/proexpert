<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Pipeline;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('postgres-contract')]
final class PipelineCheckpointPostgresContractTest extends TestCase
{
    private bool $transactionStarted = false;

    protected function setUp(): void
    {
        if (getenv('RUN_ESTIMATE_GENERATION_POSTGRES_CONTRACT') !== '1') {
            $this->markTestSkipped('Requires an explicit isolated PostgreSQL contract environment.');
        }
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL.');
        }
        DB::beginTransaction();
        $this->transactionStarted = true;
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted && DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_completed_checkpoint_is_immutable_and_aggregate_budget_is_enforced(): void
    {
        $scope = [
            'organization_id' => (int) getenv('EG_TEST_ORGANIZATION_ID'),
            'project_id' => (int) getenv('EG_TEST_PROJECT_ID'),
            'session_id' => (int) getenv('EG_TEST_SESSION_ID'),
        ];
        $attempt = '018f4a20-3f4c-7a11-8a22-'.bin2hex(random_bytes(6));
        $first = $this->completed($scope, $attempt, 'understand_documents', 5_000_000, 'a');
        $id = (int) DB::table('estimate_generation_pipeline_checkpoints')->insertGetId($first);

        $this->assertRejected(function () use ($id): void {
            DB::table('estimate_generation_pipeline_checkpoints')->where('id', $id)->update(['artifact_bytes' => 1]);
        }, 'checkpoint_is_immutable');
        $this->assertRejected(function () use ($id): void {
            DB::table('estimate_generation_pipeline_checkpoints')->where('id', $id)->delete();
        }, 'checkpoint_delete_forbidden');
        $this->assertRejected(function () use ($scope, $attempt): void {
            DB::table('estimate_generation_pipeline_checkpoints')->insert(
                $this->completed($scope, $attempt, 'understand_object', 5_000_000, 'b'),
            );
        }, 'pipeline_artifact_budget_exceeded');

        foreach ([
            'metrics' => json_encode(['changed' => true], JSON_THROW_ON_ERROR),
            'warnings' => json_encode(['changed'], JSON_THROW_ON_ERROR),
            'attempt_count' => 2,
            'input_version' => 'sha256:'.hash('sha256', 'changed-input'),
            'dependency_versions' => json_encode(['changed' => 'sha256:'.hash('sha256', 'dependency')], JSON_THROW_ON_ERROR),
            'output_version' => 'sha256:'.hash('sha256', 'changed-output'),
            'output_payload' => json_encode(['changed' => true], JSON_THROW_ON_ERROR),
            'started_at' => now()->subMinute(),
            'completed_at' => now()->addMinute(),
        ] as $column => $value) {
            $this->assertRejected(function () use ($id, $column, $value): void {
                DB::table('estimate_generation_pipeline_checkpoints')->where('id', $id)->update([$column => $value]);
            }, 'checkpoint_is_immutable');
        }

        self::assertSame(1, DB::table('estimate_generation_pipeline_checkpoints')->where('id', $id)->update([
            'status' => 'invalidated',
            'invalidated_at' => now(),
            'invalidation_reason' => 'dependency_changed',
            'updated_at' => now(),
        ]));
        $this->assertRejected(function () use ($id): void {
            DB::table('estimate_generation_pipeline_checkpoints')->where('id', $id)->update(['metrics' => '{"changed":true}']);
        }, 'checkpoint_is_immutable');
    }

    public function test_delivery_receipt_allows_one_exact_transition_rejects_mutation_and_cascades_with_parent(): void
    {
        $scope = [
            'organization_id' => (int) getenv('EG_TEST_ORGANIZATION_ID'),
            'project_id' => (int) getenv('EG_TEST_PROJECT_ID'),
            'session_id' => (int) getenv('EG_TEST_SESSION_ID'),
        ];
        $attempt = '018f4a20-3f4c-7a11-8a22-'.bin2hex(random_bytes(6));
        $recipient = (int) DB::table('estimate_generation_sessions')->where('id', $scope['session_id'])->value('user_id');
        $id = (int) DB::table('estimate_generation_finalization_deliveries')->insertGetId([
            ...$scope, 'generation_attempt_id' => $attempt, 'event_type' => 'estimate_generation_completed',
            'recipient_id' => $recipient, 'business_key' => hash('sha256', $attempt), 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertRejected(function () use ($id): void {
            DB::table('estimate_generation_finalization_deliveries')->where('id', $id)->delete();
        }, 'finalization_delivery_delete_forbidden');
        $this->assertRejected(function () use ($id): void {
            DB::table('estimate_generation_finalization_deliveries')->where('id', $id)->update(['recipient_id' => -1]);
        }, 'finalization_delivery_is_immutable');
        self::assertSame(1, DB::table('estimate_generation_finalization_deliveries')->where('id', $id)->update([
            'status' => 'delivered', 'notification_id' => '018f4a20-3f4c-7a11-8a22-123456789abc',
            'delivered_at' => now(), 'updated_at' => now(),
        ]));
        $this->assertRejected(function () use ($id): void {
            DB::table('estimate_generation_finalization_deliveries')->where('id', $id)->update(['notification_id' => null]);
        }, 'finalization_delivery_is_immutable');

        $parent = DB::table('estimate_generation_sessions')->insertGetId([
            'organization_id' => $scope['organization_id'], 'project_id' => $scope['project_id'], 'user_id' => $recipient,
            'status' => 'draft', 'processing_stage' => 'draft', 'processing_progress' => 0,
            'input_payload' => '{}', 'problem_flags' => '[]', 'state_version' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cascadeAttempt = '018f4a20-3f4c-7a11-8a22-'.bin2hex(random_bytes(6));
        DB::table('estimate_generation_finalization_outbox')->insert([
            'organization_id' => $scope['organization_id'], 'project_id' => $scope['project_id'], 'session_id' => $parent,
            'generation_attempt_id' => $cascadeAttempt, 'event_type' => 'estimate_generation_completed',
            'idempotency_key' => hash('sha256', 'outbox'.$cascadeAttempt), 'status' => 'pending', 'attempt_count' => 0,
            'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('estimate_generation_finalization_deliveries')->insert([
            'organization_id' => $scope['organization_id'], 'project_id' => $scope['project_id'], 'session_id' => $parent,
            'generation_attempt_id' => $cascadeAttempt, 'event_type' => 'estimate_generation_completed',
            'recipient_id' => $recipient, 'business_key' => hash('sha256', 'receipt'.$cascadeAttempt), 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('estimate_generation_sessions')->where('id', $parent)->delete();
        self::assertSame(0, DB::table('estimate_generation_finalization_outbox')->where('session_id', $parent)->count());
        self::assertSame(0, DB::table('estimate_generation_finalization_deliveries')->where('session_id', $parent)->count());
    }

    /** @param array{organization_id:int,project_id:int,session_id:int} $scope */
    private function completed(array $scope, string $attempt, string $stage, int $bytes, string $salt): array
    {
        $version = 'sha256:'.hash('sha256', $salt);
        $now = now();

        return [
            ...$scope, 'generation_attempt_id' => $attempt, 'base_input_version' => $version,
            'stage' => $stage, 'input_version' => $version, 'dependency_versions' => '{}',
            'output_version' => $version, 'output_payload' => json_encode(['stage' => $stage], JSON_THROW_ON_ERROR),
            'artifact_bytes' => $bytes, 'status' => 'completed', 'metrics' => '{}', 'warnings' => '[]',
            'attempt_count' => 1, 'started_at' => $now, 'completed_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ];
    }

    private function assertRejected(callable $mutation, string $message): void
    {
        DB::statement('SAVEPOINT pipeline_contract');
        try {
            $mutation();
            self::fail('PostgreSQL accepted an invalid pipeline mutation.');
        } catch (QueryException $error) {
            self::assertStringContainsString($message, $error->getMessage());
        } finally {
            DB::statement('ROLLBACK TO SAVEPOINT pipeline_contract');
            DB::statement('RELEASE SAVEPOINT pipeline_contract');
        }
    }
}
