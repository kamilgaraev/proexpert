<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Facades\JWTAuth as JWTAuthFacade;
use Tymon\JWTAuth\JWT;
use Tymon\JWTAuth\JWTAuth;

final class ResetJwtRequestState
{
    public function __construct(
        private readonly Application $app,
        private readonly JWT $jwt,
        private readonly JWTAuth $jwtAuth,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->jwt->unsetToken()->setRequest($request);
        $this->jwtAuth->unsetToken()->setRequest($request);

        $facadeJwtAuth = JWTAuthFacade::getFacadeRoot();
        if ($facadeJwtAuth instanceof JWTAuth) {
            $facadeJwtAuth->unsetToken()->setRequest($request);
        }
        Facade::clearResolvedInstance('tymon.jwt.auth');

        if ($this->app->resolved('tymon.jwt.auth')) {
            $this->app->forgetInstance('tymon.jwt.auth');
        }

        if ($this->app->resolved('tymon.jwt.provider.auth')) {
            $this->app->forgetInstance('tymon.jwt.provider.auth');
        }

        if ($this->app->resolved('auth.driver')) {
            $this->app->forgetInstance('auth.driver');
        }

        if ($this->app->resolved('auth')) {
            $auth = $this->app->make('auth');
            $auth->setApplication($this->app);
            $auth->forgetGuards();
            $auth->setDefaultDriver((string) config('auth.default_guard', 'web'));
        }

        return $next($request);
    }
}
