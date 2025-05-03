<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Throwable;

class LogService
{
    /**
     * Получает ID текущего запроса, если доступен.
     *
     * @return string|null
     */
    protected static function getRequestId(): ?string
    {
        if (Request::hasSession() && Request::session()->has('request_id')) {
            return Request::session()->get('request_id');
        }
        
        if (request()->attributes->has('request_id')) {
            return request()->attributes->get('request_id');
        }
        
        return null;
    }
    
    /**
     * Подготавливает данные для логирования, добавляя дополнительный контекст.
     *
     * @param array $data
     * @return array
     */
    protected static function prepareLogData(array $data): array
    {
        $requestId = self::getRequestId();
        
        if ($requestId) {
            $data['request_id'] = $requestId;
        }
        
        // Добавляем ID пользователя
        if (auth()->check()) {
            $data['user_id'] = auth()->id();
        }
        
        // Добавляем информацию о запросе
        if (app()->runningInConsole()) {
            $data['context'] = 'console';
            $data['command'] = request()->server('argv', []);
        } else {
            $data['context'] = 'http';
            $data['url'] = request()->fullUrl();
            $data['method'] = request()->method();
        }
        
        return $data;
    }
    
    /**
     * Логирование информационного сообщения.
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    public static function info(string $message, array $context = []): void
    {
        Log::channel('api')->info($message, self::prepareLogData($context));
    }
    
    /**
     * Логирование предупреждения.
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    public static function warning(string $message, array $context = []): void
    {
        Log::channel('api')->warning($message, self::prepareLogData($context));
    }
    
    /**
     * Логирование события аутентификации.
     *
     * @param string $action Действие (login, logout, etc)
     * @param array $data Данные для логирования
     * @return void
     */
    public static function authLog(string $action, array $data): void
    {
        $context = array_merge(['action' => $action], $data);
        Log::channel('api')->info("AUTH", self::prepareLogData($context));
    }

    /**
     * Логирование бизнес-события.
     *
     * @param string $event Название события
     * @param array $data Данные для логирования
     * @return void
     */
    public static function businessEvent(string $event, array $data): void
    {
        $context = array_merge(['event' => $event], $data);
        Log::channel('api')->info("BUSINESS_EVENT", self::prepareLogData($context));
    }

    /**
     * Логирование ошибки.
     *
     * @param string $message Сообщение об ошибке
     * @param array $context Контекст ошибки
     * @return void
     */
    public static function error(string $message, array $context = []): void
    {
        Log::channel('api')->error($message, self::prepareLogData($context));
    }

    /**
     * Логирование критической ошибки.
     *
     * @param string $message Сообщение о критической ошибке
     * @param array $context Контекст ошибки
     * @return void
     */
    public static function critical(string $message, array $context = []): void
    {
        Log::channel('api')->critical($message, self::prepareLogData($context));
    }

    /**
     * Логирование исключения.
     *
     * @param Throwable $exception Исключение
     * @param array $additionalContext Дополнительная информация
     * @return void
     */
    public static function exception(Throwable $exception, array $additionalContext = []): void
    {
        $context = array_merge([
            'exception_class' => get_class($exception),
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ], $additionalContext);

        Log::channel('api')->error("EXCEPTION", self::prepareLogData($context));
    }
    
    /**
     * Запись телеметрии для отслеживания производительности.
     *
     * @param string $operation Название операции
     * @param float $duration Длительность в секундах
     * @param array $context Дополнительный контекст
     * @return void
     */
    public static function telemetry(string $operation, float $duration, array $context = []): void
    {
        $telemetryData = array_merge([
            'operation' => $operation,
            'duration' => $duration,
            'memory' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
        ], $context);
        
        Log::channel('telemetry')->info("TELEMETRY", self::prepareLogData($telemetryData));
    }
} 