<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin\Reporting;

use App\BusinessModules\Core\Reporting\Application\Actions\Handlers\GetReportDrillDownHandler;
use App\BusinessModules\Core\Reporting\Application\Actions\Handlers\GetReportRowsHandler;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportExportCoordinator;
use App\BusinessModules\Core\Reporting\Domain\Contracts\CandidateReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;

final class ProductionReportingRegistryTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 6).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('r', 32)));

        return $app;
    }

    public function test_catalog_run_rows_drilldown_and_export_bindings_are_reachable(): void
    {
        $candidates = $this->app->make(CandidateReportDefinitionRegistry::class);
        $published = $this->app->make(ReportDefinitionRegistry::class);
        $bindings = $this->app->make(ReportDefinitionBindingAssembler::class)->assemble($published);

        self::assertSame(
            ['quality_defect_flow', 'safety_incident_actions', 'workforce_admission'],
            $candidates->candidateCodes(),
        );
        foreach ($published->publishedCodes() as $code) {
            self::assertNotContains('pdf', $published->published($code)->definition->formats);
            self::assertSame($code, $bindings->get($code)->code);
        }
        self::assertInstanceOf(GetReportRowsHandler::class, $this->app->make(GetReportRowsHandler::class));
        self::assertInstanceOf(GetReportDrillDownHandler::class, $this->app->make(GetReportDrillDownHandler::class));
        self::assertInstanceOf(ReportExportCoordinator::class, $this->app->make(ReportExportCoordinator::class));
    }
}
