<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Exports;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportPdfDocumentBuilder;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class ReportExportCellProjectionTest extends TestCase
{
    public function test_projects_structured_cells_to_deterministic_canonical_json(): void
    {
        $timezone = new DateTimeZone('UTC');

        self::assertSame(
            '{"a":{"enabled":true,"items":[null,2.0,"text"]},"z":1}',
            ReportPdfDocumentBuilder::normalizeCell([
                'z' => 1,
                'a' => [
                    'items' => [null, 2.0, 'text'],
                    'enabled' => true,
                ],
            ], $timezone),
        );
        self::assertSame(
            '{"a":{"enabled":true,"items":[null,2.0,"text"]},"z":1}',
            ReportPdfDocumentBuilder::normalizeCell([
                'a' => [
                    'enabled' => true,
                    'items' => [null, 2.0, 'text'],
                ],
                'z' => 1,
            ], $timezone),
        );
    }

    public function test_rejects_objects_and_non_finite_structured_values(): void
    {
        $timezone = new DateTimeZone('UTC');

        foreach ([(object) ['id' => 1], ['amount' => INF]] as $value) {
            try {
                ReportPdfDocumentBuilder::normalizeCell($value, $timezone);
                self::fail('Expected unsupported export cell to fail closed.');
            } catch (ReportContractException $exception) {
                self::assertSame(ReportErrorCode::REPORT_EXPORT_LIMIT_EXCEEDED, $exception->errorCode);
            }
        }
    }
}
