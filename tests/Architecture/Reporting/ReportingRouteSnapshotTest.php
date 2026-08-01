<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use App\BusinessModules\Core\Reporting\ReportingContractsServiceProvider;
use Illuminate\Routing\Route;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\HermeticReportingHttpHarness;

final class ReportingRouteSnapshotTest extends TestCase
{
    private HermeticReportingHttpHarness $harness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->harness = new HermeticReportingHttpHarness;
    }

    #[Test]
    public function production_topology_snapshot_is_unbooted_and_ordered(): void
    {
        $snapshot = $this->harness->productionTopologySnapshot();

        self::assertSame('production_topology_snapshot', $snapshot['verification_mode']);
        self::assertFalse($snapshot['application_booted']);
        self::assertFalse($snapshot['kernel_bootstrapped']);
        self::assertSame([
            \App\Http\Middleware\CorsMiddleware::class,
            \App\Http\Middleware\CorrelationIdMiddleware::class,
            \Illuminate\Foundation\Http\Middleware\InvokeDeferredCallbacks::class,
            \Illuminate\Http\Middleware\TrustProxies::class,
            \Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance::class,
            \Illuminate\Http\Middleware\ValidatePostSize::class,
            \Illuminate\Foundation\Http\Middleware\TrimStrings::class,
            \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
            \App\Http\Middleware\PrometheusMiddleware::class,
        ], $snapshot['topology']['global_middleware']);
        self::assertSame([
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\UseJwtCookieForAuthorization::class,
            \App\Http\Middleware\WebInterfaceSecurityMiddleware::class,
            'throttle:api',
            \App\Http\Middleware\RequestLoggingMiddleware::class,
            \App\Http\Middleware\SetOrganizationContext::class,
            \App\Http\Middleware\AddWebSecurityHeaders::class,
        ], $snapshot['topology']['api_middleware']);
        self::assertCount(9, $snapshot['topology']['global_middleware']);
        self::assertCount(7, $snapshot['topology']['api_middleware']);
        self::assertSame(['global_middleware', 'api_middleware'], array_keys($snapshot['topology']));
        self::assertSame(\App\Http\Middleware\CorsMiddleware::class, $snapshot['topology']['global_middleware'][0]);
        self::assertSame(\App\Http\Middleware\PrometheusMiddleware::class, $snapshot['topology']['global_middleware'][8]);
        self::assertSame(\Illuminate\Routing\Middleware\SubstituteBindings::class, $snapshot['topology']['api_middleware'][0]);
        self::assertSame(\App\Http\Middleware\AddWebSecurityHeaders::class, $snapshot['topology']['api_middleware'][6]);
        self::assertNotContains(\App\Http\Middleware\PrometheusMiddleware::class, $snapshot['topology']['api_middleware']);
        self::assertSame([], $snapshot['routes']);
        self::assertSame([], $snapshot['providers']);
        self::assertSame(0, $snapshot['dispatches']);
    }

    #[Test]
    public function test_reporting_route_slice_has_exact_core_routes_and_no_legacy_uri(): void
    {
        $expected = HermeticReportingHttpHarness::expectedRoutes();
        $routes = collect($this->harness->router()->getRoutes()->getRoutes());

        foreach ($expected as $name => [$methods, $uri]) {
            $matches = $routes->filter(
                static fn (Route $route): bool => $route->getName() === $name
                    && $route->uri() === $uri
                    && $route->methods() === $methods,
            );
            self::assertCount(1, $matches, $name);
        }

        self::assertCount(12, $routes->filter(
            static fn (Route $route): bool => in_array($route->getName(), array_keys($expected), true),
        ));
        foreach (HermeticReportingHttpHarness::legacyUris() as $uri) {
            self::assertFalse($routes->contains(
                static fn (Route $route): bool => $route->uri() === $uri,
            ), $uri);
        }
    }

    #[Test]
    public function canonical_routes_preserve_exact_raw_middleware_order(): void
    {
        $actual = $this->harness->rawMiddlewareByRoute();

        self::assertSame(HermeticReportingHttpHarness::expectedRawMiddlewareByRoute(), $actual);
        self::assertCount(12, $actual);
        self::assertSame(array_keys(HermeticReportingHttpHarness::expectedRoutes()), array_keys($actual));
        foreach ($actual as $middleware) {
            self::assertNotContains('module.access:reports', $middleware);
            self::assertSame([], array_values(array_filter(
                $middleware,
                static fn (string $entry): bool => str_starts_with($entry, 'authorize:reports.'),
            )));
        }
        self::assertSame(
            [\App\BusinessModules\Core\Reporting\Http\Admin\Middleware\AuthorizeReportDefinitionAccess::class],
            array_slice($actual['admin.reports.catalog'], -1),
        );
        self::assertSame(
            [\App\BusinessModules\Core\Reporting\Http\Admin\Middleware\AuthorizeReportDefinitionAccess::class],
            array_slice($actual['admin.reports.exports.download-link'], -1),
        );
    }

    #[Test]
    public function workspace_routes_keep_generic_module_and_permission_matrix(): void
    {
        $routes = collect($this->harness->router()->getRoutes()->getRoutes());
        $workspace = $routes->first(
            static fn (Route $route): bool => $route->getName() === 'admin.reports.workspace.show',
        );

        self::assertInstanceOf(Route::class, $workspace);
        self::assertContains('module.access:reports', $workspace->middleware());
        self::assertContains('authorize:reports.view', $workspace->middleware());
        self::assertNotContains(
            \App\BusinessModules\Core\Reporting\Http\Admin\Middleware\AuthorizeReportDefinitionAccess::class,
            $workspace->middleware(),
        );
    }

    #[Test]
    public function reporting_slice_gathers_real_reporting_http_stack(): void
    {
        $gathered = $this->harness->gatheredMiddlewareByRoute();

        self::assertCount(12, $gathered);
        self::assertContains(\App\Http\Middleware\NormalizeAdminResponse::class, $gathered['admin.reports.catalog']);
        self::assertContains(\App\Http\Middleware\JwtMiddleware::class.':api_admin', $gathered['admin.reports.catalog']);
        self::assertContains(\App\Http\Middleware\SetOrganizationContext::class, $gathered['admin.reports.catalog']);
        self::assertContains(\App\Domain\Authorization\Http\Middleware\AuthorizeMiddleware::class.':admin.access', $gathered['admin.reports.catalog']);
        self::assertContains(\App\Domain\Authorization\Http\Middleware\InterfaceMiddleware::class.':admin', $gathered['admin.reports.catalog']);
        self::assertNotContains(\App\Modules\Middleware\ModuleAccessMiddleware::class.':reports', $gathered['admin.reports.catalog']);
        self::assertContains(
            \App\BusinessModules\Core\Reporting\Http\Admin\Middleware\RenderReportErrors::class,
            $gathered['admin.reports.catalog'],
        );
        self::assertContains(
            \App\BusinessModules\Core\Reporting\Http\Admin\Middleware\AuthorizeReportDefinitionAccess::class,
            $gathered['admin.reports.catalog'],
        );
    }

    #[Test]
    public function provider_is_registered_once_and_legacy_aggregator_is_absent(): void
    {
        self::assertSame(1, $this->harness->providerRegistrationCount());
        self::assertTrue($this->harness->providersContain(ReportingContractsServiceProvider::class));
        self::assertFalse($this->harness->apiRoutesRequireLegacyReports());
        self::assertFalse($this->harness->legacyRouteFileExists());
        self::assertTrue($this->harness->canonicalRouteFileExists());
        self::assertTrue($this->harness->canonicalProviderFileExists());
    }

    #[Test]
    public function provider_leaves_execution_and_catalog_actions_unbound(): void
    {
        $unbound = $this->harness->productionProviderUnboundActions();

        self::assertCount(12, $unbound);
        foreach ($unbound as $action => $isUnbound) {
            self::assertTrue($isUnbound, $action);
        }
    }

    #[Test]
    public function exact_http_method_arrays_reject_implicit_head_and_options_drift(): void
    {
        $expected = HermeticReportingHttpHarness::expectedRoutes();

        self::assertTrue($this->harness->routeMethodsMatch($expected));
        self::assertFalse($this->harness->routeMethodsMatch(
            $this->harness->mutateExpectedMethod($expected, 'admin.reports.catalog', ['GET']),
        ));
        self::assertFalse($this->harness->routeMethodsMatch(
            $this->harness->mutateExpectedMethod($expected, 'admin.reports.runs.store', ['POST', 'OPTIONS']),
        ));
    }

    #[Test]
    public function hermetic_boundaries_are_installed_before_provider_and_route_work(): void
    {
        $proof = $this->harness->boundaryInstallationProof();

        self::assertTrue(
            $proof['installed_before_application']
            && $proof['forbidden_api_hits'] === []
            && $proof['output_fingerprint_unchanged'],
        );
        self::assertTrue($proof['installed_before_provider']);
        self::assertTrue($proof['installed_before_routes']);
        self::assertSame([], $proof['breaches']);
        self::assertSame([
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
        ], $proof['boundaries']);
    }
}
