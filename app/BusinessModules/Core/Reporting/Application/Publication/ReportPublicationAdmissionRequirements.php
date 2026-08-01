<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use App\BusinessModules\Core\Reporting\Infrastructure\Exports\CsvReportExportRenderer;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\PdfReportExportRenderer;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\XlsxReportExportRenderer;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use JsonException;
use RuntimeException;

final class ReportPublicationAdmissionRequirements
{
    public static function profileCatalog(): ReportPublicationAdmissionProfileCatalog
    {
        $contracts = self::catalog()['codes']['procurement_cycle'];
        $exports = [];
        foreach ($contracts['exports'] as $format => $contract) {
            $exports[$format] = [
                'schema_sha256' => $contract['schema_sha256'],
                'renderer_class' => $contract['renderer_class'],
            ];
        }

        return new ReportPublicationAdmissionProfileCatalog([
            new ReportPublicationAdmissionProfile(
                'procurement_cycle',
                [
                    'binding_contract', 'drill_down_contract', 'export_csv_contract', 'export_pdf_contract',
                    'export_xlsx_contract', 'formula_contract', 'postgresql_contract', 'rbac_contract', 'source_contract',
                ],
                $contracts['drill_down']['schema_sha256'],
                $exports,
            ),
        ]);
    }

    public static function requiredChecksByCode(): array
    {
        return self::profileCatalog()->requiredChecksByCode();
    }

    public static function deliveryContractsByCode(): array
    {
        return self::profileCatalog()->deliveryContractsByCode();
    }

    public static function contractHashesByCode(): array
    {
        $contracts = self::catalog()['codes']['procurement_cycle'];

        return ['procurement_cycle' => [
            'delivery_contract_sha256' => hash('sha256', CanonicalJson::encode($contracts['exports'])),
            'drill_contract_sha256' => $contracts['drill_down']['schema_sha256'],
        ]];
    }

    public static function validateFile(string $path): void
    {
        self::readCatalog($path);
    }

    private static function catalog(): array
    {
        static $catalog;
        if (is_array($catalog)) {
            return $catalog;
        }
        $path = dirname(__DIR__, 2).'/resources/report-publication-delivery-contracts.v1.json';
        $catalog = self::readCatalog($path);

        return $catalog;
    }

    private static function readCatalog(string $path): array
    {
        $bytes = @file_get_contents($path);
        try {
            $decoded = is_string($bytes) ? json_decode($bytes, true, 64, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException $exception) {
            throw new RuntimeException('report_publication_delivery_contracts_invalid', 0, $exception);
        }
        if (! is_array($decoded) || array_is_list($decoded) || ! is_string($bytes)
            || array_keys($decoded) !== ['codes', 'schema_version']
            || ($decoded['schema_version'] ?? null) !== '1.0.0') {
            throw new RuntimeException('report_publication_delivery_contracts_invalid');
        }
        $code = $decoded['codes']['procurement_cycle'] ?? null;
        if (! is_array($decoded['codes'] ?? null) || array_keys($decoded['codes']) !== ['procurement_cycle']
            || ! is_array($code) || array_keys($code) !== ['drill_down', 'exports', 'version']
            || $code['version'] !== '1.0.0'
            || ! is_array($code['drill_down'] ?? null) || ! is_array($code['exports'] ?? null)
            || array_keys($code['drill_down']) !== ['schema', 'schema_sha256']
            || array_keys($code['exports']) !== ['csv', 'pdf', 'xlsx']) {
            throw new RuntimeException('report_publication_delivery_contracts_invalid');
        }
        $renderers = [
            'csv' => CsvReportExportRenderer::class,
            'pdf' => PdfReportExportRenderer::class,
            'xlsx' => XlsxReportExportRenderer::class,
        ];
        foreach ([$code['drill_down']] as $contract) {
            if (! is_array($contract) || ! is_array($contract['schema'] ?? null)
                || ! is_string($contract['schema_sha256'] ?? null)
                || ! hash_equals($contract['schema_sha256'], hash('sha256', CanonicalJson::encode($contract['schema'])))) {
                throw new RuntimeException('report_publication_delivery_contracts_invalid');
            }
        }
        foreach ($renderers as $format => $renderer) {
            $contract = $code['exports'][$format];
            if (! is_array($contract) || array_keys($contract) !== ['renderer_class', 'schema', 'schema_sha256']
                || $contract['renderer_class'] !== $renderer || ! is_array($contract['schema'] ?? null)
                || ! is_string($contract['schema_sha256'] ?? null)
                || ! hash_equals($contract['schema_sha256'], hash('sha256', CanonicalJson::encode($contract['schema'])))) {
                throw new RuntimeException('report_publication_delivery_contracts_invalid');
            }
        }

        return $decoded;
    }
}
