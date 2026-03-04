<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services\Export\Strategies;

use App\BusinessModules\Features\BasicWarehouse\Services\Export\Contracts\WarehouseExportStrategyInterface;
use App\Services\Storage\FileService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Базовая стратегия экспорта
 */
abstract class BaseWarehouseExportStrategy implements WarehouseExportStrategyInterface
{
    public function __construct(protected FileService $fileService)
    {}

    /**
     * Сохранение Spreadsheet в S3
     */
    protected function saveSpreadsheetToS3(Spreadsheet $spreadsheet, string $path, $organization): string
    {
        $writer = new Xlsx($spreadsheet);
        
        ob_start();
        $writer->save('php://output');
        $content = ob_get_clean();
        
        $orgId = $organization instanceof \App\Models\Organization ? $organization->id : $organization;
        $s3Path = "org-{$orgId}/{$path}";
        
        $this->fileService->disk($organization)->put($s3Path, $content);

        return $s3Path;
    }

    /**
     * Установка общих стилей для таблицы
     */
    protected function applyTableStyle(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle($range)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    }

    /**
     * Установка жирного шрифта
     */
    protected function setBold(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getFont()->setBold(true);
    }

    /**
     * Центрирование текста
     */
    protected function setCenter(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    /**
     * Установка границ снизу (для подписей)
     */
    protected function setUnderline(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);
    }
}
