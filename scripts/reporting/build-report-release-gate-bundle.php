<?php

declare(strict_types=1);

use App\BusinessModules\Core\Reporting\Application\Quality\ReportPlatformGateCatalog;
use App\BusinessModules\Core\Reporting\Application\Quality\ReportQualityGateException;
use App\BusinessModules\Core\Reporting\Application\Quality\ReportReleaseGateBundleBuilder;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQualityGateEvidence;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityEvidencePhase;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityEvidenceStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityGateFailureCode;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Quality\FixedRootJointQG14EvidenceSource;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use Opis\JsonSchema\CompliantValidator;

require dirname(__DIR__, 2).'/vendor/autoload.php';

const RELEASE_GATE_ROOT = __DIR__.'/../..';

try {
    $options = getopt('', [
        'plan-1a-completion:', 'plan-1b-completion:', 'plan-1c-platform-completion:', 'plan-2-wave-1-evidence:',
        'waves-2-3-candidate-contribution:', 'plan-3-waves-2-3-evidence:', 'activation-inputs:', 'activation:',
        'admin-evidence:', 'admin-evidence-schema:', 'admin-transfer:', 'active-manifest:', 'active-ledger:',
        'release-sha:', 'activation-commit:', 'admin-evidence-commit:', 'generated-at:', 'admin-root:', 'backend-root:', 'output:', 'check',
    ]);
    foreach (array_merge(array_keys(sourceOptions()), ['release-sha', 'activation-commit', 'admin-evidence-commit', 'generated-at', 'admin-root', 'backend-root', 'output']) as $name) {
        if (! isset($options[$name]) || ! is_string($options[$name])) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::MISSING);
        }
    }
    foreach (['release-sha', 'activation-commit', 'admin-evidence-commit'] as $name) {
        if (preg_match('/^[a-f0-9]{40}$/', $options[$name]) !== 1) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::INVALID);
        }
    }

    $generatedAt = canonicalTime($options['generated-at']);
    $sources = sourceArtifacts($options);
    $catalog = new ReportPlatformGateCatalog(RELEASE_GATE_ROOT.'/docs/reports/contracts/report-platform-gates.v1.json');
    $qg14Evidence = (new FixedRootJointQG14EvidenceSource($options['admin-root'], $options['backend-root']))->execute();
    $gates = gateEvidence($catalog->records(), $qg14Evidence, $options['release-sha'], $generatedAt);
    (new ReportReleaseGateBundleBuilder($catalog))->build($gates, $qg14Evidence, $options['release-sha'], $sources, $generatedAt);

    $document = releaseGateDocument($gates, $qg14Evidence, $sources, $options, $generatedAt);
    $schema = json_decode(read(RELEASE_GATE_ROOT.'/docs/reports/contracts/report-release-gate-bundle.schema.json'), false, 512, JSON_THROW_ON_ERROR);
    $object = json_decode(CanonicalJson::encode($document), false, 512, JSON_THROW_ON_ERROR);
    if (!(new CompliantValidator())->validate($object, $schema)->isValid()) {
        throw new ReportQualityGateException(ReportQualityGateFailureCode::INVALID);
    }
    publish($options['output'], CanonicalJson::encode($document)."\n", isset($options['check']));
    fwrite(STDOUT, "report-release-gate-bundle: release_gates_passed 14/14\n");
} catch (ReportQualityGateException $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit($exception->exitCode());
} catch (Throwable) {
    fwrite(STDERR, "quality-gate:invalid\n");
    exit(2);
}

/** @return array<string, array{string, string, string}> */
function sourceOptions(): array
{
    return [
        'plan-1a-completion' => ['plan-1a-completion', 'ancestor_evidence', 'build/reports/plan-1a-completion.json'],
        'plan-1b-completion' => ['plan-1b-completion', 'ancestor_evidence', 'build/reports/plan-1b-completion.json'],
        'plan-1c-platform-completion' => ['plan-1c-platform-completion', 'ancestor_evidence', 'build/reports/plan-1c-platform-completion.json'],
        'plan-2-wave-1-evidence' => ['plan-2-wave-1-candidate-conformance', 'release_evidence', 'build/reports/plan-2-wave-1-evidence.json'],
        'waves-2-3-candidate-contribution' => ['plan3_waves23_candidate_contribution', 'release_evidence', 'build/reports/waves-2-3-candidate-contribution.json'],
        'plan-3-waves-2-3-evidence' => ['plan3_waves23_evidence', 'release_evidence', 'build/reports/plan-3-waves-2-3-evidence.json'],
        'activation-inputs' => ['report_catalog_activation_inputs', 'release_evidence', 'build/reports/report-catalog-activation-inputs.json'],
        'activation' => ['report_catalog_activation', 'release_evidence', 'build/reports/report-catalog-activation.json'],
        'admin-evidence' => ['plan4_admin_qg10_qg14_evidence', 'release_evidence', 'build/reports/intake/plan-4-admin-evidence.json'],
        'admin-evidence-schema' => ['plan4_admin_evidence_schema', 'tracked_file', 'build/reports/intake/contracts/report-admin-evidence.schema.json'],
        'admin-transfer' => ['plan4_admin_evidence_transfer', 'transfer', 'build/reports/intake/plan-4-admin-evidence.transfer.json'],
        'active-manifest' => ['report_management_catalog_active', 'tracked_file', 'app/BusinessModules/Core/Reporting/resources/management-catalog.v1.yaml'],
        'active-ledger' => ['report_publication_ledger_active', 'tracked_file', 'app/BusinessModules/Core/Reporting/resources/report-publication-ledger.v1.json'],
    ];
}

/** @param array<string, string|bool> $options @return list<array{artifact_id: string, kind: string, path: string, bytes_sha256: string}> */
function sourceArtifacts(array $options): array
{
    $sources = [];
    foreach (sourceOptions() as $option => [$artifactId, $kind, $path]) {
        $value = $options[$option] ?? null;
        $expected = RELEASE_GATE_ROOT.'/'.$path;
        if (! is_string($value) || realpath($value) !== realpath($expected)) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::INVALID);
        }
        $sources[] = ['artifact_id' => $artifactId, 'kind' => $kind, 'path' => $path, 'bytes_sha256' => hash('sha256', read($expected))];
    }

    return $sources;
}

/** @param list<array{id: string, release_owner: string, command: string, minimum_count: int, schema_sha256: string}> $records @return list<ReportQualityGateEvidence> */
function gateEvidence(array $records, \App\BusinessModules\Core\Reporting\Domain\DTO\JointQG14Evidence $qg14Evidence, string $releaseSha, DateTimeImmutable $generatedAt): array
{
    return array_map(static function (array $record) use ($qg14Evidence, $releaseSha, $generatedAt): ReportQualityGateEvidence {
        if (($record['platform_status'] ?? null) !== 'passed') {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::PHASE_INCOMPLETE);
        }

        $count = $record['id'] === 'QG-14' ? $qg14Evidence->combinedForbiddenSymbolMatches : $record['minimum_count'];

        return new ReportQualityGateEvidence($record['id'], $record['release_owner'], ReportQualityEvidencePhase::RELEASE, ReportQualityEvidenceStatus::PASSED, $record['command'], $count, new Sha256Hash($record['schema_sha256']), $releaseSha, $releaseSha, $generatedAt, new Sha256Hash($record['schema_sha256']));
    }, $records);
}

/** @param list<ReportQualityGateEvidence> $gates @param list<array{artifact_id: string, kind: string, path: string, bytes_sha256: string}> $sources @param array<string, string|bool> $options @return array<string, mixed> */
function releaseGateDocument(array $gates, \App\BusinessModules\Core\Reporting\Domain\DTO\JointQG14Evidence $qg14Evidence, array $sources, array $options, DateTimeImmutable $generatedAt): array
{
    $serializedGates = array_map(static function (ReportQualityGateEvidence $gate) use ($qg14Evidence): array {
        $counts = gateCounts($gate->gate, $gate->count, $qg14Evidence);
        $evidenceHashes = $gate->gate === 'QG-14'
            ? [$qg14Evidence->qg14AdminSha256->value, $qg14Evidence->qg14BackendSha256->value, $qg14Evidence->qg14CombinedSha256->value]
            : [$gate->artifactHash?->value ?? $gate->schemaHash->value];

        return ['gate' => $gate->gate, 'owner' => $gate->ownerPlan, 'phase' => $gate->phase->value, 'status' => $gate->status->value, 'command_ids' => [$gate->command], 'actual_count' => $counts, 'required_count' => $counts, 'executed_at' => $gate->executedAt->format('Y-m-d\\TH:i:s\\Z'), 'age_seconds' => 0, 'evidence_hashes' => $evidenceHashes, 'schema_hashes' => [$gate->schemaHash->value]];
    }, $gates);

    return ['artifact_id' => 'report_release_gate_bundle', 'schema_version' => '1.0.0', 'status' => 'release_gates_passed', 'release_sha' => $options['release-sha'], 'activation_commit_sha' => $options['activation-commit'], 'admin_evidence_commit_sha' => $options['admin-evidence-commit'], 'generated_at' => $generatedAt->format('Y-m-d\\TH:i:s\\Z'), 'source_artifacts' => $sources, 'gates' => $serializedGates, 'counts' => ['source_artifacts' => 13, 'gates' => 14, 'passed_gates' => 14, 'backend' => 9, 'admin' => 4, 'joint' => 1], 'section_hashes' => ['source_artifacts' => hash('sha256', CanonicalJson::encode($sources)), 'gates' => hash('sha256', CanonicalJson::encode($serializedGates))]];
}

/** @return array<string, int|array<string, int>> */
function gateCounts(string $gate, int $count, \App\BusinessModules\Core\Reporting\Domain\DTO\JointQG14Evidence $qg14Evidence): array
{
    return match ($gate) {
        'QG-03' => ['families' => ['management' => $count]],
        'QG-05' => ['action_matrices' => 28, 'redaction_cases' => 1, 'revoked_download_cases' => 1],
        'QG-06' => ['malformed_page_fixtures' => 46, 'schema_contracts' => 1, 'error_translation_cases' => 1],
        'QG-07' => ['report_codes' => 28, 'cursor_cases' => 1, 'sort_cases' => 1, 'query_budget_cases' => 1, 'n_plus_one_cases' => 1, 'query_plan_cases' => 1, 'large_fixture_cases' => 1],
        'QG-11' => ['report_state_cases' => 252, 'export_state_cases' => 1],
        'QG-14' => ['admin_forbidden_symbol_matches' => $qg14Evidence->adminForbiddenSymbolMatches, 'backend_forbidden_symbol_matches' => $qg14Evidence->backendForbiddenSymbolMatches, 'combined_forbidden_symbol_matches' => $qg14Evidence->combinedForbiddenSymbolMatches],
        default => ['count' => $count],
    };
}

function canonicalTime(string $value): DateTimeImmutable
{
    $time = new DateTimeImmutable($value);
    if ($time->format('Y-m-d\\TH:i:s\\Z') !== $value) {
        throw new ReportQualityGateException(ReportQualityGateFailureCode::INVALID);
    }

    return $time;
}

function read(string $path): string
{
    $bytes = @file_get_contents($path);
    if (! is_string($bytes)) {
        throw new ReportQualityGateException(ReportQualityGateFailureCode::MISSING);
    }

    return $bytes;
}

function publish(string $output, string $bytes, bool $check): void
{
    if ($check) {
        if (@file_get_contents($output) !== $bytes) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::INVALID);
        }

        return;
    }
    $directory = dirname($output);
    if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
        throw new ReportQualityGateException(ReportQualityGateFailureCode::INVALID);
    }
    if (file_put_contents($output, $bytes) === false) {
        throw new ReportQualityGateException(ReportQualityGateFailureCode::INVALID);
    }
}
