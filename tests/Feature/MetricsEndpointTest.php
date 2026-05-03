<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Monitoring\PrometheusService;
use Illuminate\Foundation\Testing\TestCase as LaravelTestCase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Psr\Log\LoggerInterface;

class MetricsEndpointTest extends LaravelTestCase
{
    public function test_metrics_endpoint_rejects_requests_without_token(): void
    {
        config()->set('monitoring.metrics_token', 'prometheus-secret');
        $this->mockPrometheusMiddlewareOnly();

        $this->get('/metrics')
            ->assertForbidden()
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    public function test_metrics_endpoint_returns_plain_text_metrics_with_bearer_token(): void
    {
        config()->set('monitoring.metrics_token', 'prometheus-secret');
        $this->mockPrometheusMetrics("# HELP app_up Application availability\napp_up 1\n");

        $this->withHeader('Authorization', 'Bearer prometheus-secret')
            ->get('/metrics')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
            ->assertContent("# HELP app_up Application availability\napp_up 1\n");
    }

    public function test_metrics_endpoint_returns_plain_text_metrics_with_prometheus_token_header(): void
    {
        config()->set('monitoring.metrics_token', 'prometheus-secret');
        $this->mockPrometheusMetrics("app_up 1\n");

        $this->withHeader('X-Prometheus-Token', 'prometheus-secret')
            ->get('/metrics')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
            ->assertContent("app_up 1\n");
    }

    public function test_metrics_endpoint_returns_safe_plain_text_service_unavailable_response(): void
    {
        config()->set('monitoring.metrics_token', 'prometheus-secret');
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')
            ->once()
            ->with('Metrics rendering failed', Mockery::on(static function (array $context): bool {
                return $context['exception'] === \RuntimeException::class
                    && $context['path'] === 'metrics'
                    && ! array_key_exists('message', $context);
            }));
        Log::swap($logger);

        $prometheus = Mockery::mock(PrometheusService::class);
        $prometheus->shouldReceive('incrementHttpRequests')->zeroOrMoreTimes();
        $prometheus->shouldReceive('observeRequestDuration')->zeroOrMoreTimes();
        $prometheus->shouldReceive('renderMetrics')
            ->once()
            ->andThrow(new \RuntimeException('storage backend exploded'));

        $this->app->instance(PrometheusService::class, $prometheus);

        $this->withHeader('X-Prometheus-Token', 'prometheus-secret')
            ->get('/metrics')
            ->assertServiceUnavailable()
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
            ->assertSee('Metrics unavailable')
            ->assertDontSee('storage backend exploded')
            ->assertDontSee('RuntimeException')
            ->assertDontSee('Stack trace');
    }

    private function mockPrometheusMetrics(string $metrics): void
    {
        $prometheus = Mockery::mock(PrometheusService::class);
        $prometheus->shouldReceive('incrementHttpRequests')->zeroOrMoreTimes();
        $prometheus->shouldReceive('observeRequestDuration')->zeroOrMoreTimes();
        $prometheus->shouldReceive('renderMetrics')
            ->once()
            ->andReturn($metrics);

        $this->app->instance(PrometheusService::class, $prometheus);
    }

    private function mockPrometheusMiddlewareOnly(): void
    {
        $prometheus = Mockery::mock(PrometheusService::class);
        $prometheus->shouldReceive('incrementHttpRequests')->zeroOrMoreTimes();
        $prometheus->shouldReceive('observeRequestDuration')->zeroOrMoreTimes();
        $prometheus->shouldReceive('renderMetrics')->never();

        $this->app->instance(PrometheusService::class, $prometheus);
    }
}
