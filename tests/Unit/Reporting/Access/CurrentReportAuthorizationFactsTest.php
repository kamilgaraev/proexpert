<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Access;

use App\BusinessModules\Core\Reporting\Application\Access\CurrentReportAuthorizationFacts;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CurrentReportAuthorizationFactsTest extends TestCase
{
    public function test_facts_are_queue_only_and_resource_project_must_match(): void
    {
        $resource = new ReportScopedResource('task', 4, 8);
        $facts = new CurrentReportAuthorizationFacts('queue', 2, 3, 8, $resource, new DateTimeImmutable);
        self::assertSame($resource, $facts->resource);

        foreach ([
            ['http', 2, 3, 8, $resource],
            ['queue', 0, 3, 8, $resource],
            ['queue', 2, 3, 9, $resource],
        ] as $input) {
            try {
                new CurrentReportAuthorizationFacts(...[...$input, new DateTimeImmutable]);
                self::fail('Invalid facts accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
