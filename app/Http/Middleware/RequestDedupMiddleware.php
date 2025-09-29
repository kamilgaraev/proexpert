<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class RequestDedupMiddleware
{
    private const DEDUP_TTL = 5; // 5 секунд

    public function handle(Request $request, Closure $next): Response
    {
        // Применяем дедупликацию только для определенных маршрутов
        $shouldDeduplicate = $this->shouldDeduplicate($request);
        
        if (!$shouldDeduplicate) {
            return $next($request);
        }

        $requestKey = $this->generateRequestKey($request);
        $lockKey = "req_lock_{$requestKey}";
        $cacheKey = "req_cache_{$requestKey}";

        // Проверяем, есть ли кэшированный результат
        $cachedResponse = Cache::get($cacheKey);
        if ($cachedResponse !== null) {
            return response()->json($cachedResponse['data'], $cachedResponse['status'])
                ->withHeaders($cachedResponse['headers']);
        }

        // Блокируем повторные запросы
        if (Cache::has($lockKey)) {
            return response()->json(['message' => 'Duplicate request ignored'], 429);
        }

        // Устанавливаем блокировку
        Cache::put($lockKey, true, self::DEDUP_TTL);

        try {
            $response = $next($request);
            
            // Кэшируем успешный ответ
            if ($response->getStatusCode() < 400) {
                $responseData = [
                    'data' => json_decode($response->getContent(), true),
                    'status' => $response->getStatusCode(),
                    'headers' => $response->headers->all()
                ];
                Cache::put($cacheKey, $responseData, self::DEDUP_TTL);
            }

            return $response;
        } finally {
            // Убираем блокировку
            Cache::forget($lockKey);
        }
    }

    private function shouldDeduplicate(Request $request): bool
    {
        // Дедуплицируем только GET запросы для определенных эндпоинтов
        if (!$request->isMethod('GET')) {
            return false;
        }

        $dedupRoutes = [
            'api/v1/landing/auth/me',
            'api/v1/landing/dashboard',
            'api/v1/landing/modules/active',
            'api/v1/landing/modules/expiring',
            'api/v1/landing/billing/balance',
            'api/lk/v1/permissions'
        ];

        foreach ($dedupRoutes as $route) {
            if (str_contains($request->getPathInfo(), $route)) {
                return true;
            }
        }

        return false;
    }

    private function generateRequestKey(Request $request): string
    {
        $key = $request->getPathInfo() . '_' . $request->getQueryString();
        
        // Добавляем ID пользователя если аутентифицирован
        if ($user = $request->user()) {
            $key .= '_user_' . $user->id;
        }

        // Добавляем organization_id из заголовков или атрибутов
        $orgId = $request->attributes->get('current_organization_id') ?? 
                 $request->header('X-Organization-Id');
        
        if ($orgId) {
            $key .= '_org_' . $orgId;
        }

        return hash('sha256', $key);
    }
}
