<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\AdminResponse;
use App\Http\Responses\LandingResponse;
use App\Http\Responses\MobileResponse;
use App\Services\Auth\UserAuthSessionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAuthSessionIsActive
{
    public function __construct(private readonly UserAuthSessionService $sessions)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!(bool) config('auth_tokens.sessions.enabled', true)) {
            return $next($request);
        }

        $payload = $request->attributes->get('token_payload');
        $sessionUuid = $payload?->get('session_uuid');

        if (!$sessionUuid && !(bool) config('auth_tokens.sessions.enforce', false)) {
            return $next($request);
        }

        $authSession = $this->sessions->findActiveByUuid($sessionUuid);

        if (!$authSession || !$authSession->isActive()) {
            return $this->error($request, trans_message('auth.security_session_expired'));
        }

        $user = $request->user();
        if (!$user || (int) $authSession->user_id !== (int) $user->id) {
            return $this->error($request, trans_message('auth.security_session_expired'));
        }

        $this->sessions->touch($authSession);
        $request->attributes->set('auth_session', $authSession);

        return $next($request);
    }

    private function error(Request $request, string $message): Response
    {
        $path = $request->path();

        if (str_contains($path, 'api/v1/admin')) {
            return AdminResponse::error($message, 401);
        }

        if (str_contains($path, 'api/v1/mobile')) {
            return MobileResponse::error($message, 401);
        }

        return LandingResponse::error($message, 401);
    }
}
