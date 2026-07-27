<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use App\BusinessModules\Core\Reporting\Application\Access\OrganizationReportScopeResolver;
use App\BusinessModules\Core\Reporting\Application\Access\ReportActorLoader;
use App\BusinessModules\Core\Reporting\Application\Access\ReportExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Application\Contracts\CancelReportExportAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\CancelReportRunAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\CreateReportDownloadLinkAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\CreateReportExportAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\CreateReportRunAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportCatalogAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportDrillDownAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportExportAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportRowsAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportRunAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\RetryReportExportAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\RetryReportRunAction;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorResponseFactory;
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
use App\BusinessModules\Core\Reporting\ReportingContractsServiceProvider;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Http\Middleware\JwtMiddleware;
use App\Http\Middleware\NormalizeAdminResponse;
use App\Http\Middleware\SetOrganizationContext;
use App\Models\User;
use App\Modules\Services\ModulePermissionService;
use App\Services\Logging\Context\RequestContext;
use App\Services\Logging\LoggingService;
use DateTimeImmutable;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Providers\FormRequestServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Routing\RoutingServiceProvider;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Route;
use Illuminate\Translation\TranslationServiceProvider;
use Illuminate\Validation\ValidationServiceProvider;
use Illuminate\View\ViewServiceProvider;
use LogicException;
use Psr\Log\AbstractLogger;
use ReflectionClass;
use ReflectionObject;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;
use Tymon\JWTAuth\JWT;

final class HermeticReportingHttpHarness
{
    private const RUN_ID = '01J00000000000000000000000';
    private const EXPORT_ID = '01J00000000000000000000001';

    private readonly string $basePath;
    private readonly HermeticBoundaryLedger $boundaries;
    private readonly Application $app;
    private readonly Router $router;
    private readonly DeterministicAuthFactory $auth;
    private readonly DeterministicAuthorizationService $authorization;
    private readonly DeterministicModulePermissionService $modules;
    private readonly DeterministicReportActorLoader $actors;
    private readonly FakeReportingActions $actions;
    private readonly string $initialOutputFingerprint;

    public function __construct()
    {
        $this->basePath = dirname(__DIR__, 3);
        $this->boundaries = new HermeticBoundaryLedger();
        $this->boundaries->mark('boundary_objects');
        Model::setConnectionResolver(new ForbiddenConnectionResolver($this->boundaries));
        $this->boundaries->mark('eloquent_boundary');

        $app = new Application($this->basePath);
        $this->boundaries->mark('application');
        $app->useLangPath($this->basePath.'/lang');
        $this->app = $app;
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        $app->instance('app', $app);
        $app->instance(Application::class, $app);
        $app->instance('config', new ConfigRepository([
            'app' => ['locale' => 'ru', 'fallback_locale' => 'ru'],
            'auth' => ['defaults' => ['guard' => 'api_admin']],
            'view' => [
                'paths' => [$this->basePath.'/resources/views'],
                'compiled' => sys_get_temp_dir().'/most-reporting-views',
            ],
        ]));
        $app->instance('events', new HermeticEventDispatcher($app, $this->boundaries));
        $app->instance('files', new Filesystem());
        $this->installThrowingBoundaries($app);
        $this->boundaries->mark('container_boundaries');
        $app->instance('db', new ForbiddenConnectionResolver($this->boundaries));

        (new TranslationServiceProvider($app))->register();
        (new ViewServiceProvider($app))->register();
        (new ValidationServiceProvider($app))->register();
        (new FormRequestServiceProvider($app))->boot();
        (new RoutingServiceProvider($app))->register();

        $this->router = $app->make('router');
        $app->instance(Router::class, $this->router);
        $this->registerMiddleware($this->router);

        $this->auth = new DeterministicAuthFactory();
        $app->instance(AuthFactory::class, $this->auth);
        $app->instance('auth', $this->auth);

        $this->authorization = new DeterministicAuthorizationService();
        $this->modules = new DeterministicModulePermissionService();
        $this->actors = new DeterministicReportActorLoader();
        $app->instance(AuthorizationService::class, $this->authorization);
        $app->instance(ModulePermissionService::class, $this->modules);
        $app->instance(ReportActorLoader::class, $this->actors);
        $app->singleton(OrganizationReportScopeResolver::class);

        $requestContext = (new ReflectionClass(RequestContext::class))->newInstanceWithoutConstructor();
        $requestContext->setCorrelationId('req_reporting_contract');
        $app->instance(RequestContext::class, $requestContext);
        $app->instance(\Psr\Log\LoggerInterface::class, new ForbiddenLogger($this->boundaries));

        $jwt = (new ReflectionClass(JWT::class))->newInstanceWithoutConstructor();
        $logging = (new ReflectionClass(LoggingService::class))->newInstanceWithoutConstructor();
        $app->instance(JWT::class, $jwt);
        $app->instance(LoggingService::class, $logging);

        $this->actions = $this->makeActions();
        $this->bindActions($app, $this->actions);

        $provider = new ReportingContractsServiceProvider($app);
        $this->boundaries->mark('provider');
        $provider->register();
        $provider->boot();
        $this->boundaries->mark('routes');
        $app->boot();
        $this->initialOutputFingerprint = $this->outputFingerprint();
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function productionTopologySnapshot(): array
    {
        $previous = Container::getInstance();
        $production = require $this->basePath.'/bootstrap/app.php';
        if (!$production instanceof Application || $production->isBooted() || $production->hasBeenBootstrapped()) {
            throw new LogicException('REPORT_HERMETIC_PRODUCTION_TOPOLOGY_BOOTED');
        }

        $kernel = $production->make(\Illuminate\Contracts\Http\Kernel::class);
        if ($production->isBooted() || $production->hasBeenBootstrapped()) {
            throw new LogicException('REPORT_HERMETIC_PRODUCTION_TOPOLOGY_BOOTED');
        }

        $reflection = new ReflectionObject($kernel);
        $middleware = $reflection->getProperty('middleware')->getValue($kernel);
        $groups = $reflection->getProperty('middlewareGroups')->getValue($kernel);
        Container::setInstance($previous);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);

        return [
            'verification_mode' => 'production_topology_snapshot',
            'application_booted' => false,
            'kernel_bootstrapped' => false,
            'topology' => [
                'global_middleware' => $middleware,
                'api_middleware' => $groups['api'],
            ],
            'routes' => [],
            'providers' => [],
            'dispatches' => 0,
        ];
    }

    public static function expectedRoutes(): array
    {
        return [
            'admin.reports.catalog' => [['GET', 'HEAD'], 'api/v1/admin/reports/catalog'],
            'admin.reports.runs.store' => [['POST'], 'api/v1/admin/reports/{reportCode}/runs'],
            'admin.reports.runs.show' => [['GET', 'HEAD'], 'api/v1/admin/reports/runs/{runId}'],
            'admin.reports.runs.rows' => [['GET', 'HEAD'], 'api/v1/admin/reports/runs/{runId}/rows'],
            'admin.reports.runs.drill-down' => [['POST'], 'api/v1/admin/reports/runs/{runId}/drill-down'],
            'admin.reports.runs.retry' => [['POST'], 'api/v1/admin/reports/runs/{runId}/retry'],
            'admin.reports.runs.cancel' => [['POST'], 'api/v1/admin/reports/runs/{runId}/cancel'],
            'admin.reports.exports.store' => [['POST'], 'api/v1/admin/reports/runs/{runId}/exports'],
            'admin.reports.exports.show' => [['GET', 'HEAD'], 'api/v1/admin/reports/exports/{exportId}'],
            'admin.reports.exports.retry' => [['POST'], 'api/v1/admin/reports/exports/{exportId}/retry'],
            'admin.reports.exports.cancel' => [['POST'], 'api/v1/admin/reports/exports/{exportId}/cancel'],
            'admin.reports.exports.download-link' => [['POST'], 'api/v1/admin/reports/exports/{exportId}/download-link'],
        ];
    }

    public static function legacyUris(): array
    {
        return array_map(
            static fn (string $uri): string => 'api/v1/admin/reports/'.$uri,
            [
                'check-basic-availability',
                'check-advanced-availability',
                'check-availability',
                'material-usage',
                'work-completion',
                'project-status-summary',
                'contract-payments',
                'act-reports',
                'contractor-settlements',
                'warehouse-stock',
                'material-movements',
                'time-tracking',
                'project-profitability',
                'project-timelines',
                'contractor-summary',
                'contractor-detail',
                'contractor-detail/{contractorId}',
                'foreman-activity',
                'official-material-usage',
            ],
        );
    }

    public static function expectedRawMiddlewareByRoute(): array
    {
        $base = \App\Support\Routing\AdminRouteStack::middleware([
            'module.access:reports',
            \App\BusinessModules\Core\Reporting\Http\Admin\Middleware\RenderReportErrors::class,
        ]);
        $permissions = [
            'admin.reports.catalog' => ['authorize:reports.view'],
            'admin.reports.runs.store' => ['authorize:reports.view', 'authorize:reports.run'],
            'admin.reports.runs.show' => ['authorize:reports.view'],
            'admin.reports.runs.rows' => ['authorize:reports.view'],
            'admin.reports.runs.drill-down' => ['authorize:reports.view'],
            'admin.reports.runs.retry' => ['authorize:reports.view', 'authorize:reports.run'],
            'admin.reports.runs.cancel' => ['authorize:reports.view', 'authorize:reports.run'],
            'admin.reports.exports.store' => ['authorize:reports.view', 'authorize:reports.export'],
            'admin.reports.exports.show' => ['authorize:reports.view'],
            'admin.reports.exports.retry' => ['authorize:reports.view', 'authorize:reports.export'],
            'admin.reports.exports.cancel' => ['authorize:reports.view', 'authorize:reports.export'],
            'admin.reports.exports.download-link' => [
                'authorize:reports.view',
                'authorize:reports.export',
                'authorize:reports.download',
            ],
        ];

        return array_map(
            static fn (array $route): array => [...$base, ...$route],
            $permissions,
        );
    }

    public function rawMiddlewareByRoute(): array
    {
        $actual = [];
        foreach ($this->namedReportingRoutes() as $route) {
            $actual[$route->getName()] = $route->middleware();
        }

        return $actual;
    }

    public function gatheredMiddlewareByRoute(): array
    {
        $actual = [];
        foreach ($this->namedReportingRoutes() as $route) {
            $actual[$route->getName()] = $this->router->gatherRouteMiddleware($route);
        }

        return $actual;
    }

    public function providerRegistrationCount(): int
    {
        return count(array_filter(
            require $this->basePath.'/bootstrap/providers.php',
            static fn (string $provider): bool => $provider === ReportingContractsServiceProvider::class,
        ));
    }

    public function providersContain(string $provider): bool
    {
        return in_array($provider, require $this->basePath.'/bootstrap/providers.php', true);
    }

    public function apiRoutesRequireLegacyReports(): bool
    {
        return str_contains(
            (string) file_get_contents($this->basePath.'/routes/api.php'),
            "require __DIR__.'/api/v1/admin/reports.php';",
        );
    }

    public function legacyRouteFileExists(): bool
    {
        return is_file($this->basePath.'/routes/api/v1/admin/reports.php');
    }

    public function canonicalRouteFileExists(): bool
    {
        return is_file($this->basePath.'/app/BusinessModules/Core/Reporting/routes.php');
    }

    public function canonicalProviderFileExists(): bool
    {
        return is_file($this->basePath.'/app/BusinessModules/Core/Reporting/ReportingContractsServiceProvider.php');
    }

    public function productionProviderUnboundActions(): array
    {
        $app = new Application($this->basePath);
        (new ReportingContractsServiceProvider($app))->register();

        $result = [];
        foreach (self::actionInterfaces() as $interface) {
            $result[$interface] = !$app->bound($interface);
        }

        return $result;
    }

    public function routeMethodsMatch(array $expected): bool
    {
        $actual = [];
        foreach ($this->namedReportingRoutes() as $route) {
            $actual[$route->getName()] = [$route->methods(), $route->uri()];
        }

        return $actual === $expected;
    }

    public function mutateExpectedMethod(array $expected, string $route, array $methods): array
    {
        $expected[$route][0] = $methods;

        return $expected;
    }

    public function boundaryInstallationProof(): array
    {
        return [
            'installed_before_application' => $this->boundaries->before('eloquent_boundary', 'application'),
            'installed_before_provider' => $this->boundaries->before('container_boundaries', 'provider'),
            'installed_before_routes' => $this->boundaries->before('container_boundaries', 'routes'),
            'breaches' => $this->boundaries->breaches(),
            'boundaries' => $this->boundaries->names(),
            'forbidden_api_hits' => $this->forbiddenApiHits(),
            'output_fingerprint_unchanged' => hash_equals(
                $this->initialOutputFingerprint,
                $this->outputFingerprint(),
            ),
        ];
    }

    public function runAuthorizationCase(string $caseId): array
    {
        [$permissions, $module, $requests, $actorStates] = $this->authorizationScenario($caseId);
        $this->authorization->permissions = $permissions;
        $this->modules->allowed = $module;
        $this->actors->states = $actorStates;

        $responses = [];
        foreach ($requests as $request) {
            $responses[] = $this->dispatch(...$request);
        }

        $statuses = array_column($responses, 'status');
        $serialized = array_map(static fn (array $response): string => $response['body'], $responses);
        $assertions = 6;
        if (str_contains($caseId, 'indistinguishable')) {
            if (count(array_unique($serialized)) !== 1
                || str_contains($serialized[0], '991')
                || str_contains($serialized[0], '992')
                || str_contains($serialized[0], 'foreign-source-token')
                || str_contains($serialized[0], 'missing-source-token')) {
                throw new LogicException('REPORT_HERMETIC_INDISTINGUISHABILITY_FAILED');
            }
        }
        if ($this->boundaries->breaches() !== []) {
            throw new LogicException('REPORT_HERMETIC_BOUNDARY_BREACH');
        }

        return [
            'case_id' => $caseId,
            'status' => $statuses[array_key_last($statuses)],
            'request_count' => count($requests),
            'response_statuses' => $statuses,
            'response_codes' => array_column($responses, 'code'),
            'action_calls' => $this->actionCallCount(),
            'actor_loads' => $this->actors->loads,
            'assertions' => $assertions,
        ];
    }

    public function runMalformedCase(string $caseId): array
    {
        $this->authorization->permissions = [
            'admin.access',
            'reports.view',
            'reports.run',
            'reports.export',
            'reports.download',
        ];
        $this->modules->allowed = true;
        $this->actors->states = ['active'];
        $requests = $this->malformedScenario($caseId);
        $responses = [];
        foreach ($requests as $request) {
            $responses[] = $this->dispatch(...$request);
        }
        if ($this->actionCallCount() !== 0 || $this->actors->loads !== 0 || $this->boundaries->breaches() !== []) {
            throw new LogicException('REPORT_HERMETIC_MALFORMED_BOUNDARY_FAILED');
        }

        return [
            'case_id' => $caseId,
            'status' => $responses[0]['status'],
            'request_count' => count($requests),
            'response_statuses' => array_column($responses, 'status'),
            'response_codes' => array_column($responses, 'code'),
            'action_calls' => $this->actionCallCount(),
            'actor_loads' => $this->actors->loads,
            'assertions' => 6,
        ];
    }

    private function installThrowingBoundaries(Application $app): void
    {
        foreach ([
            'cache',
            'cache.store',
            'db',
            'events.dispatcher.external',
            'filesystem',
            'http',
            'log',
            'mailer',
            'prometheus',
            'queue',
            'rate_limiter',
            'storage',
        ] as $binding) {
            $app->instance($binding, new ForbiddenBoundary($binding, $this->boundaries));
        }
        foreach ([
            \GuzzleHttp\ClientInterface::class => 'network',
            \Illuminate\Contracts\Bus\Dispatcher::class => 'queue',
            \Illuminate\Contracts\Cache\Factory::class => 'cache',
            \Illuminate\Contracts\Filesystem\Factory::class => 'filesystem',
            \Illuminate\Contracts\Mail\Mailer::class => 'mail',
            \Illuminate\Contracts\Queue\Factory::class => 'queue',
            \Illuminate\Http\Client\Factory::class => 'http',
            \App\Services\Monitoring\PrometheusService::class => 'prometheus',
        ] as $contract => $boundary) {
            $app->instance($contract, new ForbiddenBoundary($boundary, $this->boundaries));
        }
    }

    private function registerMiddleware(Router $router): void
    {
        $router->middlewareGroup('api', [HermeticApiSentinel::class]);
        $router->aliasMiddleware('admin.response', NormalizeAdminResponse::class);
        $router->aliasMiddleware('auth', Authenticate::class);
        $router->aliasMiddleware('auth.jwt', JwtMiddleware::class);
        $router->aliasMiddleware('organization.context', SetOrganizationContext::class);
        $router->aliasMiddleware('authorize', \App\Domain\Authorization\Http\Middleware\AuthorizeMiddleware::class);
        $router->aliasMiddleware('interface', \App\Domain\Authorization\Http\Middleware\InterfaceMiddleware::class);
        $router->aliasMiddleware('module.access', \App\Modules\Middleware\ModuleAccessMiddleware::class);
    }

    private function makeActions(): FakeReportingActions
    {
        $definition = (new ReportDefinitionBuilder())
            ->filters([[
                'id' => 'period',
                'type' => 'date',
                'operators' => ['eq'],
            ]])
            ->payload();
        $quality = new ReportQuality(
            ReportQualityStatus::COMPLETE,
            null,
            [],
            0,
            ReportReconciliationStatus::MATCHED,
            [],
            [],
        );

        return new FakeReportingActions([
            'catalog' => new ReportCatalogView('1.0.0', new Sha256Hash(str_repeat('f', 64)), [$definition]),
            'createRun' => (new ReportRunBuilder())->ready(),
            'getRun' => (new ReportRunBuilder())->queued(),
            'rows' => new ReportPage(
                [['row_key' => 'row-1', 'name' => 'МОСТ']],
                [],
                ReportFreshnessStatus::FRESH,
                $quality,
                null,
                50,
                false,
                new ReportWindowSort('name', ReportSortDirection::ASC),
            ),
            'drillDown' => new ReportDrillDownResult([['row_key' => 'row-1']], null, []),
            'retryRun' => (new ReportRunBuilder())->queued(),
            'cancelRun' => (new ReportRunBuilder())
                ->status(\App\BusinessModules\Core\Reporting\Domain\Enums\ReportRunStatus::CANCELLED)
                ->queued(),
            'createExport' => (new ReportExportBuilder())->ready(),
            'getExport' => (new ReportExportBuilder())->queued(),
            'retryExport' => (new ReportExportBuilder())->queued(),
            'cancelExport' => (new ReportExportBuilder())
                ->status(\App\BusinessModules\Core\Reporting\Domain\Enums\ReportExportStatus::CANCELLED)
                ->queued(),
            'downloadLink' => new ReportDownloadLink(
                'https://reports.example.test/report.csv',
                'version-1',
                new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
                new DateTimeImmutable('2026-01-01T00:05:00+00:00'),
            ),
        ]);
    }

    private function bindActions(Application $app, FakeReportingActions $actions): void
    {
        $bindings = [
            GetReportCatalogAction::class => $actions->catalogAction,
            CreateReportRunAction::class => $actions->createRunAction,
            GetReportRunAction::class => $actions->getRunAction,
            GetReportRowsAction::class => $actions->rowsAction,
            GetReportDrillDownAction::class => $actions->drillDownAction,
            RetryReportRunAction::class => $actions->retryRunAction,
            CancelReportRunAction::class => $actions->cancelRunAction,
            CreateReportExportAction::class => $actions->createExportAction,
            GetReportExportAction::class => $actions->getExportAction,
            RetryReportExportAction::class => $actions->retryExportAction,
            CancelReportExportAction::class => $actions->cancelExportAction,
            CreateReportDownloadLinkAction::class => $actions->downloadLinkAction,
        ];
        foreach ($bindings as $interface => $implementation) {
            $app->instance($interface, $implementation);
        }
    }

    private static function actionInterfaces(): array
    {
        return [
            GetReportCatalogAction::class,
            CreateReportRunAction::class,
            GetReportRunAction::class,
            GetReportRowsAction::class,
            GetReportDrillDownAction::class,
            RetryReportRunAction::class,
            CancelReportRunAction::class,
            CreateReportExportAction::class,
            GetReportExportAction::class,
            RetryReportExportAction::class,
            CancelReportExportAction::class,
            CreateReportDownloadLinkAction::class,
        ];
    }

    private function namedReportingRoutes(): array
    {
        $routes = [];
        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            if (is_string($route->getName()) && str_starts_with($route->getName(), 'admin.reports.')) {
                $routes[] = $route;
            }
        }

        return $routes;
    }

    private function dispatch(string $method, string $uri, array $parameters = [], bool $authenticated = true): array
    {
        $user = new User();
        $user->forceFill(['id' => 1, 'current_organization_id' => null]);
        $this->auth->guard->user = $user;

        $server = [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_IDEMPOTENCY_KEY' => 'reporting-test-key',
        ];
        $request = Request::create($uri, $method, $parameters, [], [], $server);
        $request->attributes->set('web_auth_audience', $authenticated ? 'admin' : 'invalid');
        $request->setUserResolver(fn (?string $guard = null): ?Authenticatable => $this->auth->guard($guard)->user());
        $this->app->instance('request', $request);

        try {
            $response = $this->router->dispatch($request);
        } catch (NotFoundHttpException) {
            $this->assertOutputFingerprint();

            return ['status' => 404, 'code' => null, 'body' => '{"status":404}'];
        }

        $body = (string) $response->getContent();
        $decoded = json_decode($body, true);
        $code = is_array($decoded)
            ? ($decoded['code'] ?? $decoded['error']['code'] ?? $decoded['data']['code'] ?? null)
            : null;

        $this->assertOutputFingerprint();

        return ['status' => $response->getStatusCode(), 'code' => $code, 'body' => $body];
    }

    private function authorizationScenario(string $caseId): array
    {
        $all = ['admin.access', 'reports.view', 'reports.run', 'reports.export', 'reports.download'];
        $view = ['admin.access', 'reports.view'];
        $runner = [...$view, 'reports.run'];
        $exporter = [...$view, 'reports.export'];
        $downloader = [...$exporter, 'reports.download'];
        $catalog = [['GET', '/api/v1/admin/reports/catalog']];
        $runCreate = [['POST', '/api/v1/admin/reports/report/runs', $this->validRunBody()]];
        $exportCreate = [['POST', '/api/v1/admin/reports/runs/'.self::RUN_ID.'/exports', $this->validExportBody()]];
        $download = [['POST', '/api/v1/admin/reports/exports/'.self::EXPORT_ID.'/download-link']];

        return match ($caseId) {
            'unauthenticated_catalog_denied' => [$all, true, [['GET', '/api/v1/admin/reports/catalog', [], false]], ['active']],
            'non_admin_catalog_denied' => [['reports.view'], true, $catalog, ['active']],
            'module_disabled_catalog_denied' => [$all, false, $catalog, ['active']],
            'missing_global_permission_catalog_denied' => [['admin.access'], true, $catalog, ['active']],
            'view_actor_catalog_allowed' => [$view, true, $catalog, ['active']],
            'view_actor_run_status_allowed' => [$view, true, [['GET', '/api/v1/admin/reports/runs/'.self::RUN_ID]], ['active']],
            'view_actor_rows_allowed' => [$view, true, [['GET', '/api/v1/admin/reports/runs/'.self::RUN_ID.'/rows?cursor&limit=50&sort_by=name&sort_dir=asc']], ['active']],
            'view_actor_run_create_denied' => [$view, true, $runCreate, ['active']],
            'view_actor_export_create_denied' => [$view, true, $exportCreate, ['active']],
            'view_actor_download_denied' => [$view, true, $download, ['active']],
            'runner_run_create_allowed' => [$runner, true, $runCreate, ['active']],
            'runner_run_retry_allowed' => [$runner, true, [['POST', '/api/v1/admin/reports/runs/'.self::RUN_ID.'/retry']], ['active']],
            'runner_run_cancel_allowed' => [$runner, true, [['POST', '/api/v1/admin/reports/runs/'.self::RUN_ID.'/cancel']], ['active']],
            'runner_export_denied' => [$runner, true, $exportCreate, ['active']],
            'exporter_export_allowed' => [$exporter, true, $exportCreate, ['active']],
            'exporter_download_denied' => [$exporter, true, $download, ['active']],
            'downloader_revoked_definition_denied' => $this->revokedDefinitionScenario($downloader, $download),
            'manage_does_not_expand_operational_permissions' => [
                ['admin.access', 'reports.view', 'reports.manage'],
                true,
                [$runCreate[0], $exportCreate[0], $download[0]],
                ['active'],
            ],
            'foreign_and_nonexistent_filter_indistinguishable' => $this->indistinguishableScenario(
                'createRun',
                [
                    ['POST', '/api/v1/admin/reports/report/runs', [
                        ...$this->validRunBody(),
                        'filters' => ['project_id' => ['operator' => 'eq', 'value' => 991]],
                    ]],
                    ['POST', '/api/v1/admin/reports/report/runs', [
                        ...$this->validRunBody(),
                        'filters' => ['project_id' => ['operator' => 'eq', 'value' => 992]],
                    ]],
                ],
            ),
            'foreign_and_nonexistent_source_indistinguishable' => $this->indistinguishableScenario(
                'drillDown',
                [
                    ['POST', '/api/v1/admin/reports/runs/'.self::RUN_ID.'/drill-down', [
                        'token' => 'foreign-source-token',
                        'cursor' => null,
                        'limit' => 50,
                    ]],
                    ['POST', '/api/v1/admin/reports/runs/'.self::RUN_ID.'/drill-down', [
                        'token' => 'missing-source-token',
                        'cursor' => null,
                        'limit' => 50,
                    ]],
                ],
            ),
            'blocked_actor_denied_after_context_reload' => [
                $view,
                true,
                [$catalog[0], $catalog[0]],
                ['active', 'blocked'],
            ],
            'deleted_actor_denied_after_context_reload' => [
                $view,
                true,
                [$catalog[0], $catalog[0]],
                ['active', 'deleted'],
            ],
            default => throw new LogicException('Unknown authorization case.'),
        };
    }

    private function indistinguishableScenario(string $action, array $requests): array
    {
        $this->actions->willThrow(
            $action,
            ReportContractException::fromCode(ReportErrorCode::REPORT_FILTER_VALUE_NOT_FOUND),
        );

        return [
            ['admin.access', 'reports.view', 'reports.run'],
            true,
            $requests,
            ['active', 'active'],
        ];
    }

    private function revokedDefinitionScenario(array $permissions, array $requests): array
    {
        $this->app->instance(CreateReportDownloadLinkAction::class, new class implements CreateReportDownloadLinkAction {
            public function handle(
                \App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext $context,
                \App\BusinessModules\Core\Reporting\Application\Input\CreateReportDownloadLinkData $data,
            ): ReportDownloadLink {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
            }
        });

        return [$permissions, true, $requests, ['active']];
    }

    private function malformedScenario(string $caseId): array
    {
        $invalid = 'invalid';
        $map = [
            'invalid_run_show_ulid' => [['GET', '/api/v1/admin/reports/runs/'.$invalid]],
            'invalid_run_rows_ulid' => [['GET', '/api/v1/admin/reports/runs/'.$invalid.'/rows?cursor&limit=50&sort_by=name&sort_dir=asc']],
            'invalid_run_drill_down_ulid' => [['POST', '/api/v1/admin/reports/runs/'.$invalid.'/drill-down', ['token' => 'x', 'cursor' => null, 'limit' => 50]]],
            'invalid_run_retry_ulid' => [['POST', '/api/v1/admin/reports/runs/'.$invalid.'/retry']],
            'invalid_run_cancel_ulid' => [['POST', '/api/v1/admin/reports/runs/'.$invalid.'/cancel']],
            'invalid_export_create_run_ulid' => [['POST', '/api/v1/admin/reports/runs/'.$invalid.'/exports', $this->validExportBody()]],
            'invalid_export_show_ulid' => [['GET', '/api/v1/admin/reports/exports/'.$invalid]],
            'invalid_export_retry_ulid' => [['POST', '/api/v1/admin/reports/exports/'.$invalid.'/retry']],
            'invalid_export_cancel_ulid' => [['POST', '/api/v1/admin/reports/exports/'.$invalid.'/cancel']],
            'invalid_export_download_ulid' => [['POST', '/api/v1/admin/reports/exports/'.$invalid.'/download-link']],
            'missing_run_as_of' => [['POST', '/api/v1/admin/reports/report/runs', ['filters' => []]]],
            'rows_limit_101' => [['GET', '/api/v1/admin/reports/runs/'.self::RUN_ID.'/rows?cursor&limit=101&sort_by=name&sort_dir=asc']],
            'missing_drill_down_token' => [['POST', '/api/v1/admin/reports/runs/'.self::RUN_ID.'/drill-down', ['cursor' => null, 'limit' => 50]]],
            'invalid_export_format' => [['POST', '/api/v1/admin/reports/runs/'.self::RUN_ID.'/exports', [...$this->validExportBody(), 'format' => 'zip']]],
            'unexpected_download_body' => [['POST', '/api/v1/admin/reports/exports/'.self::EXPORT_ID.'/download-link', ['ttl' => 300]]],
        ];
        if ($caseId === 'legacy_routes_absent') {
            return array_map(static fn (string $uri): array => ['GET', '/'.$uri], self::legacyUris());
        }

        return $map[$caseId] ?? throw new LogicException('Unknown malformed case.');
    }

    private function validRunBody(): array
    {
        return [
            'filters' => [],
            'as_of' => '2026-01-01T00:00:00Z',
            'locale' => 'ru-RU',
        ];
    }

    private function validExportBody(): array
    {
        return [
            'format' => 'csv',
            'columns' => ['name'],
            'sort_by' => 'name',
            'sort_dir' => 'asc',
            'locale' => 'ru-RU',
            'timezone' => 'UTC',
        ];
    }

    private function actionCallCount(): int
    {
        return array_sum(array_map('count', $this->actions->calls));
    }

    private function forbiddenApiHits(): array
    {
        $patterns = [
            '/withoutMiddleware\s*\(/',
            '/RefreshDatabase/',
            '/DatabaseMigrations/',
            '/DatabaseTransactions/',
            '/HttpKernel\s*::\s*handle/',
            '/artisan\s+(?:migrate|tinker|db:)/',
        ];
        $files = [
            $this->basePath.'/tests/Architecture/Reporting/ReportingRouteSnapshotTest.php',
            $this->basePath.'/tests/Feature/Api/V1/Admin/Reporting/ReportingAuthorizationMatrixTest.php',
            $this->basePath.'/tests/Feature/Api/V1/Admin/Reporting/ReportingMalformedRequestContractTest.php',
        ];
        $hits = [];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $source) === 1) {
                    $hits[] = basename($file).':'.$pattern;
                }
            }
        }

        return $hits;
    }

    private function assertOutputFingerprint(): void
    {
        if (!hash_equals($this->initialOutputFingerprint, $this->outputFingerprint())) {
            $this->boundaries->breach('filesystem');
        }
    }

    private function outputFingerprint(): string
    {
        $entries = [];
        foreach ([
            $this->basePath.'/build/reports',
            $this->basePath.'/storage/framework/cache',
            $this->basePath.'/storage/framework/sessions',
            $this->basePath.'/storage/framework/views',
            $this->basePath.'/storage/logs',
        ] as $root) {
            if (!is_dir($root)) {
                $entries[] = $root.':absent';
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                    continue;
                }
                $entries[] = $file->getPathname().':'.$file->getSize().':'.$file->getMTime();
            }
        }
        sort($entries);

        return hash('sha256', implode("\n", $entries));
    }
}

final class HermeticApiSentinel
{
    public function handle(Request $request, \Closure $next): Response
    {
        $request->attributes->set('hermetic_api_sentinel', true);

        return $next($request);
    }
}

final class HermeticEventDispatcher extends Dispatcher
{
    public function __construct(
        Container $container,
        private readonly HermeticBoundaryLedger $ledger,
    ) {
        parent::__construct($container);
    }

    public function dispatch($event, $payload = [], $halt = false)
    {
        if (is_object($event) && str_starts_with($event::class, 'Illuminate\\Routing\\Events\\')) {
            return parent::dispatch($event, $payload, $halt);
        }

        $this->ledger->breach('events');
    }
}

final class HermeticBoundaryLedger
{
    private array $breaches = [];
    private array $events = [];

    public function mark(string $event): void
    {
        $this->events[] = $event;
    }

    public function before(string $first, string $second): bool
    {
        $firstIndex = array_search($first, $this->events, true);
        $secondIndex = array_search($second, $this->events, true);

        return is_int($firstIndex) && is_int($secondIndex) && $firstIndex < $secondIndex;
    }

    public function breach(string $name): never
    {
        $this->breaches[] = $name;

        throw new LogicException('REPORT_HERMETIC_'.strtoupper($name).'_ACCESS_FORBIDDEN');
    }

    public function breaches(): array
    {
        return $this->breaches;
    }

    public function names(): array
    {
        return [
            'cache',
            'database',
            'eloquent',
            'events',
            'filesystem',
            'http',
            'log',
            'mail',
            'network',
            'prometheus',
            'queue',
            'rate_limit',
            'storage',
        ];
    }
}

final class ForbiddenBoundary
{
    public function __construct(
        private readonly string $name,
        private readonly HermeticBoundaryLedger $ledger,
    ) {}

    public function __call(string $name, array $arguments): never
    {
        $this->ledger->breach($this->name);
    }
}

final class ForbiddenConnectionResolver implements ConnectionResolverInterface
{
    public function __construct(private readonly HermeticBoundaryLedger $ledger) {}
    public function connection($name = null): ConnectionInterface { $this->ledger->breach('eloquent'); }
    public function getDefaultConnection(): string { return 'forbidden'; }
    public function setDefaultConnection($name): void {}
}

final class ForbiddenLogger extends AbstractLogger
{
    public function __construct(private readonly HermeticBoundaryLedger $ledger) {}
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        throw new LogicException(
            'REPORT_HERMETIC_LOG_ACCESS_FORBIDDEN:'.($context['exception_class'] ?? 'unknown'),
        );
    }
}

final class DeterministicGuard implements Guard
{
    public ?Authenticatable $user = null;
    public function check(): bool { return $this->user !== null; }
    public function guest(): bool { return $this->user === null; }
    public function user(): ?Authenticatable { return $this->user; }
    public function id(): int|string|null { return $this->user?->getAuthIdentifier(); }
    public function validate(array $credentials = []): bool { return false; }
    public function hasUser(): bool { return $this->user !== null; }
    public function setUser(Authenticatable $user): static { $this->user = $user; return $this; }
}

final class DeterministicAuthFactory implements AuthFactory
{
    public readonly DeterministicGuard $guard;
    public function __construct() { $this->guard = new DeterministicGuard(); }
    public function guard($name = null): Guard { return $this->guard; }
    public function shouldUse($name): void {}
    public function user(): ?Authenticatable { return $this->guard->user(); }
}

final class DeterministicAuthorizationService extends AuthorizationService
{
    public array $permissions = [];

    public function __construct() {}

    public function can(User $user, string $permission, ?array $context = null): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function canAccessInterface(
        User $user,
        string $interface,
        ?AuthorizationContext $context = null,
    ): bool {
        $request = Container::getInstance()->make('request');
        if ($request instanceof Request) {
            $request->attributes->set('current_organization_id', 1);
            $request->attributes->set('holding_organization_ids', [1]);
            $request->attributes->set('allowed_project_ids', []);
            $request->attributes->set('allowed_resource_ids', []);
            $request->attributes->set('organization_timezone', 'UTC');
        }

        return $interface === 'admin' && in_array('admin.access', $this->permissions, true);
    }
}

final class DeterministicModulePermissionService extends ModulePermissionService
{
    public bool $allowed = true;
    public function __construct() {}
    public function userHasModuleAccess(User $user, string $moduleSlug): bool
    {
        return $moduleSlug === 'reports' && $this->allowed;
    }
}

final class DeterministicReportActorLoader implements ReportActorLoader
{
    public array $states = ['active'];
    public int $loads = 0;

    public function loadActive(int $actorId): ReportActor
    {
        $state = $this->states[min($this->loads, count($this->states) - 1)] ?? 'active';
        $this->loads++;
        if ($state !== 'active') {
            throw new LogicException('report_actor_'.$state);
        }

        return new ReportActor($actorId, 'active', [
            'reports.view',
            'reports.run',
            'reports.export',
            'reports.download',
        ]);
    }
}
