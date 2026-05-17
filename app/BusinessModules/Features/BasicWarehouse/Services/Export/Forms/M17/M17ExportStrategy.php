<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services\Export\Forms\M17;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Services\Export\Strategies\BaseWarehouseExportStrategy;
use App\Models\Material;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class M17ExportStrategy extends BaseWarehouseExportStrategy
{
    public function export($data): string
    {
        /** @var Material $material */
        $material = $data['material'];
        /** @var OrganizationWarehouse|null $warehouse */
        $warehouse = $data['warehouse'] ?? null;
        /** @var Collection<int, WarehouseMovement> $movements */
        $movements = $data['movements'];
        $warehouseId = (int) ($warehouse?->id ?? $data['warehouse_id']);

        $material->loadMissing(['organization', 'measurementUnit']);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('М-17');
        $sheet->setShowGridlines(false);

        $this->setHeader($sheet, $material, $warehouse);
        $lastRow = $this->setTable($sheet, $movements);
        $this->applyStyles($sheet, $lastRow);

        $filename = "M17_{$material->id}_W{$warehouseId}.xlsx";
        $path = "exports/warehouse/m17/{$filename}";

        return $this->saveSpreadsheetToS3($spreadsheet, $path, $material->organization);
    }

    public function getSupportedType(): string
    {
        return 'm17';
    }

    private function setHeader(Worksheet $sheet, Material $material, ?OrganizationWarehouse $warehouse): void
    {
        $organization = $material->organization;
        $organizationName = $organization->legal_name ?? $organization->name;
        $okpo = $organization->okpo ?? '';
        $unit = $material->measurementUnit;

        $sheet->mergeCells('N1:S1');
        $sheet->setCellValue('N1', 'Типовая межотраслевая форма № М-17');
        $sheet->mergeCells('N2:S2');
        $sheet->setCellValue('N2', 'Утверждена постановлением Госкомстата России от 30.10.97 № 71а');

        $sheet->setCellValue('Q3', 'Коды');
        $sheet->setCellValue('P4', 'Форма по ОКУД');
        $sheet->setCellValue('Q4', '0315008');
        $sheet->setCellValue('P5', 'по ОКПО');
        $sheet->setCellValue('Q5', $okpo);
        $sheet->setCellValue('P6', 'Дата распечатки');
        $sheet->setCellValue('Q6', now()->format('d'));
        $sheet->setCellValue('R6', now()->format('m'));
        $sheet->setCellValue('S6', now()->format('Y'));
        $this->applyTableStyle($sheet, 'Q3:S6');
        $this->setCenter($sheet, 'Q3:S6');

        $sheet->mergeCells('F4:H4');
        $sheet->setCellValue('F4', 'КАРТОЧКА №');
        $sheet->mergeCells('F5:H5');
        $sheet->setCellValue('F5', 'учета материалов');
        $this->setBold($sheet, 'F4:H5');
        $this->setCenter($sheet, 'F4:H5');

        $sheet->setCellValue('A8', 'Организация');
        $sheet->mergeCells('B8:O8');
        $sheet->setCellValue('B8', $organizationName);
        $this->setUnderline($sheet, 'B8:O8');

        $sheet->setCellValue('A9', 'Структурное подразделение');
        $sheet->mergeCells('C9:O9');
        $sheet->setCellValue('C9', 'основное');
        $this->setUnderline($sheet, 'C9:O9');

        $this->buildMaterialHeader($sheet, $material, $warehouse, $unit?->short_name ?? $unit?->name ?? '');
    }

    private function buildMaterialHeader(
        Worksheet $sheet,
        Material $material,
        ?OrganizationWarehouse $warehouse,
        string $unitName
    ): void {
        $sheet->mergeCells('A11:B13');
        $sheet->setCellValue('A11', 'Структурное подразделение');
        $sheet->mergeCells('C11:D13');
        $sheet->setCellValue('C11', 'Вид деятельности');
        $sheet->mergeCells('E11:F13');
        $sheet->setCellValue('E11', 'Склад');
        $sheet->mergeCells('G11:H11');
        $sheet->setCellValue('G11', 'Место хранения');
        $sheet->setCellValue('G12', 'стеллаж');
        $sheet->setCellValue('H12', 'ячейка');
        $sheet->mergeCells('I11:I13');
        $sheet->setCellValue('I11', 'Марка');
        $sheet->mergeCells('J11:J13');
        $sheet->setCellValue('J11', 'Сорт');
        $sheet->mergeCells('K11:K13');
        $sheet->setCellValue('K11', 'Профиль');
        $sheet->mergeCells('L11:L13');
        $sheet->setCellValue('L11', 'Размер');
        $sheet->mergeCells('M11:M13');
        $sheet->setCellValue('M11', 'Номенклатурный номер');
        $sheet->mergeCells('N11:O11');
        $sheet->setCellValue('N11', 'Единица измерения');
        $sheet->setCellValue('N12', 'код');
        $sheet->setCellValue('O12', 'наименование');
        $sheet->mergeCells('P11:P13');
        $sheet->setCellValue('P11', 'Цена, руб. коп.');
        $sheet->mergeCells('Q11:Q13');
        $sheet->setCellValue('Q11', 'Норма запаса');
        $sheet->mergeCells('R11:R13');
        $sheet->setCellValue('R11', 'Срок годности');
        $sheet->mergeCells('S11:S13');
        $sheet->setCellValue('S11', 'Поставщик');

        $sheet->mergeCells('A14:B14');
        $sheet->setCellValue('A14', 'основное');
        $sheet->mergeCells('E14:F14');
        $sheet->setCellValue('E14', $warehouse?->name ?? '');
        $sheet->setCellValue('M14', $material->code ?? '');
        $sheet->setCellValue('O14', $unitName);
        $sheet->setCellValue('P14', $material->default_price !== null ? (float) $material->default_price : null);

        $sheet->setCellValue('A17', 'Наименование материала');
        $sheet->mergeCells('D17:K17');
        $sheet->setCellValue('D17', $material->name);
        $this->setBold($sheet, 'D17');
        $this->setUnderline($sheet, 'A17:K17');

        $this->applyTableStyle($sheet, 'A11:S14');
        $this->setCenter($sheet, 'A11:S14');
        $sheet->getStyle('A11:S14')->getAlignment()->setWrapText(true);
    }

    private function setTable(Worksheet $sheet, Collection $movements): int
    {
        $startRow = 22;
        $sheet->mergeCells('A22:A24');
        $sheet->setCellValue('A22', 'Дата записи');
        $sheet->mergeCells('B22:C22');
        $sheet->setCellValue('B22', 'Номер');
        $sheet->setCellValue('B23', 'документа');
        $sheet->setCellValue('C23', 'по порядку');
        $sheet->mergeCells('D22:L24');
        $sheet->setCellValue('D22', 'От кого получено или кому отпущено');
        $sheet->mergeCells('M22:M24');
        $sheet->setCellValue('M22', 'Учетная ед.');
        $sheet->mergeCells('N22:N24');
        $sheet->setCellValue('N22', 'Приход');
        $sheet->mergeCells('O22:O24');
        $sheet->setCellValue('O22', 'Расход');
        $sheet->mergeCells('P22:P24');
        $sheet->setCellValue('P22', 'Остаток');
        $sheet->mergeCells('Q22:R24');
        $sheet->setCellValue('Q22', 'Подпись, дата');
        $sheet->mergeCells('S22:S24');
        $sheet->setCellValue('S22', 'Примечание');

        foreach (range('A', 'S') as $index => $column) {
            $sheet->setCellValue($column . '25', $index + 1);
        }
        $sheet->mergeCells('A26:L27');
        $sheet->mergeCells('M26:M27');
        $sheet->setCellValue('M26', 'Нач. ост:');

        $row = 28;
        $balance = 0.0;
        $incomeTotal = 0.0;
        $expenseTotal = 0.0;
        $number = 1;

        foreach ($movements as $movement) {
            $isIncome = in_array($movement->movement_type, [
                WarehouseMovement::TYPE_RECEIPT,
                WarehouseMovement::TYPE_TRANSFER_IN,
                WarehouseMovement::TYPE_RETURN,
            ], true);
            $quantity = (float) $movement->quantity;

            if ($isIncome) {
                $balance += $quantity;
                $incomeTotal += $quantity;
            } else {
                $balance -= $quantity;
                $expenseTotal += $quantity;
            }

            $sheet->setCellValue("A{$row}", $movement->movement_date?->format('d.m.Y'));
            $sheet->setCellValue("B{$row}", $movement->document_number ?: $movement->id);
            $sheet->setCellValue("C{$row}", $number);
            $sheet->mergeCells("D{$row}:L{$row}");
            $sheet->setCellValue("D{$row}", $this->movementSourceText($movement, $isIncome));
            $sheet->setCellValue("N{$row}", $isIncome ? $quantity : null);
            $sheet->setCellValue("O{$row}", $isIncome ? null : $quantity);
            $sheet->setCellValue("P{$row}", $balance);

            $row++;
            $number++;
        }

        $sheet->mergeCells("A{$row}:M{$row}");
        $sheet->setCellValue("M{$row}", 'Всего:');
        $sheet->setCellValue("N{$row}", $incomeTotal);
        $sheet->setCellValue("O{$row}", $expenseTotal);
        $sheet->setCellValue("P{$row}", $balance);
        $this->setBold($sheet, "M{$row}:P{$row}");

        $this->applyTableStyle($sheet, "A22:S{$row}");
        $this->setCenter($sheet, 'A22:S27');
        $sheet->getStyle("A22:S{$row}")->getAlignment()->setWrapText(true);
        $sheet->getStyle("N28:P{$row}")->getNumberFormat()->setFormatCode('#,##0.000');

        return $row;
    }

    private function movementSourceText(WarehouseMovement $movement, bool $isIncome): string
    {
        if ($movement->reason) {
            return $movement->reason;
        }

        if ($isIncome) {
            return $movement->metadata['supplier_name'] ?? 'Получено';
        }

        return $movement->metadata['recipient'] ?? 'Выдано';
    }

    private function applyStyles(Worksheet $sheet, int $lastRow): void
    {
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)
            ->setFitToHeight(0)
            ->setPrintArea("A1:S{$lastRow}");
        $sheet->getPageMargins()
            ->setTop(0.25)
            ->setRight(0.2)
            ->setBottom(0.25)
            ->setLeft(0.2)
            ->setHeader(0.1)
            ->setFooter(0.1);

        $widths = [
            'A' => 11,
            'B' => 12,
            'C' => 6,
            'D' => 13,
            'E' => 13,
            'F' => 13,
            'G' => 8,
            'H' => 8,
            'I' => 7,
            'J' => 7,
            'K' => 8,
            'L' => 8,
            'M' => 12,
            'N' => 10,
            'O' => 10,
            'P' => 10,
            'Q' => 8,
            'R' => 8,
            'S' => 10,
        ];

        foreach ($widths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        foreach (range(1, $lastRow) as $row) {
            $sheet->getRowDimension($row)->setRowHeight($row >= 22 && $row <= 27 ? 18 : 16);
        }

        $sheet->getStyle("A1:S{$lastRow}")->getFont()->setName('Arial')->setSize(8);
        $sheet->getStyle("A1:S{$lastRow}")
            ->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle("N1:S6")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle("A28:C{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("N28:P{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle("A22:S27")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A28:S{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }
}
