<?php

declare(strict_types=1);

use App\BusinessModules\Core\Reporting\Application\Quality\ReportPlatformGateCatalog;
use App\BusinessModules\Core\Reporting\Application\Quality\ReportQualityGateException;
use App\BusinessModules\Core\Reporting\Application\Quality\ReportReleaseEvidenceBuilder;
use App\BusinessModules\Core\Reporting\Domain\DTO\LoadedReportManifest;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogActivation;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQualityGateEvidence;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityEvidencePhase;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityEvidenceStatus;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Opis\JsonSchema\CompliantValidator;

require dirname(__DIR__, 2).'/vendor/autoload.php';

const QUALITY_ROOT = __DIR__.'/../..';

try {
    $options = getopt('', ['phase:', 'manifest:', 'official::', 'ledger::', 'gates:', 'plan-1a::', 'plan-1b::', 'plan-1c::', 'activation-inputs::', 'activation::', 'activation-commit::', 'release-sha:', 'generated-at:', 'output:', 'check']);
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
    $catalogBytes = gitBlob($releaseSha, 'docs/reports/contracts/report-platform-gates.v1.json');
    $catalog = ReportPlatformGateCatalog::fromBytes($catalogBytes);
    $records = $catalog->records();
    $catalogHash = hash('sha256', $catalogBytes);
    $builder = new ReportReleaseEvidenceBuilder();
    if ($phase === 'platform') {
        $gateDocument = decodeCanonical(QUALITY_ROOT.'/'.$expectedGates);
        $gates = parseGates($gateDocument, $records, $catalogHash, $releaseSha, $generatedAt, $phase);
        foreach (['official', 'plan-1a', 'plan-1b'] as $option) {
            if (!isset($options[$option]) || !is_string($options[$option])) {
                throw new ReportQualityGateException(\App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityGateFailureCode::MISSING);
            }
        }
        $ledger = $builder->buildPlatform(loadManifest($options['manifest'], 'management-catalog.v1'), loadManifest($options['official'], 'official-document-catalog.v1'), $gates, [hash('sha256', read($options['plan-1a'])), hash('sha256', read($options['plan-1b']))], $releaseSha, $generatedAt);
        $serializedGates = serializeGates($gates, $records);
    } else {
        foreach (['ledger', 'activation-inputs', 'activation', 'activation-commit', 'plan-1a', 'plan-1b', 'plan-1c'] as $option) {
            if (!isset($options[$option]) || !is_string($options[$option])) {
                throw new ReportQualityGateException(\App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityGateFailureCode::MISSING);
            }
        }
        $gateDocument = decodeCanonical(QUALITY_ROOT.'/'.$expectedGates);
        $gates = parseReleaseGates($gateDocument, $records, $releaseSha, $generatedAt, $options['activation-commit']);
        $activation = loadActivation($options['activation'], $releaseSha);
        $prerequisites = [
            hash('sha256', read(QUALITY_ROOT.'/'.$expectedGates)),
            hash('sha256', read($options['activation-inputs'])),
            hash('sha256', read($options['activation'])),
            hash('sha256', read($options['plan-1a'])),
            hash('sha256', read($options['plan-1b'])),
            hash('sha256', read($options['plan-1c'])),
        ];
        $ledger = $builder->buildReleaseFromActivation($activation, $gates, $prerequisites, $releaseSha, $generatedAt);
        $serializedGates = serializeReleaseGates($gates);
    }
    $document = ['artifact_id' => 'report_quality_evidence', 'schema_version' => '1.0.0', 'status' => $ledger->status, 'catalog' => ['path' => 'docs/reports/contracts/report-platform-gates.v1.json', 'sha256' => $catalogHash], 'release_sha' => $releaseSha, 'generated_at' => $generatedAt->format('Y-m-d\\TH:i:s\\Z'), 'gates' => $serializedGates];
    $bytes = CanonicalJson::encode($document)."\n";
    $schema = json_decode(read(QUALITY_ROOT.'/docs/reports/contracts/report-quality-evidence.schema.json'), false, 512, JSON_THROW_ON_ERROR);
    if (!(new CompliantValidator())->validate(json_decode($bytes, false, 512, JSON_THROW_ON_ERROR), $schema)->isValid()) {
        throw new ReportQualityGateException(\App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityGateFailureCode::INVALID);
    }
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
function parseGates(array $document, array $records, string $catalogHash, string $releaseSha, DateTimeImmutable $time, string $phase): array { if (array_keys($document) !== ['artifact_id','catalog','gates','generated_at','release_sha','schema_version','status'] || ($document['artifact_id'] ?? null) !== 'report_platform_gate_inputs' || ($document['schema_version'] ?? null) !== '1.0.0' || ($document['status'] ?? null) !== 'platform_gate_inputs_passed' || ($document['catalog']['sha256'] ?? null) !== $catalogHash || ($document['release_sha'] ?? null) !== $releaseSha || ($document['generated_at'] ?? null) !== $time->format('Y-m-d\\TH:i:s\\Z') || !is_array($document['gates'] ?? null) || count($document['gates']) !== 14) { throw new ReportQualityGateException(\App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityGateFailureCode::INVALID); } $result=[]; foreach ($records as $index=>$record) { $gate=$document['gates'][$index] ?? null; if (!is_array($gate) || ($gate['gate']??null)!==$record['id'] || ($gate['owner_plan']??null)!==$record['release_owner'] || ($gate['phase']??null)!==$phase || ($gate['status']??null)!==$record['platform_status'] || ($gate['command']??null)!==$record['command'] || ($gate['count']??null)!==$record['minimum_count'] || ($gate['schema_sha256']??null)!==$record['schema_sha256'] || ($gate['release_sha']??null)!==$releaseSha || ($gate['commit_sha']??null)!==$releaseSha || ($gate['executed_at']??null)!==$time->format('Y-m-d\\TH:i:s\\Z')) { throw new ReportQualityGateException(\App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityGateFailureCode::INVALID); } $sources=[]; foreach ($record['source_paths'] as $source) { $sources[]=['path'=>$source,'sha256'=>hash('sha256',gitBlob($releaseSha, $source))]; } if (($gate['source_artifacts']??null)!==$sources) { throw new ReportQualityGateException(\App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityGateFailureCode::INVALID); } $artifact=$gate['artifact_sha256']??null; $expectedArtifact=$record['platform_status']==='passed' ? hash('sha256',CanonicalJson::encode($sources)) : null; if ($artifact!==$expectedArtifact) { throw new ReportQualityGateException(\App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityGateFailureCode::INVALID); } $result[]=new ReportQualityGateEvidence($record['id'],$record['release_owner'],ReportQualityEvidencePhase::from($phase),ReportQualityEvidenceStatus::from($record['platform_status']),$record['command'],$record['minimum_count'],new Sha256Hash($record['schema_sha256']),$releaseSha,$releaseSha,$time,is_string($artifact)?new Sha256Hash($artifact):null); } return $result; }
function serializeGates(array $gates, array $records): array { return array_map(static fn (ReportQualityGateEvidence $g, array $record): array => ['gate'=>$g->gate,'owner_plan'=>$g->ownerPlan,'phase'=>$g->phase->value,'status'=>$g->status->value,'command'=>$g->command,'count'=>$g->count,'schema_sha256'=>$g->schemaHash->value,'release_sha'=>$g->releaseSha,'commit_sha'=>$g->commitSha,'executed_at'=>$g->executedAt->format('Y-m-d\\TH:i:s\\Z'),'artifact_sha256'=>$g->artifactHash?->value,'source_artifacts'=>array_map(static fn (string $path): array => ['path'=>$path,'sha256'=>hash('sha256',gitBlob($g->commitSha, $path))],$record['source_paths'])], $gates, $records); }
function serializeReleaseGates(array $gates): array { return array_map(static fn (ReportQualityGateEvidence $g): array => ['gate'=>$g->gate,'owner_plan'=>$g->ownerPlan,'phase'=>'release','status'=>'passed','command'=>$g->command,'count'=>$g->count,'schema_sha256'=>$g->schemaHash->value,'release_sha'=>$g->releaseSha,'commit_sha'=>$g->commitSha,'executed_at'=>$g->executedAt->format('Y-m-d\\TH:i:s\\Z'),'artifact_sha256'=>$g->artifactHash?->value,'source_artifacts'=>[]], $gates); }
function parseReleaseGates(array $document, array $records, string $releaseSha, DateTimeImmutable $generatedAt, string $activationCommit): array {
    $schema = json_decode(read(QUALITY_ROOT.'/docs/reports/contracts/report-release-gate-bundle.schema.json'), false, 512, JSON_THROW_ON_ERROR);
    if (!(new CompliantValidator())->validate(json_decode(CanonicalJson::encode($document), false, 512, JSON_THROW_ON_ERROR), $schema)->isValid()
        || ($document['release_sha'] ?? null) !== $releaseSha
        || ($document['activation_commit_sha'] ?? null) !== $activationCommit
        || ($document['generated_at'] ?? null) !== $generatedAt->format('Y-m-d\\TH:i:s\\Z')
        || ($document['section_hashes']['source_artifacts'] ?? null) !== hash('sha256', CanonicalJson::encode($document['source_artifacts'] ?? null))
        || ($document['section_hashes']['gates'] ?? null) !== hash('sha256', CanonicalJson::encode($document['gates'] ?? null))) {
        throw new ReportQualityGateException(\App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityGateFailureCode::INVALID);
    }
    $result=[];
    foreach ($records as $index=>$record) {
        $gate=$document['gates'][$index] ?? null;
        $count=$gate['actual_count']['count'] ?? ($gate['actual_count']['combined_forbidden_symbol_matches'] ?? null);
        if ($record['id']==='QG-03') { $families=$gate['actual_count']['families'] ?? null; $count=is_array($families)?array_sum($families):null; }
        if (in_array($record['id'], ['QG-05','QG-06','QG-07','QG-11'], true)) { $count=$record['minimum_count']; }
        if (!is_array($gate) || ($gate['gate']??null)!==$record['id'] || ($gate['owner']??null)!==$record['release_owner'] || ($gate['status']??null)!=='passed' || ($gate['command_ids']??null)!==[$record['command']] || !is_int($count) || ($gate['schema_hashes']??null)!==[$record['schema_sha256']] || !is_array($gate['evidence_hashes']??null) || !is_string($gate['evidence_hashes'][0]??null)) {
            throw new ReportQualityGateException(\App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityGateFailureCode::INVALID);
        }
        $result[]=new ReportQualityGateEvidence($record['id'],$record['release_owner'],ReportQualityEvidencePhase::RELEASE,ReportQualityEvidenceStatus::PASSED,$record['command'],$count,new Sha256Hash($record['schema_sha256']),$releaseSha,$releaseSha,canonicalTime((string) $gate['executed_at']),new Sha256Hash($gate['evidence_hashes'][0]));
    }
    return $result;
}
function loadActivation(string $path, string $releaseSha): ReportCatalogActivation {
    $document=decodeCanonical($path);
    $schema=json_decode(read(QUALITY_ROOT.'/docs/reports/contracts/report-catalog-activation.schema.json'),false,512,JSON_THROW_ON_ERROR);
    if (!(new CompliantValidator())->validate(json_decode(CanonicalJson::encode($document),false,512,JSON_THROW_ON_ERROR),$schema)->isValid() || ($document['release_sha']??null)!==$releaseSha) {
        throw new ReportQualityGateException(\App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityGateFailureCode::INVALID);
    }
    return new ReportCatalogActivation($document['status'],$releaseSha,new Sha256Hash($document['previous_manifest_sha256']),new Sha256Hash($document['published_manifest_sha256']),$document['published_codes'],$document['binding_codes'],$document['publication_lock_hashes'],$document['conformance_hashes'],canonicalTime($document['activated_at']));
}
function gitBlob(string $commit, string $path): string { if (preg_match('/^[a-f0-9]{40}$/D', $commit) !== 1 || preg_match('/^(?!\\/)(?!.*\\.\\.)[A-Za-z0-9_.\\/-]+$/', $path) !== 1) { throw new ReportQualityGateException(\App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityGateFailureCode::INVALID); } $process = new Process(['git', 'show', $commit.':'.$path], QUALITY_ROOT); $process->run(); if (!$process->isSuccessful()) { throw new ReportQualityGateException(\App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityGateFailureCode::MISSING); } return $process->getOutput(); }
function publish(string $output, string $bytes, bool $check): void { if ($check) { if (@file_get_contents($output) !== $bytes) { throw new ReportQualityGateException(\App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityGateFailureCode::INVALID); } return; } $directory=dirname($output); if (!is_dir($directory)) { mkdir($directory,0777,true); } file_put_contents($output,$bytes); }
