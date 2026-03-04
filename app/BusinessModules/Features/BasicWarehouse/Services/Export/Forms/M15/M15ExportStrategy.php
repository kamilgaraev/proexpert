<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services\Export\Forms\M15;

use App\BusinessModules\Features\BasicWarehouse\Services\Export\Strategies\BaseWarehouseExportStrategy;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;

/**
 * Стратегия экспорта Накладной на отпуск материалов на сторону (Форма № М-15)
 */
class M15ExportStrategy extends BaseWarehouseExportStrategy
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
        
        $filename = "M15_" . ($movement->document_number ?: $movement->id) . ".xlsx";
        $path = "exports/warehouse/m15/{$filename}";
        
        return $this->saveSpreadsheetToS3($spreadsheet, $path, $movement->organization);
    }

    public function getSupportedType(): string
    {
        return 'm15';
    }

    protected function setHeader($sheet, WarehouseMovement $movement): void
    {
        $org = $movement->organization;
        
        $sheet->setCellValue('J1', 'Унифицированная форма № М-15');
        $sheet->setCellValue('J2', 'Утверждена постановлением Госкомстата');
        $sheet->setCellValue('J3', 'России от 30.10.97 № 71а');
        
        $sheet->setCellValue('A5', $org->legal_name ?? $org->name);
        $sheet->setCellValue('A6', 'организация - отправитель');
        
        $sheet->setCellValue('A8', 'НАКЛАДНАЯ № ' . ($movement->document_number ?: $movement->id));
        $sheet->setCellValue('A9', 'на отпуск материалов на сторону');
        $sheet->getStyle('A8')->getFont()->setBold(true)->setSize(14);
        
        $sheet->setCellValue('D10', 'Номер документа');
        $sheet->setCellValue('E10', 'Дата составления');
        $sheet->setCellValue('D11', $movement->document_number ?: $movement->id);
        $sheet->setCellValue('E11', $movement->movement_date->format('d.m.Y'));
        
        $sheet->setCellValue('A13', 'Основание: ' . ($movement->metadata['reason'] ?? 'Бухгалтерская справка'));
        $sheet->setCellValue('A14', 'Кому: ' . ($movement->toWarehouse->name ?? $movement->metadata['recipient'] ?? 'Сторонняя организация'));
        $sheet->setCellValue('A15', 'Через кого: ' . ($movement->user->name ?? ''));
    }

    protected function setTable($sheet, WarehouseMovement $movement): void
    {
        $row = 17;
        $sheet->setCellValue("A{$row}", 'Материал (наименование)');
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
        $sheet->setCellValue("A{$row}", 'Отпустил: ____________________');
        $sheet->setCellValue("E{$row}", 'Получил: ____________________');
    }

    protected function applyStyles($sheet): void
    {
        $sheet->getColumnDimension('A')->setWidth(40);
        $sheet->getColumnDimension('I')->setWidth(20);
        $sheet->getStyle('A17:I18')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }
}
