<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Readiness;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Services\InventoryRiskPeriod;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\ReportDefinitionBuilder;

final class InventoryRiskPeriodTest extends TestCase
{
    #[DataProvider('cutoffs')]
    public function test_cutoff_uses_the_last_local_microsecond_and_never_exceeds_as_of(string $day, string $timezone, string $asOf, string $expected): void
    {
        $query = $this->query(['period_start' => $day, 'period_end' => $day], $timezone, $asOf);

        self::assertSame($expected, (new InventoryRiskPeriod)->cutoff($query)->format('Y-m-d\TH:i:s.u\Z'));
    }

    public static function cutoffs(): array
    {
        return [
            ['2026-08-09', 'UTC', '2026-08-10T00:00:00Z', '2026-08-09T23:59:59.999999Z'],
            ['2026-08-09', 'Europe/Moscow', '2026-08-10T00:00:00Z', '2026-08-09T20:59:59.999999Z'],
            ['2026-08-09', 'Europe/Moscow', '2026-08-09T10:11:12.123456Z', '2026-08-09T10:11:12.123456Z'],
            ['2026-03-29', 'Europe/Berlin', '2026-03-30T00:00:00Z', '2026-03-29T21:59:59.999999Z'],
            ['2026-08-09', 'UTC', '2026-08-09T23:59:59.999999Z', '2026-08-09T23:59:59.999999Z'],
        ];
    }

    #[DataProvider('invalidPeriods')]
    public function test_invalid_period_returns_the_safe_request_error(array $filters): void
    {
        try {
            (new InventoryRiskPeriod)->cutoff($this->query($filters, 'UTC', '2026-08-10T00:00:00Z'));
            self::fail('An invalid period must be rejected.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_REQUEST_INVALID, $exception->errorCode);
            self::assertSame(['fields' => ['filters']], $exception->safeFields);
        }
    }

    public static function invalidPeriods(): array
    {
        return [
            [[]],
            [['period_start' => '2026-02-30', 'period_end' => '2026-03-01']],
            [['period_start' => '2026-08-10', 'period_end' => '2026-08-09']],
            [['period_start' => 20260809, 'period_end' => '2026-08-09']],
        ];
    }

    private function query(array $filters, string $timezone, string $asOf): ReportQuery
    {
        return new ReportQuery(
            (new ReportDefinitionBuilder)->code('inventory_risk')->payload(),
            new ReportScope(1, [1], [], [], new DateTimeZone($timezone)),
            new ReportFilterSet($filters), [], new DateTimeImmutable($asOf), 'ru',
        );
    }
}
