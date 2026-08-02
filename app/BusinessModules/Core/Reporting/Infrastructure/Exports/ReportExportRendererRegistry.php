<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Exports;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportExportRenderer;
use App\BusinessModules\Core\Reporting\Application\Input\CreateReportExportData;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;

final readonly class ReportExportRendererRegistry
{
    public function __construct(
        private CsvReportExportRenderer $csv,
        private XlsxReportExportRenderer $xlsx,
        private ?PdfReportExportRenderer $pdf = null,
    ) {
    }

    public function resolve(
        PublishedReportDefinition $definition,
        CreateReportExportData $data,
    ): ReportExportRenderer {
        $payload = $definition->definition;
        if (!in_array($data->format, $payload->formats, true)) {
            throw $this->rejected();
        }

        $allowedColumns = array_fill_keys(array_column($payload->columns, 'id'), true);
        foreach ($data->columns as $columnId) {
            if (!isset($allowedColumns[$columnId])) {
                throw $this->rejected();
            }
        }

        return match ($data->format) {
            'csv' => $this->csv->forDefinition($definition),
            'xlsx' => $this->xlsx->forDefinition($definition),
            'pdf' => $this->pdf?->forDefinition($definition) ?? throw $this->rejected(),
            default => throw $this->rejected(),
        };
    }

    private function rejected(): ReportContractException
    {
        return ReportContractException::fromCode(ReportErrorCode::REPORT_EXPORT_LIMIT_EXCEEDED);
    }
}
