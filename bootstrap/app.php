<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Log;
use App\Http\Middleware\JwtMiddleware;
use App\Http\Middleware\SetOrganizationContext;

/**
 * Конвертирует строку размера (например, "64M", "2G") в байты
 */
if (!function_exists('convertIniSizeToBytes')) {
    function convertIniSizeToBytes(string $size): int
    {
        $size = trim($size);
        $last = strtolower($size[strlen($size) - 1]);
        $value = (int)$size;
        
        switch ($last) {
            case 'g':
                $value *= 1024;
                // no break
            case 'm':
                $value *= 1024;
                // no break
            case 'k':
                $value *= 1024;
        }
        
        return $value;
    }
}

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Регистрация псевдонимов
        $middleware->alias([
            // === НОВАЯ СИСТЕМА АВТОРИЗАЦИИ ===
            'authorize' => \App\Domain\Authorization\Http\Middleware\AuthorizeMiddleware::class,
            'role' => \App\Domain\Authorization\Http\Middleware\RoleMiddleware::class,
            'interface' => \App\Domain\Authorization\Http\Middleware\InterfaceMiddleware::class,
            
            // === СИСТЕМА ЛОГИРОВАНИЯ PHASE 2 ===
            'correlation.id' => \App\Http\Middleware\CorrelationIdMiddleware::class,
            'request.logging' => \App\Http\Middleware\RequestLoggingMiddleware::class,
            
            // === ОСТАЛЬНЫЕ MIDDLEWARE ===
            'auth.jwt' => JwtMiddleware::class,
            'jwt.auth' => JwtMiddleware::class,
            'organization.context' => SetOrganizationContext::class,
            'organization_context' => SetOrganizationContext::class,
            'project.context' => \App\Http\Middleware\ProjectContextMiddleware::class,
            'request.dedup' => \App\Http\Middleware\RequestDedupMiddleware::class,
            'subscription.limit' => \App\Http\Middleware\CheckSubscriptionLimitsMiddleware::class,
            'module.access' => \App\Modules\Middleware\ModuleAccessMiddleware::class,
            'module.permission' => \App\Modules\Middleware\ModulePermissionMiddleware::class,
            'holding.subdomain' => \App\Http\Middleware\DetectHoldingSubdomain::class,
            'verified' => \App\Http\Middleware\EnsureEmailIsVerified::class,
        ]);

        // Глобальные middleware
        $middleware->prepend(\App\Http\Middleware\CorsMiddleware::class);
        // PHASE 2: Correlation ID для всех запросов - в самом начале цепочки
        $middleware->prepend(\App\Http\Middleware\CorrelationIdMiddleware::class);
        $middleware->append(\App\Http\Middleware\PrometheusMiddleware::class);

        // Группы middleware (например, для API)
        $middleware->api([
             'throttle:api',
             \Illuminate\Routing\Middleware\SubstituteBindings::class,
             // PHASE 2: Новое структурированное логирование для всех API запросов
             \App\Http\Middleware\RequestLoggingMiddleware::class,
             \App\Http\Middleware\SetOrganizationContext::class,
        ]);

        // Middleware для веб-группы (если нужно)
        // $middleware->web([...]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // === ЛОГИРОВАНИЕ В ОТДЕЛЬНЫЕ ФАЙЛЫ ===
        
        // 1. Ошибки Redis -> logs/redis/redis.log
        // (RedisException для phpredis опущен из-за отсутствия расширения в среде разработки)
        $exceptions->report(function (\Predis\Connection\ConnectionException $e) {
            Log::channel('redis')->error($e->getMessage(), ['trace' => $e->getTraceAsString()]);
        });

        // 2. Ошибки БД -> logs/database/database.log
        $exceptions->report(function (\Illuminate\Database\QueryException $e) {
            Log::channel('database')->error($e->getMessage(), [
                'sql' => $e->getSql(),
                'bindings' => $e->getBindings(),
            ]);
        });
        $exceptions->report(function (\PDOException $e) {
            Log::channel('database')->error($e->getMessage());
        });

        // 3. Ошибки Авторизации -> logs/auth/auth.log
        $exceptions->report(function (\Illuminate\Auth\AuthenticationException $e) {
            Log::channel('auth')->info('Authentication failed', ['error' => $e->getMessage()]);
        });
        $exceptions->report(function (\Illuminate\Auth\Access\AuthorizationException $e) {
            Log::channel('auth')->warning('Authorization failed', ['error' => $e->getMessage()]);
        });

        // Кастомизация обработки исключений
        $exceptions->renderable(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            if ($request->expectsJson()) {
                 return new \App\Http\Responses\Api\V1\ErrorResponse('Unauthenticated.', \Symfony\Component\HttpFoundation\Response::HTTP_UNAUTHORIZED);
            }
        });

        $exceptions->renderable(function (\Illuminate\Auth\Access\AuthorizationException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                 $message = $e->getMessage();
                 
                 // Делаем сообщение более понятным
                 if (empty($message) || $message === 'This action is unauthorized.') {
                     $message = 'У вас недостаточно прав для выполнения этого действия. Обратитесь к администратору.';
                 }
                 
                 Log::info('[bootstrap/app.php] AuthorizationException caught', [
                     'message' => $message,
                     'uri' => $request->getRequestUri(),
                 ]);
                 
                 return response()->json([
                     'success' => false,
                     'message' => $message
                 ], \Symfony\Component\HttpFoundation\Response::HTTP_FORBIDDEN);
            }
        });

        $exceptions->renderable(function (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                 $message = $e->getMessage();
                 
                 // Делаем сообщение более понятным
                 if (empty($message) || $message === 'This action is unauthorized.') {
                     $message = 'У вас недостаточно прав для выполнения этого действия. Обратитесь к администратору.';
                 }
                 
                 Log::info('[bootstrap/app.php] AccessDeniedHttpException caught', [
                     'message' => $message,
                     'uri' => $request->getRequestUri(),
                 ]);
                 
                 return response()->json([
                     'success' => false,
                     'message' => $message
                 ], \Symfony\Component\HttpFoundation\Response::HTTP_FORBIDDEN);
            }
        });

        $exceptions->renderable(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, $request) {
            if ($request->expectsJson()) {
                return new \App\Http\Responses\Api\V1\NotFoundResponse('Resource not found.');
            }
        });

        $exceptions->renderable(function (\Illuminate\Validation\ValidationException $e, $request) {
            if ($request->expectsJson()) {
                return new \App\Http\Responses\Api\V1\ErrorResponse(
                    message: $e->getMessage() ?: 'Validation Failed',
                    statusCode: \Symfony\Component\HttpFoundation\Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }
        });

        $exceptions->renderable(function (\App\Exceptions\Billing\InsufficientBalanceException $e, $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage() ?: 'Недостаточно средств на балансе для выполнения операции.'
                ], 402); // 402 Payment Required
            }
        });

        // Обработка PostTooLargeException с детальным логированием
        $exceptions->renderable(function (\Illuminate\Http\Exceptions\PostTooLargeException $e, $request) {
            // Получаем размер запроса из заголовков
            $contentLength = $request->header('Content-Length');
            $contentLengthBytes = $contentLength ? (int)$contentLength : null;
            $contentLengthMB = $contentLengthBytes ? round($contentLengthBytes / 1024 / 1024, 2) : null;

            // Получаем текущие лимиты PHP
            $postMaxSize = ini_get('post_max_size');
            $uploadMaxFilesize = ini_get('upload_max_filesize');
            $maxFileUploads = ini_get('max_file_uploads');

            // Конвертируем лимиты в байты для сравнения
            $postMaxSizeBytes = convertIniSizeToBytes($postMaxSize);
            $uploadMaxFilesizeBytes = convertIniSizeToBytes($uploadMaxFilesize);

            Log::error('[bootstrap/app.php] PostTooLargeException - Детальная диагностика', [
                'uri' => $request->getRequestUri(),
                'method' => $request->method(),
                'content_length_header' => $contentLength,
                'content_length_bytes' => $contentLengthBytes,
                'content_length_mb' => $contentLengthMB,
                'php_post_max_size' => $postMaxSize,
                'php_post_max_size_bytes' => $postMaxSizeBytes,
                'php_upload_max_filesize' => $uploadMaxFilesize,
                'php_upload_max_filesize_bytes' => $uploadMaxFilesizeBytes,
                'php_max_file_uploads' => $maxFileUploads,
                'content_type' => $request->header('Content-Type'),
                'has_files' => $request->hasFile('*'),
                'files_count' => count($request->allFiles()),
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
            ]);

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Размер отправляемых данных превышает допустимый лимит.',
                    'error' => 'POST data is too large',
                    'details' => [
                        'request_size_mb' => $contentLengthMB,
                        'limit_post_max_size' => $postMaxSize,
                        'limit_upload_max_filesize' => $uploadMaxFilesize,
                    ]
                ], 413); // 413 Payload Too Large
            }
        });
        
        // Обработка всех остальных ошибок для API, когда НЕ в режиме отладки
        $exceptions->renderable(function (\Throwable $e, $request) {
             if (($request->expectsJson() || $request->is('api/*')) && !app()->hasDebugModeEnabled()) {
                Log::error('[bootstrap/app.php] Throwable caught in general handler', [
                    'exception_class' => get_class($e),
                    'exception_message' => $e->getMessage(),
                    'exception_code' => $e->getCode(),
                    'uri' => $request->getRequestUri(),
                    'is_authorization' => $e instanceof \Illuminate\Auth\Access\AuthorizationException,
                ]);
                
                error_log('[bootstrap/app.php withExceptions] Caught Throwable for non-debug API: ' . $e->getMessage() . "\nStack Trace:\n" . $e->getTraceAsString());
                report($e); // Логируем ошибку стандартным механизмом Laravel
                
                // ВРЕМЕННАЯ ЗАМЕНА для диагностики ErrorResponse::send()
                return response()->json([
                    'success' => false,
                    'message' => 'Internal Server Error (diag via direct json)'
                ], \Symfony\Component\HttpFoundation\Response::HTTP_INTERNAL_SERVER_ERROR);
                
                /* Оригинальный код:
                return new \App\Http\Responses\Api\V1\ErrorResponse(
                    message: 'Internal Server Error',
                    statusCode: \Symfony\Component\HttpFoundation\Response::HTTP_INTERNAL_SERVER_ERROR
                );
                */
             }
        });

        // Не логируем business logic исключения как критические ошибки
        $exceptions->reportable(function (\App\Exceptions\Billing\InsufficientBalanceException $e) {
            return false; // Не логируем
        });

        // Добавляем трекинг исключений в Prometheus
        $exceptions->reportable(function (Throwable $e) {
            // Исключаем business logic исключения из мониторинга
            if ($e instanceof \App\Exceptions\Billing\InsufficientBalanceException) {
                return;
            }
            
            try {
                $prometheus = app(\App\Services\Monitoring\PrometheusService::class);
                $prometheus->incrementExceptions(get_class($e));
            } catch (\Exception $ignored) {
                // Игнорируем ошибки в мониторинге чтобы не сломать основное приложение
            }
        });

    })->create();
