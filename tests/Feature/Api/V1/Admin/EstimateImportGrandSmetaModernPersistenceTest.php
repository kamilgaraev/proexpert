<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateCalculationService;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Classification\ItemClassificationService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\EstimateImportService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\GrandSmeta\GrandSmetaParser;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\ImportPipelineService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\NormativeMatchingService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\SemanticMatchingService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\StagingAreaService;
use App\Jobs\ProcessEstimateImportJob;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateSection;
use App\Models\ImportSession;
use App\Services\Storage\DTO\CurrentStoredFile;
use App\Services\Storage\FileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\EstimateImportTestContext;
use Tests\TestCase;

final class EstimateImportGrandSmetaModernPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_modern_source_components_and_physical_parents_survive_job_save_and_vat_changes(): void
    {
        [$service, $context, $session] = $this->prepareImport();
        $preview = $service->preview($session->id, null, $context->access());
        self::assertTrue($preview->validationSummary['is_valid']);
        self::assertEquals(500, $preview->totals['total_amount']);
        $staging = app(StagingAreaService::class)->buildPreview($session->id, $context->organization->id, $context->access());
        $stagedItems = collect($staging['rows'])->flatMap(static fn (array $row): array => [$row, ...($row['sub_items'] ?? [])])->keyBy('row_number');
        foreach ($preview->items as $sourceRow) {
            $stagedRow = $stagedItems->get($sourceRow['row_number']);
            self::assertIsArray($stagedRow);
            self::assertSame($sourceRow['is_sub_item'], $stagedRow['is_sub_item']);
            foreach (['quantity', 'unit_price', 'current_total_amount', 'overhead_amount', 'profit_amount', 'raw_data'] as $field) {
                self::assertSame($sourceRow[$field], $stagedRow[$field]);
            }
        }
        $stagedWork = collect($staging['rows'])->firstWhere('item_name', 'Работа');
        self::assertFalse($stagedWork['has_math_mismatch']);
        self::assertSame(440.0, $stagedWork['math_expected_amount']);
        self::assertSame('quantity_price_with_charges', $stagedWork['math_comparison_basis']);
        $stagedLabor = collect($stagedWork['sub_items'])->firstWhere('item_name', 'ОТ');
        self::assertTrue($stagedLabor['is_informative']);
        self::assertFalse($stagedLabor['has_math_mismatch']);
        $estimate = $this->executeImport($service, $context, $session);
        $this->assertPreservedSource($estimate, 0);

        foreach ([0, 22, 0] as $vat) {
            $estimate = app(EstimateService::class)->update($estimate, ['name' => 'Сохранённый расчёт', 'vat_rate' => $vat]);
            $this->assertPreservedSource($estimate, $vat);
        }
        app(EstimateCalculationService::class)->recalculateAll($estimate);
        $this->assertPreservedSource($estimate->fresh(), 0);
        $work = EstimateItem::query()->where('estimate_id', $estimate->id)->where('name', 'Работа')->firstOrFail();
        $work->update(['labor_cost' => 110]);
        app(EstimateCalculationService::class)->recalculateAll($estimate);
        $this->assertPreservedSource($estimate->fresh(), 0, 110);
        self::assertEquals(220, $work->fresh()->metadata['raw_data']['financial_structure']['labor_cost']);
    }

    public function test_explicit_informative_standalone_dto_is_persisted_without_increasing_preview_or_header(): void
    {
        [$service, $context, $session] = $this->prepareImport(standaloneInformative: true);
        $preview = $service->preview($session->id, null, $context->access());
        self::assertTrue($preview->validationSummary['is_valid']);
        self::assertEquals(500, $preview->totals['total_amount']);
        self::assertCount(8, $preview->items);
        $estimate = $this->executeImport($service, $context, $session);
        $informative = EstimateItem::query()->where('estimate_id', $estimate->id)->where('name', 'Справочная стоимость')->firstOrFail();

        self::assertNull($informative->parent_work_id);
        self::assertTrue($informative->is_not_accounted);
        self::assertEquals(999, $informative->total_amount);
        $estimate = app(EstimateService::class)->update($estimate, ['vat_rate' => 22]);
        $this->assertPreservedSource($estimate, 22);
        self::assertEquals(500, $estimate->total_amount);
    }

    public function test_invalid_modern_source_cannot_commit_an_estimate_when_execute_validation_is_bypassed(): void
    {
        [$service, $context, $session] = $this->prepareImport(missingComponent: true);
        $preview = $service->preview($session->id, null, $context->access());
        self::assertFalse($preview->validationSummary['is_valid']);
        self::assertNotEmpty($preview->validationSummary['errors']);
        $before = Estimate::query()->where('organization_id', $context->organization->id)->count();
        $options = $session->fresh()->options;
        $options['run_token'] = (string) Str::uuid();
        $options['execution_user_id'] = $context->user->id;
        $options['estimate_settings'] = ['project_id' => $context->project->id, 'name' => 'Неполный расчёт', 'vat_rate' => 0];
        $session->update(['status' => 'queued', 'options' => $options]);

        (new ProcessEstimateImportJob($session->id, $options['run_token']))->handle(app(ImportPipelineService::class));

        $session->refresh();
        self::assertSame('failed', $session->status);
        self::assertArrayNotHasKey('estimate_id', $session->stats ?? []);
        self::assertSame($before, Estimate::query()->where('organization_id', $context->organization->id)->count());
        self::assertSame(0, EstimateItem::query()->whereHas('estimate', fn ($query) => $query->where('organization_id', $context->organization->id))->count());

        $options['run_token'] = (string) Str::uuid();
        $options['validate_only'] = true;
        $session->update(['status' => 'queued', 'options' => $options, 'stats' => [], 'error_message' => null]);
        (new ProcessEstimateImportJob($session->id, $options['run_token']))->handle(app(ImportPipelineService::class));

        $session->refresh();
        self::assertSame('validated', $session->status);
        self::assertFalse($session->stats['result']['validation']['is_valid']);
        self::assertNotEmpty($session->stats['result']['validation']['errors']);
        self::assertArrayNotHasKey('estimate_id', $session->stats);
        self::assertSame($before, Estimate::query()->where('organization_id', $context->organization->id)->count());
    }

    public function test_inactive_export_positions_keep_indexed_amounts_without_increasing_saved_or_recalculated_totals(): void
    {
        [$service, $context, $session] = $this->prepareImport(inactiveExport: true);
        $preview = $service->preview($session->id, null, $context->access());
        self::assertTrue($preview->validationSummary['is_valid']);
        self::assertEquals(0.11, $preview->totals['total_amount']);
        $estimate = $this->executeImport($service, $context, $session);

        foreach ([0, 22] as $vat) {
            $estimate = app(EstimateService::class)->update($estimate, ['vat_rate' => $vat]);
            app(EstimateCalculationService::class)->recalculateAll($estimate);
            $estimate->refresh();
            $items = EstimateItem::query()->where('estimate_id', $estimate->id)->orderBy('id')->get();
            $roots = $items->whereNull('parent_work_id')->values();
            self::assertCount(3, $roots);
            self::assertSame([false, true, false], $roots->pluck('is_not_accounted')->all());
            self::assertEquals([0.06, 0.09, 0.05], $roots->pluck('current_total_amount')->all());
            self::assertEquals(0.11, $estimate->total_amount);
            self::assertEquals($vat === 22 ? 0.13 : 0.11, $estimate->total_amount_with_vat);
            self::assertEquals(0.11, EstimateSection::query()->where('estimate_id', $estimate->id)->sum('section_total_amount'));
            self::assertSame('grand_export_inactive', $roots[1]->metadata['raw_data']['source_accounting']['marker']);
            foreach ($roots as $root) {
                $children = $items->where('parent_work_id', $root->id)->values();
                self::assertCount(1, $children);
                self::assertTrue($children[0]->is_not_accounted);
                self::assertTrue($children[0]->metadata['is_informative_row']);
                self::assertSame((float) $root->machinery_cost, (float) $children[0]->current_total_amount);
                self::assertSame('parent_indexed_component', $children[0]->metadata['raw_data']['total_source']);
                self::assertSame($root->metadata['raw_data']['index_provenance'], $children[0]->metadata['raw_data']['index_provenance']);
            }
        }
    }

    private function assertPreservedSource(Estimate $estimate, int $vat, float $laborCost = 220): void
    {
        $items = EstimateItem::query()->where('estimate_id', $estimate->id)->orderBy('id')->get();
        $roots = $items->whereNull('parent_work_id')->where('is_not_accounted', false)->values();
        self::assertCount(4, $roots);
        self::assertSame(['1', '1', '1', '1'], $roots->pluck('position_number')->all());
        self::assertSame([5, 6, 13, 14], $roots->map(fn ($item) => $item->metadata['source_row_number'])->all());
        self::assertSame(['0:5', '0:6', '0:13', '0:14'], $roots->map(fn ($item) => $item->metadata['raw_data']['source_identity'])->all());
        self::assertEquals([80, 440, -20, 0], $roots->pluck('total_amount')->all());
        self::assertEquals(500, $estimate->total_amount);
        self::assertEquals($vat === 22 ? 610 : 500, $estimate->total_amount_with_vat);
        self::assertEquals(470, $estimate->total_direct_costs);
        self::assertEquals(20, $estimate->total_overhead_costs);
        self::assertEquals(10, $estimate->total_estimated_profit);
        self::assertEquals(500, EstimateSection::query()->where('estimate_id', $estimate->id)->sum('section_total_amount'));
        $work = $roots[1];
        self::assertEquals(2, $work->quantity);
        self::assertEquals(410, $work->direct_costs);
        self::assertEquals(205, $work->unit_price);
        self::assertEquals(205, $work->current_unit_price);
        self::assertEquals($laborCost, $work->labor_cost);
        self::assertEquals(120, $work->machinery_cost);
        self::assertEquals(90, $work->materials_cost);
        self::assertTrue($work->metadata['import_source_components_preserved']);
        self::assertTrue($work->metadata['raw_data']['financial_structure']['labor_cost_declared']);
        self::assertEquals(220, $work->metadata['raw_data']['financial_structure']['effective_unit_price']);
        $children = $items->where('parent_work_id', $work->id)->values();
        self::assertCount(3, $children);
        self::assertSame(['ОТ', 'ЭМ', 'М'], $children->pluck('name')->all());
        self::assertTrue($children->every(fn ($item) => $item->is_not_accounted));
        self::assertTrue($children->every(fn ($item) => $item->metadata['is_informative_row']));
        self::assertTrue($children->every(fn ($item) => $item->metadata['raw_data']['parent_source_identity'] === '0:6'));
        self::assertEquals(-2, $roots[2]->quantity);
        self::assertEquals(10, $roots[2]->unit_price);
        self::assertEquals(0, $roots[3]->quantity);
        self::assertEquals(0, $roots[3]->current_total_amount);
        self::assertEquals(0, $roots[3]->overhead_amount);
        self::assertEquals(0, $roots[3]->profit_amount);
        self::assertTrue($roots[3]->metadata['import_has_source_overhead']);
        self::assertTrue($roots[3]->metadata['import_has_source_profit']);
    }

    private function executeImport(EstimateImportService $service, EstimateImportTestContext $context, ImportSession $session): Estimate
    {
        $service->execute($session->id, [], [
            'project_id' => $context->project->id, 'name' => 'Исходный расчёт',
            'financial_mode' => 'custom', 'overhead_rate' => 9, 'profit_rate' => 4, 'vat_rate' => 0,
        ], false, $context->access());
        $session->refresh();
        (new ProcessEstimateImportJob($session->id, $session->options['run_token']))->handle(app(ImportPipelineService::class));
        $session->refresh();
        self::assertSame('completed', $session->status, $session->error_message ?? '');

        return Estimate::query()->findOrFail($session->stats['estimate_id']);
    }

    private function prepareImport(bool $missingComponent = false, bool $standaloneInformative = false, bool $inactiveExport = false): array
    {
        Storage::fake('s3');
        Queue::fake();
        $disk = Storage::disk('s3');
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('putPrivate')->once()->andReturnUsing(static function (string $key, mixed $contents, string $mime, string $sha256) use ($disk): CurrentStoredFile {
            $disk->put($key, $contents);

            return new CurrentStoredFile($key, 'test-etag', $disk->size($key), $sha256, $mime);
        });
        $files->shouldReceive('existsCurrent')->andReturnUsing(static fn (string $key): bool => $disk->exists($key));
        $files->shouldReceive('readCurrent')->andReturnUsing(static fn (string $key) => $disk->readStream($key));
        $this->app->instance(FileService::class, $files);
        $this->mock(NormativeMatchingService::class)->shouldReceive('findByCode')->andReturn(null);
        $this->mock(SemanticMatchingService::class)->shouldReceive('getBestNormativeMatch')->andReturn(null);
        $this->mock(ItemClassificationService::class)->shouldReceive('classifyBatch')->andReturn([]);
        if ($standaloneInformative) {
            $original = app(GrandSmetaParser::class);
            $parser = Mockery::mock(GrandSmetaParser::class);
            $parser->shouldReceive('getStream')->andReturnUsing(static function (...$arguments) use ($original): \Generator {
                yield from $original->getStream(...$arguments);
                yield new EstimateImportRowDTO(
                    rowNumber: 100, sectionNumber: '99', itemName: 'Справочная стоимость', quantity: 1,
                    unitPrice: 999, code: 'ГЭСН-99', currentTotalAmount: 999, overheadAmount: 0, profitAmount: 0,
                    isNotAccounted: true, rawData: ['source_identity' => '0:100'], worksheetIndex: 0, worksheetName: 'Расчет',
                );
            });
            $parser->shouldReceive('getFooterData')->andReturnUsing(static fn () => $original->getFooterData());
            $parser->shouldReceive('getValidationErrors')->andReturnUsing(static fn () => $original->getValidationErrors());
            $parser->shouldReceive('getRawSampleRows')->andReturnUsing(static fn (...$arguments) => $original->getRawSampleRows(...$arguments));
            $this->app->instance(GrandSmetaParser::class, $parser);
        }
        $context = EstimateImportTestContext::create();
        $path = $inactiveExport ? $this->createInactiveSpreadsheet() : $this->createSpreadsheet($missingComponent);
        $service = app(EstimateImportService::class);
        try {
            $id = $service->uploadFile(new UploadedFile($path, 'grand-modern-synthetic.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true), $context->user->id, $context->organization->id, $context->access());
            self::assertSame('grandsmeta', $service->detectEstimateType($id, $context->access())->detectedType);
            self::assertSame('grandsmeta', $service->detectFormat($id, null, $context->access())['format']);
        } finally {
            unlink($path);
        }

        return [$service, $context, ImportSession::query()->findOrFail($id)];
    }

    private function createInactiveSpreadsheet(): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('A1', 'ГРАНД-Смета 2026.2');
        $sheet->setCellValue('J1', 'Сметная стоимость в базисном уровне цен');
        $sheet->setCellValue('M1', 'Индексы');
        $sheet->setCellValue('N1', 'Сметная стоимость в текущем уровне цен');
        $sheet->fromArray([1, 2, 3, null, null, 4, 5, 6, 7, 8, 9, 10, 11, 12], null, 'A3', true);
        $sheet->setCellValue('C4', 'Раздел 1. Монтаж');
        foreach ([5 => 'Работа один', 10 => 'Неучтённая работа', 15 => 'Работа два'] as $row => $name) {
            $sheet->fromArray([$row === 5 ? 1 : 2, 'ГЭСН-'.$row, $name], null, 'A'.$row, true);
            $sheet->setCellValue('I'.$row, 1);
            $sheet->setCellValue('C'.($row + 1), 'ЭМ');
            $sheet->setCellValue('L'.($row + 1), 0.05);
            $sheet->setCellValue('C'.($row + 2), 'Итого по расценке');
            $sheet->setCellValue('L'.($row + 2), 0.05);
            $sheet->setCellValue(($row === 10 ? 'Q' : 'P').$row, $name);
        }
        $sheet->setCellValue('C13', 'НР 10%');
        $sheet->setCellValue('N13', 0.02);
        $sheet->setCellValue('C14', 'СП 5%');
        $sheet->setCellValue('N14', 0.01);
        $sheet->getStyle('A10:C10')->getFont()->getColor()->setARGB('FF7F7F7F');
        foreach ([20 => ['C' => 'Итоги по смете:'], 21 => ['C' => 'Строительные работы'], 22 => ['C' => 'Эксплуатация машин и механизмов', 'L' => 0.1, 'M' => 1.1, 'N' => 0.11], 23 => ['C' => 'Всего', 'N' => 0.11]] as $row => $values) {
            foreach ($values as $column => $value) {
                $sheet->setCellValue($column.$row, $value);
            }
        }
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'grand-inactive-persistence-'.Str::uuid().'.xlsx';
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return $path;
    }

    private function createSpreadsheet(bool $missingComponent): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Расчет');
        $sheet->setCellValue('A1', 'ГРАНД-Смета');
        $sheet->setCellValue('J1', 'Сметная стоимость в базисном уровне цен');
        $sheet->setCellValue('M1', 'Индексы');
        $sheet->setCellValue('N1', 'Сметная стоимость в текущем уровне цен');
        $sheet->fromArray([1, 2, 3, null, null, 4, 5, 6, 7, 8, 9, 10, 11, 12], null, 'A3', true);
        $rows = [
            4 => ['C' => 'Раздел 1. Монтаж'],
            5 => ['A' => 1, 'C' => 'Материал', 'F' => 'шт', 'I' => 2, 'J' => 10, 'K' => 99, 'L' => 20, 'M' => 4, 'N' => 80],
            6 => ['A' => 1, 'B' => 'ГЭСН-1', 'C' => 'Работа', 'F' => 'шт', 'I' => 2],
            7 => ['C' => 'ОТ', 'N' => 200],
            8 => ['C' => 'ЭМ', 'L' => 30, 'N' => $missingComponent ? null : 120],
            9 => ['C' => 'в т.ч. ОТм', 'N' => 20],
            10 => ['C' => 'М', 'N' => 90],
            11 => ['C' => 'НР 10%', 'N' => 20],
            12 => ['C' => 'СП 5%', 'N' => 10],
            13 => ['A' => 1, 'B' => 'ГЭСН-2', 'C' => 'Возврат', 'F' => 'шт', 'I' => -2, 'N' => -20],
            14 => ['A' => 1, 'B' => 'ГЭСН-3', 'C' => 'Нулевая корректировка', 'F' => 'шт', 'I' => 0, 'N' => 0],
            15 => ['C' => 'Итоги по смете:'],
            16 => ['C' => 'Накладные расходы', 'N' => 20],
            17 => ['C' => 'Сметная прибыль', 'N' => 10],
            18 => ['C' => 'Всего', 'N' => 500],
            19 => ['C' => 'НДС22%', 'N' => 110],
            20 => ['C' => 'ВСЕГО по смете', 'N' => 610],
        ];
        foreach ($rows as $row => $values) {
            foreach ($values as $column => $value) {
                $sheet->setCellValue($column.$row, $value);
            }
        }
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'grand-modern-persistence-'.Str::uuid().'.xlsx';
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return $path;
    }
}
