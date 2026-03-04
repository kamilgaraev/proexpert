<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services\Export\Forms\M11;

use App\BusinessModules\Features\BasicWarehouse\Services\Export\Strategies\BaseWarehouseExportStrategy;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;

/**
 * Стратегия экспорта Требования-накладной (Форма № М-11)
 */
class M11ExportStrategy extends BaseWarehouseExportStrategy
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
        
        $filename = "M11_" . ($movement->document_number ?: $movement->id) . ".xlsx";
        $path = "exports/warehouse/m11/{$filename}";
        
        return $this->saveSpreadsheetToS3($spreadsheet, $path, $movement->organization);
    }

    public function getSupportedType(): string
    {
        return 'm11';
    }

    protected function setHeader($sheet, WarehouseMovement $movement): void
    {
        $org = $movement->organization;
        
        $sheet->setCellValue('J1', 'Унифицированная форма № М-11');
        $sheet->setCellValue('J2', 'Утверждена постановлением Госкомстата');
        $sheet->setCellValue('J3', 'России от 30.10.97 № 71а');
        
        $sheet->setCellValue('A5', $org->legal_name ?? $org->name);
        $sheet->setCellValue('A6', 'организация');
        
        $sheet->setCellValue('A8', 'ТРЕБОВАНИЕ-НАКЛАДНАЯ');
        $sheet->getStyle('A8')->getFont()->setBold(true)->setSize(14);
        
        $sheet->setCellValue('D9', 'Номер документа');
        $sheet->setCellValue('E9', 'Дата составления');
        $sheet->setCellValue('D10', $movement->document_number ?: $movement->id);
        $sheet->setCellValue('E10', $movement->movement_date->format('d.m.Y'));
        
        $sheet->setCellValue('A12', 'Отправитель: ' . ($movement->warehouse->name ?? ''));
        $sheet->setCellValue('A13', 'Получатель: ' . ($movement->toWarehouse->name ?? $movement->metadata['recipient'] ?? ''));
    }

    protected function setTable($sheet, WarehouseMovement $movement): void
    {
        $row = 15;
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
        $sheet->setCellValue("A{$row}", 'Через кого: ____________________ / ' . ($movement->user->name ?? '') . ' /');
        $row++;
        $sheet->setCellValue("A{$row}", 'Разрешил: ____________________');
        $sheet->setCellValue("E{$row}", 'Выдал: ____________________');
    }

    protected function applyStyles($sheet): void
    {
        $sheet->getColumnDimension('A')->setWidth(40);
        $sheet->getColumnDimension('I')->setWidth(20);
        $sheet->getStyle('A15:I16')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }
}
