<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use App\Services\Logging\Context\RequestContext;

class CorrelationIdMiddleware
{
    /**
     * Обработать входящий запрос
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Получить или сгенерировать correlation ID
        $correlationId = $this->getOrGenerateCorrelationId($request);
        
        // Установить correlation ID в контексте логирования
        if (App::bound(RequestContext::class)) {
            $requestContext = App::make(RequestContext::class);
            $requestContext->setCorrelationId($correlationId);
            $requestContext->setRequest($request);
        }

        // Добавить correlation ID в заголовки ответа
        $response = $next($request);
        
        if ($response instanceof Response) {
            $response->headers->set('X-Correlation-ID', $correlationId);
        }

        return $response;
    }

    /**
     * Получить correlation ID из заголовков или сгенерировать новый
     */
    protected function getOrGenerateCorrelationId(Request $request): string
    {
        // Проверить заголовки запроса
        $correlationId = $request->header('X-Correlation-ID') 
            ?? $request->header('X-Request-ID')
            ?? $request->header('X-Trace-ID');

        // Если не найден, сгенерировать новый
        if (!$correlationId || !$this->isValidCorrelationId($correlationId)) {
            $correlationId = $this->generateCorrelationId($request);
        }

        return $correlationId;
    }

    /**
     * Проверить валидность correlation ID
     */
    protected function isValidCorrelationId(string $correlationId): bool
    {
        // Базовые проверки
        if (strlen($correlationId) < 8 || strlen($correlationId) > 64) {
            return false;
        }

        // Только допустимые символы
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $correlationId)) {
            return false;
        }

        return true;
    }

    /**
     * Сгенерировать новый correlation ID
     */
    protected function generateCorrelationId(Request $request): string
    {
        $interface = $this->detectInterface($request);
        $timestamp = now()->format('Ymd-His');
        $random = Str::random(8);
        
        return "req_{$interface}_{$timestamp}_{$random}";
    }

    /**
     * Определить интерфейс из URL
     */
    protected function detectInterface(Request $request): string
    {
        $path = $request->path();
        
        if (str_contains($path, 'api/v1/admin')) {
            return 'admin';
        } elseif (str_contains($path, 'api/mobile') || str_contains($path, 'api/v1/mobile')) {
            return 'mobile';
        } elseif (str_contains($path, 'api/v1/landing') || str_contains($path, 'api/lk')) {
            return 'lk';
        } elseif (str_contains($path, 'api/superadmin')) {
            return 'superadmin';
        } elseif (str_contains($path, 'api/holding-api')) {
            return 'holding';
        }

        return 'web';
    }
}
