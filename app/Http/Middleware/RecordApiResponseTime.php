<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class RecordApiResponseTime
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/*') || $request->isMethod('OPTIONS')) {
            return $next($request);
        }

        $startedAt = hrtime(true);
        $response = null;
        $statusCode = null;

        try {
            $response = $next($request);
            $statusCode = $response->getStatusCode();

            return $response;
        } catch (Throwable $exception) {
            if ($exception instanceof HttpExceptionInterface) {
                $statusCode = $exception->getStatusCode();
            }

            throw $exception;
        } finally {
            $route = $request->route();
            $traceId = $response?->headers->get('X-Trace-ID');
            $context = [
                'method' => $request->method(),
                'route' => $route instanceof Route ? $route->uri() : 'unmatched',
                'status_code' => $statusCode,
                'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
            ];

            if (is_string($traceId) && preg_match('/^[0-9a-f]{32}$/', $traceId) === 1) {
                $context['trace_id'] = $traceId;
            }

            try {
                Log::channel('api_latency')->info('api_response_timing', $context);
            } catch (Throwable) {
            }
        }
    }
}
