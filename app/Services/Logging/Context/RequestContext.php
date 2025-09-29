<?php

namespace App\Services\Logging\Context;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class RequestContext
{
    protected ?string $correlationId = null;
    protected array $metadata = [];
    protected ?Request $request = null;

    public function __construct()
    {
        $this->generateCorrelationId();
        $this->collectMetadata();
    }

    /**
     * Генерировать уникальный ID для трекинга запроса
     */
    public function generateCorrelationId(): string
    {
        if (!$this->correlationId) {
            $this->correlationId = 'req_' . Str::random(12) . '_' . time();
        }

        return $this->correlationId;
    }

    /**
     * Получить correlation ID
     */
    public function getCorrelationId(): string
    {
        return $this->correlationId ?: $this->generateCorrelationId();
    }

    /**
     * Установить correlation ID извне (из заголовка запроса)
     */
    public function setCorrelationId(string $correlationId): void
    {
        $this->correlationId = $correlationId;
    }

    /**
     * Установить объект запроса
     */
    public function setRequest(Request $request): void
    {
        $this->request = $request;
        $this->collectMetadata();
    }

    /**
     * Собрать метаданные запроса
     */
    protected function collectMetadata(): void
    {
        $request = $this->request ?: request();
        
        if ($request) {
            $this->metadata = [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'method' => $request->method(),
                'uri' => $request->getRequestUri(),
                'api_version' => $this->extractApiVersion($request),
                'referer' => $request->header('Referer'),
                'accept_language' => $request->header('Accept-Language'),
                'content_type' => $request->header('Content-Type'),
                'is_ajax' => $request->ajax(),
                'is_json' => $request->expectsJson(),
            ];

            // Добавить специфичные заголовки ProHelper
            if ($request->hasHeader('X-Organization-Id')) {
                $this->metadata['organization_header'] = $request->header('X-Organization-Id');
            }

            if ($request->hasHeader('X-Client-Version')) {
                $this->metadata['client_version'] = $request->header('X-Client-Version');
            }
        }
    }

    /**
     * Извлечь версию API из URL
     */
    protected function extractApiVersion(Request $request): ?string
    {
        $path = $request->path();
        
        // Паттерн: api/v1/... или api/v2/...
        if (preg_match('/api\/v(\d+)/', $path, $matches)) {
            return 'v' . $matches[1];
        }

        return null;
    }

    /**
     * Получить все метаданные
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Добавить дополнительные метаданные
     */
    public function addMetadata(string $key, $value): void
    {
        $this->metadata[$key] = $value;
    }

    /**
     * Получить информацию о маршруте
     */
    public function getRouteInfo(): array
    {
        $request = $this->request ?: request();
        
        if ($request && $request->route()) {
            return [
                'name' => $request->route()->getName(),
                'action' => $request->route()->getActionName(),
                'parameters' => $request->route()->parameters(),
                'middleware' => $request->route()->middleware()
            ];
        }

        return [];
    }

    /**
     * Проверить, является ли запрос API запросом
     */
    public function isApiRequest(): bool
    {
        $request = $this->request ?: request();
        
        return $request && (
            $request->is('api/*') || 
            $request->expectsJson() ||
            $request->header('Accept') === 'application/json'
        );
    }

    /**
     * Получить интерфейс клиента (admin, mobile, lk)
     */
    public function getClientInterface(): ?string
    {
        $request = $this->request ?: request();
        
        if (!$request) {
            return null;
        }

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

    /**
     * Безопасно получить размер запроса
     */
    public function getRequestSize(): int
    {
        $request = $this->request ?: request();
        
        if ($request) {
            $content = $request->getContent();
            return strlen($content);
        }

        return 0;
    }
}
