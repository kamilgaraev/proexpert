<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;

class CorsMiddleware
{
    /**
     * Обрабатывает входящий запрос.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Получаем Origin из заголовка запроса
        $origin = $request->header('Origin');
        
        // Логируем для отладки
        Log::info('CORS Middleware вызван', [
            'request_method' => $request->method(),
            'origin' => $origin,
            'uri' => $request->getRequestUri(),
            'all_headers' => $request->headers->all(),
            'app_env' => app()->environment()
        ]);
        
        // Получаем конфигурацию CORS
        $allowedOrigins = Config::get('cors.allowed_origins', []);
        $allowedOriginsPatterns = Config::get('cors.allowed_origins_patterns', []);
        $allowedMethods = Config::get('cors.allowed_methods', ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']);
        $allowedHeaders = Config::get('cors.allowed_headers', ['Content-Type', 'X-Auth-Token', 'Origin', 'Authorization', 'X-Requested-With']);
        $exposedHeaders = Config::get('cors.exposed_headers', []);
        $maxAge = Config::get('cors.max_age', 86400);
        $allowAnyOriginInDev = Config::get('cors.allow_any_origin_in_dev', false);
        
        // Определяем, доступен ли запрошенный origin
        $allowedOrigin = null;
        $allowCredentials = 'false';
        $originMatched = false;
        
        // Если мы в режиме разработки и настройка разрешает любой origin
        if (app()->environment('local') && $allowAnyOriginInDev) {
            $allowedOrigin = $origin ?: '*';
            $allowCredentials = ($allowedOrigin === '*') ? 'false' : 'true';
            $originMatched = true;
        } 
        // Иначе проверяем по списку разрешенных
        else if ($origin) {
            if (in_array($origin, $allowedOrigins)) {
                $allowedOrigin = $origin;
                $allowCredentials = 'true';
                $originMatched = true;
            } else {
                foreach ($allowedOriginsPatterns as $pattern) {
                    if (preg_match($pattern, $origin)) {
                        $allowedOrigin = $origin;
                        $allowCredentials = 'true';
                        $originMatched = true;
                        break;
                    }
                }
            }
            
            if (!$originMatched) {
                // В режиме разработки можем быть более снисходительными
                if (app()->environment('local')) {
                    Log::warning('CORS: Принимаем запрос с неуказанного origin в режиме разработки', [
                        'origin' => $origin
                    ]);
                    $allowedOrigin = $origin;
                    $allowCredentials = 'true';
                    $originMatched = true;
                } else {
                    // В продакшене для prohelper.pro доменов разрешаем
                    if ($origin && (strpos($origin, '.prohelper.pro') !== false || $origin === 'https://prohelper.pro')) {
                        Log::warning('CORS: Принимаем prohelper.pro домен не из списка', [
                            'origin' => $origin
                        ]);
                        $allowedOrigin = $origin;
                        $allowCredentials = 'true';
                        $originMatched = true;
                    } else {
                        Log::warning('CORS: Отклонен запрос с недопустимого origin', [
                            'origin' => $origin,
                            'allowed_origins' => $allowedOrigins,
                            'allowed_patterns' => $allowedOriginsPatterns
                        ]);
                        $allowedOrigin = 'null';
                        $allowCredentials = 'false';
                    }
                }
            }
        } else {
            // Если origin не указан, используем wildcard (только для запросов без credentials)
            $allowedOrigin = '*';
            $allowCredentials = 'false';
            $originMatched = true;
        }
        
        // Устанавливаем заголовки CORS для ответа
        $headers = [
            // Устанавливаем origin из запроса или wildcard
            'Access-Control-Allow-Origin' => $allowedOrigin,
            // Разрешить включать учетные данные (только если не wildcard)
            'Access-Control-Allow-Credentials' => $allowCredentials,
            // Разрешить указанные методы
            'Access-Control-Allow-Methods' => implode(', ', $allowedMethods),
            // Разрешить указанные заголовки
            'Access-Control-Allow-Headers' => implode(', ', $allowedHeaders),
            // Срок действия preflight запроса
            'Access-Control-Max-Age' => (string) $maxAge,
        ];
        
        // Добавляем exposed headers, если они есть
        if (!empty($exposedHeaders)) {
            $headers['Access-Control-Expose-Headers'] = implode(', ', $exposedHeaders);
        }
        
        // Если это preflight OPTIONS-запрос
        if ($request->isMethod('OPTIONS')) {
            Log::info('CORS: Обработка preflight OPTIONS запроса', [
                'headers' => $headers,
                'origin' => $origin,
                'allowed_origin' => $allowedOrigin,
                'origin_matched' => $originMatched,
                'allowed_origins_config' => $allowedOrigins
            ]);
            // Возвращаем пустой ответ 200 с нужными CORS-заголовками
            return response('', 200, $headers);
        }
        
        try {
            // Для других запросов вызываем следующий middleware в цепочке
            $response = $next($request);
            
            // Добавляем заголовки CORS к ответу
            foreach ($headers as $key => $value) {
                $response->headers->set($key, $value);
            }
            
            Log::info('CORS: Заголовки успешно добавлены к ответу', [
                'has_allow_origin' => $response->headers->has('Access-Control-Allow-Origin'),
                'allow_origin' => $response->headers->get('Access-Control-Allow-Origin'),
                'status_code' => $response->getStatusCode()
            ]);
            
            return $response;
        } catch (\Throwable $e) {
            // --- НАЧАЛО ДИАГНОСТИЧЕСКИХ ЛОГОВ ---
            Log::info('CORS: Вход в блок catch Throwable', [
                'request_uri' => $request->getRequestUri(),
                'method' => $request->method()
            ]);
            Log::info('CORS: Исключение - Класс', ['class' => get_class($e)]);
            Log::info('CORS: Исключение - Сообщение', ['message' => $e->getMessage()]);
            Log::info('CORS: Исключение - Файл', ['file' => $e->getFile() . ':' . $e->getLine()]);
            // --- КОНЕЦ ДИАГНОСТИЧЕСКИХ ЛОГОВ ---

            // Специальная обработка для business logic исключений - пробрасываем дальше в Handler
            if ($e instanceof \App\Exceptions\Billing\InsufficientBalanceException ||
                $e instanceof \App\Exceptions\BusinessLogicException ||
                $e instanceof \Illuminate\Validation\ValidationException ||
                $e instanceof \Illuminate\Auth\AuthenticationException ||
                $e instanceof \Illuminate\Auth\Access\AuthorizationException) {
                
                // Сохраняем CORS заголовки в запросе для Handler
                $request->attributes->set('cors_headers', $headers);
                
                throw $e; // Пробрасываем в Handler
            }

            // Логируем ошибку для диагностики только для системных ошибок
            Log::error('CORS: Ошибка при обработке запроса (детальный лог ниже, если сработает)', [
                'error' => $e->getMessage(),
                'request_uri' => $request->getRequestUri()
            ]);
             
            if (method_exists($e, 'getTraceAsString')) {
                Log::debug('CORS: Stack trace (попытка записи через Log::debug)', ['trace' => $e->getTraceAsString()]);
            }
            
            // Возвращаем ответ об ошибке с заголовками CORS только для системных ошибок
            return response()->json([
                'error' => 'Ошибка на сервере',
                'message' => 'При обработке запроса произошла ошибка. Администратор уведомлен. [Diag: Catch Block Reached]'
            ], 500, $headers);
        }
    }
}