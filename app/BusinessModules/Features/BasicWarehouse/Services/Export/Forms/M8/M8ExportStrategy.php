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
        $org = $reservation->organization;
        
        $sheet->setCellValue('J1', 'Унифицированная форма № М-8');
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
        $sheet->setCellValue('I6', '0315005');
        $sheet->setCellValue('H7', 'по ОКПО');
        $sheet->setCellValue('I7', $org->okpo ?? '');
        $this->applyTableStyle($sheet, 'H5:I7');
        $this->setCenter($sheet, 'H5:I7');
        
        $sheet->mergeCells('A9:I9');
        $sheet->setCellValue('A9', 'ЛИМИТНО-ЗАБОРНАЯ КАРТА № ' . $reservation->id);
        $this->setBold($sheet, 'A9');
        $this->setCenter($sheet, 'A9');
        $sheet->getStyle('A9')->getFont()->setSize(14);
        
        $sheet->setCellValue('A11', 'Материал: ' . ($reservation->asset->name ?? ''));
        $sheet->setCellValue('A12', 'Лимит: ' . $reservation->quantity . ' ' . ($reservation->asset->unit ?? ''));
    }

    protected function setTable($sheet, $reservation, $movements): void
    {
        $row = 15;
        $sheet->setCellValue("A{$row}", 'Дата');
        $sheet->setCellValue("B{$row}", 'Номер документа');
        $sheet->setCellValue("E{$row}", 'Отпущено');
        $sheet->setCellValue("G{$row}", 'Остаток лимита');
        
        $this->setBold($sheet, "A{$row}:I{$row}");
        $this->setCenter($sheet, "A{$row}:I{$row}");
        
        $remaining = (float)$reservation->quantity;
        foreach ($movements as $m) {
            $row++;
            $sheet->setCellValue("A{$row}", $m->movement_date->format('d.m.Y'));
            $sheet->setCellValue("B{$row}", $m->document_number ?: $m->id);
            $sheet->setCellValue("E{$row}", $m->quantity);
            $remaining -= (float)$m->quantity;
            $sheet->setCellValue("G{$row}", max(0, $remaining));
        }
        
        $this->applyTableStyle($sheet, "A15:I{$row}");
    }

    protected function applyStyles($sheet): void
    {
        $sheet->getColumnDimension('A')->setWidth(15);
        $sheet->getColumnDimension('B')->setWidth(15);
        $sheet->getColumnDimension('E')->setWidth(15);
        $sheet->getColumnDimension('G')->setWidth(15);
        
        $sheet->getStyle('A1:L100')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
    }
}
