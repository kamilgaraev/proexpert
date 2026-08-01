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
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

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

    #[DataProvider('invalidRouteProvider')]
    public function test_every_id_bearing_request_rejects_a_lowercase_route_ulid(
        string $class,
        string $routeName,
        string $safeField,
    ): void {
        $exception = $this->validationException(
            $class,
            self::methodFor($class),
            self::inputFor($class),
            $routeName,
            '01arz3ndektsv4rrffq69g5fav',
        );

        self::assertSame(ReportErrorCode::REPORT_REQUEST_INVALID, $exception->errorCode);
        self::assertSame(['fields' => [$safeField]], $exception->safeFields);
    }

    public function test_lowercase_saved_view_id_is_rejected_before_run_data_conversion(): void
    {
        try {
            /** @var CreateReportRunRequest $request */
            $request = $this->validatedRequest(CreateReportRunRequest::class, 'POST', [
                'filters' => [],
                'as_of' => '2026-07-27T07:11:12Z',
                'saved_view_id' => '01arz3ndektsv4rrffq69g5fav',
            ]);
            $request->toData();
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_REQUEST_INVALID, $exception->errorCode);
            self::assertSame(['fields' => ['saved_view_id']], $exception->safeFields);

            return;
        } catch (\InvalidArgumentException $exception) {
            self::fail('Lowercase saved-view ULID escaped validation: '.$exception::class);
        }

        self::fail('Expected lowercase saved-view ULID validation failure.');
    }

    public function test_lowercase_export_id_is_rejected_before_download_data_conversion(): void
    {
        try {
            /** @var CreateReportDownloadLinkRequest $request */
            $request = $this->validatedRequest(
                CreateReportDownloadLinkRequest::class,
                'POST',
                [],
                'exportId',
                '01arz3ndektsv4rrffq69g5fav',
            );
            $request->toData();
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_REQUEST_INVALID, $exception->errorCode);
            self::assertSame(['fields' => ['export_id']], $exception->safeFields);

            return;
        } catch (\InvalidArgumentException $exception) {
            self::fail('Lowercase export ULID escaped validation: '.$exception::class);
        }

        self::fail('Expected lowercase export ULID validation failure.');
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

        $data = $request->toData();

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

        $data = $request->toData();

        self::assertSame([], $data->comparison);
        self::assertSame('ru-RU', $data->locale);
        self::assertNull($data->savedViewId);
    }

    #[DataProvider('validRfc3339Provider')]
    public function test_create_run_accepts_strict_rfc3339_variants(
        string $wireValue,
        string $normalizedValue,
    ): void {
        /** @var CreateReportRunRequest $request */
        $request = $this->validatedRequest(CreateReportRunRequest::class, 'POST', [
            'filters' => [],
            'as_of' => $wireValue,
        ]);

        self::assertSame(
            $normalizedValue,
            $request->toData()->asOf->format('Y-m-d\TH:i:s.vP'),
        );
    }

    #[DataProvider('invalidRfc3339Provider')]
    public function test_create_run_rejects_malformed_or_timezone_less_timestamps(string $wireValue): void
    {
        $exception = $this->validationException(CreateReportRunRequest::class, 'POST', [
            'filters' => [],
            'as_of' => $wireValue,
        ], null, null);

        self::assertSame(ReportErrorCode::REPORT_REQUEST_INVALID, $exception->errorCode);
        self::assertSame(['fields' => ['as_of']], $exception->safeFields);
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

    public function test_production_style_json_and_query_transport_accepts_each_request_family(): void
    {
        foreach (self::requestCases() as [$class, $method, $input, $routeName, $routeId]) {
            $query = $method === 'GET' ? $input : [];
            $body = $method === 'POST' ? $input : [];
            if ($class === GetReportRowsRequest::class) {
                $query['cursor'] = 'cursor-token';
            }

            $request = $this->productionRequest(
                $class,
                $method,
                $query,
                $body,
                $routeName,
                $routeId,
            );

            self::assertInstanceOf($class, $request);
        }
    }

    public function test_post_json_fields_are_rejected_when_duplicated_through_query(): void
    {
        $cases = [
            [CreateReportRunRequest::class, self::inputFor(CreateReportRunRequest::class), ['locale' => 'en-US'], null],
            [
                CreateReportDrillDownRequest::class,
                self::inputFor(CreateReportDrillDownRequest::class),
                ['limit' => 'client-query-value'],
                'runId',
            ],
            [
                CreateReportExportRequest::class,
                self::inputFor(CreateReportExportRequest::class),
                ['format' => 'client-query-value'],
                'runId',
            ],
        ];

        foreach ($cases as [$class, $body, $query, $routeName]) {
            $exception = $this->productionValidationException(
                $class,
                'POST',
                $query,
                $body,
                $routeName,
                $routeName === null ? null : '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            );

            self::assertSame(ReportErrorCode::REPORT_REQUEST_INVALID, $exception->errorCode);
            self::assertSame(['fields' => [array_key_first($query)]], $exception->safeFields);
            self::assertStringNotContainsString(
                'client-query-value',
                json_encode($exception->safeFields, JSON_THROW_ON_ERROR),
            );
        }
    }

    public function test_get_rows_rejects_json_body_even_when_the_same_query_field_is_valid(): void
    {
        $exception = $this->productionValidationException(
            GetReportRowsRequest::class,
            'GET',
            [...self::inputFor(GetReportRowsRequest::class), 'cursor' => 'cursor-token'],
            ['limit' => 99],
            'runId',
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        );

        self::assertSame(ReportErrorCode::REPORT_REQUEST_INVALID, $exception->errorCode);
        self::assertSame(['fields' => ['limit']], $exception->safeFields);
        self::assertSame('{"fields":["limit"]}', json_encode($exception->safeFields, JSON_THROW_ON_ERROR));
    }

    public function test_actual_json_body_and_query_reject_all_forbidden_fields_without_values(): void
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

        foreach ($forbidden as $field) {
            $jsonException = $this->productionValidationException(
                CreateReportRunRequest::class,
                'POST',
                [],
                [...self::inputFor(CreateReportRunRequest::class), $field => 'private-json-value'],
                null,
                null,
            );
            $queryException = $this->productionValidationException(
                GetReportCatalogRequest::class,
                'GET',
                [$field => 'private-query-value'],
                [],
                null,
                null,
            );

            self::assertSame(['fields' => [$field]], $jsonException->safeFields);
            self::assertSame($jsonException->safeFields, $queryException->safeFields);
            self::assertStringNotContainsString(
                'private',
                json_encode([$jsonException->safeFields, $queryException->safeFields], JSON_THROW_ON_ERROR),
            );
        }
    }

    public function test_actual_json_body_and_query_reject_unknown_fields_without_echoing_names_or_values(): void
    {
        $jsonException = $this->productionValidationException(
            CreateReportRunRequest::class,
            'POST',
            [],
            [...self::inputFor(CreateReportRunRequest::class), 'private_json_key' => 'private-json-value'],
            null,
            null,
        );
        $queryException = $this->productionValidationException(
            GetReportCatalogRequest::class,
            'GET',
            ['private_query_key' => 'private-query-value'],
            [],
            null,
            null,
        );

        self::assertSame([], $jsonException->safeFields);
        self::assertSame([], $queryException->safeFields);
        self::assertStringNotContainsString(
            'private',
            json_encode([$jsonException->safeFields, $queryException->safeFields], JSON_THROW_ON_ERROR),
        );
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

    public static function validRfc3339Provider(): array
    {
        return [
            'UTC Z' => ['2026-07-27T07:11:12Z', '2026-07-27T07:11:12.000+00:00'],
            'numeric offset' => ['2026-07-27T10:11:12+03:00', '2026-07-27T10:11:12.000+03:00'],
            'fractional UTC' => ['2026-07-27T07:11:12.123Z', '2026-07-27T07:11:12.123+00:00'],
        ];
    }

    public static function invalidRfc3339Provider(): array
    {
        return [
            'timezone missing' => ['2026-07-27T07:11:12'],
            'calendar date invalid' => ['2026-02-30T07:11:12Z'],
            'fraction empty' => ['2026-07-27T07:11:12.Z'],
            'trailing garbage' => ['2026-07-27T07:11:12Z-private'],
        ];
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
        if ($class === CreateReportRunRequest::class && $routeName === null) {
            $routeName = 'reportCode';
            $routeId = 'project_margin';
        }

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

    private function productionValidationException(
        string $class,
        string $method,
        array $query,
        array $jsonBody,
        ?string $routeName,
        ?string $routeId,
    ): ReportContractException {
        try {
            $this->productionRequest($class, $method, $query, $jsonBody, $routeName, $routeId);
        } catch (ReportContractException $exception) {
            return $exception;
        }

        self::fail('Expected production-style report validation failure.');
    }

    private function productionRequest(
        string $class,
        string $method,
        array $query,
        array $jsonBody,
        ?string $routeName,
        ?string $routeId,
    ): FormRequest {
        if ($class === CreateReportRunRequest::class && $routeName === null) {
            $routeName = 'reportCode';
            $routeId = 'project_margin';
        }

        $uri = '/api/v1/admin/reports';
        if ($query !== []) {
            $uri .= '?'.http_build_query($query);
        }

        $base = SymfonyRequest::create(
            $uri,
            $method,
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            $jsonBody === [] ? null : json_encode($jsonBody, JSON_THROW_ON_ERROR),
        );

        /** @var FormRequest $request */
        $request = $class::createFromBase($base);
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
