<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Http;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\CreateReportDownloadLinkRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\CreateReportDrillDownRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\CreateReportExportRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\CreateReportRunRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\GetReportCatalogRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\GetReportRowsRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\ReportExportRouteRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\ReportRunRouteRequest;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReportFormRequestContractTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $this->app->make(Kernel::class)->bootstrap();
        $this->app->setLocale('ru');
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        $this->app->flush();

        parent::tearDown();
    }

    public function test_every_concrete_request_rejects_each_centralized_forbidden_field(): void
    {
        $forbidden = [
            'organization_id',
            'user_id',
            'permission',
            'permissions',
            'formula_version',
            'source_hash',
            'snapshot_id',
            'definition_hash',
            'query_hash',
        ];

        foreach (self::requestCases() as [$class, $method, $input, $routeName, $routeId]) {
            foreach ($forbidden as $field) {
                $exception = $this->validationException(
                    $class,
                    $method,
                    [...$input, $field => 'client-controlled'],
                    $routeName,
                    $routeId,
                );

                self::assertSame(ReportErrorCode::REPORT_REQUEST_INVALID, $exception->errorCode);
                self::assertSame(['fields' => [$field]], $exception->safeFields);
            }
        }
    }

    public function test_every_concrete_request_rejects_unknown_transport_fields(): void
    {
        foreach (self::requestCases() as [$class, $method, $input, $routeName, $routeId]) {
            $exception = $this->validationException(
                $class,
                $method,
                [...$input, 'unexpected_private_value' => 'must-not-leak'],
                $routeName,
                $routeId,
            );

            self::assertSame(ReportErrorCode::REPORT_REQUEST_INVALID, $exception->errorCode);
            self::assertSame([], $exception->safeFields);
        }
    }

    #[DataProvider('invalidRouteProvider')]
    public function test_every_id_bearing_request_rejects_an_invalid_route_ulid(
        string $class,
        string $routeName,
        string $safeField,
    ): void {
        $exception = $this->validationException(
            $class,
            self::methodFor($class),
            self::inputFor($class),
            $routeName,
            'not-a-ulid',
        );

        self::assertSame(ReportErrorCode::REPORT_REQUEST_INVALID, $exception->errorCode);
        self::assertSame(['fields' => [$safeField]], $exception->safeFields);
    }

    public function test_create_run_converts_validated_transport_to_the_existing_input_contract(): void
    {
        /** @var CreateReportRunRequest $request */
        $request = $this->validatedRequest(CreateReportRunRequest::class, 'POST', [
            'filters' => ['status' => ['operator' => 'eq', 'value' => 'active']],
            'comparison' => ['mode' => 'previous_period'],
            'as_of' => '2026-07-27T10:11:12+03:00',
            'locale' => 'ru-RU',
            'saved_view_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        ]);

        $data = $request->toData('project_margin');

        self::assertSame('project_margin', $data->reportCode);
        self::assertSame(['status' => ['operator' => 'eq', 'value' => 'active']], $data->filters->values);
        self::assertSame(['mode' => 'previous_period'], $data->comparison);
        self::assertSame('2026-07-27T10:11:12+03:00', $data->asOf->format(DATE_ATOM));
        self::assertSame('ru-RU', $data->locale);
        self::assertSame('01ARZ3NDEKTSV4RRFFQ69G5FAV', $data->savedViewId);
    }

    public function test_create_run_applies_only_documented_defaults(): void
    {
        /** @var CreateReportRunRequest $request */
        $request = $this->validatedRequest(CreateReportRunRequest::class, 'POST', [
            'filters' => [],
            'as_of' => '2026-07-27T07:11:12Z',
        ]);

        $data = $request->toData('project_margin');

        self::assertSame([], $data->comparison);
        self::assertSame('ru-RU', $data->locale);
        self::assertNull($data->savedViewId);
    }

    public function test_rows_request_returns_route_id_and_window_without_business_filters(): void
    {
        /** @var GetReportRowsRequest $request */
        $request = $this->validatedRequest(
            GetReportRowsRequest::class,
            'GET',
            ['cursor' => null, 'limit' => 75, 'sort_by' => 'total_cost', 'sort_dir' => 'desc'],
            'runId',
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        );

        $window = $request->toWindow();

        self::assertSame('01ARZ3NDEKTSV4RRFFQ69G5FAV', $request->runId());
        self::assertSame(75, $window->limit);
        self::assertNull($window->cursor);
        self::assertSame('total_cost', $window->sort->field);
        self::assertSame('desc', $window->sort->direction->value);
    }

    #[DataProvider('sealedRowsFieldProvider')]
    public function test_rows_rejects_each_sealed_business_field(string $field): void
    {
        $exception = $this->validationException(
            GetReportRowsRequest::class,
            'GET',
            [
                'cursor' => null,
                'limit' => 50,
                'sort_by' => 'total_cost',
                'sort_dir' => 'asc',
                $field => [],
            ],
            'runId',
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        );

        self::assertSame(ReportErrorCode::REPORT_REQUEST_INVALID, $exception->errorCode);
    }

    public function test_drill_down_converts_only_the_sealed_token_window(): void
    {
        /** @var CreateReportDrillDownRequest $request */
        $request = $this->validatedRequest(
            CreateReportDrillDownRequest::class,
            'POST',
            ['token' => 'signed-token', 'cursor' => null, 'limit' => 25],
            'runId',
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        );

        $drillDown = $request->toDrillDown();

        self::assertSame('signed-token', $drillDown->token);
        self::assertNull($drillDown->cursor);
        self::assertSame(25, $drillDown->limit);
    }

    public function test_export_converts_only_export_presentation_options(): void
    {
        /** @var CreateReportExportRequest $request */
        $request = $this->validatedRequest(
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
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        );

        $data = $request->toData();

        self::assertSame('xlsx', $data->format);
        self::assertSame(['project', 'margin'], $data->columns);
        self::assertSame('margin', $data->sort->field);
        self::assertSame('desc', $data->sort->direction->value);
        self::assertSame('ru-RU', $data->locale);
        self::assertSame('Europe/Moscow', $data->timezone->getName());
    }

    #[DataProvider('sealedExportFieldProvider')]
    public function test_export_rejects_each_sealed_run_field(string $field): void
    {
        $exception = $this->validationException(
            CreateReportExportRequest::class,
            'POST',
            [
                ...self::inputFor(CreateReportExportRequest::class),
                $field => [],
            ],
            'runId',
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        );

        self::assertSame(ReportErrorCode::REPORT_REQUEST_INVALID, $exception->errorCode);
    }

    public function test_download_link_uses_route_export_id_and_exact_five_minute_ttl(): void
    {
        /** @var CreateReportDownloadLinkRequest $request */
        $request = $this->validatedRequest(
            CreateReportDownloadLinkRequest::class,
            'POST',
            [],
            'exportId',
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        );

        $data = $request->toData();

        self::assertSame('01ARZ3NDEKTSV4RRFFQ69G5FAV', $data->exportId);
        self::assertSame(300, $data->ttlSeconds);
    }

    public static function invalidRouteProvider(): array
    {
        return [
            'run show/retry/cancel contract' => [ReportRunRouteRequest::class, 'runId', 'run_id'],
            'export show/retry/cancel contract' => [ReportExportRouteRequest::class, 'exportId', 'export_id'],
            'rows' => [GetReportRowsRequest::class, 'runId', 'run_id'],
            'drill-down' => [CreateReportDrillDownRequest::class, 'runId', 'run_id'],
            'export creation' => [CreateReportExportRequest::class, 'runId', 'run_id'],
            'download' => [CreateReportDownloadLinkRequest::class, 'exportId', 'export_id'],
        ];
    }

    public static function sealedRowsFieldProvider(): array
    {
        return [['filters'], ['comparison'], ['as_of']];
    }

    public static function sealedExportFieldProvider(): array
    {
        return [['filters'], ['comparison'], ['as_of'], ['cursor'], ['limit'], ['token']];
    }

    private static function requestCases(): array
    {
        return [
            [GetReportCatalogRequest::class, 'GET', [], null, null],
            [ReportRunRouteRequest::class, 'GET', [], 'runId', '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
            [ReportExportRouteRequest::class, 'GET', [], 'exportId', '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
            [CreateReportRunRequest::class, 'POST', self::inputFor(CreateReportRunRequest::class), null, null],
            [GetReportRowsRequest::class, 'GET', self::inputFor(GetReportRowsRequest::class), 'runId', '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
            [CreateReportDrillDownRequest::class, 'POST', self::inputFor(CreateReportDrillDownRequest::class), 'runId', '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
            [CreateReportExportRequest::class, 'POST', self::inputFor(CreateReportExportRequest::class), 'runId', '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
            [CreateReportDownloadLinkRequest::class, 'POST', [], 'exportId', '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
        ];
    }

    private static function methodFor(string $class): string
    {
        return $class === GetReportRowsRequest::class ? 'GET' : 'POST';
    }

    private static function inputFor(string $class): array
    {
        return match ($class) {
            CreateReportRunRequest::class => [
                'filters' => [],
                'as_of' => '2026-07-27T07:11:12Z',
            ],
            GetReportRowsRequest::class => [
                'cursor' => null,
                'limit' => 50,
                'sort_by' => 'total_cost',
                'sort_dir' => 'asc',
            ],
            CreateReportDrillDownRequest::class => [
                'token' => 'signed-token',
                'cursor' => null,
                'limit' => 50,
            ],
            CreateReportExportRequest::class => [
                'format' => 'csv',
                'columns' => ['project'],
                'sort_by' => 'project',
                'sort_dir' => 'asc',
                'locale' => 'ru-RU',
                'timezone' => 'Europe/Moscow',
            ],
            default => [],
        };
    }

    private function validationException(
        string $class,
        string $method,
        array $input,
        ?string $routeName,
        ?string $routeId,
    ): ReportContractException {
        try {
            $this->validatedRequest($class, $method, $input, $routeName, $routeId);
        } catch (ReportContractException $exception) {
            return $exception;
        }

        self::fail('Expected report validation failure.');
    }

    private function validatedRequest(
        string $class,
        string $method,
        array $input,
        ?string $routeName = null,
        ?string $routeId = null,
    ): FormRequest {
        /** @var FormRequest $request */
        $request = $class::create('/api/v1/admin/reports', $method, $input);
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make('redirect'));

        if ($routeName !== null) {
            $route = new Route([$method], '/api/v1/admin/reports', static fn (): null => null);
            $route->bind($request);
            $route->setParameter($routeName, $routeId);
            $request->setRouteResolver(static fn () => $route);
        }

        $request->validateResolved();

        return $request;
    }
}
