<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter;

final class ArbiterRemediationCoordinator
{
    /** @return array<string, mixed> */
    public function recordShadowCycle(array $draft, ArbiterVerdict $verdict, string $inputHash): array
    {
        if ($verdict->outcome !== 'targeted_rebuild') {
            return $draft;
        }

        if ($this->hasRecordedCycleForInputHash($draft, $inputHash)) {
            return $this->withCycle($draft, new ArbiterReviewCycle(
                $inputHash,
                false,
                [],
                'cycle_exhausted',
                'human_review',
            ));
        }

        $targetPackageKeys = $this->verifiedTargetPackageKeys($draft, $verdict);
        if ($targetPackageKeys === null) {
            return $this->withCycle($draft, new ArbiterReviewCycle(
                $inputHash,
                false,
                [],
                'evidence_required',
                'human_review',
            ));
        }

        return $this->withCycle($draft, new ArbiterReviewCycle(
            $inputHash,
            false,
            $targetPackageKeys,
            'shadow_recommendation',
            'targeted_rebuild',
        ));
    }

    /** @return array<string, mixed> */
    public function markAttempted(array $draft, string $rootInputHash): array
    {
        $cycle = $this->cycle($draft);
        if ($this->remediation($draft) !== null || ! $this->isOriginalRecommendation($cycle, $rootInputHash)) {
            return $this->routeToHumanReview($draft, $rootInputHash);
        }

        return $this->withRemediation($draft, new ArbiterRemediationState(
            $rootInputHash,
            $cycle->targetPackageKeys,
            true,
            'attempted',
            null,
        ));
    }

    /** @return array<string, mixed> */
    public function resolveAfterRebuild(array $draft, ArbiterVerdict $verdict): array
    {
        $remediation = $this->attemptedRemediation($draft);
        if ($remediation === null) {
            return $draft;
        }

        $outcome = in_array($verdict->outcome, ['passed', 'confirmed_scope_only'], true)
            ? $verdict->outcome
            : 'human_review';

        return $this->withRemediation(
            $draft,
            new ArbiterRemediationState(
                $remediation->rootInputHash,
                $remediation->targetPackageKeys,
                true,
                'reviewed',
                $outcome,
            ),
            $outcome,
        );
    }

    /** @return list<string>|null */
    private function verifiedTargetPackageKeys(array $draft, ArbiterVerdict $verdict): ?array
    {
        $availablePackageKeys = [];
        foreach ((array) ($draft['local_estimates'] ?? []) as $estimate) {
            $packageKey = is_array($estimate) ? $estimate['key'] ?? null : null;
            if (is_string($packageKey) && preg_match('/^[A-Za-z0-9:._-]{1,120}$/', $packageKey) === 1) {
                $availablePackageKeys[$packageKey] = true;
            }
        }

        $targetPackageKeys = [];
        $hasRebuildFinding = false;
        foreach ($verdict->findings as $finding) {
            if (! is_array($finding) || ($finding['action'] ?? null) !== 'rebuild') {
                continue;
            }
            $hasRebuildFinding = true;
            $packageKeys = $this->references($finding['package_keys'] ?? null);
            $evidenceRefs = $this->references($finding['evidence_refs'] ?? null);
            if ($packageKeys === null || $evidenceRefs === null) {
                return null;
            }
            foreach ($packageKeys as $packageKey) {
                if (! isset($availablePackageKeys[$packageKey])) {
                    return null;
                }
                $targetPackageKeys[$packageKey] = true;
            }
        }
        if (! $hasRebuildFinding || $targetPackageKeys === []) {
            return null;
        }

        $targetPackageKeys = array_keys($targetPackageKeys);
        sort($targetPackageKeys, SORT_STRING);

        return $targetPackageKeys;
    }

    private function hasRecordedCycleForInputHash(array $draft, string $inputHash): bool
    {
        $cycle = is_array($draft['arbiter_review'] ?? null)
            ? $draft['arbiter_review']['cycle'] ?? null
            : null;

        return is_array($cycle) && ($cycle['input_hash'] ?? null) === $inputHash;
    }

    private function isOriginalRecommendation(?ArbiterReviewCycle $cycle, string $rootInputHash): bool
    {
        return $cycle !== null
            && $cycle->inputHash === $rootInputHash
            && $cycle->attempted === false
            && $cycle->status === 'shadow_recommendation'
            && $cycle->terminalOutcome === 'targeted_rebuild'
            && $cycle->targetPackageKeys !== [];
    }

    private function attemptedRemediation(array $draft): ?ArbiterRemediationState
    {
        $state = $this->remediation($draft);

        return $state !== null && $state->phase === 'attempted' && $state->rebuildAttempted ? $state : null;
    }

    private function remediation(array $draft): ?ArbiterRemediationState
    {
        $review = is_array($draft['arbiter_review'] ?? null) ? $draft['arbiter_review'] : null;
        $remediation = is_array($review) && is_array($review['remediation'] ?? null)
            ? $review['remediation']
            : null;
        if ($remediation === null) {
            return null;
        }

        try {
            $state = ArbiterRemediationState::fromArray($remediation);
        } catch (\Throwable) {
            return null;
        }

        return $state;
    }

    private function cycle(array $draft): ?ArbiterReviewCycle
    {
        $review = is_array($draft['arbiter_review'] ?? null) ? $draft['arbiter_review'] : null;
        $cycle = is_array($review) && is_array($review['cycle'] ?? null) ? $review['cycle'] : null;
        if ($cycle === null
            || ! is_string($cycle['input_hash'] ?? null)
            || ! is_bool($cycle['attempted'] ?? null)
            || ! is_array($cycle['target_package_keys'] ?? null)
            || ! is_string($cycle['status'] ?? null)
            || ! is_string($cycle['terminal_outcome'] ?? null)) {
            return null;
        }

        try {
            return new ArbiterReviewCycle(
                $cycle['input_hash'],
                $cycle['attempted'],
                $cycle['target_package_keys'],
                $cycle['status'],
                $cycle['terminal_outcome'],
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<string>|null */
    private function references(mixed $references): ?array
    {
        if (! is_array($references) || $references === []) {
            return null;
        }
        $result = [];
        foreach ($references as $reference) {
            if (! is_string($reference) || preg_match('/^[A-Za-z0-9:._-]{1,120}$/', $reference) !== 1) {
                return null;
            }
            $result[$reference] = true;
        }

        return array_keys($result);
    }

    /** @return array<string, mixed> */
    private function withCycle(array $draft, ArbiterReviewCycle $cycle): array
    {
        $review = is_array($draft['arbiter_review'] ?? null) ? $draft['arbiter_review'] : [];
        $review['outcome'] = $cycle->terminalOutcome;
        $review['cycle'] = $cycle->toArray();
        $draft['arbiter_review'] = $review;

        return $draft;
    }

    /** @return array<string, mixed> */
    private function withRemediation(array $draft, ArbiterRemediationState $remediation, ?string $outcome = null): array
    {
        $review = is_array($draft['arbiter_review'] ?? null) ? $draft['arbiter_review'] : [];
        if ($outcome !== null) {
            $review['outcome'] = $outcome;
        }
        $review['remediation'] = $remediation->toArray();
        $draft['arbiter_review'] = $review;

        return $draft;
    }

    /** @return array<string, mixed> */
    private function routeToHumanReview(array $draft, string $rootInputHash): array
    {
        $review = is_array($draft['arbiter_review'] ?? null) ? $draft['arbiter_review'] : [];
        $review['outcome'] = 'human_review';
        unset($review['remediation']);

        if (preg_match('/^sha256:[a-f0-9]{64}$/', $rootInputHash) === 1) {
            $review['cycle'] = (new ArbiterReviewCycle(
                $rootInputHash,
                false,
                [],
                'evidence_required',
                'human_review',
            ))->toArray();
        } else {
            unset($review['cycle']);
        }

        $draft['arbiter_review'] = $review;

        return $draft;
    }
}
