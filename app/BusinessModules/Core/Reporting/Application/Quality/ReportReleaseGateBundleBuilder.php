<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Quality;

use App\BusinessModules\Core\Reporting\Domain\DTO\JointQG14Evidence;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQualityGateEvidence;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportReleaseGateBundle;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityEvidencePhase;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityEvidenceStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityGateFailureCode;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

final class ReportReleaseGateBundleBuilder
{
    private const MINIMUM_COUNT_GATES = ['QG-03', 'QG-11'];

    private const SOURCE_ARTIFACTS = [
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

    private const GATE_SOURCES = [
        'QG-01' => 'plan-1c-platform-completion',
        'QG-02' => 'plan-2-wave-1-candidate-conformance',
        'QG-03' => 'plan-2-wave-1-candidate-conformance',
        'QG-04' => 'plan-2-wave-1-candidate-conformance',
        'QG-05' => 'plan-2-wave-1-candidate-conformance',
        'QG-06' => 'plan3_waves23_candidate_contribution',
        'QG-07' => 'plan3_waves23_candidate_contribution',
        'QG-08' => 'plan3_waves23_evidence',
        'QG-09' => 'plan3_waves23_evidence',
        'QG-10' => 'plan4_admin_qg10_qg14_evidence',
        'QG-11' => 'plan4_admin_qg10_qg14_evidence',
        'QG-12' => 'plan4_admin_qg10_qg14_evidence',
        'QG-13' => 'plan4_admin_qg10_qg14_evidence',
        'QG-14' => 'plan4_admin_qg10_qg14_evidence',
    ];

    public function __construct(private readonly ?ReportPlatformGateCatalog $catalog = null)
    {
    }

    /** @param list<ReportQualityGateEvidence> $gates */
    public function build(
        array $gates,
        JointQG14Evidence $qg14Evidence,
        string $releaseSha,
        array $sources,
        string $activationCommitSha,
        string $adminEvidenceCommitSha,
        DateTimeImmutable $generatedAt,
    ): ReportReleaseGateBundle
    {
        if (preg_match('/^[a-f0-9]{40}$/D', $releaseSha) !== 1
            || preg_match('/^[a-f0-9]{40}$/D', $activationCommitSha) !== 1
            || preg_match('/^[a-f0-9]{40}$/D', $adminEvidenceCommitSha) !== 1
            || ! $this->commitExists($releaseSha)
            || ! $this->commitExists($activationCommitSha)
            || ! $this->commitExists($adminEvidenceCommitSha)
            || $activationCommitSha === $releaseSha
            || ! array_is_list($gates)
            || count($gates) !== 14
            || ! $this->hasExactSourceArtifacts($sources, $releaseSha, $activationCommitSha, $adminEvidenceCommitSha)) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::CATALOG_COUNT_MISMATCH);
        }

        $catalog = $this->catalog()->records();
        $loadedGates = $this->loadGateEvidence($sources, $releaseSha, $activationCommitSha, $adminEvidenceCommitSha);

        foreach ($gates as $index => $gate) {
            $definition = $catalog[$index];
            $loadedGate = $loadedGates[$index];
            if (! $gate instanceof ReportQualityGateEvidence
                || $gate->gate !== $definition['id']
                || $gate->phase !== ReportQualityEvidencePhase::RELEASE
                || $gate->status !== ReportQualityEvidenceStatus::PASSED
                || $gate->releaseSha !== $releaseSha
                || $gate->ownerPlan !== $definition['release_owner']
                || $gate->command !== $definition['command']
                || $gate->schemaHash->value !== $definition['schema_sha256']
                || $gate->commitSha !== $loadedGate->commitSha
                || $gate->executedAt != $loadedGate->executedAt
                || $gate->artifactHash?->value !== $loadedGate->artifactHash?->value
                || ! $this->matchesCount($gate, $definition)
                || ($gate->gate === 'QG-07' && ($generatedAt->getTimestamp() - $gate->executedAt->getTimestamp() < 0 || $generatedAt->getTimestamp() - $gate->executedAt->getTimestamp() > 86400))) {
                throw new ReportQualityGateException(ReportQualityGateFailureCode::PHASE_INCOMPLETE);
            }
        }

        $qg14 = $gates[13];
        if ($qg14->command !== $qg14Evidence->commandId
            || $qg14->count !== $qg14Evidence->combinedForbiddenSymbolMatches
            || $this->qg14SourceHashes() !== [
                $qg14Evidence->qg14AdminSha256->value,
                $qg14Evidence->qg14BackendSha256->value,
                $qg14Evidence->qg14CombinedSha256->value,
            ]) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::COMMAND_COUNT_MISMATCH);
        }

        return new ReportReleaseGateBundle(
            'report_release_gate_bundle',
            'release_gates_passed',
            $releaseSha,
            $activationCommitSha,
            $adminEvidenceCommitSha,
            $gates,
            $sources,
            $generatedAt,
            ['backend' => 9, 'admin' => 4, 'joint' => 1],
        );
    }

    /**
     * @param list<array{artifact_id: string, kind: string, path: string, bytes_sha256: string}> $sources
     * @return list<ReportQualityGateEvidence>
     */
    public function loadGateEvidence(
        array $sources,
        string $releaseSha,
        string $activationCommitSha,
        string $adminEvidenceCommitSha,
    ): array {
        if (! $this->hasExactSourceArtifacts($sources, $releaseSha, $activationCommitSha, $adminEvidenceCommitSha)) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::CATALOG_COUNT_MISMATCH);
        }

        $catalog = $this->catalog()->records();
        $byGate = [];
        foreach ($sources as $index => $source) {
            $bytes = $this->artifactBytes($source['path']);
            if ($index === 9) {
                $schema = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($schema)
                    || ($schema['$schema'] ?? null) !== 'https://json-schema.org/draft/2020-12/schema'
                    || ($schema['type'] ?? null) !== 'object'
                    || ($schema['additionalProperties'] ?? null) !== false) {
                    throw new ReportQualityGateException(ReportQualityGateFailureCode::PHASE_INCOMPLETE);
                }
                continue;
            }
            if ($index === 11) {
                $manifest = Yaml::parse($bytes);
                if (! is_array($manifest)
                    || ($manifest['catalog'] ?? null) !== 'management-catalog.v1'
                    || ! is_array($manifest['definitions'] ?? null)
                    || count($manifest['definitions']) !== 28) {
                    throw new ReportQualityGateException(ReportQualityGateFailureCode::PHASE_INCOMPLETE);
                }
                continue;
            }
            if ($index === 12) {
                $ledger = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($ledger)
                    || ($ledger['artifact_id'] ?? null) !== 'report_publication_ledger'
                    || ($ledger['schema_version'] ?? null) !== '1.0.0'
                    || ! is_array($ledger['events'] ?? null)) {
                    throw new ReportQualityGateException(ReportQualityGateFailureCode::PHASE_INCOMPLETE);
                }
                continue;
            }

            $document = $this->decodeCanonical($bytes);
            $this->assertEvidenceDocument(
                $document,
                $source['artifact_id'],
                $source['kind'],
                $releaseSha,
                $activationCommitSha,
                $adminEvidenceCommitSha,
            );
            foreach ($document['quality_gates'] as $item) {
                $gate = $item['gate'] ?? null;
                if (! is_string($gate) || isset($byGate[$gate])) {
                    throw new ReportQualityGateException(ReportQualityGateFailureCode::PHASE_INCOMPLETE);
                }
                $catalogIndex = (int) substr($gate, -2) - 1;
                $definition = $catalog[$catalogIndex] ?? null;
                $section = is_string($item['evidence_section'] ?? null)
                    ? ($document['evidence_sections'][$item['evidence_section']] ?? null)
                    : null;
                $sectionKeys = $gate === 'QG-14'
                    ? ['source_artifact_id', 'gate', 'result', 'observed_count', 'qg14_admin_sha256', 'qg14_backend_sha256', 'qg14_combined_sha256']
                    : ['source_artifact_id', 'gate', 'result', 'observed_count'];
                if (! is_array($definition)
                    || ! $this->hasExactKeys($item, ['gate', 'owner_plan', 'command', 'count', 'schema_sha256', 'executed_at', 'evidence_section', 'artifact_sha256'])
                    || $gate !== $definition['id']
                    || ($item['owner_plan'] ?? null) !== $definition['release_owner']
                    || ($item['command'] ?? null) !== $definition['command']
                    || ! is_int($item['count'] ?? null)
                    || ($item['schema_sha256'] ?? null) !== $definition['schema_sha256']
                    || (self::GATE_SOURCES[$gate] ?? null) !== $source['artifact_id']
                    || ! is_string($item['evidence_section'] ?? null)
                    || ! is_array($section)
                    || ! $this->hasExactKeys($section, $sectionKeys)
                    || ($section['source_artifact_id'] ?? null) !== $source['artifact_id']
                    || ($section['gate'] ?? null) !== $gate
                    || ($section['result'] ?? null) !== 'passed'
                    || ($section['observed_count'] ?? null) !== $item['count']
                    || ($gate === 'QG-14' && ! $this->hasValidQG14Hashes($section))
                    || ($item['artifact_sha256'] ?? null) !== ($document['section_hashes'][$item['evidence_section']] ?? null)) {
                    throw new ReportQualityGateException(ReportQualityGateFailureCode::PHASE_INCOMPLETE);
                }
                $executedAt = $this->canonicalTime($item['executed_at'] ?? null);
                $byGate[$gate] = new ReportQualityGateEvidence(
                    $gate,
                    $definition['release_owner'],
                    ReportQualityEvidencePhase::RELEASE,
                    ReportQualityEvidenceStatus::PASSED,
                    $definition['command'],
                    $item['count'],
                    new Sha256Hash($definition['schema_sha256']),
                    $releaseSha,
                    $document['repository_commit'],
                    $executedAt,
                    new Sha256Hash($item['artifact_sha256']),
                );
            }
        }

        $gates = [];
        foreach ($catalog as $definition) {
            if (! isset($byGate[$definition['id']])) {
                throw new ReportQualityGateException(ReportQualityGateFailureCode::PHASE_INCOMPLETE);
            }
            $gates[] = $byGate[$definition['id']];
        }

        return $gates;
    }

    /** @param array{id: string, minimum_count: int} $definition */
    private function matchesCount(ReportQualityGateEvidence $gate, array $definition): bool
    {
        if (in_array($definition['id'], self::MINIMUM_COUNT_GATES, true)) {
            return $gate->count >= $definition['minimum_count'];
        }

        return $gate->count === $definition['minimum_count'];
    }

    private function catalog(): ReportPlatformGateCatalog
    {
        return $this->catalog ?? new ReportPlatformGateCatalog(
            dirname(__DIR__, 6).'/docs/reports/contracts/report-platform-gates.v1.json',
        );
    }

    /** @param list<array<string, mixed>> $sources */
    private function hasExactSourceArtifacts(
        array $sources,
        string $releaseSha,
        string $activationCommitSha,
        string $adminEvidenceCommitSha,
    ): bool
    {
        if (! array_is_list($sources) || count($sources) !== 13) {
            return false;
        }
        if (! $this->commitExists($releaseSha)
            || ! $this->commitExists($activationCommitSha)
            || ! $this->commitExists($adminEvidenceCommitSha)) {
            return false;
        }

        foreach ($sources as $index => $source) {
            [$artifactId, $kind, $path] = self::SOURCE_ARTIFACTS[$index];
            $bytes = @file_get_contents(dirname(__DIR__, 6).'/'.$path);
            if (! is_string($bytes)) {
                return false;
            }
            $bytesHash = hash('sha256', $bytes);
            $expectedCommit = $this->expectedRepositoryCommit(
                $artifactId,
                $releaseSha,
                $activationCommitSha,
                $adminEvidenceCommitSha,
            );
            $expectedStatus = 'tracked';
            $expectedSections = ['document' => $bytesHash];
            if (! in_array($index, [9, 11, 12], true)) {
                try {
                    $document = $this->decodeCanonical($bytes);
                } catch (\Throwable) {
                    return false;
                }
                $expectedStatus = is_string($document['status'] ?? null) ? $document['status'] : '';
                $expectedSections = is_array($document['section_hashes'] ?? null)
                    ? $document['section_hashes']
                    : [];
            }
            if (! is_array($source)
                || array_keys($source) !== ['artifact_id', 'kind', 'path', 'bytes_sha256', 'document_sha256', 'repository_commit', 'status', 'section_hashes']
                || ($source['artifact_id'] ?? null) !== $artifactId
                || ($source['kind'] ?? null) !== $kind
                || ($source['path'] ?? null) !== $path
                || ($source['bytes_sha256'] ?? null) !== $bytesHash
                || ($source['document_sha256'] ?? null) !== $bytesHash
                || ($source['repository_commit'] ?? null) !== $expectedCommit
                || ($source['status'] ?? null) !== $expectedStatus
                || ($source['section_hashes'] ?? null) !== $expectedSections
            ) {
                return false;
            }
        }

        return true;
    }

    private function artifactBytes(string $path): string
    {
        $bytes = @file_get_contents(dirname(__DIR__, 6).'/'.$path);
        if (! is_string($bytes)) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::MISSING);
        }

        return $bytes;
    }

    /** @return list<string> */
    private function qg14SourceHashes(): array
    {
        $document = $this->decodeCanonical($this->artifactBytes('build/reports/intake/plan-4-admin-evidence.json'));
        $sectionName = null;
        foreach ($document['quality_gates'] as $gate) {
            if (($gate['gate'] ?? null) === 'QG-14' && is_string($gate['evidence_section'] ?? null)) {
                $sectionName = $gate['evidence_section'];
                break;
            }
        }
        $section = is_string($sectionName) ? ($document['evidence_sections'][$sectionName] ?? null) : null;
        if (! is_array($section) || ! $this->hasValidQG14Hashes($section)) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::COMMAND_COUNT_MISMATCH);
        }

        return [
            $section['qg14_admin_sha256'],
            $section['qg14_backend_sha256'],
            $section['qg14_combined_sha256'],
        ];
    }

    /** @return array<string, mixed> */
    private function decodeCanonical(string $bytes): array
    {
        try {
            $document = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::PHASE_INCOMPLETE);
        }
        if (! is_array($document) || CanonicalJson::encode($document)."\n" !== $bytes) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::PHASE_INCOMPLETE);
        }

        return $document;
    }

    /** @param array<string, mixed> $document */
    private function assertEvidenceDocument(
        array $document,
        string $artifactId,
        string $kind,
        string $releaseSha,
        string $activationCommitSha,
        string $adminEvidenceCommitSha,
    ): void {
        $expectedKeys = ['artifact_id', 'schema_version', 'status', 'repository_commit', 'generated_at', 'evidence_sections', 'quality_gates', 'section_hashes'];
        if ($kind !== 'ancestor_evidence') {
            $expectedKeys[] = 'release_sha';
        }
        if ($kind === 'transfer') {
            $expectedKeys[] = 'activation_commit_sha';
            $expectedKeys[] = 'admin_evidence_commit_sha';
        }
        sort($expectedKeys);
        $actualKeys = array_keys($document);
        sort($actualKeys);
        if ($actualKeys !== $expectedKeys
            || ($document['artifact_id'] ?? null) !== $artifactId
            || ($document['schema_version'] ?? null) !== '1.0.0'
            || ! in_array($document['status'] ?? null, ['passed', 'artifact_transferred'], true)
            || ($document['repository_commit'] ?? null) !== $this->expectedRepositoryCommit(
                $artifactId,
                $releaseSha,
                $activationCommitSha,
                $adminEvidenceCommitSha,
            )
            || ! is_array($document['evidence_sections'] ?? null)
            || ! array_is_list($document['quality_gates'] ?? null)
            || ! is_array($document['section_hashes'] ?? null)
            || array_keys($document['section_hashes']) !== array_keys($document['evidence_sections'])) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::PHASE_INCOMPLETE);
        }
        foreach ($document['evidence_sections'] as $section => $evidence) {
            if (preg_match('/^[a-z][a-z0-9_]{2,63}$/D', (string) $section) !== 1
                || ($document['section_hashes'][$section] ?? null) !== hash('sha256', CanonicalJson::encode($evidence))) {
                throw new ReportQualityGateException(ReportQualityGateFailureCode::PHASE_INCOMPLETE);
            }
        }
        $this->canonicalTime($document['generated_at'] ?? null);
        if ($kind === 'ancestor_evidence') {
            if (array_key_exists('release_sha', $document)) {
                throw new ReportQualityGateException(ReportQualityGateFailureCode::PHASE_INCOMPLETE);
            }
        } elseif (($document['release_sha'] ?? null) !== $releaseSha) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::PHASE_INCOMPLETE);
        }
        if ($kind === 'transfer'
            && (($document['status'] ?? null) !== 'artifact_transferred'
                || ($document['activation_commit_sha'] ?? null) !== $activationCommitSha
                || ($document['admin_evidence_commit_sha'] ?? null) !== $adminEvidenceCommitSha)) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::PHASE_INCOMPLETE);
        }
    }

    private function expectedRepositoryCommit(
        string $artifactId,
        string $releaseSha,
        string $activationCommitSha,
        string $adminEvidenceCommitSha,
    ): string {
        return match ($artifactId) {
            'plan-1a-completion', 'plan-1b-completion', 'plan-1c-platform-completion' => $activationCommitSha,
            'plan4_admin_qg10_qg14_evidence', 'plan4_admin_evidence_transfer' => $adminEvidenceCommitSha,
            default => $releaseSha,
        };
    }

    /** @param array<string, mixed> $section */
    private function hasValidQG14Hashes(array $section): bool
    {
        foreach (['qg14_admin_sha256', 'qg14_backend_sha256', 'qg14_combined_sha256'] as $key) {
            if (! is_string($section[$key] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $section[$key]) !== 1) {
                return false;
            }
        }

        return true;
    }

    private function commitExists(string $commit): bool
    {
        if (preg_match('/^[a-f0-9]{40}$/D', $commit) !== 1) {
            return false;
        }

        $process = new Process(['git', 'cat-file', '-e', $commit.'^{commit}'], $this->root());
        $process->run();

        return $process->isSuccessful();
    }

    private function root(): string
    {
        $root = realpath(dirname(__DIR__, 6));
        if (! is_string($root)) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::MISSING);
        }

        return str_replace('\\', '/', $root);
    }

    private function canonicalTime(mixed $value): DateTimeImmutable
    {
        if (! is_string($value)) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::PHASE_INCOMPLETE);
        }
        try {
            $time = new DateTimeImmutable($value);
        } catch (\Exception) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::PHASE_INCOMPLETE);
        }
        if ($time->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::PHASE_INCOMPLETE);
        }

        return $time;
    }

    /** @param array<string, mixed> $value @param list<string> $keys */
    private function hasExactKeys(array $value, array $keys): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);

        return $actual === $keys;
    }
}
