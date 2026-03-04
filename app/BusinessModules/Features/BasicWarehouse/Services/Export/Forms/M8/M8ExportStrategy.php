<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services\Export\Forms\M8;

use App\BusinessModules\Features\BasicWarehouse\Services\Export\Strategies\BaseWarehouseExportStrategy;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;

/**
 * Стратегия экспорта Лимитно-заборной карты (Форма № М-8)
 */
class M8ExportStrategy extends BaseWarehouseExportStrategy
{
    /**
     * @param array $data ['reservation' => AssetReservation, 'movements' => Collection]
     */
    public function export($data): string
    {
        $reservation = $data['reservation'];
        $movements = $data['movements'];
        
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        $this->setHeader($sheet, $reservation);
        $this->setTable($sheet, $reservation, $movements);
        $this->applyStyles($sheet);
        
        $filename = "M8_Res{$reservation->id}.xlsx";
        $path = "exports/warehouse/m8/{$filename}";
        
        return $this->saveSpreadsheetToS3($spreadsheet, $path, $reservation->organization_id);
    }

    public function getSupportedType(): string
    {
        return 'm8';
    }

    protected function setHeader($sheet, $reservation): void
    {
        $sheet->setCellValue('J1', 'Унифицированная форма № М-8');
        $sheet->setCellValue('J2', 'Утверждена постановлением Госкомстата');
        $sheet->setCellValue('J3', 'России от 30.10.97 № 71а');
        
        $sheet->setCellValue('A5', 'ЛИМИТНО-ЗАБОРНАЯ КАРТА');
        $sheet->getStyle('A5')->getFont()->setBold(true)->setSize(14);
        
        $sheet->setCellValue('A7', 'Материал: ' . ($reservation->asset->name ?? ''));
        $sheet->setCellValue('A8', 'Лимит: ' . $reservation->quantity);
    }

    protected function setTable($sheet, $reservation, $movements): void
    {
        $row = 10;
        $sheet->setCellValue("A{$row}", 'Дата');
        $sheet->setCellValue("B{$row}", 'Номер документа');
        $sheet->setCellValue("C{$row}", 'Отпущено');
        $sheet->setCellValue("D{$row}", 'Остаток лимита');
        
        $remaining = $reservation->quantity;
        foreach ($movements as $m) {
            $row++;
            $sheet->setCellValue("A{$row}", $m->movement_date->format('d.m.Y'));
            $sheet->setCellValue("B{$row}", $m->document_number ?: $m->id);
            $sheet->setCellValue("C{$row}", $m->quantity);
            $remaining -= $m->quantity;
            $sheet->setCellValue("D{$row}", max(0, $remaining));
        }
    }

    protected function applyStyles($sheet): void
    {
        $sheet->getColumnDimension('A')->setWidth(15);
        $sheet->getColumnDimension('D')->setWidth(20);
        $highestRow = $sheet->getHighestRow();
        $sheet->getStyle("A10:D{$highestRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }
}
