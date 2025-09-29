<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;
use App\Services\Logging\LoggingService;
use App\Services\Logging\Context\PerformanceContext;
use Throwable;

class RequestLoggingMiddleware
{
    protected LoggingService $loggingService;

    public function __construct(LoggingService $loggingService)
    {
        $this->loggingService = $loggingService;
    }

    /**
     * Обработать входящий запрос
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Начать отслеживание производительности
        if (App::bound(PerformanceContext::class)) {
            $performanceContext = App::make(PerformanceContext::class);
            $performanceContext->checkpoint('request_start');
        }

        // Логировать входящий запрос (только для API)
        if ($this->shouldLogRequest($request)) {
            $this->loggingService->access([
                'method' => $request->method(),
                'uri' => $request->getRequestUri(),
                'content_length' => strlen($request->getContent()),
                'has_files' => $request->hasFile('*'),
                'query_params_count' => count($request->query())
            ]);
        }

        $response = null;
        $exception = null;

        try {
            $response = $next($request);
            
            // Checkpoint после обработки запроса
            if (App::bound(PerformanceContext::class)) {
                $performanceContext = App::make(PerformanceContext::class);
                $performanceContext->checkpoint('request_processed');
            }
            
        } catch (Throwable $e) {
            $exception = $e;
            throw $e;
        } finally {
            // Логировать результат запроса
            if ($this->shouldLogRequest($request)) {
                $this->logRequestResult($request, $response, $exception);
            }
        }

        return $response;
    }

    /**
     * Определить, нужно ли логировать запрос
     */
    protected function shouldLogRequest(Request $request): bool
    {
        // Не логировать статические файлы
        if ($this->isStaticFile($request)) {
            return false;
        }

        // Не логировать health check запросы
        if ($this->isHealthCheck($request)) {
            return false;
        }

        // Логировать все API запросы
        if ($request->is('api/*')) {
            return true;
        }

        // Логировать важные web маршруты
        if ($this->isImportantWebRoute($request)) {
            return true;
        }

        return false;
    }

    /**
     * Логировать результат запроса
     */
    protected function logRequestResult(Request $request, ?Response $response, ?Throwable $exception): void
    {
        $responseData = [];
        
        if ($response) {
            $responseData = [
                'status_code' => $response->getStatusCode(),
                'content_length' => strlen($response->getContent()),
                'content_type' => $response->headers->get('Content-Type'),
                'has_errors' => $response->getStatusCode() >= 400
            ];
        }

        if ($exception) {
            $responseData = [
                'status_code' => ($exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) ? $exception->getStatusCode() : 500,
                'has_errors' => true,
                'exception_class' => get_class($exception),
                'exception_message' => $exception->getMessage()
            ];
        }

        // ACCESS: Логируем завершенный запрос с полными метриками
        $this->loggingService->access(array_merge([
            'event' => 'http.request.completed',
            'method' => $request->method(),
            'uri' => $request->getRequestUri(),
            'status_code' => $response?->getStatusCode(),
            'content_length' => strlen($request->getContent()),
            'query_params_count' => count($request->query()),
            'has_files' => $request->hasFile('*')
        ], $responseData ?? [], $performanceData ?? []));

        // TECHNICAL: Логируем производительность запросов для оптимизации
        if (App::bound(PerformanceContext::class)) {
            $performanceContext = App::make(PerformanceContext::class);
            $metrics = $performanceContext->getBasicMetrics();
            
            // Медленные запросы (>2 секунд)
            if ($performanceContext->isSlowRequest(2000)) {
                $this->loggingService->technical('performance.slow_request', [
                    'method' => $request->method(),
                    'uri' => $request->getRequestUri(),
                    'duration_ms' => $metrics['duration_ms'],
                    'memory_mb' => $metrics['memory_mb'],
                    'status_code' => $response?->getStatusCode(),
                    'user_id' => $request->user()?->id,
                    'organization_id' => $request->attributes->get('current_organization_id'),
                    'performance_summary' => $performanceContext->getPerformanceSummary()
                ], 'warning');
            }

            // Критически медленные запросы (>5 секунд)
            if ($performanceContext->isSlowRequest(5000)) {
                $this->loggingService->technical('performance.critical_slow_request', [
                    'method' => $request->method(),
                    'uri' => $request->getRequestUri(),
                    'duration_ms' => $metrics['duration_ms'],
                    'memory_mb' => $metrics['memory_mb'],
                    'status_code' => $response?->getStatusCode(),
                    'user_id' => $request->user()?->id,
                    'organization_id' => $request->attributes->get('current_organization_id'),
                    'query_params_count' => count($request->query()),
                    'has_files' => $request->hasFile('*'),
                    'content_length' => strlen($request->getContent()),
                    'full_performance' => $performanceContext->getDetailedMetrics()
                ], 'error');
            }

            // Высокое потребление памяти (>100MB)
            if ($performanceContext->isMemoryIntensive(100)) {
                $this->loggingService->technical('performance.high_memory_usage', [
                    'method' => $request->method(),
                    'uri' => $request->getRequestUri(),
                    'memory_mb' => $metrics['memory_mb'],
                    'duration_ms' => $metrics['duration_ms'],
                    'status_code' => $response?->getStatusCode(),
                    'user_id' => $request->user()?->id,
                    'organization_id' => $request->attributes->get('current_organization_id')
                ], 'warning');
            }

            // Критическое потребление памяти (>256MB)
            if ($performanceContext->isMemoryIntensive(256)) {
                $this->loggingService->technical('performance.critical_memory_usage', [
                    'method' => $request->method(),
                    'uri' => $request->getRequestUri(),
                    'memory_mb' => $metrics['memory_mb'],
                    'duration_ms' => $metrics['duration_ms'],
                    'status_code' => $response?->getStatusCode(),
                    'user_id' => $request->user()?->id,
                    'memory_limit' => ini_get('memory_limit')
                ], 'critical');
            }
        }

        // Логировать ошибки
        if ($exception) {
            $this->loggingService->technical('http.request_exception', [
                'method' => $request->method(),
                'uri' => $request->getRequestUri(),
                'exception_class' => get_class($exception),
                'exception_message' => $exception->getMessage(),
                'status_code' => ($exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) ? $exception->getStatusCode() : 500
            ], 'error');
        } elseif ($response && $response->getStatusCode() >= 500) {
            $this->loggingService->technical('http.server_error', [
                'method' => $request->method(),
                'uri' => $request->getRequestUri(),
                'status_code' => $response->getStatusCode()
            ], 'error');
        } elseif ($response && $response->getStatusCode() >= 400) {
            $this->loggingService->technical('http.client_error', [
                'method' => $request->method(),
                'uri' => $request->getRequestUri(),
                'status_code' => $response->getStatusCode()
            ], 'warning');
        }
    }

    /**
     * Проверить, является ли запрос статическим файлом
     */
    protected function isStaticFile(Request $request): bool
    {
        $staticExtensions = ['.css', '.js', '.jpg', '.jpeg', '.png', '.gif', '.svg', '.ico', '.woff', '.woff2', '.ttf'];
        $uri = $request->getRequestUri();
        
        foreach ($staticExtensions as $extension) {
            if (str_ends_with($uri, $extension)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Проверить, является ли запрос health check'ом
     */
    protected function isHealthCheck(Request $request): bool
    {
        $healthPaths = ['/health', '/ping', '/status', '/metrics'];
        $path = $request->path();
        
        foreach ($healthPaths as $healthPath) {
            if (str_contains($path, $healthPath)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Проверить, является ли маршрут важным для логирования
     */
    protected function isImportantWebRoute(Request $request): bool
    {
        $importantPaths = ['/login', '/logout', '/register', '/password'];
        $path = $request->path();
        
        foreach ($importantPaths as $importantPath) {
            if (str_contains($path, $importantPath)) {
                return true;
            }
        }
        
        return false;
    }
}
