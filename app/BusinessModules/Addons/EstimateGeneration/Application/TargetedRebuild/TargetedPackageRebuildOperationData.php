<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild;

use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter\ArbiterRemediationState;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter\ArbiterReviewCycle;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;

final readonly class TargetedPackageRebuildOperationData
{
    /**
     * @param  array<string, mixed>  $resultDelta
     * @param  array<string, mixed>  $safeArbiterReview
     */
    private function __construct(
        public string $operationId,
        public string $idempotencyKey,
        public int $organizationId,
        public int $projectId,
        public int $sessionId,
        public int $expectedStateVersion,
        public string $sourceInputVersion,
        public string $rootInputHash,
        public string $sourceDraftFingerprint,
        public string $packageKey,
        public string $status,
        public ?string $leaseToken,
        public ?DateTimeImmutable $leaseExpiresAt,
        public int $attemptCount,
        public array $resultDelta,
        public array $safeArbiterReview,
    ) {
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $operationId) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $idempotencyKey) !== 1
            || min($organizationId, $projectId, $sessionId) < 1
            || $expectedStateVersion < 0
            || ! $this->isHash($sourceInputVersion)
            || ! $this->isHash($rootInputHash)
            || ! $this->isHash($sourceDraftFingerprint)
            || preg_match('/\A[A-Za-z0-9:._-]{1,120}\z/', $packageKey) !== 1
            || ! in_array($status, self::STATUSES, true)
            || $attemptCount < 0
            || ($leaseToken !== null && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $leaseToken) !== 1)
            || (($leaseToken === null) !== ($leaseExpiresAt === null))
            || ! $this->validLifecycle()) {
            throw new InvalidArgumentException('Invalid targeted rebuild operation data.');
        }
    }

    private const STATUSES = [
        'queued',
        'running',
        'reviewed',
        'committed',
        'human_review',
        'stale',
        'cancelled',
    ];

    public static function queued(
        string $operationId,
        string $idempotencyKey,
        int $organizationId,
        int $projectId,
        int $sessionId,
        int $expectedStateVersion,
        string $sourceInputVersion,
        string $rootInputHash,
        string $sourceDraftFingerprint,
        string $packageKey,
    ): self {
        return new self(
            $operationId,
            $idempotencyKey,
            $organizationId,
            $projectId,
            $sessionId,
            $expectedStateVersion,
            $sourceInputVersion,
            $rootInputHash,
            $sourceDraftFingerprint,
            $packageKey,
            'queued',
            null,
            null,
            0,
            [],
            [],
        );
    }

    public function withLease(string $leaseToken, DateTimeImmutable $leaseExpiresAt): self
    {
        if ($this->status !== 'queued') {
            throw new LogicException('Targeted rebuild operation cannot be claimed from its current state.');
        }

        return new self(
            $this->operationId,
            $this->idempotencyKey,
            $this->organizationId,
            $this->projectId,
            $this->sessionId,
            $this->expectedStateVersion,
            $this->sourceInputVersion,
            $this->rootInputHash,
            $this->sourceDraftFingerprint,
            $this->packageKey,
            'running',
            $leaseToken,
            $leaseExpiresAt,
            $this->attemptCount + 1,
            $this->resultDelta,
            $this->safeArbiterReview,
        );
    }

    /**
     * @param  array<string, mixed>  $resultDelta
     * @param  array<string, mixed>  $safeArbiterReview
     */
    public function withReviewed(array $resultDelta, array $safeArbiterReview): self
    {
        if ($this->status !== 'running' || $this->leaseToken === null || $this->leaseExpiresAt === null || $this->attemptCount < 1
            || ($safeArbiterReview['status'] ?? null) !== 'reviewed'
            || ! in_array($safeArbiterReview['outcome'] ?? null, ['passed', 'confirmed_scope_only'], true)
            || ! $this->validResultDelta($resultDelta) || ! $this->validSafeReview($safeArbiterReview)) {
            throw new InvalidArgumentException('Invalid targeted rebuild review result.');
        }

        return new self(
            $this->operationId,
            $this->idempotencyKey,
            $this->organizationId,
            $this->projectId,
            $this->sessionId,
            $this->expectedStateVersion,
            $this->sourceInputVersion,
            $this->rootInputHash,
            $this->sourceDraftFingerprint,
            $this->packageKey,
            'reviewed',
            null,
            null,
            $this->attemptCount,
            $resultDelta,
            $safeArbiterReview,
        );
    }

    public function withCommitted(): self
    {
        if ($this->status !== 'reviewed') {
            throw new LogicException('Targeted rebuild operation must be reviewed before it is committed.');
        }

        return $this->terminal('committed');
    }

    public function commitRecovery(): TargetedPackageRebuildCommitRecovery
    {
        if ($this->status !== 'reviewed') {
            throw new LogicException('Targeted rebuild operation must be reviewed before commit recovery.');
        }

        return TargetedPackageRebuildCommitRecovery::fromReviewedOperation($this);
    }

    /** @param array<string, mixed> $safeArbiterReview */
    public function withHumanReview(array $safeArbiterReview): self
    {
        if (! in_array($this->status, ['running', 'reviewed'], true)
            || ! $this->validSafeReview($safeArbiterReview)
            || ($safeArbiterReview['outcome'] ?? null) !== 'human_review') {
            throw new InvalidArgumentException('Invalid targeted rebuild human review transition.');
        }

        return new self(
            $this->operationId,
            $this->idempotencyKey,
            $this->organizationId,
            $this->projectId,
            $this->sessionId,
            $this->expectedStateVersion,
            $this->sourceInputVersion,
            $this->rootInputHash,
            $this->sourceDraftFingerprint,
            $this->packageKey,
            'human_review',
            null,
            null,
            $this->attemptCount,
            $this->resultDelta,
            $safeArbiterReview,
        );
    }

    public function withStale(): self
    {
        return $this->endWithoutCommit('stale');
    }

    public function withCancelled(): self
    {
        return $this->endWithoutCommit('cancelled');
    }

    private function endWithoutCommit(string $status): self
    {
        if (! in_array($this->status, ['queued', 'running', 'reviewed'], true)) {
            throw new LogicException('Targeted rebuild operation cannot enter this terminal state.');
        }

        return $this->terminal($status);
    }

    private function terminal(string $status): self
    {
        return new self(
            $this->operationId,
            $this->idempotencyKey,
            $this->organizationId,
            $this->projectId,
            $this->sessionId,
            $this->expectedStateVersion,
            $this->sourceInputVersion,
            $this->rootInputHash,
            $this->sourceDraftFingerprint,
            $this->packageKey,
            $status,
            null,
            null,
            $this->attemptCount,
            $this->resultDelta,
            $this->safeArbiterReview,
        );
    }

    private function isHash(string $value): bool
    {
        return preg_match('/\Asha256:[a-f0-9]{64}\z/', $value) === 1;
    }

    /** @param array<string, mixed> $resultDelta */
    private function validResultDelta(array $resultDelta): bool
    {
        $expected = ['target_package', 'target_before_fingerprint', 'target_after_fingerprint', 'non_target_fingerprints'];
        if (array_keys($resultDelta) !== $expected
            || ! $this->validTargetPackage($resultDelta['target_package'] ?? null)
            || ! is_string($resultDelta['target_before_fingerprint'])
            || ! $this->isHash($resultDelta['target_before_fingerprint'])
            || ! is_string($resultDelta['target_after_fingerprint'])
            || ! $this->isHash($resultDelta['target_after_fingerprint'])
            || ! is_array($resultDelta['non_target_fingerprints'])) {
            return false;
        }
        foreach ($resultDelta['non_target_fingerprints'] as $packageKey => $fingerprint) {
            if (! is_string($packageKey)
                || preg_match('/\A[A-Za-z0-9:._-]{1,120}\z/', $packageKey) !== 1
                || ! is_string($fingerprint)
                || ! $this->isHash($fingerprint)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $review */
    private function validSafeReview(array $review): bool
    {
        $allowed = ['mode', 'status', 'outcome', 'input_hash', 'schema_version', 'prompt_version', 'model', 'findings', 'cycle', 'remediation', 'input_tokens', 'output_tokens'];
        $required = ['mode', 'status', 'outcome', 'input_hash', 'schema_version', 'prompt_version', 'model', 'findings'];
        if ($review === []
            || array_diff(array_keys($review), $allowed) !== []
            || array_diff($required, array_keys($review)) !== []
            || ($review['mode'] ?? null) !== 'shadow'
            || ! in_array($review['status'] ?? null, ['reviewed', 'unavailable'], true)
            || ! in_array($review['outcome'] ?? null, ['passed', 'confirmed_scope_only', 'human_review'], true)
            || ! is_string($review['input_hash']) || ! $this->isHash($review['input_hash'])
            || ! $this->isVersion($review['schema_version'])
            || ! $this->isVersion($review['prompt_version'])
            || ! $this->isModel($review['model'])
            || ! $this->validFindings($review['findings'])) {
            return false;
        }
        if (($review['status'] === 'unavailable') !== ($review['outcome'] === 'human_review')) {
            return false;
        }
        foreach (['input_tokens', 'output_tokens'] as $key) {
            if (array_key_exists($key, $review) && (! is_int($review[$key]) || $review[$key] < 0 || $review[$key] > 1_000_000)) {
                return false;
            }
        }
        try {
            if (isset($review['cycle'])) {
                if (! is_array($review['cycle']) || ! $this->validCycle($review['cycle'])) {
                    return false;
                }
            }
            if (isset($review['remediation'])) {
                if (! is_array($review['remediation'])) {
                    return false;
                }
                ArbiterRemediationState::fromArray($review['remediation']);
            }
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    private function validLifecycle(): bool
    {
        $hasLease = $this->leaseToken !== null && $this->leaseExpiresAt !== null;
        $hasResult = $this->resultDelta !== [];
        $hasReview = $this->safeArbiterReview !== [];
        if (($hasResult && ! $this->validResultDelta($this->resultDelta))
            || ($hasReview && ! $this->validSafeReview($this->safeArbiterReview))) {
            return false;
        }

        return match ($this->status) {
            'queued' => $this->attemptCount === 0 && ! $hasLease && ! $hasResult && ! $hasReview,
            'running' => $this->attemptCount >= 1 && $hasLease && ! $hasResult && ! $hasReview,
            'reviewed', 'committed' => $this->attemptCount >= 1 && ! $hasLease && $hasResult && $hasReview,
            'human_review' => $this->attemptCount >= 1 && ! $hasLease && $hasReview,
            'stale', 'cancelled' => ! $hasLease && ($hasResult === $hasReview),
            default => false,
        };
    }

    private function validTargetPackage(mixed $package): bool
    {
        if (! is_array($package)
            || ! isset($package['key'])
            || ! is_string($package['key'])
            || $package['key'] !== $this->packageKey
            || ! $this->isPackageKey($package['key'])) {
            return false;
        }
        $keys = array_keys($package);
        if ($keys === ['key']) {
            return true;
        }
        if ($keys !== ['key', 'sections'] || ! is_array($package['sections']) || ! array_is_list($package['sections'])) {
            return false;
        }
        foreach ($package['sections'] as $section) {
            if (! is_array($section) || array_keys($section) !== ['key', 'work_items']
                || ! is_string($section['key']) || ! $this->isPackageKey($section['key'])
                || ! is_array($section['work_items']) || ! array_is_list($section['work_items'])) {
                return false;
            }
            foreach ($section['work_items'] as $workItem) {
                if (! $this->validWorkItem($workItem)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function validWorkItem(mixed $workItem): bool
    {
        if (! is_array($workItem)
            || ! is_string($workItem['key'] ?? null)
            || ! $this->isPackageKey($workItem['key'])) {
            return false;
        }
        $allowed = ['key', 'item_type', 'name', 'unit', 'quantity', 'materials', 'labor', 'machinery', 'other_resources', 'materials_cost', 'labor_cost', 'machinery_cost', 'total_cost', 'pricing_status', 'pricing_blocker', 'validation_flags', 'normative_match'];
        if (array_diff(array_keys($workItem), $allowed) !== []) {
            return false;
        }
        foreach (['materials', 'labor', 'machinery', 'other_resources'] as $group) {
            if (array_key_exists($group, $workItem)
                && (! is_array($workItem[$group]) || ! array_is_list($workItem[$group]) || ! $this->validResources($workItem[$group]))) {
                return false;
            }
        }

        if (array_key_exists('normative_match', $workItem) && ! $this->validNormativeMatch($workItem['normative_match'])) {
            return false;
        }
        if (array_key_exists('validation_flags', $workItem) && ! $this->validScalarList($workItem['validation_flags'])) {
            return false;
        }
        foreach (['item_type', 'name', 'unit', 'quantity', 'materials_cost', 'labor_cost', 'machinery_cost', 'total_cost', 'pricing_status', 'pricing_blocker'] as $key) {
            if (array_key_exists($key, $workItem) && ! $this->validScalar($workItem[$key])) {
                return false;
            }
        }

        return $this->containsForbiddenKey($workItem) === false;
    }

    /** @param array<int, mixed> $resources */
    private function validResources(array $resources): bool
    {
        foreach ($resources as $resource) {
            if (! is_array($resource)) {
                return false;
            }
            $allowed = ['key', 'code', 'name', 'resource_type', 'unit', 'price_unit', 'quantity', 'unit_price', 'total_cost', 'normative_ref', 'project_material_selection'];
            if (array_diff(array_keys($resource), $allowed) !== [] || $this->containsForbiddenKey($resource)) {
                return false;
            }
            foreach (['key', 'code', 'name', 'resource_type', 'unit', 'price_unit', 'quantity', 'unit_price', 'total_cost'] as $key) {
                if (array_key_exists($key, $resource) && ! $this->validScalar($resource[$key])) {
                    return false;
                }
            }
            if (array_key_exists('normative_ref', $resource) && ! $this->validNormativeReference($resource['normative_ref'])) {
                return false;
            }
            if (array_key_exists('project_material_selection', $resource) && ! $this->validProjectMaterialSelection($resource['project_material_selection'])) {
                return false;
            }
        }

        return true;
    }

    private function validNormativeMatch(mixed $match): bool
    {
        if (! is_array($match) || array_diff(array_keys($match), ['status', 'decision', 'confidence', 'work_composition']) !== []) {
            return false;
        }
        if (array_key_exists('status', $match) && ! is_string($match['status'])) {
            return false;
        }
        if (array_key_exists('confidence', $match) && ! is_numeric($match['confidence'])) {
            return false;
        }
        if (array_key_exists('work_composition', $match) && ! $this->validScalarList($match['work_composition'])) {
            return false;
        }
        if (! array_key_exists('decision', $match)) {
            return true;
        }
        $decision = $match['decision'];
        if (! is_array($decision) || array_diff(array_keys($decision), ['status', 'norm_id', 'code']) !== []) {
            return false;
        }

        return ! array_key_exists('status', $decision) || is_string($decision['status']);
    }

    private function validNormativeReference(mixed $reference): bool
    {
        if (! is_array($reference) || array_diff(array_keys($reference), ['resource_code', 'resource_id', 'norm_resource_id', 'price_id', 'project_material_selection']) !== []) {
            return false;
        }
        foreach (['resource_code', 'resource_id', 'norm_resource_id', 'price_id'] as $key) {
            if (array_key_exists($key, $reference) && ! $this->validScalar($reference[$key])) {
                return false;
            }
        }

        return ! array_key_exists('project_material_selection', $reference)
            || $this->validProjectMaterialSelection($reference['project_material_selection']);
    }

    private function validProjectMaterialSelection(mixed $selection): bool
    {
        if (! is_array($selection)) {
            return false;
        }
        $allowed = ['version', 'work_item_key', 'assumption_code', 'source_unit_price', 'source_price_unit', 'price_conversion_factor', 'preferred_resource_code', 'selection_policy', 'candidate_pool_version', 'candidate_resource_price_ids'];
        if (array_diff(array_keys($selection), $allowed) !== []) {
            return false;
        }
        foreach ($selection as $key => $value) {
            if ($key === 'candidate_resource_price_ids') {
                if (! is_array($value) || ! array_is_list($value) || ! $this->validScalarList($value)) {
                    return false;
                }
                continue;
            }
            if (! $this->validScalar($value)) {
                return false;
            }
        }

        return true;
    }

    private function validScalarList(mixed $value): bool
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (! $this->validScalar($item)) {
                return false;
            }
        }

        return true;
    }

    private function validScalar(mixed $value): bool
    {
        return is_string($value) || is_int($value) || is_float($value) || is_bool($value) || $value === null;
    }

    /** @param array<int|string, mixed> $value */
    private function containsForbiddenKey(array $value): bool
    {
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array($key, ['draft_payload', 'prompt_payload', 'document_payload', 'prompt', 'documents', 'context', 'raw_response', 'request'], true)) {
                return true;
            }
            if (is_array($item) && $this->containsForbiddenKey($item)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, mixed> $findings */
    private function validFindings(mixed $findings): bool
    {
        if (! is_array($findings) || ! array_is_list($findings)) {
            return false;
        }
        foreach ($findings as $finding) {
            if (! is_array($finding) || array_keys($finding) !== ['scope_key', 'package_keys', 'evidence_refs', 'action', 'reason_code']
                || ! is_string($finding['scope_key']) || ! $this->isPackageKey($finding['scope_key'])
                || ! in_array($finding['action'], ['rebuild', 'review'], true)
                || ! in_array($finding['reason_code'], ['missing_component', 'evidence_required', 'quantity_unconfirmed', 'invalid_response', 'invalid_reference'], true)
                || ! $this->validReferences($finding['package_keys']) || ! $this->validReferences($finding['evidence_refs'])) {
                return false;
            }
        }

        return true;
    }

    /** @param array<int, mixed> $references */
    private function validReferences(mixed $references): bool
    {
        if (! is_array($references) || ! array_is_list($references)) {
            return false;
        }
        $seen = [];
        foreach ($references as $reference) {
            if (! is_string($reference) || ! $this->isPackageKey($reference) || isset($seen[$reference])) {
                return false;
            }
            $seen[$reference] = true;
        }

        return true;
    }

    /** @param array<string, mixed> $cycle */
    private function validCycle(array $cycle): bool
    {
        $keys = array_keys($cycle);
        sort($keys, SORT_STRING);
        if ($keys !== ['attempted', 'input_hash', 'status', 'target_package_keys', 'terminal_outcome']
            || ! is_string($cycle['input_hash']) || ! is_bool($cycle['attempted'])
            || ! is_array($cycle['target_package_keys']) || ! is_string($cycle['status']) || ! is_string($cycle['terminal_outcome'])) {
            return false;
        }
        new ArbiterReviewCycle(
            $cycle['input_hash'],
            $cycle['attempted'],
            $cycle['target_package_keys'],
            $cycle['status'],
            $cycle['terminal_outcome'],
        );

        return true;
    }

    private function isVersion(mixed $value): bool
    {
        return is_string($value) && preg_match('~\A[A-Za-z0-9:._/-]{1,120}\z~', $value) === 1;
    }

    private function isModel(mixed $value): bool
    {
        return is_string($value) && preg_match('~\A[A-Za-z0-9][A-Za-z0-9:._/-]{0,159}\z~', $value) === 1;
    }

    private function isPackageKey(string $value): bool
    {
        return preg_match('/\A[A-Za-z0-9:._-]{1,120}\z/', $value) === 1;
    }
}
