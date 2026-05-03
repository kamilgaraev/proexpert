<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Monitoring\PrometheusService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class MetricsController extends Controller
{
    private const PLAIN_TEXT_HEADERS = [
        'Content-Type' => 'text/plain; charset=utf-8',
    ];

    protected PrometheusService $prometheus;

    public function __construct(PrometheusService $prometheus)
    {
        $this->prometheus = $prometheus;
    }

    public function metrics(Request $request): Response
    {
        if (! $this->hasValidMetricsToken($request)) {
            return response('Forbidden', Response::HTTP_FORBIDDEN, self::PLAIN_TEXT_HEADERS);
        }

        try {
            $metrics = $this->prometheus->renderMetrics();

            return response($metrics, Response::HTTP_OK, self::PLAIN_TEXT_HEADERS);
        } catch (Throwable $exception) {
            Log::error('Metrics rendering failed', [
                'exception' => $exception::class,
                'path' => $request->path(),
            ]);

            return response("Metrics unavailable\n", Response::HTTP_SERVICE_UNAVAILABLE, self::PLAIN_TEXT_HEADERS);
        }
    }

    private function hasValidMetricsToken(Request $request): bool
    {
        $expectedToken = config('monitoring.metrics_token');

        if (! is_string($expectedToken) || $expectedToken === '') {
            return false;
        }

        $actualToken = $request->bearerToken() ?: $request->header('X-Prometheus-Token');

        if (! is_string($actualToken) || $actualToken === '') {
            return false;
        }

        return hash_equals($expectedToken, $actualToken);
    }

}
