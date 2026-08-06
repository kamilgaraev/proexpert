<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Services;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\Models\ContractPerformanceAct;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\ProductionAcceptanceBackfillLedger;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class ProductionAcceptanceCoverageGapRecorder
{
    public function record(
        ContractPerformanceAct $act,
        string $transition,
        string $reason,
        CarbonImmutable $recognizedAt,
    ): void {
        $organizationId = (int) $act->contract?->organization_id;
        $projectId = (int) $act->project_id;
        if ($organizationId < 1 || $projectId < 1 || ! in_array($transition, ['acceptance', 'reversal'], true)) {
            throw new InvalidArgumentException('production_acceptance_gap_scope_invalid');
        }

        $reason = 'runtime_'.$transition.'_'.$reason;
        $identity = [
            'organization_id' => $organizationId,
            'performance_act_id' => (int) $act->id,
            'project_id' => $projectId,
            'reason' => $reason,
            'recognized_at' => $recognizedAt->format(DATE_ATOM),
            'status' => 'unprovable',
        ];
        $sourceHash = hash('sha256', CanonicalJson::encode($identity));

        ProductionAcceptanceBackfillLedger::query()->firstOrCreate(
            [
                'organization_id' => $organizationId,
                'source_hash' => $sourceHash,
            ],
            [
                ...$identity,
                'recorded_at' => CarbonImmutable::now(),
            ],
        );
    }
}
