<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use App\Http\Middleware\JwtMiddleware;
use App\Http\Middleware\SetOrganizationContext;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // ============================================================
        // РЕГИСТРАЦИЯ ПСЕВДОНИМОВ MIDDLEWARE
        // ============================================================
        $middleware->alias([
            // Новая система авторизации
            'authorize' => \App\Domain\Authorization\Http\Middleware\AuthorizeMiddleware::class,
            'role' => \App\Domain\Authorization\Http\Middleware\RoleMiddleware::class,
            'interface' => \App\Domain\Authorization\Http\Middleware\InterfaceMiddleware::class,
            
            // Система логирования и трекинга
            'correlation.id' => \App\Http\Middleware\CorrelationIdMiddleware::class,
            'request.logging' => \App\Http\Middleware\RequestLoggingMiddleware::class,
            
            // === ОСТАЛЬНЫЕ MIDDLEWARE ===
            'auth.jwt' => JwtMiddleware::class,
            'jwt.auth' => JwtMiddleware::class,
            'organization.context' => SetOrganizationContext::class,
            'organization_context' => SetOrganizationContext::class,
            'project.context' => \App\Http\Middleware\ProjectContextMiddleware::class,
            
            // Дополнительные middleware
            'request.dedup' => \App\Http\Middleware\RequestDedupMiddleware::class,
            'subscription.limit' => \App\Http\Middleware\CheckSubscriptionLimitsMiddleware::class,
            'module.access' => \App\Modules\Middleware\ModuleAccessMiddleware::class,
            'module.permission' => \App\Modules\Middleware\ModulePermissionMiddleware::class,
            'holding.subdomain' => \App\Http\Middleware\DetectHoldingSubdomain::class,
            'verified' => \App\Http\Middleware\EnsureEmailIsVerified::class,
        ]);

        // ============================================================
        // ГЛОБАЛЬНЫЕ MIDDLEWARE
        // Порядок важен: сначала CORS, затем Correlation ID, в конце Prometheus
        // ============================================================
        
        // 1. CORS - должен быть первым для обработки preflight запросов
        $middleware->prepend(\App\Http\Middleware\CorsMiddleware::class);
        
        // 2. Correlation ID - генерируем уникальный ID для трекинга запроса
        $middleware->prepend(\App\Http\Middleware\CorrelationIdMiddleware::class);
        
        // 3. Prometheus - метрики в конце цепочки для корректного измерения времени
        $middleware->append(\App\Http\Middleware\PrometheusMiddleware::class);

        // ============================================================
        // ГРУППА MIDDLEWARE ДЛЯ API
        // ============================================================
        $middleware->api([
            'throttle:api', // Rate limiting
            \Illuminate\Routing\Middleware\SubstituteBindings::class, // Route model binding
            \App\Http\Middleware\RequestLoggingMiddleware::class, // Структурированное логирование
            \App\Http\Middleware\SetOrganizationContext::class, // Контекст организации
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // ============================================================
        // СТРУКТУРИРОВАННОЕ ЛОГИРОВАНИЕ ИСКЛЮЧЕНИЙ
        // Логируем разные типы ошибок в отдельные каналы для удобства анализа
        // ============================================================
        
        // Redis ошибки -> logs/redis/redis.log
        $exceptions->report(function (\Predis\Connection\ConnectionException $e): void {
            Log::channel('redis')->error('Redis connection error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        });

        // Ошибки базы данных -> logs/database/database.log
        $exceptions->report(function (\Illuminate\Database\QueryException $e): void {
            Log::channel('database')->error('Database query error', [
                'message' => $e->getMessage(),
                'sql' => $e->getSql(),
                'bindings' => $e->getBindings(),
            ]);
        });

        $exceptions->report(function (\PDOException $e): void {
            Log::channel('database')->error('PDO error', [
                'message' => $e->getMessage(),
            ]);
        });

        // Ошибки аутентификации -> logs/auth/auth.log
        $exceptions->report(function (\Illuminate\Auth\AuthenticationException $e): void {
            Log::channel('auth')->info('Authentication failed', [
                'message' => $e->getMessage(),
            ]);
        });

        // Ошибки авторизации -> logs/auth/auth.log
        $exceptions->report(function (\Illuminate\Auth\Access\AuthorizationException $e): void {
            Log::channel('auth')->warning('Authorization failed', [
                'message' => $e->getMessage(),
            ]);
        });

        // ============================================================
        // ИСКЛЮЧЕНИЯ ИЗ ЛОГИРОВАНИЯ
        // Не логируем как критические ошибки
        // ============================================================
        
        $exceptions->dontReport([
            \App\Exceptions\Billing\InsufficientBalanceException::class,
        ]);

        // ============================================================
        // ИНТЕГРАЦИЯ С PROMETHEUS
        // Трекинг исключений для мониторинга
        // ============================================================
        
        $exceptions->reportable(function (Throwable $e): void {
            // Исключаем business logic исключения из мониторинга
            if ($e instanceof \App\Exceptions\Billing\InsufficientBalanceException) {
                return;
            }
            
            try {
                $prometheus = app(\App\Services\Monitoring\PrometheusService::class);
                $prometheus->incrementExceptions(get_class($e));
            } catch (\Throwable $ignored) {
                // Игнорируем ошибки мониторинга, чтобы не сломать основное приложение
            }
        });

        // ============================================================
        // ПРИМЕЧАНИЕ:
        // Вся логика рендеринга исключений (renderable) находится в
        // app/Exceptions/Handler.php для централизованного управления
        // ============================================================
    })
    ->create();
