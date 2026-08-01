<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use App\BusinessModules\Core\Reporting\Application\Exports\ReportExportRenderer;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use LogicException;
use ReflectionClass;

final class ReportPublicationDeliveryContractHasher
{
    private const COMMON_FILES = [
        'app/BusinessModules/Core/Reporting/Application/Exports/ReportExportLimits.php',
        'app/BusinessModules/Core/Reporting/Application/Exports/ReportExportRenderer.php',
        'app/BusinessModules/Core/Reporting/Application/Exports/ReportPdfDocumentBuilder.php',
        'app/BusinessModules/Core/Reporting/Infrastructure/Exports/ReportExportRendererRegistry.php',
        'app/Helpers/TranslationHelper.php',
        'app/Helpers/helpers.php',
        'lang/en/reports.php',
        'lang/ru/reports.php',
    ];

    private const FILES_BY_FORMAT = [
        'csv' => [
            'app/BusinessModules/Core/Reporting/Infrastructure/Exports/CsvReportExportRenderer.php',
        ],
        'xlsx' => [
            'app/BusinessModules/Core/Reporting/Infrastructure/Exports/XlsxReportExportRenderer.php',
        ],
        'pdf' => [
            'app/BusinessModules/Core/Reporting/Application/Exports/ReportPdfDocument.php',
            'app/BusinessModules/Core/Reporting/Application/Exports/ReportPdfDocumentRenderer.php',
            'app/BusinessModules/Core/Reporting/Application/Exports/ReportPdfRenderBudget.php',
            'app/BusinessModules/Core/Reporting/Infrastructure/Exports/DompdfReportPdfDocumentRenderer.php',
            'app/BusinessModules/Core/Reporting/Infrastructure/Exports/PdfReportExportRenderer.php',
            'config/dompdf.php',
            'resources/views/reports/exports/canonical-report-pdf.blade.php',
        ],
    ];

    private const COMPOSER_PACKAGES_BY_FORMAT = [
        'csv' => [],
        'xlsx' => [],
        'pdf' => [
            'barryvdh/laravel-dompdf',
            'dompdf/dompdf',
            'dompdf/php-font-lib',
            'dompdf/php-svg-lib',
            'masterminds/html5',
            'sabberworm/php-css-parser',
            'thecodingmachine/safe',
        ],
    ];

    private const EXTENSIONS_BY_FORMAT = [
        'csv' => ['mbstring'],
        'xlsx' => ['mbstring', 'zip'],
        'pdf' => ['dom', 'mbstring'],
    ];

    public function __construct(private readonly ?string $projectRoot = null) {}

    public function hash(
        string $format,
        string $rendererClass,
        Sha256Hash $rendererHash,
        string $rendererVersion,
        Sha256Hash $schemaHash,
        Sha256Hash $fixtureHash,
        array $assertionCodes,
    ): Sha256Hash {
        return new Sha256Hash(hash('sha256', CanonicalJson::encode($this->descriptor(
            $format,
            $rendererClass,
            $rendererHash,
            $rendererVersion,
            $schemaHash,
            $fixtureHash,
            $assertionCodes,
        ))));
    }

    public function descriptor(
        string $format,
        string $rendererClass,
        Sha256Hash $rendererHash,
        string $rendererVersion,
        Sha256Hash $schemaHash,
        Sha256Hash $fixtureHash,
        array $assertionCodes,
    ): array {
        if (! is_subclass_of($rendererClass, ReportExportRenderer::class)
            || ! hash_equals($format, $rendererClass::format())
            || ! isset(self::FILES_BY_FORMAT[$format])) {
            throw new LogicException('report_publication_renderer_contract_invalid');
        }
        $sortedAssertions = array_values(array_unique($assertionCodes));
        sort($sortedAssertions, SORT_STRING);
        if ($assertionCodes !== $sortedAssertions) {
            throw new LogicException('report_publication_renderer_contract_invalid');
        }
        $rendererFile = (new ReflectionClass($rendererClass))->getFileName();
        if (! is_string($rendererFile)
            || ! hash_equals($rendererHash->value, (string) hash_file('sha256', $rendererFile))) {
            throw new LogicException('report_publication_renderer_contract_invalid');
        }
        $root = $this->projectRoot ?? dirname(__DIR__, 6);
        $paths = array_values(array_unique(array_merge(
            self::COMMON_FILES,
            self::FILES_BY_FORMAT[$format],
        )));
        sort($paths, SORT_STRING);
        $files = [];
        foreach ($paths as $path) {
            $absolutePath = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
            $hash = is_file($absolutePath) ? hash_file('sha256', $absolutePath) : false;
            if (! is_string($hash)) {
                throw new LogicException('report_publication_renderer_contract_invalid');
            }
            $files[] = ['path' => $path, 'sha256' => $hash];
        }

        $extensions = [];
        foreach (self::EXTENSIONS_BY_FORMAT[$format] as $extension) {
            $version = phpversion($extension);
            if (! is_string($version)) {
                throw new LogicException('report_publication_renderer_contract_invalid');
            }
            $extensions[] = ['name' => $extension, 'version' => $version];
        }

        return [
            'assertion_codes' => $assertionCodes,
            'composer_packages' => $this->composerPackages(
                $root,
                self::COMPOSER_PACKAGES_BY_FORMAT[$format],
            ),
            'fixture_sha256' => $fixtureHash->value,
            'format' => $format,
            'project_files' => $files,
            'renderer_class' => $rendererClass,
            'renderer_sha256' => $rendererHash->value,
            'renderer_version' => $rendererVersion,
            'runtime_extensions' => $extensions,
            'schema_sha256' => $schemaHash->value,
            'schema_version' => '1.0.0',
        ];
    }

    private function composerPackages(string $root, array $requiredPackages): array
    {
        $lockBytes = file_get_contents($root.DIRECTORY_SEPARATOR.'composer.lock');
        $lock = is_string($lockBytes) ? json_decode($lockBytes, true, 512, JSON_THROW_ON_ERROR) : null;
        if (! is_array($lock)) {
            throw new LogicException('report_publication_renderer_contract_invalid');
        }
        $packagesByName = [];
        foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
            if (is_array($package) && is_string($package['name'] ?? null)) {
                $packagesByName[$package['name']] = $package;
            }
        }
        $packages = [];
        foreach ($requiredPackages as $name) {
            $package = $packagesByName[$name] ?? null;
            $version = is_array($package) ? ($package['version'] ?? null) : null;
            $reference = is_array($package) ? ($package['source']['reference'] ?? null) : null;
            if (! is_string($version) || ! is_string($reference)) {
                throw new LogicException('report_publication_renderer_contract_invalid');
            }
            $packages[] = [
                'name' => $name,
                'source_reference' => $reference,
                'version' => $version,
            ];
        }

        return $packages;
    }
}
