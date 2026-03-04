<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services\Export\Forms\M7;

use App\BusinessModules\Features\BasicWarehouse\Services\Export\Strategies\BaseWarehouseExportStrategy;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;

/**
 * Стратегия экспорта Акта о приемке материалов (Форма № М-7)
 */
class M7ExportStrategy extends BaseWarehouseExportStrategy
{
    public function export($movement): string
    {
        /** @var WarehouseMovement $movement */
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        $this->setHeader($sheet, $movement);
        $this->setTable($sheet, $movement);
        $this->setFooter($sheet, $movement);
        $this->applyStyles($sheet);
        
        $filename = "M7_" . ($movement->document_number ?: $movement->id) . ".xlsx";
        $path = "exports/warehouse/m7/{$filename}";
        
        return $this->saveSpreadsheetToS3($spreadsheet, $path, $movement->organization);
    }

    public function getSupportedType(): string
    {
        return 'm7';
    }

    protected function setHeader($sheet, WarehouseMovement $movement): void
    {
        $org = $movement->organization;
        
        $sheet->setCellValue('J1', 'Унифицированная форма № М-7');
        $sheet->setCellValue('J2', 'Утверждена постановлением Госкомстата');
        $sheet->setCellValue('J3', 'России от 30.10.97 № 71а');
        $sheet->getStyle('J1:L3')->getFont()->setSize(8);
        
        $sheet->mergeCells('A5:G5');
        $sheet->setCellValue('A5', $org->legal_name ?? $org->name);
        $this->setUnderline($sheet, 'A5:G5');
        $sheet->setCellValue('A6', 'организация - получатель');
        $this->setCenter($sheet, 'A6:G6');
        $sheet->getStyle('A6')->getFont()->setSize(8);

        $sheet->setCellValue('H5', 'Код');
        $sheet->setCellValue('H6', 'Форма по ОКУД');
        $sheet->setCellValue('I6', '0315004');
        $sheet->setCellValue('H7', 'по ОКПО');
        $sheet->setCellValue('I7', $org->okpo ?? '');
        $this->applyTableStyle($sheet, 'H5:I7');
        $this->setCenter($sheet, 'H5:I7');
        
        $sheet->mergeCells('A9:I9');
        $sheet->setCellValue('A9', 'АКТ О ПРИЕМКЕ МАТЕРИАЛОВ № ' . ($movement->document_number ?: $movement->id));
        $this->setBold($sheet, 'A9');
        $this->setCenter($sheet, 'A9');
        $sheet->getStyle('A9')->getFont()->setSize(12);
        
        $sheet->setCellValue('D10', 'Номер документа');
        $sheet->setCellValue('E10', 'Дата составления');
        $sheet->setCellValue('D11', $movement->document_number ?: $movement->id);
        $sheet->setCellValue('E11', $movement->movement_date->format('d.m.Y'));
        $this->applyTableStyle($sheet, 'D10:E11');
        $this->setCenter($sheet, 'D10:E11');
        
        $sheet->setCellValue('A13', 'Место приемки: ' . ($movement->warehouse->name ?? ''));
        $sheet->setCellValue('A14', 'Поставщик: ' . ($movement->metadata['supplier_name'] ?? ''));
        $sheet->setCellValue('A15', 'Сопроводительный документ: ' . ($movement->metadata['invoice_number'] ?? ''));
    }

    protected function setTable($sheet, WarehouseMovement $movement): void
    {
        $row = 17;
        $sheet->setCellValue("A{$row}", 'Материал (наименование, размер, сорт)');
        $sheet->setCellValue("E{$row}", 'Ед. изм.');
        $sheet->setCellValue("F{$row}", 'По документам');
        $sheet->setCellValue("G{$row}", 'Фактически');
        $sheet->setCellValue("H{$row}", 'Недостача');
        $sheet->setCellValue("I{$row}", 'Излишки');
        
        $this->setBold($sheet, "A{$row}:I{$row}");
        $this->setCenter($sheet, "A{$row}:I{$row}");
        $sheet->getStyle("A{$row}:I{$row}")->getAlignment()->setWrapText(true);
        
        $row++;
        $sheet->setCellValue("A{$row}", $movement->material->name);
        $sheet->setCellValue("E{$row}", $movement->material->measurementUnit->name ?? '');
        
        $supplierQty = (float)($movement->metadata['supplier_quantity'] ?? $movement->quantity);
        $actualQty = (float)$movement->quantity;
        $diff = $actualQty - $supplierQty;
        
        $sheet->setCellValue("F{$row}", $supplierQty);
        $sheet->setCellValue("G{$row}", $actualQty);
        $sheet->setCellValue("H{$row}", $diff < 0 ? abs($diff) : '');
        $sheet->setCellValue("I{$row}", $diff > 0 ? $diff : '');
        
        $this->applyTableStyle($sheet, "A17:I{$row}");
    }

    protected function setFooter($sheet, WarehouseMovement $movement): void
    {
        $row = $sheet->getHighestRow() + 2;
        $sheet->setCellValue("A{$row}", 'Комиссия: ____________________');
        $row++;
        $sheet->setCellValue("A{$row}", 'Представитель поставщика: ____________________');
        $sheet->mergeCells("A{$row}:I{$row}");
    }

    protected function applyStyles($sheet): void
    {
        $sheet->getColumnDimension('A')->setWidth(45);
        $sheet->getColumnDimension('E')->setWidth(10);
        $sheet->getColumnDimension('F')->setWidth(12);
        $sheet->getColumnDimension('G')->setWidth(12);
        $sheet->getColumnDimension('H')->setWidth(12);
        $sheet->getColumnDimension('I')->setWidth(12);
        
        $sheet->getStyle('A1:L50')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
    }
}
