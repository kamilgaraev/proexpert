<?php

declare(strict_types=1);

namespace Tests\Unit\Acting;

use App\Models\CompletedWork;
use App\Models\EstimateItem;
use App\Services\Acting\ActingActWizardService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class OfficialFormsWorkflowContractTest extends TestCase
{
    public function test_official_exports_use_approved_sources_and_shared_vat_calculation(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3).'/app/BusinessModules/Features/BudgetEstimates/Services/Export/OfficialFormsExportService.php'
        );

        self::assertStringNotContainsString('* 0.20', $source);
        self::assertGreaterThanOrEqual(4, substr_count($source, 'vatAmountFromGross('));
        self::assertStringContainsString(
            'journalEntriesForExport($journal, $from, $to, $estimateId, true)',
            $source,
        );
        self::assertGreaterThanOrEqual(2, substr_count($source, "->where('is_approved', true)"));
        self::assertGreaterThanOrEqual(4, substr_count($source, 'assertActApprovedForExport('));
        self::assertMatchesRegularExpression(
            "/performanceActs\(\).*?where\('is_approved', true\).*?where\('project_id'/s",
            $source,
        );
        $prepareKs2Offset = strpos($source, 'protected function prepareKS2Data');
        $estimateOffset = strpos($source, '$estimate = $contract->estimate;', $prepareKs2Offset);
        $vatOffset = strpos($source, '$vatAmount =', $prepareKs2Offset);
        self::assertIsInt($estimateOffset);
        self::assertIsInt($vatOffset);
        self::assertTrue($estimateOffset < $vatOffset);
    }

    public function test_spreadsheet_exports_are_bounded_and_extended_report_uses_one_pass_aggregation(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3).'/app/BusinessModules/Features/BudgetEstimates/Services/Export/OfficialFormsExportService.php'
        );

        self::assertGreaterThanOrEqual(2, substr_count($source, 'assertSpreadsheetExportSize('));
        self::assertStringContainsString('aggregateExtendedReportEntries(', $source);
        self::assertStringNotContainsString('$totalEntries = $entries->count();', $source);
        self::assertStringNotContainsString('$totalWorkers = $entries->sum(', $source);
    }

    public function test_completed_work_act_line_uses_a_business_title(): void
    {
        $work = new CompletedWork(['notes' => 'Служебное примечание']);
        $work->setRelation('estimateItem', new EstimateItem(['name' => 'Монтаж монолитных перекрытий']));
        $work->setRelation('workType', null);
        $work->setRelation('journalEntry', null);

        $reflection = new ReflectionClass(ActingActWizardService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('resolveCompletedWorkTitle');

        self::assertSame('Монтаж монолитных перекрытий', $method->invoke($service, $work));
    }
}
