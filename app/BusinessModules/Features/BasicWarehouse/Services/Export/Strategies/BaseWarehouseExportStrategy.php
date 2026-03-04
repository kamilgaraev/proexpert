<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services\Export\Strategies;

use App\BusinessModules\Features\BasicWarehouse\Services\Export\Contracts\WarehouseExportStrategyInterface;
use App\Services\Storage\FileService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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
}
