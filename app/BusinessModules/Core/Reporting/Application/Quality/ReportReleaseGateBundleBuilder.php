<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Quality;

use App\BusinessModules\Core\Reporting\Domain\DTO\JointQG14Evidence;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQualityGateEvidence;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportReleaseGateBundle;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityEvidencePhase;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityEvidenceStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityGateFailureCode;
use DateTimeImmutable;

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
    public function build(array $gates, JointQG14Evidence $qg14Evidence, string $releaseSha, array $sources, DateTimeImmutable $generatedAt): ReportReleaseGateBundle
    {
        if (preg_match('/^[a-f0-9]{40}$/', $releaseSha) !== 1 || ! array_is_list($gates) || count($gates) !== 14 || ! $this->hasExactSourceArtifacts($sources)) {
            throw new ReportQualityGateException(ReportQualityGateFailureCode::CATALOG_COUNT_MISMATCH);
        }

        $catalog = $this->catalog()->records();

        foreach ($gates as $index => $gate) {
            $definition = $catalog[$index];
            if (! $gate instanceof ReportQualityGateEvidence
                || $gate->gate !== $definition['id']
                || $gate->phase !== ReportQualityEvidencePhase::RELEASE
                || $gate->status !== ReportQualityEvidenceStatus::PASSED
                || $gate->releaseSha !== $releaseSha
                || $gate->ownerPlan !== $definition['release_owner']
                || $gate->command !== $definition['command']
                || $gate->schemaHash->value !== $definition['schema_sha256']
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
            $gates,
            $sources,
            $generatedAt,
            ['backend' => 9, 'admin' => 4, 'joint' => 1],
        );
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
}
