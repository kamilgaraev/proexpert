<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting\Persistence;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportStore;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class EloquentReportExportStoreTest extends TestCase
{
    public function test_export_store_contract_has_the_closed_seven_method_surface(): void
    {
        $methods = array_map(static fn ($method): string => $method->getName(), (new ReflectionClass(ReportExportStore::class))->getMethods());
        sort($methods);

        self::assertSame([
            'cancel',
            'createOrReuse',
            'fail',
            'get',
            'sealReady',
            'startRendering',
            'startUploading',
        ], $methods);
    }
}
