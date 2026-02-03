<?php

namespace App\Services\Logging;

use App\Services\Logging\Context\RequestContext;
use App\Services\Logging\Context\UserContext;
use App\Services\Logging\Context\PerformanceContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AccessLogger
{
    protected RequestContext $requestContext;
    protected UserContext $userContext;
    protected PerformanceContext $performanceContext;

    public function __construct(
        RequestContext $requestContext,
        UserContext $userContext,
        PerformanceContext $performanceContext
    ) {
        $this->requestContext = $requestContext;
        $this->userContext = $userContext;
        $this->performanceContext = $performanceContext;
    }

    /**
     * Логировать HTTP запрос
     */
    public function logRequest(array $requestData = [], array $responseData = []): void
    {
        // Определить уровень логирования на основе статуса ответа
        $level = $this->determineLogLevel($responseData['status_code'] ?? 200);
        
        $accessEntry = $this->createAccessEntry($requestData, $responseData, $level);
        
        match($level) {
            'error' => Log::error("[ACCESS]", $accessEntry),
            'warning' => Log::warning("[ACCESS]", $accessEntry),
            // default => Log::info("[ACCESS]", $accessEntry)
            default => null // Disable info level access logs to reduce noise
        };

        // Отправить метрики в мониторинг
        $this->sendAccessMetrics($accessEntry);
    }

    /**
     * Создать запись доступа
     */
    protected function createAccessEntry(array $requestData, array $responseData, string $level = 'info'): array
    {
        $metadata = $this->requestContext->getMetadata();
        $performance = $this->performanceContext->getMetrics();
        
        return [
            'timestamp' => now()->toISOString(),
            'level' => strtoupper($level),
            'category' => 'ACCESS',
            'event' => 'http.request',
            'correlation_id' => $this->requestContext->getCorrelationId(),
            'user_id' => $this->userContext->getUserId(),
            'organization_id' => $this->userContext->getOrganizationId(),
            'request' => [
                'method' => $requestData['method'] ?? $metadata['method'] ?? 'UNKNOWN',
                'uri' => $requestData['uri'] ?? $metadata['uri'] ?? '',
                'api_version' => $metadata['api_version'],
                'interface' => $this->requestContext->getClientInterface(),
                'content_type' => $metadata['content_type'],
                'content_length' => $requestData['content_length'] ?? $this->requestContext->getRequestSize(),
                'is_ajax' => $metadata['is_ajax'] ?? false,
                'is_json' => $metadata['is_json'] ?? false,
                'has_files' => $requestData['has_files'] ?? false,
                'query_params_count' => $requestData['query_params_count'] ?? 0
            ],
            'response' => [
                'status_code' => $responseData['status_code'] ?? null,
                'content_length' => $responseData['content_length'] ?? null,
                'content_type' => $responseData['content_type'] ?? null,
                'has_errors' => $responseData['has_errors'] ?? false,
                'cache_status' => $responseData['cache_status'] ?? null
            ],
            'client' => [
                'ip' => $metadata['ip'],
                'user_agent' => $metadata['user_agent'],
                'referer' => $metadata['referer'],
                'accept_language' => $metadata['accept_language'],
                'is_mobile' => $this->isMobileUserAgent($metadata['user_agent'] ?? ''),
                'is_bot' => $this->isBotUserAgent($metadata['user_agent'] ?? '')
            ],
            'authentication' => [
                'is_authenticated' => $this->userContext->isAuthenticated(),
                'user_type' => $this->userContext->getUserType(),
                'roles' => $this->userContext->getRolesSafe(),
                'has_organization_context' => !empty($this->userContext->getOrganizationId())
            ],
            'performance' => $performance,
            'route' => $this->requestContext->getRouteInfo(),
            'environment' => config('app.env'),
            'application' => 'prohelper'
        ];
    }

    /**
     * Определить уровень логирования на основе статуса ответа
     */
    protected function determineLogLevel(int $statusCode): string
    {
        if ($statusCode >= 500) {
            return 'error';
        } elseif ($statusCode >= 400) {
            return 'warning';
        }
        
        return 'info';
    }

    /**
     * Отправить метрики доступа в мониторинг
     */
    protected function sendAccessMetrics(array $accessEntry): void
    {
        try {
            if (app()->bound(\App\Services\Monitoring\PrometheusService::class)) {
                $prometheus = app(\App\Services\Monitoring\PrometheusService::class);
                
                // Счетчик запросов - используем существующий метод
                $prometheus->incrementHttpRequests(
                    $accessEntry['request']['method'],
                    $accessEntry['request']['interface'] ?? 'unknown',
                    $accessEntry['response']['status_code'] ?? 200
                );
                
                // Метрика времени ответа - используем существующий метод
                if (isset($accessEntry['performance']['duration_ms'])) {
                    $prometheus->observeRequestDuration(
                        $accessEntry['request']['method'],
                        $accessEntry['request']['interface'] ?? 'unknown',
                        $accessEntry['performance']['duration_ms'] / 1000 // конвертируем в секунды
                    );
                }
                
                // Метрика использования памяти - используем существующий метод
                $prometheus->setMemoryUsage();
            }
        } catch (\Exception $e) {
            // Не прерываем выполнение
        }
    }

    /**
     * Логировать из Request и Response объектов Laravel
     */
    public function logHttpRequest(Request $request, ?Response $response = null): void
    {
        $requestData = [
            'method' => $request->method(),
            'uri' => $request->getRequestUri(),
            'content_length' => strlen($request->getContent()),
            'has_files' => $request->hasFile('*'),
            'query_params_count' => count($request->query())
        ];

        $responseData = [];
        if ($response) {
            $responseData = [
                'status_code' => $response->getStatusCode(),
                'content_length' => strlen($response->getContent()),
                'content_type' => $response->headers->get('Content-Type'),
                'has_errors' => $response->getStatusCode() >= 400,
                'cache_status' => $response->headers->get('Cache-Control')
            ];
        }

        $this->logRequest($requestData, $responseData);
    }

    /**
     * Логировать API запрос с дополнительной информацией
     */
    public function logApiRequest(Request $request, ?Response $response = null, ?array $additionalContext = null): void
    {
        // Базовое логирование
        $this->logHttpRequest($request, $response);

        // Дополнительная информация для API
        if ($additionalContext) {
            $apiEntry = [
                'timestamp' => now()->toISOString(),
                'level' => 'INFO',
                'category' => 'ACCESS',
                'event' => 'api.request.details',
                'correlation_id' => $this->requestContext->getCorrelationId(),
                'context' => [
                    'endpoint' => $request->route()?->getName(),
                    'controller_action' => $request->route()?->getActionName(),
                    'middleware' => $request->route()?->middleware(),
                    'parameters' => $request->route()?->parameters(),
                    'validation_errors' => $additionalContext['validation_errors'] ?? null,
                    'business_context' => $additionalContext['business_context'] ?? null
                ]
            ];

            Log::info("[API_DETAILS]", $apiEntry);
        }
    }

    /**
     * Логировать медленный запрос
     */
    public function logSlowRequest(Request $request, Response $response, float $durationMs): void
    {
        $slowRequestEntry = [
            'timestamp' => now()->toISOString(),
            'level' => 'WARNING',
            'category' => 'ACCESS',
            'event' => 'http.slow_request',
            'correlation_id' => $this->requestContext->getCorrelationId(),
            'context' => [
                'method' => $request->method(),
                'uri' => $request->getRequestUri(),
                'status_code' => $response->getStatusCode(),
                'duration_ms' => $durationMs,
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
                'interface' => $this->requestContext->getClientInterface(),
                'user_id' => $this->userContext->getUserId(),
                'organization_id' => $this->userContext->getOrganizationId()
            ]
        ];

        Log::warning("[SLOW_REQUEST]", $slowRequestEntry);
    }

    /**
     * Логировать подозрительную активность
     */
    public function logSuspiciousRequest(Request $request, array $suspiciousIndicators = []): void
    {
        $suspiciousEntry = [
            'timestamp' => now()->toISOString(),
            'level' => 'WARNING',
            'category' => 'ACCESS',
            'event' => 'http.suspicious_request',
            'correlation_id' => $this->requestContext->getCorrelationId(),
            'context' => [
                'method' => $request->method(),
                'uri' => $request->getRequestUri(),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'indicators' => $suspiciousIndicators,
                'interface' => $this->requestContext->getClientInterface()
            ]
        ];

        Log::warning("[SUSPICIOUS_REQUEST]", $suspiciousEntry);
    }

    /**
     * Вспомогательные методы
     */

    protected function isMobileUserAgent(string $userAgent): bool
    {
        $mobileKeywords = ['Mobile', 'Android', 'iPhone', 'iPad', 'Windows Phone'];
        
        foreach ($mobileKeywords as $keyword) {
            if (stripos($userAgent, $keyword) !== false) {
                return true;
            }
        }
        
        return false;
    }

    protected function isBotUserAgent(string $userAgent): bool
    {
        $botKeywords = [
            'bot', 'crawler', 'spider', 'scraper', 'curl', 'wget',
            'Googlebot', 'Bingbot', 'Slurp', 'facebookexternalhit'
        ];
        
        foreach ($botKeywords as $keyword) {
            if (stripos($userAgent, $keyword) !== false) {
                return true;
            }
        }
        
        return false;
    }
}
