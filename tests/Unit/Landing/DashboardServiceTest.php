<?php

declare(strict_types=1);

namespace Tests\Unit\Landing;

use App\Repositories\Landing\OrganizationDashboardRepositoryInterface;
use App\Services\Landing\DashboardService;
use App\Services\Logging\LoggingService;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use Mockery;
use PHPUnit\Framework\TestCase;

class DashboardServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        $loader = new FileLoader(new Filesystem(), dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'lang');
        $translator = new Translator($loader, 'ru');

        $container->instance('app', new class {
            public function getLocale(): string
            {
                return 'ru';
            }
        });
        $container->instance('translator', $translator);
        $container->instance('config', new class {
            public function get(string $key, mixed $default = null): mixed
            {
                return $key === 'app.fallback_locale' ? 'ru' : $default;
            }
        });

        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function test_dashboard_adds_russian_status_labels_without_replacing_machine_keys(): void
    {
        $repository = Mockery::mock(OrganizationDashboardRepositoryInterface::class);
        $repository->shouldReceive('getFinancialSummary')->once()->with(42)->andReturn([
            'balance' => 0,
            'credits_this_month' => 0,
            'debits_this_month' => 0,
        ]);
        $repository->shouldReceive('getProjectSummary')->once()->with(42)->andReturn([
            'total' => 3,
            'active' => 2,
            'completed' => 1,
        ]);
        $repository->shouldReceive('getContractSummary')->once()->with(42)->andReturn([
            'total' => 2,
            'active' => 1,
            'draft' => 1,
            'completed' => 0,
            'total_amount' => 0,
        ]);
        $repository->shouldReceive('getWorkMaterialSummary')->once()->with(42)->andReturn([
            'works' => [],
            'materials' => [],
        ]);
        $repository->shouldReceive('getActSummary')->once()->with(42)->andReturn([
            'total' => 0,
            'approved' => 0,
            'total_amount' => 0,
        ]);
        $repository->shouldReceive('getTeamSummary')->once()->with(42)->andReturn([
            'total' => 0,
            'by_roles' => [],
        ]);
        $repository->shouldReceive('getTeamDetails')->once()->with(42)->andReturn([]);
        $repository->shouldReceive('getTimeseries')->with('projects', 'month', 42)->andReturn([
            'labels' => [],
            'values' => [],
        ]);
        $repository->shouldReceive('getTimeseries')->with('contracts', 'month', 42)->andReturn([
            'labels' => [],
            'values' => [],
        ]);
        $repository->shouldReceive('getTimeseries')->with('completed_works', 'month', 42)->andReturn([
            'labels' => [],
            'values' => [],
        ]);
        $repository->shouldReceive('getMonthlyBalance')->once()->with(42)->andReturn([
            'labels' => [],
            'values' => [],
        ]);
        $repository->shouldReceive('getStatusDistribution')->once()->with('projects', 42)->andReturn([
            'active' => 2,
            'completed' => 1,
        ]);
        $repository->shouldReceive('getStatusDistribution')->once()->with('contracts', 42)->andReturn([
            'active' => 1,
            'draft' => 1,
            'unexpected_status' => 1,
        ]);

        $logging = Mockery::mock(LoggingService::class)->shouldIgnoreMissing();

        $dashboard = new DashboardService($repository, $logging);
        $data = $dashboard->getDashboardData(42);

        $this->assertSame([
            'active' => 2,
            'completed' => 1,
        ], $data['charts']['projects_status']);
        $this->assertSame([
            'active' => 'Активные',
            'completed' => 'Завершенные',
        ], $data['charts']['status_labels']['projects']);
        $this->assertSame([
            'active' => 'Активные',
            'draft' => 'Черновики',
            'unexpected_status' => 'Другой статус',
        ], $data['charts']['status_labels']['contracts']);
    }
}
