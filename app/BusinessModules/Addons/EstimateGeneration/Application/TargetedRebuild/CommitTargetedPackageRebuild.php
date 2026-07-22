<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\SessionBaseInputVersionResolver;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter\ArbiterRemediationCoordinator;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter\ArbiterRemediationState;
use DomainException;
use InvalidArgumentException;

final readonly class CommitTargetedPackageRebuild
{
    public function __construct(
        private TargetedPackageCommitStore $store,
        private TargetedPackageDraftWriter $packages,
        private TargetedPackageDraftSummaryProjector $summary,
        private ArbiterRemediationCoordinator $remediation,
        private SessionBaseInputVersionResolver $baseInputVersions,
        private TargetedPackageReviewUpdater $advance,
    ) {}

    /** @param array<string, mixed> $reviewedDraft */
    public function commit(
        TargetedPackageRebuildCommand $command,
        TargetedPackagePatchResult $result,
        array $reviewedDraft,
    ): TargetedPackageCommitResult {
        $operationFingerprint = $this->operationFingerprint($command, $result, $reviewedDraft);

        return $this->store->withinLockedSession(
            $command->sessionId,
            $command->organizationId,
            $command->projectId,
            function (EstimateGenerationSession $session) use ($command, $result, $reviewedDraft, $operationFingerprint): TargetedPackageCommitResult {
                $existing = $this->store->operation($session, $command->operationId);
                if ($existing !== null) {
                    return $this->replay($existing, $operationFingerprint);
                }

                $this->assertSessionFence($session, $command);
                $initialDraft = $this->draft($session);
                $attempted = $this->remediation->markAttempted($initialDraft, $command->arbiterInputHash);
                $this->assertSameDraft($attempted, $command->draft, 'Targeted command draft is stale.');
                $this->assertSingleTarget($command);
                $this->assertPatch($command, $result);
                $outcome = $this->assertSecondReview($command, $result, $reviewedDraft);

                $requiresReview = $outcome === 'human_review';
                $storedDraft = $requiresReview
                    ? $this->withReviewMetadata($initialDraft, $reviewedDraft)
                    : $this->summary->project($reviewedDraft);

                if (! $requiresReview) {
                    $this->packages->syncPackageFromDraft(
                        $session,
                        $command->packageKey,
                        $this->targetPackage($storedDraft, $command->packageKey),
                        $command->sourceInputVersion,
                    );
                }

                $updated = $this->advance->reviewUpdated($session, $requiresReview, [
                    'draft_payload' => $storedDraft,
                    'problem_flags' => is_array($storedDraft['problem_flags'] ?? null)
                        ? $storedDraft['problem_flags']
                        : [],
                    'last_error' => null,
                    'failure_code' => null,
                ]);
                $payload = [
                    'operation_id' => $command->operationId,
                    'operation_fingerprint' => $operationFingerprint,
                    'session_id' => (int) $updated->getKey(),
                    'package_key' => $command->packageKey,
                    'source_input_version' => $command->sourceInputVersion,
                    'arbiter_input_hash' => $command->arbiterInputHash,
                    'target_before_fingerprint' => $result->targetBeforeFingerprint,
                    'target_after_fingerprint' => $result->targetAfterFingerprint,
                    'non_target_fingerprints' => $result->nonTargetFingerprints,
                    'outcome' => $outcome,
                    'state_version' => (int) $updated->state_version,
                ];
                $this->store->recordOperation($updated, $payload);

                return TargetedPackageCommitResult::fromOperation($payload, false);
            },
        );
    }

    /** @param array<string, mixed> $payload */
    private function replay(array $payload, string $operationFingerprint): TargetedPackageCommitResult
    {
        $storedFingerprint = $payload['operation_fingerprint'] ?? null;
        if (! is_string($storedFingerprint) || ! hash_equals($storedFingerprint, $operationFingerprint)) {
            throw new InvalidArgumentException('Targeted package operation identifier is already bound to different content.');
        }

        return TargetedPackageCommitResult::fromOperation($payload, true);
    }

    private function assertSessionFence(EstimateGenerationSession $session, TargetedPackageRebuildCommand $command): void
    {
        if ((int) $session->state_version !== $command->expectedStateVersion
            || $session->status->value !== $command->sessionStatus
            || ! in_array($session->status, [EstimateGenerationStatus::EstimateReviewRequired, EstimateGenerationStatus::ReadyToApply], true)
            || $session->applied_estimate_id !== null
            || ! hash_equals($this->baseInputVersions->resolve($session), $command->sourceInputVersion)) {
            throw new StaleEstimateGenerationState($command->sessionId, $command->expectedStateVersion);
        }
        $sourceInputVersion = $session->draft_payload['source_input_version'] ?? null;
        if (! is_string($sourceInputVersion) || ! hash_equals($sourceInputVersion, $command->sourceInputVersion)) {
            throw new StaleEstimateGenerationState($command->sessionId, $command->expectedStateVersion);
        }
    }

    private function assertSingleTarget(TargetedPackageRebuildCommand $command): void
    {
        $review = $command->draft['arbiter_review'] ?? null;
        $cycle = is_array($review) ? $review['cycle'] ?? null : null;
        $targets = is_array($cycle) ? $cycle['target_package_keys'] ?? null : null;
        if (! is_array($targets) || $targets !== [$command->packageKey]) {
            throw new DomainException('Targeted package cycle does not identify exactly one package.');
        }
    }

    private function assertPatch(TargetedPackageRebuildCommand $command, TargetedPackagePatchResult $result): void
    {
        if (! hash_equals($result->packageKey, $command->packageKey)
            || ! hash_equals($this->fingerprint($this->targetPackage($command->draft, $command->packageKey)), $result->targetBeforeFingerprint)
            || ! hash_equals($this->fingerprint($this->targetPackage($result->draft, $command->packageKey)), $result->targetAfterFingerprint)
            || ! hash_equals($result->draft['source_input_version'] ?? '', $command->sourceInputVersion)) {
            throw new DomainException('Targeted package patch is invalid.');
        }
        $actualNonTargetFingerprints = $this->nonTargetFingerprints($result->draft, $command->packageKey);
        $sourceNonTargetFingerprints = $this->nonTargetFingerprints($command->draft, $command->packageKey);
        if (array_keys($result->nonTargetFingerprints) !== array_keys($actualNonTargetFingerprints)
            || array_keys($sourceNonTargetFingerprints) !== array_keys($actualNonTargetFingerprints)) {
            throw new DomainException('Targeted package patch changed non-target packages.');
        }
        foreach ($result->nonTargetFingerprints as $packageKey => $expectedFingerprint) {
            $actualFingerprint = $actualNonTargetFingerprints[$packageKey] ?? null;
            $sourceFingerprint = $sourceNonTargetFingerprints[$packageKey] ?? null;
            if (! is_string($expectedFingerprint)
                || ! is_string($actualFingerprint)
                || ! is_string($sourceFingerprint)
                || ! hash_equals($expectedFingerprint, $actualFingerprint)
                || ! hash_equals($sourceFingerprint, $actualFingerprint)) {
                throw new DomainException('Targeted package patch changed non-target packages.');
            }
        }
    }

    /** @param array<string, mixed> $reviewedDraft */
    private function assertSecondReview(
        TargetedPackageRebuildCommand $command,
        TargetedPackagePatchResult $result,
        array $reviewedDraft,
    ): string {
        $withoutReview = $reviewedDraft;
        unset($withoutReview['arbiter_review']);
        $patchedWithoutReview = $result->draft;
        unset($patchedWithoutReview['arbiter_review']);
        $this->assertSameDraft($patchedWithoutReview, $withoutReview, 'Second review changed estimate content.');

        $review = $reviewedDraft['arbiter_review'] ?? null;
        $remediation = is_array($review) ? $review['remediation'] ?? null : null;
        if (! is_array($review)
            || ($review['mode'] ?? null) !== 'shadow'
            || ($review['status'] ?? null) !== 'reviewed'
            || ! is_string($review['input_hash'] ?? null)
            || ! hash_equals($review['input_hash'], $command->arbiterInputHash)
            || ! is_array($remediation)) {
            throw new DomainException('Second arbiter review is invalid.');
        }
        try {
            $state = ArbiterRemediationState::fromArray($remediation);
        } catch (\Throwable) {
            throw new DomainException('Second arbiter review is invalid.');
        }
        $outcome = $review['outcome'] ?? null;
        if (! is_string($outcome)
            || ! in_array($outcome, ['passed', 'confirmed_scope_only', 'human_review'], true)
            || ! hash_equals($state->rootInputHash, $command->arbiterInputHash)
            || $state->phase !== 'reviewed'
            || ! $state->rebuildAttempted
            || $state->targetPackageKeys !== [$command->packageKey]
            || $state->reviewOutcome !== $outcome) {
            throw new DomainException('Second arbiter review is invalid.');
        }

        return $outcome;
    }

    /** @param array<string, mixed> $draft @param array<string, mixed> $reviewedDraft @return array<string, mixed> */
    private function withReviewMetadata(array $draft, array $reviewedDraft): array
    {
        $review = $reviewedDraft['arbiter_review'] ?? null;
        if (! is_array($review)) {
            throw new DomainException('Second arbiter review is invalid.');
        }
        $draft['arbiter_review'] = $review;

        return $draft;
    }

    /** @return array<string, mixed> */
    private function draft(EstimateGenerationSession $session): array
    {
        if (! is_array($session->draft_payload)) {
            throw new DomainException('Targeted package session draft is invalid.');
        }

        return $session->draft_payload;
    }

    /** @param array<string, mixed> $draft @return array<string, mixed> */
    private function targetPackage(array $draft, string $packageKey): array
    {
        $matches = [];
        foreach ($draft['local_estimates'] ?? [] as $localEstimate) {
            if (! is_array($localEstimate) || ! is_string($localEstimate['key'] ?? null)) {
                throw new DomainException('Targeted package draft is invalid.');
            }
            if (hash_equals($localEstimate['key'], $packageKey)) {
                $matches[] = $localEstimate;
            }
        }
        if (count($matches) !== 1) {
            throw new DomainException('Targeted package must exist exactly once.');
        }

        return $matches[0];
    }

    /** @param array<string, mixed> $draft @return array<string, string> */
    private function nonTargetFingerprints(array $draft, string $targetPackageKey): array
    {
        $fingerprints = [];
        foreach ($draft['local_estimates'] ?? [] as $localEstimate) {
            if (! is_array($localEstimate) || ! is_string($localEstimate['key'] ?? null)) {
                throw new DomainException('Targeted package draft is invalid.');
            }
            if (! hash_equals($localEstimate['key'], $targetPackageKey)) {
                $fingerprints[$localEstimate['key']] = $this->fingerprint($localEstimate);
            }
        }
        ksort($fingerprints, SORT_STRING);

        return $fingerprints;
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right */
    private function assertSameDraft(array $left, array $right, string $message): void
    {
        if (! hash_equals(
            CanonicalPipelineJson::encode($this->normalizeDatabaseNumbers($left)),
            CanonicalPipelineJson::encode($this->normalizeDatabaseNumbers($right)),
        )) {
            throw new DomainException($message);
        }
    }

    private function normalizeDatabaseNumbers(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->normalizeDatabaseNumbers($item);
            }

            return $value;
        }
        if (is_float($value) && is_finite($value) && floor($value) === $value) {
            return (int) $value;
        }

        return $value;
    }

    /** @param array<string, mixed> $package */
    private function fingerprint(array $package): string
    {
        return 'sha256:'.hash('sha256', CanonicalPipelineJson::encode($package));
    }

    /** @param array<string, mixed> $reviewedDraft */
    private function operationFingerprint(
        TargetedPackageRebuildCommand $command,
        TargetedPackagePatchResult $result,
        array $reviewedDraft,
    ): string {
        return 'sha256:'.hash('sha256', CanonicalPipelineJson::encode([
            'session_id' => $command->sessionId,
            'organization_id' => $command->organizationId,
            'project_id' => $command->projectId,
            'expected_state_version' => $command->expectedStateVersion,
            'source_input_version' => $command->sourceInputVersion,
            'operation_id' => $command->operationId,
            'arbiter_input_hash' => $command->arbiterInputHash,
            'package_key' => $command->packageKey,
            'verdict' => ['outcome' => $command->verdict->outcome, 'findings' => $command->verdict->findings],
            'patch' => [
                'target_before' => $result->targetBeforeFingerprint,
                'target_after' => $result->targetAfterFingerprint,
                'non_target_fingerprints' => $result->nonTargetFingerprints,
            ],
            'reviewed_arbiter_review' => $reviewedDraft['arbiter_review'] ?? null,
        ]));
    }
}
