<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use App\BusinessModules\Core\Reporting\Http\Admin\Controllers\ReportSubscriptionController;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\CreateReportSubscriptionRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\ListReportSubscriptionsRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\ReportSubscriptionRouteRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\RunReportSubscriptionNowRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\UpdateReportSubscriptionRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Route;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\Reporting\HermeticReportingHttpHarness;

final class ReportSubscriptionRouteContractTest extends TestCase
{
    private HermeticReportingHttpHarness $harness;

    protected function setUp(): void
    {
        parent::setUp();

        $this->harness = new HermeticReportingHttpHarness;
    }

    public function test_subscription_routes_are_exactly_the_seven_canonical_reports_endpoints(): void
    {
        self::assertSame($this->expectedRoutes(), $this->subscriptionRoutes());
    }

    public function test_subscription_routes_do_not_use_legacy_reporting_prefix(): void
    {
        $legacyRoutes = array_filter(
            $this->harness->router()->getRoutes()->getRoutes(),
            static fn (Route $route): bool => str_starts_with($route->uri(), 'api/v1/admin/reporting'),
        );

        self::assertSame([], array_values($legacyRoutes));
    }

    public function test_every_subscription_route_requires_view_and_manage_permissions(): void
    {
        $routes = $this->subscriptionRouteInstances();

        self::assertCount(7, $routes);

        foreach ($routes as $route) {
            self::assertContains('authorize:reports.view', $route->gatherMiddleware(), $route->getName());
            self::assertContains('authorize:reports.manage', $route->gatherMiddleware(), $route->getName());
        }
    }

    public function test_routes_target_the_thin_subscription_controller_contract(): void
    {
        $controller = new ReflectionClass(ReportSubscriptionController::class);
        $declaredMethods = array_values(array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            array_filter(
                $controller->getMethods(ReflectionMethod::IS_PUBLIC),
                static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $controller->getName()
                    && $method->getName() !== '__construct',
            ),
        ));
        sort($declaredMethods);

        self::assertSame(['destroy', 'index', 'pause', 'resume', 'runNow', 'store', 'update'], $declaredMethods);

        foreach ($this->expectedRequests() as $method => $request) {
            $reflection = $controller->getMethod($method);
            $parameters = $reflection->getParameters();

            self::assertCount(1, $parameters, $method);
            self::assertSame($request, $parameters[0]->getType()?->getName(), $method);
            self::assertSame(JsonResponse::class, $reflection->getReturnType()?->getName(), $method);
        }

        foreach ($this->subscriptionRouteInstances() as $route) {
            self::assertSame(
                ReportSubscriptionController::class.'@'.$this->actionForRoute($route->getName()),
                $route->getActionName(),
                $route->getName(),
            );
        }
    }

    private function expectedRoutes(): array
    {
        return [
            'admin.reports.subscriptions.index' => [['GET', 'HEAD'], 'api/v1/admin/reports/subscriptions'],
            'admin.reports.subscriptions.store' => [['POST'], 'api/v1/admin/reports/subscriptions'],
            'admin.reports.subscriptions.update' => [['PATCH'], 'api/v1/admin/reports/subscriptions/{subscriptionId}'],
            'admin.reports.subscriptions.destroy' => [['DELETE'], 'api/v1/admin/reports/subscriptions/{subscriptionId}'],
            'admin.reports.subscriptions.pause' => [['POST'], 'api/v1/admin/reports/subscriptions/{subscriptionId}/pause'],
            'admin.reports.subscriptions.resume' => [['POST'], 'api/v1/admin/reports/subscriptions/{subscriptionId}/resume'],
            'admin.reports.subscriptions.run-now' => [['POST'], 'api/v1/admin/reports/subscriptions/{subscriptionId}/run-now'],
        ];
    }

    private function subscriptionRoutes(): array
    {
        $routes = [];
        foreach ($this->subscriptionRouteInstances() as $route) {
            $routes[$route->getName()] = [$route->methods(), $route->uri()];
        }

        return $routes;
    }

    private function subscriptionRouteInstances(): array
    {
        return array_values(array_filter(
            $this->harness->router()->getRoutes()->getRoutes(),
            static fn (Route $route): bool => str_starts_with($route->uri(), 'api/v1/admin/reports/subscriptions'),
        ));
    }

    /** @return array<string, class-string> */
    private function expectedRequests(): array
    {
        return [
            'index' => ListReportSubscriptionsRequest::class,
            'store' => CreateReportSubscriptionRequest::class,
            'update' => UpdateReportSubscriptionRequest::class,
            'destroy' => ReportSubscriptionRouteRequest::class,
            'pause' => ReportSubscriptionRouteRequest::class,
            'resume' => ReportSubscriptionRouteRequest::class,
            'runNow' => RunReportSubscriptionNowRequest::class,
        ];
    }

    private function actionForRoute(?string $name): string
    {
        return match ($name) {
            'admin.reports.subscriptions.index' => 'index',
            'admin.reports.subscriptions.store' => 'store',
            'admin.reports.subscriptions.update' => 'update',
            'admin.reports.subscriptions.destroy' => 'destroy',
            'admin.reports.subscriptions.pause' => 'pause',
            'admin.reports.subscriptions.resume' => 'resume',
            'admin.reports.subscriptions.run-now' => 'runNow',
            default => self::fail('Unexpected subscription route name: '.(string) $name),
        };
    }
}
