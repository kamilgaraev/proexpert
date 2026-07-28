<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\AdminResponse;
use App\Http\Responses\LandingResponse;
use App\Http\Responses\MobileResponse;
use App\Services\LogService;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenBlacklistedException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Tymon\JWTAuth\JWT;

use function trans_message;

class JwtMiddleware
{
    public function __construct(
        private readonly JWT $jwt,
    ) {
    }

    public function handle(Request $request, Closure $next, ?string $guard = null): Response
    {
        $webAudience = $request->attributes->get('web_auth_audience');

        if (is_string($webAudience)) {
            $expectedAudience = match ($guard) {
                'api_landing' => 'lk',
                'api_admin' => 'admin',
                default => null,
            };

            if ($expectedAudience === null || hash_equals($expectedAudience, $webAudience)) {
                return $next($request);
            }

            return $this->errorResponse($request, $guard, 'auth.token_invalid', Response::HTTP_UNAUTHORIZED);
        }

        $isRefreshEndpoint = $this->isRefreshEndpoint($request);

        $token = $this->jwt->getToken();

        try {
            if (! $token) {
                LogService::authLog('auth_failed', [
                    'reason' => 'token_missing',
                    'ip' => $request->ip(),
                    'uri' => $request->getRequestUri(),
                ]);

                return $this->errorResponse($request, $guard, 'auth.token_missing', Response::HTTP_UNAUTHORIZED);
            }

            try {
                $payload = $this->jwt->setToken($token)->getPayload();
            } catch (TokenBlacklistedException) {
                LogService::authLog('auth_failed', [
                    'reason' => 'token_blacklisted',
                    'ip' => $request->ip(),
                    'uri' => $request->getRequestUri(),
                ]);

                return $this->errorResponse($request, $guard, 'auth.security_session_expired', Response::HTTP_UNAUTHORIZED);
            }

            if ($guard) {
                auth()->shouldUse($guard);
            }

            $user = $this->authenticateRequestUser($request, $guard);

            if (! $user) {
                LogService::authLog('auth_failed', [
                    'token_present' => true,
                    'reason' => 'user_not_found',
                    'ip' => $request->ip(),
                    'uri' => $request->getRequestUri(),
                ]);

                return $this->errorResponse($request, $guard, 'auth.not_authenticated', Response::HTTP_UNAUTHORIZED);
            }

            if ($this->jwt->manager()->getBlacklist()->has($payload)) {
                LogService::authLog('auth_failed', [
                    'user_id' => $user->getAuthIdentifier(),
                    'reason' => 'token_blacklisted_check',
                    'ip' => $request->ip(),
                    'uri' => $request->getRequestUri(),
                ]);

                return $this->errorResponse($request, $guard, 'auth.security_session_expired', Response::HTTP_UNAUTHORIZED);
            }

            $request->attributes->add(['token_payload' => $payload]);
            $request->attributes->add(['jwt_token' => (string) $token]);

            LogService::authLog('auth_success', [
                'user_id' => $user->getAuthIdentifier(),
                'guard' => $guard,
                'ip' => $request->ip(),
                'uri' => $request->getRequestUri(),
            ]);
        } catch (TokenExpiredException) {
            if ($isRefreshEndpoint) {
                try {
                    $payload = $this->jwt->manager()
                        ->setRefreshFlow()
                        ->decode($token);
                    $request->attributes->add(['token_payload' => $payload]);
                    $request->attributes->add(['jwt_token' => (string) $token]);
                } catch (JWTException $exception) {
                    LogService::exception($exception, [
                        'action' => 'token_refresh_payload_decode',
                        'ip' => $request->ip(),
                        'uri' => $request->getRequestUri(),
                    ]);

                    return $this->errorResponse($request, $guard, 'auth.token_error', Response::HTTP_UNAUTHORIZED);
                } finally {
                    $this->jwt->manager()->setRefreshFlow(false);
                }

                LogService::authLog('token_expired_refresh', [
                    'reason' => 'token_expired_allowed_for_refresh',
                    'ip' => $request->ip(),
                    'uri' => $request->getRequestUri(),
                ]);

                return $next($request);
            }

            LogService::authLog('token_rejected', [
                'reason' => 'token_expired',
                'ip' => $request->ip(),
                'uri' => $request->getRequestUri(),
            ]);

            return $this->errorResponse($request, $guard, 'auth.token_expired', Response::HTTP_UNAUTHORIZED);
        } catch (TokenInvalidException) {
            LogService::authLog('token_rejected', [
                'reason' => 'token_invalid',
                'ip' => $request->ip(),
                'uri' => $request->getRequestUri(),
            ]);

            return $this->errorResponse($request, $guard, 'auth.token_invalid', Response::HTTP_UNAUTHORIZED);
        } catch (JWTException $exception) {
            LogService::exception($exception, [
                'action' => 'token_validation',
                'ip' => $request->ip(),
                'uri' => $request->getRequestUri(),
                'error_message' => $exception->getMessage(),
            ]);

            return $this->errorResponse($request, $guard, 'auth.token_error', Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }

    private function authenticateRequestUser(Request $request, ?string $guard): ?Authenticatable
    {
        $user = $guard ? auth($guard)->user() : auth()->user();

        if (! $user instanceof Authenticatable) {
            return null;
        }

        $request->setUserResolver(static function (?string $requestedGuard = null) use ($guard, $user): ?Authenticatable {
            if ($requestedGuard === null || $requestedGuard === $guard) {
                return $user;
            }

            return auth($requestedGuard)->user();
        });

        return $user;
    }

    private function errorResponse(Request $request, ?string $guard, string $messageKey, int $statusCode): JsonResponse
    {
        $responseClass = $this->responseClass($request, $guard);

        return $responseClass::error(trans_message($messageKey), $statusCode);
    }

    private function isRefreshEndpoint(Request $request): bool
    {
        return $request->is('*/auth/refresh') || $request->is('*/landingAdminAuth/refresh');
    }

    private function responseClass(Request $request, ?string $guard): string
    {
        $path = $request->path();

        if ($guard === 'api_mobile' || str_starts_with($path, 'api/v1/mobile/') || str_starts_with($path, 'api/mobile/')) {
            return MobileResponse::class;
        }

        if (
            in_array($guard, ['api_landing', 'api_landing_admin'], true)
            || str_starts_with($path, 'api/v1/landing/')
            || str_starts_with($path, 'api/landing/')
            || str_starts_with($path, 'api/v1/customer/')
            || str_starts_with($path, 'api/v1/holding-api/')
        ) {
            return LandingResponse::class;
        }

        return AdminResponse::class;
    }
}
