<?php

declare(strict_types=1);

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationAdmissionRequirements;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\CiEvidence\GitHubActionsOidcProvenanceVerifier;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\CiEvidence\R15EvidenceIdentity;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\CiEvidence\StreamR15HttpClient;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\CiEvidence\SystemR15Clock;
use Opis\JsonSchema\CompliantValidator;

require dirname(__DIR__, 2).'/vendor/autoload.php';

const R15_CODE = 'procurement_cycle';
const OUTPUT_DIRECTORY = 'build/reports/r15-candidate-evidence';
const WORKFLOW_NAME = 'Notification PostgreSQL Concurrency';
const WORKFLOW_REF = 'kamilgaraev/proexpert/.github/workflows/notification-concurrency.yml@refs/heads/main';
const REPOSITORY = 'kamilgaraev/proexpert';
const REF = 'refs/heads/main';

try {
    $check = $argc === 2 && $argv[1] === '--check';
    if (! $check && $argc !== 1) {
        throw new RuntimeException('r15_candidate_evidence_input_invalid');
    }
    $root = dirname(__DIR__, 2);
    [$sha, $runId, $attempt] = githubActionsContext($root);
    authenticateGithubOidc($sha, $runId);
    $directory = $root.'/'.OUTPUT_DIRECTORY;
    if ($check) {
        validateExistingDocuments($root, $directory, $sha);
        exit(0);
    }
    $artifacts = artifactGroups($root, $sha);
    $timestamp = commitTimestamp($sha);
    $definition = candidateDefinition($artifacts);
    $conformance = generateConformanceEvidence($root, $sha, $timestamp, $artifacts);
    $candidate = [
        'admission_status' => 'candidate', 'candidate_definition' => $definition,
        'candidate_definition_sha256' => digest($definition), 'code' => R15_CODE,
        'contract_version' => '1.0.0', 'formula_version' => 'procurement-cycle.v1',
        'generated_from_commit' => $sha, 'publication_status' => 'candidate',
        'runtime_binding' => $artifacts['runtime_binding'], 'source_schema_version' => '1.0.0',
    ];
    $proof = [
        'admission_status' => 'candidate', 'artifacts' => $artifacts,
        'binding_sha256' => digest($artifacts['runtime_binding']),
        'candidate_definition_sha256' => digest($definition),
        'candidate_manifest_sha256' => digest($candidate),
        'canonical_publication_proof_schema_sha256' => gitBlobHash($sha, 'docs/reports/contracts/report-publication-proof.v1.schema.json'),
        'ci' => ['commit_sha' => $sha, 'required_checks' => requiredChecks(), 'run_id' => $runId.'.'.$attempt, 'suite_sha256' => manifestIdentity($artifacts)],
        'code' => R15_CODE, 'conformance_evidence_sha256' => digest($conformance),
        'contract_version' => '1.0.0', 'definition' => $definition,
        'schema_version' => '1.0.0',
    ];
    $request = [
        'request_id' => 'r15_release_request',
        'artifact_paths' => ['candidate_manifest' => 'r15-candidate-manifest.json', 'conformance_evidence' => 'r15-conformance-evidence.json', 'proof_template' => 'r15-proof-template.json'],
        'code' => R15_CODE, 'commit_sha' => $sha, 'proof_sha256' => digest($proof), 'schema_version' => '1.0.0',
    ];
    $documents = ['r15-candidate-manifest.json' => $candidate, 'r15-conformance-evidence.json' => $conformance, 'r15-proof-template.json' => $proof, 'r15_release_request.json' => $request];
    validateDocuments($root, $documents, $sha);
    writeDocuments($directory, $documents);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
}

/** @return array{string,string,string} */
function githubActionsContext(string $root): array
{
    $sha = getenv('GITHUB_SHA');
    $runId = getenv('GITHUB_RUN_ID');
    $attempt = getenv('GITHUB_RUN_ATTEMPT');
    if (getenv('GITHUB_ACTIONS') !== 'true' || ! is_string($sha) || ! is_string($runId) || ! is_string($attempt)
        || preg_match('/^[a-f0-9]{40}$/D', $sha) !== 1 || preg_match('/^[1-9][0-9]*$/D', $runId) !== 1 || preg_match('/^[1-9][0-9]*$/D', $attempt) !== 1
        || ! hash_equals(trim(command('git -C '.escapeshellarg($root).' rev-parse HEAD')), $sha)) {
        throw new RuntimeException('r15_candidate_evidence_input_invalid');
    }

    return [$sha, $runId, $attempt];
}

function authenticateGithubOidc(string $sha, string $runId): void
{
    $url = getenv('ACTIONS_ID_TOKEN_REQUEST_URL');
    $token = getenv('ACTIONS_ID_TOKEN_REQUEST_TOKEN');
    if (! is_string($url) || ! is_string($token)) {
        throw new RuntimeException('r15_candidate_evidence_oidc_untrusted');
    }
    (new GitHubActionsOidcProvenanceVerifier(new StreamR15HttpClient, new SystemR15Clock, REPOSITORY, REF, WORKFLOW_NAME, WORKFLOW_REF))
        ->verify($url, $token, $sha, $runId);
}

/** @return array<string, list<array{path:string,sha256:string}>|array{class:string,sha256:string}> */
function artifactGroups(string $root, string $sha): array
{
    $manifest = [
        'source_state' => [
            'app/BusinessModules/Features/Procurement/Reporting/Cycle/Contracts/ProcurementCycleSourceReader.php', 'app/BusinessModules/Features/Procurement/Reporting/Cycle/Contracts/ProcurementCycleSourceSnapshotWriter.php', 'app/BusinessModules/Features/Procurement/Reporting/Cycle/Contracts/ProcurementCycleSourceState.php',
            'app/BusinessModules/Features/Procurement/Reporting/Cycle/Services/EloquentProcurementCycleSourceReader.php', 'app/BusinessModules/Features/Procurement/Reporting/Cycle/Services/EloquentProcurementCycleSourceState.php', 'app/BusinessModules/Features/Procurement/Reporting/Cycle/Services/CanonicalProcurementCycleSourceSnapshotWriter.php', 'app/BusinessModules/Features/Procurement/Reporting/Cycle/Services/ProcurementCycleSourceSnapshotMaterializer.php',
            'app/BusinessModules/Features/Procurement/migrations/2026_08_01_000001_create_procurement_cycle_source.php',
        ],
        'events_and_policy' => ['app/BusinessModules/Features/Procurement/Reporting/Cycle/DTO/ProcurementCycleEvent.php', 'app/BusinessModules/Features/Procurement/Reporting/Cycle/DTO/ProcurementCyclePolicyDefinition.php', 'app/BusinessModules/Features/Procurement/Reporting/Cycle/DTO/ProcurementCyclePolicySnapshot.php', 'app/BusinessModules/Features/Procurement/Reporting/Cycle/DTO/ProcurementProcessDimensionSnapshot.php', 'app/BusinessModules/Features/Procurement/Reporting/Cycle/DTO/ProcurementProcessTransition.php', 'app/BusinessModules/Features/Procurement/Reporting/Cycle/Enums/ProcurementProcessEventCode.php', 'app/BusinessModules/Features/Procurement/Reporting/Cycle/Enums/ProcurementTerminalReason.php', 'app/BusinessModules/Features/Procurement/Reporting/Cycle/Services/ProcurementCycleOwnerEventRecorder.php', 'app/BusinessModules/Features/Procurement/Reporting/Cycle/Services/ProcurementCyclePolicyPublisher.php', 'app/BusinessModules/Features/Procurement/Reporting/Cycle/Services/EloquentProcurementProcessEventStore.php'],
        'formula_and_calendar' => ['app/BusinessModules/Features/Procurement/Reporting/Cycle/Services/ProcurementBusinessCalendar.php', 'app/BusinessModules/Features/Procurement/Reporting/Cycle/Services/ProcurementCycleFormula.php', 'app/BusinessModules/Features/Procurement/Reporting/Cycle/DTO/ProcurementCycleLineResult.php', 'app/BusinessModules/Features/Procurement/Reporting/Cycle/DTO/ProcurementCycleMetric.php', 'app/BusinessModules/Features/Procurement/Reporting/Cycle/Enums/ProcurementCycleStage.php'],
        'readiness_and_binding' => ['app/BusinessModules/Features/Procurement/Reporting/Cycle/Services/ProcurementCycleReadinessProbe.php', 'app/BusinessModules/Features/Procurement/Reporting/Cycle/Services/ProcurementCycleReportAdapter.php', 'app/BusinessModules/Features/Procurement/Reporting/Cycle/Services/ProcurementCycleReportBindingFactory.php'],
        'core_reporting_delivery_and_drill' => array_merge(gitTreePaths($root, $sha, 'app/BusinessModules/Core/Reporting'), ['composer.json', 'composer.lock']),
        'cycle_runtime' => gitTreePaths($root, $sha, 'app/BusinessModules/Features/Procurement/Reporting/Cycle'),
        'rbac_and_translation' => array_merge(['lang/ru/permissions.php', 'lang/ru/reports.php'], roleDefinitionPaths($root, $sha)),
            'ci_contract' => ['.github/workflows/notification-concurrency.yml', 'scripts/reporting/build-r15-publication-candidate.php', 'scripts/reporting/generate-r15-conformance-evidence.php', 'tests/Support/Reporting/ReportDefinitionBuilder.php', 'tests/Support/Reporting/ReportExecutionContextBuilder.php', 'tests/Support/Reporting/R15CiRuntimeFixtureFactory.php', 'docs/reports/contracts/r15-candidate-manifest.v1.schema.json', 'docs/reports/contracts/r15-candidate-conformance.v1.schema.json', 'docs/reports/contracts/r15-candidate-proof-template.v1.schema.json', 'docs/reports/contracts/r15-publication-request.v1.schema.json'],
    ];
    assertNoUntrackedInputs($root, ['app/BusinessModules/Features/Procurement/Reporting/Cycle', 'app/BusinessModules/Features/Procurement/migrations/2026_08_01_000001_create_procurement_cycle_source.php', 'app/BusinessModules/Core/Reporting', 'config/RoleDefinitions', 'lang/ru/permissions.php', 'lang/ru/reports.php', 'docs/reports/contracts', '.github/workflows/notification-concurrency.yml', 'scripts/reporting/build-r15-publication-candidate.php', 'composer.json', 'composer.lock']);
    $result = [];
    foreach ($manifest as $name => $paths) {
        $result[$name] = hashesAtCommit($sha, $paths);
    }
    $result['runtime_binding'] = ['class' => 'App\\BusinessModules\\Features\\Procurement\\Reporting\\Cycle\\Services\\ProcurementCycleReportBindingFactory', 'sha256' => gitBlobHash($sha, 'app/BusinessModules/Features/Procurement/Reporting/Cycle/Services/ProcurementCycleReportBindingFactory.php')];
    ksort($result, SORT_STRING);

    return $result;
}

/** @param array<string,mixed> $artifacts @return array<string,mixed> */
function generateConformanceEvidence(string $root, string $sha, string $timestamp, array $artifacts): array
{
    $artifactPath = getenv('MOST_R15_CI_EVIDENCE_ARTIFACT');
    if (is_string($artifactPath) && $artifactPath !== '') {
        $output = is_link($artifactPath) || ! is_file($artifactPath) ? false : file_get_contents($artifactPath);
    } else {
        $output = command('php '.escapeshellarg($root.'/scripts/reporting/generate-r15-conformance-evidence.php'));
    }
    try {
        if (! is_string($output)) {
            throw new JsonException('r15_ci_evidence_artifact_missing');
        }
        $artifact = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new RuntimeException('r15_ci_evidence_artifact_invalid');
    }
    if (! is_array($artifact) || array_is_list($artifact)
        || ($artifact['artifact_id'] ?? null) !== 'r15_runtime_conformance'
        || ($artifact['code'] ?? null) !== R15_CODE
        || ($artifact['schema_version'] ?? null) !== '1.0.0'
        || ($artifact['status'] ?? null) !== 'passed'
        || ! is_array($artifact['conformance'] ?? null)
        || ! is_string($artifact['conformance_digest'] ?? null)
        || preg_match('/^[a-f0-9]{64}$/D', $artifact['conformance_digest']) !== 1
        || ! hash_equals($artifact['conformance_digest'], digest((array) $artifact['conformance']))) {
        throw new RuntimeException('r15_ci_evidence_artifact_invalid');
    }
    validatePassedConformance($artifact['conformance'], $sha);
    $artifact['artifacts'] = $artifacts;
    $artifact['commit_sha'] = $sha;
    $artifact['generated_at'] = $timestamp;
    $artifact['verification_status'] = 'passed';

    return $artifact;
}

/** @param array<string,mixed> $conformance */
function validatePassedConformance(array $conformance, string $sha): void
{
    $source = $conformance['source'] ?? null;
    $formula = $conformance['formula'] ?? null;
    $assertions = array_merge(
        is_array($source) && is_array($source['assertion_codes'] ?? null) ? $source['assertion_codes'] : [],
        is_array($formula) && is_array($formula['assertion_codes'] ?? null) ? $formula['assertion_codes'] : [],
    );
    if (($conformance['code'] ?? null) !== R15_CODE
        || ($conformance['commit_sha'] ?? null) !== $sha
        || ($conformance['status'] ?? null) !== 'passed'
        || ! is_int($conformance['assertion_count'] ?? null)
        || $conformance['assertion_count'] < 1
        || $conformance['assertion_count'] !== count($assertions)
        || ! is_array($source)
        || ($source['passed'] ?? null) !== true
        || ! is_array($formula)
        || ($formula['passed'] ?? null) !== true
        || count($assertions) !== count(array_filter($assertions, static fn (mixed $code): bool => is_string($code) && str_ends_with($code, '.passed')))
        || ! is_array($conformance['component_class_hashes'] ?? null)
        || ($conformance['component_class_hashes'] ?? []) === []) {
        throw new RuntimeException('r15_ci_evidence_artifact_invalid');
    }
}

/** @return list<string> */
function roleDefinitionPaths(string $root, string $sha): array
{
    return gitTreePaths($root, $sha, 'config/RoleDefinitions');
}

/** @return list<string> */
function gitTreePaths(string $root, string $sha, string $directory): array
{
    $files = command('git -C '.escapeshellarg($root).' ls-tree -r --name-only '.escapeshellarg($sha).' -- '.escapeshellarg($directory));
    $paths = array_values(array_filter(array_map('trim', explode("\n", $files))));
    sort($paths, SORT_STRING);

    if ($paths === []) {
        throw new RuntimeException('r15_candidate_evidence_artifact_invalid');
    }

    return $paths;
}

/** @param list<string> $paths */
function assertNoUntrackedInputs(string $root, array $paths): void
{
    $status = command('git -C '.escapeshellarg($root).' status --porcelain --untracked-files=all -- '.implode(' ', array_map('escapeshellarg', $paths)));
    if (trim($status) !== '') {
        throw new RuntimeException('r15_candidate_evidence_input_untracked');
    }
}

/** @param list<string> $paths @return list<array{path:string,sha256:string}> */
function hashesAtCommit(string $sha, array $paths): array
{
    $paths = array_values(array_unique($paths));
    sort($paths, SORT_STRING);

    return array_map(static fn (string $path): array => ['path' => $path, 'sha256' => gitBlobHash($sha, $path)], $paths);
}

function gitBlobHash(string $sha, string $path): string
{
    if (preg_match('#^(?!/)(?!.*(?:^|/)\.\.(?:/|$))[A-Za-z0-9_.\-/]+$#D', $path) !== 1) {
        throw new RuntimeException('r15_candidate_evidence_artifact_invalid');
    }
    command('git cat-file -e '.escapeshellarg($sha.':'.$path));
    $bytes = command('git show --no-textconv '.escapeshellarg($sha.':'.$path));
    if ($bytes === '') {
        throw new RuntimeException('r15_candidate_evidence_artifact_invalid');
    }

    return hash('sha256', $bytes);
}

function commitTimestamp(string $sha): string
{
    $timestamp = trim(command('git show -s --format=%cI '.escapeshellarg($sha)));
    $date = new DateTimeImmutable($timestamp);

    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.000000\\Z');
}

/** @param array<string,mixed> $document */
function digest(array $document): string
{
    return hash('sha256', CanonicalJson::encode($document));
}
/** @param array<string,mixed> $artifacts */
function manifestIdentity(array $artifacts): string
{
    return R15EvidenceIdentity::fromArtifacts($artifacts);
}

/** @return array<string,mixed> */
function candidateDefinition(array $artifacts): array
{
    $columns = ['row_key', 'cohort_date', 'purchase_request_line_id', 'request_number', 'material_name', 'requester_id', 'buyer_id', 'priority', 'current_stage', 'outcome', 'total_cycle_seconds', 'open_age_seconds', 'awarded_supplier_party_id', 'awarded_amount', 'currency', 'quality_status', 'gap_codes', 'stage_breakdown', 'audit_timeline'];

    return [
        'capabilities' => ['reproducible_scheduled_snapshot' => false, 'supports_subscriptions' => false],
        'code' => R15_CODE,
        'columns' => array_map(static fn (string $id): array => ['id' => $id], $columns),
        'filters' => [['id' => 'project_ids'], ['id' => 'as_of']],
        'formats' => ['csv', 'pdf', 'xlsx'],
        'grain' => 'request_line_process',
        'permissions' => ['audit' => ['procurement.audit.view'], 'export' => ['procurement.reports.export'], 'sensitive' => [], 'view' => ['procurement.dashboard.view']],
        'readiness' => ['delivery' => 'verified', 'formula' => 'verified', 'publication' => 'candidate', 'source' => 'verified'],
        'runtime' => ['binding' => $artifacts['runtime_binding'],
            'delivery_contract_sha256' => ReportPublicationAdmissionRequirements::contractHashesByCode()[R15_CODE]['delivery_contract_sha256'],
            'drill_contract_sha256' => ReportPublicationAdmissionRequirements::contractHashesByCode()[R15_CODE]['drill_contract_sha256'],
        ],
        'semantic_fingerprints' => ['formula' => digest($artifacts['formula_and_calendar']), 'source' => digest($artifacts['source_state'])],
        'sorts' => [['direction' => 'asc', 'id' => 'cohort_date']],
        'versions' => ['contract' => '1.0.0', 'formula' => '1.0.0', 'renderer' => '1.0.0', 'source_schema' => '1.0.0'],
    ];
}

/** @return list<string> */
function requiredChecks(): array
{
    return ['binding_contract', 'drill_down_contract', 'export_csv_contract', 'export_pdf_contract', 'export_xlsx_contract', 'formula_contract', 'postgresql_contract', 'rbac_contract', 'source_contract'];
}

/** @param array<string,array<string,mixed>> $documents */
function validateDocuments(string $root, array $documents, string $sha): void
{
    $schemas = ['r15-candidate-manifest.json' => 'docs/reports/contracts/r15-candidate-manifest.v1.schema.json', 'r15-conformance-evidence.json' => 'docs/reports/contracts/r15-candidate-conformance.v1.schema.json', 'r15-proof-template.json' => 'docs/reports/contracts/r15-candidate-proof-template.v1.schema.json', 'r15_release_request.json' => 'docs/reports/contracts/r15-publication-request.v1.schema.json'];
    $validator = new CompliantValidator;
    foreach ($schemas as $document => $schema) {
        if (! $validator->validate(json_decode(CanonicalJson::encode($documents[$document]), false, 512, JSON_THROW_ON_ERROR), json_decode((string) file_get_contents($root.'/'.$schema), false, 512, JSON_THROW_ON_ERROR))->isValid()) {
            throw new RuntimeException('r15_candidate_evidence_schema_invalid');
        }
    }
    $conformance = $documents['r15-conformance-evidence.json'];
    if (($documents['r15-candidate-manifest.json']['generated_from_commit'] ?? null) !== $sha || ($conformance['commit_sha'] ?? null) !== $sha || ($conformance['verification_status'] ?? null) !== 'passed' || ($conformance['status'] ?? null) !== 'passed' || ($conformance['conformance']['commit_sha'] ?? null) !== $sha || ($conformance['conformance']['status'] ?? null) !== 'passed' || ($documents['r15-proof-template.json']['ci']['commit_sha'] ?? null) !== $sha || ($documents['r15_release_request.json']['commit_sha'] ?? null) !== $sha || ! hash_equals(digest((array) ($documents['r15-candidate-manifest.json']['candidate_definition'] ?? null)), (string) ($documents['r15-candidate-manifest.json']['candidate_definition_sha256'] ?? '')) || ! hash_equals((string) ($documents['r15-candidate-manifest.json']['candidate_definition_sha256'] ?? ''), (string) ($documents['r15-proof-template.json']['candidate_definition_sha256'] ?? '')) || ($documents['r15-proof-template.json']['definition'] ?? null) !== ($documents['r15-candidate-manifest.json']['candidate_definition'] ?? null) || ($documents['r15-proof-template.json']['ci']['required_checks'] ?? null) !== requiredChecks() || ! hash_equals(digest($documents['r15-candidate-manifest.json']), (string) ($documents['r15-proof-template.json']['candidate_manifest_sha256'] ?? '')) || ! hash_equals(digest($conformance), (string) ($documents['r15-proof-template.json']['conformance_evidence_sha256'] ?? '')) || ! hash_equals(digest($documents['r15-proof-template.json']), (string) ($documents['r15_release_request.json']['proof_sha256'] ?? ''))) {
        throw new RuntimeException('r15_candidate_evidence_links_invalid');
    }
}

function validateExistingDocuments(string $root, string $directory, string $sha): void
{
    $documents = [];
    foreach (['r15-candidate-manifest.json', 'r15-conformance-evidence.json', 'r15-proof-template.json', 'r15_release_request.json'] as $name) {
        $bytes = is_file($directory.DIRECTORY_SEPARATOR.$name) ? file_get_contents($directory.DIRECTORY_SEPARATOR.$name) : false;
        if (! is_string($bytes)) {
            throw new RuntimeException('r15_candidate_evidence_not_current');
        }
        $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || ! hash_equals(CanonicalJson::encode($decoded), $bytes)) {
            throw new RuntimeException('r15_candidate_evidence_not_current');
        }
        $documents[$name] = $decoded;
    }
    validateDocuments($root, $documents, $sha);
}

/** @param array<string,array<string,mixed>> $documents */
function writeDocuments(string $directory, array $documents): void
{
    if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
        throw new RuntimeException('r15_candidate_evidence_output_invalid');
    }
    foreach ($documents as $name => $document) {
        if (file_put_contents($directory.DIRECTORY_SEPARATOR.$name, CanonicalJson::encode($document)) === false) {
            throw new RuntimeException('r15_candidate_evidence_output_invalid');
        }
    }
}

function command(string $command): string
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('r15_candidate_evidence_git_unavailable');
    }
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0 || ! is_string($output)) {
        throw new RuntimeException('r15_candidate_evidence_git_unavailable');
    }

    return $output;
}
