<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ProjectCommandCenter\Services;

use App\BusinessModules\Features\ProjectCommandCenter\DTO\ProjectCommandCenterData;
use App\Domain\Project\ValueObjects\ProjectContext;
use App\Models\Project;
use Carbon\CarbonImmutable;

final class ProjectCommandCenterService
{
    public function __construct(
        private readonly ProjectProblemCollector $problemCollector,
        private readonly ProjectFinanceHealthBuilder $financeHealthBuilder,
        private readonly ProjectDeliveryBuilder $deliveryBuilder,
        private readonly ProjectAnalyticsBuilder $analyticsBuilder,
    ) {
    }

    public function build(
        Project $project,
        ProjectContext $projectContext,
        string $period,
        ?string $dateFrom,
        ?string $dateTo,
    ): ProjectCommandCenterData {
        $generatedAt = CarbonImmutable::now();
        $data = ProjectCommandCenterData::empty(
            project: $project,
            projectContext: $projectContext,
            period: $period,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            generatedAt: $generatedAt,
        );

        $problems = $this->problemCollector->collect(
            project: $project,
            projectContext: $projectContext,
            now: $generatedAt,
        );

        $finance = $this->financeHealthBuilder->build($project, $projectContext, $generatedAt)->toArray();
        $delivery = $this->deliveryBuilder->build($project, $projectContext, $generatedAt, $problems)->toArray();

        return $data
            ->withProblems($problems)
            ->withFinance($finance)
            ->withDelivery($delivery)
            ->withAnalytics($this->analyticsBuilder->fromFacts(
                finance: $finance,
                asOf: $generatedAt,
            ));
    }
}
