<?php

declare(strict_types=1);

use App\BusinessModules\Core\Reporting\Application\Quality\ReportPlatformGateCatalog;
use App\BusinessModules\Core\Reporting\Application\Quality\ReportQualityGateException;
use App\BusinessModules\Core\Reporting\Application\Quality\ReportReleaseEvidenceBuilder;
use App\BusinessModules\Core\Reporting\Domain\DTO\LoadedReportManifest;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQualityGateEvidence;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityEvidencePhase;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityEvidenceStatus;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use Symfony\Component\Yaml\Yaml;

require dirname(__DIR__, 2).'/vendor/autoload.php';

const QUALITY_ROOT = __DIR__.'/../..';

try {
    $options = getopt('', ['phase:', 'manifest:', 'official::', 'ledger::', 'gates:', 'plan-1a::', 'plan-1b::', 'release-gates::', 'release-sha:', 'generated-at:', 'output:', 'check']);
    foreach (['phase', 'manifest', 'gates', 'release-sha', 'generated-at', 'output'] as $option) {
        if (!isset($options[$option]) || !is_string($options[$option])) {
            throw new ReportQualityGateException(\App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityGateFailureCode::INVALID);
        }
    }
    $phase = $options['phase'];
    if (!in_array($phase, ['platform', 'release'], true)) {
        throw new ReportQualityGateException(\App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityGateFailureCode::INVALID);
    }
    $expectedGates = $phase === 'platform'
        ? 'tests/Fixtures/Reporting/Quality/platform-gates.valid.json'
        : 'build/reports/report-release-gate-bundle.json';
    assertExactPath($options['gates'], $expectedGates);
    $generatedAt = canonicalTime($options['generated-at']);
    $releaseSha = $options['release-sha'];
    $catalog = new ReportPlatformGateCatalog(QUALITY_ROOT.'/docs/reports/contracts/report-platform-gates.v1.json');
    $records = $catalog->records();
    $gateDocument = decodeCanonical(QUALITY_ROOT.'/'.$expectedGates);
    $gates = parseGates($gateDocument, $records, $catalog->hash(), $releaseSha, $generatedAt, $phase);
    $builder = new ReportReleaseEvidenceBuilder();
    if ($phase === 'platform') {
        foreach (['official', 'plan-1a', 'plan-1b'] as $option) {
            if (!isset($options[$option]) || !is_string($options[$option])) {
                throw new ReportQualityGateException(\App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityGateFailureCode::MISSING);
            }
        }
        $ledger = $builder->buildPlatform(loadManifest($options['manifest'], 'management-catalog.v1'), loadManifest($options['official'], 'official-document-catalog.v1'), $gates, [hash('sha256', read($options['plan-1a'])), hash('sha256', read($options['plan-1b']))], $releaseSha, $generatedAt);
    } else {
        throw new ReportQualityGateException(\App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityGateFailureCode::PHASE_INCOMPLETE);
    }
    $document = ['artifact_id' => 'report_quality_evidence', 'schema_version' => '1.0.0', 'status' => $ledger->status, 'catalog' => ['path' => 'docs/reports/contracts/report-platform-gates.v1.json', 'sha256' => $catalog->hash()], 'release_sha' => $releaseSha, 'generated_at' => $generatedAt->format('Y-m-d\\TH:i:s\\Z'), 'gates' => serializeGates($gates, $records)];
    $bytes = CanonicalJson::encode($document)."\n";
    publish($options['output'], $bytes, isset($options['check']));
    fwrite(STDOUT, 'report-quality-evidence: '.$ledger->status.PHP_EOL);
} catch (ReportQualityGateException $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit($exception->exitCode());
} catch (Throwable) {
    fwrite(STDERR, "quality-gate:invalid\n");
    exit(2);
}

function assertExactPath(string $path, string $expected): void { if (realpath($path) !== realpath(QUALITY_ROOT.'/'.$expected)) { throw new ReportQualityGateException(\App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityGateFailureCode::INVALID); } }
function read(string $path): string { $bytes = @file_get_contents($path); if (!is_string($bytes)) { throw new ReportQualityGateException(\App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityGateFailureCode::MISSING); } return $bytes; }
function canonicalTime(string $value): DateTimeImmutable { $time = new DateTimeImmutable($value); if ($time->format('Y-m-d\\TH:i:s\\Z') !== $value) { throw new ReportQualityGateException(\App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityGateFailureCode::INVALID); } return $time; }
function decodeCanonical(string $path): array { $bytes = read($path); $data = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR); if (!is_array($data) || CanonicalJson::encode($data)."\n" !== $bytes) { throw new ReportQualityGateException(\App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityGateFailureCode::INVALID); } return $data; }
function loadManifest(string $path, string $catalog): LoadedReportManifest { $bytes = read($path); $data = Yaml::parse($bytes); if (!is_array($data) || ($data['catalog'] ?? null) !== $catalog || !is_array($data['definitions'] ?? null)) { throw new ReportQualityGateException(\App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityGateFailureCode::INVALID); } return new LoadedReportManifest($catalog, (string) ($data['contract_version'] ?? ''), new Sha256Hash(hash('sha256', $bytes)), $data['definitions']); }
function parseGates(array $document, array $records, string $catalogHash, string $releaseSha, DateTimeImmutable $time, string $phase): array { if (array_keys($document) !== ['artifact_id','catalog','gates','generated_at','release_sha','schema_version','status'] || ($document['artifact_id'] ?? null) !== 'report_platform_gate_inputs' || ($document['schema_version'] ?? null) !== '1.0.0' || ($document['status'] ?? null) !== 'platform_gate_inputs_passed' || ($document['catalog']['sha256'] ?? null) !== $catalogHash || ($document['release_sha'] ?? null) !== $releaseSha || ($document['generated_at'] ?? null) !== $time->format('Y-m-d\\TH:i:s\\Z') || !is_array($document['gates'] ?? null) || count($document['gates']) !== 14) { throw new ReportQualityGateException(\App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityGateFailureCode::INVALID); } $result=[]; foreach ($records as $index=>$record) { $gate=$document['gates'][$index] ?? null; if (!is_array($gate) || ($gate['gate']??null)!==$record['id'] || ($gate['owner_plan']??null)!==$record['release_owner'] || ($gate['phase']??null)!==$phase || ($gate['status']??null)!==$record['platform_status'] || ($gate['command']??null)!==$record['command'] || ($gate['count']??null)!==$record['minimum_count'] || ($gate['schema_sha256']??null)!==$record['schema_sha256'] || ($gate['release_sha']??null)!==$releaseSha || ($gate['commit_sha']??null)!==$releaseSha || ($gate['executed_at']??null)!==$time->format('Y-m-d\\TH:i:s\\Z')) { throw new ReportQualityGateException(\App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityGateFailureCode::INVALID); } $sources=[]; foreach ($record['source_paths'] as $source) { $sources[]=['path'=>$source,'sha256'=>hash('sha256',read(QUALITY_ROOT.'/'.$source))]; } if (($gate['source_artifacts']??null)!==$sources) { throw new ReportQualityGateException(\App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityGateFailureCode::INVALID); } $artifact=$gate['artifact_sha256']??null; if (($record['platform_status']==='passed') !== is_string($artifact)) { throw new ReportQualityGateException(\App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityGateFailureCode::INVALID); } $result[]=new ReportQualityGateEvidence($record['id'],$record['release_owner'],ReportQualityEvidencePhase::from($phase),ReportQualityEvidenceStatus::from($record['platform_status']),$record['command'],$record['minimum_count'],new Sha256Hash($record['schema_sha256']),$releaseSha,$releaseSha,$time,is_string($artifact)?new Sha256Hash($artifact):null); } return $result; }
function serializeGates(array $gates, array $records): array { return array_map(static fn (ReportQualityGateEvidence $g, array $record): array => ['gate'=>$g->gate,'owner_plan'=>$g->ownerPlan,'phase'=>$g->phase->value,'status'=>$g->status->value,'command'=>$g->command,'count'=>$g->count,'schema_sha256'=>$g->schemaHash->value,'release_sha'=>$g->releaseSha,'commit_sha'=>$g->commitSha,'executed_at'=>$g->executedAt->format('Y-m-d\\TH:i:s\\Z'),'artifact_sha256'=>$g->artifactHash?->value,'source_artifacts'=>array_map(static fn (string $path): array => ['path'=>$path,'sha256'=>hash('sha256',read(QUALITY_ROOT.'/'.$path))],$record['source_paths'])], $gates, $records); }
function publish(string $output, string $bytes, bool $check): void { if ($check) { if (@file_get_contents($output) !== $bytes) { throw new ReportQualityGateException(\App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityGateFailureCode::INVALID); } return; } $directory=dirname($output); if (!is_dir($directory)) { mkdir($directory,0777,true); } file_put_contents($output,$bytes); }
