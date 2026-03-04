<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services\Export\Forms\M17;

use App\BusinessModules\Features\BasicWarehouse\Services\Export\Strategies\BaseWarehouseExportStrategy;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\Models\Material;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;

/**
 * Стратегия экспорта Карточки учета материалов (Форма № М-17)
 */
class M17ExportStrategy extends BaseWarehouseExportStrategy
{
    /**
     * @param array $data ['material' => Material, 'warehouse_id' => int, 'movements' => Collection]
     */
    public function export($data): string
    {
        $material = $data['material'];
        $warehouseId = $data['warehouse_id'];
        $movements = $data['movements'];
        
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        $this->setHeader($sheet, $material, $warehouseId);
        $this->setTable($sheet, $movements);
        $this->applyStyles($sheet);
        
        $filename = "M17_{$material->id}_W{$warehouseId}.xlsx";
        $path = "exports/warehouse/m17/{$filename}";
        
        return $this->saveSpreadsheetToS3($spreadsheet, $path, $material->organization_id);
    }

    public function getSupportedType(): string
    {
        return 'm17';
    }

    protected function setHeader($sheet, Material $material, int $warehouseId): void
    {
        $sheet->setCellValue('J1', 'Унифицированная форма № М-17');
        $sheet->setCellValue('J2', 'Утверждена постановлением Госкомстата');
        $sheet->setCellValue('J3', 'России от 30.10.97 № 71а');
        
        $sheet->setCellValue('A5', 'КАРТОЧКА УЧЕТА МАТЕРИАЛОВ');
        $sheet->getStyle('A5')->getFont()->setBold(true)->setSize(14);
        
        $sheet->setCellValue('A7', 'Материал: ' . $material->name);
        $sheet->setCellValue('A8', 'Ед. изм.: ' . ($material->measurementUnit->name ?? ''));
    }

    protected function setTable($sheet, $movements): void
    {
        $row = 10;
        $sheet->setCellValue("A{$row}", 'Дата');
        $sheet->setCellValue("B{$row}", 'Номер документа');
        $sheet->setCellValue("C{$row}", 'От кого/Кому');
        $sheet->setCellValue("D{$row}", 'Приход');
        $sheet->setCellValue("E{$row}", 'Расход');
        $sheet->setCellValue("F{$row}", 'Остаток');
        
        $balance = 0;
        foreach ($movements as $m) {
            $row++;
            $sheet->setCellValue("A{$row}", $m->movement_date->format('d.m.Y'));
            $sheet->setCellValue("B{$row}", $m->document_number ?: $m->id);
            
            $isReceipt = $m->movement_type === 'receipt';
            
            if ($isReceipt) {
                $sheet->setCellValue("C{$row}", $m->metadata['supplier_name'] ?? 'Поставщик');
                $sheet->setCellValue("D{$row}", $m->quantity);
                $balance += $m->quantity;
            } else {
                $sheet->setCellValue("C{$row}", $m->metadata['recipient'] ?? 'Списание/Перемещение');
                $sheet->setCellValue("E{$row}", $m->quantity);
                $balance -= $m->quantity;
            }
            $sheet->setCellValue("F{$row}", $balance);
        }
    }

    protected function applyStyles($sheet): void
    {
        $sheet->getColumnDimension('A')->setWidth(15);
        $sheet->getColumnDimension('C')->setWidth(30);
        $highestRow = $sheet->getHighestRow();
        $sheet->getStyle("A10:F{$highestRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }
}
