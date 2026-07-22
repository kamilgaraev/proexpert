<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Application\TargetedRebuild;

use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\RunTargetedPackageRebuildOperation;
use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackagePatchResult;
use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageRebuildCommand;
use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageRebuildOperationData;
use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageRebuildOperationStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageRebuildOperationStoreResult;
use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageRebuildSessionSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageRebuildSessionSource;
use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageRebuildExecutor;
use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageRebuildCommitter;
use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageCommitResult;
use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageDraftPatcher;
use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageEvidenceRequired;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter\ArbiterOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter\ArbiterVerdict;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter\TargetedPackageRebuildReviewer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RunTargetedPackageRebuildOperationTest extends TestCase
{
    #[Test]
    public function it_exposes_a_dedicated_safe_targeted_rebuild_executor(): void
    {
        self::assertTrue(class_exists(
            \App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\RunTargetedPackageRebuildOperation::class,
        ));
    }

    #[Test]
    public function it_marks_a_changed_source_as_stale_before_the_rebuilder_or_arbiter_are_called(): void
    {
        $operation = $this->queued();
        $operations = new InMemoryRunTargetedPackageOperationStore($operation);
        $rebuilds = new RecordingTargetedPackageRebuildExecutor;
        $reviews = new RecordingTargetedPackageRebuildReviewer;
        $commits = new RecordingTargetedPackageRebuildCommitter;
        $handler = $this->handler(
            $operations,
            new InMemoryTargetedPackageRebuildSessionSource($this->snapshot(stateVersion: 9)),
            $rebuilds,
            $reviews,
            $commits,
        );

        $handler->handle($operation->operationId);

        self::assertSame('stale', $operations->operation->status);
        self::assertSame([], $rebuilds->commands);
        self::assertSame([], $reviews->contexts);
        self::assertSame([], $commits->commands);
    }

    #[Test]
    public function it_cancels_an_inactive_contour_before_the_rebuilder_or_arbiter_are_called(): void
    {
        $operation = $this->queued();
        $operations = new InMemoryRunTargetedPackageOperationStore($operation);
        $rebuilds = new RecordingTargetedPackageRebuildExecutor;
        $reviews = new RecordingTargetedPackageRebuildReviewer;
        $commits = new RecordingTargetedPackageRebuildCommitter;
        $handler = $this->handler(
            $operations,
            new InMemoryTargetedPackageRebuildSessionSource($this->snapshot()),
            $rebuilds,
            $reviews,
            $commits,
            false,
        );

        $handler->handle($operation->operationId);

        self::assertSame('cancelled', $operations->operation->status);
        self::assertSame([], $rebuilds->commands);
        self::assertSame([], $reviews->contexts);
        self::assertSame([], $commits->commands);
    }

    #[Test]
    public function it_cancels_a_cancelled_session_before_the_rebuilder_or_arbiter_are_called(): void
    {
        $operation = $this->queued();
        $operations = new InMemoryRunTargetedPackageOperationStore($operation);
        $rebuilds = new RecordingTargetedPackageRebuildExecutor;
        $reviews = new RecordingTargetedPackageRebuildReviewer;
        $commits = new RecordingTargetedPackageRebuildCommitter;
        $handler = $this->handler(
            $operations,
            new InMemoryTargetedPackageRebuildSessionSource($this->snapshot(status: 'cancelled')),
            $rebuilds,
            $reviews,
            $commits,
        );

        $handler->handle($operation->operationId);

        self::assertSame('cancelled', $operations->operation->status);
        self::assertSame([], $rebuilds->commands);
        self::assertSame([], $reviews->contexts);
        self::assertSame([], $commits->commands);
    }

    #[Test]
    public function it_never_reclaims_an_already_running_operation(): void
    {
        $operation = $this->queued()->withLease(
            '018f809a-e85e-7382-b419-00f5a7d7ab59',
            new \DateTimeImmutable('2026-07-22T12:30:00+00:00'),
        );
        $operations = new InMemoryRunTargetedPackageOperationStore($operation);
        $rebuilds = new RecordingTargetedPackageRebuildExecutor;
        $reviews = new RecordingTargetedPackageRebuildReviewer;
        $commits = new RecordingTargetedPackageRebuildCommitter;
        $handler = $this->handler(
            $operations,
            new InMemoryTargetedPackageRebuildSessionSource($this->snapshot()),
            $rebuilds,
            $reviews,
            $commits,
        );

        $handler->handle($operation->operationId);

        self::assertSame('running', $operations->operation->status);
        self::assertSame(1, $operations->operation->attemptCount);
        self::assertSame([], $rebuilds->commands);
        self::assertSame([], $reviews->contexts);
        self::assertSame([], $commits->commands);
    }

    #[Test]
    public function it_runs_one_attempt_with_the_stable_operation_context_then_commits_only_the_recovered_delta(): void
    {
        $operation = $this->queued();
        $operations = new InMemoryRunTargetedPackageOperationStore($operation);
        $rebuilds = new RecordingTargetedPackageRebuildExecutor;
        $reviews = new RecordingTargetedPackageRebuildReviewer;
        $commits = new RecordingTargetedPackageRebuildCommitter;
        $handler = $this->handler(
            $operations,
            new InMemoryTargetedPackageRebuildSessionSource($this->snapshot()),
            $rebuilds,
            $reviews,
            $commits,
        );

        $handler->handle($operation->operationId);
        $handler->handle($operation->operationId);

        self::assertSame('committed', $operations->operation->status);
        self::assertCount(1, $rebuilds->commands);
        self::assertCount(1, $reviews->contexts);
        self::assertSame($operation->operationId, $reviews->contexts[0]->checkpointClaimToken);
        self::assertSame($operation->sourceInputVersion, $reviews->contexts[0]->inputVersion);
        self::assertSame(1, $reviews->contexts[0]->attemptOrdinal);
        self::assertCount(1, $commits->commands);
        self::assertTrue($rebuilds->commands[0]->draft['arbiter_review']['remediation']['rebuild_attempted']);
    }

    #[Test]
    public function it_recovers_a_reviewed_delta_without_a_second_arbiter_wire_call(): void
    {
        $operation = $this->reviewed();
        $operations = new InMemoryRunTargetedPackageOperationStore($operation);
        $rebuilds = new RecordingTargetedPackageRebuildExecutor;
        $reviews = new RecordingTargetedPackageRebuildReviewer;
        $commits = new RecordingTargetedPackageRebuildCommitter;
        $handler = $this->handler(
            $operations,
            new InMemoryTargetedPackageRebuildSessionSource($this->snapshot()),
            $rebuilds,
            $reviews,
            $commits,
        );

        $handler->handle($operation->operationId);

        self::assertSame('committed', $operations->operation->status);
        self::assertSame([], $rebuilds->commands);
        self::assertSame([], $reviews->contexts);
        self::assertCount(1, $commits->commands);
        self::assertSame('reviewed', $commits->reviewedDrafts[0]['arbiter_review']['remediation']['phase']);
    }

    #[Test]
    public function it_routes_an_evidence_failure_to_human_review_without_writing_a_package(): void
    {
        $operation = $this->queued();
        $operations = new InMemoryRunTargetedPackageOperationStore($operation);
        $rebuilds = new RecordingTargetedPackageRebuildExecutor(throwEvidenceRequired: true);
        $reviews = new RecordingTargetedPackageRebuildReviewer;
        $commits = new RecordingTargetedPackageRebuildCommitter;
        $handler = $this->handler(
            $operations,
            new InMemoryTargetedPackageRebuildSessionSource($this->snapshot()),
            $rebuilds,
            $reviews,
            $commits,
        );

        $handler->handle($operation->operationId);

        self::assertSame('human_review', $operations->operation->status);
        self::assertSame([], $reviews->contexts);
        self::assertSame([], $commits->commands);
    }

    #[Test]
    public function it_routes_an_unavailable_arbiter_to_human_review_without_writing_a_package(): void
    {
        $operation = $this->queued();
        $operations = new InMemoryRunTargetedPackageOperationStore($operation);
        $rebuilds = new RecordingTargetedPackageRebuildExecutor;
        $reviews = new RecordingTargetedPackageRebuildReviewer(unavailable: true);
        $commits = new RecordingTargetedPackageRebuildCommitter;
        $handler = $this->handler(
            $operations,
            new InMemoryTargetedPackageRebuildSessionSource($this->snapshot()),
            $rebuilds,
            $reviews,
            $commits,
        );

        $handler->handle($operation->operationId);

        self::assertSame('human_review', $operations->operation->status);
        self::assertCount(1, $reviews->contexts);
        self::assertSame([], $commits->commands);
    }

    private function handler(
        TargetedPackageRebuildOperationStore $operations,
        TargetedPackageRebuildSessionSource $sessions,
        TargetedPackageRebuildExecutor $rebuilds,
        TargetedPackageRebuildReviewer $reviews,
        TargetedPackageRebuildCommitter $commits,
        bool $active = true,
    ): RunTargetedPackageRebuildOperation {
        return new RunTargetedPackageRebuildOperation(
            $operations,
            $sessions,
            $rebuilds,
            $reviews,
            $commits,
            $active,
            static fn (): string => '018f809a-e85e-7382-b419-00f5a7d7ab59',
            static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-07-22T12:30:00+00:00'),
        );
    }

    private function queued(): TargetedPackageRebuildOperationData
    {
        return TargetedPackageRebuildOperationData::queued(
            operationId: '018f809a-e85e-7382-b419-00f5a7d7ab59',
            idempotencyKey: hash('sha256', 'targeted-rebuild'),
            organizationId: 4,
            projectId: 7,
            sessionId: 11,
            expectedStateVersion: 8,
            sourceInputVersion: 'sha256:'.str_repeat('a', 64),
            rootInputHash: 'sha256:'.str_repeat('b', 64),
            sourceDraftFingerprint: 'sha256:'.hash('sha256', \App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode($this->draft())),
            packageKey: 'roof',
        );
    }

    private function reviewed(): TargetedPackageRebuildOperationData
    {
        $queued = $this->queued();
        $attempted = (new \App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter\ArbiterRemediationCoordinator)
            ->markAttempted($this->draft(), $queued->rootInputHash);
        $patch = (new TargetedPackageDraftPatcher)->replace(
            $attempted,
            $queued->sourceInputVersion,
            $queued->packageKey,
            ['key' => 'roof'],
        );

        return $queued
            ->withLease('018f809a-e85e-7382-b419-00f5a7d7ab59', new \DateTimeImmutable('2026-07-22T12:30:00+00:00'))
            ->withReviewed(
                [
                    'target_package' => ['key' => 'roof'],
                    'target_before_fingerprint' => $patch->targetBeforeFingerprint,
                    'target_after_fingerprint' => $patch->targetAfterFingerprint,
                    'non_target_fingerprints' => $patch->nonTargetFingerprints,
                ],
                $this->review('passed', 'reviewed'),
            );
    }

    private function snapshot(int $stateVersion = 8, string $status = 'ready_to_apply'): TargetedPackageRebuildSessionSnapshot
    {
        return new TargetedPackageRebuildSessionSnapshot(
            organizationId: 4,
            projectId: 7,
            sessionId: 11,
            stateVersion: $stateVersion,
            status: $status,
            appliedEstimateId: null,
            draft: $this->draft(),
        );
    }

    /** @return array<string, mixed> */
    private function draft(): array
    {
        return [
            'source_input_version' => 'sha256:'.str_repeat('a', 64),
            'local_estimates' => [
                ['key' => 'roof'],
                ['key' => 'walls'],
            ],
            'arbiter_review' => [
                'mode' => 'shadow',
                'status' => 'reviewed',
                'outcome' => 'targeted_rebuild',
                'input_hash' => 'sha256:'.str_repeat('b', 64),
                'schema_version' => 'completeness-arbiter:v1',
                'prompt_version' => 'completeness-arbiter:v1',
                'model' => 'openai/gpt-5-mini',
                'findings' => [[
                    'scope_key' => 'roof',
                    'package_keys' => ['roof'],
                    'evidence_refs' => ['evidence:roof'],
                    'action' => 'rebuild',
                    'reason_code' => 'missing_component',
                ]],
                'cycle' => [
                    'input_hash' => 'sha256:'.str_repeat('b', 64),
                    'attempted' => false,
                    'target_package_keys' => ['roof'],
                    'status' => 'shadow_recommendation',
                    'terminal_outcome' => 'targeted_rebuild',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function review(string $outcome, string $phase): array
    {
        return [
            'mode' => 'shadow',
            'status' => 'reviewed',
            'outcome' => $outcome,
            'input_hash' => 'sha256:'.str_repeat('b', 64),
            'schema_version' => 'completeness-arbiter:v1',
            'prompt_version' => 'completeness-arbiter:v1',
            'model' => 'openai/gpt-5-mini',
            'findings' => [],
            'cycle' => [
                'input_hash' => 'sha256:'.str_repeat('b', 64),
                'attempted' => false,
                'target_package_keys' => ['roof'],
                'status' => 'shadow_recommendation',
                'terminal_outcome' => 'targeted_rebuild',
            ],
            'remediation' => [
                'root_input_hash' => 'sha256:'.str_repeat('b', 64),
                'target_package_keys' => ['roof'],
                'rebuild_attempted' => true,
                'phase' => $phase,
                'review_outcome' => $outcome,
            ],
        ];
    }
}

final class InMemoryRunTargetedPackageOperationStore implements TargetedPackageRebuildOperationStore
{
    public function __construct(public TargetedPackageRebuildOperationData $operation) {}

    public function createOrFind(TargetedPackageRebuildOperationData $operation): TargetedPackageRebuildOperationStoreResult
    {
        return new TargetedPackageRebuildOperationStoreResult($this->operation, false);
    }

    public function find(string $operationId): ?TargetedPackageRebuildOperationData
    {
        return $this->operation->operationId === $operationId ? $this->operation : null;
    }

    public function claimQueued(string $operationId, string $leaseToken, \DateTimeImmutable $leaseExpiresAt): ?TargetedPackageRebuildOperationData
    {
        if ($this->operation->operationId !== $operationId || $this->operation->status !== 'queued') {
            return null;
        }

        return $this->operation = $this->operation->withLease($leaseToken, $leaseExpiresAt);
    }

    public function save(TargetedPackageRebuildOperationData $operation): void
    {
        $this->operation = $operation;
    }
}

final class InMemoryTargetedPackageRebuildSessionSource implements TargetedPackageRebuildSessionSource
{
    public function __construct(private TargetedPackageRebuildSessionSnapshot $snapshot) {}

    public function find(TargetedPackageRebuildOperationData $operation): ?TargetedPackageRebuildSessionSnapshot
    {
        return $this->snapshot;
    }
}

final class RecordingTargetedPackageRebuildExecutor implements TargetedPackageRebuildExecutor
{
    /** @var list<TargetedPackageRebuildCommand> */
    public array $commands = [];

    public function __construct(private bool $throwEvidenceRequired = false) {}

    public function rebuild(TargetedPackageRebuildCommand $command): TargetedPackagePatchResult
    {
        $this->commands[] = $command;
        if ($this->throwEvidenceRequired) {
            throw new TargetedPackageEvidenceRequired('evidence required');
        }

        return (new TargetedPackageDraftPatcher)->replace(
            $command->draft,
            $command->sourceInputVersion,
            $command->packageKey,
            ['key' => 'roof'],
        );
    }
}

final class RecordingTargetedPackageRebuildReviewer implements TargetedPackageRebuildReviewer
{
    /** @var list<ArbiterOperationContext> */
    public array $contexts = [];

    public function __construct(private bool $unavailable = false) {}

    /** @return array<string, mixed> */
    public function review(array $draft, ?ArbiterOperationContext $operation = null): array
    {
        if (! $operation instanceof ArbiterOperationContext) {
            throw new \LogicException('Arbiter context is required.');
        }
        $this->contexts[] = $operation;

        $draft['arbiter_review'] = [
            'mode' => 'shadow',
            'status' => $this->unavailable ? 'unavailable' : 'reviewed',
            'outcome' => $this->unavailable ? 'human_review' : 'passed',
            'input_hash' => 'sha256:'.str_repeat('b', 64),
            'schema_version' => 'completeness-arbiter:v1',
            'prompt_version' => 'completeness-arbiter:v1',
            'model' => 'openai/gpt-5-mini',
            'findings' => [],
            'cycle' => [
                'input_hash' => 'sha256:'.str_repeat('b', 64),
                'attempted' => false,
                'target_package_keys' => ['roof'],
                'status' => 'shadow_recommendation',
                'terminal_outcome' => 'targeted_rebuild',
            ],
            'remediation' => [
                'root_input_hash' => 'sha256:'.str_repeat('b', 64),
                'target_package_keys' => ['roof'],
                'rebuild_attempted' => true,
                'phase' => 'reviewed',
                'review_outcome' => $this->unavailable ? 'human_review' : 'passed',
            ],
        ];

        return $draft;
    }
}

final class RecordingTargetedPackageRebuildCommitter implements TargetedPackageRebuildCommitter
{
    /** @var list<TargetedPackageRebuildCommand> */
    public array $commands = [];

    /** @var list<array<string, mixed>> */
    public array $reviewedDrafts = [];

    public function commit(
        TargetedPackageRebuildCommand $command,
        TargetedPackagePatchResult $result,
        array $reviewedDraft,
    ): TargetedPackageCommitResult {
        $this->commands[] = $command;
        $this->reviewedDrafts[] = $reviewedDraft;

        return new TargetedPackageCommitResult($command->sessionId, $command->packageKey, 'passed', $command->expectedStateVersion + 1, false);
    }
}
