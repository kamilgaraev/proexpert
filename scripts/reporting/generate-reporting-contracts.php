<?php

declare(strict_types=1);

use App\BusinessModules\Core\Reporting\Application\Catalog\ReportPermissionCatalog;
use App\BusinessModules\Core\Reporting\Application\Generation\ReportCatalogArtifactGenerator;
use App\BusinessModules\Core\Reporting\Application\Generation\ReportPermissionTranslationGenerator;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ManifestReportCatalogMetadataRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ManifestReportSchedulingCapabilityRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\PublishedReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportManifestSemanticValidator;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\YamlReportManifestLoader;
use App\BusinessModules\Core\Reporting\Infrastructure\Validation\Draft202012SchemaValidator;
use Opis\JsonSchema\CompliantValidator;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$phase = 'platform';
$check = false;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--phase=')) {
        $phase = substr($argument, strlen('--phase='));
    } elseif ($argument === '--check') {
        $check = true;
    } else {
        fwrite(STDERR, "reporting-contracts: invalid argument\n");
        exit(2);
    }
}

$root = dirname(__DIR__, 2);
$manifestPath = $root.'/app/BusinessModules/Core/Reporting/resources/management-catalog.v1.yaml';
$manifestBytes = file_get_contents($manifestPath);
if ($manifestBytes === false) {
    fwrite(STDERR, "reporting-contracts: manifest unreadable\n");
    exit(2);
}

$loader = new YamlReportManifestLoader(
    new Draft202012SchemaValidator(new CompliantValidator),
    new ReportManifestSemanticValidator,
    new ReportPermissionCatalog,
);
$manifest = $loader->loadManagement(
    $manifestPath,
    $root.'/app/BusinessModules/Core/Reporting/resources/management-catalog.v1.schema.json',
);
$factory = new ReportDefinitionFactory;
$registry = new PublishedReportDefinitionRegistry($manifest, $factory);
$metadata = new ManifestReportCatalogMetadataRegistry($manifest, $factory, $registry);
$scheduling = new ManifestReportSchedulingCapabilityRegistry($manifest, $factory, $registry);
$generated = (new ReportCatalogArtifactGenerator)->generate($phase, $registry, [
    'manifest_bytes' => $manifestBytes,
    'metadata' => $metadata,
    'scheduling' => $scheduling,
    'translations' => ReportPermissionTranslationGenerator::fromProject($root),
]);

$json = static function (array $value, int $indent = 4): string {
    $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if ($indent === 2) {
        $encoded = preg_replace_callback('/^( +)/m', static fn (array $matches): string => str_repeat(' ', intdiv(strlen($matches[1]), 2)), $encoded);
    }

    return $encoded."\n";
};

$wirePath = $root.'/tests/Fixtures/Reporting/Wire/reporting-admin-resources.v1.json';
$wire = json_decode((string) file_get_contents($wirePath), false, 512, JSON_THROW_ON_ERROR);
if (! is_object($wire)) {
    throw new RuntimeException('reporting_wire_fixture_invalid');
}
$wire->catalog = (object) [
    'success' => true,
    'message' => null,
    'data' => json_decode(json_encode($generated['resource'], JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR),
];
unset($wire->catalog_generated);

$files = [
    $root.'/docs/reports/generated/reporting-catalog.v1.json' => $json($generated['catalog']),
    $root.'/docs/reports/generated/reporting-catalog.v1.d.ts' => $generated['typeScript'],
    $root.'/docs/reports/generated/report-permissions.v1.json' => $json($generated['translations']),
    $root.'/docs/reports/contracts/reporting-generation.lock.json' => $json($generated['lock']),
    $root.'/tests/Fixtures/Reporting/Wire/report-catalog-resource.v1.json' => $json($generated['resource']),
    $wirePath => json_encode($wire, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
];

$dirty = false;
foreach ($files as $path => $contents) {
    $current = is_file($path) ? file_get_contents($path) : false;
    if ($current !== $contents) {
        $dirty = true;
        if (! $check) {
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            file_put_contents($path, $contents);
        }
    }
}

if ($check && $dirty) {
    fwrite(STDERR, "reporting-contracts: generated artifacts are stale\n");
    exit(1);
}

fwrite(STDOUT, $check ? "reporting-contracts: clean\n" : "reporting-contracts: generated\n");
