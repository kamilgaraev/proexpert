<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceReadiness;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSourceReadinessStatus;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class ReportSourceReadinessFactory
{
    public function make(
        array $eligible,
        array $projected,
        int $gapCount,
        int $unknownCount,
        string $watermark,
    ): ReportSourceReadiness {
        if (! array_is_list($eligible)
            || ! array_is_list($projected)
            || min($gapCount, $unknownCount) < 0
            || count($projected) + $gapCount + $unknownCount !== count($eligible)
        ) {
            throw new InvalidArgumentException('report_source_readiness_measurement_invalid');
        }

        $status = $gapCount === 0 && $unknownCount === 0
            ? ReportSourceReadinessStatus::READY
            : (count($projected) === 0
                ? ReportSourceReadinessStatus::UNAVAILABLE
                : ReportSourceReadinessStatus::PARTIAL);

        return new ReportSourceReadiness(
            status: $status,
            eligibleCount: count($eligible),
            projectedCount: count($projected),
            gapCount: $gapCount,
            unknownCount: $unknownCount,
            watermark: $watermark,
            inputHash: hash('sha256', CanonicalJson::encode($eligible)),
            outputHash: hash('sha256', CanonicalJson::encode($projected)),
            verifiedAt: CarbonImmutable::now(),
        );
    }
}
