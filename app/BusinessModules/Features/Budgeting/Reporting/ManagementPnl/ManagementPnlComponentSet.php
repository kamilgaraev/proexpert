<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl;

use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\DTO\ManagementPnlComponentSnapshot;
use DomainException;

final readonly class ManagementPnlComponentSet
{
    private const CODES = [
        'project_margin',
        'budget_plan_fact',
        'project_labor_cost',
        'payroll_readiness',
    ];

    public function validate(
        array $components,
        int $organizationId,
        array $projectIds,
        string $periodFrom,
        string $periodTo,
        string $scenario,
    ): array {
        $matrix = [];
        $currencies = [];

        foreach ($components as $component) {
            if (!$component instanceof ManagementPnlComponentSnapshot
                || !in_array($component->componentCode, self::CODES, true)
                || $component->periodFrom !== $periodFrom
                || $component->periodTo !== $periodTo
                || $component->scenario !== $scenario) {
                throw new DomainException('management_pnl_component_scope_mismatch');
            }

            $identity = $component->componentCode.':'.$component->currency;
            if (isset($matrix[$identity])) {
                throw new DomainException('management_pnl_component_snapshot_duplicate');
            }
            $matrix[$identity] = true;
            $currencies[$component->currency] = true;

            foreach ($component->facts as $fact) {
                if ($fact->sourceSnapshotId !== $component->snapshotId
                    || $fact->sourceType !== $component->componentCode
                    || $fact->organizationId !== $organizationId
                    || ($projectIds !== [] && ($fact->projectId === null
                        || !in_array($fact->projectId, $projectIds, true)))
                    || $fact->period < $periodFrom
                    || $fact->period > $periodTo
                    || $fact->scenario !== $scenario
                    || $fact->currency !== $component->currency) {
                    throw new DomainException('management_pnl_component_fact_scope_mismatch');
                }
            }
        }

        if ($currencies === []) {
            throw new DomainException('management_pnl_component_facts_missing');
        }
        foreach (array_keys($currencies) as $currency) {
            foreach (self::CODES as $code) {
                if (!isset($matrix[$code.':'.$currency])) {
                    throw new DomainException('management_pnl_component_cardinality_invalid');
                }
            }
        }

        return $components;
    }
}
