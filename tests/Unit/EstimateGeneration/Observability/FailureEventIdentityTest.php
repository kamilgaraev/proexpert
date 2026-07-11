<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationDocumentJob;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class FailureEventIdentityTest extends TestCase
{
    #[Test]
    #[DataProvider('jobs')]
    public function queued_job_event_is_stable_on_redelivery_and_fresh_for_new_dispatch(object $first, object $second): void
    {
        $redelivered = unserialize(serialize($first));

        self::assertSame($this->eventId($first), $this->eventId($redelivered));
        self::assertNotSame($this->eventId($first), $this->eventId($second));
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $this->eventId($first));
    }

    /** @return iterable<string, array{object, object}> */
    public static function jobs(): iterable
    {
        yield 'generation' => [new GenerateEstimateDraftJob(10, 2, 'attempt-a'), new GenerateEstimateDraftJob(10, 2, 'attempt-a')];
        yield 'document' => [new ProcessEstimateGenerationDocumentJob(20), new ProcessEstimateGenerationDocumentJob(20)];
    }

    private function eventId(object $job): string
    {
        $property = new ReflectionProperty($job, 'failureEventId');

        return (string) $property->getValue($job);
    }
}
