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
    /**
     * Проверка JWT токена.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  $guard Имя guard для аутентификации
     * @return mixed
     */
    public function handle(Request $request, Closure $next, $guard = null)
    {
        try {
            // Проверяем наличие токена в запросе
            if (!$token = JWTAuth::getToken()) {
                LogService::authLog('auth_failed', [
                    'reason' => 'token_missing',
                    'ip' => $request->ip(),
                    'uri' => $request->getRequestUri(),
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Токен не найден'
                ], 401);
            }
            
            // Проверяем токен на действительность (включая черный список)
            try {
                $payload = JWTAuth::getPayload($token);
            } catch (TokenBlacklistedException $e) {
                LogService::authLog('auth_failed', [
                    'reason' => 'token_blacklisted',
                    'ip' => $request->ip(),
                    'uri' => $request->getRequestUri(),
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Токен в черном списке. Выполните повторный вход.',
                ], 401);
            }
            
            // Устанавливаем guard, если указан
            if ($guard) {
                auth()->shouldUse($guard);
            }
            
            // Пытаемся получить пользователя по токену
            $user = JWTAuth::parseToken()->authenticate();
            
            if (!$user) {
                LogService::authLog('auth_failed', [
                    'token_present' => true,
                    'reason' => 'user_not_found',
                    'ip' => $request->ip(),
                    'uri' => $request->getRequestUri(),
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Пользователь не найден'
                ], 401);
            }
            
            // Проверяем, есть ли токен в черном списке еще раз
            if (JWTAuth::manager()->getBlacklist()->has($payload)) {
                LogService::authLog('auth_failed', [
                    'user_id' => $user->id,
                    'reason' => 'token_blacklisted_check',
                    'ip' => $request->ip(),
                    'uri' => $request->getRequestUri(),
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Сессия завершена. Выполните повторный вход.'
                ], 401);
            }
            
            // Добавляем информацию о токене в запрос
            $request->attributes->add(['token_payload' => $payload]);
            $request->attributes->add(['jwt_token' => (string)$token]);
            
            LogService::authLog('auth_success', [
                'user_id' => $user->id,
                'guard' => $guard,
                'ip' => $request->ip(),
                'uri' => $request->getRequestUri(),
            ]);
            
        } catch (TokenExpiredException $e) {
            LogService::authLog('token_rejected', [
                'reason' => 'token_expired',
                'ip' => $request->ip(),
                'uri' => $request->getRequestUri(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Токен истек'
            ], 401);
            
        } catch (TokenInvalidException $e) {
            LogService::authLog('token_rejected', [
                'reason' => 'token_invalid',
                'ip' => $request->ip(),
                'uri' => $request->getRequestUri(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Токен недействителен'
            ], 401);
            
        } catch (JWTException $e) {
            LogService::exception($e, [
                'action' => 'token_validation',
                'ip' => $request->ip(),
                'uri' => $request->getRequestUri(),
                'error_message' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при обработке токена: ' . $e->getMessage()
            ], 500);
        }

        return $next($request);
    }
} 