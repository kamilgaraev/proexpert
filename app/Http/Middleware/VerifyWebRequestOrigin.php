<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Security\WebOriginPolicy;
use App\Services\Auth\WebAuthFailureResponder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VerifyWebRequestOrigin
{
    public function __construct(private readonly WebOriginPolicy $origins) {}

    public function handle(
        Request $request,
        Closure $next,
        string $audience,
        string $requireForSafeMethods = 'false',
    ): Response {
        $mustValidate = ! $request->isMethodSafe()
            || filter_var($requireForSafeMethods, FILTER_VALIDATE_BOOL);

        if ($mustValidate && ! $this->origins->allows($request->header('Origin'), $audience)) {
            return WebAuthFailureResponder::reject($request, $audience, 403, 'web_origin_forbidden');
        }

        return $next($request);
    }
}
