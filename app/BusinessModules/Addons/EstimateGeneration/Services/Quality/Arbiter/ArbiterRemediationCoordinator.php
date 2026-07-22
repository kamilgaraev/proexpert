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
}
