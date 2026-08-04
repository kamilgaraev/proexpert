<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting;

use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactCandidateContract;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectMarginCandidateContract;
use App\BusinessModules\Features\Budgeting\Services\BudgetingReportOptionsService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class BudgetingReportOptionsFormulaIsolationTest extends TestCase
{
    public function test_each_report_has_a_distinct_close_formula_and_options_filter_by_it(): void
    {
        self::assertNotSame(
            BudgetPlanFactCandidateContract::FORMULA_VERSION,
            ProjectMarginCandidateContract::FORMULA_VERSION,
        );

        $file = (new ReflectionClass(BudgetingReportOptionsService::class))->getFileName();
        self::assertIsString($file);
        $source = file_get_contents($file);
        self::assertIsString($source);
        self::assertStringContainsString("->where('report_code', \$reportCode)", $source);
        self::assertStringContainsString("->where('formula_version', \$formulaVersion)", $source);
    }
}
