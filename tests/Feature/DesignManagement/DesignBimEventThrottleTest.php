<?php

declare(strict_types=1);

namespace Tests\Feature\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Http\Middleware\ThrottleDesignModelSessionEvents;
use App\Models\User;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class DesignBimEventThrottleTest extends TestCase
{
    public function test_events_share_a_limit_per_participant_and_session_and_recover_after_expiry(): void
    {
        $middleware = new ThrottleDesignModelSessionEvents(new RateLimiter(new Repository(new ArrayStore)));
        Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00'));
        try {
            foreach (range(1, 10) as $number) {
                self::assertSame(204, $this->send($middleware, 1, 1, $number % 2 ? 'cursor' : 'camera'));
            }
            self::assertSame(429, $this->send($middleware, 1, 1, 'select'));
            self::assertSame(429, $this->send($middleware, 1, 1, 'cursor'));
            self::assertSame(204, $this->send($middleware, 2, 1, 'cursor'));
            self::assertSame(204, $this->send($middleware, 1, 2, 'cursor'));
            Carbon::setTestNow(Carbon::now()->addSeconds(2));
            self::assertSame(204, $this->send($middleware, 1, 1, 'camera'));
        } finally {
            Carbon::setTestNow();
        }
    }

    private function send(ThrottleDesignModelSessionEvents $middleware, int $userId, int $sessionId, string $type): int
    {
        $request = Request::create('/sessions/'.$sessionId.'/events', 'POST', ['type' => $type]);
        $user = new User;
        $user->setAttribute('id', $userId);
        $request->setUserResolver(static fn () => $user);
        $route = new Route('POST', 'sessions/{sessionId}/events', static fn () => null);
        $route->bind($request);
        $request->setRouteResolver(static fn () => $route);

        return $middleware->handle($request, static fn () => new Response('', 204))->getStatusCode();
    }
}
