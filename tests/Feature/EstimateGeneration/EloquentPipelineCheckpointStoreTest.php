<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPipelineCheckpoint;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CheckpointClaimStatus;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\EloquentPipelineCheckpointStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageResult;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('database')]
final class EloquentPipelineCheckpointStoreTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function only_current_owner_can_complete_reclaimed_checkpoint(): void
    {
        [$store, $context] = $this->storeAndContext();
        $started = new DateTimeImmutable('2026-07-11T10:00:00+00:00');
        $old = $store->claim($context, ProcessingStage::UnderstandObject, $started, $started->modify('+1 second'));
        $fresh = $store->claim(
            $context,
            ProcessingStage::UnderstandObject,
            $started->modify('+2 seconds'),
            $started->modify('+1 minute'),
        );
        $result = new PipelineStageResult(ProcessingStage::UnderstandObject, 'sha256:output', []);

        self::assertSame(CheckpointClaimStatus::Acquired, $fresh->status);
        self::assertNotSame($old->claimToken, $fresh->claimToken);
        self::assertFalse($store->complete($old, $result, $started->modify('+3 seconds')));
        self::assertFalse($store->fail($old, new \RuntimeException('stale'), $started->modify('+3 seconds')));
        self::assertTrue($store->complete($fresh, $result, $started->modify('+3 seconds')));
        self::assertSame(1, EstimateGenerationPipelineCheckpoint::query()->count());
    }

    #[Test]
    public function contending_unique_claims_use_same_connection_and_only_one_is_acquired(): void
    {
        [$firstStore, $context] = $this->storeAndContext();
        $secondStore = new EloquentPipelineCheckpointStore(DB::connection());
        $now = new DateTimeImmutable('2026-07-11T10:00:00+00:00');

        $first = $firstStore->claim($context, ProcessingStage::UnderstandObject, $now, $now->modify('+1 minute'));
        $second = $secondStore->claim($context, ProcessingStage::UnderstandObject, $now, $now->modify('+1 minute'));
        $checkpoint = EstimateGenerationPipelineCheckpoint::query()->sole();

        self::assertSame(CheckpointClaimStatus::Acquired, $first->status);
        self::assertSame(CheckpointClaimStatus::Busy, $second->status);
        self::assertSame(DB::connection()->getName(), $checkpoint->getConnectionName());
    }

    #[Test]
    public function expired_unreclaimed_owner_cannot_complete_fail_or_renew(): void
    {
        [$store, $context] = $this->storeAndContext();
        $now = new DateTimeImmutable('2026-07-11T10:00:00+00:00');
        $claim = $store->claim($context, ProcessingStage::UnderstandObject, $now, $now->modify('+1 second'));
        $expired = $now->modify('+2 seconds');
        $result = new PipelineStageResult(ProcessingStage::UnderstandObject, 'sha256:output', []);

        self::assertFalse($store->complete($claim, $result, $expired));
        self::assertFalse($store->fail($claim, new \RuntimeException('expired'), $expired));
        self::assertFalse($store->renewLease($claim, $expired, $expired->modify('+1 minute')));
    }

    #[Test]
    public function failed_checkpoint_is_reclaimed_as_next_attempt(): void
    {
        [$store, $context] = $this->storeAndContext();
        $now = new DateTimeImmutable('2026-07-11T10:00:00+00:00');
        $failed = $store->claim($context, ProcessingStage::UnderstandObject, $now, $now->modify('+1 minute'));
        self::assertTrue($store->fail($failed, new \RuntimeException('failed'), $now->modify('+1 second')));

        $retry = $store->claim(
            $context,
            ProcessingStage::UnderstandObject,
            $now->modify('+2 seconds'),
            $now->modify('+1 minute'),
        );

        self::assertSame(CheckpointClaimStatus::Acquired, $retry->status);
        self::assertNotSame($failed->claimToken, $retry->claimToken);
        self::assertSame(2, EstimateGenerationPipelineCheckpoint::query()->sole()->attempt_count);
    }

    #[Test]
    #[DataProvider('invalidCheckpointStates')]
    public function database_rejects_invalid_checkpoint_states(array $invalidState): void
    {
        [, $context] = $this->storeAndContext();
        $now = new DateTimeImmutable('2026-07-11T10:00:00+00:00');
        $this->expectException(QueryException::class);

        DB::table('estimate_generation_pipeline_checkpoints')->insert([
            'session_id' => $context->sessionId,
            'stage' => ProcessingStage::UnderstandObject->value,
            'input_version' => 'sha256:constraint',
            'status' => 'running',
            'metrics' => '{}',
            'warnings' => '[]',
            'attempt_count' => 1,
            'claim_token' => '550e8400-e29b-41d4-a716-446655440000',
            'lease_expires_at' => $now->modify('+1 minute'),
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
            ...$invalidState,
        ]);
    }

    public static function invalidCheckpointStates(): array
    {
        return [
            'zero attempt' => [['attempt_count' => 0]],
            'unknown stage' => [['stage' => 'unknown']],
            'metrics list' => [['metrics' => '[]']],
            'warnings object' => [['warnings' => '{}']],
            'running without token' => [['claim_token' => null]],
            'completed without output' => [[
                'status' => 'completed', 'claim_token' => null, 'lease_expires_at' => null,
                'completed_at' => new DateTimeImmutable('2026-07-11T10:00:01+00:00'),
            ]],
            'failed without fingerprint' => [[
                'status' => 'failed', 'claim_token' => null, 'lease_expires_at' => null,
                'failed_at' => new DateTimeImmutable('2026-07-11T10:00:01+00:00'),
                'last_error_code' => 'pipeline_stage_failed',
            ]],
        ];
    }

    /** @return array{EloquentPipelineCheckpointStore, PipelineContext} */
    private function storeAndContext(): array
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
        $context = new PipelineContext($session->id, $organization->id, $project->id, 0, 'sha256:input');

        return [new EloquentPipelineCheckpointStore(DB::connection()), $context];
    }
}
