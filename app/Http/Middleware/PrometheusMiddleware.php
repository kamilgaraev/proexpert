<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Services\Monitoring\PrometheusService;
use Symfony\Component\HttpFoundation\Response;

class PrometheusMiddleware
{
    protected PrometheusService $prometheus;

    public function __construct(PrometheusService $prometheus)
    {
        $this->prometheus = $prometheus;
    }

    public function handle(Request $request, Closure $next)
    {
        $startTime = microtime(true);
        
        $response = $next($request);
        
        $duration = microtime(true) - $startTime;
        $method = $request->method();
        $route = $request->route() ? $request->route()->getName() ?: $request->route()->uri() : 'unknown';
        $status = $response->getStatusCode();

        $this->prometheus->incrementHttpRequests($method, $route, $status);
        $this->prometheus->observeRequestDuration($method, $route, $duration);

        return $response;
    }
} 