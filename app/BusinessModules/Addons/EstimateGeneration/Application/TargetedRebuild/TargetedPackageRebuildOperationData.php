<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild;

use DateTimeImmutable;
use InvalidArgumentException;

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
            || (($leaseToken === null) !== ($leaseExpiresAt === null))) {
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
        if (! $this->validResultDelta($resultDelta) || ! $this->validSafeReview($safeArbiterReview)) {
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

    private function isHash(string $value): bool
    {
        return preg_match('/\Asha256:[a-f0-9]{64}\z/', $value) === 1;
    }

    /** @param array<string, mixed> $resultDelta */
    private function validResultDelta(array $resultDelta): bool
    {
        $expected = ['target_package', 'target_before_fingerprint', 'target_after_fingerprint', 'non_target_fingerprints'];
        if (array_keys($resultDelta) !== $expected
            || ! is_array($resultDelta['target_package'])
            || ($resultDelta['target_package']['key'] ?? null) !== $this->packageKey
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

        return $review !== []
            && array_diff(array_keys($review), $allowed) === []
            && ($review['mode'] ?? null) === 'shadow'
            && in_array($review['status'] ?? null, ['reviewed', 'unavailable'], true)
            && in_array($review['outcome'] ?? null, ['passed', 'confirmed_scope_only', 'human_review'], true);
    }
}
