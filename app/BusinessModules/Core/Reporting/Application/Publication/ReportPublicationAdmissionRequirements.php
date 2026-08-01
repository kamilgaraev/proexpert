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
    /** @var array<string, array<string, class-string>> */
    private const DELIVERY_RENDERERS_BY_CODE = [
        'budget_plan_fact' => [
            'csv' => CsvReportExportRenderer::class,
            'xlsx' => XlsxReportExportRenderer::class,
        ],
        'procurement_cycle' => [
            'csv' => CsvReportExportRenderer::class,
            'pdf' => PdfReportExportRenderer::class,
            'xlsx' => XlsxReportExportRenderer::class,
        ],
    ];

    /** @var list<string> */
    private const BASE_REQUIRED_CHECKS = [
        'binding_contract',
        'drill_down_contract',
        'formula_contract',
        'postgresql_contract',
        'rbac_contract',
        'source_contract',
    ];

    public static function profileCatalog(): ReportPublicationAdmissionProfileCatalog
    {
        $profiles = [];
        foreach (self::catalog()['codes'] as $code => $contracts) {
            $exports = [];
            foreach ($contracts['exports'] as $format => $contract) {
                $exports[$format] = [
                    'schema_sha256' => $contract['schema_sha256'],
                    'renderer_class' => $contract['renderer_class'],
                ];
            }

            $checks = array_merge(
                self::BASE_REQUIRED_CHECKS,
                array_map(static fn (string $format): string => 'export_'.$format.'_contract', array_keys($exports)),
            );
            sort($checks, SORT_STRING);
            $profiles[] = new ReportPublicationAdmissionProfile(
                $code,
                $checks,
                $contracts['drill_down']['schema_sha256'],
                $exports,
            );
        }

        return new ReportPublicationAdmissionProfileCatalog($profiles);
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
        $hashes = [];
        foreach (self::catalog()['codes'] as $code => $contracts) {
            $hashes[$code] = [
                'delivery_contract_sha256' => hash('sha256', CanonicalJson::encode($contracts['exports'])),
                'drill_contract_sha256' => $contracts['drill_down']['schema_sha256'],
            ];
        }

        return $hashes;
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
        $codes = $decoded['codes'] ?? null;
        if (! is_array($codes) || array_is_list($codes)
            || array_keys($codes) !== array_keys(self::DELIVERY_RENDERERS_BY_CODE)) {
            throw new RuntimeException('report_publication_delivery_contracts_invalid');
        }

        foreach (self::DELIVERY_RENDERERS_BY_CODE as $code => $renderers) {
            $contracts = $codes[$code] ?? null;
            if (! is_array($contracts) || array_keys($contracts) !== ['drill_down', 'exports', 'version']
                || $contracts['version'] !== '1.0.0'
                || ! is_array($contracts['drill_down'] ?? null) || ! is_array($contracts['exports'] ?? null)
                || array_keys($contracts['drill_down']) !== ['schema', 'schema_sha256']
                || array_keys($contracts['exports']) !== array_keys($renderers)) {
                throw new RuntimeException('report_publication_delivery_contracts_invalid');
            }

            $drillDown = $contracts['drill_down'];
            if (! is_array($drillDown) || ! is_array($drillDown['schema'] ?? null)
                || ! is_string($drillDown['schema_sha256'] ?? null)
                || ! hash_equals($drillDown['schema_sha256'], hash('sha256', CanonicalJson::encode($drillDown['schema'])))) {
                throw new RuntimeException('report_publication_delivery_contracts_invalid');
            }

            foreach ($renderers as $format => $renderer) {
                $contract = $contracts['exports'][$format];
                if (! is_array($contract) || array_keys($contract) !== ['renderer_class', 'schema', 'schema_sha256']
                    || $contract['renderer_class'] !== $renderer || ! is_array($contract['schema'] ?? null)
                    || ! is_string($contract['schema_sha256'] ?? null)
                    || ! hash_equals($contract['schema_sha256'], hash('sha256', CanonicalJson::encode($contract['schema'])))) {
                    throw new RuntimeException('report_publication_delivery_contracts_invalid');
                }
            }
        }

        return $decoded;
    }
}
