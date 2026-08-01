<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Execution;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportAsyncContextSeedReader;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportAsyncContextSeed;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\Reporting\ReportDefinitionBuilder;

final class ReportExportAsyncContextSeedReaderContractTest extends TestCase
{
    public function test_shared_seed_remains_the_closed_task_five_run_seed(): void
    {
        $scope = new ReportScope(7, [7], [11], [], new DateTimeZone('UTC'));
        $definition = (new ReportDefinitionBuilder)->payload();

        self::assertSame('run', (new ReportAsyncContextSeed(
            'run',
            '01J00000000000000000000000',
            7,
            17,
            $scope,
            $definition,
            null,
        ))->aggregateKind);
        $this->expectException(\InvalidArgumentException::class);
        new ReportAsyncContextSeed(
            'export',
            '01J00000000000000000000002',
            7,
            17,
            $scope,
            $definition,
            null,
        );
    }

    public function test_reader_surface_returns_the_closed_shared_seed(): void
    {
        $method = (new ReflectionClass(ReportExportAsyncContextSeedReader::class))->getMethod('forExport');

        self::assertSame(ReportAsyncContextSeed::class, $method->getReturnType()?->getName());
        self::assertSame(['exportId'], array_map(
            static fn ($parameter): string => $parameter->getName(),
            $method->getParameters(),
        ));
    }
}
