<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\DTOs\Auth\WebAuthTokenPayload;
use App\Services\Auth\WebAuthTokenService;
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
            return $this->error($audience);
        }

        $refreshPayload = $request->attributes->get('web_refresh_payload');

        if ($refreshPayload instanceof WebAuthTokenPayload) {
            if (! is_string($refreshPayload->csrfToken) || ! hash_equals($refreshPayload->csrfToken, $csrfToken)) {
                return $this->error($audience);
            }

            return $next($request);
        }

        $accessPayload = $request->attributes->get('web_auth_payload');

        if (! $accessPayload instanceof WebAuthTokenPayload
            || ! $this->tokens->matchesCurrentCsrfToken($accessPayload, $csrfToken)
        ) {
            return $this->error($audience);
        }

        return $next($request);
    }

    private function error(string $audience): Response
    {
        $message = trans_message('auth.access_denied');

        return match ($audience) {
            'admin' => \App\Http\Responses\AdminResponse::error($message, 403),
            'customer' => \App\Http\Responses\CustomerResponse::error($message, 403),
            default => \App\Http\Responses\LandingResponse::error($message, 403),
        };
    }
}
