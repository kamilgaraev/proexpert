<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services\Export\Forms\INV3;

use App\BusinessModules\Features\BasicWarehouse\Models\InventoryAct;
use App\BusinessModules\Features\BasicWarehouse\Services\Export\InventoryCommissionMemberNameResolver;
use App\BusinessModules\Features\BasicWarehouse\Services\Export\Strategies\BaseWarehouseExportStrategy;
use App\Services\Storage\FileService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Стратегия экспорта Инвентаризационной описи (Форма № ИНВ-3)
 */
class INV3ExportStrategy extends BaseWarehouseExportStrategy
{
    public function __construct(
        FileService $fileService,
        private readonly InventoryCommissionMemberNameResolver $commissionMemberNameResolver,
    ) {
        parent::__construct($fileService);
    }

    public function export($act): string
    {
        /** @var InventoryAct $act */
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $this->setHeader($sheet, $act);
        $this->setTable($sheet, $act);
        $this->setFooter($sheet, $act);
        $this->applyStyles($sheet);

        $filename = 'INV3_'.$this->documentFileSuffix($act->act_number, $act->inventory_date).'.xlsx';
        $path = "exports/warehouse/inv3/{$filename}";

        return $this->saveSpreadsheetToS3($spreadsheet, $path, $act->organization);
    }

    public function getSupportedType(): string
    {
        return 'inv3';
    }

    protected function setHeader(Worksheet $sheet, InventoryAct $act): void
    {
        $org = $act->organization;

        $sheet->setCellValue('J1', 'Унифицированная форма № ИНВ-3');
        $sheet->setCellValue('J2', 'Утверждена постановлением Госкомстата');
        $sheet->setCellValue('J3', 'России от 18.08.98 № 88');
        $sheet->getStyle('J1:L3')->getFont()->setSize(8);

        $sheet->mergeCells('A5:G5');
        $sheet->setCellValue('A5', $org->legal_name ?? $org->name);
        $this->setUnderline($sheet, 'A5:G5');
        $sheet->setCellValue('A6', 'организация');
        $this->setCenter($sheet, 'A6:G6');
        $sheet->getStyle('A6')->getFont()->setSize(8);

        $sheet->setCellValue('H5', 'Код');
        $sheet->setCellValue('H6', 'Форма по ОКУД');
        $sheet->setCellValue('I6', '0317004');
        $sheet->setCellValue('H7', 'по ОКПО');
        $sheet->setCellValue('I7', $org->okpo ?? '');
        $this->applyTableStyle($sheet, 'H5:I7');
        $this->setCenter($sheet, 'H5:I7');

        $sheet->mergeCells('A9:L9');
        $sheet->setCellValue('A9', 'ИНВЕНТАРИЗАЦИОННАЯ ОПИСЬ ТОВАРНО-МАТЕРИАЛЬНЫХ ЦЕННОСТЕЙ');
        $this->setBold($sheet, 'A9');
        $this->setCenter($sheet, 'A9');
        $sheet->getStyle('A9')->getFont()->setSize(12);

        $sheet->mergeCells('D10:F10');
        $sheet->mergeCells('D11:F11');
        $sheet->mergeCells('G10:I10');
        $sheet->mergeCells('G11:I11');
        $sheet->setCellValue('D10', 'Номер документа');
        $sheet->setCellValue('G10', 'Дата составления');
        $sheet->setCellValue('D11', $this->documentNumber($act->act_number));
        $sheet->setCellValue('G11', $act->inventory_date->format('d.m.Y'));
        $this->applyTableStyle($sheet, 'D10:I11');
        $this->setCenter($sheet, 'D10:I11');

        $sheet->setCellValue('A13', 'Местонахождение: '.($act->warehouse->name ?? ''));
    }

    protected function setTable(Worksheet $sheet, InventoryAct $act): void
    {
        $row = 15;
        $this->mergeInventoryColumns($sheet, $row);
        $sheet->setCellValue("A{$row}", '№');
        $sheet->setCellValue("B{$row}", 'ТМЦ (наименование, размер, сорт)');
        $sheet->setCellValue("E{$row}", 'Ед. изм.');
        $sheet->setCellValue("F{$row}", 'По данным учета');
        $sheet->setCellValue("H{$row}", 'Фактическое наличие');
        $sheet->setCellValue("J{$row}", 'Результат (излишки/недостача)');

        $this->setBold($sheet, "A{$row}:L{$row}");
        $this->setCenter($sheet, "A{$row}:L{$row}");
        $sheet->getStyle("A{$row}:L{$row}")->getAlignment()->setWrapText(true);
        $sheet->getRowDimension($row)->setRowHeight(36);

        $row++;
        foreach ($act->items as $index => $item) {
            $this->mergeInventoryColumns($sheet, $row);
            $sheet->setCellValue("A{$row}", $index + 1);
            $sheet->setCellValue("B{$row}", $item->material->name);
            $sheet->setCellValue("E{$row}", $item->material->measurementUnit->name ?? '');
            $sheet->setCellValue("F{$row}", $item->expected_quantity);
            $sheet->setCellValue("H{$row}", $item->actual_quantity);
            $sheet->setCellValue("J{$row}", $item->difference);

            $row++;
        }

        $this->applyTableStyle($sheet, 'A15:L'.($row - 1));
    }

    protected function setFooter(Worksheet $sheet, InventoryAct $act): void
    {
        $row = $sheet->getHighestRow() + 2;
        $sheet->setCellValue("A{$row}", 'Председатель комиссии: ____________________');
        $this->setUnderline($sheet, "B{$row}");
        $row++;
        $sheet->setCellValue("A{$row}", 'Члены комиссии:');
        foreach ($this->commissionMemberNameResolver->resolve($act) as $memberName) {
            $row++;
            $sheet->setCellValue("A{$row}", "- ____________________ / {$memberName} /");
        }
    }

    protected function applyStyles(Worksheet $sheet): void
    {
        $sheet->getColumnDimension('A')->setWidth(5);
        foreach (range('B', 'L') as $column) {
            $sheet->getColumnDimension($column)->setWidth(11);
        }

        $sheet->getStyle('A1:L'.$sheet->getHighestRow())
            ->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)
            ->setFitToHeight(0);
        $sheet->getPageSetup()->setPrintArea('A1:L'.$sheet->getHighestRow());
    }

    private function mergeInventoryColumns(Worksheet $sheet, int $row): void
    {
        $sheet->mergeCells("B{$row}:D{$row}");
        $sheet->mergeCells("F{$row}:G{$row}");
        $sheet->mergeCells("H{$row}:I{$row}");
        $sheet->mergeCells("J{$row}:L{$row}");
    }
}
