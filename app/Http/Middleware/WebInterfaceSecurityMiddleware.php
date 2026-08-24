<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\DTOs\Auth\WebAuthTokenPayload;
use App\Http\Responses\AdminResponse;
use App\Http\Responses\CustomerResponse;
use App\Http\Responses\LandingResponse;
use App\Models\User;
use App\Services\Auth\UserAuthSessionService;
use App\Services\Auth\WebAuthTokenService;
use App\Services\Organization\OrganizationContext;
use App\Services\Security\WebOriginPolicy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Fluent;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class WebInterfaceSecurityMiddleware
{
    public function __construct(
        private readonly WebAuthTokenService $tokens,
        private readonly UserAuthSessionService $sessions,
        private readonly AuthorizationService $authorization,
        private readonly WebOriginPolicy $origins,
    ) {}

    public function handle(Request $request, Closure $next, ?string $forcedAudience = null): Response
    {
        $audience = $forcedAudience ?? $this->audienceFor($request);

        if ($audience === null || $this->isPublicRoute($request) || $request->isMethod('OPTIONS')) {
            return $next($request);
        }

        $accessToken = $request->bearerToken();

        if ($accessToken === null || $accessToken === '') {
            return $this->error($audience, 401);
        }

        try {
            $payload = $this->tokens->parse($accessToken, $audience, 'access');
            $session = $this->sessions->findActiveByUuid($payload->sessionUuid);
            $user = User::query()->find($payload->userId);

            if ($session === null
                || ! $user instanceof User
                || ! $user->is_active
                || (int) $session->user_id !== (int) $user->id
            ) {
                return $this->error($audience, 401);
            }

            $organization = $this->resolveOrganization($user, $payload, $audience);

            if ($organization === false) {
                return $this->error($audience, 403);
            }

            $context = $organization !== null
                ? AuthorizationContext::getOrganizationContext((int) $organization->id)
                : AuthorizationContext::getSystemContext();

            if (! $this->hasInterfaceAccess($user, $audience, $context)
                || ! $this->hasRequiredInterfacePermission($user, $audience, $organization?->id)
            ) {
                return $this->error($audience, 403);
            }

            if (! $request->isMethodSafe() && ! $this->origins->allows($request->header('Origin'), $audience)) {
                return $this->error($audience, 403);
            }

            $this->bindAuthenticatedUser($request, $user, $payload, $session, $audience);

            if ($organization !== null) {
                $request->attributes->set('current_organization_id', (int) $organization->id);
                $request->attributes->set('current_organization', $organization);
                $user->current_organization_id = (int) $organization->id;
                App::instance(OrganizationContext::class, new OrganizationContext($organization));
            }

            return $next($request);
        } catch (Throwable) {
            return $this->error($audience, 401);
        }
    }

    private function audienceFor(Request $request): ?string
    {
        if ($request->is('api/v1/admin/*')) {
            return 'admin';
        }

        if ($request->is('api/v1/landing/*') || $request->is('api/lk/*')) {
            return 'lk';
        }

        if ($request->is('api/v1/customer/*') || $request->is('api/customer/*')) {
            return 'customer';
        }

        return null;
    }

    private function isPublicRoute(Request $request): bool
    {
        if ($request->is('api/v1/admin/auth/login')
            || $request->is('api/v1/admin/auth/refresh')
            || $request->is('api/v1/admin/auth/csrf')
        ) {
            return true;
        }

        if ($request->is('api/v1/customer/auth/register')
            || $request->is('api/v1/customer/auth/login')
            || $request->is('api/v1/customer/auth/forgot-password')
            || $request->is('api/v1/customer/auth/reset-password')
            || $request->is('api/v1/customer/auth/email/resend')
            || $request->is('api/v1/customer/auth/email/verify/*')
            || $request->is('api/v1/customer/auth/refresh')
            || $request->is('api/v1/customer/auth/csrf')
            || $request->is('api/v1/customer/invitations/*/login')
            || $request->is('api/v1/customer/invitations/*/register')
            || $request->is('api/v1/customer/invitations/*/decline')
            || ($request->isMethod('GET') && $request->is('api/v1/customer/invitations/*'))
        ) {
            return true;
        }

        return $request->is('api/v1/landing/auth/login')
            || $request->is('api/v1/landing/auth/register')
            || $request->is('api/v1/landing/auth/email/verification-notification')
            || $request->is('api/v1/landing/auth/password/email')
            || $request->is('api/v1/landing/auth/password/reset')
            || $request->is('api/v1/landing/auth/refresh')
            || $request->is('api/v1/landing/auth/csrf')
            || $request->is('api/v1/landing/email/verify/*')
            || $request->is('api/v1/landing/holding/public/*')
            || $request->is('api/v1/landing/user-management/invitation/*')
            || $request->is('api/v1/landing/dadata/*')
            || $request->is('api/v1/landing/landingAdminAuth/*');
    }

    private function resolveOrganization(User $user, WebAuthTokenPayload $payload, string $audience): mixed
    {
        if ($payload->organizationId === null) {
            return $audience === 'admin' ? null : false;
        }

        return $user->organizations()
            ->whereKey($payload->organizationId)
            ->wherePivot('is_active', true)
            ->first() ?: false;
    }

    private function hasRequiredInterfacePermission(User $user, string $audience, ?int $organizationId): bool
    {
        if ($audience !== 'admin') {
            return true;
        }

        if ($this->authorization->can($user, 'admin.access', ['context_type' => 'system'])) {
            return true;
        }

        return $organizationId !== null && $this->authorization->can($user, 'admin.access', [
            'context_type' => 'organization',
            'organization_id' => $organizationId,
        ]);
    }

    private function hasInterfaceAccess(User $user, string $audience, AuthorizationContext $context): bool
    {
        if ($this->authorization->canAccessInterface($user, $audience, $context)) {
            return true;
        }

        return $audience === 'admin'
            && $this->authorization->canAccessInterface($user, 'admin', AuthorizationContext::getSystemContext());
    }

    private function bindAuthenticatedUser(
        Request $request,
        User $user,
        WebAuthTokenPayload $payload,
        mixed $session,
        string $audience,
    ): void {
        $guard = $audience === 'admin' ? 'api_admin' : 'api_landing';
        Auth::shouldUse($guard);
        Auth::guard($guard)->setUser($user);
        $request->setUserResolver(static fn (): User => $user);
        $request->attributes->set('web_auth_audience', $audience);
        $request->attributes->set('web_auth_payload', $payload);
        $request->attributes->set('auth_session', $session);
        $request->attributes->set('token_payload', new Fluent([
            'sub' => (string) $payload->userId,
            'session_uuid' => $payload->sessionUuid,
            'organization_id' => $payload->organizationId,
        ]));
    }

    private function error(string $audience, int $status): Response
    {
        $message = $status === 401
            ? trans_message('errors.unauthenticated')
            : trans_message('auth.access_denied');

        return match ($audience) {
            'admin' => AdminResponse::error($message, $status),
            'customer' => CustomerResponse::error($message, $status),
            default => LandingResponse::error($message, $status),
        };
    }
}
