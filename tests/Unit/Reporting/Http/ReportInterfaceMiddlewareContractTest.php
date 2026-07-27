<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Http;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\CreateReportRunRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\GetReportCatalogRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\ReportFormRequest;
use App\Domain\Authorization\Http\Middleware\InterfaceMiddleware;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response;

final class ReportInterfaceMiddlewareContractTest extends TestCase
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

    #[DataProvider('validRequestProvider')]
    public function test_server_derived_interface_passes_real_middleware_and_report_validation(
        string $requestClass,
        string $method,
        array $query,
        array $body,
    ): void {
        $request = $this->reportRequest($requestClass, $method, $query, $body);
        $legacyInterface = null;

        $response = $this->middleware()->handle(
            $request,
            static function (ReportFormRequest $request) use (&$legacyInterface): Response {
                $request->validateResolved();
                $legacyInterface = $request->input('current_interface');

                return new Response(status: 204);
            },
            'admin',
        );

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('admin', $legacyInterface);
    }

    public function test_client_json_interface_is_rejected_after_middleware_overwrites_legacy_input(): void
    {
        $request = $this->reportRequest(
            CreateReportRunRequest::class,
            'POST',
            [],
            [
                ...self::validRunBody(),
                'current_interface' => 'mobile',
            ],
        );

        $exception = $this->middlewareValidationException($request);

        self::assertSame(ReportErrorCode::REPORT_REQUEST_INVALID, $exception->errorCode);
        self::assertSame([], $exception->safeFields);
    }

    public function test_client_query_interface_is_rejected_after_middleware_overwrites_legacy_input(): void
    {
        $request = $this->reportRequest(
            GetReportCatalogRequest::class,
            'GET',
            ['current_interface' => 'mobile'],
            [],
        );

        $exception = $this->middlewareValidationException($request);

        self::assertSame(ReportErrorCode::REPORT_REQUEST_INVALID, $exception->errorCode);
        self::assertSame([], $exception->safeFields);
    }

    public function test_direct_report_request_cannot_treat_current_interface_as_server_derived(): void
    {
        $request = $this->reportRequest(
            CreateReportRunRequest::class,
            'POST',
            [],
            [
                ...self::validRunBody(),
                'current_interface' => 'admin',
            ],
        );

        $exception = $this->validationException($request);

        self::assertSame(ReportErrorCode::REPORT_REQUEST_INVALID, $exception->errorCode);
        self::assertSame([], $exception->safeFields);
    }

    public function test_client_transport_cannot_spoof_internal_interface_markers(): void
    {
        $request = $this->reportRequest(
            CreateReportRunRequest::class,
            'POST',
            [],
            [
                ...self::validRunBody(),
                'current_interface' => 'mobile',
                '__most_interface_client_supplied' => false,
                '__most_interface_server_derived' => true,
            ],
        );

        $exception = $this->middlewareValidationException($request);

        self::assertSame(ReportErrorCode::REPORT_REQUEST_INVALID, $exception->errorCode);
        self::assertSame([], $exception->safeFields);
    }

    public static function validRequestProvider(): array
    {
        return [
            'catalog query' => [GetReportCatalogRequest::class, 'GET', [], []],
            'run JSON body' => [CreateReportRunRequest::class, 'POST', [], self::validRunBody()],
        ];
    }

    private static function validRunBody(): array
    {
        return [
            'filters' => [],
            'as_of' => '2026-07-27T07:11:12Z',
        ];
    }

    /**
     * @param  class-string<ReportFormRequest>  $requestClass
     */
    private function reportRequest(
        string $requestClass,
        string $method,
        array $query,
        array $body,
    ): ReportFormRequest {
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
            $body === [] ? null : json_encode($body, JSON_THROW_ON_ERROR),
        );

        /** @var ReportFormRequest $request */
        $request = $requestClass::createFromBase($base);
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make('redirect'));
        $request->setUserResolver(static fn (): User => new User);

        if ($request instanceof CreateReportRunRequest) {
            $route = new Route(['POST'], '/api/v1/admin/reports/{reportCode}/runs', static fn (): null => null);
            $route->bind($request);
            $route->setParameter('reportCode', 'project_margin');
            $request->setRouteResolver(static fn () => $route);
        }

        return $request;
    }

    private function middleware(): InterfaceMiddleware
    {
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->method('canAccessInterface')->willReturn(true);

        return new InterfaceMiddleware($authorization);
    }

    private function middlewareValidationException(ReportFormRequest $request): ReportContractException
    {
        try {
            $this->middleware()->handle(
                $request,
                static function (ReportFormRequest $request): Response {
                    $request->validateResolved();

                    return new Response(status: 204);
                },
                'admin',
            );
        } catch (ReportContractException $exception) {
            return $exception;
        }

        self::fail('Expected report validation failure.');
    }

    private function validationException(ReportFormRequest $request): ReportContractException
    {
        try {
            $request->validateResolved();
        } catch (ReportContractException $exception) {
            return $exception;
        }

        self::fail('Expected report validation failure.');
    }
}
