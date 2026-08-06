<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceReadiness;
use App\Support\Reporting\ReportSourceReadinessFactory;
use App\Support\Reporting\StableReportingSourceView;

final readonly class ProjectPortfolioHealthReadinessProbe implements ReportSourceReadinessProbe
{
    public function __construct(private ProjectPortfolioHealthSourceReader $sources, private ReportSourceReadinessFactory $readiness, private StableReportingSourceView $stableView) {}

    public function supports(ReportDefinition $definition): bool
    {
        return $definition->code === BudgetingPortfolioProjectionService::HEALTH_CODE;
    }

    public function reportCodes(): array
    {
        return [BudgetingPortfolioProjectionService::HEALTH_CODE];
    }

    public function inspect(ReportExecutionContext $context, ReportQuery $query): ReportSourceReadiness
    {
        return $this->stableView->capture(function () use ($context, $query): ReportSourceReadiness {
            $read = $this->sources->read($context, $query);
            $tuple = (new ProjectPortfolioHealthSourceTupleAssembler)->assemble($read['components'], $read['gaps']);
            $measurement = new ProjectPortfolioHealthReadinessMeasurement($tuple->gaps);
            $eligible = $measurement->eligible();
            $projected = $measurement->projected();

            return $this->readiness->make($eligible, $projected, $measurement->gapCount(), 0, $tuple->watermark);
        }, 5);
    }
}
