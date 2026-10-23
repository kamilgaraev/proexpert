<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services\Export\Forms\M8;

use App\BusinessModules\Features\BasicWarehouse\Models\AssetReservation;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Services\Export\Strategies\BaseWarehouseExportStrategy;
use App\Services\Storage\FileService;
use App\Support\OrganizationBusinessTimezone;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class M8ExportStrategy extends BaseWarehouseExportStrategy
{
    private const PAGE_HEIGHT = 730;

    public function __construct(
        FileService $fileService,
        private readonly OrganizationBusinessTimezone $businessTimezone,
    ) {
        parent::__construct($fileService);
    }

    /**
     * @param  array{reservation: AssetReservation, movements: Collection<int, WarehouseMovement>}  $data
     */
    public function export($data): string
    {
        $reservation = $data['reservation'];
        $movements = $data['movements'];
        $reservation->loadMissing(['organization', 'material.measurementUnit', 'warehouse', 'project']);

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(9);
        $sheet = $spreadsheet->getActiveSheet()->setTitle('Лицевая сторона');
        $this->prepareSheet($sheet);
        $this->setHeader($sheet, $reservation);
        $row = 28;
        $this->issueHeader($sheet, $row);
        $remaining = (float) $reservation->quantity;
        $timezone = $this->businessTimezone->resolve($reservation->organization);

        $rows = $movements->isEmpty() ? collect([null]) : $movements;
        foreach ($rows as $movement) {
            $height = $movement === null ? 25 : $this->issueRowHeight($this->documentNumber($movement->document_number));
            if ($this->usedHeight($sheet, $row) + $height > self::PAGE_HEIGHT) {
                $this->finishSheet($sheet, $row === 28 && $sheet === $spreadsheet->getSheet(0) ? 26 : $row);
                $sheet = $spreadsheet->createSheet()->setTitle('Выдача '.$spreadsheet->getSheetCount());
                $this->prepareSheet($sheet);
                $this->continuationHeader($sheet, $reservation, 'Продолжение выдачи');
                $row = 10;
                $this->issueHeader($sheet, $row);
            }

            $row++;
            $this->mergeIssueRow($sheet, $row);
            if ($movement !== null) {
                $this->text($sheet, "A{$row}:B{$row}", $movement->movement_date->copy()->setTimezone($timezone)->format('d.m.Y'));
                $this->text($sheet, "C{$row}:F{$row}", $this->documentNumber($movement->document_number));
                $sheet->setCellValue("G{$row}", (float) $movement->quantity);
                $remaining -= (float) $movement->quantity;
                $sheet->setCellValue("H{$row}", max(0, $remaining));
                $sheet->getStyle("G{$row}:H{$row}")->getNumberFormat()->setFormatCode('0.###');
                $sheet->getStyle("G{$row}:H{$row}")->getAlignment()->setWrapText(false)->setShrinkToFit(true);
            }
            $sheet->getRowDimension($row)->setRowHeight($height);
            $this->applyTableStyle($sheet, 'A'.($row - 1).":L{$row}");
        }

        $this->finishSheet($sheet, $row);

        $reverse = $spreadsheet->createSheet()->setTitle('Оборотная сторона');
        $this->prepareSheet($reverse);
        $this->continuationHeader($reverse, $reservation, 'Оборотная сторона');
        $this->setReturnsAndSignatures($reverse);
        $this->finishSheet($reverse, 33);
        $spreadsheet->setActiveSheetIndex(0);

        $materialCode = $this->filenameFragment(
            $reservation->material?->code ?? $reservation->material?->name,
            'material'
        );
        $filename = 'M8_'.$materialCode.'_'.$this->documentFileSuffix(
            $reservation->metadata['document_number'] ?? null,
            $reservation->reserved_at ?? $reservation->created_at
        ).'.xlsx';
        $path = "exports/warehouse/m8/{$filename}";

        return $this->saveSpreadsheetToS3($spreadsheet, $path, $reservation->organization_id);
    }

    public function getSupportedType(): string
    {
        return 'm8';
    }

    private function setHeader(Worksheet $sheet, AssetReservation $reservation): void
    {
        $org = $reservation->organization;
        $timezone = $this->businessTimezone->resolve($org);
        $this->text($sheet, 'G1:L1', 'Унифицированная форма № М-8');
        $this->text($sheet, 'G2:L2', 'Утверждена постановлением Госкомстата');
        $this->text($sheet, 'G3:L3', 'России от 30.10.97 № 71а');
        $sheet->getStyle('G1:L3')->getFont()->setSize(8);
        $sheet->getStyle('G1:L3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        foreach (range(1, 3) as $row) {
            $this->fitWrappedCell($sheet, "G{$row}:L{$row}");
        }
        $this->text($sheet, 'A5:I5', $org->legal_name ?: $org->name);
        $this->setUnderline($sheet, 'A5:I5');
        $this->text($sheet, 'A6:I6', 'организация');
        $sheet->getStyle('A6:I6')->getFont()->setSize(8);
        $this->setCenter($sheet, 'A6:I6');
        $this->text($sheet, 'J5:L5', 'Код');
        $this->text($sheet, 'J6:K6', 'Форма по ОКУД');
        $this->text($sheet, 'L6', '0315005');
        $this->text($sheet, 'J7:K7', 'по ОКПО');
        $this->text($sheet, 'L7', $org->okpo ?? '');
        $sheet->getStyle('L6:L7')->getAlignment()->setWrapText(false)->setShrinkToFit(true);
        $sheet->getStyle('J5:L7')->getFont()->setSize(8);
        $this->applyTableStyle($sheet, 'J5:L7');
        $this->setCenter($sheet, 'J5:L7');

        $this->title($sheet, $reservation, 9);
        $this->fields($sheet, 11, [
            'A:B' => 'Дата составления', 'C:D' => 'Код вида операции',
            'E:F' => 'Вид деятельности', 'G:I' => 'Отправитель', 'J:L' => 'Получатель',
        ]);
        $this->text($sheet, 'A12:B12', ($reservation->reserved_at ?? $reservation->created_at)->copy()->setTimezone($timezone)->format('d.m.Y'));
        $this->fields($sheet, 13, [
            'A:D' => 'Корреспондирующий счет, субсчет',
            'E:H' => 'Код аналитического учета', 'I:L' => 'Учетная единица выпуска',
        ]);
        $this->text($sheet, 'A15:L15', 'Склад: '.($reservation->warehouse?->name ?? ''));
        $this->text($sheet, 'A16:L16', 'Проект: '.($reservation->project?->name ?? ''));
        $this->text($sheet, 'A17:L17', 'Основание резерва: '.($reservation->reason ?? ''));
        $this->text($sheet, 'A18:L18', 'Срок резерва: '.($reservation->expires_at?->copy()->setTimezone($timezone)->format('d.m.Y H:i') ?? ''));

        $this->fields($sheet, 20, [
            'A:H' => 'Материальные ценности: наименование, сорт, размер, марка',
            'I:L' => 'Номенклатурный номер',
        ]);
        $this->text($sheet, 'A21:H21', $reservation->material?->name ?? '');
        $this->text($sheet, 'I21:L21', $reservation->material?->code ?? '');
        $this->fields($sheet, 22, ['A:D' => 'Код ОКЕИ', 'E:H' => 'Единица измерения', 'I:L' => 'Лимит']);
        $unit = $reservation->material?->measurementUnit;
        $this->text($sheet, 'E23:H23', $unit?->short_name ?: ($unit?->name ?? ''));
        $sheet->setCellValue('I23', (float) $reservation->quantity);
        $sheet->getStyle('I23:L23')->getNumberFormat()->setFormatCode('0.###');
        $sheet->getStyle('I23:L23')->getAlignment()->setWrapText(false)->setShrinkToFit(true);
        $this->fields($sheet, 24, [
            'A:C' => 'Номер по картотеке', 'D:G' => 'Всего отпущено с учетом возврата',
            'H:I' => 'Цена, руб. коп.', 'J:L' => 'Сумма без учета НДС, руб. коп.',
        ]);
        $this->text($sheet, 'A26:L26', 'Возвраты, стоимость и подписи заполняются по первичным документам.');
        $sheet->getStyle('A26:L26')->getFont()->setSize(8);
        $this->fitWrappedCell($sheet, 'A26:L26');
    }

    private function continuationHeader(Worksheet $sheet, AssetReservation $reservation, string $label): void
    {
        $this->title($sheet, $reservation, 1);
        $this->text($sheet, 'A2:L2', $label);
        $this->setCenter($sheet, 'A2:L2');
        $this->text($sheet, 'A4:L4', 'Организация: '.($reservation->organization->legal_name ?: $reservation->organization->name));
        $this->text($sheet, 'A5:L5', 'Материал: '.($reservation->material?->name ?? ''));
        $this->text($sheet, 'A6:L6', 'Номенклатурный номер: '.($reservation->material?->code ?? ''));
        $this->text($sheet, 'A7:L7', 'Склад: '.($reservation->warehouse?->name ?? ''));
        $unit = $reservation->material?->measurementUnit;
        $this->text($sheet, 'A8:L8', 'Единица измерения: '.($unit?->short_name ?: ($unit?->name ?? '')));
    }

    private function title(Worksheet $sheet, AssetReservation $reservation, int $row): void
    {
        $title = 'ЛИМИТНО-ЗАБОРНАЯ КАРТА № '.$this->documentNumber($reservation->metadata['document_number'] ?? null);
        $this->text($sheet, "A{$row}:L{$row}", $title);
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
        $this->setCenter($sheet, "A{$row}:L{$row}");
        $this->fitWrappedCell($sheet, "A{$row}:L{$row}", max(26, ceil(mb_strwidth($title, 'UTF-8') / 70) * 21));
    }

    private function fields(Worksheet $sheet, int $row, array $fields): void
    {
        foreach ($fields as $columns => $label) {
            [$start, $end] = explode(':', $columns);
            $this->text($sheet, "{$start}{$row}:{$end}{$row}", $label);
            $this->text($sheet, $start.($row + 1).':'.$end.($row + 1), '');
            $this->setCenter($sheet, "{$start}{$row}:{$end}".($row + 1));
        }
        $this->applyTableStyle($sheet, "A{$row}:L".($row + 1));
    }

    private function issueHeader(Worksheet $sheet, int $row): void
    {
        $this->mergeIssueRow($sheet, $row);
        foreach (['A' => 'Дата', 'C' => 'Номер документа', 'G' => 'Отпущено', 'H' => 'Остаток лимита', 'I' => 'Подпись кладовщика', 'K' => 'Подпись получателя'] as $column => $label) {
            $sheet->setCellValueExplicit($column.$row, $label, DataType::TYPE_STRING);
        }
        $sheet->getRowDimension($row)->setRowHeight(50);
        $sheet->getStyle("A{$row}:L{$row}")->getAlignment()->setWrapText(true);
        $this->setCenter($sheet, "A{$row}:L{$row}");
        $this->applyTableStyle($sheet, "A{$row}:L{$row}");
    }

    private function setReturnsAndSignatures(Worksheet $sheet): void
    {
        $this->text($sheet, 'A10:L10', 'Возвращено');
        $this->setBold($sheet, 'A10:L10');
        foreach (['A11:B11' => 'Дата', 'C11:F11' => 'Номер документа', 'G11:H11' => 'Количество', 'I11:J11' => 'Подпись кладовщика', 'K11:L11' => 'Подпись сдавшего'] as $range => $label) {
            $this->text($sheet, $range, $label);
        }
        foreach (range(12, 14) as $row) {
            foreach (['A:B', 'C:F', 'G:H', 'I:J', 'K:L'] as $columns) {
                [$start, $end] = explode(':', $columns);
                $this->text($sheet, "{$start}{$row}:{$end}{$row}", '');
            }
            $sheet->getRowDimension($row)->setRowHeight(26);
        }
        $this->setCenter($sheet, 'A11:L14');
        $this->applyTableStyle($sheet, 'A11:L14');
        $this->text($sheet, 'A16:L16', 'Всего отпущено с учетом возврата: __________________________________');
        $this->text($sheet, 'A18:L18', 'Руководитель подразделения, установившего лимит');
        $this->signatureFields($sheet, 20);
        $this->text($sheet, 'A23:L23', 'Руководитель подразделения, получившего материальные ценности');
        $this->signatureFields($sheet, 25);
        $this->text($sheet, 'A28:L28', 'Заведующий складом');
        $this->signatureFields($sheet, 30);
        $this->text($sheet, 'A33:L33', 'Дата: «_____» __________________ 20_____ г.');
    }

    private function signatureFields(Worksheet $sheet, int $row): void
    {
        foreach (['A:D' => 'должность', 'E:G' => 'подпись', 'H:L' => 'расшифровка подписи'] as $columns => $label) {
            [$start, $end] = explode(':', $columns);
            $this->text($sheet, "{$start}{$row}:{$end}{$row}", '');
            $this->setUnderline($sheet, "{$start}{$row}:{$end}{$row}");
            $this->text($sheet, $start.($row + 1).':'.$end.($row + 1), $label);
            $sheet->getStyle($start.($row + 1))->getFont()->setSize(8);
            $this->setCenter($sheet, $start.($row + 1).':'.$end.($row + 1));
        }
    }

    private function mergeIssueRow(Worksheet $sheet, int $row): void
    {
        foreach (['A:B', 'C:F', 'I:J', 'K:L'] as $columns) {
            [$start, $end] = explode(':', $columns);
            $sheet->mergeCells("{$start}{$row}:{$end}{$row}");
        }
    }

    private function issueRowHeight(string $number): float
    {
        return max(25, (ceil(mb_strwidth($number, 'UTF-8') / 26) + 1) * 12);
    }

    private function text(Worksheet $sheet, string $range, string $value): void
    {
        $anchor = explode(':', $range)[0];
        if (str_contains($range, ':') && !isset($sheet->getMergeCells()[$range])) {
            $sheet->mergeCells($range);
        }
        $sheet->setCellValueExplicit($anchor, $value, DataType::TYPE_STRING);
        $this->fitWrappedCell($sheet, $range);
    }

    private function prepareSheet(Worksheet $sheet): void
    {
        $sheet->setShowGridlines(false);
        foreach (range('A', 'L') as $column) {
            $sheet->getColumnDimension($column)->setWidth(9);
        }
        $sheet->getDefaultRowDimension()->setRowHeight(18);
        $sheet->getPageMargins()->setTop(0.3)->setBottom(0.3)->setLeft(0.3)->setRight(0.3)->setHeader(0.1)->setFooter(0.15);
    }

    private function usedHeight(Worksheet $sheet, int $lastRow): float
    {
        $height = 0.0;
        foreach (range(1, $lastRow) as $row) {
            $value = $sheet->getRowDimension($row)->getRowHeight();
            $height += $value > 0 ? $value : 18;
        }

        return $height;
    }

    private function finishSheet(Worksheet $sheet, int $lastRow): void
    {
        $sheet->getStyle("A1:L{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_PORTRAIT)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0)
            ->setPrintArea("A1:L{$lastRow}");
        $sheet->getHeaderFooter()->setOddFooter('&R&8Страница &P из &N');
    }
}
