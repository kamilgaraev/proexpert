<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ReportCleanupEvidence
{
    public function __construct(
        public string $artifactId,
        public string $schemaVersion,
        public string $status,
        public string $verificationMode,
        public string $releaseSha,
        public string $activationCommitSha,
        public string $cutoverCommitSha,
        public string $producerCommitSha,
        public int $rollbackWindowSeconds,
        public DateTimeImmutable $eligibleAt,
        public DateTimeImmutable $generatedAt,
        public array $checkIds,
        public array $evidenceHashes,
    ) {
        if ($artifactId !== 'report_cleanup_evidence'
            || $schemaVersion !== '1.0.0'
            || $status !== 'cleanup_verified'
            || $verificationMode !== 'external_read_only'
            || $rollbackWindowSeconds !== 604800
            || $checkIds !== [
                'cleanup.cutover_pair',
                'cleanup.rollback_window',
                'cleanup.legacy_route_aliases',
                'cleanup.legacy_direct_callers',
                'cleanup.qg14_forbidden_symbols',
                'cleanup.policy_lock',
            ]
            || count($evidenceHashes) !== 6
            || $generatedAt < $eligibleAt) {
            throw new InvalidArgumentException('report_cleanup_evidence_invalid');
        }
        foreach ([$releaseSha, $activationCommitSha, $cutoverCommitSha, $producerCommitSha] as $sha) {
            if (preg_match('/^[a-f0-9]{40}$/', $sha) !== 1) {
                throw new InvalidArgumentException('report_cleanup_evidence_invalid');
            }
        }
        foreach ($evidenceHashes as $hash) {
            if (! is_string($hash) || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                throw new InvalidArgumentException('report_cleanup_evidence_invalid');
            }
        }
    }
}
