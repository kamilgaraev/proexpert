<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting\WaveOne;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
use App\BusinessModules\Features\TimeTracking\Reporting\Contracts\EffectiveLaborRateSource;
use App\BusinessModules\Features\TimeTracking\Reporting\Contracts\ProjectLaborCostDatabasePort;
use App\BusinessModules\Features\TimeTracking\Reporting\Infrastructure\DatabaseProjectLaborCostAdapter;
use App\BusinessModules\Features\TimeTracking\Reporting\ProjectLaborCostOptionsService;
use App\BusinessModules\Features\TimeTracking\Reporting\ProjectLaborCostProvider;
use App\BusinessModules\Features\TimeTracking\Reporting\ProjectLaborCostQueryService;
use App\BusinessModules\Features\TimeTracking\TimeTrackingServiceProvider;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Contracts\PayrollReadinessDatabasePort;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Infrastructure\DatabasePayrollReadinessAdapter;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadinessProvider;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadinessQueryService;
use App\BusinessModules\Features\WorkforceManagement\WorkforceManagementServiceProvider;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LaborPayrollProviderTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new Application(dirname(__DIR__, 4));
        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('connection')->andReturn(Mockery::mock(ConnectionInterface::class));
        $this->app->instance(DatabaseManager::class, $database);
        (new TimeTrackingServiceProvider($this->app))->register();
        (new WorkforceManagementServiceProvider($this->app))->register();
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    #[Test]
    public function project_labor_cost_provider_contract(): void
    {
        self::assertInstanceOf(
            DatabaseProjectLaborCostAdapter::class,
            $this->app->make(ProjectLaborCostDatabasePort::class),
        );
        self::assertSame(
            $this->app->make(ProjectLaborCostDatabasePort::class),
            $this->app->make(EffectiveLaborRateSource::class),
        );
        self::assertInstanceOf(ReportDataProvider::class, $this->app->make(ProjectLaborCostProvider::class));
        self::assertInstanceOf(ReportRowQuery::class, $this->app->make(ProjectLaborCostQueryService::class));
        self::assertInstanceOf(ReportDrillDownProvider::class, $this->app->make(ProjectLaborCostQueryService::class));
        self::assertInstanceOf(ProjectLaborCostOptionsService::class, $this->app->make(ProjectLaborCostOptionsService::class));
    }

    #[Test]
    public function payroll_readiness_provider_contract(): void
    {
        self::assertInstanceOf(
            DatabasePayrollReadinessAdapter::class,
            $this->app->make(PayrollReadinessDatabasePort::class),
        );
        self::assertInstanceOf(ReportDataProvider::class, $this->app->make(PayrollReadinessProvider::class));
        self::assertInstanceOf(ReportRowQuery::class, $this->app->make(PayrollReadinessQueryService::class));
        self::assertInstanceOf(ReportDrillDownProvider::class, $this->app->make(PayrollReadinessQueryService::class));
    }
}
