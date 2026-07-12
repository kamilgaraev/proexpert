<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Training;

use App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationTrainingDatasetJob;
use PHPUnit\Framework\TestCase;

final class ProcessTrainingDatasetJobContractTest extends TestCase
{
    public function test_releases_do_not_exhaust_attempts_and_retry_deadline_is_serialized_once(): void
    {
        $deadline = new \DateTimeImmutable('2030-01-02T03:04:05+00:00');
        $job = new ProcessEstimateGenerationTrainingDatasetJob(42, $deadline);

        $restored = unserialize(serialize($job));

        self::assertInstanceOf(ProcessEstimateGenerationTrainingDatasetJob::class, $restored);
        self::assertSame(0, $restored->tries);
        self::assertSame(8, $restored->maxExceptions);
        self::assertSame($deadline->format(DATE_ATOM), $restored->retryUntil()->format(DATE_ATOM));
        self::assertSame('42', $restored->uniqueId());
    }
}
