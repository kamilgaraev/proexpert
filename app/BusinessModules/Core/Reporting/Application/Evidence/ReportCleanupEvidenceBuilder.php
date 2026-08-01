<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Evidence;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCleanupEvidence;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class ReportCleanupEvidenceBuilder
{
    private const CHECK_IDS = [
        'cleanup.cutover_pair',
        'cleanup.rollback_window',
        'cleanup.legacy_route_aliases',
        'cleanup.legacy_direct_callers',
        'cleanup.qg14_forbidden_symbols',
        'cleanup.policy_lock',
    ];

    public function build(
        string $releaseSha,
        string $activationCommitSha,
        string $cutoverCommitSha,
        string $producerCommitSha,
        DateTimeImmutable $cutoverAt,
        DateTimeImmutable $generatedAt,
        array $evidenceHashes,
    ): ReportCleanupEvidence {
        $eligibleAt = $cutoverAt->setTimezone(new DateTimeZone('UTC'))->modify('+604800 seconds');
        if ($generatedAt->setTimezone(new DateTimeZone('UTC')) < $eligibleAt) {
            throw new InvalidArgumentException('REPORT_CLEANUP_WINDOW_NOT_ELAPSED');
        }

        return new ReportCleanupEvidence(
            'report_cleanup_evidence',
            '1.0.0',
            'cleanup_verified',
            'external_read_only',
            $releaseSha,
            $activationCommitSha,
            $cutoverCommitSha,
            $producerCommitSha,
            604800,
            $eligibleAt,
            $generatedAt->setTimezone(new DateTimeZone('UTC')),
            self::CHECK_IDS,
            $evidenceHashes,
        );
    }
}
