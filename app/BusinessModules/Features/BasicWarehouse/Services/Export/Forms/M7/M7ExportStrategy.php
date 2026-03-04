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
        
        $sheet->setCellValue('A5', $org->legal_name ?? $org->name);
        $sheet->setCellValue('A6', 'организация - получатель');
        
        $sheet->setCellValue('A8', 'АКТ О ПРИЕМКЕ МАТЕРИАЛОВ № ' . ($movement->document_number ?: $movement->id));
        $sheet->getStyle('A8')->getFont()->setBold(true)->setSize(12);
        
        $sheet->setCellValue('D9', 'Дата составления');
        $sheet->setCellValue('E9', $movement->movement_date->format('d.m.Y'));
        
        $sheet->setCellValue('A11', 'Место приемки: ' . ($movement->warehouse->name ?? ''));
        $sheet->setCellValue('A12', 'Поставщик: ' . ($movement->metadata['supplier_name'] ?? ''));
        $sheet->setCellValue('A13', 'Сопроводительный документ: ' . ($movement->metadata['invoice_number'] ?? ''));
    }

    protected function setTable($sheet, WarehouseMovement $movement): void
    {
        $row = 15;
        $sheet->setCellValue("A{$row}", 'Материал');
        $sheet->setCellValue("D{$row}", 'Ед. изм.');
        $sheet->setCellValue("E{$row}", 'По документам');
        $sheet->setCellValue("F{$row}", 'Фактически');
        $sheet->setCellValue("G{$row}", 'Расхождения');
        
        $row++;
        $sheet->setCellValue("A{$row}", $movement->material->name);
        $sheet->setCellValue("D{$row}", $movement->material->measurementUnit->name ?? '');
        
        $supplierQty = $movement->metadata['supplier_quantity'] ?? $movement->quantity;
        $sheet->setCellValue("E{$row}", $supplierQty);
        $sheet->setCellValue("F{$row}", $movement->quantity);
        $sheet->setCellValue("G{$row}", $movement->quantity - $supplierQty);
    }

    protected function setFooter($sheet, WarehouseMovement $movement): void
    {
        $row = $sheet->getHighestRow() + 2;
        $sheet->setCellValue("A{$row}", 'Комиссия: ____________________');
        $row++;
        $sheet->setCellValue("A{$row}", 'Представитель поставщика: ____________________');
    }

    protected function applyStyles($sheet): void
    {
        $sheet->getColumnDimension('A')->setWidth(30);
        $highestRow = $sheet->getHighestRow();
        $sheet->getStyle("A15:G16")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }
}
