<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use App\BusinessModules\Core\Reporting\Application\Access\ReportSourceAccessResolver;
use App\BusinessModules\Core\Reporting\Application\Contracts\CreateReportRunAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionClock;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunStore;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunCoordinator;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSavedViewReferenceResolver;
use App\BusinessModules\Core\Reporting\ReportingCatalogServiceProvider;
use App\BusinessModules\Core\Reporting\ReportingContractsServiceProvider;
use App\BusinessModules\Core\Reporting\ReportingExecutionServiceProvider;
use App\Domain\Authorization\Services\AuthorizationService;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

final class ReportingRuntimeContainerResolutionTest extends TestCase
{
    public function test_registered_providers_resolve_run_coordinator_and_create_action_with_persistence_ports_replaced(): void
    {
        $app = new Application(dirname(__DIR__, 3));
        (new ReportingContractsServiceProvider($app))->register();
        (new ReportingExecutionServiceProvider($app))->register();
        (new ReportingCatalogServiceProvider($app))->register();

        $app->instance(AuthorizationService::class, $this->createStub(AuthorizationService::class));
        $app->instance(ReportDefinitionRegistry::class, $this->createStub(ReportDefinitionRegistry::class));
        $app->instance(ReportSavedViewReferenceResolver::class, $this->createStub(ReportSavedViewReferenceResolver::class));
        $app->instance(ReportSourceAccessResolver::class, $this->createStub(ReportSourceAccessResolver::class));
        $app->instance(ReportRunStore::class, $this->createStub(ReportRunStore::class));
        $app->instance(ReportExecutionClock::class, $this->createStub(ReportExecutionClock::class));

        self::assertInstanceOf(ReportRunCoordinator::class, $app->make(ReportRunCoordinator::class));
        self::assertInstanceOf(CreateReportRunAction::class, $app->make(CreateReportRunAction::class));
    }
}
