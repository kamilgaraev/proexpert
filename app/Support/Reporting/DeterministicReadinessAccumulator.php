<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceReadiness;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSourceReadinessStatus;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class DeterministicReadinessAccumulator
{
    private DeterministicObjectSpool $eligible;

    private DeterministicObjectSpool $projected;

    public function __construct()
    {
        $this->eligible = new DeterministicObjectSpool;
        $this->projected = new DeterministicObjectSpool;
    }

    public function eligible(array $identity): void
    {
        $this->eligible->append((object) $identity, $identity);
    }

    public function projected(array $identity): void
    {
        $this->projected->append((object) $identity, $identity);
    }

    public function finish(
        int $gapCount,
        int $unknownCount,
        string $watermark,
    ): ReportSourceReadiness {
        $eligibleCount = $this->eligible->count();
        $projectedCount = $this->projected->count();
        if ($projectedCount + $gapCount + $unknownCount !== $eligibleCount) {
            throw new InvalidArgumentException('report_source_readiness_measurement_invalid');
        }
        $status = $gapCount === 0 && $unknownCount === 0
            ? ReportSourceReadinessStatus::READY
            : ($projectedCount === 0
                ? ReportSourceReadinessStatus::UNAVAILABLE
                : ReportSourceReadinessStatus::PARTIAL);

        return new ReportSourceReadiness(
            $status,
            $eligibleCount,
            $projectedCount,
            $gapCount,
            $unknownCount,
            $watermark,
            $this->eligible->sha256(),
            $this->projected->sha256(),
            CarbonImmutable::now(),
        );
    }
}
