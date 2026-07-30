<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Evidence;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPrerequisiteEvidenceBundle;
use App\BusinessModules\Core\Reporting\Domain\DTO\TrackedPlanDocument;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Opis\JsonSchema\CompliantValidator;
use RuntimeException;
use Symfony\Component\Process\Process;

final readonly class PlanOneCPlatformEvidenceBuilder
{
    public const PLAN_ONE_B_SHA256 = '58f865ed19b1f040057a37b72dfc52a1822a2925416a1fea3ecc30ee50d4c626';

    public function __construct(private ?string $repositoryRoot = null) {}

    /** @return array<string, mixed> */
    public function build(
        ReportPrerequisiteEvidenceBundle $bundle,
        TrackedPlanDocument $planOneB,
        TrackedPlanDocument $planOneC,
        string $repositoryCommit,
        DateTimeImmutable $generatedAt,
        array $platformEvidence,
    ): array {
        if (preg_match('/^[a-f0-9]{40}$/D', $repositoryCommit) !== 1
            || $planOneB->commitSha !== $repositoryCommit
            || $planOneC->commitSha !== $repositoryCommit
            || $planOneB->bytesHash->value !== self::PLAN_ONE_B_SHA256) {
            throw new RuntimeException('plan_one_c_platform_evidence_invalid');
        }
        $verifiedPlatformEvidence = $this->verifyPlatformEvidence($platformEvidence, $repositoryCommit);
        $artifacts = [];
        foreach ($bundle->artifacts as $artifact) $artifacts[$artifact->id] = $artifact->sha256->value;
        $document = [
            'schema_version' => '1.0.0',
            'plan_id' => '1c',
            'status' => 'platform_passed',
            'repository_commit' => $repositoryCommit,
            'generated_at' => $generatedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z'),
            'plan_1c_lock_sha256' => $this->sha256($repositoryCommit, 'docs/reports/contracts/plan-1c-contract-lock.json'),
            'plan_1a_completion_sha256' => $artifacts['plan-1a-completion'] ?? null,
            'plan_1a_artifact_hashes' => [
                'contract_lock' => $artifacts['plan-1a-contract-lock'] ?? null,
                'resource_schema' => $artifacts['plan-1a-resource-schema'] ?? null,
                'route_snapshot' => $artifacts['plan-1a-route-snapshot'] ?? null,
                'ci_authorization' => $artifacts['plan-1a-ci-authorization'] ?? null,
                'ci_malformed' => $artifacts['plan-1a-ci-malformed'] ?? null,
            ],
            'plan_1b_completion_sha256' => $artifacts['plan-1b-completion'] ?? null,
            'plan_1b_gate_hashes' => array_combine(self::gateIds(), array_map(static fn (string $gate): ?string => $artifacts['plan-1b:'.$gate] ?? null, self::gateIds())),
            'plan_1b_plan_sha256' => self::PLAN_ONE_B_SHA256,
            'plan_1b_document_sha256' => $planOneB->bytesHash->value,
            'plan_1c_document_sha256' => $planOneC->bytesHash->value,
            'prerequisite_bundle_sha256' => $bundle->manifestHash->value,
            'platform_quality_sha256' => $verifiedPlatformEvidence['platform_quality_sha256'],
            'platform_quality_catalog_sha256' => $verifiedPlatformEvidence['platform_quality_catalog_sha256'],
            'source_hashes' => $this->sourceHashes($repositoryCommit),
            'command_records' => $verifiedPlatformEvidence['command_records'],
            'ci_artifact_hashes' => $verifiedPlatformEvidence['ci_artifact_hashes'],
            'published_count' => $verifiedPlatformEvidence['published_count'],
            'binding_count' => $verifiedPlatformEvidence['binding_count'],
            'unresolved_risks' => $verifiedPlatformEvidence['unresolved_risks'],
        ];
        $this->validate($document);

        return $document;
    }

    /** @return array<string, mixed> */
    public function publish(string $path, array $document): array
    {
        $this->validate($document);
        $bytes = CanonicalJson::encode($document)."\n";
        $directory = dirname($path);
        if ((!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) || is_link($path)) {
            throw new RuntimeException('plan_one_c_platform_evidence_write_failed');
        }
        $temporary = tempnam($directory, '.plan-1c-platform-');
        if (!is_string($temporary)) throw new RuntimeException('plan_one_c_platform_evidence_write_failed');
        try {
            if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes) || !rename($temporary, $path)) {
                throw new RuntimeException('plan_one_c_platform_evidence_write_failed');
            }
            $reread = file_get_contents($path);
            if (!is_string($reread) || !hash_equals($bytes, $reread) || CanonicalJson::encode($this->decode($reread))."\n" !== $reread) {
                throw new RuntimeException('plan_one_c_platform_evidence_reread_failed');
            }
            $result = $this->decode($reread);
            $this->validate($result);
            return $result;
        } finally {
            if (is_file($temporary)) unlink($temporary);
        }
    }

    private function validate(array $document): void
    {
        $repositoryCommit = $document['repository_commit'] ?? null;
        if (!is_string($repositoryCommit) || preg_match('/^[a-f0-9]{40}$/D', $repositoryCommit) !== 1) {
            throw new RuntimeException('plan_one_c_platform_evidence_invalid');
        }

        $schema = $this->decode($this->readGitBlob($repositoryCommit, 'docs/reports/contracts/plan-1c-platform-completion.schema.json'));
        try {
            $valid = (new CompliantValidator())->validate(
                json_decode(json_encode($document, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR),
                json_decode(json_encode($schema, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR),
            )->isValid();
        } catch (JsonException) {
            $valid = false;
        }
        if (!$valid || ($document['status'] ?? null) !== 'platform_passed'
            || ($document['published_count'] ?? null) !== ($document['binding_count'] ?? null)
            || !is_array($document['unresolved_risks'] ?? null) || $document['unresolved_risks'] !== []) {
            throw new RuntimeException('plan_one_c_platform_evidence_invalid');
        }
    }

    /** @return array{platform_quality_sha256:string, platform_quality_catalog_sha256:string, source_hashes:array<string,string>, command_records:array<string,array<string,mixed>>, ci_artifact_hashes:array<string,string>, published_count:int, binding_count:int, unresolved_risks:list<mixed>} */
    private function verifyPlatformEvidence(array $evidence, string $commit): array
    {
        $names = ['workspace', 'saved_views', 'subscriptions', 'integration', 'fake_sequence'];
        if (array_keys($evidence) !== ['platform_quality', 'ci_artifacts', 'published_count', 'binding_count', 'unresolved_risks']) {
            throw new RuntimeException('plan_one_c_platform_evidence_invalid');
        }
        $quality = $evidence['platform_quality'] ?? null;
        $ci = $evidence['ci_artifacts'] ?? null;
        if (!is_array($quality) || !is_array($ci) || array_keys($ci) !== $names
            || !is_int($evidence['published_count']) || !is_int($evidence['binding_count'])
            || !is_array($evidence['unresolved_risks'])) {
            throw new RuntimeException('plan_one_c_platform_evidence_invalid');
        }
        $qualityHash = $this->documentHash($quality);
        $catalog = $this->decode($this->readGitBlob($commit, 'docs/reports/contracts/report-platform-gates.v1.json'));
        if (($quality['artifact_id'] ?? null) !== 'report_quality_evidence'
            || ($quality['schema_version'] ?? null) !== '1.0.0'
            || ($quality['status'] ?? null) !== 'platform_passed'
            || ($quality['release_sha'] ?? null) !== $commit
            || !is_array($quality['catalog'] ?? null)
            || ($quality['catalog']['path'] ?? null) !== 'docs/reports/contracts/report-platform-gates.v1.json'
            || ($quality['catalog']['sha256'] ?? null) !== $this->sha256($commit, 'docs/reports/contracts/report-platform-gates.v1.json')
            || !is_array($quality['gates'] ?? null) || count($quality['gates']) !== 14) {
            throw new RuntimeException('plan_one_c_platform_evidence_invalid');
        }
        $this->validateQualityArtifact($quality, $commit);
        $qualityCommands = [];
        $gates = [];
        $catalogGates = $catalog['gates'] ?? null;
        if (!is_array($catalogGates) || !array_is_list($catalogGates) || count($catalogGates) !== 14) {
            throw new RuntimeException('plan_one_c_platform_evidence_invalid');
        }
        foreach ($quality['gates'] as $index => $gate) {
            $catalogGate = $catalogGates[$index] ?? null;
            if (!is_array($gate) || !is_string($gate['gate'] ?? null) || isset($gates[$gate['gate']])
                || !is_array($catalogGate) || ($gate['gate'] ?? null) !== ($catalogGate['id'] ?? null)
                || ($gate['owner_plan'] ?? null) !== ($catalogGate['release_owner'] ?? null)
                || ($gate['phase'] ?? null) !== 'platform' || ($gate['status'] ?? null) !== ($catalogGate['platform_status'] ?? null)
                || ($gate['command'] ?? null) !== ($catalogGate['command'] ?? null)
                || ($gate['count'] ?? null) !== ($catalogGate['minimum_count'] ?? null)
                || ($gate['schema_sha256'] ?? null) !== ($catalogGate['schema_sha256'] ?? null)
                || ($gate['commit_sha'] ?? null) !== $commit || ($gate['release_sha'] ?? null) !== $commit
                || !$this->matchesQualityArtifactHash($gate, $catalogGate)
                || !$this->matchesQualitySources($gate, $catalogGate, $commit)) {
                throw new RuntimeException('plan_one_c_platform_evidence_invalid');
            }
            $gates[$gate['gate']] = true;
        }
        if (array_keys($gates) !== array_map(static fn (int $number): string => sprintf('QG-%02d', $number), range(1, 14))) {
            throw new RuntimeException('plan_one_c_platform_evidence_invalid');
        }
        $qualityCommands['platform_quality'] = [
            'command' => 'build-report-quality-evidence',
            'status' => 'passed',
            'count' => count($quality['gates']),
            'duration_ms' => 0,
            'output_sha256' => $qualityHash,
        ];
        $hashes = ['platform_quality' => $qualityHash];
        foreach ($names as $name) {
            $artifact = $ci[$name];
            if (!is_array($artifact) || !$this->isValidCiArtifact($artifact, $name, $commit)) {
                throw new RuntimeException('plan_one_c_platform_evidence_invalid');
            }
            if (($artifact['published_count'] ?? null) !== $evidence['published_count']
                || ($artifact['binding_count'] ?? null) !== $evidence['binding_count']
                || ($artifact['unresolved_risks'] ?? null) !== $evidence['unresolved_risks']) {
                throw new RuntimeException('plan_one_c_platform_evidence_invalid');
            }
            $hash = $this->documentHash($artifact);
            $qualityCommands[$name] = $this->commandRecord($artifact['command_record'] ?? null);
            $hashes[$name] = $hash;
        }
        if ($evidence['published_count'] !== $this->releasePublishedCount($commit)
            || $evidence['binding_count'] !== $this->releasePublishedCount($commit)
            || $evidence['unresolved_risks'] !== []) {
            throw new RuntimeException('plan_one_c_platform_evidence_invalid');
        }
        return ['platform_quality_sha256' => $qualityHash, 'platform_quality_catalog_sha256' => $quality['catalog']['sha256'], 'source_hashes' => $this->sourceHashes($commit), 'command_records' => $qualityCommands, 'ci_artifact_hashes' => $hashes, 'published_count' => $evidence['published_count'], 'binding_count' => $evidence['binding_count'], 'unresolved_risks' => $evidence['unresolved_risks']];
    }

    /** @return array{command:string,status:string,count:int,duration_ms:int,output_sha256:string} */
    private function commandRecord(mixed $record): array
    {
        if (!is_array($record) || array_keys($record) !== ['command', 'status', 'count', 'duration_ms', 'output_sha256']
            || !is_string($record['command']) || $record['command'] === '' || ($record['status'] ?? null) !== 'passed'
            || !is_int($record['count']) || $record['count'] < 1 || !is_int($record['duration_ms']) || $record['duration_ms'] < 0
            || !is_string($record['output_sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $record['output_sha256']) !== 1) {
            throw new RuntimeException('plan_one_c_platform_evidence_invalid');
        }
        return $record;
    }

    private function isValidCiArtifact(array $artifact, string $name, string $commit): bool
    {
        if (array_keys($artifact) !== [
            'artifact_id', 'schema_version', 'status', 'repository_commit', 'source_hashes', 'command_record',
            'output', 'published_count', 'binding_count', 'unresolved_risks',
        ] || ($artifact['artifact_id'] ?? null) !== 'reporting_ci_'.$name
            || ($artifact['schema_version'] ?? null) !== '1.0.0' || ($artifact['status'] ?? null) !== 'passed'
            || ($artifact['repository_commit'] ?? null) !== $commit || ($artifact['source_hashes'] ?? null) !== $this->sourceHashes($commit)
            || !is_array($artifact['output'] ?? null) || array_keys($artifact['output']) !== ['check_id', 'status', 'count']
            || ($artifact['output']['check_id'] ?? null) !== 'reporting_ci_'.$name
            || ($artifact['output']['status'] ?? null) !== 'passed' || !is_int($artifact['output']['count'] ?? null)) {
            return false;
        }
        try {
            $record = $this->commandRecord($artifact['command_record'] ?? null);
        } catch (RuntimeException) {
            return false;
        }

        return $record['command'] === 'reporting-ci-'.$name
            && $record['count'] === $artifact['output']['count']
            && hash_equals($this->documentHash($artifact['output']), $record['output_sha256']);
    }

    private function validateQualityArtifact(array $artifact, string $commit): void
    {
        $schema = $this->decode($this->readGitBlob($commit, 'docs/reports/contracts/report-quality-evidence.schema.json'));
        try {
            $valid = (new CompliantValidator())->validate(
                json_decode(json_encode($artifact, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR),
                json_decode(json_encode($schema, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR),
            )->isValid();
        } catch (JsonException) {
            $valid = false;
        }
        if (!$valid) {
            throw new RuntimeException('plan_one_c_platform_evidence_invalid');
        }
    }

    private function matchesQualityArtifactHash(array $gate, array $catalogGate): bool
    {
        $artifactHash = $gate['artifact_sha256'] ?? null;
        if (($catalogGate['platform_status'] ?? null) === 'passed') {
            if (!is_string($artifactHash) || preg_match('/^[a-f0-9]{64}$/D', $artifactHash) !== 1) {
                return false;
            }

            return hash_equals(
                hash('sha256', CanonicalJson::encode($gate['source_artifacts'] ?? [])),
                $artifactHash,
            );
        }

        return $artifactHash === null;
    }

    private function matchesQualitySources(array $gate, array $catalogGate, string $commit): bool
    {
        $sources = $catalogGate['source_paths'] ?? null;
        if (!is_array($sources) || !array_is_list($sources) || !is_array($gate['source_artifacts'] ?? null)) {
            return false;
        }
        $expected = [];
        foreach ($sources as $source) {
            if (!is_string($source)) {
                return false;
            }
            $expected[] = ['path' => $source, 'sha256' => $this->sha256($commit, $source)];
        }

        return $gate['source_artifacts'] === $expected;
    }

    private function releasePublishedCount(string $commit): int
    {
        $lock = $this->decode($this->readGitBlob($commit, 'docs/reports/contracts/plan-1c-contract-lock.json'));
        $count = $lock['quality']['release_published_count'] ?? null;

        if (!is_int($count) || $count < 1) {
            throw new RuntimeException('plan_one_c_platform_evidence_invalid');
        }

        return $count;
    }

    /** @return array{manifest:string,official_manifest:string,generated_catalog:string,resource:string,permission:string,translation:string,route:string,schema:string,candidate_validation:string,conformance_framework:string,publication_framework:string,platform_quality_ledger:string} */
    private function sourceHashes(string $commit): array
    {
        return [
            'manifest' => $this->sha256($commit, 'app/BusinessModules/Core/Reporting/resources/management-catalog.v1.yaml'),
            'official_manifest' => $this->sha256($commit, 'app/BusinessModules/Core/Reporting/resources/official-document-catalog.v1.yaml'),
            'generated_catalog' => $this->sha256($commit, 'docs/reports/generated/reporting-catalog.v1.json'),
            'resource' => $this->sha256($commit, 'docs/reports/contracts/reporting-admin-resources.v1.schema.json'),
            'permission' => $this->sha256($commit, 'docs/reports/generated/report-permissions.v1.json'),
            'translation' => $this->sha256($commit, 'lang/ru/reports.php'),
            'route' => $this->sha256($commit, 'app/BusinessModules/Core/Reporting/routes.php'),
            'schema' => $this->sha256($commit, 'app/BusinessModules/Core/Reporting/resources/management-catalog.v1.schema.json'),
            'candidate_validation' => $this->sha256($commit, 'docs/reports/contracts/report-candidate-validation.schema.json'),
            'conformance_framework' => $this->sha256($commit, 'docs/reports/contracts/report-conformance-evidence.schema.json'),
            'publication_framework' => $this->sha256($commit, 'docs/reports/contracts/report-publication-ledger.schema.json'),
            'platform_quality_ledger' => $this->sha256($commit, 'docs/reports/contracts/report-quality-evidence.schema.json'),
        ];
    }

    private function documentHash(array $document): string
    {
        return hash('sha256', CanonicalJson::encode($document)."\n");
    }

    /** @return list<string> */
    private static function gateIds(): array
    {
        return ['plan1a_handoff', 'ownership_boundary', 'run_state_machine', 'run_idempotency', 'snapshot_identity', 'rows_cursor_drill_parity', 'row_stream_shape', 'export_state_machine', 'export_idempotency', 'renderer_parity', 'pdf_renderer_budget', 'streaming_budget', 'file_service_call_graph', 's3_version_race', 'audit_fail_closed', 'retention_exact_version', 'action_bindings', 'error_retryability', 'run_export_observability', 'static_analysis'];
    }

    private function sha256(string $commit, string $relative): string
    {
        return hash('sha256', $this->readGitBlob($commit, $relative));
    }

    private function readGitBlob(string $commit, string $relative): string
    {
        if (preg_match('/^[a-f0-9]{40}$/D', $commit) !== 1) {
            throw new RuntimeException('plan_one_c_platform_evidence_invalid');
        }

        $process = new Process(['git', 'show', $commit.':'.$relative], $this->root());
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException('plan_one_c_platform_evidence_invalid');
        }

        return $process->getOutput();
    }
    private function root(): string { $root = realpath($this->repositoryRoot ?? getcwd()); if (!is_string($root)) throw new RuntimeException('plan_one_c_platform_evidence_invalid'); return str_replace('\\', '/', $root); }
    /** @return array<string, mixed> */
    private function decode(string $bytes): array { try { $data = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR); } catch (JsonException) { throw new RuntimeException('plan_one_c_platform_evidence_invalid'); } if (!is_array($data) || array_is_list($data)) throw new RuntimeException('plan_one_c_platform_evidence_invalid'); return $data; }
}
