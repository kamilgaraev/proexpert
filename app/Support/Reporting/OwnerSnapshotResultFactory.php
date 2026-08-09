<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCoverage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProvenance;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuality;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResultMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWarning;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportWarningSeverity;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;

final readonly class OwnerSnapshotResultFactory
{
    public function snapshot(
        string $kind,
        string $id,
        ReportScope $scope,
        Sha256Hash $definitionHash,
        string $formulaVersion,
        string $sourceHash,
        DateTimeImmutable $generatedAt,
        ?DateTimeImmutable $staleAt,
        array $watermarks,
    ): ReportSnapshotRef {
        return new ReportSnapshotRef(
            $kind,
            $id,
            $scope,
            $definitionHash,
            $formulaVersion,
            new Sha256Hash($sourceHash),
            $generatedAt,
            $staleAt,
            $watermarks,
            ReportSnapshotClassification::OPERATIONAL,
            null,
        );
    }

    public function result(
        ReportSnapshotRef $snapshot,
        int $rowCount,
        int $gapCount,
        array $totals,
        string $source,
        string $sourceSchemaVersion,
        string $watermark,
        array $rowSchema,
        array $capabilities,
        ReportReconciliationStatus $reconciliation,
    ): ReportResult {
        $qualityStatus = $gapCount === 0 ? ReportQualityStatus::COMPLETE : ReportQualityStatus::PARTIAL;
        $covered = max(0, $rowCount - $gapCount);
        $coverage = new ReportCoverage(
            (string) $covered,
            (string) $rowCount,
            $rowCount === 0
                ? null
                : (string) BigDecimal::of($covered)->dividedBy(
                    BigDecimal::of($rowCount),
                    8,
                    RoundingMode::HalfUp,
                ),
        );
        $quality = new ReportQuality(
            $qualityStatus,
            $coverage,
            $gapCount === 0 ? [] : [
                new ReportWarning('SOURCE_GAPS', ReportWarningSeverity::CRITICAL, null, $gapCount),
            ],
            $gapCount,
            $reconciliation,
            $gapCount === 0 ? [] : ['source_coverage'],
            [],
        );
        $freshness = $qualityStatus !== ReportQualityStatus::COMPLETE
            ? ReportFreshnessStatus::PARTIAL
            : ($snapshot->staleAt !== null && $snapshot->staleAt <= new DateTimeImmutable
                ? ReportFreshnessStatus::STALE
                : ReportFreshnessStatus::FRESH);
        $sourceRef = new ReportSourceRef(
            $source,
            $snapshot->kind,
            'snapshot_'.strtolower($snapshot->id),
            $sourceSchemaVersion,
            self::watermarkIdentifier($watermark),
            $rowCount,
            $snapshot->sourceHash,
        );

        return new ReportResult(
            new ReportResultMetadata($snapshot, $rowCount, $snapshot->generatedAt, $snapshot->staleAt),
            $totals,
            $freshness,
            $quality,
            new ReportProvenance($source, [$sourceRef], $snapshot->sourceHash, null),
            $rowSchema,
            $capabilities,
        );
    }

    private static function watermarkIdentifier(string $watermark): string
    {
        return 'watermark_'.substr(hash('sha256', $watermark), 0, 32);
    }
}
