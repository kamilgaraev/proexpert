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
use App\Services\Logging\LoggingService;
use App\Exceptions\Billing\InsufficientBalanceException;
use App\Exceptions\BusinessLogicException;

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
        InsufficientBalanceException::class,
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
     * Determine if the exception should be reported.
     */
    public function shouldReport(Throwable $e)
    {
        // Не логируем InsufficientBalanceException вообще
        if ($e instanceof InsufficientBalanceException) {
            return false;
        }

        return parent::shouldReport($e);
    }

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
                
                // Получаем CORS заголовки из CorsMiddleware если есть
                $corsHeaders = $request->attributes->get('cors_headers', []);
                
                if ($e instanceof ValidationException) {
                    return response()->json([
                        'message' => $e->getMessage() ?: 'Данные не прошли валидацию.',
                        'errors' => $e->errors(),
                    ], $e->status, $corsHeaders);
                }

                if ($e instanceof BusinessLogicException) {
                    $status = $e->getCode();
                    if (!is_int($status) || $status < 400 || $status >= 600) {
                        $status = 400;
                    }
                    return response()->json([
                        'message' => $e->getMessage() ?: 'Ошибка бизнес-логики.',
                    ], $status, $corsHeaders);
                }

                if ($e instanceof AuthorizationException) {
                    return response()->json([
                        'message' => $e->getMessage() ?: 'Доступ запрещён.',
                    ], 403, $corsHeaders);
                }

                if ($e instanceof AuthenticationException) {
                    return response()->json([
                        'message' => $e->getMessage() ?: 'Не аутентифицировано.',
                    ], 401, $corsHeaders);
                }

                if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                    return response()->json([
                        'message' => 'Ресурс не найден.'
                    ], 404, $corsHeaders);
                }

                if ($e instanceof RouteNotFoundException) {
                    return response()->json([
                        'message' => 'Маршрут не найден или доступ запрещён.'
                    ], 401, $corsHeaders);
                }

                if ($e instanceof InsufficientBalanceException) {
                    return response()->json([
                        'message' => $e->getMessage() ?: 'Недостаточно средств на балансе для выполнения операции.'
                    ], 402, $corsHeaders); // 402 Payment Required
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

                return response()->json($response, $statusCode, $corsHeaders);
            }
        });
    }

    public function report(Throwable $exception)
    {
        // Интеграция с PrometheusService
        if (app()->bound(PrometheusService::class)) {
            $prometheus = app(PrometheusService::class);
            $prometheus->incrementExceptions(get_class($exception));
        }

        // Structured Logging для исключений
        if (app()->bound(LoggingService::class)) {
            $logging = app(LoggingService::class);
            $this->logStructuredException($exception, $logging);
        }

        parent::report($exception);
    }

    /**
     * Структурированное логирование исключений для анализа и мониторинга
     */
    protected function logStructuredException(Throwable $exception, LoggingService $logging): void
    {
        $request = request();
        $user = $request->user();
        
        $exceptionContext = [
            'exception_class' => get_class($exception),
            'exception_message' => $exception->getMessage(),
            'exception_file' => $exception->getFile(),
            'exception_line' => $exception->getLine(),
            'exception_code' => $exception->getCode(),
            'request_method' => $request->method(),
            'request_uri' => $request->getRequestUri(),
            'user_id' => $user?->id,
            'organization_id' => $request->attributes->get('current_organization_id'),
            'user_agent' => $request->header('User-Agent'),
            'ip_address' => $request->ip(),
            'stack_trace_hash' => md5($exception->getTraceAsString())
        ];

        // Категоризация исключений по типам
        if ($exception instanceof ValidationException) {
            // TECHNICAL: Ошибки валидации - важны для UX
            $logging->technical('exception.validation', array_merge($exceptionContext, [
                'validation_errors' => $exception->errors(),
                'failed_rules' => array_keys($exception->errors())
            ]), 'warning');
            
        } elseif ($exception instanceof BusinessLogicException) {
            // BUSINESS: Ошибки бизнес-логики - важны для понимания проблем процессов
            $logging->business('exception.business_logic', $exceptionContext, 'warning');
            
        } elseif ($exception instanceof AuthenticationException) {
            // SECURITY: Проблемы аутентификации - критичны для безопасности
            $logging->security('exception.authentication', array_merge($exceptionContext, [
                'attempted_route' => $request->route()?->getName(),
                'auth_guard' => $exception->guards()
            ]), 'warning');
            
        } elseif ($exception instanceof AuthorizationException) {
            // SECURITY: Нарушения авторизации - важны для безопасности
            $logging->security('exception.authorization', array_merge($exceptionContext, [
                'attempted_action' => $request->route()?->getActionName(),
                'required_permission' => $this->extractPermissionFromMessage($exception->getMessage())
            ]), 'warning');
            
        } elseif ($exception instanceof InsufficientBalanceException) {
            // BUSINESS: Проблемы с балансом - критично для биллинга
            $logging->business('exception.insufficient_balance', array_merge($exceptionContext, [
                'billing_context' => true
            ]), 'warning');
            
        } elseif ($exception instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
            // TECHNICAL: Модель не найдена - может указывать на проблемы данных
            $logging->technical('exception.model_not_found', array_merge($exceptionContext, [
                'model_type' => $this->extractModelFromException($exception)
            ]), 'info');
            
        } elseif ($exception instanceof \Illuminate\Database\QueryException) {
            // TECHNICAL: Ошибки базы данных - критичны для стабильности
            $logging->technical('exception.database_query', array_merge($exceptionContext, [
                'sql_error_code' => $exception->errorInfo[1] ?? null,
                'sql_error_message' => $exception->errorInfo[2] ?? null,
                'is_connection_error' => str_contains($exception->getMessage(), 'Connection')
            ]), 'error');
            
        } elseif ($exception instanceof HttpExceptionInterface) {
            $statusCode = $exception->getStatusCode();
            
            if ($statusCode >= 500) {
                // TECHNICAL: 5xx ошибки - серверные проблемы
                $logging->technical('exception.server_error', array_merge($exceptionContext, [
                    'http_status' => $statusCode,
                    'is_server_error' => true
                ]), 'error');
            } elseif ($statusCode >= 400) {
                // TECHNICAL: 4xx ошибки - клиентские проблемы
                $logging->technical('exception.client_error', array_merge($exceptionContext, [
                    'http_status' => $statusCode,
                    'is_client_error' => true
                ]), 'warning');
            }
        } else {
            // TECHNICAL: Прочие исключения
            $logging->technical('exception.general', $exceptionContext, 'error');
        }
    }

    /**
     * Извлечение информации о разрешении из сообщения об исключении
     */
    protected function extractPermissionFromMessage(string $message): ?string
    {
        if (preg_match('/permission[:\s]*([a-zA-Z0-9_.-]+)/i', $message, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Извлечение типа модели из исключения
     */
    protected function extractModelFromException(\Illuminate\Database\Eloquent\ModelNotFoundException $exception): ?string
    {
        return $exception->getModel();
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