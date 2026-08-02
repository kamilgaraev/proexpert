<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
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

    private const IDENTITIES = [
        'project_margin' => ['budgeting.project-margin.v1', 'budgeting.project-margin.v1'],
        'budget_plan_fact' => ['budgeting.plan-fact.v1', 'budgeting.plan-fact.v1'],
        'project_labor_cost' => ['time-tracking.labor-cost.v1', 'approved-time-entry-reporting-fact.v1'],
        'payroll_readiness' => ['workforce.payroll-readiness.v1', 'payroll-readiness-snapshot.v1'],
    ];

    public function validate(
        array $components,
        int $organizationId,
        array $projectIds,
        string $periodFrom,
        string $periodTo,
        string $scenario,
        ?string $scopeHash = null,
        ?string $asOf = null,
        array $requiredCurrencies = [],
    ): array {
        $matrix = [];
        $currencies = [];

        foreach ($components as $component) {
            if (! $component instanceof ManagementPnlComponentSnapshot
                || ! in_array($component->componentCode, self::CODES, true)
                || $component->periodFrom !== $periodFrom
                || $component->periodTo !== $periodTo
                || $component->scenario !== $scenario
                || ($scopeHash !== null && $component->scopeHash !== $scopeHash)
                || $component->queryHash === null
                || preg_match('/^[a-f0-9]{64}$/D', $component->queryHash) !== 1
                || $component->definitionHash === null
                || preg_match('/^[a-f0-9]{64}$/D', $component->definitionHash) !== 1
                || ($asOf !== null && $component->asOf !== $asOf)
                || [$component->formulaVersion, $component->sourceSchemaVersion]
                    !== self::IDENTITIES[$component->componentCode]
                || $component->rowCount === null
                || $component->coverageNumerator === null
                || $component->coverageDenominator === null) {
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
                        || ! in_array($fact->projectId, $projectIds, true)))
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
        sort($requiredCurrencies, SORT_STRING);
        $actualCurrencies = array_keys($currencies);
        sort($actualCurrencies, SORT_STRING);
        if ($requiredCurrencies !== []
            && CanonicalJson::encode($actualCurrencies) !== CanonicalJson::encode($requiredCurrencies)) {
            throw new DomainException('management_pnl_component_currency_scope_mismatch');
        }
        foreach (array_keys($currencies) as $currency) {
            foreach (self::CODES as $code) {
                if (! isset($matrix[$code.':'.$currency])) {
                    throw new DomainException('management_pnl_component_cardinality_invalid');
                }
            }
        }

        return $components;
    }
}
