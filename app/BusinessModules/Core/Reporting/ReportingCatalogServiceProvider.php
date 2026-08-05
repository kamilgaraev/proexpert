<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting;

use App\BusinessModules\Core\Reporting\Application\Access\ReportAccessService;
use App\BusinessModules\Core\Reporting\Application\Catalog\GetReportCatalogHandler;
use App\BusinessModules\Core\Reporting\Application\Catalog\ImmutableReportDefinitionBindingAssembler;
use App\BusinessModules\Core\Reporting\Application\Catalog\StrictReportDefinitionCandidateValidator;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionClock;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportCatalogAction;
use App\BusinessModules\Core\Reporting\Application\SavedViews\ReportSavedViewVersionHasher;
use App\BusinessModules\Core\Reporting\Application\SavedViews\StoredReportSavedViewReferenceResolver;
use App\BusinessModules\Core\Reporting\Application\Subscriptions\ReportSubscriptionCoordinator;
use App\BusinessModules\Core\Reporting\Application\Subscriptions\ReportSubscriptionScheduleCalculator;
use App\BusinessModules\Core\Reporting\Domain\Contracts\CandidateReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\InAppReportSubscriptionNotifier;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportCatalogMetadataRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionCandidateValidator;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportPublicationFeatureStore;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportPublicationRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSavedViewReferenceResolver;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSavedViewStore;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSavedViewVersionStore;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSchedulingCapabilityRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionCursorCodec;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionDeliveryDispatcher;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionDeliveryStore;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionEventRecorder;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionStore;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportWorkspacePreferencesStore;
use App\BusinessModules\Core\Reporting\Domain\DTO\LoadedReportManifest;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBindingMap;
use App\BusinessModules\Core\Reporting\Infrastructure\Audit\LogReportSubscriptionEventRecorder;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\BuiltinPublishedReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\BuiltinReportCatalogMetadataRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\BuiltinReportSchedulingCapabilityRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\CompositePublishedReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\CompositeReportCatalogMetadataRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\CompositeReportSchedulingCapabilityRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\DatabasePublishedReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\DatabaseReportCatalogMetadataRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\DatabaseReportSchedulingCapabilityRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\YamlCandidateReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\YamlReportManifestLoader;
use App\BusinessModules\Core\Reporting\Infrastructure\Cursors\SignedReportSavedViewCursorCodec;
use App\BusinessModules\Core\Reporting\Infrastructure\Cursors\SignedReportSubscriptionCursorCodec;
use App\BusinessModules\Core\Reporting\Infrastructure\Notifications\PersistedInAppReportSubscriptionNotifier;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportSavedViewStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportSavedViewVersionStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportSubscriptionDeliveryStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportSubscriptionStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportWorkspacePreferencesStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Publication\EloquentReportPublicationFeatureStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Publication\EloquentReportPublicationRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Queue\LaravelReportSubscriptionDeliveryDispatcher;
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactBuiltinPublishedReport;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectMarginBuiltinPublishedReport;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\InventoryRiskBuiltinPublishedReport;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\InventoryRiskCandidateContract;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\ProcurementCycleBuiltinPublishedReport;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\ProcurementCycleCandidateContract;
use App\BusinessModules\Features\Procurement\Reporting\Award\SupplierAwardBuiltinPublishedReport;
use App\BusinessModules\Features\Procurement\Reporting\Award\SupplierAwardCandidateContract;
use App\BusinessModules\Features\Procurement\Reporting\Supply\SupplyReliabilityBuiltinPublishedReport;
use App\BusinessModules\Features\Procurement\Reporting\Supply\SupplyReliabilityCandidateContract;
use App\BusinessModules\Features\TimeTracking\Reporting\ProjectLaborCostBuiltinPublishedReport;
use App\BusinessModules\Features\TimeTracking\Reporting\ProjectLaborCostCandidateContract;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadinessBuiltinPublishedReport;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadinessCandidateContract;
use App\BusinessModules\Features\WorkforceManagement\Reporting\WorkforceCapacityBuiltinPublishedReport;
use App\BusinessModules\Features\WorkforceManagement\Reporting\WorkforceCapacityCandidateContract;
use App\BusinessModules\Features\WorkforceManagement\Reporting\AttendanceExecutionBuiltinPublishedReport;
use App\BusinessModules\Features\WorkforceManagement\Reporting\AttendanceExecutionCandidateContract;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\QualityDefectFlowBuiltinPublishedReport;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\QualityDefectFlowCandidateContract;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\WorkforceAdmissionBuiltinPublishedReport;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\WorkforceAdmissionCandidateContract;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\SafetyIncidentActionsBuiltinPublishedReport;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\SafetyIncidentActionsCandidateContract;
use App\BusinessModules\Features\ScheduleManagement\Reporting\BaselineScheduleVarianceBuiltinPublishedReport;
use App\BusinessModules\Features\ScheduleManagement\Reporting\BaselineScheduleVarianceCandidateContract;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class ReportingCatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            LoadedReportManifest::class,
            fn (Application $app): LoadedReportManifest => $app
                ->make(YamlReportManifestLoader::class)
                ->loadManagement(
                    __DIR__.'/resources/management-catalog.v1.yaml',
                    __DIR__.'/resources/management-catalog.v1.schema.json',
                ),
        );
        $this->app->singleton(ReportPublicationRegistry::class, fn (Application $app): EloquentReportPublicationRegistry => new EloquentReportPublicationRegistry(
            $app['db']->connection(),
            $app->make(\App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory::class),
        ));
        $this->app->singleton(ReportPublicationFeatureStore::class, fn (Application $app): EloquentReportPublicationFeatureStore => new EloquentReportPublicationFeatureStore(
            $app['db']->connection(),
        ));
        $this->app->singleton(BudgetPlanFactBuiltinPublishedReport::class, fn (Application $app): BudgetPlanFactBuiltinPublishedReport => new BudgetPlanFactBuiltinPublishedReport(
            $app->make(\App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactCandidateContract::class),
        ));
        $this->app->singleton(ProjectMarginBuiltinPublishedReport::class, fn (Application $app): ProjectMarginBuiltinPublishedReport => new ProjectMarginBuiltinPublishedReport(
            $app->make(\App\BusinessModules\Features\Budgeting\Reporting\ProjectMarginCandidateContract::class),
        ));
        $this->app->singleton(BaselineScheduleVarianceCandidateContract::class);
        $this->app->singleton(BaselineScheduleVarianceBuiltinPublishedReport::class, fn (Application $app): BaselineScheduleVarianceBuiltinPublishedReport => new BaselineScheduleVarianceBuiltinPublishedReport(
            $app->make(BaselineScheduleVarianceCandidateContract::class),
        ));
        $this->app->singleton(ProjectLaborCostBuiltinPublishedReport::class, fn (Application $app): ProjectLaborCostBuiltinPublishedReport => new ProjectLaborCostBuiltinPublishedReport(
            $app->make(ProjectLaborCostCandidateContract::class),
        ));
        $this->app->singleton(PayrollReadinessBuiltinPublishedReport::class, fn (Application $app): PayrollReadinessBuiltinPublishedReport => new PayrollReadinessBuiltinPublishedReport(
            $app->make(PayrollReadinessCandidateContract::class),
        ));
        $this->app->singleton(WorkforceCapacityBuiltinPublishedReport::class, fn (Application $app): WorkforceCapacityBuiltinPublishedReport => new WorkforceCapacityBuiltinPublishedReport(
            $app->make(WorkforceCapacityCandidateContract::class),
        ));
        $this->app->singleton(AttendanceExecutionCandidateContract::class);
        $this->app->singleton(AttendanceExecutionBuiltinPublishedReport::class, fn (Application $app): AttendanceExecutionBuiltinPublishedReport => new AttendanceExecutionBuiltinPublishedReport(
            $app->make(AttendanceExecutionCandidateContract::class),
        ));
        $this->app->singleton(QualityDefectFlowCandidateContract::class);
        $this->app->singleton(QualityDefectFlowBuiltinPublishedReport::class, fn (Application $app): QualityDefectFlowBuiltinPublishedReport => new QualityDefectFlowBuiltinPublishedReport(
            $app->make(QualityDefectFlowCandidateContract::class),
        ));
        $this->app->singleton(WorkforceAdmissionCandidateContract::class);
        $this->app->singleton(WorkforceAdmissionBuiltinPublishedReport::class, fn (Application $app): WorkforceAdmissionBuiltinPublishedReport => new WorkforceAdmissionBuiltinPublishedReport(
            $app->make(WorkforceAdmissionCandidateContract::class),
        ));
        $this->app->singleton(SafetyIncidentActionsCandidateContract::class);
        $this->app->singleton(SafetyIncidentActionsBuiltinPublishedReport::class, fn (Application $app): SafetyIncidentActionsBuiltinPublishedReport => new SafetyIncidentActionsBuiltinPublishedReport(
            $app->make(SafetyIncidentActionsCandidateContract::class),
        ));
        $this->app->singleton(ProcurementCycleCandidateContract::class);
        $this->app->singleton(ProcurementCycleBuiltinPublishedReport::class, fn (Application $app): ProcurementCycleBuiltinPublishedReport => new ProcurementCycleBuiltinPublishedReport(
            $app->make(ProcurementCycleCandidateContract::class),
        ));
        $this->app->singleton(SupplierAwardCandidateContract::class);
        $this->app->singleton(SupplierAwardBuiltinPublishedReport::class, fn (Application $app): SupplierAwardBuiltinPublishedReport => new SupplierAwardBuiltinPublishedReport(
            $app->make(SupplierAwardCandidateContract::class),
        ));
        $this->app->singleton(SupplyReliabilityCandidateContract::class);
        $this->app->singleton(SupplyReliabilityBuiltinPublishedReport::class, fn (Application $app): SupplyReliabilityBuiltinPublishedReport => new SupplyReliabilityBuiltinPublishedReport(
            $app->make(SupplyReliabilityCandidateContract::class),
        ));
        $this->app->singleton(InventoryRiskCandidateContract::class);
        $this->app->singleton(InventoryRiskBuiltinPublishedReport::class, fn (Application $app): InventoryRiskBuiltinPublishedReport => new InventoryRiskBuiltinPublishedReport(
            $app->make(InventoryRiskCandidateContract::class),
        ));
        $this->app->singleton(
            BuiltinPublishedReportDefinitionRegistry::class,
            fn (Application $app): BuiltinPublishedReportDefinitionRegistry => new BuiltinPublishedReportDefinitionRegistry(
                $app->make(ProjectMarginBuiltinPublishedReport::class),
                $app->make(BudgetPlanFactBuiltinPublishedReport::class),
                $app->make(BaselineScheduleVarianceBuiltinPublishedReport::class),
                $app->make(ProjectLaborCostBuiltinPublishedReport::class),
                $app->make(PayrollReadinessBuiltinPublishedReport::class),
                $app->make(WorkforceCapacityBuiltinPublishedReport::class),
                $app->make(ProcurementCycleBuiltinPublishedReport::class),
                $app->make(SupplierAwardBuiltinPublishedReport::class),
                $app->make(SupplyReliabilityBuiltinPublishedReport::class),
                $app->make(InventoryRiskBuiltinPublishedReport::class),
                $app->make(AttendanceExecutionBuiltinPublishedReport::class),
                $app->make(QualityDefectFlowBuiltinPublishedReport::class),
                $app->make(SafetyIncidentActionsBuiltinPublishedReport::class),
                $app->make(WorkforceAdmissionBuiltinPublishedReport::class),
            ),
        );
        $this->app->singleton(
            ReportDefinitionRegistry::class,
            fn (Application $app): CompositePublishedReportDefinitionRegistry => new CompositePublishedReportDefinitionRegistry(
                $app->make(BuiltinPublishedReportDefinitionRegistry::class),
                $app->make(DatabasePublishedReportDefinitionRegistry::class),
            ),
        );
        $this->app->singleton(BuiltinReportCatalogMetadataRegistry::class, fn (Application $app): BuiltinReportCatalogMetadataRegistry => new BuiltinReportCatalogMetadataRegistry($app->make(ProjectMarginBuiltinPublishedReport::class), $app->make(BudgetPlanFactBuiltinPublishedReport::class), $app->make(BaselineScheduleVarianceBuiltinPublishedReport::class), $app->make(ProjectLaborCostBuiltinPublishedReport::class), $app->make(PayrollReadinessBuiltinPublishedReport::class), $app->make(WorkforceCapacityBuiltinPublishedReport::class), $app->make(ProcurementCycleBuiltinPublishedReport::class), $app->make(SupplierAwardBuiltinPublishedReport::class), $app->make(SupplyReliabilityBuiltinPublishedReport::class), $app->make(InventoryRiskBuiltinPublishedReport::class), $app->make(AttendanceExecutionBuiltinPublishedReport::class), $app->make(QualityDefectFlowBuiltinPublishedReport::class), $app->make(SafetyIncidentActionsBuiltinPublishedReport::class), $app->make(WorkforceAdmissionBuiltinPublishedReport::class)));
        $this->app->singleton(ReportCatalogMetadataRegistry::class, fn (Application $app): CompositeReportCatalogMetadataRegistry => new CompositeReportCatalogMetadataRegistry($app->make(BuiltinReportCatalogMetadataRegistry::class), $app->make(DatabaseReportCatalogMetadataRegistry::class)));
        $this->app->singleton(BuiltinReportSchedulingCapabilityRegistry::class, fn (Application $app): BuiltinReportSchedulingCapabilityRegistry => new BuiltinReportSchedulingCapabilityRegistry($app->make(ProjectMarginBuiltinPublishedReport::class), $app->make(BudgetPlanFactBuiltinPublishedReport::class), $app->make(BaselineScheduleVarianceBuiltinPublishedReport::class), $app->make(ProjectLaborCostBuiltinPublishedReport::class), $app->make(PayrollReadinessBuiltinPublishedReport::class), $app->make(WorkforceCapacityBuiltinPublishedReport::class), $app->make(ProcurementCycleBuiltinPublishedReport::class), $app->make(SupplierAwardBuiltinPublishedReport::class), $app->make(SupplyReliabilityBuiltinPublishedReport::class), $app->make(InventoryRiskBuiltinPublishedReport::class), $app->make(AttendanceExecutionBuiltinPublishedReport::class), $app->make(QualityDefectFlowBuiltinPublishedReport::class), $app->make(SafetyIncidentActionsBuiltinPublishedReport::class), $app->make(WorkforceAdmissionBuiltinPublishedReport::class)));
        $this->app->singleton(ReportSchedulingCapabilityRegistry::class, fn (Application $app): CompositeReportSchedulingCapabilityRegistry => new CompositeReportSchedulingCapabilityRegistry($app->make(BuiltinReportSchedulingCapabilityRegistry::class), $app->make(DatabaseReportSchedulingCapabilityRegistry::class)));
        $this->app->singleton(GetReportCatalogAction::class, GetReportCatalogHandler::class);
        $this->app->singleton(
            ReportWorkspacePreferencesStore::class,
            EloquentReportWorkspacePreferencesStore::class,
        );
        $this->app->singleton(ReportSavedViewStore::class, EloquentReportSavedViewStore::class);
        $this->app->singleton(ReportSavedViewVersionStore::class, EloquentReportSavedViewVersionStore::class);
        $this->app->singleton(ReportSavedViewVersionHasher::class);
        $this->app->singleton(
            ReportSavedViewReferenceResolver::class,
            StoredReportSavedViewReferenceResolver::class,
        );
        $this->app->singleton(ReportSubscriptionStore::class, EloquentReportSubscriptionStore::class);
        $this->app->singleton(ReportSubscriptionDeliveryStore::class, EloquentReportSubscriptionDeliveryStore::class);
        $this->app->singleton(ReportSubscriptionDeliveryDispatcher::class, LaravelReportSubscriptionDeliveryDispatcher::class);
        $this->app->singleton(
            ReportSubscriptionCursorCodec::class,
            fn (Application $app): SignedReportSubscriptionCursorCodec => new SignedReportSubscriptionCursorCodec(
                (string) config('app.key'),
                $app->make(ReportExecutionClock::class),
            ),
        );
        $this->app->singleton(
            ReportSubscriptionCoordinator::class,
            fn (Application $app): ReportSubscriptionCoordinator => new ReportSubscriptionCoordinator(
                $app->make(ReportSubscriptionStore::class),
                $app->make(ReportSubscriptionDeliveryStore::class),
                $app->make(ReportSubscriptionDeliveryDispatcher::class),
                null,
                $app->make(ReportDefinitionRegistry::class),
                $app->make(ReportSchedulingCapabilityRegistry::class),
                $app->make(ReportSavedViewStore::class),
                $app->make(ReportAccessService::class),
                $app->make(ReportSubscriptionScheduleCalculator::class),
                $app->make(ReportExecutionClock::class),
            ),
        );
        $this->app->singleton(InAppReportSubscriptionNotifier::class, PersistedInAppReportSubscriptionNotifier::class);
        $this->app->singleton(ReportSubscriptionEventRecorder::class, LogReportSubscriptionEventRecorder::class);
        $this->app->singleton(SignedReportSavedViewCursorCodec::class, fn (): SignedReportSavedViewCursorCodec => new SignedReportSavedViewCursorCodec((string) config('app.key')));
        $this->app->singleton(
            CandidateReportDefinitionRegistry::class,
            YamlCandidateReportDefinitionRegistry::class,
        );
        $this->app->singleton(
            ReportDefinitionBindingAssembler::class,
            ImmutableReportDefinitionBindingAssembler::class,
        );
        $this->app->singleton(
            ReportDefinitionCandidateValidator::class,
            StrictReportDefinitionCandidateValidator::class,
        );
        $this->app->singleton(
            ReportDefinitionBindingMap::class,
            fn (Application $app): ReportDefinitionBindingMap => $app
                ->make(ReportDefinitionBindingAssembler::class)
                ->assemble($app->make(ReportDefinitionRegistry::class)),
        );
    }
}
