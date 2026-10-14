<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\BudgetEstimates\Services\EstimateCalculationService;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Classification\ItemClassificationService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\ImportPipelineService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\NormativeMatchingService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\SemanticMatchingService;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateSection;
use App\Models\ImportSession;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Storage\FileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

final class EstimateImportFinancialPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public static function financialModes(): array
    {
        return ['plain' => ['plain'], 'custom' => ['custom'], 'organization' => ['organization_defaults']];
    }

    public static function mixedChargeSources(): array
    {
        return [
            'local-overhead-plain' => [true, 'plain', 0, 0],
            'local-profit-plain' => [false, 'plain', 0, 0],
            'local-overhead-custom-zero' => [true, 'custom', 0, 0],
            'local-profit-custom-zero' => [false, 'custom', 0, 0],
            'local-overhead-custom-rates' => [true, 'custom', 9, 4],
            'local-profit-custom-rates' => [false, 'custom', 9, 4],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mixedChargeSources')]
    public function test_position_charge_and_other_global_component_both_survive_save(bool $localOverhead, string $mode, float $overheadRate, float $profitRate): void
    {
        $localLabel = $localOverhead ? 'Накладные расходы' : 'Сметная прибыль';
        $globalLabel = $localOverhead ? 'Сметная прибыль' : 'Накладные расходы';
        $localAmount = $localOverhead ? 10 : 5;
        $globalAmount = $localOverhead ? 5 : 10;
        $session = $this->import([
            ['1', 'ГЭСН01-01', 'Работа', 'шт', 1, 100, 100],
            [null, $localLabel, null, null, null, null, $localAmount],
            [null, 'Итого с накладными и см. прибылью', null, null, null, null, 100 + $localAmount],
            [null, 'Итого по смете', null, null, null, null, 100 + $localAmount],
            [null, $globalLabel, null, null, null, null, $globalAmount],
            [null, 'Всего по смете', null, null, null, null, 115],
        ], $mode, $overheadRate, $profitRate);
        $estimate = Estimate::query()->findOrFail($session->stats['estimate_id']);
        foreach ([0, 22, 0] as $vat) {
            $estimate = app(EstimateService::class)->update($estimate, ['vat_rate' => $vat]);
            $item = EstimateItem::query()->where('estimate_id', $estimate->id)->firstOrFail();
            self::assertEquals(115, $item->total_amount);
            self::assertEquals(100, $item->direct_costs);
            self::assertEquals(10, $item->overhead_amount);
            self::assertEquals(5, $item->profit_amount);
            self::assertEquals(115, $estimate->total_amount);
            self::assertEquals($vat === 22 ? 140.30 : 115, $estimate->total_amount_with_vat);
            self::assertEquals(115, EstimateSection::query()->where('estimate_id', $estimate->id)->value('section_total_amount'));
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mixedChargeSources')]
    public function test_same_component_global_increment_is_added_to_position_source_charge(bool $localOverhead, string $mode, float $overheadRate, float $profitRate): void
    {
        $label = $localOverhead ? 'Накладные расходы' : 'Сметная прибыль';
        $session = $this->import([
            ['1', 'ГЭСН01-01', 'Работа', 'шт', 1, 100, 100],
            [null, $label, null, null, null, null, 10],
            [null, 'Итого с накладными и см. прибылью', null, null, null, null, 110],
            [null, 'Итого по смете', null, null, null, null, 110],
            [null, $label, null, null, null, null, 20],
            [null, 'Всего по смете', null, null, null, null, 130],
        ], $mode, $overheadRate, $profitRate);
        $estimate = Estimate::query()->findOrFail($session->stats['estimate_id']);
        self::assertEquals(130, $estimate->metadata['footer']['total_estimate_cost']);
        foreach ([0, 22, 0] as $vat) {
            $estimate = app(EstimateService::class)->update($estimate, ['vat_rate' => $vat]);
            $item = EstimateItem::query()->where('estimate_id', $estimate->id)->firstOrFail();
            self::assertEquals(130, $item->total_amount);
            self::assertEquals(100, $item->direct_costs);
            self::assertEquals($localOverhead ? 30 : 0, $item->overhead_amount);
            self::assertEquals($localOverhead ? 0 : 30, $item->profit_amount);
            self::assertEquals(130, $estimate->total_amount);
            self::assertEquals($vat === 22 ? 158.60 : 130, $estimate->total_amount_with_vat);
            self::assertEquals(130, EstimateSection::query()->where('estimate_id', $estimate->id)->value('section_total_amount'));
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('financialModes')]
    public function test_footer_charges_survive_normal_save_and_vat_round_trip(string $mode): void
    {
        $session = $this->import([
            ['1', 'ГЭСН01-01', 'Первая работа', '100m2', 1, 100, 100],
            ['2', 'ГЭСН01-02', 'Вторая работа', '100м²', 2, 50, 100],
            [null, 'Итого по смете', null, null, null, null, 200],
            [null, 'Накладные расходы', null, null, null, null, 20],
            [null, 'Сметная прибыль', null, null, null, null, 10],
            [null, 'Всего по смете', null, null, null, null, 230],
        ], $mode);
        $estimate = Estimate::query()->findOrFail($session->stats['estimate_id']);
        $items = EstimateItem::query()->where('estimate_id', $estimate->id)->orderBy('id')->get();

        self::assertSame('completed', $session->status);
        self::assertSame(100, Cache::get("import_session_progress_{$session->id}"));
        self::assertCount(2, $items);
        self::assertSame($items[0]->measurement_unit_id, $items[1]->measurement_unit_id);
        self::assertEquals(1, $items[0]->quantity);
        self::assertEquals(2, $items[1]->quantity);
        self::assertEquals(230, $items->sum('total_amount'));
        self::assertEquals(200, $items->sum('direct_costs'));
        self::assertEquals(20, $items->sum('overhead_amount'));
        self::assertEquals(10, $items->sum('profit_amount'));
        self::assertEquals(230, EstimateSection::query()->where('estimate_id', $estimate->id)->value('section_total_amount'));
        self::assertEquals($mode === 'plain' ? 0 : 9, $estimate->overhead_rate);
        self::assertEquals($mode === 'plain' ? 0 : 4, $estimate->profit_rate);
        self::assertTrue($estimate->metadata['import_source_charges_preserved']);

        $service = app(EstimateService::class);
        foreach ([0, 22, 0] as $vat) {
            $estimate = $service->update($estimate, ['name' => 'Сохранённая смета', 'vat_rate' => $vat]);
            self::assertEquals(230, $estimate->total_amount);
            self::assertEquals($vat === 22 ? 280.60 : 230, $estimate->total_amount_with_vat);
            self::assertEquals(200, $estimate->total_direct_costs);
            self::assertEquals(230, EstimateSection::query()->where('estimate_id', $estimate->id)->value('section_total_amount'));
        }
        app(EstimateCalculationService::class)->recalculateAll($estimate);
        self::assertEquals(230, $estimate->fresh()->total_amount);
    }

    public function test_negative_estimate_and_source_components_keep_signed_header_after_save(): void
    {
        $session = $this->import([
            ['1', 'ГЭСН01-01', 'Корректировка', 'шт', -2, 100, -200],
            [null, 'Итого по смете', null, null, null, null, -200],
            [null, 'Накладные расходы', null, null, null, null, -20],
            [null, 'Сметная прибыль', null, null, null, null, -10],
            [null, 'Всего по смете', null, null, null, null, -230],
        ]);
        $estimate = Estimate::query()->findOrFail($session->stats['estimate_id']);
        $estimate = app(EstimateService::class)->update($estimate, ['vat_rate' => 22]);
        self::assertEquals(-230, $estimate->total_amount);
        self::assertEquals(-280.60, $estimate->total_amount_with_vat);
        self::assertEquals(-200, $estimate->total_direct_costs);
        self::assertEquals(-20, $estimate->total_overhead_costs);
        self::assertEquals(-10, $estimate->total_estimated_profit);
        self::assertEquals(-2, EstimateItem::query()->where('estimate_id', $estimate->id)->value('quantity'));
    }

    public function test_named_zero_item_and_zero_quantity_nonzero_total_survive_import_with_warning(): void
    {
        $session = $this->import([
            ['1', 'ГЭСН01-01', 'Нулевая позиция', 'шт', 0, 0, 0],
            ['2', 'ГЭСН01-02', 'Фиксированная сумма', 'шт', 0, 0, 50],
            ['3', 'ГЭСН01-03', 'Работа', 'шт', 1, 100, 100],
            [null, 'Итого по смете', null, null, null, null, 150],
        ]);
        $estimate = Estimate::query()->findOrFail($session->stats['estimate_id']);
        $items = EstimateItem::query()->where('estimate_id', $estimate->id)->orderBy('id')->get();
        self::assertCount(3, $items);
        self::assertEquals(0, $items[0]->total_amount);
        self::assertEquals(50, $items[1]->total_amount);
        self::assertNotEmpty($session->stats['result']['row_warnings']);
        self::assertEquals(150, $estimate->total_amount);
        app(EstimateCalculationService::class)->recalculateAll($estimate);
        self::assertEquals(150, $estimate->fresh()->total_amount);
    }

    private function import(array $rows, string $mode = 'plain', float $overheadRate = 9, float $profitRate = 4): ImportSession
    {
        Queue::fake();
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        MeasurementUnit::create(['organization_id' => $organization->id, 'name' => 'Сто квадратных метров', 'short_name' => '100 м²', 'type' => 'work']);
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(['№', 'Код', 'Наименование', 'Единица', 'Количество', 'Цена', 'Стоимость'], null, 'A1');
        $sheet->setCellValue('A2', 'Раздел 1. Работы');
        foreach ($rows as $index => $row) {
            $sheet->fromArray($row, null, 'A'.($index + 3), true);
        }
        $path = sys_get_temp_dir().'/financial-import-'.Str::uuid().'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $this->mock(FileService::class, function ($mock) use ($path): void {
            $mock->shouldReceive('existsCurrent')->andReturn(true);
            $mock->shouldReceive('readCurrent')->andReturnUsing(static fn () => fopen($path, 'rb'));
        });
        $this->mock(NormativeMatchingService::class)->shouldReceive('findByCode')->andReturn(null);
        $this->mock(SemanticMatchingService::class)->shouldReceive('getBestNormativeMatch')->andReturn(null);
        $this->mock(ItemClassificationService::class)->shouldReceive('classifyBatch')->andReturn([]);

        $session = ImportSession::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'status' => 'queued',
            'file_path' => "org-{$organization->id}/estimate-imports/synthetic.xlsx",
            'file_name' => 'synthetic.xlsx',
            'file_size' => filesize($path),
            'file_format' => 'xlsx',
            'options' => [
                'format_handler' => 'custom_excel',
                'structure' => ['header_row' => 1, 'column_mapping' => ['position_number' => 'A', 'code' => 'B', 'name' => 'C', 'unit' => 'D', 'quantity' => 'E', 'unit_price' => 'F', 'total_price' => 'G']],
                'estimate_settings' => ['project_id' => $project->id, 'name' => 'Проверка импорта', 'financial_mode' => $mode, 'overhead_rate' => $overheadRate, 'profit_rate' => $profitRate, 'vat_rate' => 0],
            ],
        ]);
        try {
            app(ImportPipelineService::class)->run($session);
        } finally {
            unlink($path);
        }

        return $session->refresh();
    }
}
