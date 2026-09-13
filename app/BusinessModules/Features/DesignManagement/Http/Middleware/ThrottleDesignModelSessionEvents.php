<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ThrottleDesignModelSessionEvents
{
    public function __construct(private RateLimiter $limiter) {}

    public function handle(Request $request, Closure $next): Response
    {
        $actorId = (int) $request->user()?->id;
        $sessionId = (int) $request->route('sessionId');
        $key = "design-model-session-events:{$actorId}:{$sessionId}";

        if ($actorId <= 0 || $sessionId <= 0 || $this->limiter->hit($key, 1) > 10) {
            return response()->json(['success' => false, 'message' => trans_message('design_bim.errors.events_rate_limited')], 429);
        }

        return $next($request);
    }
}
