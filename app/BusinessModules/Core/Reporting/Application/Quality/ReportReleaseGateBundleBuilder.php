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
            || $activationCommitSha === $releaseSha
            || ! array_is_list($gates)
            || count($gates) !== 14
            || ! $this->hasExactSourceArtifacts($sources)) {
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
        if ($qg14->command !== $qg14Evidence->commandId || $qg14->count !== $qg14Evidence->combinedForbiddenSymbolMatches) {
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
        if (! $this->hasExactSourceArtifacts($sources)) {
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
            foreach ($document['gate_evidence'] as $item) {
                $gate = $item['gate'] ?? null;
                if (! is_string($gate) || isset($byGate[$gate])) {
                    throw new ReportQualityGateException(ReportQualityGateFailureCode::PHASE_INCOMPLETE);
                }
                $catalogIndex = (int) substr($gate, -2) - 1;
                $definition = $catalog[$catalogIndex] ?? null;
                if (! is_array($definition)
                    || $gate !== $definition['id']
                    || ($item['owner_plan'] ?? null) !== $definition['release_owner']
                    || ($item['status'] ?? null) !== 'passed'
                    || ($item['command'] ?? null) !== $definition['command']
                    || ! is_int($item['count'] ?? null)
                    || ($item['schema_sha256'] ?? null) !== $definition['schema_sha256']
                    || preg_match('/^[a-f0-9]{40}$/D', $item['commit_sha'] ?? '') !== 1
                    || ! is_array($item['evidence'] ?? null)
                    || ($item['artifact_sha256'] ?? null) !== hash('sha256', CanonicalJson::encode($item['evidence']))) {
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
                    $item['commit_sha'],
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

    /** @param list<array{artifact_id: string, kind: string, path: string, bytes_sha256: string}> $sources */
    private function hasExactSourceArtifacts(array $sources): bool
    {
        if (! array_is_list($sources) || count($sources) !== 13) {
            return false;
        }

        foreach ($sources as $index => $source) {
            [$artifactId, $kind, $path] = self::SOURCE_ARTIFACTS[$index];
            if (! is_array($source)
                || array_keys($source) !== ['artifact_id', 'kind', 'path', 'bytes_sha256']
                || ($source['artifact_id'] ?? null) !== $artifactId
                || ($source['kind'] ?? null) !== $kind
                || ($source['path'] ?? null) !== $path
                || preg_match('/^[a-f0-9]{64}$/', $source['bytes_sha256'] ?? null) !== 1
                || ! $this->matchesArtifactBytes($path, $source['bytes_sha256'])
            ) {
                return false;
            }
        }

        return true;
    }

    private function matchesArtifactBytes(string $path, string $expectedHash): bool
    {
        $bytes = @file_get_contents(dirname(__DIR__, 6).'/'.$path);

        return is_string($bytes) && hash_equals(hash('sha256', $bytes), $expectedHash);
    }

    private function artifactBytes(string $path): string
    {
        $bytes = @file_get_contents(dirname(__DIR__, 6).'/'.$path);
        if (! is_string($bytes)) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::MISSING);
        }

        return $bytes;
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
        $requiredKeys = ['artifact_id', 'schema_version', 'status', 'producer_commit_sha', 'generated_at', 'gate_evidence', 'section_hashes'];
        foreach ($requiredKeys as $key) {
            if (! array_key_exists($key, $document)) {
                throw new ReportQualityGateException(ReportQualityGateFailureCode::PHASE_INCOMPLETE);
            }
        }
        if (($document['artifact_id'] ?? null) !== $artifactId
            || ($document['schema_version'] ?? null) !== '1.0.0'
            || ! in_array($document['status'] ?? null, ['passed', 'artifact_transferred'], true)
            || preg_match('/^[a-f0-9]{40}$/D', $document['producer_commit_sha'] ?? '') !== 1
            || ! array_is_list($document['gate_evidence'] ?? null)
            || ! is_array($document['section_hashes'] ?? null)
            || ($document['section_hashes']['gate_evidence'] ?? null) !== hash('sha256', CanonicalJson::encode($document['gate_evidence']))) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::PHASE_INCOMPLETE);
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
}
