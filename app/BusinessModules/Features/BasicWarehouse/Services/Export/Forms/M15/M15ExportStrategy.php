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
    public function export($movementOrCollection): string
    {
        $movements = $movementOrCollection instanceof \Illuminate\Database\Eloquent\Collection 
            ? $movementOrCollection 
            : collect([$movementOrCollection]);
            
        /** @var WarehouseMovement $firstMovement */
        $firstMovement = $movements->first();
        
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        $this->setHeader($sheet, $firstMovement);
        $this->setTable($sheet, $movements);
        $this->setFooter($sheet, $firstMovement);
        $this->applyStyles($sheet);
        
        $filename = "M15_" . ($firstMovement->document_number ?: $firstMovement->id) . ".xlsx";
        $path = "exports/warehouse/m15/{$filename}";
        
        return $this->saveSpreadsheetToS3($spreadsheet, $path, $firstMovement->organization);
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
        $sheet->getStyle('J1:L3')->getFont()->setSize(8);
        
        $sheet->mergeCells('A5:G5');
        $sheet->setCellValue('A5', $org->legal_name ?? $org->name);
        $this->setUnderline($sheet, 'A5:G5');
        $sheet->setCellValue('A6', 'организация - отправитель');
        $this->setCenter($sheet, 'A6:G6');
        $sheet->getStyle('A6')->getFont()->setSize(8);

        $sheet->setCellValue('H5', 'Код');
        $sheet->setCellValue('H6', 'Форма по ОКУД');
        $sheet->setCellValue('I6', '0315007');
        $sheet->setCellValue('H7', 'по ОКПО');
        $sheet->setCellValue('I7', $org->okpo ?? '');
        $this->applyTableStyle($sheet, 'H5:I7');
        $this->setCenter($sheet, 'H5:I7');
        
        $sheet->mergeCells('A9:I9');
        $sheet->setCellValue('A9', 'НАКЛАДНАЯ № ' . ($movement->document_number ?: $movement->id));
        $this->setBold($sheet, 'A9');
        $this->setCenter($sheet, 'A9');
        $sheet->getStyle('A9')->getFont()->setSize(12);
        
        $sheet->mergeCells('A10:I10');
        $sheet->setCellValue('A10', 'на отпуск материалов на сторону');
        $this->setCenter($sheet, 'A10');
        
        $sheet->setCellValue('D11', 'Номер документа');
        $sheet->setCellValue('E11', 'Дата составления');
        $sheet->setCellValue('D12', $movement->document_number ?: $movement->id);
        $sheet->setCellValue('E12', $movement->movement_date->format('d.m.Y'));
        $this->applyTableStyle($sheet, 'D11:E12');
        $this->setCenter($sheet, 'D11:E12');
        
        $sheet->setCellValue('A14', 'Основание: ' . ($movement->metadata['reason'] ?? 'Бухгалтерская справка'));
        $sheet->setCellValue('A15', 'Кому: ' . ($movement->toWarehouse->name ?? $movement->metadata['recipient'] ?? 'Сторонняя организация'));
        $sheet->setCellValue('A16', 'Через кого: ' . ($movement->user->name ?? ''));
    }

    protected function setTable($sheet, $movements): void
    {
        $row = 18;
        $sheet->setCellValue("A{$row}", 'Материал (наименование, сорт, размер, марка)');
        $sheet->setCellValue("E{$row}", 'Ед. изм.');
        $sheet->setCellValue("F{$row}", 'Количество');
        $sheet->setCellValue("H{$row}", 'Цена, руб. коп.');
        $sheet->setCellValue("I{$row}", 'Сумма без НДС, руб. коп.');
        
        $this->setBold($sheet, "A{$row}:I{$row}");
        $this->setCenter($sheet, "A{$row}:I{$row}");
        $sheet->getStyle("A{$row}:I{$row}")->getAlignment()->setWrapText(true);
        
        foreach ($movements as $m) {
            $row++;
            $sheet->setCellValue("A{$row}", $m->material->name);
            $sheet->setCellValue("E{$row}", $m->material->measurementUnit->name ?? '');
            $sheet->setCellValue("F{$row}", $m->quantity);
            $sheet->setCellValue("H{$row}", number_format((float)$m->price, 2, ',', ' '));
            $sheet->setCellValue("I{$row}", number_format((float)($m->quantity * $m->price), 2, ',', ' '));
        }
        
        $this->applyTableStyle($sheet, "A18:I{$row}");
    }

    protected function setFooter($sheet, WarehouseMovement $movement): void
    {
        $row = $sheet->getHighestRow() + 2;
        $sheet->setCellValue("A{$row}", 'Отпустил: ____________________');
        $sheet->setCellValue("E{$row}", 'Получил: ____________________');
        $sheet->mergeCells("A{$row}:D{$row}");
        $sheet->mergeCells("E{$row}:I{$row}");
    }

    protected function applyStyles($sheet): void
    {
        $sheet->getColumnDimension('A')->setWidth(45);
        $sheet->getColumnDimension('E')->setWidth(12);
        $sheet->getColumnDimension('F')->setWidth(12);
        $sheet->getColumnDimension('H')->setWidth(15);
        $sheet->getColumnDimension('I')->setWidth(20);
        
        $sheet->getStyle('A1:L50')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
    }
}
