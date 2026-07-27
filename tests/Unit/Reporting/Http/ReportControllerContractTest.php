<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Http;

use App\BusinessModules\Core\Reporting\Application\Access\OrganizationReportScopeResolver;
use App\BusinessModules\Core\Reporting\Application\Access\ReportActorLoader;
use App\BusinessModules\Core\Reporting\Application\Access\ReportExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogView;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDownloadLink;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuality;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\ReportCatalogController;
use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\ReportDrillDownController;
use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\ReportExportController;
use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\ReportRowsController;
use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\ReportRunController;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\CreateReportDownloadLinkRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\CreateReportDrillDownRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\CreateReportExportRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\CreateReportRunRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\GetReportCatalogRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\GetReportRowsRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\ReportExportRouteRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\ReportRunRouteRequest;
use App\Services\Logging\Context\RequestContext;
use DateTimeImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\FakeReportingActions;
use Tests\Support\Reporting\ReportDefinitionBuilder;
use Tests\Support\Reporting\ReportExportBuilder;
use Tests\Support\Reporting\ReportRunBuilder;

final class ReportControllerContractTest extends TestCase
{
    private const RUN_ID = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
    private const EXPORT_ID = '01ARZ3NDEKTSV4RRFFQ69G5FAW';

    private Application $app;
    private ReportExecutionContextFactory $contexts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $this->app->make(Kernel::class)->bootstrap();
        $loader = new class implements ReportActorLoader {
            public function loadActive(int $actorId): ReportActor
            {
                return new ReportActor($actorId, 'active', [
                    'reports.download',
                    'reports.export',
                    'reports.run',
                    'reports.view',
                ]);
            }
        };
        $requestContext = new RequestContext();
        $requestContext->setCorrelationId('report-controller-test');
        $this->contexts = new ReportExecutionContextFactory(
            $loader,
            new OrganizationReportScopeResolver(),
            $requestContext,
        );
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        $this->app->flush();
        parent::tearDown();
    }

    public function test_catalog_rows_and_drill_down_forward_exact_request_objects_and_shape_responses(): void
    {
        $catalog = new ReportCatalogView(
            '1.0.0',
            new Sha256Hash(str_repeat('9', 64)),
            [(new ReportDefinitionBuilder())->contractVersion('1.0.0')->payload()],
        );
        $page = new ReportPage(
            [['row_key' => 'row-1', 'value' => 7]],
            ['value' => 7],
            ReportFreshnessStatus::FRESH,
            new ReportQuality(
                ReportQualityStatus::COMPLETE,
                null,
                [],
                0,
                ReportReconciliationStatus::MATCHED,
                [],
                [],
            ),
            'next',
            25,
            true,
            new ReportWindowSort('value', ReportSortDirection::DESC),
        );
        $drill = new ReportDrillDownResult([['row_key' => 'detail-1']], null, []);
        $fake = new FakeReportingActions([
            'catalog' => $catalog,
            'rows' => $page,
            'drillDown' => $drill,
        ]);

        $catalogRequest = $this->request(GetReportCatalogRequest::class, 'GET');
        $rowsRequest = $this->request(
            GetReportRowsRequest::class,
            'GET',
            ['cursor' => null, 'limit' => 25, 'sort_by' => 'value', 'sort_dir' => 'desc'],
            'runId',
            self::RUN_ID,
        );
        $drillRequest = $this->request(
            CreateReportDrillDownRequest::class,
            'POST',
            ['token' => 'signed', 'cursor' => null, 'limit' => 10],
            'runId',
            self::RUN_ID,
        );

        $catalogResponse = (new ReportCatalogController($this->contexts, $fake->catalogAction))($catalogRequest);
        $rowsResponse = (new ReportRowsController($this->contexts, $fake->rowsAction))($rowsRequest);
        $drillResponse = (new ReportDrillDownController($this->contexts, $fake->drillDownAction))($drillRequest);

        self::assertSame(200, $catalogResponse->getStatusCode());
        self::assertSame('1.0.0', $catalogResponse->getData(true)['data']['contract_version']);
        self::assertSame(200, $rowsResponse->getStatusCode());
        self::assertSame(
            ['limit' => 25, 'next_cursor' => 'next', 'has_more' => true, 'sort' => ['field' => 'value', 'direction' => 'desc']],
            $rowsResponse->getData(true)['meta'],
        );
        self::assertSame([['row_key' => 'detail-1']], $drillResponse->getData(true)['data']['rows']);
        self::assertEquals($rowsRequest->toWindow(), $fake->calls['rows'][0][2]);
        self::assertEquals($drillRequest->toDrillDown(), $fake->calls['drillDown'][0][2]);
        self::assertSame(self::RUN_ID, $fake->calls['rows'][0][1]);
        self::assertCount(1, $fake->calls['catalog']);
        self::assertCount(1, $fake->calls['rows']);
        self::assertCount(1, $fake->calls['drillDown']);
    }

    public function test_all_run_endpoints_forward_exact_data_id_and_key_with_dto_status_and_headers(): void
    {
        $queued = (new ReportRunBuilder())->id(self::RUN_ID)->queued();
        $ready = (new ReportRunBuilder())->id(self::RUN_ID)->ready();
        $fake = new FakeReportingActions([
            'createRun' => $ready,
            'getRun' => $ready,
            'retryRun' => $queued,
            'cancelRun' => $queued,
        ]);
        $controller = new ReportRunController(
            $this->contexts,
            $fake->createRunAction,
            $fake->getRunAction,
            $fake->retryRunAction,
            $fake->cancelRunAction,
        );
        $create = $this->request(
            CreateReportRunRequest::class,
            'POST',
            ['filters' => [], 'as_of' => '2026-07-27T07:11:12Z'],
            headers: ['Idempotency-Key' => str_repeat('a', 32)],
        );
        $route = $this->request(ReportRunRouteRequest::class, 'GET', [], 'runId', self::RUN_ID);

        $created = $controller->store($create, 'project_margin');
        $shown = $controller->show($route);
        $retried = $controller->retry($route);
        $cancelled = $controller->cancel($route);

        self::assertSame(201, $created->getStatusCode());
        self::assertSame('/api/v1/admin/reports/runs/'.self::RUN_ID, $created->headers->get('Location'));
        self::assertSame(200, $shown->getStatusCode());
        self::assertSame(202, $retried->getStatusCode());
        self::assertSame('1', $retried->headers->get('Retry-After'));
        self::assertSame(202, $cancelled->getStatusCode());
        self::assertSame('project_margin', $fake->calls['createRun'][0][1]->reportCode);
        self::assertSame(str_repeat('a', 32), $fake->calls['createRun'][0][2]->value);
        self::assertSame(self::RUN_ID, $fake->calls['getRun'][0][1]);
        self::assertCount(1, $fake->calls['createRun']);
        self::assertCount(1, $fake->calls['getRun']);
        self::assertCount(1, $fake->calls['retryRun']);
        self::assertCount(1, $fake->calls['cancelRun']);
    }

    public function test_all_export_endpoints_forward_exact_data_id_and_key_with_dto_status_and_headers(): void
    {
        $queued = (new ReportExportBuilder())->id(self::EXPORT_ID)->runId(self::RUN_ID)->queued();
        $ready = (new ReportExportBuilder())->id(self::EXPORT_ID)->runId(self::RUN_ID)->ready();
        $link = new ReportDownloadLink(
            'https://storage.example/reports/export',
            'version-1',
            new DateTimeImmutable('2026-07-27T10:00:00Z'),
            new DateTimeImmutable('2026-07-27T10:05:00Z'),
        );
        $fake = new FakeReportingActions([
            'createExport' => $ready,
            'getExport' => $ready,
            'retryExport' => $queued,
            'cancelExport' => $queued,
            'downloadLink' => $link,
        ]);
        $controller = new ReportExportController(
            $this->contexts,
            $fake->createExportAction,
            $fake->getExportAction,
            $fake->retryExportAction,
            $fake->cancelExportAction,
            $fake->downloadLinkAction,
        );
        $create = $this->request(
            CreateReportExportRequest::class,
            'POST',
            [
                'format' => 'xlsx',
                'columns' => ['project', 'margin'],
                'sort_by' => 'margin',
                'sort_dir' => 'desc',
                'locale' => 'ru-RU',
                'timezone' => 'Europe/Moscow',
            ],
            'runId',
            self::RUN_ID,
            ['Idempotency-Key' => str_repeat('b', 32)],
        );
        $route = $this->request(ReportExportRouteRequest::class, 'GET', [], 'exportId', self::EXPORT_ID);
        $download = $this->request(CreateReportDownloadLinkRequest::class, 'POST', [], 'exportId', self::EXPORT_ID);

        $created = $controller->store($create);
        $shown = $controller->show($route);
        $retried = $controller->retry($route);
        $cancelled = $controller->cancel($route);
        $downloaded = $controller->downloadLink($download);

        self::assertSame(201, $created->getStatusCode());
        self::assertSame('/api/v1/admin/reports/exports/'.self::EXPORT_ID, $created->headers->get('Location'));
        self::assertSame(200, $shown->getStatusCode());
        self::assertSame(202, $retried->getStatusCode());
        self::assertSame(202, $cancelled->getStatusCode());
        self::assertSame('https://storage.example/reports/export', $downloaded->getData(true)['data']['url']);
        self::assertSame(self::RUN_ID, $fake->calls['createExport'][0][1]);
        self::assertSame('xlsx', $fake->calls['createExport'][0][2]->format);
        self::assertSame(str_repeat('b', 32), $fake->calls['createExport'][0][3]->value);
        self::assertSame(self::EXPORT_ID, $fake->calls['downloadLink'][0][1]->exportId);
        self::assertCount(1, $fake->calls['createExport']);
        self::assertCount(1, $fake->calls['getExport']);
        self::assertCount(1, $fake->calls['retryExport']);
        self::assertCount(1, $fake->calls['cancelExport']);
        self::assertCount(1, $fake->calls['downloadLink']);
    }

    public function test_invalid_idempotency_key_fails_before_action_and_action_exception_is_not_swallowed(): void
    {
        $queued = (new ReportRunBuilder())->id(self::RUN_ID)->queued();
        $fake = new FakeReportingActions(['createRun' => $queued]);
        $controller = new ReportRunController(
            $this->contexts,
            $fake->createRunAction,
            $fake->getRunAction,
            $fake->retryRunAction,
            $fake->cancelRunAction,
        );
        $invalid = $this->request(
            CreateReportRunRequest::class,
            'POST',
            ['filters' => [], 'as_of' => '2026-07-27T07:11:12Z'],
            headers: ['Idempotency-Key' => 'invalid'],
        );

        try {
            $controller->store($invalid, 'project_margin');
            self::fail('Expected invalid idempotency key.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_IDEMPOTENCY_KEY_INVALID, $exception->errorCode);
            self::assertSame([], $fake->calls['createRun']);
        }

        $expected = ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
        $fake->willThrow('getRun', $expected);
        $route = $this->request(ReportRunRouteRequest::class, 'GET', [], 'runId', self::RUN_ID);

        $this->expectExceptionObject($expected);
        $controller->show($route);
    }

    private function request(
        string $class,
        string $method,
        array $input = [],
        ?string $routeName = null,
        ?string $routeId = null,
        array $headers = [],
    ): FormRequest {
        /** @var FormRequest $request */
        $request = $class::create('/api/v1/admin/reports', $method, $input);
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make('redirect'));
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }
        if ($routeName !== null) {
            $route = new Route([$method], '/api/v1/admin/reports', static fn (): null => null);
            $route->bind($request);
            $route->setParameter($routeName, $routeId);
            $request->setRouteResolver(static fn () => $route);
        }
        $request->setUserResolver(static fn (?string $guard = null): object => new class {
            public function getAuthIdentifier(): int
            {
                return 17;
            }
        });
        $request->attributes->set('current_organization_id', 41);
        $request->attributes->set('organization_timezone', 'Europe/Moscow');
        $request->validateResolved();

        return $request;
    }
}
