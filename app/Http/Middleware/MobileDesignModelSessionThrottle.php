<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\MobileResponse;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class MobileDesignModelSessionThrottle
{
    public function __construct(private RateLimiter $limiter) {}

    public function handle(Request $request, Closure $next): Response
    {
        $actorId = (int) $request->user()?->id;
        $sessionId = (int) $request->route('sessionId');
        $budget = in_array($request->input('type'), ['cursor', 'camera'], true) ? 'motion' : 'control';
        $key = "design-model-session-events:{$actorId}:{$sessionId}:{$budget}";
        if ($actorId <= 0 || $sessionId <= 0 || $this->limiter->hit($key, 1) > 10) {
            return MobileResponse::error(trans_message('design_bim.errors.events_rate_limited'), 429);
        }

        return $next($request);
    }
}
