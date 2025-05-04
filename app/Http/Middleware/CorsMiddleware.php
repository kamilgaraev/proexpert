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
        $allowedMethods = Config::get('cors.allowed_methods', ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']);
        $allowedHeaders = Config::get('cors.allowed_headers', ['Content-Type', 'X-Auth-Token', 'Origin', 'Authorization', 'X-Requested-With']);
        $exposedHeaders = Config::get('cors.exposed_headers', []);
        $maxAge = Config::get('cors.max_age', 86400);
        
        // УНИВЕРСАЛЬНОЕ РЕШЕНИЕ: разрешаем запросы с любого Origin
        $allowOrigin = $origin ?: '*';
        $allowCredentials = ($allowOrigin === '*') ? 'false' : 'true';
        
        // Устанавливаем заголовки CORS для ответа
        $headers = [
            // Устанавливаем origin из запроса или wildcard
            'Access-Control-Allow-Origin' => $allowOrigin,
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