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
            'uri' => $request->getRequestUri()
        ]);
        
        // Получаем конфигурацию CORS
        $allowedOrigins = Config::get('cors.allowed_origins', ['http://localhost:5173']);
        $allowedMethods = Config::get('cors.allowed_methods', ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']);
        $allowedHeaders = Config::get('cors.allowed_headers', ['Content-Type', 'X-Auth-Token', 'Origin', 'Authorization', 'X-Requested-With']);
        $exposedHeaders = Config::get('cors.exposed_headers', []);
        $maxAge = Config::get('cors.max_age', 86400);
        $supportsCredentials = Config::get('cors.supports_credentials', true);
        
        // Проверяем origin
        $allowOrigin = '';
        
        // В режиме разработки можно разрешить все origins
        $isDevMode = Config::get('app.env') === 'local';
        
        if ($isDevMode && Config::get('cors.allow_any_origin_in_dev', false)) {
            $allowOrigin = $origin ?: '*';
            Log::info('CORS: Разрешен любой origin в режиме разработки', ['origin' => $origin]);
        } else {
            // Проверяем обычным способом
            if (in_array($origin, $allowedOrigins)) {
                $allowOrigin = $origin;
            } else {
                // Пытаемся извлечь хост из origin
                $originHost = parse_url($origin, PHP_URL_HOST);
                
                // Проверяем если origin это IP-адрес из списка разрешенных
                foreach ($allowedOrigins as $allowed) {
                    $allowedHost = parse_url($allowed, PHP_URL_HOST);
                    
                    if ($originHost === $allowedHost) {
                        $allowOrigin = $origin;
                        break;
                    }
                }
                
                // Дополнительная проверка для IP 89.111.152.112
                if (empty($allowOrigin) && $originHost && 
                    (strpos($originHost, '89.111.152.112') === 0 || $originHost === '89.111.152.112')) {
                    $allowOrigin = $origin;
                    Log::info('CORS: Разрешен специальный IP', ['origin' => $origin, 'host' => $originHost]);
                }
            }
        }
        
        // Устанавливаем заголовки CORS для ответа
        $headers = [
            // Устанавливаем конкретный origin вместо wildcard '*'
            'Access-Control-Allow-Origin' => $allowOrigin,
            // Разрешить включать учетные данные (куки, заголовки авторизации)
            // Но если allowOrigin равен '*', то нельзя указывать true для credentials
            'Access-Control-Allow-Credentials' => ($allowOrigin === '*') ? 'false' : 'true',
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
        
        // Если Origin не в списке разрешенных, добавляем предупреждение в лог
        if (empty($allowOrigin) && $origin) {
            Log::warning('CORS: Origin не в списке разрешенных', [
                'origin' => $origin, 
                'allowed_origins' => $allowedOrigins
            ]);
        }
        
        // Если это preflight OPTIONS-запрос
        if ($request->isMethod('OPTIONS')) {
            Log::info('CORS: Обработка preflight OPTIONS запроса', ['headers' => $headers]);
            // Возвращаем пустой ответ 200 с нужными CORS-заголовками
            return response('', 200, $headers);
        }
        
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
    }
}