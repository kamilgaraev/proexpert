<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use App\BusinessModules\Core\Reporting\Infrastructure\Exports\CsvReportExportRenderer;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\PdfReportExportRenderer;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\XlsxReportExportRenderer;

final class ReportPublicationAdmissionRequirements
{
    public static function requiredChecksByCode(): array
    {
        return [
            'procurement_cycle' => [
                'binding_contract',
                'drill_down_contract',
                'export_csv_contract',
                'export_pdf_contract',
                'export_xlsx_contract',
                'formula_contract',
                'postgresql_contract',
                'rbac_contract',
                'source_contract',
            ],
        ];
    }

    public static function deliveryContractsByCode(): array
    {
        return [
            'procurement_cycle' => [
                'drill_down_schema_sha256' => self::hash('procurement_cycle:drill-down:v1'),
                'exports' => [
                    'csv' => [
                        'schema_sha256' => self::hash('procurement_cycle:export:csv:v1'),
                        'renderer_class' => CsvReportExportRenderer::class,
                    ],
                    'pdf' => [
                        'schema_sha256' => self::hash('procurement_cycle:export:pdf:v1'),
                        'renderer_class' => PdfReportExportRenderer::class,
                    ],
                    'xlsx' => [
                        'schema_sha256' => self::hash('procurement_cycle:export:xlsx:v1'),
                        'renderer_class' => XlsxReportExportRenderer::class,
                    ],
                ],
            ],
        ];
    }

    private static function hash(string $contract): string
    {
        return hash('sha256', $contract);
    }
}
