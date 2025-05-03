<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ApiLoggingMiddleware
{
    /**
     * Максимальный размер логируемого тела запроса/ответа
     */
    protected const MAX_CONTENT_SIZE = 10000;

    /**
     * Список полей, которые будут маскироваться
     */
    protected const SENSITIVE_FIELDS = [
        'password', 
        'password_confirmation', 
        'token', 
        'auth_token', 
        'api_key', 
        'secret', 
        'credit_card'
    ];

    /**
     * Логирование API-запросов и ответов.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Генерируем уникальный ID запроса
        $requestId = uniqid('req_', true);
        
        // Добавляем requestId в контекст запроса для использования в других местах
        $request->attributes->set('request_id', $requestId);
        
        // Логируем входящий запрос
        $this->logRequest($request, $requestId);
        
        // Замеряем время выполнения
        $startTime = microtime(true);
        
        // Обрабатываем запрос
        $response = $next($request);
        
        // Вычисляем затраченное время
        $executionTime = round(microtime(true) - $startTime, 3);
        
        // Логируем ответ
        $this->logResponse($response, $requestId, $executionTime);
        
        // Добавляем ID запроса в заголовки ответа для отладки
        $response->headers->set('X-Request-ID', $requestId);
        
        return $response;
    }
    
    /**
     * Логирование входящего запроса.
     *
     * @param Request $request
     * @param string $requestId
     * @return void
     */
    protected function logRequest(Request $request, string $requestId)
    {
        // Фильтруем чувствительные данные
        $input = $this->maskSensitiveData($request->all());
        
        $data = [
            'type' => 'request',
            'id' => $requestId,
            'ip' => $request->ip(),
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'route' => $request->route() ? $request->route()->getName() : null,
            'user_agent' => $request->header('User-Agent'),
            'headers' => $this->getHeadersForLog($request),
            'payload' => $this->truncateContent($input),
        ];
        
        // Добавляем информацию о пользователе, если он авторизован
        if ($request->user()) {
            $data['user'] = [
                'id' => $request->user()->id,
                'email' => $request->user()->email,
            ];
        }
        
        Log::channel('api')->info('API запрос', $data);
    }
    
    /**
     * Логирование ответа.
     *
     * @param Response $response
     * @param string $requestId
     * @param float $executionTime
     * @return void
     */
    protected function logResponse($response, string $requestId, float $executionTime)
    {
        // Получаем тело ответа
        $content = $response->getContent();
        
        // Если это JSON, декодируем
        $responseBody = json_decode($content, true);
        
        $responseData = $responseBody ?: $content;
        
        // Маскируем чувствительные данные
        if (is_array($responseData)) {
            $responseData = $this->maskSensitiveData($responseData);
        }
        
        $data = [
            'type' => 'response',
            'id' => $requestId,
            'status' => $response->getStatusCode(),
            'time' => $executionTime,
            'response' => $this->truncateContent($responseData),
            'memory' => round(memory_get_peak_usage(true) / 1024 / 1024, 2).' MB',
        ];
        
        Log::channel('api')->info('API ответ', $data);
    }
    
    /**
     * Получение заголовков для логирования.
     *
     * @param Request $request
     * @return array
     */
    protected function getHeadersForLog(Request $request)
    {
        $headers = $request->headers->all();
        
        // Чувствительные заголовки
        $sensitiveHeaders = [
            'authorization',
            'cookie',
            'x-xsrf-token',
            'x-csrf-token',
        ];
        
        foreach ($sensitiveHeaders as $header) {
            if (isset($headers[$header])) {
                $headers[$header] = ['******'];
            }
        }
        
        return $headers;
    }
    
    /**
     * Маскирует чувствительные данные в массиве.
     *
     * @param array $data
     * @return array
     */
    protected function maskSensitiveData(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->maskSensitiveData($value);
            } elseif (is_string($key) && in_array(strtolower($key), self::SENSITIVE_FIELDS, true)) {
                $data[$key] = '******';
            }
        }
        
        return $data;
    }
    
    /**
     * Ограничивает размер контента для логирования.
     *
     * @param mixed $content
     * @return mixed
     */
    protected function truncateContent($content)
    {
        if (is_string($content) && strlen($content) > self::MAX_CONTENT_SIZE) {
            return substr($content, 0, self::MAX_CONTENT_SIZE) . ' [обрезано...]';
        }
        
        if (is_array($content)) {
            $serialized = json_encode($content);
            if (strlen($serialized) > self::MAX_CONTENT_SIZE) {
                return ['truncated' => true, 'message' => 'Содержимое слишком большое для логирования'];
            }
        }
        
        return $content;
    }
} 