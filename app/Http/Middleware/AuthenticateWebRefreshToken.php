<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\UserAuthSessionService;
use App\Services\Auth\WebAuthTokenService;
use App\Services\Auth\WebRefreshCookieService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class AuthenticateWebRefreshToken
{
    public function __construct(
        private readonly WebRefreshCookieService $cookies,
        private readonly WebAuthTokenService $tokens,
        private readonly UserAuthSessionService $sessions,
    ) {}

    public function handle(Request $request, Closure $next, string $audience): Response
    {
        try {
            $refreshToken = $this->cookies->tokenFromRequest($request, $audience);

            if ($refreshToken === null) {
                return $this->error($audience);
            }

            $payload = $this->tokens->parse($refreshToken, $audience, 'refresh');
            $session = $this->sessions->findActiveByUuid($payload->sessionUuid);
            $user = User::query()->find($payload->userId);

            if ($session === null
                || ! $user instanceof User
                || ! $user->is_active
                || (int) $session->user_id !== (int) $user->id
            ) {
                return $this->error($audience);
            }

            if ($payload->organizationId !== null
                && ! $user->activeOrganizations()->whereKey($payload->organizationId)->exists()
            ) {
                $this->sessions->revoke($session, 'organization_membership_inactive');
                $this->tokens->invalidateRefreshSession($audience, $payload->sessionUuid);

                return $this->membershipError($audience);
            }

            $guard = $audience === 'admin' ? 'api_admin' : 'api_landing';
            Auth::shouldUse($guard);
            Auth::guard($guard)->setUser($user);
            $request->setUserResolver(static fn (): User => $user);
            $request->attributes->set('web_auth_audience', $audience);
            $request->attributes->set('web_refresh_payload', $payload);
            $request->attributes->set('web_refresh_token', $refreshToken);
            $request->attributes->set('auth_session', $session);

            return $next($request);
        } catch (Throwable) {
            return $this->error($audience);
        }
    }

    private function error(string $audience): Response
    {
        $message = trans_message('errors.unauthenticated');

        return match ($audience) {
            'admin' => \App\Http\Responses\AdminResponse::error($message, 401),
            'customer' => \App\Http\Responses\CustomerResponse::error($message, 401),
            default => \App\Http\Responses\LandingResponse::error($message, 401),
        };
    }

    private function membershipError(string $audience): Response
    {
        $message = trans_message('organization.access_denied');
        $extra = ['code' => 'organization_membership_inactive'];

        return match ($audience) {
            'admin' => \App\Http\Responses\AdminResponse::error($message, 403, null, $extra),
            'customer' => \App\Http\Responses\CustomerResponse::error($message, 403, null, $extra),
            default => \App\Http\Responses\LandingResponse::error($message, 403, null, $extra),
        };
    }
}
