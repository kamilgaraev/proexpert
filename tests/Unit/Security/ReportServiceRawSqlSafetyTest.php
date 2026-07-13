<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use PHPUnit\Framework\TestCase;

final class ReportServiceRawSqlSafetyTest extends TestCase
{
    public function testReportServiceCastsProjectIdBeforeRawSqlInterpolation(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../app/Services/Report/ReportService.php');

        self::assertStringContainsString(
            '$projectId = $request->filled(\'project_id\') ? (int) $request->query(\'project_id\') : null;',
            $source
        );

        self::assertStringNotContainsString(
            '$projectId = $request->filled(\'project_id\') ? $request->query(\'project_id\') : null;',
            $source
        );
    }
}
