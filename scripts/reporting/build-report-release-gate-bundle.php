<?php

declare(strict_types=1);

use App\BusinessModules\Core\Reporting\Application\Quality\ReportQualityGateException;
use App\BusinessModules\Core\Reporting\Application\Quality\ReportPlatformGateCatalog;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityGateFailureCode;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use Opis\JsonSchema\CompliantValidator;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $options = getopt('', ['input:', 'release-sha:', 'activation-commit:', 'admin-evidence-commit:', 'output:', 'check']);
    foreach (['input', 'release-sha', 'activation-commit', 'admin-evidence-commit', 'output'] as $name) {
        if (!isset($options[$name]) || !is_string($options[$name])) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::INVALID);
        }
    }
    foreach (['release-sha', 'activation-commit', 'admin-evidence-commit'] as $name) {
        if (preg_match('/^[a-f0-9]{40}$/', $options[$name]) !== 1) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::INVALID);
        }
    }
    $bytes = @file_get_contents($options['input']);
    $root = dirname(__DIR__, 2);
    $schemaBytes = @file_get_contents($root.'/docs/reports/contracts/report-release-gate-bundle.schema.json');
    if (!is_string($bytes) || !is_string($schemaBytes)) {
        throw new ReportQualityGateException(ReportQualityGateFailureCode::MISSING);
    }
    $document = json_decode($bytes, false, 512, JSON_THROW_ON_ERROR);
    $schema = json_decode($schemaBytes, false, 512, JSON_THROW_ON_ERROR);
    if (!(new CompliantValidator())->validate($document, $schema)->isValid()
        || !is_object($document)
        || ($document->release_sha ?? null) !== $options['release-sha']
        || ($document->activation_commit_sha ?? null) !== $options['activation-commit']
        || ($document->admin_evidence_commit_sha ?? null) !== $options['admin-evidence-commit']) {
        throw new ReportQualityGateException(ReportQualityGateFailureCode::INVALID);
    }
    $input = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($input)) {
        throw new ReportQualityGateException(ReportQualityGateFailureCode::INVALID);
    }
    $canonical = CanonicalJson::encode(buildReleaseGateBundle($input, $root))."\n";
    if (!hash_equals($canonical, $bytes)) {
        throw new ReportQualityGateException(ReportQualityGateFailureCode::INVALID);
    }
    if (isset($options['check'])) {
        if (@file_get_contents($options['output']) !== $bytes) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::INVALID);
        }
    } else {
        $directory = dirname($options['output']);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::INVALID);
        }
        file_put_contents($options['output'], $bytes);
    }
    fwrite(STDOUT, "report-release-gate-bundle: release_gates_passed 14/14\n");
} catch (ReportQualityGateException $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit($exception->exitCode());
} catch (Throwable) {
    fwrite(STDERR, "quality-gate:invalid\n");
    exit(2);
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function buildReleaseGateBundle(array $input, string $root): array
{
    $generatedAt = new DateTimeImmutable((string) ($input['generated_at'] ?? ''));
    $records = (new ReportPlatformGateCatalog($root.'/docs/reports/contracts/report-platform-gates.v1.json'))->records();
    $sources = $input['source_artifacts'] ?? null;
    $gates = $input['gates'] ?? null;
    if (! is_array($sources) || ! array_is_list($sources) || count($sources) !== 13 || ! is_array($gates) || ! array_is_list($gates) || count($gates) !== 14) {
        throw new ReportQualityGateException(ReportQualityGateFailureCode::INVALID);
    }

    foreach ($sources as $index => $source) {
        [$artifactId, $kind, $path] = releaseSourceArtifacts()[$index];
        if (! is_array($source) || array_keys($source) !== ['artifact_id', 'kind', 'path', 'bytes_sha256']
            || ($source['artifact_id'] ?? null) !== $artifactId
            || ($source['kind'] ?? null) !== $kind
            || ($source['path'] ?? null) !== $path
            || ! is_string($source['bytes_sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/', $source['bytes_sha256']) !== 1
            || ! matchesSourceArtifactBytes($root, $path, $source['bytes_sha256'])) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::INVALID);
        }
    }

    foreach ($gates as $index => $gate) {
        $record = $records[$index];
        if (! is_array($gate)
            || ($gate['gate'] ?? null) !== $record['id']
            || ($gate['owner'] ?? null) !== $record['release_owner']
            || ($gate['command_ids'] ?? null) !== [$record['command']]
            || ! is_array($gate['actual_count'] ?? null)
            || ! is_array($gate['required_count'] ?? null)
            || ! is_string($gate['executed_at'] ?? null)) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::INVALID);
        }
        $executedAt = new DateTimeImmutable($gate['executed_at']);
        $age = $generatedAt->getTimestamp() - $executedAt->getTimestamp();
        if (($gate['age_seconds'] ?? null) !== $age || $age < 0 || ($record['id'] === 'QG-07' && $age > 86400)) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::INVALID);
        }
        assertGateCounts($record['id'], $gate['actual_count'], $gate['required_count'], $record['minimum_count']);
    }

    return [
        'artifact_id' => 'report_release_gate_bundle',
        'schema_version' => '1.0.0',
        'status' => 'release_gates_passed',
        'release_sha' => $input['release_sha'],
        'activation_commit_sha' => $input['activation_commit_sha'],
        'admin_evidence_commit_sha' => $input['admin_evidence_commit_sha'],
        'generated_at' => $input['generated_at'],
        'source_artifacts' => $sources,
        'gates' => $gates,
        'counts' => ['source_artifacts' => 13, 'gates' => 14, 'passed_gates' => 14, 'backend' => 9, 'admin' => 4, 'joint' => 1],
        'section_hashes' => [
            'source_artifacts' => hash('sha256', CanonicalJson::encode($sources)),
            'gates' => hash('sha256', CanonicalJson::encode($gates)),
        ],
    ];
}

/** @return list<array{string, string, string}> */
function releaseSourceArtifacts(): array
{
    return [
        ['plan-1a-completion', 'ancestor_evidence', 'build/reports/plan-1a-completion.json'],
        ['plan-1b-completion', 'ancestor_evidence', 'build/reports/plan-1b-completion.json'],
        ['plan-1c-platform-completion', 'ancestor_evidence', 'build/reports/plan-1c-platform-completion.json'],
        ['plan-2-wave-1-candidate-conformance', 'release_evidence', 'build/reports/plan-2-wave-1-evidence.json'],
        ['plan3_waves23_candidate_contribution', 'release_evidence', 'build/reports/waves-2-3-candidate-contribution.json'],
        ['plan3_waves23_evidence', 'release_evidence', 'build/reports/plan-3-waves-2-3-evidence.json'],
        ['report_catalog_activation_inputs', 'release_evidence', 'build/reports/report-catalog-activation-inputs.json'],
        ['report_catalog_activation', 'release_evidence', 'build/reports/report-catalog-activation.json'],
        ['plan4_admin_qg10_qg14_evidence', 'release_evidence', 'build/reports/intake/plan-4-admin-evidence.json'],
        ['plan4_admin_evidence_schema', 'tracked_file', 'build/reports/intake/contracts/report-admin-evidence.schema.json'],
        ['plan4_admin_evidence_transfer', 'transfer', 'build/reports/intake/plan-4-admin-evidence.transfer.json'],
        ['report_management_catalog_active', 'tracked_file', 'app/BusinessModules/Core/Reporting/resources/management-catalog.v1.yaml'],
        ['report_publication_ledger_active', 'tracked_file', 'app/BusinessModules/Core/Reporting/resources/report-publication-ledger.v1.json'],
    ];
}

function matchesSourceArtifactBytes(string $root, string $path, string $expectedHash): bool
{
    $bytes = @file_get_contents($root.'/'.$path);

    return is_string($bytes) && hash_equals(hash('sha256', $bytes), $expectedHash);
}

/** @param array<string, int> $actual @param array<string, int> $required */
function assertGateCounts(string $gate, array $actual, array $required, int $minimum): void
{
    if ($gate === 'QG-03') {
        $actualFamilies = $actual['families'] ?? null;
        $requiredFamilies = $required['families'] ?? null;
        if (! is_array($actualFamilies) || ! is_array($requiredFamilies) || $actualFamilies === [] || array_keys($actualFamilies) !== array_keys($requiredFamilies)) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::INVALID);
        }
        foreach ($actualFamilies as $family => $actualCount) {
            if (! is_string($family) || ! is_int($actualCount) || ! is_int($requiredFamilies[$family]) || $requiredFamilies[$family] < $minimum || $actualCount < $requiredFamilies[$family]) {
                throw new ReportQualityGateException(ReportQualityGateFailureCode::INVALID);
            }
        }

        return;
    }

    if ($actual !== $required || $actual === [] || array_sum($actual) < $minimum) {
        throw new ReportQualityGateException(ReportQualityGateFailureCode::INVALID);
    }
}
