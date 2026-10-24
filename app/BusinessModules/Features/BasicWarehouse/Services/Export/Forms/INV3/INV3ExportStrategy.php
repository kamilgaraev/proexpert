<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services\Export\Forms\INV3;

use App\BusinessModules\Features\BasicWarehouse\Models\InventoryAct;
use App\BusinessModules\Features\BasicWarehouse\Models\InventoryActItem;
use App\BusinessModules\Features\BasicWarehouse\Services\Export\InventoryCommissionMemberNameResolver;
use App\BusinessModules\Features\BasicWarehouse\Services\Export\Strategies\BaseWarehouseExportStrategy;
use App\Helpers\NumberToWordsHelper;
use App\Services\Storage\FileService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class INV3ExportStrategy extends BaseWarehouseExportStrategy
{
    public function __construct(FileService $fileService, private readonly InventoryCommissionMemberNameResolver $commissionMemberNameResolver)
    {
        parent::__construct($fileService);
    }

    public function export($act): string
    {
        $spreadsheet = new Spreadsheet;
        $this->fillRequisites($spreadsheet->getActiveSheet()->setTitle('Реквизиты'), $act);
        $this->fillExtendedNotes($spreadsheet, $act);
        $sheet = $this->createInventoryPage($spreadsheet, $act, 1);
        $row = 9;
        $height = 0.0;
        $pageCount = 0;
        $pageQuantity = BigDecimal::zero()->toScale(3);
        $pageActualValue = BigDecimal::zero()->toScale(2);
        $pageExpectedValue = BigDecimal::zero()->toScale(2);
        $totalQuantity = $pageQuantity;
        $totalActualValue = $pageActualValue;
        $totalExpectedValue = $pageExpectedValue;
        $pageNumber = 1;
        foreach ($act->items as $index => $item) {
            $itemHeight = $this->itemHeight($item, $index + 1);
            if ($pageCount > 0 && $height + $itemHeight > 300) {
                $this->finishInventoryPage($sheet, $row, $pageCount, $pageQuantity, $pageActualValue, $pageExpectedValue);
                $sheet = $this->createInventoryPage($spreadsheet, $act, ++$pageNumber);
                $row = 9;
                $height = 0.0;
                $pageCount = 0;
                $pageQuantity = BigDecimal::zero()->toScale(3);
                $pageActualValue = BigDecimal::zero()->toScale(2);
                $pageExpectedValue = BigDecimal::zero()->toScale(2);
            }
            $actual = $item->actual_quantity === null ? null : BigDecimal::of((string) $item->actual_quantity);
            $price = $item->unit_price === null ? null : BigDecimal::of((string) $item->unit_price);
            $expectedValue = $price?->multipliedBy((string) $item->expected_quantity)->toScale(2, RoundingMode::HALF_UP);
            $actualValue = $actual === null ? null : $price?->multipliedBy($actual)->toScale(2, RoundingMode::HALF_UP);
            $this->fillItem($sheet, $row++, $index + 1, $item, $actualValue, $expectedValue, $itemHeight);
            $height += $itemHeight;
            $pageCount++;
            $pageQuantity = $actual === null || $pageQuantity === null ? null : $pageQuantity->plus($actual);
            $totalQuantity = $actual === null || $totalQuantity === null ? null : $totalQuantity->plus($actual);
            $pageActualValue = $actualValue === null || $pageActualValue === null ? null : $pageActualValue->plus($actualValue);
            $totalActualValue = $actualValue === null || $totalActualValue === null ? null : $totalActualValue->plus($actualValue);
            $pageExpectedValue = $expectedValue === null || $pageExpectedValue === null ? null : $pageExpectedValue->plus($expectedValue);
            $totalExpectedValue = $expectedValue === null || $totalExpectedValue === null ? null : $totalExpectedValue->plus($expectedValue);
        }
        $this->finishInventoryPage($sheet, $row, $pageCount, $pageQuantity, $pageActualValue, $pageExpectedValue);
        $members = $this->commissionMemberNameResolver->resolve($act);
        $this->fillCommissionPages($spreadsheet, $act, ...array_slice($members, 4));
        $this->fillSignatures($spreadsheet->createSheet()->setTitle('Итоги и подписи'), $act, $totalQuantity, $totalActualValue, $totalExpectedValue, count($members), ...array_slice($members, 0, 4));
        foreach ($spreadsheet->getAllSheets() as $index => $page) {
            $row = $page->getHighestRow() + 2;
            $this->text($page, "A{$row}:L{$row}", 'Лист '.($index + 1).' из '.$spreadsheet->getSheetCount());
            $page->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $this->applyStyles($page);
        }
        $spreadsheet->setActiveSheetIndex(0);
        $filename = 'INV3_'.$this->documentFileSuffix($act->act_number, $act->inventory_date).'.xlsx';

        return $this->saveSpreadsheetToS3($spreadsheet, "exports/warehouse/inv3/{$filename}", $act->organization);
    }

    public function getSupportedType(): string
    {
        return 'inv3';
    }

    private function fillRequisites(Worksheet $sheet, InventoryAct $act): void
    {
        $this->text($sheet, 'A1:L1', 'ИНВЕНТАРИЗАЦИОННАЯ ОПИСЬ ТОВАРНО-МАТЕРИАЛЬНЫХ ЦЕННОСТЕЙ', 30);
        $this->setBold($sheet, 'A1');
        $this->setCenter($sheet, 'A1');
        $this->text($sheet, 'A2:L2', 'На основе формы № ИНВ-3. Форма по ОКУД 0317004.');
        $this->text($sheet, 'A4:L4', 'Организация: '.($act->organization->legal_name ?? $act->organization->name));
        $this->text($sheet, 'A5:L5', 'ОКПО: '.($act->organization->okpo ?? '____________________'));
        $this->text($sheet, 'A6:L6', 'Местонахождение ТМЦ: '.($act->warehouse->name ?? '____________________'));
        $this->text($sheet, 'A7:L7', 'Номер описи: '.$this->documentNumber($act->act_number).'    Дата инвентаризации: '.$act->inventory_date->format('d.m.Y'));
        $this->text($sheet, 'A9:L9', 'Основание проведения (приказ, распоряжение, иной документ): ________________________________________');
        $this->text($sheet, 'A10:L10', 'Номер основания: ____________________    Дата: ____________________');
        $this->text($sheet, 'A11:L11', 'Инвентаризация начата: ____________________    Окончена: ____________________');
        $this->text($sheet, 'A12:L12', 'Вид ценностей и основание их нахождения (собственные, хранение и другое): ______________________________');
        $this->text($sheet, 'A14:L14', 'РАСПИСКА ДО НАЧАЛА ИНВЕНТАРИЗАЦИИ', 22);
        $this->setBold($sheet, 'A14');
        $this->text($sheet, 'A15:L15', 'К началу проведения инвентаризации все расходные и приходные документы на товарно-материальные ценности сданы в бухгалтерию, все ценности, поступившие на мою (нашу) ответственность, оприходованы, а выбывшие списаны в расход.', 38);
        $this->text($sheet, 'A16:L16', 'Материально ответственное лицо: должность ________________    Подпись ________________    ФИО ________________________', 26);
        $this->text($sheet, 'A17:L17', 'Дата расписки: ____________________');
        $this->text($sheet, 'A19:L19', 'Примечание к описи:');
        $row = 20;
        $notes = (string) ($act->notes ?? '');
        $this->text($sheet, "A{$row}:L{$row}", $this->hasExtendedNotes($notes) ? trans_message('warehouse_basic.inv3.extended_notes_reference') : $notes, 38);
        $row++;
        $row++;
        $this->text($sheet, "A{$row}:L{$row}", trans_message('warehouse_basic.inv3.cover_requisites_note'), 38);
    }

    private function hasExtendedNotes(string $notes): bool
    {
        return mb_strlen($notes) > 500 || substr_count($notes, "\n") > 5;
    }

    private function fillExtendedNotes(Spreadsheet $spreadsheet, InventoryAct $act): void
    {
        $this->appendNotes($spreadsheet, $act, (string) ($act->notes ?? ''), 'Примечания', 'Примечание к описи');
        foreach ($act->items as $index => $item) {
            $number = $index + 1;
            $this->appendNotes($spreadsheet, $act, $this->itemDetails($item), 'ТМЦ '.$number.' прим.', 'Данные строки № '.$number.' описи');
        }
    }

    private function appendNotes(Spreadsheet $spreadsheet, InventoryAct $act, string $notes, string $title, string $label): void
    {
        if (! $this->hasExtendedNotes($notes)) {
            return;
        }
        $page = 0;
        $row = 0;
        $sheet = null;
        foreach (preg_split('/\R/u', $notes) ?: [] as $line) {
            preg_match_all('/.{1,350}(?:[ \t]+|$)|.{350}/u', $line === '' ? ' ' : $line, $parts);
            foreach ($parts[0] as $part) {
                if ($sheet === null || $row > 11) {
                    $sheet = $spreadsheet->createSheet()->setTitle($title.' '.(++$page));
                    $this->text($sheet, 'A1:L1', $label.' № '.$this->documentNumber($act->act_number), 24);
                    $row = 3;
                }
                $this->text($sheet, "A{$row}:L{$row}", $part, 38);
                $row++;
            }
        }
    }

    private function createInventoryPage(Spreadsheet $spreadsheet, InventoryAct $act, int $number): Worksheet
    {
        $sheet = $spreadsheet->createSheet()->setTitle('Опись '.$number);
        $this->text($sheet, 'A1:L1', 'ОПИСЬ ТМЦ № '.$this->documentNumber($act->act_number).' от '.$act->inventory_date->format('d.m.Y').' — табличный лист '.$number, 24);
        $this->setBold($sheet, 'A1');
        $this->text($sheet, 'A2:L2', 'Организация: '.($act->organization->legal_name ?? $act->organization->name));
        $this->text($sheet, 'A3:L3', 'Местонахождение: '.($act->warehouse->name ?? ''));
        $this->text($sheet, 'A5:L5', trans_message('warehouse_basic.inv3.page_units_and_fact_note'));
        foreach (['A8' => '№', 'B8:D8' => 'ТМЦ: наименование и номенклатурный номер', 'E8' => 'Единица измерения', 'F8' => 'Цена за единицу', 'G8' => 'Факт: количество', 'H8' => 'Факт: сумма', 'I8' => 'По учёту: количество', 'J8' => 'По учёту: сумма', 'K8:L8' => 'Место хранения, партия, примечание'] as $range => $label) {
            $this->text($sheet, $range, $label, 38);
        }
        $this->setBold($sheet, 'A8:L8');
        $this->setCenter($sheet, 'A8:L8');

        return $sheet;
    }

    private function fillItem(Worksheet $sheet, int $row, int $number, InventoryActItem $item, ?BigDecimal $actualValue, ?BigDecimal $expectedValue, float $height): void
    {
        $this->number($sheet, "A{$row}", (string) $number, '0');
        $this->text($sheet, "B{$row}:D{$row}", $item->material->name."\n".($item->material->code ?? ''), $height);
        $this->text($sheet, "E{$row}", $item->material->measurementUnit->name ?? '', $height);
        $this->number($sheet, "F{$row}", $item->unit_price, '#,##0.00');
        $this->number($sheet, "G{$row}", $item->actual_quantity, '#,##0.###');
        $this->number($sheet, "H{$row}", $actualValue === null ? null : (string) $actualValue, '#,##0.00');
        $this->number($sheet, "I{$row}", $item->expected_quantity, '#,##0.###');
        $this->number($sheet, "J{$row}", $expectedValue === null ? null : (string) $expectedValue, '#,##0.00');
        $this->text($sheet, "K{$row}:L{$row}", $this->displayItemDetails($item, $number), $height);
    }

    private function finishInventoryPage(Worksheet $sheet, int $row, int $count, ?BigDecimal $quantity, ?BigDecimal $actualValue, ?BigDecimal $expectedValue): void
    {
        $this->applyTableStyle($sheet, 'A8:L'.max(8, $row - 1));
        $this->text($sheet, "A{$row}:G{$row}", 'Итого по табличному листу:');
        $this->number($sheet, "H{$row}", $actualValue === null ? null : (string) $actualValue, '#,##0.00');
        $this->number($sheet, "J{$row}", $expectedValue === null ? null : (string) $expectedValue, '#,##0.00');
        $row += 2;
        $this->text($sheet, "A{$row}:L{$row}", 'Количество порядковых номеров прописью: '.NumberToWordsHelper::integerToWords($count));
        $row++;
        $this->text($sheet, "A{$row}:L{$row}", 'Общее количество фактически учтённых единиц (независимо от единиц измерения) прописью: '.$this->quantityWords($quantity), 26);
    }

    private function fillCommissionPages(Spreadsheet $spreadsheet, InventoryAct $act, string ...$members): void
    {
        foreach (array_chunk($members, 10) as $index => $names) {
            $sheet = $spreadsheet->createSheet()->setTitle('Комиссия '.($index + 1));
            $this->text($sheet, 'A1:L1', 'Члены комиссии — продолжение. Опись № '.$this->documentNumber($act->act_number).' от '.$act->inventory_date->format('d.m.Y'), 24);
            $this->text($sheet, 'A2:L2', 'Организация: '.($act->organization->legal_name ?? $act->organization->name));
            foreach ($names as $offset => $name) {
                $row = $offset + 4;
                $this->text($sheet, "A{$row}:L{$row}", 'Должность ________________    Подпись ________________ / '.$name.' /', 26);
            }
        }
    }

    private function fillSignatures(Worksheet $sheet, InventoryAct $act, ?BigDecimal $quantity, ?BigDecimal $actualValue, ?BigDecimal $expectedValue, int $memberCount, string ...$members): void
    {
        $this->text($sheet, 'A1:L1', 'ИТОГИ ОПИСИ № '.$this->documentNumber($act->act_number).' от '.$act->inventory_date->format('d.m.Y'), 24);
        $this->setBold($sheet, 'A1');
        $this->text($sheet, 'A3:L3', 'Всего порядковых номеров прописью: '.NumberToWordsHelper::integerToWords($act->items->count()));
        $this->text($sheet, 'A4:L4', 'Общее фактическое количество (независимо от единиц измерения) прописью: '.$this->quantityWords($quantity));
        $this->text($sheet, 'A5:L5', 'Всего по учёту, руб.: '.($expectedValue === null ? trans_message('warehouse_basic.inv3.expected_total_price_missing') : str_replace('.', ',', (string) $expectedValue)));
        $this->text($sheet, 'A6:L6', 'Всего по факту, руб.: '.($actualValue === null ? trans_message('warehouse_basic.inv3.actual_total_fact_or_price_missing') : str_replace('.', ',', (string) $actualValue)));
        $this->text($sheet, 'A8:L8', 'Председатель комиссии: должность ________________    Подпись ________________    ФИО ________________________', 26);
        $this->text($sheet, 'A9:L9', 'Члены комиссии:');
        $row = 10;
        foreach ($members as $memberName) {
            $this->text($sheet, "A{$row}:L{$row}", 'Должность ________________    Подпись ________________ / '.$memberName.' /', 26);
            $row++;
        }
        if ($row === 10) {
            $this->text($sheet, 'A10:L10', 'Должность ________________    Подпись ________________    ФИО ________________________', 26);
            $row++;
        }
        if ($memberCount > count($members)) {
            $this->text($sheet, "A{$row}:L{$row}", trans_message('warehouse_basic.inv3.commission_continuation'));
            $row++;
        }
        $row++;
        $this->text($sheet, "A{$row}:L{$row}", 'ОТМЕТКА ПРОВЕРКИ ЦЕН И ИТОГОВ (заполняется проверившим лицом)', 24);
        $this->setBold($sheet, "A{$row}");
        $row++;
        $this->text($sheet, "A{$row}:L{$row}", 'Указанные в описи цены, стоимость по строкам и итоговые подсчёты проверены.');
        $row++;
        $this->text($sheet, "A{$row}:L{$row}", 'Должность ________________    Подпись ________________    ФИО ________________________    Дата ________________', 26);
        $row += 2;
        $this->text($sheet, "A{$row}:L{$row}", 'РАСПИСКА МАТЕРИАЛЬНО ОТВЕТСТВЕННОГО ЛИЦА (заполняется и подписывается лично)', 24);
        $this->setBold($sheet, "A{$row}");
        $row++;
        $this->text($sheet, "A{$row}:L{$row}", 'Все ценности, поименованные в настоящей описи, проверены комиссией в моём присутствии и внесены в опись. Претензий к комиссии не имею. Перечисленные в описи ценности приняты на моё ответственное хранение.', 38);
        $row++;
        $this->text($sheet, "A{$row}:L{$row}", 'Должность ________________    Подпись ________________    ФИО ________________________    Дата ________________', 26);
    }

    private function quantityWords(?BigDecimal $quantity): string
    {
        if ($quantity === null) {
            return trans_message('warehouse_basic.inv3.quantity_words_missing_facts');
        }
        $parts = explode('.', (string) $quantity->toScale(3, RoundingMode::UNNECESSARY));
        $whole = NumberToWordsHelper::integerToWords((int) $parts[0]);
        if ($parts[1] === '000') {
            return $whole;
        }
        $whole = preg_replace(['/один$/iu', '/два$/iu'], ['одна', 'две'], $whole) ?? $whole;
        $whole = mb_strtoupper(mb_substr($whole, 0, 1)).mb_substr($whole, 1);
        $fraction = mb_strtolower(NumberToWordsHelper::integerToWords((int) $parts[1]));
        $fraction = preg_replace(['/один$/u', '/два$/u'], ['одна', 'две'], $fraction) ?? $fraction;
        $wholeEnding = (int) $parts[0] % 10 === 1 && (int) $parts[0] % 100 !== 11 ? ' целая ' : ' целых ';
        $fractionEnding = (int) $parts[1] % 10 === 1 && (int) $parts[1] % 100 !== 11 ? ' тысячная' : ' тысячных';

        return $whole.$wholeEnding.$fraction.$fractionEnding;
    }

    private function itemDetails(InventoryActItem $item): string
    {
        return implode("\n", array_filter([$item->location_code, $item->batch_number, $item->notes], static fn ($value) => $value !== null && $value !== ''));
    }

    private function displayItemDetails(InventoryActItem $item, int $number): string
    {
        $details = $this->itemDetails($item);

        return $this->hasExtendedNotes($details) ? trans_message('warehouse_basic.inv3.extended_item_reference', ['number' => $number]) : $details;
    }

    private function itemHeight(InventoryActItem $item, int $number): float
    {
        $name = $item->material->name."\n".($item->material->code ?? '');
        $details = $this->displayItemDetails($item, $number);
        $nameLines = array_sum(array_map(static fn (string $line): int => max(1, (int) ceil(mb_strlen($line) / 29)), preg_split('/\R/u', $name) ?: ['']));
        $detailLines = array_sum(array_map(static fn (string $line): int => max(1, (int) ceil(mb_strlen($line) / 17)), preg_split('/\R/u', $details) ?: ['']));

        return max(36, (max($nameLines, $detailLines) + 1) * 12);
    }

    private function text(Worksheet $sheet, string $range, string $value, float $height = 18): void
    {
        if (str_contains($range, ':')) {
            $sheet->mergeCells($range);
        }
        $cell = explode(':', $range)[0];
        $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
        $sheet->getStyle($range)->getAlignment()->setWrapText(true)->setShrinkToFit(false);
        $row = $sheet->getCell($cell)->getRow();
        $sheet->getRowDimension($row)->setRowHeight(max($sheet->getRowDimension($row)->getRowHeight(), $height));
    }

    private function number(Worksheet $sheet, string $cell, mixed $value, string $format): void
    {
        if ($value !== null) {
            $decimal = (string) $value;
            $parts = explode('.', $decimal);
            $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ' ', $parts[0]) ?? $parts[0];
            $fraction = $parts[1] ?? '';
            if ($format === '#,##0.###') {
                $fraction = rtrim($fraction, '0');
            }
            $display = $whole.($fraction !== '' ? ','.$fraction : '');
            $width = match ($cell[0]) {
                'A' => 5,
                'H', 'J' => 16,
                default => 12,
            };
            if (strlen(ltrim(str_replace(['.', '-'], '', $decimal), '0')) > 15 || strlen($display) > $width) {
                $sheet->setCellValueExplicit($cell, $display, DataType::TYPE_STRING);
                $sheet->getStyle($cell)->getAlignment()->setWrapText(true)->setShrinkToFit(false);
                $row = $sheet->getCell($cell)->getRow();
                $sheet->getRowDimension($row)->setRowHeight(max($sheet->getRowDimension($row)->getRowHeight(), ceil(strlen($display) / ($width - 2)) * 12 + 4));
            } else {
                $sheet->setCellValueExplicit($cell, $decimal, DataType::TYPE_NUMERIC);
            }
        }
        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode($format);
        $sheet->getStyle($cell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }

    private function applyStyles(Worksheet $sheet): void
    {
        foreach (['A' => 5, 'B' => 12, 'C' => 12, 'D' => 12, 'E' => 10, 'F' => 12, 'G' => 12, 'H' => 16, 'I' => 12, 'J' => 16, 'K' => 12, 'L' => 12] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->getStyle('A1:L'.$sheet->getHighestRow())->getFont()->setName('Arial')->setSize(9)->getColor()->setRGB('000000');
        $sheet->getStyle('A1:L'.$sheet->getHighestRow())->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        foreach ($sheet->getMergeCells() as $range) {
            $this->fitWrappedCell($sheet, $range);
        }
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(1);
        $sheet->getPageSetup()->setPrintArea('A1:L'.$sheet->getHighestRow());
        $sheet->getPageMargins()->setLeft(0.25)->setRight(0.25)->setTop(0.3)->setBottom(0.3);
    }
}
