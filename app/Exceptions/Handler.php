<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use App\Services\Monitoring\PrometheusService;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<\Throwable>, \Psr\Log\LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [
        AuthorizationException::class,
        \Symfony\Component\HttpKernel\Exception\HttpException::class,
        \Illuminate\Database\Eloquent\ModelNotFoundException::class,
        ValidationException::class,
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        $this->reportable(function (Throwable $e) {
            // 
        });

        // Для API запросов мы хотим всегда возвращать JSON
        $this->renderable(function (Throwable $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                
                if ($e instanceof ValidationException) {
                    return response()->json([
                        'message' => $e->getMessage() ?: 'Данные не прошли валидацию.',
                        'errors' => $e->errors(),
                    ], $e->status);
                }

                if ($e instanceof AuthorizationException) {
                    return response()->json([
                        'message' => $e->getMessage() ?: 'Доступ запрещён.',
                    ], 403);
                }

                if ($e instanceof AuthenticationException) {
                    return response()->json([
                        'message' => $e->getMessage() ?: 'Не аутентифицировано.',
                    ], 401);
                }

                if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                    return response()->json([
                        'message' => 'Ресурс не найден.'
                    ], 404);
                }

                if ($e instanceof RouteNotFoundException) {
                    return response()->json([
                        'message' => 'Маршрут не найден или доступ запрещён.'
                    ], 401);
                }

                $statusCode = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
                $message = $e->getMessage();

                if ($statusCode >= 500 && !config('app.debug')) {
                    $message = 'Произошла внутренняя ошибка сервера. Мы уже работаем над её исправлением. ' .
                               'Если проблема повторяется, пожалуйста, свяжитесь с администрацией.';
                }
                
                $response = ['message' => $message];

                if (config('app.debug')) {
                    $response['exception'] = get_class($e);
                    $response['file'] = $e->getFile();
                    $response['line'] = $e->getLine();
                    // $response['trace'] = $e->getTraceAsString(); // Трассировка может быть очень большой
                }

                return response()->json($response, $statusCode);
            }
        });
    }

    public function report(Throwable $exception)
    {
        if (app()->bound(PrometheusService::class)) {
            $prometheus = app(PrometheusService::class);
            $prometheus->incrementExceptions(get_class($exception));
        }

        parent::report($exception);
    }

    public function render($request, Throwable $e)
    {
        // Для JSON/API отвечает register()->renderable выше
        $response = parent::render($request, $e);

        // Если не API/JSON запрос и в проде (debug=false) — подменяем тело для 5xx ошибок
        if (!config('app.debug') && !$request->expectsJson() && !$request->is('api/*')) {
            $status = $response->getStatusCode();
            if ($status >= 500) {
                return response(
                    'Произошла внутренняя ошибка сервера. Мы уже работаем над её исправлением. ' .
                    'Если проблема повторяется, пожалуйста, свяжитесь с администрацией.',
                    $status,
                    ['Content-Type' => 'text/plain; charset=utf-8']
                );
            }
        }
        return $response;
    }
} 