<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\DTOs\Auth\WebAuthTokenPayload;
use App\Services\Auth\WebAuthTokenService;
use App\Services\Auth\WebAuthFailureResponder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VerifyWebCsrfToken
{
    public function __construct(private readonly WebAuthTokenService $tokens) {}

    public function handle(Request $request, Closure $next, string $audience): Response
    {
        $csrfToken = $request->header('X-CSRF-Token');

        if (! is_string($csrfToken) || $csrfToken === '') {
            return WebAuthFailureResponder::reject($request, $audience, 403, 'web_csrf_missing');
        }

        $refreshPayload = $request->attributes->get('web_refresh_payload');

        if ($refreshPayload instanceof WebAuthTokenPayload) {
            if (! is_string($refreshPayload->csrfToken) || ! hash_equals($refreshPayload->csrfToken, $csrfToken)) {
                return WebAuthFailureResponder::reject($request, $audience, 403, 'web_csrf_mismatch');
            }

            return $next($request);
        }

        $accessPayload = $request->attributes->get('web_auth_payload');

        if (! $accessPayload instanceof WebAuthTokenPayload
            || ! $this->tokens->matchesCurrentCsrfToken($accessPayload, $csrfToken)
        ) {
            return WebAuthFailureResponder::reject($request, $audience, 403, 'web_csrf_mismatch');
        }

        return $next($request);
    }

}
