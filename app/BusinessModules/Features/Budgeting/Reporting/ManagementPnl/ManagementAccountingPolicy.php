<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl;

use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\DTO\ManagementPnlAllocation;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\DTO\ManagementPnlClassification;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\DTO\ManagementSourceFact;
use DomainException;

final readonly class ManagementAccountingPolicy
{
    public function __construct(
        private string $policyVersion,
        private array $classifications,
        private array $allocations,
    ) {
        if (trim($policyVersion) === '') {
            throw new DomainException('management_pnl_policy_version_invalid');
        }
        foreach ($allocations as $group => $rules) {
            if (!is_string($group) || !is_array($rules) || array_sum(array_column($rules, 'basis_points')) !== 10000) {
                throw new DomainException('management_pnl_allocation_reconciliation_failed');
            }
        }
    }

    public function classify(ManagementSourceFact $fact): ManagementPnlClassification
    {
        $category = $this->classifications[$fact->sourceType.'.'.$fact->metricCode] ?? null;
        if (!is_string($category)) {
            throw new DomainException('management_pnl_classification_missing');
        }
        if (($category === 'direct_labor') !== ($fact->sourceType === 'project_labor_cost')) {
            throw new DomainException('management_pnl_direct_labor_source_invalid');
        }

        return new ManagementPnlClassification($category);
    }

    public function allocate(ManagementSourceFact $fact): array
    {
        $key = $fact->sourceType.'.'.$fact->metricCode;
        $rules = $this->allocations[$key] ?? [[
            'project_id' => $fact->projectId,
            'responsibility_center_id' => $fact->responsibilityCenterId,
            'budget_article_id' => $fact->budgetArticleId,
            'basis_points' => 10000,
        ]];
        if (array_sum(array_column($rules, 'basis_points')) !== 10000) {
            throw new DomainException('management_pnl_allocation_reconciliation_failed');
        }

        return array_map(static fn (array $rule): ManagementPnlAllocation => new ManagementPnlAllocation(
            projectId: isset($rule['project_id']) ? (int) $rule['project_id'] : $fact->projectId,
            responsibilityCenterId: isset($rule['responsibility_center_id'])
                ? (int) $rule['responsibility_center_id']
                : $fact->responsibilityCenterId,
            budgetArticleId: isset($rule['budget_article_id']) ? (int) $rule['budget_article_id'] : $fact->budgetArticleId,
            basisPoints: (int) $rule['basis_points'],
        ), $rules);
    }

    public function version(): string
    {
        return $this->policyVersion;
    }
}
