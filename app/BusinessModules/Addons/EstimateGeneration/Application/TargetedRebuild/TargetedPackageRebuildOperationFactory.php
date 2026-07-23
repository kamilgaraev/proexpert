<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;

final class TargetedPackageRebuildOperationFactory
{
    /** @param array<string, mixed> $draft */
    public function fromPublishedDraft(
        string $operationId,
        int $organizationId,
        int $projectId,
        int $sessionId,
        int $stateVersion,
        string $sessionStatus,
        array $draft,
        bool $active = true,
    ): ?TargetedPackageRebuildOperationData {
        if (! $active
            || ! in_array($sessionStatus, ['estimate_review_required', 'ready_to_apply'], true)
            || $organizationId < 1
            || $projectId < 1
            || $sessionId < 1
            || $stateVersion < 0) {
            return null;
        }

        $sourceInputVersion = $draft['source_input_version'] ?? null;
        $review = $draft['arbiter_review'] ?? null;
        if (! is_string($sourceInputVersion)
            || ! $this->isHash($sourceInputVersion)
            || ! is_array($review)
            || ($review['mode'] ?? null) !== 'shadow'
            || ($review['status'] ?? null) !== 'reviewed'
            || ($review['outcome'] ?? null) !== 'targeted_rebuild'
            || ! is_string($review['input_hash'] ?? null)
            || ! $this->isHash($review['input_hash'])) {
            return null;
        }

        $packageKey = $this->targetPackageKey($draft, $review);
        if ($packageKey === null) {
            return null;
        }

        return TargetedPackageRebuildOperationData::queued(
            $operationId,
            hash('sha256', implode('|', [$sessionId, $stateVersion, $sourceInputVersion, $review['input_hash'], $packageKey])),
            $organizationId,
            $projectId,
            $sessionId,
            $stateVersion,
            $sourceInputVersion,
            $review['input_hash'],
            'sha256:'.hash('sha256', CanonicalPipelineJson::encode($draft)),
            $packageKey,
        );
    }

    /** @param array<string, mixed> $draft @param array<string, mixed> $review */
    private function targetPackageKey(array $draft, array $review): ?string
    {
        $cycle = $review['cycle'] ?? null;
        if (! is_array($cycle)
            || ($cycle['input_hash'] ?? null) !== $review['input_hash']
            || ($cycle['attempted'] ?? null) !== false
            || ($cycle['status'] ?? null) !== 'shadow_recommendation'
            || ($cycle['terminal_outcome'] ?? null) !== 'targeted_rebuild'
            || ! is_array($cycle['target_package_keys'] ?? null)
            || ! array_is_list($cycle['target_package_keys'])
            || count($cycle['target_package_keys']) !== 1
            || ! is_string($cycle['target_package_keys'][0] ?? null)
            || ! $this->isPackageKey($cycle['target_package_keys'][0])) {
            return null;
        }
        $packageKey = $cycle['target_package_keys'][0];
        $findings = $review['findings'] ?? null;
        if (! is_array($findings) || ! array_is_list($findings)) {
            return null;
        }
        $hasConfirmedTarget = false;
        foreach ($findings as $finding) {
            if (! is_array($finding) || ($finding['action'] ?? null) !== 'rebuild') {
                continue;
            }
            $packageKeys = $finding['package_keys'] ?? null;
            $evidenceRefs = $finding['evidence_refs'] ?? null;
            if (! is_array($packageKeys)
                || $packageKeys !== [$packageKey]
                || ! is_array($evidenceRefs)
                || $evidenceRefs === []
                || ! $this->validEvidenceReferences($evidenceRefs)) {
                return null;
            }
            $hasConfirmedTarget = true;
        }
        if (! $hasConfirmedTarget) {
            return null;
        }

        $matches = 0;
        foreach ($draft['local_estimates'] ?? [] as $estimate) {
            if (is_array($estimate) && ($estimate['key'] ?? null) === $packageKey) {
                $matches++;
            }
        }

        return $matches === 1 ? $packageKey : null;
    }

    /** @param array<int, mixed> $references */
    private function validEvidenceReferences(array $references): bool
    {
        foreach ($references as $reference) {
            if (! is_string($reference) || ! $this->isPackageKey($reference)) {
                return false;
            }
        }

        return true;
    }

    private function isHash(string $value): bool
    {
        return preg_match('/\Asha256:[a-f0-9]{64}\z/', $value) === 1;
    }

    private function isPackageKey(string $value): bool
    {
        return preg_match('/\A[A-Za-z0-9:._-]{1,120}\z/', $value) === 1;
    }
}
