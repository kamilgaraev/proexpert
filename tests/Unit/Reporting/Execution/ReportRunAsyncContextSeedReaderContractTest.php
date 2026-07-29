<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Execution;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunAsyncContextSeedReader;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportAsyncContextSeed;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\Reporting\ReportDefinitionBuilder;

final class ReportRunAsyncContextSeedReaderContractTest extends TestCase
{
    public function test_seed_is_a_closed_authority_free_projection(): void
    {
        $scope = new ReportScope(7, [7], [11], [], new DateTimeZone('UTC'));
        $definition = (new ReportDefinitionBuilder)->payload();
        $seed = new ReportAsyncContextSeed('run', '01J00000000000000000000000', 7, 17, $scope, $definition, 'lineage');

        self::assertSame(
            ['aggregateKind', 'aggregateId', 'organizationId', 'requesterActorId', 'requestedScope', 'definition', 'correlationLineageId'],
            array_keys(get_object_vars($seed)),
        );
        self::assertSame($scope, $seed->requestedScope);
        try {
            new ReportAsyncContextSeed('export', '01J00000000000000000000001', 7, 17, $scope, $definition, null);
            self::fail('Run context seed must reject export aggregates.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('report_async_context_seed_invalid', $exception->getMessage());
        }

        $method = (new ReflectionClass(ReportRunAsyncContextSeedReader::class))->getMethod('forRun');
        self::assertSame(ReportAsyncContextSeed::class, $method->getReturnType()?->getName());
    }
}
