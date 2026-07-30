<?php

declare(strict_types=1);

use App\BusinessModules\Core\Reporting\Application\Catalog\ReportPermissionCatalog;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportCatalogActivationInputBundleLoader;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportCatalogActivationService;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportCatalogActivationTransactionService;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportDefinitionCanonicalProjector;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportDefinitionVersionPolicy;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportManifestPromotionService;
use App\BusinessModules\Core\Reporting\Domain\DTO\LoadedReportManifest;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionConformanceEvidence;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportManifestSemanticValidator;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\YamlCandidateReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\YamlReportManifestLoader;
use App\BusinessModules\Core\Reporting\Infrastructure\Conformance\FilesystemReportConformanceEvidenceRepository;
use App\BusinessModules\Core\Reporting\Infrastructure\Publication\FilesystemReportPublicationLedger;
use App\BusinessModules\Core\Reporting\Infrastructure\Validation\Draft202012SchemaValidator;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use Opis\JsonSchema\CompliantValidator;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function activationOptions(array $argv): array
{
    $required = ['current', 'ledger', 'inputs', 'release-sha', 'activated-at', 'output'];
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--check') {
            if (isset($options['check'])) throw new RuntimeException('report_catalog_activation_option_invalid');
            $options['check'] = true; continue;
        }
        if (preg_match('/^--([a-z0-9-]+)=(.+)$/D', $argument, $match) !== 1 || ! in_array($match[1], $required, true) || isset($options[$match[1]])) {
            throw new RuntimeException('report_catalog_activation_option_invalid');
        }
        $options[$match[1]] = $match[2];
    }
    foreach ($required as $name) if (! isset($options[$name])) throw new RuntimeException('report_catalog_activation_option_missing');
    return $options + ['check' => false];
}

function activationPath(string $root, string $relative, bool $output = false): string
{
    if (preg_match('#^(?:[A-Za-z]:)?[/\\\\]|(?:.*[/\\\\])?\.\.(?:[/\\\\]|$)#D', $relative) === 1) throw new RuntimeException('report_catalog_activation_path_invalid');
    $root = (string) realpath($root);
    $candidate = $root.DIRECTORY_SEPARATOR.$relative;
    $path = $output ? $candidate : realpath($candidate);
    if ($path === false || is_link($path) || (!$output && ! is_file($path)) || ! str_starts_with(str_replace('\\', '/', $path), str_replace('\\', '/', $root).'/')) throw new RuntimeException('report_catalog_activation_path_invalid');
    return $path;
}

function activationDocument(object $activation): string
{
    return CanonicalJson::encode([
        'artifact_id' => 'report_catalog_activation', 'schema_version' => '1.0.0', 'status' => $activation->status,
        'release_sha' => $activation->releaseSha, 'previous_manifest_hash' => $activation->previousManifestHash->value,
        'published_manifest_hash' => $activation->publishedManifestHash->value, 'published_codes' => $activation->publishedCodes,
        'binding_codes' => $activation->bindingCodes, 'publication_lock_hashes' => $activation->publicationLockHashes,
        'conformance_hashes' => $activation->conformanceHashes, 'activated_at' => $activation->activatedAt->format('Y-m-d\\TH:i:s\\Z'),
    ])."\n";
}

try {
    $options = activationOptions($argv);
    $root = (string) realpath(dirname(__DIR__, 2));
    $manifestSchema = $root.'/app/BusinessModules/Core/Reporting/resources/management-catalog.v1.schema.json';
    $schemas = new Draft202012SchemaValidator(new CompliantValidator());
    $definitions = new ReportDefinitionFactory();
    $manifests = new YamlReportManifestLoader($schemas, new ReportManifestSemanticValidator(), new ReportPermissionCatalog());
    $currentPath = activationPath($root, $options['current']);
    $ledgerPath = activationPath($root, $options['ledger']);
    $inputPath = activationPath($root, $options['inputs']);
    $outputPath = activationPath($root, $options['output'], true);
    if ($options['current'] !== 'app/BusinessModules/Core/Reporting/resources/management-catalog.v1.yaml'
        || $options['ledger'] !== 'app/BusinessModules/Core/Reporting/resources/report-publication-ledger.v1.json') throw new RuntimeException('report_catalog_activation_active_path_invalid');

    $bindingLoader = static function (array $rows, LoadedReportManifest $candidate) use ($definitions): array {
        $waveOne = 'App\\BusinessModules\\Core\\Reporting\\Application\\Candidates\\WaveOneCandidateBindingSet';
        $waves23 = 'App\\BusinessModules\\Core\\Reporting\\Application\\Candidates\\Waves23CandidateBindingSet';
        if (! class_exists($waveOne) || ! method_exists($waveOne, 'bindings') || ! class_exists($waves23) || ! method_exists($waves23, 'build')) throw new RuntimeException('report_catalog_activation_binding_set_unavailable');
        $registry = new YamlCandidateReportDefinitionRegistry($candidate, $definitions);
        $bindings = array_merge($waveOne::bindings(), $waves23::build($registry));
        $result = [];
        foreach ($bindings as $binding) {
            if (! $binding instanceof ReportDefinitionBinding || isset($result[$binding->code])) throw new RuntimeException('report_catalog_activation_binding_invalid');
            $result[$binding->code] = $binding;
        }
        if (array_keys($result) !== array_column($rows, 'code')) throw new RuntimeException('report_catalog_activation_binding_descriptor_mismatch');
        return array_values($result);
    };
    $conformanceLoader = static function (array $rows) use ($root, $schemas): array {
        $repository = new FilesystemReportConformanceEvidenceRepository($root, $schemas);
        $result = [];
        foreach ($rows as $row) {
            if (! isset($row['code'], $row['definition_hash'], $row['fixture_hash'], $row['conformance_digest'])) throw new RuntimeException('report_catalog_activation_conformance_descriptor_invalid');
            $evidence = $repository->get($row['code'], new Sha256Hash($row['definition_hash']), new Sha256Hash($row['fixture_hash']));
            if (! hash_equals($row['conformance_digest'], $evidence->digest()->value)) throw new RuntimeException('report_catalog_activation_conformance_descriptor_mismatch');
            $result[] = $evidence;
        }
        return $result;
    };
    $inputs = (new ReportCatalogActivationInputBundleLoader($root, $schemas, $manifests, $manifestSchema, $bindingLoader, $conformanceLoader))->load($inputPath);
    $current = $manifests->loadManagement($currentPath, $manifestSchema);
    $activatedAt = new DateTimeImmutable($options['activated-at']);
    $service = new ReportCatalogActivationService();
    if ($options['check']) {
        $activation = $service->activate($current, $inputs->candidateManifest, $inputs->validation, $inputs->candidateBindings, $inputs->conformanceEvidence, $inputs->planEvidenceDocuments, $options['release-sha'], $activatedAt);
    } else {
        $promotion = new ReportManifestPromotionService(new ReportDefinitionVersionPolicy(), new ReportDefinitionCanonicalProjector(), $definitions, $manifests, $schemas, $manifestSchema, $root.'/docs/reports/contracts/report-publication-lock.schema.json');
        $ledger = new FilesystemReportPublicationLedger($schemas, $root.'/docs/reports/contracts/report-publication-ledger.schema.json');
        $activation = (new ReportCatalogActivationTransactionService($service, $promotion, $definitions, $manifests, $ledger, $manifestSchema))->activate($currentPath, $ledgerPath, $current, $inputs->candidateManifest, $inputs->validation, $inputs->candidateBindings, $inputs->conformanceEvidence, $inputs->planEvidenceDocuments, $options['release-sha'], $activatedAt);
    }
    $bytes = activationDocument($activation);
    if ($options['check']) {
        if (! is_file($outputPath) || ! hash_equals((string) file_get_contents($outputPath), $bytes)) throw new RuntimeException('report_catalog_activation_check_failed');
    } else {
        $temporary = $outputPath.'.'.bin2hex(random_bytes(8)).'.tmp';
        if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes) || ! rename($temporary, $outputPath)) throw new RuntimeException('report_catalog_activation_output_failed');
    }
    fwrite(STDOUT, 'report-catalog-activation: catalog_activated 28/28 sha256='.hash('sha256', $bytes).PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, "quality-gate:invalid\n");
    exit(2);
}
