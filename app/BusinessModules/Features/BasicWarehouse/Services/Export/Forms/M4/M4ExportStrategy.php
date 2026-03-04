<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services\Export\Forms\M4;

use App\BusinessModules\Features\BasicWarehouse\Services\Export\Strategies\BaseWarehouseExportStrategy;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;

/**
 * Стратегия экспорта Приходного ордера (Форма № М-4)
 */
class M4ExportStrategy extends BaseWarehouseExportStrategy
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
        
        $filename = "M4_" . ($movement->document_number ?: $movement->id) . ".xlsx";
        $path = "exports/warehouse/m4/{$filename}";
        
        return $this->saveSpreadsheetToS3($spreadsheet, $path, $movement->organization);
    }

    public function getSupportedType(): string
    {
        return 'm4';
    }

    protected function setHeader($sheet, WarehouseMovement $movement): void
    {
        $org = $movement->organization;
        $warehouse = $movement->warehouse;
        
        $sheet->setCellValue('J1', 'Унифицированная форма № М-4');
        $sheet->setCellValue('J2', 'Утверждена постановлением Госкомстата');
        $sheet->setCellValue('J3', 'России от 30.10.97 № 71а');
        
        $sheet->setCellValue('A5', $org->legal_name ?? $org->name);
        $sheet->setCellValue('A6', 'организация');
        
        $sheet->setCellValue('H5', 'Код');
        $sheet->setCellValue('H6', 'Форма по ОКУД 0315003');
        $sheet->setCellValue('H7', 'по ОКПО');
        $sheet->setCellValue('I7', $org->okpo ?? '');
        
        $sheet->setCellValue('A9', 'ПРИХОДНЫЙ ОРДЕР');
        $sheet->getStyle('A9')->getFont()->setBold(true)->setSize(14);
        
        $sheet->setCellValue('D10', 'Номер документа');
        $sheet->setCellValue('E10', 'Дата составления');
        $sheet->setCellValue('D11', $movement->document_number ?: $movement->id);
        $sheet->setCellValue('E11', $movement->movement_date->format('d.m.Y'));
        
        $sheet->setCellValue('A13', 'Склад: ' . ($warehouse->name ?? ''));
        $sheet->setCellValue('A14', 'Поставщик: ' . ($movement->metadata['supplier_name'] ?? ''));
    }

    protected function setTable($sheet, WarehouseMovement $movement): void
    {
        $row = 16;
        $sheet->setCellValue("A{$row}", 'Материал (наименование, сорт, размер, марка)');
        $sheet->setCellValue("E{$row}", 'Ед. изм.');
        $sheet->setCellValue("F{$row}", 'Количество');
        $sheet->setCellValue("H{$row}", 'Цена, руб. коп.');
        $sheet->setCellValue("I{$row}", 'Сумма без НДС, руб. коп.');
        
        $row++;
        $sheet->setCellValue("A{$row}", $movement->material->name);
        $sheet->setCellValue("E{$row}", $movement->material->measurementUnit->name ?? '');
        $sheet->setCellValue("F{$row}", $movement->quantity);
        $sheet->setCellValue("H{$row}", $movement->price);
        $sheet->setCellValue("I{$row}", $movement->quantity * $movement->price);
    }

    protected function setFooter($sheet, WarehouseMovement $movement): void
    {
        $row = $sheet->getHighestRow() + 2;
        $sheet->setCellValue("A{$row}", 'Принял: ____________________ / ' . ($movement->user->name ?? '') . ' /');
    }

    protected function applyStyles($sheet): void
    {
        $sheet->getColumnDimension('A')->setWidth(40);
        $sheet->getColumnDimension('I')->setWidth(20);
        $sheet->getStyle('A16:I17')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }
}
