<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Http\Parser\Parser;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Tymon\JWTAuth\Exceptions\TokenBlacklistedException;
use Tymon\JWTAuth\Exceptions\JWTException;
use App\Services\LogService;

class JwtMiddleware
{
    public function handle(Request $request, Closure $next, $guard = null)
    {
        $isRefreshEndpoint = $request->is('*/auth/refresh');

        try {
            if (!$token = JWTAuth::getToken()) {
                LogService::authLog('auth_failed', [
                    'reason' => 'token_missing',
                    'ip' => $request->ip(),
                    'uri' => $request->getRequestUri(),
                ]);

                return \App\Http\Responses\AdminResponse::fromPayload([
                    'success' => false,
                    'message' => 'РўРѕРєРµРЅ РЅРµ РЅР°Р№РґРµРЅ'
                ], 401);
            }

            try {
                $payload = JWTAuth::setToken($token)->getPayload();
            } catch (TokenBlacklistedException $e) {
                LogService::authLog('auth_failed', [
                    'reason' => 'token_blacklisted',
                    'ip' => $request->ip(),
                    'uri' => $request->getRequestUri(),
                ]);

                return \App\Http\Responses\AdminResponse::fromPayload([
                    'success' => false,
                    'message' => 'РўРѕРєРµРЅ РІ С‡РµСЂРЅРѕРј СЃРїРёСЃРєРµ. Р’С‹РїРѕР»РЅРёС‚Рµ РїРѕРІС‚РѕСЂРЅС‹Р№ РІС…РѕРґ.',
                ], 401);
            }

            if ($guard) {
                auth()->shouldUse($guard);
            }

            $user = JWTAuth::parseToken()->authenticate();

            if (!$user) {
                LogService::authLog('auth_failed', [
                    'token_present' => true,
                    'reason' => 'user_not_found',
                    'ip' => $request->ip(),
                    'uri' => $request->getRequestUri(),
                ]);

                return \App\Http\Responses\AdminResponse::fromPayload([
                    'success' => false,
                    'message' => 'РџРѕР»СЊР·РѕРІР°С‚РµР»СЊ РЅРµ РЅР°Р№РґРµРЅ'
                ], 401);
            }

            if (JWTAuth::manager()->getBlacklist()->has($payload)) {
                LogService::authLog('auth_failed', [
                    'user_id' => $user->id,
                    'reason' => 'token_blacklisted_check',
                    'ip' => $request->ip(),
                    'uri' => $request->getRequestUri(),
                ]);

                return \App\Http\Responses\AdminResponse::fromPayload([
                    'success' => false,
                    'message' => 'РЎРµСЃСЃРёСЏ Р·Р°РІРµСЂС€РµРЅР°. Р’С‹РїРѕР»РЅРёС‚Рµ РїРѕРІС‚РѕСЂРЅС‹Р№ РІС…РѕРґ.'
                ], 401);
            }

            $request->attributes->add(['token_payload' => $payload]);
            $request->attributes->add(['jwt_token' => (string)$token]);

            LogService::authLog('auth_success', [
                'user_id' => $user->id,
                'guard' => $guard,
                'ip' => $request->ip(),
                'uri' => $request->getRequestUri(),
            ]);

        } catch (TokenExpiredException $e) {
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

            return \App\Http\Responses\AdminResponse::fromPayload([
                'success' => false,
                'message' => 'РўРѕРєРµРЅ РёСЃС‚РµРє'
            ], 401);

        } catch (TokenInvalidException $e) {
            LogService::authLog('token_rejected', [
                'reason' => 'token_invalid',
                'ip' => $request->ip(),
                'uri' => $request->getRequestUri(),
            ]);

            return \App\Http\Responses\AdminResponse::fromPayload([
                'success' => false,
                'message' => 'РўРѕРєРµРЅ РЅРµРґРµР№СЃС‚РІРёС‚РµР»РµРЅ'
            ], 401);

        } catch (JWTException $e) {
            LogService::exception($e, [
                'action' => 'token_validation',
                'ip' => $request->ip(),
                'uri' => $request->getRequestUri(),
                'error_message' => $e->getMessage()
            ]);

            return \App\Http\Responses\AdminResponse::fromPayload([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РїСЂРё РѕР±СЂР°Р±РѕС‚РєРµ С‚РѕРєРµРЅР°: ' . $e->getMessage()
            ], 500);
        }

        return $next($request);
    }
}
