<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\AdminResponse;
use App\Http\Responses\LandingResponse;
use App\Http\Responses\MobileResponse;
use App\Services\LogService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenBlacklistedException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Tymon\JWTAuth\Facades\JWTAuth;

use function trans_message;

class JwtMiddleware
{
    public function handle(Request $request, Closure $next, ?string $guard = null): Response
    {
        $isRefreshEndpoint = $request->is('*/auth/refresh');

        try {
            if (! ($token = JWTAuth::getToken())) {
                LogService::authLog('auth_failed', [
                    'reason' => 'token_missing',
                    'ip' => $request->ip(),
                    'uri' => $request->getRequestUri(),
                ]);

                return $this->errorResponse($request, $guard, 'auth.token_missing', Response::HTTP_UNAUTHORIZED);
            }

            try {
                $payload = JWTAuth::setToken($token)->getPayload();
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

            $user = JWTAuth::parseToken()->authenticate();

            if (! $user) {
                LogService::authLog('auth_failed', [
                    'token_present' => true,
                    'reason' => 'user_not_found',
                    'ip' => $request->ip(),
                    'uri' => $request->getRequestUri(),
                ]);

                return $this->errorResponse($request, $guard, 'auth.not_authenticated', Response::HTTP_UNAUTHORIZED);
            }

            if (JWTAuth::manager()->getBlacklist()->has($payload)) {
                LogService::authLog('auth_failed', [
                    'user_id' => $user->id,
                    'reason' => 'token_blacklisted_check',
                    'ip' => $request->ip(),
                    'uri' => $request->getRequestUri(),
                ]);

                return $this->errorResponse($request, $guard, 'auth.security_session_expired', Response::HTTP_UNAUTHORIZED);
            }

            $request->attributes->add(['token_payload' => $payload]);
            $request->attributes->add(['jwt_token' => (string) $token]);

            LogService::authLog('auth_success', [
                'user_id' => $user->id,
                'guard' => $guard,
                'ip' => $request->ip(),
                'uri' => $request->getRequestUri(),
            ]);
        } catch (TokenExpiredException) {
            if ($isRefreshEndpoint) {
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

    private function errorResponse(Request $request, ?string $guard, string $messageKey, int $statusCode): JsonResponse
    {
        $responseClass = $this->responseClass($request, $guard);

        return $responseClass::error(trans_message($messageKey), $statusCode);
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
