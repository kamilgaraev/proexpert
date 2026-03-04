<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services\Export\Forms\INV3;

use App\BusinessModules\Features\BasicWarehouse\Services\Export\Strategies\BaseWarehouseExportStrategy;
use App\BusinessModules\Features\BasicWarehouse\Models\InventoryAct;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;

/**
 * Стратегия экспорта Инвентаризационной описи (Форма № ИНВ-3)
 */
class INV3ExportStrategy extends BaseWarehouseExportStrategy
{
    public function export($act): string
    {
        /** @var InventoryAct $act */
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        $this->setHeader($sheet, $act);
        $this->setTable($sheet, $act);
        $this->setFooter($sheet, $act);
        $this->applyStyles($sheet);
        
        $filename = "INV3_" . ($act->act_number ?: $act->id) . ".xlsx";
        $path = "exports/warehouse/inv3/{$filename}";
        
        return $this->saveSpreadsheetToS3($spreadsheet, $path, $act->organization);
    }

    public function getSupportedType(): string
    {
        return 'inv3';
    }

    protected function setHeader($sheet, InventoryAct $act): void
    {
        $org = $act->organization;
        
        $sheet->setCellValue('J1', 'Унифицированная форма № ИНВ-3');
        $sheet->setCellValue('J2', 'Утверждена постановлением Госкомстата');
        $sheet->setCellValue('J3', 'России от 18.08.98 № 88');
        
        $sheet->setCellValue('A5', $org->legal_name ?? $org->name);
        $sheet->setCellValue('A6', 'организация');
        
        $sheet->setCellValue('A8', 'ИНВЕНТАРИЗАЦИОННАЯ ОПИСЬ ТОВАРНО-МАТЕРИАЛЬНЫХ ЦЕННОСТЕЙ');
        $sheet->getStyle('A8')->getFont()->setBold(true)->setSize(12);
        
        $sheet->setCellValue('D9', 'Номер документа');
        $sheet->setCellValue('E9', 'Дата составления');
        $sheet->setCellValue('D10', $act->act_number ?: $act->id);
        $sheet->setCellValue('E10', $act->inventory_date->format('d.m.Y'));
        
        $sheet->setCellValue('A12', 'Местонахождение: ' . ($act->warehouse->name ?? ''));
    }

    protected function setTable($sheet, InventoryAct $act): void
    {
        $row = 14;
        $sheet->setCellValue("A{$row}", '№');
        $sheet->setCellValue("B{$row}", 'ТМЦ (наименование)');
        $sheet->setCellValue("E{$row}", 'Ед. изм.');
        $sheet->setCellValue("F{$row}", 'По данным учета');
        $sheet->setCellValue("H{$row}", 'Фактическое наличие');
        $sheet->setCellValue("J{$row}", 'Результат (излишки/недостача)');
        
        $row++;
        foreach ($act->items as $index => $item) {
            $sheet->setCellValue("A{$row}", $index + 1);
            $sheet->setCellValue("B{$row}", $item->material->name);
            $sheet->setCellValue("E{$row}", $item->material->measurementUnit->name ?? '');
            $sheet->setCellValue("F{$row}", $item->expected_quantity);
            $sheet->setCellValue("H{$row}", $item->actual_quantity);
            $sheet->setCellValue("J{$row}", $item->difference);
            $row++;
        }
    }

    protected function setFooter($sheet, InventoryAct $act): void
    {
        $row = $sheet->getHighestRow() + 2;
        $sheet->setCellValue("A{$row}", 'Председатель комиссии: ____________________');
        $row++;
        $sheet->setCellValue("A{$row}", 'Члены комиссии:');
        foreach ($act->commission_members ?? [] as $member) {
            $row++;
            $sheet->setCellValue("A{$row}", "- {$member}");
        }
    }

    protected function applyStyles($sheet): void
    {
        $sheet->getColumnDimension('A')->setWidth(5);
        $sheet->getColumnDimension('B')->setWidth(40);
        $highestRow = $sheet->getHighestRow();
        $sheet->getStyle("A14:J{$highestRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }
}
