<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Auth\JwtCookieService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UseJwtCookieForAuthorization
{
    public function __construct(
        private readonly JwtCookieService $jwtCookieService
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->usesWebInterfaceAuthentication($request)) {
            return $next($request);
        }

        if (!$request->headers->has('Authorization')) {
            $token = $this->jwtCookieService->tokenFromRequest($request);

            if ($token !== null) {
                $request->headers->set('Authorization', 'Bearer ' . $token);
            }
        }

        return $next($request);
    }

    private function usesWebInterfaceAuthentication(Request $request): bool
    {
        return $request->is('api/v1/admin/*')
            || $request->is('api/v1/landing/*')
            || $request->is('api/lk/*');
    }
}
