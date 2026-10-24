<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services\Export\Forms\M7;

use App\BusinessModules\Features\BasicWarehouse\Enums\WarehouseReceiptVatMode;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Services\Export\Strategies\BaseWarehouseExportStrategy;
use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseReceiptTaxCalculator;
use App\Support\OrganizationBusinessTimezone;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class M7ExportStrategy extends BaseWarehouseExportStrategy
{
    public function export($movementOrCollection): string
    {
        $movements = $movementOrCollection instanceof Collection ? $movementOrCollection : new Collection([$movementOrCollection]);
        $movement = $movements->firstOrFail();
        $book = new Spreadsheet;
        $book->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);
        $this->requisites($this->makeSheet($book->getActiveSheet(), '1. Реквизиты', $movement), $movement);
        $this->inspection($this->makeSheet($book->createSheet(), '2. Осмотр', $movement));
        $this->materials($this->makeSheet($book->createSheet(), '3. Материалы', $movement, true), $movements, $movement);
        $this->commission($this->makeSheet($book->createSheet(), '4. Комиссия', $movement));
        foreach ($book->getAllSheets() as $index => $sheet) {
            $this->finish($sheet, $movement, $index + 1);
        }
        $book->setActiveSheetIndex(0);
        $filename = 'M7_'.$this->documentFileSuffix($movement->document_number, $this->businessDate($movement)).'.xlsx';
        $directory = (string) Str::uuid();

        return $this->saveSpreadsheetToS3($book, "exports/warehouse/m7/{$directory}/{$filename}", $movement->organization);
    }

    public function getSupportedType(): string
    {
        return 'm7';
    }

    private function makeSheet(Worksheet $sheet, string $title, WarehouseMovement $movement, bool $landscape = false): Worksheet
    {
        $sheet->setTitle($title)->setShowGridlines(false);
        $sheet->getDefaultRowDimension()->setRowHeight(17);
        $widths = $landscape ? [23, 12, 5, 7, 4, 9, 10, 11, 4, 9, 10, 11, 9, 9, 11, 9, 11, 9, 11, 12] : array_fill(0, 12, 8);
        foreach ($widths as $index => $width) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth($width);
        }
        $last = $landscape ? 'T' : 'L';
        $identity = 'М-7 № '.$this->documentNumber($movement->document_number).' от '.$this->businessDate($movement)->format('d.m.Y');
        $this->text($sheet, "A1:{$last}1", $identity);
        $this->setBold($sheet, "A1:{$last}1");
        $this->text($sheet, "A2:{$last}2", 'Организация: '.($movement->organization->legal_name ?: $movement->organization->name));
        $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setOrientation($landscape ? PageSetup::ORIENTATION_LANDSCAPE : PageSetup::ORIENTATION_PORTRAIT)
            ->setScale($landscape ? 70 : 100)->setFitToPage(false)
            ->setRowsToRepeatAtTop($landscape ? [8, 11] : [1, 2]);
        $sheet->getPageMargins()->setTop(0.25)->setBottom(0.3)->setLeft(0.2)->setRight(0.2)->setHeader(0.1)->setFooter(0.15);

        return $sheet;
    }

    private function requisites(Worksheet $sheet, WarehouseMovement $movement): void
    {
        $sheet->getStyle('A11:L23')->getFont()->setSize(9);
        foreach (['H6:L6', 'H8:L8', 'H9:L9'] as $range) {
            $sheet->getStyle($range)->getFont()->setSize(8);
        }
        $sheet->getStyle('A3:L3')->getFont()->setSize(8);
        $this->text($sheet, 'A3:L3', 'Типовая межотраслевая форма № М-7. Постановление Госкомстата России от 30.10.1997 № 71а.');
        $this->text($sheet, 'A4:G4', 'АКТ О ПРИЕМКЕ МАТЕРИАЛОВ');
        $this->setBold($sheet, 'A4:G4');
        $this->text($sheet, 'H4:L4', 'УТВЕРЖДАЮ');
        foreach ([5 => 'должность', 7 => 'подпись, расшифровка подписи'] as $row => $label) {
            $this->text($sheet, "H{$row}:L{$row}", '________________________');
            $this->text($sheet, 'H'.($row + 1).':L'.($row + 1), $label);
        }
        $this->text($sheet, 'H9:L9', 'Дата утверждения: __________________');
        $lines = [
            11 => 'Форма по ОКУД: 0315004. По ОКПО: '.($movement->organization->okpo ?? ''),
            12 => 'Место составления акта: '.($movement->warehouse->name ?? ''),
            13 => 'Начало приемки: ____ ч. ____ мин. Окончание приемки: ____ ч. ____ мин.',
            14 => 'Сопроводительные документы прибывшего груза: '.$this->documentText($movement, 'invoice_number'),
            15 => 'Удостоверение о качестве (сертификат) №: ______________________________',
            16 => 'Станция (пристань): ______ Транспортный документ №: ______ от ______ Вагоны №: ______',
            17 => 'Отправитель (наименование, адрес, телефон): ___________________________',
            18 => 'Поставщик (наименование, адрес, телефон): '.$this->documentText($movement, 'supplier_name'),
            19 => 'Получатель: '.($movement->organization->legal_name ?: $movement->organization->name),
            20 => 'Страховая компания (наименование, адрес, телефон): ____________________',
            21 => 'Дата отправки продукции со станции (пристани, порта) или со склада отправителя: __________',
            22 => 'Договор №: ______________ от ______________ на поставку продукции',
            23 => 'Дата и номер телефонограммы или телеграммы о вызове отправителя (заготовителя): __________',
            25 => 'По сопроводительным транспортным документам значилось',
            33 => 'Дата и время, ч. мин.',
        ];
        foreach ($lines as $row => $line) {
            $this->text($sheet, "A{$row}:L{$row}", $line);
        }
        foreach (['A26:A27' => 'Отметка об опломбировании груза', 'B26:B27' => 'Количество мест', 'C26:D27' => 'Вид упаковки',
            'E26:G27' => 'Наименование продукции, товара (груза) или номера контейнеров', 'H26:H27' => 'Единица измерения',
            'I26:J26' => 'Масса груза, т', 'I27' => 'отправителя', 'J27' => 'дороги, пристани',
            'K26:L27' => 'Особые отметки отправителя по накладной'] as $range => $label) {
            $this->text($sheet, $range, $label);
        }
        foreach (['A', 'B', 'C', 'E', 'H', 'I', 'J', 'K'] as $index => $column) {
            $sheet->setCellValue($column.'28', $index + 1);
        }
        foreach (range(29, 31) as $row) {
            foreach (['C' => 'D', 'E' => 'G', 'K' => 'L'] as $start => $end) {
                $sheet->mergeCells("{$start}{$row}:{$end}{$row}");
            }
        }
        $this->applyTableStyle($sheet, 'A26:L31');
        $sheet->getStyle('A26:L27')->getFont()->setSize(9);
        foreach (['A' => ['C', 'Прибытия на станцию (пристань, порт) назначения'], 'D' => ['F', 'Выдачи груза органом транспорта'],
            'G' => ['I', 'Вскрытия вагона и других транспортных средств'], 'J' => ['L', 'Доставки на склад получателя']] as $start => [$end, $label]) {
            $this->text($sheet, "{$start}34:{$end}35", $label);
            foreach ([36, 37] as $row) {
                $sheet->mergeCells("{$start}{$row}:{$end}{$row}");
            }
        }
        foreach (['A', 'D', 'G', 'J'] as $index => $column) {
            $sheet->setCellValue($column.'36', $index + 1);
        }
        $this->applyTableStyle($sheet, 'A34:L37');
        foreach ([26, 27, 34, 35] as $row) {
            $sheet->getRowDimension($row)->setRowHeight(36);
        }
        foreach ([10, 24, 32] as $row) {
            $sheet->getRowDimension($row)->setRowHeight(8);
        }
    }

    private function inspection(Worksheet $sheet): void
    {
        foreach ([4 => [10, 'Условия хранения продукции на складе получателя'], 12 => [18, 'Состояние тары и упаковки в момент осмотра продукции'],
            20 => [24, 'Количество недостающей продукции определено (взвешиванием, счетом мест, обмером и т.п.)'], 26 => [35, 'Другие данные']] as $row => [$end, $label]) {
            $this->text($sheet, "A{$row}:L{$row}", $label);
            $this->blankLines($sheet, $row + 1, $end);
        }
    }

    private function materials(Worksheet $sheet, Collection $movements, WarehouseMovement $movement): void
    {
        foreach (['A4:B4' => ['Код вида операции', ''], 'C4:E4' => ['Структурное подразделение', ''], 'F4:G4' => ['Вид деятельности', ''],
            'H4:J4' => ['Склад', $movement->warehouse->code ?? ''], 'K4:L4' => ['Код поставщика', ''],
            'M4:O4' => ['Корреспондирующий счет (счет, субсчет)', ''], 'P4:Q4' => ['Код аналитического учета', ''],
            'R4:T4' => ['Номер сопроводительного документа', $this->documentText($movement, 'invoice_number')]] as $range => [$label, $value]) {
            $this->text($sheet, $range, $label);
            $this->text($sheet, str_replace('4', '5', $range), $value);
        }
        $this->applyTableStyle($sheet, 'A4:T5');
        $this->text($sheet, 'A7:T7', 'Цена и сумма в графах 11–12 приведены по введённой цене прихода; налоговый режим указан в графе 1. Документальная цена и суммы расхождений без подтверждённых исходных данных не заполняются.');
        $this->text($sheet, 'A8:T8', (string) $sheet->getCell('A1')->getValue().' • '.($movement->organization->legal_name ?: $movement->organization->name).' • Склад '.($movement->warehouse->code ?? ''));
        $headers = ['A9:B9' => 'Материальные ценности', 'C9:D9' => 'Единица измерения', 'E9:H9' => 'По документам', 'I9:L9' => 'Фактически оказалось',
            'M9:M10' => 'Порядковый номер записи по складской картотеке', 'N9:O9' => 'Брак и бой', 'P9:Q9' => 'Недостачи', 'R9:S9' => 'Излишки', 'T9:T10' => 'Номер паспорта',
            'A10' => 'наименование', 'B10' => 'номенклатурный номер', 'C10' => 'код', 'D10' => 'наименование',
            'E10' => 'сорт', 'F10' => 'количество', 'G10' => 'цена, руб. коп.', 'H10' => 'сумма, руб. коп.',
            'I10' => 'сорт', 'J10' => 'количество', 'K10' => 'цена, руб. коп.', 'L10' => 'сумма, руб. коп.',
            'N10' => 'количество', 'O10' => 'сумма, руб. коп.', 'P10' => 'количество', 'Q10' => 'сумма, руб. коп.', 'R10' => 'количество', 'S10' => 'сумма, руб. коп.'];
        foreach ($headers as $range => $label) {
            $this->text($sheet, $range, $label, false);
        }
        foreach (range(1, 20) as $column) {
            $sheet->setCellValue([$column, 11], $column);
        }
        $row = 11;
        foreach ($movements as $line) {
            $row++;
            $this->text($sheet, "A{$row}", $line->material->name."\n".$this->taxLabel($line));
            $this->text($sheet, "B{$row}", (string) ($line->material->code ?? ''));
            $unit = $line->material->measurementUnit;
            $this->text($sheet, "D{$row}", ($unit?->short_name ?: $unit?->name) ?? '');
            $actual = BigDecimal::of((string) $line->quantity);
            $this->quantity($sheet, "J{$row}", $actual);
            $document = $this->sourceQuantity($this->documentValue($line, 'supplier_quantity'));
            if ($document !== null) {
                $this->quantity($sheet, "F{$row}", $document);
                $difference = $actual->minus($document);
                if (! $difference->isZero()) {
                    $this->quantity($sheet, ($difference->isNegative() ? 'P' : 'R').$row, $difference->abs());
                }
            }
            if ($line->price !== null) {
                $this->text($sheet, "K{$row}", $this->decimalText((string) $line->price, true), false);
                $this->text($sheet, "L{$row}", $this->decimalText((string) $actual->multipliedBy((string) $line->price)->toScale(2, RoundingMode::HALF_UP), true), false);
            }
        }
        $this->applyTableStyle($sheet, "A9:T{$row}");
        $this->setCenter($sheet, 'A9:T11');
        $this->setBold($sheet, 'A9:T11');
        $sheet->getStyle('M9:M10')->getFont()->setSize(9);
        $sheet->getRowDimension(9)->setRowHeight(34);
        $sheet->getRowDimension(10)->setRowHeight(68);
        $sheet->getRowDimension(11)->setRowHeight(18);
        $sheet->getRowDimension(3)->setRowHeight(8);
        $sheet->getRowDimension(6)->setRowHeight(8);
    }

    private function commission(Worksheet $sheet): void
    {
        $this->text($sheet, 'A4:L4', 'Заключение комиссии:');
        $this->blankLines($sheet, 5, 10);
        $this->text($sheet, 'A12:L12', 'Приложение. Перечень прилагаемых документов');
        $this->blankLines($sheet, 13, 16);
        $sheet->getStyle('A18:L18')->getFont()->setSize(9);
        $this->text($sheet, 'A18:L18', 'С правилами приемки материальных ценностей по количеству, качеству и комплектности все члены комиссии ознакомлены и предупреждены, что они несут ответственность за подписание акта, содержащего данные, не соответствующие действительности.');
        foreach ([20, 24, 28] as $row) {
            $sheet->getStyle('A'.($row + 1).':L'.($row + 1))->getFont()->setSize(8);
            foreach (['A' => ['B', 'должность'], 'C' => ['D', 'подпись'], 'E' => ['G', 'расшифровка подписи'],
                'H' => ['L', 'номер и дата выдачи документа о полномочиях и наименование организации, выдавшей документы']] as $start => [$end, $label]) {
                $this->text($sheet, "{$start}{$row}:{$end}{$row}", '');
                $this->setUnderline($sheet, "{$start}{$row}:{$end}{$row}");
                $this->text($sheet, $start.($row + 1).':'.$end.($row + 1), $label);
            }
        }
        $this->text($sheet, 'A33:L33', 'Материальные ценности приняты и оприходованы: ________________________');
        $this->text($sheet, 'A35:L35', 'Коммерческий акт №: __________________ от __________________');
        $this->text($sheet, 'A37:D37', 'Заведующий складом');
        $this->text($sheet, 'E37:G37', '________________');
        $this->text($sheet, 'H37:L37', '________________');
        $this->text($sheet, 'E38:G38', 'подпись');
        $this->text($sheet, 'H38:L38', 'расшифровка подписи');
    }

    private function finish(Worksheet $sheet, WarehouseMovement $movement, int $part): void
    {
        $last = $part === 3 ? 'T' : 'L';
        $lastRow = $sheet->getHighestRow();
        $sheet->getStyle("A1:{$last}{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        if ($part === 3) {
            foreach (range(12, $lastRow) as $row) {
                foreach (['F', 'J', 'K', 'L', 'P', 'R'] as $column) {
                    $cell = "{$column}{$row}";
                    $wrapped = str_contains((string) $sheet->getCell($cell)->getValue(), ' ');
                    $sheet->getStyle($cell)->getAlignment()->setWrapText($wrapped)->setShrinkToFit(! $wrapped)->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                    if ($wrapped) {
                        $this->fitWrappedCell($sheet, $cell, 14);
                    }
                }
            }
        }
        $sheet->getPageSetup()->setPrintArea("A1:{$last}{$lastRow}");
        $number = str_replace('&', '&&', $this->documentNumber($movement->document_number));
        $sheet->getHeaderFooter()->setOddFooter('&L&8М-7 № '.$number.' • Раздел '.$part.'&R&8Страница &P из &N');
        $blocks = match ($part) {
            1 => [25 => 29, 33 => 37], 2 => [4 => 5, 12 => 13, 20 => 21, 26 => 27], 4 => [18 => 21, 24 => 25, 28 => 29, 37 => 38], default => []
        };
        $this->paginate($sheet, $part === 3 ? 12 : 3, $blocks);
    }

    private function text(Worksheet $sheet, string $range, string $value, bool $fit = true): void
    {
        if (str_contains($range, ':')) {
            $sheet->mergeCells($range);
        }
        $sheet->setCellValueExplicit(explode(':', $range)[0], $value, DataType::TYPE_STRING);
        [$start, $end] = Coordinate::rangeBoundaries($range);
        if ($fit && $start[1] === $end[1]) {
            $this->fitWrappedCell($sheet, $range, 14);
        }
    }

    private function blankLines(Worksheet $sheet, int $start, int $end): void
    {
        foreach (range($start, $end) as $row) {
            $sheet->mergeCells("A{$row}:L{$row}");
            $this->setUnderline($sheet, "A{$row}:L{$row}");
        }
    }

    private function quantity(Worksheet $sheet, string $cell, BigDecimal $value): void
    {
        if (strlen((string) $value) > 8 || $value->compareTo(BigDecimal::of((string) (float) (string) $value)) !== 0) {
            $this->text($sheet, $cell, $this->decimalText((string) $value, true), false);
        } else {
            $sheet->setCellValue($cell, (string) $value);
        }
    }

    private function sourceQuantity(mixed $value): ?BigDecimal
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }
        if (is_float($value) && ! is_finite($value)) {
            return null;
        }
        $text = is_float($value) ? json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) : trim((string) $value);
        if (strlen($text) > 64 || ! preg_match('/^[+-]?\d+(?:\.\d+)?(?:[eE][+-]?\d{1,2})?$/D', $text)) {
            return null;
        }
        try {
            $quantity = BigDecimal::of($text);
            if ($quantity->isNegative() || $quantity->isGreaterThan('999999999999.999')) {
                return null;
            }

            return $quantity->toScale(3, RoundingMode::UNNECESSARY);
        } catch (MathException) {
            return null;
        }
    }

    private function documentValue(WarehouseMovement $movement, string $key): mixed
    {
        $metadata = $movement->metadata ?? [];
        $entered = is_array($metadata['metadata'] ?? null) ? $metadata['metadata'] : [];

        return $metadata[$key] ?? $entered[$key] ?? null;
    }

    private function documentText(WarehouseMovement $movement, string $key): string
    {
        $value = $this->documentValue($movement, $key);

        return is_string($value) || is_int($value) ? (string) $value : '';
    }

    private function businessDate(WarehouseMovement $movement): \Carbon\CarbonInterface
    {
        return $movement->movement_date->copy()->setTimezone((new OrganizationBusinessTimezone)->resolve($movement->organization));
    }

    private function taxLabel(WarehouseMovement $movement): string
    {
        $unknown = 'Налоговый режим не указан';
        $snapshot = $movement->metadata['receipt_tax'] ?? null;
        if (! is_array($snapshot) || ($snapshot['version'] ?? null) !== 1 || $movement->price === null) {
            return $unknown;
        }
        $mode = is_string($snapshot['mode'] ?? null) ? WarehouseReceiptVatMode::tryFrom($snapshot['mode']) : null;
        if ($mode === null || (isset($snapshot['rate']) && ! is_string($snapshot['rate']))) {
            return $unknown;
        }
        try {
            $expected = (new WarehouseReceiptTaxCalculator)->calculate((string) $movement->quantity, (string) $movement->price, $mode, $snapshot['rate'] ?? null)->toArray();
        } catch (\InvalidArgumentException) {
            return $unknown;
        }
        foreach ($expected as $key => $value) {
            if (($snapshot[$key] ?? null) !== $value) {
                return $unknown;
            }
        }

        return match ($mode) {
            WarehouseReceiptVatMode::None => 'Цена без НДС',
            WarehouseReceiptVatMode::Included => 'Цена включает НДС '.$this->decimalText($expected['rate']).'%',
            WarehouseReceiptVatMode::OnTop => 'НДС '.$this->decimalText($expected['rate']).'% начисляется сверх цены',
        };
    }

    private function decimalText(string $value, bool $group = false): string
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        if ($group) {
            $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ' ', $whole) ?? $whole;
        }

        return $whole.($fraction !== '' ? ','.$fraction : '');
    }

    private function paginate(Worksheet $sheet, int $start, array $blocks): void
    {
        $scale = $sheet->getPageSetup()->getScale() / 100;
        $pageHeight = $sheet->getPageSetup()->getOrientation() === PageSetup::ORIENTATION_LANDSCAPE ? 595.28 : 841.89;
        $margins = $sheet->getPageMargins();
        $capacity = ($pageHeight - 72 * ($margins->getTop() + $margins->getBottom())) / $scale * 0.98;
        $height = static function (int $first, int $last) use ($sheet): float {
            $sum = 0.0;
            foreach (range($first, $last) as $row) {
                $rowHeight = $sheet->getRowDimension($row)->getRowHeight();
                $sum += $rowHeight < 0 ? 17 : $rowHeight;
            }

            return $sum;
        };
        [$repeatStart, $repeatEnd] = $sheet->getPageSetup()->getRowsToRepeatAtTop();
        $repeated = $height((int) $repeatStart, (int) $repeatEnd);
        $used = $height(1, $start - 1);
        foreach (range($start, $sheet->getHighestRow()) as $row) {
            $required = $height($row, $blocks[$row] ?? $row);
            if ($used + $required > $capacity && $used > $repeated) {
                $sheet->setBreak('A'.($row - 1), Worksheet::BREAK_ROW);
                $used = $repeated;
            }
            $used += $height($row, $row);
        }
    }
}
