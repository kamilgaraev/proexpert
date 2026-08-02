<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\CiEvidence;

use LogicException;

final class BudgetPlanFactCiEvidenceRuntimeGuard
{
    public function assertEnabled(): void
    {
        $environment = strtolower((string) (getenv('APP_ENV') ?: ''));
        if (PHP_SAPI !== 'cli'
            || in_array($environment, ['production', 'prod'], true)
            || getenv('GITHUB_ACTIONS') !== 'true'
            || getenv('MOST_BUDGET_PLAN_FACT_CI_EVIDENCE') !== '1') {
            throw new LogicException('budget_plan_fact_ci_evidence_runtime_forbidden');
        }
    }
}
