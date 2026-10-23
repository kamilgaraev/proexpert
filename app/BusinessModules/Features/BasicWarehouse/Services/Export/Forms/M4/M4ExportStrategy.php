<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services\Export\Forms\M4;

use App\BusinessModules\Features\BasicWarehouse\Enums\WarehouseReceiptVatMode;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Services\Export\Strategies\BaseWarehouseExportStrategy;
use App\BusinessModules\Features\BasicWarehouse\Services\Export\WarehouseDocumentPersonResolver;
use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseReceiptTaxCalculator;
use App\Services\Storage\FileService;
use App\Support\OrganizationBusinessTimezone;
use Brick\Math\BigDecimal;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class M4ExportStrategy extends BaseWarehouseExportStrategy
{
    private const PRINT_SCALE = 90;

    public function __construct(
        FileService $fileService,
        private readonly WarehouseDocumentPersonResolver $personResolver,
    ) {
        parent::__construct($fileService);
    }

    public function export($movementOrCollection): string
    {
        $movements = $movementOrCollection instanceof \Illuminate\Database\Eloquent\Collection
            ? $movementOrCollection : collect([$movementOrCollection]);
        $firstMovement = $movements->first();
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $this->setHeader($sheet, $firstMovement);
        $this->setTable($sheet, $movements);
        $this->setFooter($sheet, $firstMovement);
        $this->applyStyles($sheet);
        $filename = 'M4_'.$this->documentFileSuffix($firstMovement->document_number, $this->documentDate($firstMovement)).'.xlsx';

        return $this->saveSpreadsheetToS3($spreadsheet, "exports/warehouse/m4/{$filename}", $firstMovement->organization);
    }

    public function getSupportedType(): string
    {
        return 'm4';
    }

    protected function setHeader($sheet, WarehouseMovement $movement): void
    {
        $org = $movement->organization;
        foreach ([1 => 'Унифицированная форма № М-4', 2 => 'Утверждена постановлением Госкомстата России', 3 => 'от 30.10.1997 № 71а'] as $row => $text) {
            $this->text($sheet, "H{$row}:L{$row}", $text);
        }
        $this->text($sheet, 'A5:G5', $org->legal_name ?: $org->name);
        $this->setUnderline($sheet, 'A5:G5');
        $this->text($sheet, 'A6:G6', 'организация');
        $this->text($sheet, 'H5:J5', 'Код');
        $this->text($sheet, 'H6:J6', 'Форма по ОКУД');
        $this->text($sheet, 'K6:L6', '0315003');
        $this->text($sheet, 'H7:J7', 'по ОКПО');
        $this->text($sheet, 'K7:L7', (string) ($org->okpo ?? ''));
        $this->applyTableStyle($sheet, 'H5:L7');
        $this->text($sheet, 'A7:G7', 'Структурное подразделение:');
        $this->text($sheet, 'A9:L9', 'ПРИХОДНЫЙ ОРДЕР');
        $this->text($sheet, 'H10:I10', 'Номер документа');
        $this->text($sheet, 'J10:L10', 'Дата составления');
        $this->text($sheet, 'H11:I11', $this->documentNumber($movement->document_number));
        $this->text($sheet, 'J11:L11', $this->documentDate($movement)->format('d.m.Y'));
        $this->applyTableStyle($sheet, 'H10:L11');
        $this->text($sheet, 'A12:D12', 'Код вида операции:');
        $this->text($sheet, 'E12:H12', 'Склад (код): '.($movement->warehouse->code ?? ''));
        $this->text($sheet, 'I12:L12', 'Поставщик (код):');
        $this->text($sheet, 'A13:L13', 'Склад: '.($movement->warehouse->name ?? ''));
        $this->text($sheet, 'A14:L14', 'Поставщик: '.($this->documentText($movement, 'supplier_name') ?: $this->documentText($movement, 'source_organization_name')));
        $this->text($sheet, 'A15:L15', 'Страховая компания:'.($movement->isCustomerMaterialReceipt() ? "\n".trans_message('warehouse_basic.tolling_receipt_note') : ''));
        $this->text($sheet, 'A16:F16', 'Корреспондирующий счет (счет, субсчет):');
        $this->text($sheet, 'G16:L16', 'Код аналитического учета:');
        $this->text($sheet, 'A17:F17', 'Номер сопроводительного документа: '.$this->documentText($movement, 'invoice_number'));
        $this->text($sheet, 'G17:L17', 'Номер платежного документа:');
        $this->text($sheet, 'A18:L18', 'Налоговый режим цены указан в графе 1. При отсутствии сохраненных налоговых данных графы 8–10 не заполняются. Неизвестные реквизиты оставлены для заполнения.');
        $this->text($sheet, 'A19:L19', 'М-4 № '.$this->documentNumber($movement->document_number).' от '.$this->documentDate($movement)->format('d.m.Y').' • '.($org->legal_name ?: $org->name).' • Склад '.($movement->warehouse->code ?? ''));
    }

    protected function setTable($sheet, $movements): void
    {
        $headers = [
            'A20:A21' => 'Материальные ценности (наименование, сорт, размер, марка)',
            'B20:B21' => 'Номенклатурный номер',
            'C20:D20' => 'Единица измерения', 'C21' => 'Код', 'D21' => 'Наименование',
            'E20:F20' => 'Количество', 'E21' => 'По документу', 'F21' => 'Принято',
            'G20:G21' => 'Цена (режим в графе 1), руб. коп.',
            'H20:H21' => 'Сумма без учета НДС, руб. коп.',
            'I20:I21' => 'Сумма НДС, руб. коп.', 'J20:J21' => 'Всего с учетом НДС, руб. коп.',
            'K20:K21' => 'Номер паспорта', 'L20:L21' => 'Порядковый номер по складской картотеке',
        ];
        foreach ($headers as $range => $value) {
            $this->text($sheet, $range, $value);
        }
        foreach (range(1, 12) as $column) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($column).'22', $column);
        }
        $row = 22;
        foreach ($movements as $movement) {
            $row++;
            $tax = $this->taxSnapshot($movement);
            $mode = $tax['mode'] ?? null;
            $taxLabel = match ($mode) {
                'none' => 'Цена без НДС',
                'included' => 'Цена включает НДС '.$this->decimalText($tax['rate']).'%',
                'on_top' => 'НДС '.$this->decimalText($tax['rate']).'% начисляется сверх цены',
                default => 'Налоговый режим не указан',
            };
            $this->text($sheet, "A{$row}", $movement->material->name."\n".$taxLabel);
            $this->text($sheet, "B{$row}", (string) ($movement->material->code ?? ''));
            $unit = $movement->material->measurementUnit;
            $this->text($sheet, "D{$row}", ($unit?->short_name ?: $unit?->name) ?? '');
            $documentQuantity = $this->documentValue($movement, 'supplier_quantity');
            if (is_numeric($documentQuantity) && is_finite((float) $documentQuantity) && (float) $documentQuantity >= 0) {
                $this->quantity($sheet, "E{$row}", (string) $documentQuantity);
            }
            $this->quantity($sheet, "F{$row}", (string) $movement->quantity);
            if ($movement->price !== null) {
                $this->text($sheet, "G{$row}", $this->decimalText((string) $movement->price, true));
            }
            if ($tax !== null) {
                $this->text($sheet, "H{$row}", $this->decimalText($tax['net_amount'], true));
                $this->text($sheet, "I{$row}", $mode === 'none' ? 'Без НДС' : $this->decimalText($tax['vat_amount'], true));
                $this->text($sheet, "J{$row}", $this->decimalText($tax['gross_amount'], true));
            }
        }
        $this->applyTableStyle($sheet, "A20:L{$row}");
    }

    protected function setFooter($sheet, WarehouseMovement $movement): void
    {
        $row = $sheet->getHighestRow() + 2;
        $person = $this->personResolver->resolve($movement->user, (int) $movement->organization_id, $this->documentDate($movement));
        foreach (['Принял' => $person, 'Сдал' => ''] as $label => $name) {
            $this->text($sheet, "A{$row}:B{$row}", $label);
            $this->text($sheet, "C{$row}:E{$row}", '________________');
            $this->text($sheet, "F{$row}:H{$row}", '________________');
            $this->text($sheet, "I{$row}:L{$row}", $name ?: '________________');
            $this->text($sheet, 'C'.($row + 1).':E'.($row + 1), 'должность');
            $this->text($sheet, 'F'.($row + 1).':H'.($row + 1), 'подпись');
            $this->text($sheet, 'I'.($row + 1).':L'.($row + 1), 'расшифровка подписи');
            $row += 3;
        }
    }

    protected function applyStyles($sheet): void
    {
        $lastRow = $sheet->getHighestRow();
        $lastDataRow = $lastRow - 6;
        $sheet->setShowGridlines(false);
        $sheet->getStyle("A1:L{$lastRow}")->getFont()->setName('Arial')->setSize(9);
        $sheet->getStyle("A1:L{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        foreach (['A' => 30, 'B' => 13, 'C' => 6, 'D' => 8, 'E' => 10, 'F' => 10, 'G' => 15, 'H' => 17, 'I' => 13, 'J' => 18, 'K' => 10, 'L' => 10] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        foreach (range(1, $lastRow) as $row) {
            $sheet->getRowDimension($row)->setRowHeight(18);
        }
        $sheet->getStyle('H1:L3')->getFont()->setSize(8);
        $sheet->getStyle('H1:L3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle('A9')->getFont()->setBold(true)->setSize(14);
        $this->setCenter($sheet, 'A9:L9');
        $this->setCenter($sheet, 'H5:L11');
        $sheet->getStyle('A20:L22')->getFont()->setBold(true)->setSize(8);
        $this->setCenter($sheet, 'A20:L22');
        $sheet->getRowDimension(20)->setRowHeight(46);
        $sheet->getRowDimension(21)->setRowHeight(28);
        $sheet->getStyle('A18:L19')->getFont()->setSize(8);
        foreach ($sheet->getMergeCells() as $range) {
            [$start] = Coordinate::rangeBoundaries($range);
            if (in_array((int) $start[1], [20, 21], true)) {
                continue;
            }
            $this->fitWrappedCell($sheet, $range);
        }
        foreach (range(23, $lastDataRow) as $row) {
            foreach (range(1, 12) as $column) {
                $this->fitWrappedCell($sheet, Coordinate::stringFromColumnIndex($column).$row);
            }
            $sheet->getStyle("E{$row}:J{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }
        $sheet->getStyle("E23:J{$lastDataRow}")->getAlignment()->setWrapText(false)->setShrinkToFit(true);
        $sheet->getStyle("E23:F{$lastDataRow}")->getNumberFormat()->setFormatCode('0.###');
        foreach ([1, 2, 3, 6, 18] as $row) {
            $sheet->getStyle("A{$row}:L{$row}")->getFont()->setSize(8);
            $sheet->getRowDimension($row)->setRowHeight(12);
        }
        foreach ([$lastDataRow + 3, $lastDataRow + 6] as $row) {
            $sheet->getStyle("A{$row}:L{$row}")->getFont()->setSize(7);
            $sheet->getRowDimension($row)->setRowHeight(12);
        }
        foreach ([4, 8, $lastDataRow + 1, $lastDataRow + 4] as $row) {
            $sheet->getRowDimension($row)->setRowHeight(6);
        }
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setScale(self::PRINT_SCALE)->setFitToPage(false)->setPrintArea("A1:L{$lastRow}")->setRowsToRepeatAtTop([19, 22]);
        $sheet->getPageMargins()->setTop(0.25)->setBottom(0.3)->setLeft(0.2)->setRight(0.2)->setHeader(0.1)->setFooter(0.15);
        $this->paginate($sheet, $lastDataRow, $lastRow);
        $number = str_replace('&', '&&', (string) $sheet->getCell('H11')->getValue());
        $sheet->getHeaderFooter()->setOddFooter('&L&8М-4 № '.$number.'&R&8Страница &P из &N');
    }

    private function text(Worksheet $sheet, string $range, string $value): void
    {
        if (str_contains($range, ':')) {
            $sheet->mergeCells($range);
        }
        $sheet->setCellValueExplicit(explode(':', $range)[0], $value, DataType::TYPE_STRING);
    }

    private function quantity(Worksheet $sheet, string $coordinate, string $value): void
    {
        $decimal = BigDecimal::of($value);
        $numeric = BigDecimal::of((string) (float) $value);
        if ($decimal->compareTo($numeric) !== 0) {
            $this->text($sheet, $coordinate, $this->decimalText((string) $decimal));

            return;
        }

        $sheet->setCellValue($coordinate, $value);
    }

    private function documentValue(WarehouseMovement $movement, string $key): mixed
    {
        $metadata = $movement->metadata ?? [];
        $entered = $metadata['metadata'] ?? [];

        return $metadata[$key] ?? (is_array($entered) ? ($entered[$key] ?? null) : null);
    }

    private function documentText(WarehouseMovement $movement, string $key): string
    {
        $value = $this->documentValue($movement, $key);

        return is_string($value) || is_int($value) ? (string) $value : '';
    }

    private function documentDate(WarehouseMovement $movement): \Carbon\CarbonInterface
    {
        return $movement->movement_date->copy()->setTimezone((new OrganizationBusinessTimezone)->resolve($movement->organization));
    }

    private function paginate(Worksheet $sheet, int $lastDataRow, int $lastRow): void
    {
        $margins = $sheet->getPageMargins();
        $scale = self::PRINT_SCALE / 100;
        $capacity = (595.28 - 72 * ($margins->getTop() + $margins->getBottom())) / $scale * 0.98;
        $height = static function (int $start, int $end) use ($sheet): float {
            $sum = 0.0;
            foreach (range($start, $end) as $row) {
                $sum += $sheet->getRowDimension($row)->getRowHeight();
            }

            return $sum;
        };
        $used = $height(1, 22);
        $repeated = $height(19, 22);
        $footer = $height($lastDataRow + 1, $lastRow);
        foreach (range(23, $lastDataRow) as $row) {
            $rowHeight = $height($row, $row);
            $required = $rowHeight + ($row === $lastDataRow ? $footer : 0);
            if ($used + $required > $capacity) {
                $sheet->setBreak('A'.($row - 1), Worksheet::BREAK_ROW);
                $used = $repeated;
            }
            $used += $rowHeight;
        }
    }

    private function decimalText(string $value, bool $group = false): string
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        if ($group) {
            $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ' ', $whole) ?? $whole;
        }

        return $whole.($fraction !== '' ? ','.$fraction : '');
    }

    private function taxSnapshot(WarehouseMovement $movement): ?array
    {
        $saved = $movement->metadata['receipt_tax'] ?? null;
        if (! is_array($saved) || ($saved['version'] ?? null) !== 1 || $movement->price === null) {
            return null;
        }
        $mode = is_string($saved['mode'] ?? null) ? WarehouseReceiptVatMode::tryFrom($saved['mode']) : null;
        if ($mode === null || (isset($saved['rate']) && ! is_string($saved['rate']))) {
            return null;
        }
        try {
            $expected = (new WarehouseReceiptTaxCalculator)->calculate((string) $movement->quantity, (string) $movement->price, $mode, $saved['rate'] ?? null)->toArray();
        } catch (\InvalidArgumentException) {
            return null;
        }
        foreach ($expected as $key => $value) {
            if (($saved[$key] ?? null) !== $value) {
                return null;
            }
        }

        return $expected;
    }
}
