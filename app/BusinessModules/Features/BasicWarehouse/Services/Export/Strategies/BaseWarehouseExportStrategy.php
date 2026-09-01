<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services\Export\Strategies;

use App\BusinessModules\Features\BasicWarehouse\Services\Export\Contracts\WarehouseExportStrategyInterface;
use App\Models\Organization;
use App\Services\Storage\FileService;
use DateTimeInterface;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Базовая стратегия экспорта
 */
abstract class BaseWarehouseExportStrategy implements WarehouseExportStrategyInterface
{
    public function __construct(protected FileService $fileService) {}

    /**
     * Сохранение Spreadsheet в S3
     */
    protected function saveSpreadsheetToS3(Spreadsheet $spreadsheet, string $path, Organization|int|string $organization): string
    {
        $writer = new Xlsx($spreadsheet);

        ob_start();
        $writer->save('php://output');
        $content = ob_get_clean();

        $organizationModel = $organization instanceof Organization ? $organization : null;
        $orgId = $organizationModel?->id ?? $organization;
        $s3Path = "org-{$orgId}/{$path}";

        $this->fileService->disk($organizationModel)->put($s3Path, $content);

        return $s3Path;
    }

    protected function documentNumber(mixed $number): string
    {
        $trimmed = trim((string) $number);
        $normalized = preg_replace('/\s+/u', ' ', $trimmed) ?? $trimmed;

        return $normalized !== '' ? $normalized : trans_message('warehouse_basic.document_without_number');
    }

    protected function documentFileSuffix(mixed $number, DateTimeInterface $date): string
    {
        $businessNumber = Str::slug(trim((string) $number));

        return $businessNumber !== '' ? $businessNumber : $date->format('Ymd_His');
    }

    protected function filenameFragment(mixed $value, string $fallback): string
    {
        $fragment = Str::slug(trim((string) $value));

        return $fragment !== '' ? $fragment : $fallback;
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
