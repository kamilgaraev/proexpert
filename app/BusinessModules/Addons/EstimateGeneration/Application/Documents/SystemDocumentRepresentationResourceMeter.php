<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use Closure;
use Throwable;

final class SystemDocumentRepresentationResourceMeter implements DocumentRepresentationResourceMeter
{
    private Closure $monotonicNanoseconds;

    private Closure $processPeakBytes;

    public function __construct(?callable $monotonicNanoseconds = null, ?callable $processPeakBytes = null)
    {
        $this->monotonicNanoseconds = Closure::fromCallable(
            $monotonicNanoseconds ?? static fn (): int => hrtime(true),
        );
        $this->processPeakBytes = Closure::fromCallable(
            $processPeakBytes ?? static fn (): int => memory_get_peak_usage(true),
        );
    }

    public function measure(callable $operation): DocumentRepresentationMeasurement
    {
        $startedAt = ($this->monotonicNanoseconds)();
        $peakBefore = ($this->processPeakBytes)();
        try {
            $result = $operation();
        } catch (Throwable $exception) {
            throw new DocumentRepresentationMeasurementException(
                $this->measurement(null, $startedAt, $peakBefore),
                $exception,
            );
        }

        return $this->measurement($result, $startedAt, $peakBefore);
    }

    private function measurement(mixed $result, int $startedAt, int $peakBefore): DocumentRepresentationMeasurement
    {
        $peakAfter = ($this->processPeakBytes)();
        $finishedAt = ($this->monotonicNanoseconds)();
        $elapsed = max(0, $finishedAt - $startedAt);
        $durationMs = $elapsed === 0 ? 0 : (int) ceil($elapsed / 1_000_000);
        $incrementalPeak = max(0, $peakAfter - $peakBefore);
        $limitations = [];
        if ($durationMs === 0) {
            $limitations[] = 'duration_resolution_not_observed';
        }
        if ($incrementalPeak === 0) {
            $limitations[] = 'incremental_process_peak_not_observed';
        }

        return new DocumentRepresentationMeasurement(
            $result,
            $durationMs,
            $incrementalPeak,
            $limitations,
        );
    }
}
