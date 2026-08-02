<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Errors;

use PHPUnit\Framework\TestCase;

final class ReportContractExceptionFallbackTest extends TestCase
{
    public function test_reporting_contract_exception_has_global_admin_api_fallback(): void
    {
        $bootstrap = file_get_contents(dirname(__DIR__, 4).'/bootstrap/app.php');

        self::assertIsString($bootstrap);
        self::assertStringContainsString('function (ReportContractException $exception, Request $request)', $bootstrap);
        self::assertStringContainsString("str_starts_with(\$request->path(), 'api/v1/admin/reports/')", $bootstrap);
        self::assertStringContainsString('app(ReportErrorResponseFactory::class)->make(', $bootstrap);
    }
}
