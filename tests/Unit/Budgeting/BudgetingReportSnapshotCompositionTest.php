<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceSnapshotStore;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceSnapshotStreamingStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportSourceSnapshotStore;
use App\BusinessModules\Core\Reporting\ReportingContractsServiceProvider;
use App\BusinessModules\Features\Budgeting\BudgetingServiceProvider;
use App\BusinessModules\Features\Budgeting\Contracts\BudgetingReportSourceCloseStore;
use App\BusinessModules\Features\Budgeting\Contracts\PlanFactSourceSnapshotReport;
use App\BusinessModules\Features\Budgeting\Contracts\ProjectMarginSourceSnapshotReport;
use App\BusinessModules\Features\Budgeting\DTOs\BudgetingReportSourceClose;
use App\BusinessModules\Features\Budgeting\DTOs\CreateBudgetingReportSourceClose;
use App\BusinessModules\Features\Budgeting\Services\BudgetPlanFactReportBindingFactory;
use App\BusinessModules\Features\Budgeting\Services\PlanFactReportSourceSnapshotAdapter;
use App\BusinessModules\Features\Budgeting\Services\PlanFactSourceSnapshotWriter;
use App\BusinessModules\Features\Budgeting\Services\ProjectMarginReportBindingFactory;
use App\BusinessModules\Features\Budgeting\Services\ProjectMarginReportSourceSnapshotAdapter;
use App\BusinessModules\Features\Budgeting\Services\ProjectMarginSourceSnapshotWriter;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

final class BudgetingReportSnapshotCompositionTest extends TestCase
{
    public function test_budgeting_snapshot_services_resolve_without_procurement_provider(): void
    {
        $app = new Container;
        (new ReportingContractsServiceProvider($app))->register();
        (new BudgetingServiceProvider($app))->register();

        $app->bind(PlanFactSourceSnapshotReport::class, static fn (): PlanFactSourceSnapshotReport => new class implements PlanFactSourceSnapshotReport
        {
            public function reportForProjectScope(array $input, array $projectIds): array
            {
                return [];
            }

            public function drillDownForProjectScope(array $input, array $projectIds): array
            {
                return [];
            }
        });
        $app->bind(ProjectMarginSourceSnapshotReport::class, static fn (): ProjectMarginSourceSnapshotReport => new class implements ProjectMarginSourceSnapshotReport
        {
            public function reportForProjectScope(array $input, array $projectIds): array
            {
                return [];
            }

            public function drillDownForProjectScope(array $input, array $projectIds): array
            {
                return [];
            }
        });
        $app->bind(BudgetingReportSourceCloseStore::class, static fn (): BudgetingReportSourceCloseStore => new class implements BudgetingReportSourceCloseStore
        {
            public function createApproved(CreateBudgetingReportSourceClose $request): BudgetingReportSourceClose
            {
                throw new \LogicException;
            }

            public function find(string $closeId): ?BudgetingReportSourceClose
            {
                return null;
            }
        });

        self::assertInstanceOf(EloquentReportSourceSnapshotStore::class, $app->make(ReportSourceSnapshotStore::class));
        self::assertInstanceOf(EloquentReportSourceSnapshotStore::class, $app->make(ReportSourceSnapshotStreamingStore::class));
        self::assertInstanceOf(PlanFactSourceSnapshotWriter::class, $app->make(PlanFactSourceSnapshotWriter::class));
        self::assertInstanceOf(PlanFactReportSourceSnapshotAdapter::class, $app->make(PlanFactReportSourceSnapshotAdapter::class));
        self::assertInstanceOf(BudgetPlanFactReportBindingFactory::class, $app->make(BudgetPlanFactReportBindingFactory::class));
        self::assertInstanceOf(ProjectMarginSourceSnapshotWriter::class, $app->make(ProjectMarginSourceSnapshotWriter::class));
        self::assertInstanceOf(ProjectMarginReportSourceSnapshotAdapter::class, $app->make(ProjectMarginReportSourceSnapshotAdapter::class));
        self::assertInstanceOf(ProjectMarginReportBindingFactory::class, $app->make(ProjectMarginReportBindingFactory::class));
    }
}
