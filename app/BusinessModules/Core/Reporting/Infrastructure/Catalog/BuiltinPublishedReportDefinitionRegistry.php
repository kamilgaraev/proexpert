<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Catalog;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Core\MultiOrganization\Reporting\IntercompanyContractFlowBuiltinPublishedReport;
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactBuiltinPublishedReport;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\PortfolioLiquidityBuiltinPublishedReport;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\ProjectEvmControlBuiltinPublishedReport;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectMarginBuiltinPublishedReport;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\WipCompletionForecastBuiltinPublishedReport;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\InventoryRiskBuiltinPublishedReport;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\ProcurementCycleBuiltinPublishedReport;
use App\BusinessModules\Features\Procurement\Reporting\Award\SupplierAwardBuiltinPublishedReport;
use App\BusinessModules\Features\Procurement\Reporting\Supply\SupplyReliabilityBuiltinPublishedReport;
use App\BusinessModules\Features\TimeTracking\Reporting\ProjectLaborCostBuiltinPublishedReport;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadinessBuiltinPublishedReport;
use App\BusinessModules\Features\WorkforceManagement\Reporting\WorkforceCapacityBuiltinPublishedReport;
use App\BusinessModules\Features\WorkforceManagement\Reporting\AttendanceExecutionBuiltinPublishedReport;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\QualityDefectFlowBuiltinPublishedReport;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\WorkforceAdmissionBuiltinPublishedReport;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\SafetyIncidentActionsBuiltinPublishedReport;
use App\BusinessModules\Features\ScheduleManagement\Reporting\BaselineScheduleVarianceBuiltinPublishedReport;
use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\HandoverReadinessBuiltinPublishedReport;
use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\ContractorScorecardBuiltinPublishedReport;
use App\Services\Customer\Reporting\Sla\CustomerSlaBuiltinPublishedReport;

final readonly class BuiltinPublishedReportDefinitionRegistry implements ReportDefinitionRegistry
{
    /** @var array<string, PublishedReportDefinition> */
    private array $definitions;

    public function __construct(
        ProjectMarginBuiltinPublishedReport $projectMargin,
        BudgetPlanFactBuiltinPublishedReport $budgetPlanFact,
        PortfolioLiquidityBuiltinPublishedReport $portfolioLiquidity,
        ProjectEvmControlBuiltinPublishedReport $projectEvmControl,
        WipCompletionForecastBuiltinPublishedReport $wipCompletionForecast,
        BaselineScheduleVarianceBuiltinPublishedReport $baselineScheduleVariance,
        ProjectLaborCostBuiltinPublishedReport $projectLaborCost,
        PayrollReadinessBuiltinPublishedReport $payrollReadiness,
        WorkforceCapacityBuiltinPublishedReport $workforceCapacity,
        ProcurementCycleBuiltinPublishedReport $procurementCycle,
        SupplierAwardBuiltinPublishedReport $supplierAward,
        SupplyReliabilityBuiltinPublishedReport $supplyReliability,
        InventoryRiskBuiltinPublishedReport $inventoryRisk,
        AttendanceExecutionBuiltinPublishedReport $attendanceExecution,
        QualityDefectFlowBuiltinPublishedReport $qualityDefectFlow,
        SafetyIncidentActionsBuiltinPublishedReport $safetyIncidentActions,
        WorkforceAdmissionBuiltinPublishedReport $workforceAdmission,
        HandoverReadinessBuiltinPublishedReport $handoverReadiness,
        ContractorScorecardBuiltinPublishedReport $contractorScorecard,
        CustomerSlaBuiltinPublishedReport $customerSla,
        IntercompanyContractFlowBuiltinPublishedReport $intercompanyContractFlow,
    ) {
        $byCode = [];
        foreach ([$projectMargin->definition(), $budgetPlanFact->definition(), $portfolioLiquidity->definition(), $projectEvmControl->definition(), $wipCompletionForecast->definition(), $baselineScheduleVariance->definition(), $projectLaborCost->definition(), $payrollReadiness->definition(), $workforceCapacity->definition(), $procurementCycle->definition(), $supplierAward->definition(), $supplyReliability->definition(), $inventoryRisk->definition(), $attendanceExecution->definition(), $qualityDefectFlow->definition(), $safetyIncidentActions->definition(), $workforceAdmission->definition(), $handoverReadiness->definition(), $contractorScorecard->definition(), $customerSla->definition(), $intercompanyContractFlow->definition()] as $definition) {
            $byCode[$definition->code] = $definition;
        }
        ksort($byCode, SORT_STRING);
        $this->definitions = $byCode;
    }

    public function published(string $code): PublishedReportDefinition
    {
        return $this->definitions[$code]
            ?? throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
    }

    public function publishedCodes(): array
    {
        return array_keys($this->definitions);
    }

    public function manifestSha256(): Sha256Hash
    {
        return new Sha256Hash(hash('sha256', CanonicalJson::encode(array_map(
            static fn (PublishedReportDefinition $definition): array => [
                'code' => $definition->code,
                'definition_sha256' => $definition->definitionHash->value,
            ],
            array_values($this->definitions),
        ))));
    }
}
