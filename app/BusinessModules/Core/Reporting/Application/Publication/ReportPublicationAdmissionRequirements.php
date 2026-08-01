<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use App\BusinessModules\Core\Reporting\Infrastructure\Exports\CsvReportExportRenderer;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\PdfReportExportRenderer;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\XlsxReportExportRenderer;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class ReportPublicationAdmissionRequirements
{
    public static function requiredChecksByCode(): array
    {
        return ['procurement_cycle' => [
            'binding_contract', 'drill_down_contract', 'export_csv_contract', 'export_pdf_contract',
            'export_xlsx_contract', 'formula_contract', 'postgresql_contract', 'rbac_contract', 'source_contract',
        ]];
    }

    public static function deliveryContractsByCode(): array
    {
        $contracts = self::catalog()['codes']['procurement_cycle'];
        $exports = [];
        foreach ($contracts['exports'] as $format => $contract) {
            $exports[$format] = [
                'schema_sha256' => $contract['schema_sha256'],
                'renderer_class' => $contract['renderer_class'],
            ];
        }

        return ['procurement_cycle' => [
            'drill_down_schema_sha256' => $contracts['drill_down']['schema_sha256'],
            'exports' => $exports,
        ]];
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
            || ($decoded['schema_version'] ?? null) !== '1.0.0') {
            throw new RuntimeException('report_publication_delivery_contracts_invalid');
        }
        $code = $decoded['codes']['procurement_cycle'] ?? null;
        if (! is_array($code) || ! is_array($code['drill_down'] ?? null) || ! is_array($code['exports'] ?? null)) {
            throw new RuntimeException('report_publication_delivery_contracts_invalid');
        }
        foreach ([$code['drill_down'], ...array_values($code['exports'])] as $contract) {
            if (! is_array($contract) || ! is_array($contract['schema'] ?? null)
                || ! is_string($contract['schema_sha256'] ?? null)
                || ! hash_equals($contract['schema_sha256'], hash('sha256', CanonicalJson::encode($contract['schema'])))) {
                throw new RuntimeException('report_publication_delivery_contracts_invalid');
            }
        }
        return $decoded;
    }
}
