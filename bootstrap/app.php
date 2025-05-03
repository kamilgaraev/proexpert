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
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Регистрация псевдонимов
        $middleware->alias([
            'role' => \App\Http\Middleware\CheckRoleMiddleware::class,
            'auth.jwt' => JwtMiddleware::class,
            'organization.context' => SetOrganizationContext::class,
        ]);

        // Глобальные middleware (если нужны)
        // $middleware->append(\App\Http\Middleware\SomeGlobalMiddleware::class);

        // Группы middleware (например, для API)
        $middleware->api([
             'throttle:api',
             \Illuminate\Routing\Middleware\SubstituteBindings::class,
             // Добавьте сюда ApiLoggingMiddleware, если он общий для всех API
             \App\Http\Middleware\ApiLoggingMiddleware::class,
             // Добавьте сюда SetOrganizationContext, если он общий
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
                 return new \App\Http\Responses\Api\V1\ErrorResponse($e->getMessage() ?: 'Forbidden.', \Symfony\Component\HttpFoundation\Response::HTTP_FORBIDDEN);
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
                    statusCode: \Symfony\Component\HttpFoundation\Response::HTTP_UNPROCESSABLE_ENTITY,
                    errors: $e->errors()
                );
            }
        });
        
        // Обработка всех остальных ошибок для API в production
        $exceptions->renderable(function (\Throwable $e, $request) {
             if ($request->expectsJson() && !app()->hasDebugModeEnabled()) {
                report($e); // Логируем ошибку
                return new \App\Http\Responses\Api\V1\ErrorResponse(
                    message: 'Internal Server Error',
                    statusCode: \Symfony\Component\HttpFoundation\Response::HTTP_INTERNAL_SERVER_ERROR
                );
             }
        });

    })->create();
