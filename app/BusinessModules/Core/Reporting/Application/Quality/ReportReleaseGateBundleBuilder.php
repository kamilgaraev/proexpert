<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Quality;

use App\BusinessModules\Core\Reporting\Domain\DTO\JointQG14Evidence;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQualityGateEvidence;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportReleaseGateBundle;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityEvidencePhase;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityEvidenceStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityGateFailureCode;
use DateTimeImmutable;

final class ReportReleaseGateBundleBuilder
{
    private const OWNERS = [
        'backend', 'backend', 'backend', 'backend', 'backend', 'backend', 'backend', 'backend', 'backend',
        'admin', 'admin', 'admin', 'admin', 'both',
    ];

    private const EXACT_COUNTS = [1 => 28, 2 => 56, 4 => 28, 5 => 28, 6 => 46, 8 => 28, 10 => 28, 12 => 25, 13 => 3, 14 => 0];

    /** @param list<ReportQualityGateEvidence> $gates */
    public function build(array $gates, JointQG14Evidence $qg14Evidence, string $releaseSha, array $sources, DateTimeImmutable $generatedAt): ReportReleaseGateBundle
    {
        if (preg_match('/^[a-f0-9]{40}$/', $releaseSha) !== 1 || ! array_is_list($gates) || count($gates) !== 14) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::CATALOG_COUNT_MISMATCH);
        }

        foreach ($gates as $index => $gate) {
            $number = $index + 1;
            if (! $gate instanceof ReportQualityGateEvidence
                || $gate->gate !== sprintf('QG-%02d', $number)
                || $gate->phase !== ReportQualityEvidencePhase::RELEASE
                || $gate->status !== ReportQualityEvidenceStatus::PASSED
                || $gate->releaseSha !== $releaseSha
                || $gate->ownerPlan !== self::OWNERS[$index]
                || (isset(self::EXACT_COUNTS[$number]) && $gate->count !== self::EXACT_COUNTS[$number])
                || ($number === 3 && $gate->count < 500)
                || ($number === 11 && $gate->count < 252)) {
                throw new ReportQualityGateException(ReportQualityGateFailureCode::PHASE_INCOMPLETE);
            }
        }

        $qg14 = $gates[13];
        if ($qg14->command !== $qg14Evidence->commandId || $qg14->count !== $qg14Evidence->combinedForbiddenSymbolMatches) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::COMMAND_COUNT_MISMATCH);
        }

        return new ReportReleaseGateBundle(
            'report_release_gate_bundle',
            'release_gates_passed',
            $releaseSha,
            $gates,
            $sources,
            $generatedAt,
            ['backend' => 9, 'admin' => 4, 'joint' => 1],
        );
    }
}
