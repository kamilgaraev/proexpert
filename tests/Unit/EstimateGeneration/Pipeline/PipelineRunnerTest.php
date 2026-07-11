<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CheckpointClaim;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CheckpointClaimStatus;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineCheckpointStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineRegistry;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineRunner;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageResult;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class PipelineRunnerTest extends TestCase
{
    private InMemoryCheckpointStore $store;

    private MutableClock $clock;

    protected function setUp(): void
    {
        $this->store = new InMemoryCheckpointStore;
        $this->clock = new MutableClock(new DateTimeImmutable('2026-07-11T10:00:00+00:00'));
    }

    #[Test]
    public function completed_stage_is_not_executed_twice_for_same_input_version(): void
    {
        $stage = new CountingStage(ProcessingStage::UnderstandObject);
        $runner = $this->runner([$stage]);
        $context = $this->context();

        $runner->runNext($context);
        self::assertNull($runner->runNext($context));

        self::assertSame(1, $stage->executions);
        self::assertSame(1, $this->store->count());
    }

    #[Test]
    public function two_runners_cannot_execute_same_stage_during_active_lease(): void
    {
        $stage = new CountingStage(ProcessingStage::UnderstandObject);
        $context = $this->context();
        $claim = $this->store->claim(
            $context,
            $stage->stage(),
            $this->clock->now,
            $this->clock->now->modify('+5 minutes'),
        );

        self::assertSame(CheckpointClaimStatus::Acquired, $claim->status);
        self::assertNull($this->runner([$stage])->runNext($context));
        self::assertSame(0, $stage->executions);
    }

    #[Test]
    public function busy_prior_stage_blocks_later_stage(): void
    {
        $first = new CountingStage(ProcessingStage::UnderstandDocuments);
        $later = new CountingStage(ProcessingStage::UnderstandObject);
        $context = $this->context();
        $this->store->claim($context, $first->stage(), $this->clock->now, $this->clock->now->modify('+1 minute'));

        self::assertNull($this->runner([$first, $later])->runNext($context));
        self::assertSame(0, $later->executions);
    }

    #[Test]
    public function failed_stage_is_retried_with_incremented_attempt(): void
    {
        $stage = new CountingStage(ProcessingStage::UnderstandObject, failures: 1);
        $runner = $this->runner([$stage]);

        try {
            $runner->runNext($this->context());
            self::fail('First attempt must fail.');
        } catch (RuntimeException) {
        }

        $result = $runner->runNext($this->context());

        self::assertSame(2, $stage->executions);
        self::assertSame(2, $this->store->attempts($this->context(), $stage->stage()));
        self::assertSame($stage->stage(), $result?->stage);
    }

    #[Test]
    public function stale_claim_cannot_complete_after_expired_lease_is_reclaimed(): void
    {
        $context = $this->context();
        $stage = ProcessingStage::UnderstandObject;
        $old = $this->store->claim($context, $stage, $this->clock->now, $this->clock->now->modify('+1 second'));
        $this->clock->advance('+2 seconds');
        $fresh = $this->store->claim($context, $stage, $this->clock->now, $this->clock->now->modify('+1 minute'));

        self::assertSame(CheckpointClaimStatus::Acquired, $fresh->status);
        self::assertNotSame($old->claimToken, $fresh->claimToken);
        self::assertFalse($this->store->renewLease(
            $old,
            $this->clock->now,
            $this->clock->now->modify('+1 minute'),
        ));
        self::assertFalse($this->store->complete($old, $this->stageResult($stage), $this->clock->now));
        self::assertTrue($this->store->complete($fresh, $this->stageResult($stage), $this->clock->now));
    }

    #[Test]
    public function expired_unreclaimed_claim_cannot_complete_or_fail(): void
    {
        $context = $this->context();
        $claim = $this->store->claim(
            $context,
            ProcessingStage::UnderstandObject,
            $this->clock->now,
            $this->clock->now->modify('+1 second'),
        );
        $this->clock->advance('+2 seconds');

        self::assertFalse($this->store->complete(
            $claim,
            $this->stageResult(ProcessingStage::UnderstandObject),
            $this->clock->now,
        ));
        self::assertFalse($this->store->fail($claim, new RuntimeException('late'), $this->clock->now));
        self::assertSame('running', $this->store->status($context, ProcessingStage::UnderstandObject));
    }

    #[Test]
    public function current_owner_can_renew_only_an_unexpired_lease(): void
    {
        $context = $this->context();
        $claim = $this->store->claim(
            $context,
            ProcessingStage::UnderstandObject,
            $this->clock->now,
            $this->clock->now->modify('+2 seconds'),
        );

        self::assertTrue($this->store->renewLease(
            $claim,
            $this->clock->now,
            $this->clock->now->modify('+10 seconds'),
        ));
        $this->clock->advance('+11 seconds');
        self::assertFalse($this->store->renewLease(
            $claim,
            $this->clock->now,
            $this->clock->now->modify('+10 seconds'),
        ));
    }

    #[Test]
    public function acquired_claim_rejects_non_canonical_uuid_tokens(): void
    {
        $this->expectException(LogicException::class);

        CheckpointClaim::acquired(
            $this->context(),
            ProcessingStage::UnderstandObject,
            'prefix-550e8400-e29b-41d4-a716-446655440000',
        );
    }

    #[Test]
    public function acquired_claim_normalizes_uuid_and_copies_scalar_token(): void
    {
        $token = '550E8400-E29B-41D4-A716-446655440000';
        $claim = CheckpointClaim::acquired($this->context(), ProcessingStage::UnderstandObject, $token);
        $token = 'changed';

        self::assertSame('550e8400-e29b-41d4-a716-446655440000', $claim->claimToken);
        self::assertNotSame($token, $claim->claimToken);
    }

    #[Test]
    public function stage_result_mismatch_is_failed_and_rethrown(): void
    {
        $stage = new MismatchedStage;

        $this->expectException(LogicException::class);
        try {
            $this->runner([$stage])->runNext($this->context());
        } finally {
            self::assertSame('failed', $this->store->status($this->context(), $stage->stage()));
        }
    }

    #[Test]
    public function result_finishing_after_lease_expiry_is_never_published(): void
    {
        $stage = new AdvancingStage($this->clock, '+61 seconds');

        $this->expectException(LogicException::class);
        try {
            $this->runner([$stage])->runNext($this->context());
        } finally {
            self::assertSame('running', $this->store->status($this->context(), $stage->stage()));
        }
    }

    #[Test]
    public function exception_marks_owned_checkpoint_failed_and_is_rethrown(): void
    {
        $stage = new CountingStage(ProcessingStage::UnderstandObject, failures: 1);

        $this->expectException(RuntimeException::class);
        try {
            $this->runner([$stage])->runNext($this->context());
        } finally {
            self::assertSame('failed', $this->store->status($this->context(), $stage->stage()));
        }
    }

    #[Test]
    public function stages_progress_in_registry_order_and_all_complete_returns_null(): void
    {
        $later = new CountingStage(ProcessingStage::ExtractQuantities);
        $first = new CountingStage(ProcessingStage::UnderstandDocuments);
        $middle = new CountingStage(ProcessingStage::UnderstandObject);
        $runner = $this->runner([$later, $middle, $first]);
        $context = $this->context();

        self::assertSame($first->stage(), $runner->runNext($context)?->stage);
        self::assertSame($middle->stage(), $runner->runNext($context)?->stage);
        self::assertSame($later->stage(), $runner->runNext($context)?->stage);
        self::assertNull($runner->runNext($context));
    }

    #[Test]
    public function runner_does_not_mutate_context_state_version(): void
    {
        $context = $this->context(stateVersion: 17);
        $this->runner([new CountingStage(ProcessingStage::UnderstandObject)])->runNext($context);

        self::assertSame(17, $context->stateVersion);
    }

    /** @param list<PipelineStage> $stages */
    private function runner(array $stages): PipelineRunner
    {
        return new PipelineRunner(
            new PipelineRegistry($stages),
            $this->store,
            fn (): DateTimeImmutable => $this->clock->now,
            60,
        );
    }

    private function context(int $stateVersion = 3): PipelineContext
    {
        return new PipelineContext(10, 20, 30, $stateVersion, 'sha256:a');
    }

    private function stageResult(ProcessingStage $stage): PipelineStageResult
    {
        return new PipelineStageResult($stage, 'sha256:out', ['items' => 1], ['check']);
    }
}

final class MutableClock
{
    public function __construct(public DateTimeImmutable $now) {}

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}

final class CountingStage implements PipelineStage
{
    public int $executions = 0;

    public function __construct(
        private readonly ProcessingStage $processingStage,
        private int $failures = 0,
    ) {}

    public function stage(): ProcessingStage
    {
        return $this->processingStage;
    }

    public function execute(PipelineContext $context): PipelineStageResult
    {
        $this->executions++;
        if ($this->failures-- > 0) {
            throw new RuntimeException('stage failed');
        }

        return new PipelineStageResult($this->processingStage, 'sha256:out', ['attempt' => $this->executions]);
    }
}

final class MismatchedStage implements PipelineStage
{
    public function stage(): ProcessingStage
    {
        return ProcessingStage::UnderstandObject;
    }

    public function execute(PipelineContext $context): PipelineStageResult
    {
        return new PipelineStageResult(ProcessingStage::ExtractQuantities, 'sha256:wrong', []);
    }
}

final class AdvancingStage implements PipelineStage
{
    public function __construct(
        private readonly MutableClock $clock,
        private readonly string $modifier,
    ) {}

    public function stage(): ProcessingStage
    {
        return ProcessingStage::UnderstandObject;
    }

    public function execute(PipelineContext $context): PipelineStageResult
    {
        $this->clock->advance($this->modifier);

        return new PipelineStageResult($this->stage(), 'sha256:late', []);
    }
}

final class InMemoryCheckpointStore implements PipelineCheckpointStore
{
    /** @var array<string, array{status: string, token: ?string, expires: ?DateTimeImmutable, attempts: int}> */
    private array $items = [];

    private int $tokenSequence = 0;

    public function claim(
        PipelineContext $context,
        ProcessingStage $stage,
        DateTimeImmutable $now,
        DateTimeImmutable $leaseExpiresAt,
    ): CheckpointClaim {
        $key = $this->key($context, $stage);
        $item = $this->items[$key] ?? null;

        if ($item !== null && $item['status'] === 'completed') {
            return CheckpointClaim::alreadyCompleted($context, $stage);
        }

        if ($item !== null && $item['status'] === 'running' && $item['expires'] > $now) {
            return CheckpointClaim::busy($context, $stage);
        }

        $token = sprintf('00000000-0000-4000-8000-%012d', ++$this->tokenSequence);
        $this->items[$key] = [
            'status' => 'running',
            'token' => $token,
            'expires' => $leaseExpiresAt,
            'attempts' => ($item['attempts'] ?? 0) + 1,
        ];

        return CheckpointClaim::acquired($context, $stage, $token);
    }

    public function complete(CheckpointClaim $claim, PipelineStageResult $result, DateTimeImmutable $completedAt): bool
    {
        return $this->transition($claim, 'completed', $completedAt);
    }

    public function fail(CheckpointClaim $claim, Throwable $error, DateTimeImmutable $failedAt): bool
    {
        return $this->transition($claim, 'failed', $failedAt);
    }

    public function renewLease(
        CheckpointClaim $claim,
        DateTimeImmutable $now,
        DateTimeImmutable $newLeaseExpiresAt,
    ): bool {
        $key = $this->key($claim->context, $claim->stage);
        if (
            ($this->items[$key]['status'] ?? null) !== 'running'
            || ($this->items[$key]['token'] ?? null) !== $claim->claimToken
            || ($this->items[$key]['expires'] ?? null) <= $now
            || $newLeaseExpiresAt <= $now
        ) {
            return false;
        }

        $this->items[$key]['expires'] = $newLeaseExpiresAt;

        return true;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function attempts(PipelineContext $context, ProcessingStage $stage): int
    {
        return $this->items[$this->key($context, $stage)]['attempts'];
    }

    public function status(PipelineContext $context, ProcessingStage $stage): string
    {
        return $this->items[$this->key($context, $stage)]['status'];
    }

    private function transition(CheckpointClaim $claim, string $status, DateTimeImmutable $now): bool
    {
        $key = $this->key($claim->context, $claim->stage);
        if (
            ($this->items[$key]['status'] ?? null) !== 'running'
            || ($this->items[$key]['token'] ?? null) !== $claim->claimToken
            || ($this->items[$key]['expires'] ?? null) <= $now
        ) {
            return false;
        }

        $this->items[$key]['status'] = $status;
        $this->items[$key]['token'] = null;
        $this->items[$key]['expires'] = null;

        return true;
    }

    private function key(PipelineContext $context, ProcessingStage $stage): string
    {
        return $context->sessionId.'|'.$stage->value.'|'.$context->inputVersion;
    }
}
