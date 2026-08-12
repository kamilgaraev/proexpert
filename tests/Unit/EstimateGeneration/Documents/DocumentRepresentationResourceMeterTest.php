<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentRepresentationResourceMeter;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\SystemDocumentRepresentationResourceMeter;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\DatabaseLessTestCase;

final class DocumentRepresentationResourceMeterTest extends DatabaseLessTestCase
{
    #[Test]
    public function deterministic_meter_reports_elapsed_time_and_incremental_process_peak(): void
    {
        $times = [1_000_000_000, 1_004_200_000];
        $peaks = [1000, 1450];
        $meter = new SystemDocumentRepresentationResourceMeter(
            static function () use (&$times): int {
                return array_shift($times);
            },
            static function () use (&$peaks): int {
                return array_shift($peaks);
            },
        );

        $measurement = $meter->measure(static fn (): array => ['unit']);

        self::assertSame(['unit'], $measurement->result);
        self::assertSame(5, $measurement->durationMs);
        self::assertSame(450, $measurement->incrementalPeakMemoryBytes);
        self::assertSame([], $measurement->limitations);
        self::assertSame('incremental_process_peak_delta', $measurement->memoryMetric);
    }

    #[Test]
    public function unchanged_process_peak_is_explicitly_unavailable_not_a_default_success(): void
    {
        $times = [10, 10];
        $peaks = [2048, 2048];
        $meter = new SystemDocumentRepresentationResourceMeter(
            static function () use (&$times): int {
                return array_shift($times);
            },
            static function () use (&$peaks): int {
                return array_shift($peaks);
            },
        );

        $measurement = $meter->measure(static fn (): array => []);

        self::assertSame(0, $measurement->durationMs);
        self::assertSame(0, $measurement->incrementalPeakMemoryBytes);
        self::assertEqualsCanonicalizing(
            ['duration_resolution_not_observed', 'incremental_process_peak_not_observed'],
            $measurement->limitations,
        );
    }

    #[Test]
    public function failed_operation_keeps_exception_and_resource_measurement(): void
    {
        $times = [2_000_000_000, 2_007_100_000];
        $peaks = [4096, 5120];
        $meter = new SystemDocumentRepresentationResourceMeter(
            static function () use (&$times): int {
                return array_shift($times);
            },
            static function () use (&$peaks): int {
                return array_shift($peaks);
            },
        );

        try {
            $meter->measure(static function (): never {
                throw new RuntimeException('measured failure');
            });
            self::fail('The measured operation must fail.');
        } catch (RuntimeException $exception) {
            self::assertSame('measured failure', $exception->getPrevious()?->getMessage());
            self::assertObjectHasProperty('measurement', $exception);
            self::assertSame(8, $exception->measurement->durationMs);
            self::assertSame(1024, $exception->measurement->incrementalPeakMemoryBytes);
        }
    }

    #[Test]
    public function production_container_binds_the_system_meter(): void
    {
        self::assertInstanceOf(
            SystemDocumentRepresentationResourceMeter::class,
            app(DocumentRepresentationResourceMeter::class),
        );
    }
}
