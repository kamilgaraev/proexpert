<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization;

use App\BusinessModules\Core\MultiOrganization\Reporting\IntercompanyContractFlowCandidateContract;
use App\BusinessModules\Core\MultiOrganization\Reporting\IntercompanyContractFlowPublishedRuntimeBindingRegistrar;
use App\BusinessModules\Core\MultiOrganization\Reporting\IntercompanyContractFlowReportBindingFactory;
use App\BusinessModules\Core\MultiOrganization\Reporting\Listeners\ProjectHoldingAllocationFacts;
use App\BusinessModules\Core\MultiOrganization\Reporting\Providers\IntercompanyContractFlowsReportProvider;
use App\BusinessModules\Core\MultiOrganization\Reporting\Queries\IntercompanyContractFlowRowQuery;
use App\BusinessModules\Core\MultiOrganization\Reporting\Readiness\IntercompanyContractFlowReadinessProbe;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingAllocationCheckpointSourceAssembler;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingAllocationFactProjector;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\IntercompanyContractFlowOptionsService;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\IntercompanyContractFlowSnapshotMaterializer;
use App\BusinessModules\Core\Payments\Events\PaymentDocumentPaid;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use Illuminate\Support\ServiceProvider;
use App\BusinessModules\Core\MultiOrganization\Services\MultiOrganizationHelperService;
use App\BusinessModules\Core\MultiOrganization\Contracts\OrganizationScopeInterface;
use App\BusinessModules\Core\MultiOrganization\Contracts\ContractorSharingInterface;
use App\BusinessModules\Core\MultiOrganization\Services\SingleOrganizationScope;
use App\BusinessModules\Core\MultiOrganization\Services\SingleContractorAccess;
use App\BusinessModules\Core\MultiOrganization\Services\ContextAwareOrganizationScope;
use App\BusinessModules\Core\MultiOrganization\Services\HierarchicalContractorSharing;
use App\BusinessModules\Core\MultiOrganization\Listeners\AutoAddParentToProject;
use App\Models\Organization;
use App\Modules\Core\AccessController;
use Illuminate\Support\Facades\Event;

class MultiOrganizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(IntercompanyContractFlowCandidateContract::class);
        $this->app->scoped(HoldingAllocationFactProjector::class);
        $this->app->scoped(HoldingAllocationCheckpointSourceAssembler::class);
        $this->app->scoped(IntercompanyContractFlowOptionsService::class);
        $this->app->scoped(IntercompanyContractFlowSnapshotMaterializer::class);
        $this->app->scoped(IntercompanyContractFlowsReportProvider::class);
        $this->app->scoped(IntercompanyContractFlowRowQuery::class);
        $this->app->scoped(IntercompanyContractFlowReadinessProbe::class);
        $this->app->scoped(IntercompanyContractFlowReportBindingFactory::class);
        $this->app->scoped(IntercompanyContractFlowPublishedRuntimeBindingRegistrar::class);

        $this->app->singleton(MultiOrganizationHelperService::class, function ($app) {
            return new MultiOrganizationHelperService($app->make(\App\Services\Landing\MultiOrganizationService::class));
        });

        $this->app->singleton(ContextAwareOrganizationScope::class);
        $this->app->singleton(HierarchicalContractorSharing::class);

        $this->app->bind(
            OrganizationScopeInterface::class,
            SingleOrganizationScope::class
        );

        $this->app->bind(
            ContractorSharingInterface::class,
            SingleContractorAccess::class
        );

        $this->app->extend(OrganizationScopeInterface::class, function ($default, $app) {
            $orgId = request()->attributes->get('current_organization_id');

            if ($orgId && $this->isMultiOrgActiveForOrganization($orgId)) {
                return $app->make(ContextAwareOrganizationScope::class);
            }

            return $default;
        });

        $this->app->extend(ContractorSharingInterface::class, function ($default, $app) {
            $orgId = request()->attributes->get('current_organization_id');

            if ($orgId && $this->isMultiOrgActiveForOrganization($orgId)) {
                return $app->make(HierarchicalContractorSharing::class);
            }

            return $default;
        });
    }

    public function boot(): void
    {
        $this->app->afterResolving(
            ReportDefinitionBindingAssembler::class,
            function (ReportDefinitionBindingAssembler $assembler): void {
                $this->app
                    ->make(IntercompanyContractFlowPublishedRuntimeBindingRegistrar::class)
                    ->register($assembler);
            },
        );
        Event::listen(
            \App\Events\ProjectCreated::class,
            AutoAddParentToProject::class
        );
        Event::listen(PaymentDocumentPaid::class, ProjectHoldingAllocationFacts::class);
    }

    private function isMultiOrgActiveForOrganization(int $orgId): bool
    {
        $org = Organization::find($orgId);

        if (!$org) {
            return false;
        }

        $accessController = app(AccessController::class);

        return $accessController->hasModuleAccess($orgId, 'multi-organization') &&
            ($org->is_holding || $org->parent_organization_id);
    }
}
