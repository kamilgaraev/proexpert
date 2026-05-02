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
        if (!$request->headers->has('Authorization')) {
            $token = $this->jwtCookieService->tokenFromRequest($request);

            if ($token !== null) {
                $request->headers->set('Authorization', 'Bearer ' . $token);
            }
        }

        return $next($request);
    }
}
