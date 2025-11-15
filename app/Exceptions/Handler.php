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
use App\Services\ErrorTracking\ErrorTrackingService;
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
                \Log::info('[Handler] renderable() called', [
                    'exception_class' => get_class($e),
                    'exception_message' => $e->getMessage(),
                    'exception_code' => $e->getCode(),
                    'uri' => $request->getRequestUri(),
                    'method' => $request->getMethod(),
                ]);
                
                // Получаем CORS заголовки из CorsMiddleware если есть
                $corsHeaders = $request->attributes->get('cors_headers', []);
                
                if ($e instanceof ValidationException) {
                    return response()->json([
                        'success' => false,
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
                        'success' => false,
                        'message' => $e->getMessage() ?: 'Ошибка бизнес-логики.',
                    ], $status, $corsHeaders);
                }

                if ($e instanceof AuthorizationException) {
                    \Log::info('[Handler] AuthorizationException caught', [
                        'message' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]);
                    
                    $message = $e->getMessage();
                    
                    // Делаем сообщение более понятным
                    if (empty($message) || $message === 'This action is unauthorized.') {
                        $message = 'У вас недостаточно прав для выполнения этого действия. Обратитесь к администратору.';
                    }
                    
                    \Log::info('[Handler] Returning 403 response');
                    
                    return response()->json([
                        'success' => false,
                        'message' => $message,
                    ], 403, $corsHeaders);
                }

                if ($e instanceof AuthenticationException) {
                    return response()->json([
                        'success' => false,
                        'message' => $e->getMessage() ?: 'Требуется аутентификация.',
                    ], 401, $corsHeaders);
                }

                if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Запрашиваемый ресурс не найден.'
                    ], 404, $corsHeaders);
                }

                if ($e instanceof RouteNotFoundException) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Маршрут не найден или доступ запрещён.'
                    ], 401, $corsHeaders);
                }

                if ($e instanceof InsufficientBalanceException) {
                    return response()->json([
                        'success' => false,
                        'message' => $e->getMessage() ?: 'Недостаточно средств на балансе для выполнения операции.'
                    ], 402, $corsHeaders); // 402 Payment Required
                }

                $statusCode = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
                $message = $e->getMessage();

                if ($statusCode >= 500 && !config('app.debug')) {
                    $message = 'Произошла внутренняя ошибка сервера. Мы уже работаем над её исправлением. ' .
                               'Если проблема повторяется, пожалуйста, свяжитесь с администрацией.';
                }
                
                $response = [
                    'success' => false,
                    'message' => $message
                ];

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

        // Error Tracking - сохранение ошибок в БД для анализа
        if ($this->shouldReport($exception) && config('error-tracking.enabled', true)) {
            try {
                $mode = config('error-tracking.mode', 'async');
                
                // Проверить, не в списке игнорируемых ли
                $ignoredExceptions = config('error-tracking.ignored_exceptions', []);
                $shouldIgnore = false;
                foreach ($ignoredExceptions as $ignoredException) {
                    if ($exception instanceof $ignoredException) {
                        $shouldIgnore = true;
                        break;
                    }
                }
                
                if (!$shouldIgnore) {
                    if ($mode === 'async' && app()->bound(\App\Services\ErrorTracking\ErrorTrackingServiceAsync::class)) {
                        // Async режим - через очередь
                        $errorTracking = app(\App\Services\ErrorTracking\ErrorTrackingServiceAsync::class);
                    } else {
                        // Sync режим - напрямую в БД
                        $errorTracking = app(ErrorTrackingService::class);
                    }
                    
                    $errorTracking->track($exception, [
                        'organization_id' => request()->attributes->get('current_organization_id'),
                        'user_id' => auth()->id(),
                        'module' => $this->detectModuleFromRequest(),
                    ]);
                }
            } catch (\Exception $e) {
                // Не ломаем приложение если error tracking упал
                \Log::error('error_tracking.integration_failed', [
                    'error' => $e->getMessage(),
                ]);
            }
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
        $statusCode = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
        
        $exceptionContext = [
            'exception_class' => get_class($exception),
            'exception_message' => $exception->getMessage(),
            'exception_file' => $exception->getFile(),
            'exception_line' => $exception->getLine(),
            'exception_code' => $exception->getCode(),
            'request_method' => $request->method(),
            'request_uri' => $request->getRequestUri(),
            'request_data' => [
                'query' => $request->query->all(),
                'input_keys' => array_keys($request->all()),
                'body_size' => strlen($request->getContent()),
            ],
            'user_id' => $user?->id,
            'organization_id' => $request->attributes->get('current_organization_id'),
            'user_agent' => $request->header('User-Agent'),
            'ip_address' => $request->ip(),
            'stack_trace_hash' => md5($exception->getTraceAsString()),
            'status_code' => $statusCode,
        ];
        
        if ($statusCode >= 500) {
            $exceptionContext['stack_trace'] = $exception->getTraceAsString();
            $exceptionContext['trace_array'] = array_slice($exception->getTrace(), 0, 10);
            
            if ($exception->getPrevious()) {
                $exceptionContext['previous_exception'] = [
                    'class' => get_class($exception->getPrevious()),
                    'message' => $exception->getPrevious()->getMessage(),
                    'file' => $exception->getPrevious()->getFile(),
                    'line' => $exception->getPrevious()->getLine(),
                    'trace' => array_slice($exception->getPrevious()->getTrace(), 0, 5),
                ];
            }
        }

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

    /**
     * Определить модуль из текущего запроса
     */
    protected function detectModuleFromRequest(): string
    {
        $path = request()->path();
        
        // Попытка определить из URL (api/v1/admin/{module}/...)
        if (preg_match('#api/v1/admin/([^/]+)#', $path, $matches)) {
            return $matches[1];
        }
        
        // Попытка определить из URL (api/v1/lk/{module}/...)
        if (preg_match('#api/v1/lk/([^/]+)#', $path, $matches)) {
            return $matches[1];
        }
        
        return 'unknown';
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