<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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
        
        // Устанавливаем заголовки CORS для ответа
        $headers = [
            // Разрешить запросы только с конкретного домена фронтенда
            'Access-Control-Allow-Origin' => $origin ?: 'http://localhost:5173',
            // Разрешить включать учетные данные (куки, заголовки авторизации)
            'Access-Control-Allow-Credentials' => 'true',
            // Разрешить указанные методы
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
            // Разрешить указанные заголовки
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With, X-CSRF-TOKEN',
            // Срок действия preflight запроса
            'Access-Control-Max-Age' => '86400',
        ];
        
        // Если это preflight OPTIONS-запрос
        if ($request->isMethod('OPTIONS')) {
            // Возвращаем пустой ответ 200 с нужными CORS-заголовками
            return response('', 200, $headers);
        }
        
        // Для других запросов вызываем следующий middleware в цепочке
        $response = $next($request);
        
        // Добавляем заголовки CORS к ответу
        foreach ($headers as $key => $value) {
            $response->headers->set($key, $value);
        }
        
        return $response;
    }
}