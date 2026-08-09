<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Models\InventoryRiskSnapshot;
use App\BusinessModules\Features\Procurement\Reporting\Award\Models\SupplierAwardSnapshot;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Models\ProcurementCycleSnapshot;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Models\SupplyReliabilitySnapshot;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReportingSnapshotDateCastTest extends TestCase
{
    #[Test]
    #[DataProvider('snapshotModels')]
    public function snapshot_as_of_is_an_immutable_date(string $modelClass): void
    {
        $snapshot = new $modelClass(['as_of' => '2026-08-09T00:00:00+00:00']);

        self::assertInstanceOf(CarbonImmutable::class, $snapshot->as_of);
        self::assertSame('2026-08-09T00:00:00+00:00', $snapshot->as_of->toAtomString());
    }

    public static function snapshotModels(): array
    {
        return [
            'procurement cycle' => [ProcurementCycleSnapshot::class],
            'supplier award' => [SupplierAwardSnapshot::class],
            'supply reliability' => [SupplyReliabilitySnapshot::class],
            'inventory risk' => [InventoryRiskSnapshot::class],
        ];
    }
}
