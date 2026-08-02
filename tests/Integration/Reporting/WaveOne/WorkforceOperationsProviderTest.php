<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting\WaveOne;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
use App\BusinessModules\Features\WorkforceManagement\Reporting\AttendanceExecutionProvider;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Contracts\EffectiveAssignmentSource;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Contracts\WorkforceReportDatabasePort;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Infrastructure\DatabaseWorkforceReportAdapter;
use App\BusinessModules\Features\WorkforceManagement\Reporting\WorkforceCapacityProvider;
use App\BusinessModules\Features\WorkforceManagement\Reporting\WorkforceReportQueryService;
use App\BusinessModules\Features\WorkforceManagement\WorkforceManagementServiceProvider;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkforceOperationsProviderTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new Application(dirname(__DIR__, 4));
        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('connection')->andReturn(Mockery::mock(ConnectionInterface::class));
        $this->app->instance(DatabaseManager::class, $database);
        (new WorkforceManagementServiceProvider($this->app))->register();
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    #[Test]
    public function workforce_capacity_provider_contract(): void
    {
        self::assertInstanceOf(
            DatabaseWorkforceReportAdapter::class,
            $this->app->make(WorkforceReportDatabasePort::class),
        );
        self::assertSame(
            $this->app->make(WorkforceReportDatabasePort::class),
            $this->app->make(EffectiveAssignmentSource::class),
        );
        self::assertInstanceOf(ReportDataProvider::class, $this->app->make(WorkforceCapacityProvider::class));
        self::assertInstanceOf(ReportRowQuery::class, $this->app->make(WorkforceReportQueryService::class));
        self::assertInstanceOf(ReportDrillDownProvider::class, $this->app->make(WorkforceReportQueryService::class));
    }

    #[Test]
    public function attendance_execution_provider_contract(): void
    {
        self::assertInstanceOf(ReportDataProvider::class, $this->app->make(AttendanceExecutionProvider::class));
        self::assertInstanceOf(ReportRowQuery::class, $this->app->make(WorkforceReportQueryService::class));
        self::assertInstanceOf(ReportDrillDownProvider::class, $this->app->make(WorkforceReportQueryService::class));
    }
}
