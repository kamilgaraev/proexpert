<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Security\WebOriginPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VerifyWebRequestOrigin
{
    public function __construct(private readonly WebOriginPolicy $origins)
    {
    }

    public function handle(
        Request $request,
        Closure $next,
        string $audience,
        string $requireForSafeMethods = 'false',
    ): Response
    {
        $mustValidate = ! $request->isMethodSafe()
            || filter_var($requireForSafeMethods, FILTER_VALIDATE_BOOL);

        if ($mustValidate && ! $this->origins->allows($request->header('Origin'), $audience)) {
            $message = trans_message('auth.access_denied');

            return $audience === 'admin'
                ? \App\Http\Responses\AdminResponse::error($message, 403)
                : \App\Http\Responses\LandingResponse::error($message, 403);
        }

        return $next($request);
    }
}
