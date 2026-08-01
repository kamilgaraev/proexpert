<?php

declare(strict_types=1);

use App\BusinessModules\Core\Reporting\Application\Catalog\ImmutableReportConformanceFixtureHashRegistry;
use App\BusinessModules\Core\Reporting\Application\Catalog\ReportBindingCompatibilityChecker;
use App\BusinessModules\Core\Reporting\Application\Catalog\ReportCodeSetComparator;
use App\BusinessModules\Core\Reporting\Application\Catalog\ReportPermissionCatalog;
use App\BusinessModules\Core\Reporting\Application\Catalog\StrictReportDefinitionCandidateValidator;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportDefinitionCanonicalProjector;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportDefinitionVersionPolicy;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportManifestPromotionService;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionConformanceEvidence;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFormulaConformanceEvidence;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceConformanceEvidence;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportManifestSemanticValidator;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\YamlCandidateReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\YamlReportManifestLoader;
use App\BusinessModules\Core\Reporting\Infrastructure\Publication\FilesystemReportPublicationLedger;
use App\BusinessModules\Core\Reporting\Infrastructure\Validation\Draft202012SchemaValidator;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use Opis\JsonSchema\CompliantValidator;
use Tests\Support\Reporting\CatalogBindingTestFactory;
use Tests\Support\Reporting\Publication\ReportCandidateValidationFixtureBuilder;
use Tests\Support\Reporting\RecordingReportConformanceEvidenceRepository;
use Tests\Support\Reporting\ReportConformanceFixtureBuilder;

require dirname(__DIR__, 2).'/vendor/autoload.php';

const REQUIRED_VALUE_OPTIONS = [
    'current',
    'candidate',
    'candidate-sha256',
    'validation',
    'conformance',
    'release-sha',
    'published-at',
    'output',
    'lock-output',
];

function options(array $arguments): array
{
    $resolved = [];
    foreach (array_slice($arguments, 1) as $argument) {
        if ($argument === '--check') {
            if (array_key_exists('check', $resolved)) {
                throw new RuntimeException('report_promotion_option_duplicate');
            }
            $resolved['check'] = true;

            continue;
        }
        if (! preg_match('/^--([a-z0-9-]+)=(.+)$/D', $argument, $matches)
            || ! in_array($matches[1], REQUIRED_VALUE_OPTIONS, true)
            || array_key_exists($matches[1], $resolved)) {
            throw new RuntimeException('report_promotion_option_invalid');
        }
        $resolved[$matches[1]] = $matches[2];
    }
    foreach (REQUIRED_VALUE_OPTIONS as $name) {
        if (! array_key_exists($name, $resolved)) {
            throw new RuntimeException('report_promotion_option_missing');
        }
    }
    $resolved['check'] ??= false;
    if (count($resolved) !== count(REQUIRED_VALUE_OPTIONS) + 1) {
        throw new RuntimeException('report_promotion_option_invalid');
    }

    return $resolved;
}

function inputPath(string $root, string $relative): string
{
    $normalized = str_replace('\\', '/', $relative);
    if ($normalized === ''
        || str_starts_with($normalized, '/')
        || preg_match('/^[A-Za-z]:\//D', $normalized) === 1
        || in_array('..', explode('/', $normalized), true)) {
        throw new RuntimeException('report_promotion_path_invalid');
    }
    $path = realpath($root.'/'.$normalized);
    if (! is_string($path)
        || ! is_file($path)
        || is_link($path)
        || ! str_starts_with(str_replace('\\', '/', $path), $root.'/')) {
        throw new RuntimeException('report_promotion_path_invalid');
    }

    return $path;
}

function outputPath(string $root, string $relative): string
{
    $normalized = str_replace('\\', '/', $relative);
    if ($normalized === ''
        || str_starts_with($normalized, '/')
        || preg_match('/^[A-Za-z]:\//D', $normalized) === 1
        || in_array('..', explode('/', $normalized), true)) {
        throw new RuntimeException('report_promotion_output_path_invalid');
    }
    $directory = realpath(dirname($root.'/'.$normalized));
    if (! is_string($directory)
        || ! is_dir($directory)
        || is_link($directory)
        || ! str_starts_with(str_replace('\\', '/', $directory).'/', $root.'/')) {
        throw new RuntimeException('report_promotion_output_path_invalid');
    }
    $path = str_replace('\\', '/', $directory).'/'.basename($normalized);
    if (is_link($path) || (file_exists($path) && ! is_file($path))) {
        throw new RuntimeException('report_promotion_output_path_invalid');
    }

    return $path;
}

function readBytes(string $path): string
{
    $bytes = @file_get_contents($path);
    if (! is_string($bytes)) {
        throw new RuntimeException('report_promotion_input_unreadable');
    }

    return $bytes;
}

function jsonObject(string $bytes): object
{
    $value = json_decode($bytes, false, 512, JSON_THROW_ON_ERROR);
    if (! is_object($value)) {
        throw new RuntimeException('report_promotion_json_invalid');
    }

    return $value;
}

function jsonArray(string $bytes): array
{
    $value = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($value) || array_is_list($value)) {
        throw new RuntimeException('report_promotion_json_invalid');
    }

    return $value;
}

function conformance(array $document): ReportDefinitionConformanceEvidence
{
    $componentHashes = [];
    foreach ($document['component_class_hashes'] as $component) {
        $componentHashes[$component['class']] = new Sha256Hash($component['sha256']);
    }
    $source = $document['source'];
    $formula = $document['formula'];
    $evidence = new ReportDefinitionConformanceEvidence(
        $document['code'],
        new Sha256Hash($document['definition_hash']),
        $document['contract_version'],
        $document['source_schema_version'],
        new Sha256Hash($document['fixture_hash']),
        new ReportSourceConformanceEvidence(
            new Sha256Hash($source['source_hash']),
            $source['snapshot_kind'],
            $source['snapshot_id'],
            $source['row_count'],
            new Sha256Hash($source['rows_hash']),
            $source['passed'],
            $source['assertion_codes'],
        ),
        new ReportFormulaConformanceEvidence(
            $formula['formula_version'],
            new Sha256Hash($formula['totals_hash']),
            $formula['passed'],
            $formula['assertion_codes'],
        ),
        $componentHashes,
        $document['assertion_count'],
        $document['status'],
        $document['commit_sha'],
        new DateTimeImmutable($document['generated_at']),
    );
    if (! isset($document['digest'])
        || ! is_string($document['digest'])
        || ! hash_equals($document['digest'], $evidence->digest()->value)) {
        throw new RuntimeException('report_promotion_conformance_digest_invalid');
    }

    return $evidence;
}

try {
    $arguments = options($argv);
    $root = str_replace('\\', '/', (string) realpath(dirname(__DIR__, 2)));
    $paths = [];
    foreach (['current', 'candidate', 'candidate-sha256', 'validation', 'conformance'] as $name) {
        $paths[$name] = inputPath($root, $arguments[$name]);
    }
    if (! hash_equals(
        $root.'/'.ReportCandidateValidationFixtureBuilder::CANDIDATE_PATH,
        str_replace('\\', '/', $paths['candidate']),
    )) {
        throw new RuntimeException('report_promotion_candidate_path_invalid');
    }
    $paths['output'] = outputPath($root, $arguments['output']);
    $paths['lock-output'] = outputPath($root, $arguments['lock-output']);

    $candidateBytes = readBytes($paths['candidate']);
    if (! mb_check_encoding($candidateBytes, 'UTF-8')
        || str_starts_with($candidateBytes, "\xEF\xBB\xBF")
        || str_contains($candidateBytes, "\r")
        || ! str_ends_with($candidateBytes, "\n")
        || str_ends_with($candidateBytes, "\n\n")) {
        throw new RuntimeException('report_promotion_candidate_bytes_invalid');
    }
    $checksumBytes = readBytes($paths['candidate-sha256']);
    if (preg_match('/\A([a-f0-9]{64})\n\z/D', $checksumBytes, $checksumMatch) !== 1
        || ! hash_equals($checksumMatch[1], hash('sha256', $candidateBytes))) {
        throw new RuntimeException('report_promotion_candidate_checksum_invalid');
    }

    $schemaValidator = new Draft202012SchemaValidator(new CompliantValidator);
    $loader = new YamlReportManifestLoader(
        $schemaValidator,
        new ReportManifestSemanticValidator,
        new ReportPermissionCatalog,
    );
    $manifestSchemaPath = $root.'/app/BusinessModules/Core/Reporting/resources/management-catalog.v1.schema.json';
    $current = $loader->loadManagement($paths['current'], $manifestSchemaPath);
    $candidateManifest = $loader->loadManagement($paths['candidate'], $manifestSchemaPath);
    if (! hash_equals($candidateManifest->bytesHash->value, $checksumMatch[1])) {
        throw new RuntimeException('report_promotion_candidate_checksum_invalid');
    }

    $validationBytes = readBytes($paths['validation']);
    $validationDocument = jsonArray($validationBytes);
    if (! hash_equals(CanonicalJson::encode($validationDocument)."\n", $validationBytes)) {
        throw new RuntimeException('report_promotion_validation_noncanonical');
    }
    $schemaValidator->assertValid(
        jsonObject($validationBytes),
        jsonObject(readBytes($root.'/docs/reports/contracts/report-candidate-validation.schema.json')),
        'most.report-candidate-validation.v1',
    );

    $factory = new ReportDefinitionFactory;
    $registry = new YamlCandidateReportDefinitionRegistry($candidateManifest, $factory);
    $candidateCodes = $registry->candidateCodes();
    $candidateTuples = [];
    foreach ($candidateCodes as $code) {
        $item = $registry->candidate($code);
        $candidateTuples[] = [$item->code, $item->definitionHash->value];
    }
    $validationTuples = [];
    foreach ($validationDocument['items'] as $item) {
        $validationTuples[] = [$item['code'], $item['definition_hash']];
    }
    if (($validationDocument['candidate_manifest']['path'] ?? null) !== ReportCandidateValidationFixtureBuilder::CANDIDATE_PATH
        || ($validationDocument['candidate_manifest']['sha256'] ?? null) !== $candidateManifest->bytesHash->value
        || ($validationDocument['candidate_manifest']['codes'] ?? null) !== $candidateCodes
        || $validationTuples !== $candidateTuples) {
        throw new RuntimeException('report_promotion_validation_identity_invalid');
    }

    $conformanceBytes = readBytes($paths['conformance']);
    $conformanceDocument = jsonArray($conformanceBytes);
    $schemaValidator->assertValid(
        jsonObject($conformanceBytes),
        jsonObject(readBytes($root.'/docs/reports/contracts/report-conformance-evidence.schema.json')),
        'most.report-conformance-evidence.v1',
    );
    $evidence = conformance($conformanceDocument);
    if (count($candidateCodes) !== 1 || ! hash_equals($candidateCodes[0], $evidence->code)) {
        throw new RuntimeException('report_promotion_conformance_identity_invalid');
    }
    $candidate = $registry->candidate($candidateCodes[0]);
    $binding = CatalogBindingTestFactory::binding($candidate->payload());
    $fixture = (new ReportConformanceFixtureBuilder)->build();
    if (! hash_equals($fixture->fixtureHash->value, $evidence->fixtureHash->value)) {
        throw new RuntimeException('report_promotion_conformance_fixture_invalid');
    }
    $concreteValidator = new StrictReportDefinitionCandidateValidator(
        new RecordingReportConformanceEvidenceRepository($evidence),
        new ImmutableReportConformanceFixtureHashRegistry([$candidate->code => $fixture]),
        new ReportBindingCompatibilityChecker,
        new ReportCodeSetComparator,
    );
    $projector = new ReportDefinitionCanonicalProjector;
    $generated = (new ReportCandidateValidationFixtureBuilder(
        $concreteValidator,
        $factory,
        $projector,
    ))->build(
        $candidateManifest,
        $registry,
        [$candidate->code => $binding],
    );
    if (! hash_equals($checksumBytes, $generated->checksumBytes)
        || ! hash_equals($validationBytes, $generated->validationBytes)) {
        throw new RuntimeException('report_promotion_validation_not_validator_derived');
    }

    $service = new ReportManifestPromotionService(
        new ReportDefinitionVersionPolicy,
        $projector,
        $factory,
        $loader,
        $schemaValidator,
        $manifestSchemaPath,
        $root.'/docs/reports/contracts/report-publication-lock.schema.json',
    );
    $release = $service->promote(
        $current,
        $candidateManifest,
        $candidate,
        $generated->validation,
        $evidence,
        new Sha256Hash($checksumMatch[1]),
        $arguments['release-sha'],
        new DateTimeImmutable($arguments['published-at']),
    );
    $lockBytes = CanonicalJson::encode($release->lock->canonicalPayload())."\n";

    if ($arguments['check']) {
        if (! hash_equals(readBytes($paths['output']), $release->publishedBytes)
            || ! hash_equals(readBytes($paths['lock-output']), $lockBytes)) {
            throw new RuntimeException('report_promotion_fixture_stale');
        }
    } else {
        $ledgerPath = dirname($paths['lock-output']).'/report-publication-ledger.json';
        (new FilesystemReportPublicationLedger(
            $schemaValidator,
            $root.'/docs/reports/contracts/report-publication-ledger.schema.json',
        ))->publish(
            $ledgerPath,
            $release->lock,
            [
                $paths['output'] => $release->publishedBytes,
                $paths['lock-output'] => $lockBytes,
            ],
            static function (string $final, string $temporary, string $bytes) use (
                $loader,
                $manifestSchemaPath,
                $release,
                $paths,
                $schemaValidator,
                $root,
            ): void {
                if (hash_equals($final, $paths['output'])) {
                    $loaded = $loader->loadManagement($temporary, $manifestSchemaPath);
                    if (! hash_equals($loaded->bytesHash->value, $release->publishedBytesHash->value)
                        || ! hash_equals(hash('sha256', $bytes), $release->publishedBytesHash->value)) {
                        throw new RuntimeException('report_promotion_output_hash_invalid');
                    }

                    return;
                }
                if (! hash_equals($final, $paths['lock-output'])) {
                    throw new RuntimeException('report_promotion_output_path_invalid');
                }
                $schemaValidator->assertValid(
                    jsonObject($bytes),
                    jsonObject(readBytes($root.'/docs/reports/contracts/report-publication-lock.schema.json')),
                    'most.report-publication-lock.v1',
                );
            },
        );
    }

    fwrite(STDOUT, "promotion-check: PASS\n");
    exit(0);
} catch (Throwable) {
    fwrite(STDERR, "promotion-check: FAIL\n");
    exit(1);
}
