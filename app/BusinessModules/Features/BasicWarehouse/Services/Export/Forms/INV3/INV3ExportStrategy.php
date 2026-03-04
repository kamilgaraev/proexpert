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
        
        $sheet->mergeCells('A9:J9');
        $sheet->setCellValue('A9', 'ИНВЕНТАРИЗАЦИОННАЯ ОПИСЬ ТОВАРНО-МАТЕРИАЛЬНЫХ ЦЕННОСТЕЙ');
        $this->setBold($sheet, 'A9');
        $this->setCenter($sheet, 'A9');
        $sheet->getStyle('A9')->getFont()->setSize(12);
        
        $sheet->setCellValue('D10', 'Номер документа');
        $sheet->setCellValue('E10', 'Дата составления');
        $sheet->setCellValue('D11', $act->act_number ?: $act->id);
        $sheet->setCellValue('E11', $act->inventory_date->format('d.m.Y'));
        $this->applyTableStyle($sheet, 'D10:E11');
        $this->setCenter($sheet, 'D10:E11');
        
        $sheet->setCellValue('A13', 'Местонахождение: ' . ($act->warehouse->name ?? ''));
    }

    protected function setTable($sheet, InventoryAct $act): void
    {
        $row = 15;
        $sheet->setCellValue("A{$row}", '№');
        $sheet->setCellValue("B{$row}", 'ТМЦ (наименование, размер, сорт)');
        $sheet->setCellValue("E{$row}", 'Ед. изм.');
        $sheet->setCellValue("F{$row}", 'По данным учета');
        $sheet->setCellValue("H{$row}", 'Фактическое наличие');
        $sheet->setCellValue("J{$row}", 'Результат (излишки/недостача)');
        
        $this->setBold($sheet, "A{$row}:J{$row}");
        $this->setCenter($sheet, "A{$row}:J{$row}");
        $sheet->getStyle("A{$row}:J{$row}")->getAlignment()->setWrapText(true);
        
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
        
        $this->applyTableStyle($sheet, "A15:J" . ($row - 1));
    }

    protected function setFooter($sheet, InventoryAct $act): void
    {
        $row = $sheet->getHighestRow() + 2;
        $sheet->setCellValue("A{$row}", 'Председатель комиссии: ____________________');
        $this->setUnderline($sheet, "B{$row}");
        $row++;
        $sheet->setCellValue("A{$row}", 'Члены комиссии:');
        foreach ($act->commission_members ?? [] as $member) {
            $row++;
            $sheet->setCellValue("A{$row}", "- ____________________ / {$member} /");
        }
    }

    protected function applyStyles($sheet): void
    {
        $sheet->getColumnDimension('A')->setWidth(5);
        $sheet->getColumnDimension('B')->setWidth(40);
        $sheet->getColumnDimension('E')->setWidth(10);
        $sheet->getColumnDimension('F')->setWidth(12);
        $sheet->getColumnDimension('H')->setWidth(12);
        $sheet->getColumnDimension('J')->setWidth(15);
        
        $sheet->getStyle('A1:L100')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
    }
}
