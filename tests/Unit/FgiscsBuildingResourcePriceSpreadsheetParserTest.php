<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Import\FgiscsBuildingResourcePriceSpreadsheetParser;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

class FgiscsBuildingResourcePriceSpreadsheetParserTest extends TestCase
{
    public function test_it_reads_direct_material_prices_from_fgiscs_export(): void
    {
        $file = $this->xlsx([
            ['Код строительного ресурса', 'Наименование', 'Единица измерения', 'Отпускная цена', 'Сметная цена'],
            ['02.1.01.02-0003', 'Грунт песчаный (пескогрунт)', 'м3', '120.00', '603.20'],
        ]);

        $prices = iterator_to_array((new FgiscsBuildingResourcePriceSpreadsheetParser)->parse($file));

        $this->assertCount(1, $prices);
        $this->assertSame('02.1.01.02-0003', $prices[0]->code);
        $this->assertSame('Грунт песчаный (пескогрунт)', $prices[0]->name);
        $this->assertSame('м3', $prices[0]->unit);
        $this->assertSame(603.20, $prices[0]->currentPrice);
        $this->assertSame('regional_building_resource_export', $prices[0]->sourcePriceKind);
    }

    public function test_it_reads_split_form_direct_and_indexed_material_prices(): void
    {
        $file = $this->xlsx([
            ['02.1.01.02-0003', 'Грунт песчаный (пескогрунт)', 'м3', '150.00', '514.19', '440', 'Пескогрунты', '603.20', '-'],
            ['02.2.02.01-0001', 'Антрацит дробленый для загрузки фильтра', 'т', '19310.12', '20094.38', '22', 'Инертные материалы прочие', '-', '1.38'],
        ]);

        $prices = iterator_to_array((new FgiscsBuildingResourcePriceSpreadsheetParser)->parse($file));

        $this->assertCount(2, $prices);
        $this->assertSame(603.20, $prices[0]->currentPrice);
        $this->assertSame('regional_building_resource_direct', $prices[0]->sourcePriceKind);

        $this->assertSame('02.2.02.01-0001', $prices[1]->code);
        $this->assertSame(27730.2444, $prices[1]->currentPrice);
        $this->assertSame('regional_building_resource_index', $prices[1]->sourcePriceKind);
        $this->assertSame('22', $prices[1]->rawData['group_code']);
        $this->assertSame(1.38, $prices[1]->rawData['group_index']);
    }

    public function test_it_reads_direct_export_when_official_columns_change_order(): void
    {
        $file = $this->xlsx([
            ['СМЕТНЫЕ ЦЕНЫ МАТЕРИАЛОВ'],
            ['Сметная цена, рублей за единицу измерения, без НДС', 'Единица измерения', 'Наименование', 'Код строительного ресурса (материального ресурса или оборудования)', 'Отпускная цена, рублей за единицу измерения, без НДС'],
            ['603,20', 'м3', 'Грунт песчаный (пескогрунт)', '02.1.01.02-0003', '120,00'],
        ]);

        $prices = iterator_to_array((new FgiscsBuildingResourcePriceSpreadsheetParser)->parse($file));

        $this->assertCount(1, $prices);
        $this->assertSame('02.1.01.02-0003', $prices[0]->code);
        $this->assertSame('м3', $prices[0]->unit);
        $this->assertSame(603.20, $prices[0]->currentPrice);
        $this->assertSame(120.0, $prices[0]->rawData['release_price']);
        $this->assertSame('regional_building_resource_export', $prices[0]->sourcePriceKind);
    }

    public function test_it_reads_split_form_when_official_columns_change_order(): void
    {
        $file = $this->xlsx([
            ['Сплит-форма информации о ценах за 2 квартал 2026 года'],
            [
                'Индекс изменения сметной стоимости к группе однородных строительных ресурсов',
                'Сметная цена в текущем уровне цен, руб.',
                'Код группы однородных строительных ресурсов',
                'Наименование группы однородных строительных ресурсов',
                'Код группы, ресурса',
                'Наименование строительного ресурса, затрат',
                'Единица измерения',
                'Сметная цена в базисном уровне цен по состоянию на 01.01.2022, руб.',
                'Отпускная цена в базисном уровне цен по состоянию на 01.01.2022, руб.',
            ],
            ['-', '603,20', '440', 'Пескогрунты', '02.1.01.02-0003', 'Грунт песчаный (пескогрунт)', 'м3', '514,19', '150,00'],
            ['1,38', '-', '22', 'Инертные материалы прочие', '02.2.02.01-0001', 'Антрацит дробленый для загрузки фильтра', 'т', '20094,38', '19310,12'],
        ]);

        $prices = iterator_to_array((new FgiscsBuildingResourcePriceSpreadsheetParser)->parse($file));

        $this->assertCount(2, $prices);
        $this->assertSame(603.20, $prices[0]->currentPrice);
        $this->assertSame('regional_building_resource_direct', $prices[0]->sourcePriceKind);
        $this->assertSame('440', $prices[0]->rawData['group_code']);
        $this->assertSame(27730.2444, $prices[1]->currentPrice);
        $this->assertSame('regional_building_resource_index', $prices[1]->sourcePriceKind);
        $this->assertSame(1.38, $prices[1]->rawData['group_index']);
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     */
    private function xlsx(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $columnIndex => $value) {
                $sheet->setCellValue([$columnIndex + 1, $rowIndex + 1], $value);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'fgiscs-building-resources-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }
}
