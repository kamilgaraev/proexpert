<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter\ArbiterOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter\ArbiterRemediationCoordinator;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter\ArbiterVerdict;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter\TargetedPackageRebuildReviewer;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;

final class RunTargetedPackageRebuildOperation implements TargetedPackageRebuildJobHandler
{
    public function __construct(
        private readonly TargetedPackageRebuildOperationStore $operations,
        private readonly TargetedPackageRebuildSessionSource $sessions,
        private readonly TargetedPackageRebuildExecutor $rebuilds,
        private readonly TargetedPackageRebuildReviewer $reviews,
        private readonly TargetedPackageRebuildCommitter $commits,
        private readonly bool $active,
        private readonly ?Closure $leaseTokens = null,
        private readonly ?Closure $clock = null,
        private readonly ArbiterRemediationCoordinator $remediation = new ArbiterRemediationCoordinator,
        private readonly TargetedPackageDraftPatcher $patcher = new TargetedPackageDraftPatcher,
    ) {}

    public function handle(string $operationId): void
    {
        $operation = $this->operations->find($operationId);
        if (! $operation instanceof TargetedPackageRebuildOperationData) {
            return;
        }
        if (! $this->active) {
            $this->cancelIfOpen($operation);

            return;
        }
        if ($operation->status === 'reviewed') {
            $this->recoverReviewed($operation);

            return;
        }
        if ($operation->status !== 'queued') {
            return;
        }

        $claimed = $this->operations->claimQueued(
            $operation->operationId,
            $this->leaseToken(),
            $this->now()->modify('+5 minutes'),
        );
        if (! $claimed instanceof TargetedPackageRebuildOperationData) {
            return;
        }

        $snapshot = $this->sessions->find($claimed);
        $fence = $this->fence($claimed, $snapshot);
        if ($fence !== 'ready') {
            $this->finishFence($claimed, $fence);

            return;
        }
        try {
            $attemptedDraft = $this->remediation->markAttempted($snapshot->draft, $claimed->rootInputHash);
            $command = $this->command($claimed, $snapshot, $attemptedDraft);
            $result = $this->rebuilds->rebuild($command);
        } catch (TargetedPackageEvidenceRequired|InvalidArgumentException) {
            $this->routeToHumanReview($claimed, $snapshot->draft ?? []);

            return;
        } catch (\Throwable) {
            $this->routeToHumanReview($claimed, $snapshot->draft ?? []);

            return;
        }

        try {
            $reviewedDraft = $this->reviews->review(
                $result->draft,
                new ArbiterOperationContext(
                    $claimed->organizationId,
                    $claimed->projectId,
                    $claimed->sessionId,
                    $claimed->operationId,
                    $claimed->sourceInputVersion,
                    1,
                ),
            );
        } catch (\Throwable) {
            $this->routeToHumanReview($claimed, $attemptedDraft);

            return;
        }

        $review = $reviewedDraft['arbiter_review'] ?? null;
        if (! is_array($review)) {
            $this->routeToHumanReview($claimed, $attemptedDraft);

            return;
        }
        if (($review['outcome'] ?? null) === 'human_review') {
            $this->routeToHumanReview($claimed, $reviewedDraft, $review);

            return;
        }
        try {
            $reviewed = $claimed->withReviewed($this->delta($result), $review);
            $this->operations->save($reviewed);
        } catch (\Throwable) {
            $this->routeToHumanReview($claimed, $attemptedDraft);

            return;
        }

        $this->recoverReviewed($reviewed);
    }

    private function recoverReviewed(TargetedPackageRebuildOperationData $operation): void
    {
        $snapshot = $this->sessions->find($operation);
        $fence = $this->fence($operation, $snapshot);
        if ($fence !== 'ready') {
            $this->finishFence($operation, $fence);

            return;
        }
        try {
            $attemptedDraft = $this->remediation->markAttempted($snapshot->draft, $operation->rootInputHash);
            $command = $this->command($operation, $snapshot, $attemptedDraft);
            $result = $this->patcher->replace(
                $attemptedDraft,
                $operation->sourceInputVersion,
                $operation->packageKey,
                $this->deltaTarget($operation),
            );
            $this->assertRecoveredResult($operation, $result);
            $reviewedDraft = $result->draft;
            $reviewedDraft['arbiter_review'] = $operation->safeArbiterReview;
            $this->assertReviewFence($operation, $reviewedDraft);
        } catch (\Throwable) {
            $this->operations->save($operation->withStale());

            return;
        }

        try {
            $commit = $this->commits->commit($command, $result, $reviewedDraft);
        } catch (StaleEstimateGenerationState|InvalidArgumentException) {
            $this->operations->save($operation->withStale());

            return;
        } catch (\Throwable) {
            $this->routeToHumanReview($operation, $attemptedDraft);

            return;
        }
        if ($commit->outcome === 'human_review') {
            $this->routeToHumanReview($operation, $reviewedDraft, $operation->safeArbiterReview);

            return;
        }
        if (! in_array($commit->outcome, ['passed', 'confirmed_scope_only'], true)) {
            $this->operations->save($operation->withStale());

            return;
        }

        $this->operations->save($operation->withCommitted());
    }

    /** @param array<string, mixed>|null $snapshot */
    private function fence(TargetedPackageRebuildOperationData $operation, ?TargetedPackageRebuildSessionSnapshot $snapshot): string
    {
        if (! $snapshot instanceof TargetedPackageRebuildSessionSnapshot
            || $snapshot->organizationId !== $operation->organizationId
            || $snapshot->projectId !== $operation->projectId
            || $snapshot->sessionId !== $operation->sessionId
            || $snapshot->stateVersion !== $operation->expectedStateVersion
            || $snapshot->appliedEstimateId !== null
            || ! in_array($snapshot->status, ['estimate_review_required', 'ready_to_apply'], true)
            || ! hash_equals($operation->sourceDraftFingerprint, $this->fingerprint($snapshot->draft))
            || ! is_string($snapshot->draft['source_input_version'] ?? null)
            || ! hash_equals($operation->sourceInputVersion, $snapshot->draft['source_input_version'])) {
            return $snapshot instanceof TargetedPackageRebuildSessionSnapshot && $snapshot->status === 'cancelled'
                ? 'cancelled'
                : 'stale';
        }

        return 'ready';
    }

    private function finishFence(TargetedPackageRebuildOperationData $operation, string $fence): void
    {
        $this->operations->save($fence === 'cancelled' ? $operation->withCancelled() : $operation->withStale());
    }

    private function cancelIfOpen(TargetedPackageRebuildOperationData $operation): void
    {
        if (in_array($operation->status, ['queued', 'running', 'reviewed'], true)) {
            $this->operations->save($operation->withCancelled());
        }
    }

    /** @param array<string, mixed> $attemptedDraft */
    private function command(
        TargetedPackageRebuildOperationData $operation,
        TargetedPackageRebuildSessionSnapshot $snapshot,
        array $attemptedDraft,
    ): TargetedPackageRebuildCommand {
        $review = $attemptedDraft['arbiter_review'] ?? null;
        $findings = is_array($review) ? $review['findings'] ?? null : null;
        if (! is_array($findings) || ! array_is_list($findings)) {
            throw new TargetedPackageEvidenceRequired('Targeted rebuild findings are unavailable.');
        }

        return new TargetedPackageRebuildCommand(
            $operation->sessionId,
            $operation->organizationId,
            $operation->projectId,
            $operation->expectedStateVersion,
            $operation->sourceInputVersion,
            $operation->operationId,
            $operation->rootInputHash,
            $operation->packageKey,
            new ArbiterVerdict('targeted_rebuild', $findings),
            $snapshot->status,
            $attemptedDraft,
        );
    }

    /** @return array<string, mixed> */
    private function delta(TargetedPackagePatchResult $result): array
    {
        return [
            'target_package' => $this->targetPackage($result->draft, $result->packageKey),
            'target_before_fingerprint' => $result->targetBeforeFingerprint,
            'target_after_fingerprint' => $result->targetAfterFingerprint,
            'non_target_fingerprints' => $result->nonTargetFingerprints,
        ];
    }

    /** @return array<string, mixed> */
    private function deltaTarget(TargetedPackageRebuildOperationData $operation): array
    {
        $target = $operation->resultDelta['target_package'] ?? null;
        if (! is_array($target)) {
            throw new InvalidArgumentException('Targeted rebuild result is unavailable.');
        }

        return $target;
    }

    private function assertRecoveredResult(TargetedPackageRebuildOperationData $operation, TargetedPackagePatchResult $result): void
    {
        $delta = $operation->resultDelta;
        if (! is_string($delta['target_before_fingerprint'] ?? null)
            || ! is_string($delta['target_after_fingerprint'] ?? null)
            || ! is_array($delta['non_target_fingerprints'] ?? null)
            || ! hash_equals($delta['target_before_fingerprint'], $result->targetBeforeFingerprint)
            || ! hash_equals($delta['target_after_fingerprint'], $result->targetAfterFingerprint)
            || $delta['non_target_fingerprints'] !== $result->nonTargetFingerprints) {
            throw new InvalidArgumentException('Targeted rebuild recovery is stale.');
        }
    }

    /** @param array<string, mixed> $reviewedDraft */
    private function assertReviewFence(TargetedPackageRebuildOperationData $operation, array $reviewedDraft): void
    {
        $review = $reviewedDraft['arbiter_review'] ?? null;
        if (! is_array($review)
            || ($review['mode'] ?? null) !== 'shadow'
            || ($review['status'] ?? null) !== 'reviewed'
            || ! in_array($review['outcome'] ?? null, ['passed', 'confirmed_scope_only'], true)
            || ! is_string($review['input_hash'] ?? null)
            || ! hash_equals($operation->rootInputHash, $review['input_hash'])) {
            throw new InvalidArgumentException('Targeted rebuild review is stale.');
        }
    }

    /** @param array<string, mixed> $draft @return array<string, mixed> */
    private function targetPackage(array $draft, string $packageKey): array
    {
        foreach ($draft['local_estimates'] ?? [] as $localEstimate) {
            if (is_array($localEstimate) && ($localEstimate['key'] ?? null) === $packageKey) {
                return $localEstimate;
            }
        }

        throw new InvalidArgumentException('Targeted rebuild package is unavailable.');
    }

    /** @param array<string, mixed> $draft */
    private function routeToHumanReview(
        TargetedPackageRebuildOperationData $operation,
        array $draft,
        ?array $review = null,
    ): void {
        $review ??= $this->humanReview($draft, $operation);
        try {
            if (! is_array($review)) {
                throw new InvalidArgumentException('Targeted rebuild review is unavailable.');
            }
            $this->operations->save($operation->withHumanReview($review));
        } catch (\Throwable) {
            $this->operations->save($operation->withStale());
        }
    }

    /** @param array<string, mixed> $draft @return array<string, mixed>|null */
    private function humanReview(array $draft, TargetedPackageRebuildOperationData $operation): ?array
    {
        $source = $draft['arbiter_review'] ?? null;
        if (! is_array($source)
            || ! is_string($source['schema_version'] ?? null)
            || ! is_string($source['prompt_version'] ?? null)
            || ! is_string($source['model'] ?? null)) {
            return null;
        }

        return [
            'mode' => 'shadow',
            'status' => 'reviewed',
            'outcome' => 'human_review',
            'input_hash' => $operation->rootInputHash,
            'schema_version' => $source['schema_version'],
            'prompt_version' => $source['prompt_version'],
            'model' => $source['model'],
            'findings' => [],
        ];
    }

    /** @param array<string, mixed> $draft */
    private function fingerprint(array $draft): string
    {
        return 'sha256:'.hash('sha256', CanonicalPipelineJson::encode($draft));
    }

    private function leaseToken(): string
    {
        if ($this->leaseTokens instanceof Closure) {
            return ($this->leaseTokens)();
        }

        return (string) \Illuminate\Support\Str::uuid();
    }

    private function now(): DateTimeImmutable
    {
        if ($this->clock instanceof Closure) {
            return ($this->clock)();
        }

        return new DateTimeImmutable;
    }
}
