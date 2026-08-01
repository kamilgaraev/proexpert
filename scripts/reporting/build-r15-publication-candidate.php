<?php

declare(strict_types=1);

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use Opis\JsonSchema\CompliantValidator;

require dirname(__DIR__, 2).'/vendor/autoload.php';

const R15_CODE = 'procurement_cycle';

$options = getopt('', ['check']);

try {
    $releaseSha = getenv('GITHUB_SHA');
    $runId = getenv('GITHUB_RUN_ID');
    $attempt = getenv('GITHUB_RUN_ATTEMPT');
    if (getenv('GITHUB_ACTIONS') !== 'true'
        || ! is_string($releaseSha)
        || ! is_string($runId)
        || ! is_string($attempt)
        || preg_match('/^[a-f0-9]{40}$/D', $releaseSha) !== 1
        || ! hash_equals(trim(command('git rev-parse HEAD')), $releaseSha)
        || preg_match('/^[0-9]+$/D', $runId) !== 1
        || preg_match('/^[1-9][0-9]*$/D', $attempt) !== 1) {
        throw new RuntimeException('r15_candidate_evidence_input_invalid');
    }
    authenticateGithubOidc($releaseSha, $runId);

    $root = dirname(__DIR__, 2);
    $outputDirectory = $root.'/build/reports/r15-candidate-evidence';
    if (isset($options['check'])) {
        validateExistingDocuments($root, $outputDirectory);
        exit(0);
    }
    $completedAt = gmdate('Y-m-d\\TH:i:s.000000\\Z');
    $artifacts = artifactGroups($root, $releaseSha);
    $candidate = [
        'admission_status' => 'candidate',
        'code' => R15_CODE,
        'contract_version' => '1.0.0',
        'formula_version' => 'procurement-cycle.v1',
        'generated_from_commit' => $releaseSha,
        'publication_status' => 'blocked',
        'runtime_binding' => $artifacts['runtime_binding'],
        'source_schema_version' => '1.0.0',
    ];
    $conformance = [
        'artifact_id' => 'r15_candidate_conformance',
        'artifacts' => $artifacts,
        'code' => R15_CODE,
        'commit_sha' => $releaseSha,
        'generated_at' => $completedAt,
        'schema_version' => '1.0.0',
        'verification_status' => 'ci_required',
    ];
    $proof = [
        'admission_status' => 'blocked',
        'artifacts' => $artifacts,
        'candidate_manifest_sha256' => hash('sha256', CanonicalJson::encode($candidate)),
        'ci' => [
            'commit_sha' => $releaseSha,
            'completed_at_utc' => $completedAt,
            'required_checks' => ['r15_formula_contract', 'r15_postgresql_contract', 'r15_runtime_contract'],
            'run_id' => $runId.'.'.$attempt,
        ],
        'code' => R15_CODE,
        'conformance_evidence_sha256' => hash('sha256', CanonicalJson::encode($conformance)),
        'schema_version' => '1.0.0',
    ];
    $request = [
        'admission_status' => 'blocked',
        'artifact_paths' => [
            'candidate_manifest' => 'r15-candidate-manifest.json',
            'conformance_evidence' => 'r15-conformance-evidence.json',
            'proof_template' => 'r15-proof-template.json',
        ],
        'code' => R15_CODE,
        'commit_sha' => $releaseSha,
        'request_kind' => 'r15_candidate_evidence',
        'schema_version' => '1.0.0',
    ];
    $documents = [
        'r15-candidate-manifest.json' => $candidate,
        'r15-conformance-evidence.json' => $conformance,
        'r15-proof-template.json' => $proof,
        'r15-release-request.json' => $request,
    ];
    validateDocuments($root, $documents);
    writeDocuments($outputDirectory, $documents, false);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
}

/** @return array<string, list<array{path:string,sha256:string}>|array{class:string,sha256:string}> */
function artifactGroups(string $root, string $sha): array
{
    $groups = [
        'source_runtime' => [
            'app/BusinessModules/Features/Procurement/Reporting/Cycle/Contracts/ProcurementCycleSourceReader.php',
            'app/BusinessModules/Features/Procurement/Reporting/Cycle/Contracts/ProcurementCycleSourceSnapshotWriter.php',
            'app/BusinessModules/Features/Procurement/Reporting/Cycle/Services/EloquentProcurementCycleSourceReader.php',
            'app/BusinessModules/Features/Procurement/Reporting/Cycle/Services/CanonicalProcurementCycleSourceSnapshotWriter.php',
            'app/BusinessModules/Features/Procurement/Reporting/Cycle/Services/ProcurementCycleSourceSnapshotMaterializer.php',
            'app/BusinessModules/Features/Procurement/migrations/2026_08_01_000001_create_procurement_cycle_source.php',
        ],
        'formula_runtime' => [
            'app/BusinessModules/Features/Procurement/Reporting/Cycle/Services/ProcurementCycleFormula.php',
            'app/BusinessModules/Features/Procurement/Reporting/Cycle/DTO/ProcurementCycleLineResult.php',
            'app/BusinessModules/Features/Procurement/Reporting/Cycle/DTO/ProcurementCycleMetric.php',
            'app/BusinessModules/Features/Procurement/Reporting/Cycle/Enums/ProcurementCycleStage.php',
        ],
        'drill_down_runtime' => [
            'app/BusinessModules/Features/Procurement/Reporting/Cycle/Services/ProcurementCycleReportAdapter.php',
            'app/BusinessModules/Features/Procurement/Reporting/Cycle/Services/ProcurementCycleReadinessProbe.php',
        ],
        'delivery_contract' => [
            'app/BusinessModules/Core/Reporting/Application/Exports/ReportExportRenderer.php',
            'app/BusinessModules/Core/Reporting/Infrastructure/Exports/CsvReportExportRenderer.php',
            'app/BusinessModules/Core/Reporting/Infrastructure/Exports/XlsxReportExportRenderer.php',
            'app/BusinessModules/Core/Reporting/Infrastructure/Exports/PdfReportExportRenderer.php',
            'docs/reports/contracts/report-publication-proof.v1.schema.json',
            'lang/ru/reports.php',
        ],
        'rbac' => array_merge(['lang/ru/permissions.php'], roleDefinitionPaths($root)),
    ];
    $result = [];
    foreach ($groups as $name => $paths) {
        $result[$name] = hashesAtCommit($sha, $paths);
    }
    $bindingPath = 'app/BusinessModules/Features/Procurement/Reporting/Cycle/Services/ProcurementCycleReportBindingFactory.php';
    $result['runtime_binding'] = [
        'class' => 'App\\BusinessModules\\Features\\Procurement\\Reporting\\Cycle\\Services\\ProcurementCycleReportBindingFactory',
        'sha256' => gitBlobHash($sha, $bindingPath),
    ];

    return $result;
}

/** @return list<string> */
function roleDefinitionPaths(string $root): array
{
    $paths = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/config/RoleDefinitions', FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'json') {
            $paths[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        }
    }
    sort($paths, SORT_STRING);

    return $paths;
}

/** @param list<string> $paths @return list<array{path:string,sha256:string}> */
function hashesAtCommit(string $sha, array $paths): array
{
    sort($paths, SORT_STRING);

    return array_map(static fn (string $path): array => ['path' => $path, 'sha256' => gitBlobHash($sha, $path)], $paths);
}

function gitBlobHash(string $sha, string $path): string
{
    if (preg_match('#^(?!/)(?!.*(?:^|/)\\.\\.(?:/|$))[A-Za-z0-9_.\\-/]+$#D', $path) !== 1) {
        throw new RuntimeException('r15_candidate_evidence_artifact_invalid');
    }
    command('git cat-file -e '.escapeshellarg($sha.':'.$path));
    $bytes = command('git show --no-textconv '.escapeshellarg($sha.':'.$path));
    if ($bytes === '') {
        throw new RuntimeException('r15_candidate_evidence_artifact_invalid');
    }

    return hash('sha256', $bytes);
}

/** @param array<string, array<string, mixed>> $documents */
function validateDocuments(string $root, array $documents): void
{
    $schemas = [
        'r15-candidate-manifest.json' => 'docs/reports/contracts/r15-candidate-manifest.v1.schema.json',
        'r15-conformance-evidence.json' => 'docs/reports/contracts/r15-candidate-conformance.v1.schema.json',
        'r15-proof-template.json' => 'docs/reports/contracts/r15-candidate-proof-template.v1.schema.json',
        'r15-release-request.json' => 'docs/reports/contracts/r15-publication-request.v1.schema.json',
    ];
    $validator = new CompliantValidator;
    foreach ($schemas as $document => $schema) {
        if (! $validator->validate(
            json_decode(CanonicalJson::encode($documents[$document]), false, 512, JSON_THROW_ON_ERROR),
            json_decode((string) file_get_contents($root.'/'.$schema), false, 512, JSON_THROW_ON_ERROR),
        )->isValid()) {
            throw new RuntimeException('r15_candidate_evidence_schema_invalid');
        }
    }
}

function validateExistingDocuments(string $root, string $directory): void
{
    $documents = [];
    foreach (['r15-candidate-manifest.json', 'r15-conformance-evidence.json', 'r15-proof-template.json', 'r15-release-request.json'] as $name) {
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
    validateDocuments($root, $documents);
}

/** @param array<string, array<string, mixed>> $documents */
function writeDocuments(string $directory, array $documents, bool $check): void
{
    if ($check) {
        foreach ($documents as $name => $document) {
            $path = $directory.DIRECTORY_SEPARATOR.$name;
            if (! is_file($path) || ! hash_equals(CanonicalJson::encode($document), (string) file_get_contents($path))) {
                throw new RuntimeException('r15_candidate_evidence_not_current');
            }
        }

        return;
    }
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

function isTimestamp(string $value): bool
{
    $timestamp = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s.u\\Z', $value, new DateTimeZone('UTC'));

    return $timestamp instanceof DateTimeImmutable && $timestamp->format('Y-m-d\\TH:i:s.u\\Z') === $value;
}

function authenticateGithubOidc(string $sha, string $runId): void
{
    $url = getenv('ACTIONS_ID_TOKEN_REQUEST_URL');
    $token = getenv('ACTIONS_ID_TOKEN_REQUEST_TOKEN');
    if (! is_string($url) || ! is_string($token) || $url === '' || $token === '') {
        throw new RuntimeException('r15_candidate_evidence_oidc_untrusted');
    }
    $separator = str_contains($url, '?') ? '&' : '?';
    $response = httpGet($url.$separator.'audience=most-r15-candidate-evidence', ['Authorization: Bearer '.$token]);
    $payload = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
    $jwt = is_array($payload) ? ($payload['value'] ?? null) : null;
    if (! is_string($jwt)) {
        throw new RuntimeException('r15_candidate_evidence_oidc_untrusted');
    }
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        throw new RuntimeException('r15_candidate_evidence_oidc_untrusted');
    }
    [$header, $claims, $signature] = array_map('base64UrlDecode', $parts);
    $header = json_decode($header, true, 16, JSON_THROW_ON_ERROR);
    $claims = json_decode($claims, true, 32, JSON_THROW_ON_ERROR);
    if (! is_array($header) || ! is_array($claims) || ($header['alg'] ?? null) !== 'RS256' || ! is_string($header['kid'] ?? null)) {
        throw new RuntimeException('r15_candidate_evidence_oidc_untrusted');
    }
    $jwks = json_decode(httpGet('https://token.actions.githubusercontent.com/.well-known/jwks'), true, 64, JSON_THROW_ON_ERROR);
    $key = null;
    foreach ($jwks['keys'] ?? [] as $item) { if (is_array($item) && ($item['kid'] ?? null) === $header['kid']) { $key = $item; break; } }
    if (! is_array($key) || openssl_verify($parts[0].'.'.$parts[1], $signature, jwkPem($key), OPENSSL_ALGO_SHA256) !== 1
        || ($claims['iss'] ?? null) !== 'https://token.actions.githubusercontent.com'
        || ($claims['aud'] ?? null) !== 'most-r15-candidate-evidence'
        || ($claims['repository'] ?? null) !== 'kamilgaraev/proexpert'
        || ($claims['ref'] ?? null) !== 'refs/heads/main'
        || ($claims['sha'] ?? null) !== $sha
        || (string) ($claims['run_id'] ?? '') !== $runId
        || ($claims['workflow_ref'] ?? null) !== 'kamilgaraev/proexpert/.github/workflows/notification-concurrency.yml@refs/heads/main'
        || ! is_int($claims['exp'] ?? null) || $claims['exp'] < time()) {
        throw new RuntimeException('r15_candidate_evidence_oidc_untrusted');
    }
}

function httpGet(string $url, array $headers = []): string { $context = stream_context_create(['http' => ['header' => implode("\r\n", $headers), 'timeout' => 10, 'ignore_errors' => false]]); $body = @file_get_contents($url, false, $context); if (! is_string($body)) { throw new RuntimeException('r15_candidate_evidence_oidc_untrusted'); } return $body; }
function base64UrlDecode(string $value): string { $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true); if (! is_string($decoded)) { throw new RuntimeException('r15_candidate_evidence_oidc_untrusted'); } return $decoded; }
function der(string $tag, string $value): string { $length = strlen($value); return $tag.($length < 128 ? chr($length) : chr(0x81).chr($length)).$value; }
function jwkPem(array $key): string { if (($key['kty'] ?? null) !== 'RSA' || ! is_string($key['n'] ?? null) || ! is_string($key['e'] ?? null)) { throw new RuntimeException('r15_candidate_evidence_oidc_untrusted'); } $integer = static fn (string $value): string => der("\x02", (ord($value[0]) > 127 ? "\0" : '').$value); $rsa = der("\x30", $integer(base64UrlDecode($key['n'])).$integer(base64UrlDecode($key['e']))); $spki = der("\x30", "\x30\r\x06\t*\x86H\x86\xf7\r\x01\x01\x01\x05\0".der("\x03", "\0".$rsa)); return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n"; }
