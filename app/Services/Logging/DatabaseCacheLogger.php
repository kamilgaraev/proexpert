<?php

namespace App\Services\Logging;

use App\Services\Logging\LoggingService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Сервис для логирования критических Database и Cache событий
 */
class DatabaseCacheLogger
{
    protected LoggingService $logging;

    public function __construct(LoggingService $logging)
    {
        $this->logging = $logging;
    }

    /**
     * Регистрация слушателей для DB и Cache событий
     */
    public function registerListeners(): void
    {
        // Слушатель медленных SQL запросов
        Event::listen(QueryExecuted::class, function (QueryExecuted $query) {
            $this->logSlowQuery($query);
        });
    }

    /**
     * Логирование медленных SQL запросов
     */
    protected function logSlowQuery(QueryExecuted $query): void
    {
        $durationMs = $query->time;
        
        // Логируем только медленные запросы (>1 секунды)
        if ($durationMs > 1000) {
            // TECHNICAL: Медленный SQL запрос
            $this->logging->technical('database.slow_query', [
                'sql' => $query->sql,
                'bindings' => $this->sanitizeBindings($query->bindings),
                'duration_ms' => $durationMs,
                'connection_name' => $query->connectionName,
                'query_type' => $this->detectQueryType($query->sql),
                'table_name' => $this->extractTableName($query->sql),
                'is_write_operation' => $this->isWriteOperation($query->sql)
            ], $durationMs > 5000 ? 'error' : 'warning');
        }

        // Логируем критически медленные запросы (>5 секунд)
        if ($durationMs > 5000) {
            // TECHNICAL: Критически медленный запрос
            $this->logging->technical('database.critical_slow_query', [
                'sql' => $query->sql,
                'bindings' => $this->sanitizeBindings($query->bindings),
                'duration_ms' => $durationMs,
                'connection_name' => $query->connectionName,
                'query_hash' => md5($query->sql),
                'requires_optimization' => true
            ], 'critical');
        }
    }

    /**
     * Логирование операций с кэшем
     */
    public function logCacheOperation(string $operation, string $key, $value = null, ?float $durationMs = null): void
    {
        $context = [
            'operation' => $operation,
            'cache_key' => $key,
            'key_prefix' => $this->getCacheKeyPrefix($key),
            'duration_ms' => $durationMs,
            'value_size_bytes' => $value ? strlen(serialize($value)) : null
        ];

        switch ($operation) {
            case 'get':
            case 'remember':
                // TECHNICAL: Операции чтения кэша
                $this->logging->technical('cache.read', $context);
                break;
                
            case 'put':
            case 'forever':
                // TECHNICAL: Операции записи в кэш
                $this->logging->technical('cache.write', array_merge($context, [
                    'ttl_seconds' => $this->extractTtlFromKey($key)
                ]));
                break;
                
            case 'forget':
            case 'flush':
                // TECHNICAL: Операции очистки кэша
                $this->logging->technical('cache.clear', $context);
                break;
                
            case 'hit':
                // TECHNICAL: Попадание в кэш
                $this->logging->technical('cache.hit', $context);
                break;
                
            case 'miss':
                // TECHNICAL: Промах кэша
                $this->logging->technical('cache.miss', $context, 'info');
                break;
        }
    }

    /**
     * Логирование массовой очистки кэша
     */
    public function logCacheBulkClear(string $pattern, int $keysCleared): void
    {
        // TECHNICAL: Массовая очистка кэша - важно для производительности
        $this->logging->technical('cache.bulk_clear', [
            'pattern' => $pattern,
            'keys_cleared' => $keysCleared,
            'cache_type' => $this->detectCacheType($pattern)
        ], $keysCleared > 100 ? 'warning' : 'info');
    }

    /**
     * Логирование проблем с соединением к базе данных
     */
    public function logDatabaseConnection(string $event, string $connection, ?\Exception $exception = null): void
    {
        $context = [
            'connection_name' => $connection,
            'event' => $event
        ];

        if ($exception) {
            $context = array_merge($context, [
                'exception_class' => get_class($exception),
                'exception_message' => $exception->getMessage(),
                'exception_code' => $exception->getCode()
            ]);
        }

        switch ($event) {
            case 'connected':
                $this->logging->technical('database.connection.established', $context);
                break;
                
            case 'disconnected':
                $this->logging->technical('database.connection.closed', $context);
                break;
                
            case 'failed':
                $this->logging->technical('database.connection.failed', $context, 'error');
                break;
                
            case 'reconnected':
                $this->logging->technical('database.connection.restored', $context, 'warning');
                break;
        }
    }

    /**
     * Логирование Redis операций
     */
    public function logRedisOperation(string $command, array $args, ?float $durationMs = null, ?\Exception $exception = null): void
    {
        $context = [
            'redis_command' => $command,
            'args_count' => count($args),
            'duration_ms' => $durationMs,
            'first_key' => $args[0] ?? null
        ];

        if ($exception) {
            $context = array_merge($context, [
                'exception_class' => get_class($exception),
                'exception_message' => $exception->getMessage()
            ]);
            
            // TECHNICAL: Ошибка Redis
            $this->logging->technical('redis.command.failed', $context, 'error');
        } else {
            // Логируем только медленные операции Redis
            if ($durationMs && $durationMs > 500) {
                // TECHNICAL: Медленная Redis операция
                $this->logging->technical('redis.command.slow', $context, 'warning');
            }
        }
    }

    /**
     * Санитизация параметров запроса для безопасного логирования
     */
    protected function sanitizeBindings(array $bindings): array
    {
        return array_map(function ($binding) {
            if (is_string($binding)) {
                // Скрываем потенциально чувствительные данные
                if (Str::contains(strtolower($binding), ['password', 'token', 'secret', 'key', 'hash'])) {
                    return '[REDACTED]';
                }
                
                // Ограничиваем длину строк
                if (strlen($binding) > 200) {
                    return substr($binding, 0, 200) . '...';
                }
            }
            
            return $binding;
        }, $bindings);
    }

    /**
     * Определение типа SQL запроса
     */
    protected function detectQueryType(string $sql): string
    {
        $sql = strtoupper(trim($sql));
        
        if (Str::startsWith($sql, 'SELECT')) return 'SELECT';
        if (Str::startsWith($sql, 'INSERT')) return 'INSERT';
        if (Str::startsWith($sql, 'UPDATE')) return 'UPDATE';
        if (Str::startsWith($sql, 'DELETE')) return 'DELETE';
        if (Str::startsWith($sql, 'CREATE')) return 'CREATE';
        if (Str::startsWith($sql, 'DROP')) return 'DROP';
        if (Str::startsWith($sql, 'ALTER')) return 'ALTER';
        
        return 'OTHER';
    }

    /**
     * Извлечение имени таблицы из SQL
     */
    protected function extractTableName(string $sql): ?string
    {
        $sql = strtolower(trim($sql));
        
        // Простое извлечение для основных случаев
        if (preg_match('/(?:from|into|update|join)\s+`?([a-zA-Z0-9_]+)`?/i', $sql, $matches)) {
            return $matches[1];
        }
        
        return null;
    }

    /**
     * Проверка является ли запрос операцией записи
     */
    protected function isWriteOperation(string $sql): bool
    {
        $sql = strtoupper(trim($sql));
        return Str::startsWith($sql, ['INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER', 'TRUNCATE']);
    }

    /**
     * Получение префикса ключа кэша
     */
    protected function getCacheKeyPrefix(string $key): string
    {
        $parts = explode(':', $key);
        return $parts[0] ?? 'unknown';
    }

    /**
     * Извлечение TTL из ключа кэша
     */
    protected function extractTtlFromKey(string $key): ?int
    {
        // Простая логика для извлечения TTL из ключа, если он есть
        if (preg_match('/ttl_(\d+)/', $key, $matches)) {
            return (int) $matches[1];
        }
        
        return null;
    }

    /**
     * Определение типа кэша по паттерну
     */
    protected function detectCacheType(string $pattern): string
    {
        if (Str::contains($pattern, ['org_', 'organization_'])) return 'organization';
        if (Str::contains($pattern, ['user_', 'auth_'])) return 'user';
        if (Str::contains($pattern, ['project_'])) return 'project';
        if (Str::contains($pattern, ['holding_'])) return 'holding';
        if (Str::contains($pattern, ['material_'])) return 'material';
        
        return 'general';
    }
}
