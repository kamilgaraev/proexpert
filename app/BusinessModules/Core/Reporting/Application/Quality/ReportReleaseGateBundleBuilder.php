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

    public function __construct(private readonly ?ReportPlatformGateCatalog $catalog = null)
    {
    }

    /** @param list<ReportQualityGateEvidence> $gates */
    public function build(array $gates, JointQG14Evidence $qg14Evidence, string $releaseSha, array $sources, DateTimeImmutable $generatedAt): ReportReleaseGateBundle
    {
        if (preg_match('/^[a-f0-9]{40}$/', $releaseSha) !== 1 || ! array_is_list($gates) || count($gates) !== 14) {
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
                || ! $this->matchesCount($gate, $definition)) {
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
}
