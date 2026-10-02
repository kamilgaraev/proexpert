<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Monitoring\TracingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class TraceHttpRequest
{
    public function __construct(private readonly TracingService $tracing)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->path(), ['up', 'ready', 'metrics'], true)) {
            return $next($request);
        }
        if (! $this->tracing->supportsSpans()) {
            return $this->tracing->correlateHttp($request, $next);
        }

        try {
            [$span, $scope] = $this->tracing->startHttp($request);
        } catch (Throwable) {
            return $next($request);
        }

        try {
            $response = $next($request);
            $this->tracing->finishHttp($span, $request, $response);

            if ($span->getContext()->isValid()) {
                $response->headers->set('X-Trace-ID', $span->getContext()->getTraceId());
            }

            return $response;
        } catch (Throwable $exception) {
            $this->tracing->markError($span, $exception);

            throw $exception;
        } finally {
            $span->end();
            $scope->detach();
            $this->tracing->endHttpContext($request);
            if ($span->getContext()->isSampled()) {
                $this->tracing->logCompletedTrace($span, 'http');
            }
            $this->tracing->flush();
        }
    }
}
