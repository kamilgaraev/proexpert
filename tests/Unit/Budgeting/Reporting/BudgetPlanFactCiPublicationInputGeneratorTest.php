<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting\Reporting;

use App\BusinessModules\Features\Budgeting\Reporting\CiEvidence\BudgetPlanFactCiEvidenceRuntimeGuard;
use PHPUnit\Framework\TestCase;

final class BudgetPlanFactCiPublicationInputGeneratorTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('APP_ENV');
        putenv('GITHUB_ACTIONS');
        putenv('MOST_BUDGET_PLAN_FACT_CI_EVIDENCE');

        parent::tearDown();
    }

    public function test_ci_runtime_guard_requires_explicit_github_actions_composition(): void
    {
        putenv('APP_ENV=testing');
        putenv('GITHUB_ACTIONS=true');
        putenv('MOST_BUDGET_PLAN_FACT_CI_EVIDENCE=1');

        (new BudgetPlanFactCiEvidenceRuntimeGuard)->assertEnabled();
    }

    public function test_ci_runtime_guard_rejects_production_even_when_ci_flags_are_present(): void
    {
        putenv('APP_ENV=production');
        putenv('GITHUB_ACTIONS=true');
        putenv('MOST_BUDGET_PLAN_FACT_CI_EVIDENCE=1');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('budget_plan_fact_ci_evidence_runtime_forbidden');

        (new BudgetPlanFactCiEvidenceRuntimeGuard)->assertEnabled();
    }
}
