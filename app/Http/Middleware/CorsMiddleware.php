<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use App\Services\Logging\LoggingService;

class CorsMiddleware
{
    protected LoggingService $logging;

    public function __construct(LoggingService $logging)
    {
        $this->logging = $logging;
    }
    /**
     * Обрабатывает входящий запрос.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Получаем Origin из заголовка запроса
        $origin = $request->header('Origin');
        
        // Логируем только подозрительные или важные CORS запросы (не /metrics от Prometheus)
        if (!$this->isRoutineRequest($request)) {
            $this->logging->access([
                'event' => 'cors.request.processed',
                'method' => $request->method(),
                'origin' => $origin,
                'uri' => $request->getRequestUri(),
                'user_agent' => $request->header('User-Agent'),
                'ip_address' => $request->ip()
            ]);
        }
        
        // Получаем конфигурацию CORS
        $allowedOrigins = Config::get('cors.allowed_origins', []);
        $allowedOriginsPatterns = Config::get('cors.allowed_origins_patterns', []);
        $allowedMethods = Config::get('cors.allowed_methods', ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']);
        $allowedHeaders = Config::get('cors.allowed_headers', ['Content-Type', 'X-Auth-Token', 'Origin', 'Authorization', 'X-Requested-With']);
        $exposedHeaders = Config::get('cors.exposed_headers', []);
        $maxAge = Config::get('cors.max_age', 86400);
        $allowAnyOriginInDev = Config::get('cors.allow_any_origin_in_dev', false);
        
        // Определяем, доступен ли запрошенный origin
        $allowedOrigin = null;
        $allowCredentials = 'false';
        $originMatched = false;
        
        // Если мы в режиме разработки и настройка разрешает любой origin
        if (app()->environment('local') && $allowAnyOriginInDev) {
            $allowedOrigin = $origin ?: '*';
            $allowCredentials = ($allowedOrigin === '*') ? 'false' : 'true';
            $originMatched = true;
        } 
        // Иначе проверяем по списку разрешенных
        else if ($origin) {
            if (in_array($origin, $allowedOrigins)) {
                $allowedOrigin = $origin;
                $allowCredentials = 'true';
                $originMatched = true;
            } else {
                foreach ($allowedOriginsPatterns as $pattern) {
                    if (preg_match($pattern, $origin)) {
                        $allowedOrigin = $origin;
                        $allowCredentials = 'true';
                        $originMatched = true;
                        break;
                    }
                }
            }
            
            if (!$originMatched) {
                // В режиме разработки можем быть более снисходительными
                if (app()->environment('local')) {
                    // SECURITY: Разрешение неизвестного origin в dev среде
                    $this->logging->security('cors.origin.allowed.dev', [
                        'origin' => $origin,
                        'environment' => 'local',
                        'uri' => $request->getRequestUri()
                    ], 'info');
                    $allowedOrigin = $origin;
                    $allowCredentials = 'true';
                    $originMatched = true;
                } else {
                    // В продакшене для prohelper.pro доменов разрешаем
                    if ($origin && (strpos($origin, '.prohelper.pro') !== false || $origin === 'https://prohelper.pro')) {
                        // SECURITY: Разрешение prohelper.pro домена не из списка
                        $this->logging->security('cors.origin.allowed.prohelper', [
                            'origin' => $origin,
                            'uri' => $request->getRequestUri(),
                            'auto_approved' => true
                        ], 'info');
                        $allowedOrigin = $origin;
                        $allowCredentials = 'true';
                        $originMatched = true;
                    } else {
                        // SECURITY: КРИТИЧНО - Отклонен запрос с недопустимого origin
                        $this->logging->security('cors.origin.rejected', [
                            'origin' => $origin,
                            'uri' => $request->getRequestUri(),
                            'user_agent' => $request->header('User-Agent'),
                            'ip_address' => $request->ip(),
                            'allowed_origins' => $allowedOrigins,
                            'potential_security_threat' => true
                        ], 'warning');
                        $allowedOrigin = 'null';
                        $allowCredentials = 'false';
                    }
                }
            }
        } else {
            // Если origin не указан, используем wildcard (только для запросов без credentials)
            $allowedOrigin = '*';
            $allowCredentials = 'false';
            $originMatched = true;
        }
        
        // Устанавливаем заголовки CORS для ответа
        $headers = [
            // Устанавливаем origin из запроса или wildcard
            'Access-Control-Allow-Origin' => $allowedOrigin,
            // Разрешить включать учетные данные (только если не wildcard)
            'Access-Control-Allow-Credentials' => $allowCredentials,
            // Разрешить указанные методы
            'Access-Control-Allow-Methods' => implode(', ', $allowedMethods),
            // Разрешить указанные заголовки
            'Access-Control-Allow-Headers' => implode(', ', $allowedHeaders),
            // Срок действия preflight запроса
            'Access-Control-Max-Age' => (string) $maxAge,
        ];
        
        // Добавляем exposed headers, если они есть
        if (!empty($exposedHeaders)) {
            $headers['Access-Control-Expose-Headers'] = implode(', ', $exposedHeaders);
        }
        
        // Если это preflight OPTIONS-запрос
        if ($request->isMethod('OPTIONS')) {
            // TECHNICAL: Обработка preflight запроса - важно для API интеграций
            $this->logging->technical('cors.preflight.processed', [
                'origin' => $origin,
                'allowed_origin' => $allowedOrigin,
                'origin_matched' => $originMatched,
                'uri' => $request->getRequestUri(),
                'requested_method' => $request->header('Access-Control-Request-Method'),
                'requested_headers' => $request->header('Access-Control-Request-Headers')
            ]);
            // Возвращаем пустой ответ 200 с нужными CORS-заголовками
            return response('', 200, $headers);
        }
        
        try {
            // Для других запросов вызываем следующий middleware в цепочке
            $response = $next($request);
            
            // Добавляем заголовки CORS к ответу
            foreach ($headers as $key => $value) {
                $response->headers->set($key, $value);
            }
            
            // Логируем только проблемные или важные CORS ответы (не каждый /metrics)
            if (!$this->isRoutineRequest($request) || $response->getStatusCode() >= 400) {
                // ACCESS: Успешная обработка CORS
                $this->logging->access([
                    'event' => 'cors.response.success',
                    'uri' => $request->getRequestUri(),
                    'method' => $request->method(),
                    'status_code' => $response->getStatusCode(),
                    'allow_origin' => $response->headers->get('Access-Control-Allow-Origin'),
                    'origin' => $origin
                ]);
            }
            
            return $response;
        } catch (\Throwable $e) {
            // TECHNICAL: Исключение в CORS middleware
            $this->logging->technical('cors.exception.caught', [
                'uri' => $request->getRequestUri(),
                'method' => $request->method(),
                'exception_class' => get_class($e),
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'origin' => $origin
            ], 'error');

            // Специальная обработка для business logic исключений - пробрасываем дальше в Handler
            if ($e instanceof \App\Exceptions\Billing\InsufficientBalanceException ||
                $e instanceof \App\Exceptions\BusinessLogicException ||
                $e instanceof \Illuminate\Validation\ValidationException ||
                $e instanceof \Illuminate\Auth\AuthenticationException ||
                $e instanceof \Illuminate\Auth\Access\AuthorizationException ||
                $e instanceof \InvalidArgumentException) { // Для ошибок конфигурации (например, guard не определён)
                
                // Сохраняем CORS заголовки в запросе для Handler
                $request->attributes->set('cors_headers', $headers);
                
                throw $e; // Пробрасываем в Handler
            }

            // TECHNICAL: Системная ошибка в CORS middleware
            $this->logging->technical('cors.system.error', [
                'error_message' => $e->getMessage(),
                'uri' => $request->getRequestUri(),
                'method' => $request->method(),
                'exception_class' => get_class($e),
                'stack_trace_hash' => md5($e->getTraceAsString())
            ], 'error');
            
            // Возвращаем ответ об ошибке с заголовками CORS только для системных ошибок
            return response()->json([
                'error' => 'Ошибка на сервере',
                'message' => 'При обработке запроса произошла ошибка. Администратор уведомлен. [Diag: Catch Block Reached]'
            ], 500, $headers);
        }
    }

    /**
     * Проверяет является ли запрос рутинным (например, мониторинг)
     */
    protected function isRoutineRequest(Request $request): bool
    {
        $uri = $request->getRequestUri();
        $userAgent = $request->header('User-Agent', '');
        
        // Prometheus мониторинг
        if (str_contains($uri, '/metrics') && str_contains($userAgent, 'Prometheus/')) {
            return true;
        }
        
        // Health checks
        if (in_array($uri, ['/up', '/health', '/ping'])) {
            return true;
        }
        
        return false;
    }
}