<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\JwtMiddleware;
use App\Http\Middleware\SetOrganizationContext;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Регистрация псевдонимов
        $middleware->alias([
            'role' => \App\Http\Middleware\CheckRoleMiddleware::class,
            'auth.jwt' => JwtMiddleware::class,
            'jwt.auth' => JwtMiddleware::class,
            'organization.context' => SetOrganizationContext::class,
            'organization_context' => SetOrganizationContext::class,
            'subscription.limit' => \App\Http\Middleware\CheckSubscriptionLimitsMiddleware::class,
            'module.access' => \App\Http\Middleware\CheckModuleAccessMiddleware::class,
            'holding.subdomain' => \App\Http\Middleware\DetectHoldingSubdomain::class,
        ]);

        // Глобальные middleware
        $middleware->prepend(\App\Http\Middleware\CorsMiddleware::class);
        $middleware->append(\App\Http\Middleware\PrometheusMiddleware::class);

        // Группы middleware (например, для API)
        $middleware->api([
             'throttle:api',
             \Illuminate\Routing\Middleware\SubstituteBindings::class,
             // Временно отключаем логирование для диагностики ошибки 500 при 403
             // \App\Http\Middleware\ApiLoggingMiddleware::class,
             \App\Http\Middleware\SetOrganizationContext::class,
        ]);

        // Middleware для веб-группы (если нужно)
        // $middleware->web([...]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Кастомизация обработки исключений
        $exceptions->renderable(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            if ($request->expectsJson()) {
                 return new \App\Http\Responses\Api\V1\ErrorResponse('Unauthenticated.', \Symfony\Component\HttpFoundation\Response::HTTP_UNAUTHORIZED);
            }
        });

        $exceptions->renderable(function (\Illuminate\Auth\Access\AuthorizationException $e, $request) {
            if ($request->expectsJson()) {
                 return response()->json([
                     'success' => false,
                     'message' => $e->getMessage() ?: 'Forbidden.'
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
        
        // Обработка всех остальных ошибок для API, когда НЕ в режиме отладки
        $exceptions->renderable(function (\Throwable $e, $request) {
             if ($request->expectsJson() && !app()->hasDebugModeEnabled()) {
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

        // Добавляем трекинг исключений в Prometheus
        $exceptions->reportable(function (Throwable $e) {
            try {
                $prometheus = app(\App\Services\Monitoring\PrometheusService::class);
                $prometheus->incrementExceptions(get_class($e));
            } catch (\Exception $ignored) {
                // Игнорируем ошибки в мониторинге чтобы не сломать основное приложение
            }
        });

    })->create();
