<?php

declare(strict_types=1);

namespace Tests\Unit\Acting;

use App\BusinessModules\Features\BudgetEstimates\Services\Export\OfficialFormsExportService;
use App\Models\Contract;
use App\Models\ContractParty;
use App\Models\ContractPerformanceAct;
use App\Models\Estimate;
use App\Models\PerformanceActLine;
use App\Services\Acting\ActingPriceService;
use Illuminate\Database\Eloquent\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class OfficialFormsSnapshotRegressionTest extends TestCase
{
    public function test_ks2_uses_stored_vat_after_estimate_rate_changes(): void
    {
        $sheet = $this->renderItems('20.00');

        self::assertEquals(20, $sheet->getCell('G4')->getValue());
        self::assertEquals(120, $sheet->getCell('G5')->getValue());
        self::assertSame('02.01', $sheet->getCell('B2')->getValue());
    }

    public function test_zero_vat_snapshot_is_not_replaced_with_live_estimate_tax(): void
    {
        $sheet = $this->renderItems('0.00');

        self::assertEquals(0, $sheet->getCell('G4')->getValue());
        self::assertEquals(120, $sheet->getCell('G5')->getValue());
    }

    public function test_subcontract_export_uses_frozen_contract_parties(): void
    {
        $contract = new Contract;
        $first = new ContractParty;
        $first->setRawAttributes(['name' => 'Подрядчик договора', 'legal_name' => 'ООО Подрядчик', 'inn' => '7700000001', 'legal_address' => 'Адрес подрядчика']);
        $second = new ContractParty;
        $second->setRawAttributes(['name' => 'Субподрядчик', 'inn' => '7700000002', 'legal_address' => 'Адрес субподрядчика']);
        $contract->setRelation('firstParty', $first);
        $contract->setRelation('secondParty', $second);
        $contract->setRelation('project', (object) ['organization' => (object) ['name' => 'Заказчик всего объекта']]);
        $contract->setRelation('organization', (object) ['name' => 'Изменённое название']);
        $contract->setRelation('contractor', (object) ['name' => 'Изменённый контрагент']);
        $reflection = new ReflectionClass(OfficialFormsExportService::class);
        [$customer, $contractor] = $reflection->getMethod('exportParties')->invoke($reflection->newInstanceWithoutConstructor(), $contract);

        self::assertSame('ООО Подрядчик', $customer->legal_name);
        self::assertSame('7700000001', $customer->tax_number);
        self::assertSame('Субподрядчик', $contractor->name);
        self::assertSame('7700000002', $contractor->inn);
    }

    public function test_contract_condition_basis_captures_estimate_position_for_export(): void
    {
        $contract = new Contract;
        $contract->setRawAttributes(['id' => 1, 'organization_id' => 1, 'project_id' => 1, 'currency' => 'RUB']);
        $estimate = new Estimate;
        $estimate->setRawAttributes(['id' => 1, 'organization_id' => 1, 'project_id' => 1]);
        $item = new \App\Models\EstimateItem;
        $item->setRawAttributes(['id' => 3, 'position_number' => '02.01', 'name' => 'Работа', 'normative_rate_code' => 'ГЭСН-01']);
        $item->setRelation('estimate', $estimate);
        $allocation = new \App\Models\EstimateFinanceAllocation;
        $allocation->setRawAttributes(['id' => 1, 'key' => 'allocation-1', 'organization_id' => 1, 'estimate_id' => 1, 'contract_id' => 1,
            'quantity' => 10, 'amount_without_vat' => 100, 'amount_with_vat' => 120, 'vat_mode' => 'included', 'vat_rate' => 20,
            'composition_confirmed' => true, 'currency' => 'RUB',
        ]);
        $item->setRelation('financeAllocations', new Collection([$allocation]));
        $basis = (new \App\Services\Acting\PerformanceActContractBasisService)->resolve($item, $contract);
        $item->position_number = '99';

        self::assertSame('02.01', $basis['snapshot']['estimate_item']['position_number'] ?? null);
        self::assertSame('ГЭСН-01', $basis['snapshot']['estimate_item']['normative_rate_code'] ?? null);
        self::assertSame('12.00', $basis['unit_price']);
    }

    public function test_ks3_keeps_cumulative_values_per_line_without_repeating_total(): void
    {
        $reflection = new ReflectionClass(OfficialFormsExportService::class);
        $export = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('applyKS3LineAggregates');

        $lines = new Collection([
            ['key' => 'estimate:1', 'title' => 'Первая', 'amount' => 60],
            ['key' => 'estimate:2', 'title' => 'Вторая', 'amount' => 40],
        ]);
        $result = $method->invoke($export, $lines, [
            'lines' => [
                'estimate:1' => ['from_start' => 100, 'year_total' => 60],
                'estimate:2' => ['from_start' => 50, 'year_total' => 40],
            ],
        ]);

        self::assertSame([100.0, 50.0], $result->pluck('from_start')->all());
        self::assertSame([60.0, 40.0], $result->pluck('year_total')->all());
        self::assertSame(150.0, $result->sum('from_start'));
    }

    public function test_ks3_historical_only_line_has_zero_current_period_amount(): void
    {
        $reflection = new ReflectionClass(OfficialFormsExportService::class);
        $export = $reflection->newInstanceWithoutConstructor();
        $result = $reflection->getMethod('applyKS3LineAggregates')->invoke($export, new Collection, [
            'lines' => [
                'estimate:1' => [
                    'line' => ['key' => 'estimate:1', 'title' => 'Ранее выполненная работа', 'quantity' => 5, 'amount' => 100],
                    'from_start' => 100,
                    'year_total' => 100,
                ],
            ],
        ]);

        self::assertSame(0.0, $result->first()['amount']);
        self::assertSame(0.0, $result->first()['quantity']);
        self::assertSame(100.0, $result->first()['from_start']);
    }

    public function test_ks3_excel_writes_per_line_cumulative_cells(): void
    {
        $service = new class extends OfficialFormsExportService
        {
            public function __construct() {}

            protected function ks3LineAggregates(ContractPerformanceAct $act): array
            {
                return [
                    'lines' => [
                        'estimate:1' => ['from_start' => 100, 'year_total' => 60],
                        'estimate:2' => ['from_start' => 50, 'year_total' => 40],
                    ],
                    'year_total' => 100,
                    'total_from_start' => 150,
                ];
            }
        };
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('setKS3Items');

        $contract = new Contract;
        $contract->setRelation('estimate', null);
        $act = new ContractPerformanceAct;
        $act->setRawAttributes([
            'amount' => 100,
            'vat_amount' => 20,
            'act_date' => '2026-03-31',
        ]);
        $act->setRelation('contract', $contract);
        $lines = new Collection;
        foreach ([['estimate:1', 'Первая', 60], ['estimate:2', 'Вторая', 40]] as $index => [$key, $title, $amount]) {
            $line = new PerformanceActLine;
            $line->setRawAttributes(['id' => $index + 1, 'amount' => $amount, 'quantity' => 1, 'title' => $title]);
            $line->setRelation('estimateItem', (object) ['id' => $index + 1, 'workType' => null, 'measurementUnit' => null]);
            $line->setRelation('completedWork', null);
            $lines->push($line);
        }
        $act->setRelation('lines', $lines);
        $act->setRelation('completedWorks', new Collection);

        $sheet = (new Spreadsheet)->getActiveSheet();
        $method->invoke($service, $sheet, $act);

        $found = [];
        for ($row = 1; $row <= $sheet->getHighestRow(); $row++) {
            $title = $sheet->getCell("B{$row}")->getValue();
            if (is_string($title) && in_array($title, ['Первая', 'Вторая'], true)) {
                $found[$title] = [$sheet->getCell("D{$row}")->getValue(), $sheet->getCell("E{$row}")->getValue()];
            }
        }

        self::assertSame([[100.0, 60.0], [50.0, 40.0]], array_values($found));
    }

    public function test_ks6a_rows_keep_three_months_across_year_boundary_and_cumulative_amounts(): void
    {
        $reflection = new ReflectionClass(OfficialFormsExportService::class);
        $export = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('buildKS6aRows');

        $contract = new Contract;
        $contract->setRelation('contractEstimateItems', new Collection);
        $acts = new Collection;
        $estimateItem = (object) [
            'id' => 1,
            'name' => 'Работа',
            'quantity_total' => 100,
            'quantity' => 100,
            'total_amount' => 100,
            'current_total_amount' => 100,
            'measurementUnit' => null,
            'workType' => null,
        ];
        foreach ([['2025-12-01', 10], ['2026-01-01', 20], ['2026-02-01', 30]] as $index => [$date, $amount]) {
            $line = new PerformanceActLine;
            $line->setRawAttributes(['id' => $index + 1, 'quantity' => $amount, 'amount' => $amount, 'title' => 'Работа']);
            $line->setRelation('estimateItem', $estimateItem);
            $line->setRelation('completedWork', null);
            $act = new ContractPerformanceAct;
            $act->setRawAttributes(['id' => $index + 1, 'act_date' => '2026-04-15', 'period_end' => $date, 'is_approved' => true]);
            $act->setRelation('lines', new Collection([$line]));
            $act->setRelation('completedWorks', new Collection);
            $acts->push($act);
        }

        $months = new Collection(['2025-12', '2026-01', '2026-02']);
        $rows = $method->invoke($export, $contract, $acts, $months, $months);
        $monthData = $rows->first()['months'];

        self::assertSame([10.0, 20.0, 30.0], array_map(
            static fn (array $month): float => $month['amount'],
            array_values($monthData),
        ));
        self::assertSame(60.0, $monthData['2026-02']['from_start']);
        self::assertSame(40.0, $rows->first()['remaining_amount']);
        self::assertSame(40.0, $rows->first()['remaining_quantity']);
    }

    public function test_ks6a_excel_uses_the_same_detailed_rows_as_pdf(): void
    {
        $service = new class extends OfficialFormsExportService
        {
            public function __construct() {}

            protected function prepareKS6aData(Contract $contract, ?string $periodStart = null, ?string $periodEnd = null): array
            {
                return [
                    'month_groups' => [['key' => '2026-01', 'title' => 'январь 2026 г.']],
                    'rows' => collect([[
                        'number' => 1, 'estimate_position' => '2.1', 'title' => 'Монтаж', 'unit' => 'м2',
                        'unit_price' => 1, 'estimate_quantity' => 100, 'estimate_amount' => 100,
                        'fact_quantity' => 12, 'fact_amount' => 12,
                        'acted_quantity' => 10, 'acted_amount' => 10,
                        'remaining_fact_quantity' => 88, 'remaining_fact_amount' => 88,
                        'remaining_acted_quantity' => 90, 'remaining_acted_amount' => 90,
                        'months' => ['2026-01' => [
                            'quantity' => 10, 'amount' => 10, 'from_start' => 10,
                            'fact_quantity' => 12, 'fact_amount' => 12, 'fact_from_start' => 12,
                            'acted_quantity' => 10, 'acted_amount' => 10, 'acted_from_start' => 10,
                        ]],
                    ]]),
                ];
            }
        };
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $reflection = new ReflectionClass($service);
        $reflection->getMethod('setKS6aTable')->invoke($service, $sheet, new Contract);
        $reflection->getMethod('applyKS6aStyles')->invoke($service, $sheet);
        $path = tempnam(sys_get_temp_dir(), 'most-ks6a-test-');
        self::assertIsString($path);
        try {
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);
            $sheet = (new \PhpOffice\PhpSpreadsheet\Reader\Xlsx)->load($path)->getActiveSheet();
        } finally {
            unlink($path);
        }

        self::assertSame('2.1', $sheet->getCell('B17')->getValue());
        self::assertSame('Монтаж', $sheet->getCell('C17')->getValue());
        self::assertEquals(100, $sheet->getCell('F17')->getValue());
        self::assertEquals(12, $sheet->getCell('I17')->getValue());
        self::assertEquals(10, $sheet->getCell('K17')->getValue());
        self::assertEquals(88, $sheet->getCell('M17')->getValue());
        self::assertEquals(90, $sheet->getCell('O17')->getValue());
        self::assertEquals(12, $sheet->getCell('P17')->getValue());
        self::assertEquals(10, $sheet->getCell('S17')->getValue());
        self::assertEquals([15, 16], $sheet->getPageSetup()->getRowsToRepeatAtTop());
        self::assertSame(['A', 'O'], $sheet->getPageSetup()->getColumnsToRepeatAtLeft());
    }

    public function test_ks6a_month_span_covers_thirteen_months_across_year_boundary(): void
    {
        $reflection = new ReflectionClass(OfficialFormsExportService::class);
        $export = $reflection->newInstanceWithoutConstructor();
        $keys = $reflection->getMethod('monthKeysBetween')->invoke($export, '2025-12-01', '2026-12-31');

        self::assertSame(13, $keys->count());
        self::assertSame('2025-12', $keys->first());
        self::assertSame('2026-12', $keys->last());
    }

    public function test_ks6a_keeps_same_title_on_distinct_estimate_keys(): void
    {
        $reflection = new ReflectionClass(OfficialFormsExportService::class);
        $export = $reflection->newInstanceWithoutConstructor();
        $firstItem = (object) [
            'id' => 11,
            'name' => 'Одинаковое имя',
            'quantity_total' => 10,
            'quantity' => 10,
            'total_amount' => 10,
            'current_total_amount' => 10,
            'measurementUnit' => null,
            'workType' => null,
        ];
        $secondItem = (object) [
            'id' => 12,
            'name' => 'Одинаковое имя',
            'quantity_total' => 20,
            'quantity' => 20,
            'total_amount' => 20,
            'current_total_amount' => 20,
            'measurementUnit' => null,
            'workType' => null,
        ];
        $acts = new Collection;
        foreach ([[$firstItem, 10], [$secondItem, 20]] as $index => [$item, $amount]) {
            $line = new PerformanceActLine;
            $line->setRawAttributes(['id' => $index + 1, 'quantity' => $amount, 'amount' => $amount, 'title' => 'Одинаковое имя']);
            $line->setRelation('estimateItem', $item);
            $line->setRelation('completedWork', null);
            $act = new ContractPerformanceAct;
            $act->setRawAttributes(['id' => $index + 1, 'act_date' => '2026-01-31', 'period_end' => '2026-01-01', 'is_approved' => true]);
            $act->setRelation('lines', new Collection([$line]));
            $act->setRelation('completedWorks', new Collection);
            $acts->push($act);
        }
        $contract = new Contract;
        $contract->setRelation('contractEstimateItems', new Collection);
        $months = new Collection(['2026-01']);
        $rows = $reflection->getMethod('buildKS6aRows')->invoke($export, $contract, $acts, $months, $months);

        self::assertCount(2, $rows);
        self::assertSame(['Одинаковое имя', 'Одинаковое имя'], $rows->pluck('title')->all());
        self::assertSame([10.0, 20.0], $rows->pluck('acted_amount')->all());
    }

    private function renderItems(string $vat)
    {
        $estimate = new Estimate;
        $estimate->setRawAttributes(['vat_rate' => '22', 'total_amount' => '100', 'total_amount_with_vat' => '122']);
        $contract = new Contract;
        $contract->setRelation('estimate', $estimate);
        $line = new PerformanceActLine;
        $line->setRawAttributes(['title' => 'Работа', 'unit' => 'м2', 'quantity' => '1', 'unit_price' => '120', 'amount' => '120',
            'basis_snapshot' => json_encode(['estimate_item' => ['position_number' => '02.01']]),
        ]);
        $line->setRelation('estimateItem', null);
        $line->setRelation('completedWork', null);
        $act = new ContractPerformanceAct;
        $act->setRawAttributes([
            'amount' => '120', 'amount_without_vat' => $vat === '0.00' ? '120' : '100',
            'vat_rate' => $vat === '0.00' ? '0' : '20', 'vat_amount' => $vat,
        ]);
        $act->setRelation('contract', $contract);
        $act->setRelation('lines', new Collection([$line]));
        $act->setRelation('completedWorks', new Collection);
        $reflection = new ReflectionClass(OfficialFormsExportService::class);
        $export = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('priceService')->setValue($export, new ActingPriceService);
        $sheet = (new Spreadsheet)->getActiveSheet();

        $reflection->getMethod('setKS2Items')->invoke($export, $sheet, $act);

        return $sheet;
    }
}
