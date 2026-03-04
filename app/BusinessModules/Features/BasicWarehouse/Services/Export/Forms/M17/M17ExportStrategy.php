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
        $org = $material->organization;
        
        $sheet->setCellValue('J1', 'Унифицированная форма № М-17');
        $sheet->setCellValue('J2', 'Утверждена постановлением Госкомстата');
        $sheet->setCellValue('J3', 'России от 30.10.97 № 71а');
        $sheet->getStyle('J1:L3')->getFont()->setSize(8);
        
        $sheet->mergeCells('A5:G5');
        $sheet->setCellValue('A5', $org->legal_name ?? $org->name);
        $this->setUnderline($sheet, 'A5:G5');
        $sheet->setCellValue('A6', 'организация');
        $this->setCenter($sheet, 'A6:G6');
        $sheet->getStyle('A6')->getFont()->setSize(8);

        $sheet->setCellValue('H5', 'Код');
        $sheet->setCellValue('H6', 'Форма по ОКУД');
        $sheet->setCellValue('I6', '0315008');
        $sheet->setCellValue('H7', 'по ОКПО');
        $sheet->setCellValue('I7', $org->okpo ?? '');
        $this->applyTableStyle($sheet, 'H5:I7');
        $this->setCenter($sheet, 'H5:I7');
        
        $sheet->mergeCells('A9:I9');
        $sheet->setCellValue('A9', 'КАРТОЧКА УЧЕТА МАТЕРИАЛОВ');
        $this->setBold($sheet, 'A9');
        $this->setCenter($sheet, 'A9');
        $sheet->getStyle('A9')->getFont()->setSize(14);
        
        $sheet->setCellValue('A11', 'Материал: ' . $material->name);
        $sheet->setCellValue('A12', 'Ед. изм.: ' . ($material->measurementUnit->name ?? ''));
    }

    protected function setTable($sheet, $movements): void
    {
        $row = 15;
        $sheet->setCellValue("A{$row}", 'Дата');
        $sheet->setCellValue("B{$row}", 'Номер документа');
        $sheet->setCellValue("C{$row}", 'От кого/Кому');
        $sheet->setCellValue("E{$row}", 'Приход');
        $sheet->setCellValue("G{$row}", 'Расход');
        $sheet->setCellValue("I{$row}", 'Остаток');
        
        $this->setBold($sheet, "A{$row}:I{$row}");
        $this->setCenter($sheet, "A{$row}:I{$row}");
        $sheet->getStyle("A{$row}:I{$row}")->getAlignment()->setWrapText(true);
        
        $balance = 0;
        foreach ($movements as $m) {
            $row++;
            $sheet->setCellValue("A{$row}", $m->movement_date->format('d.m.Y'));
            $sheet->setCellValue("B{$row}", $m->document_number ?: $m->id);
            
            $isReceipt = $m->movement_type === 'receipt';
            
            if ($isReceipt) {
                $sheet->setCellValue("C{$row}", $m->metadata['supplier_name'] ?? 'Поставщик');
                $sheet->setCellValue("E{$row}", $m->quantity);
                $balance += $m->quantity;
            } else {
                $sheet->setCellValue("C{$row}", $m->metadata['recipient'] ?? 'Списание/Перемещение');
                $sheet->setCellValue("G{$row}", $m->quantity);
                $balance -= $m->quantity;
            }
            $sheet->setCellValue("I{$row}", $balance);
        }
        
        $this->applyTableStyle($sheet, "A15:I{$row}");
    }

    protected function applyStyles($sheet): void
    {
        $sheet->getColumnDimension('A')->setWidth(15);
        $sheet->getColumnDimension('B')->setWidth(15);
        $sheet->getColumnDimension('C')->setWidth(30);
        $sheet->getColumnDimension('E')->setWidth(12);
        $sheet->getColumnDimension('G')->setWidth(12);
        $sheet->getColumnDimension('I')->setWidth(12);
        
        $sheet->getStyle('A1:L100')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
    }
}
