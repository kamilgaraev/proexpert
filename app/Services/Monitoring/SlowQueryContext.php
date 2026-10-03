<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use DateTimeInterface;
use Illuminate\Database\Events\QueryExecuted;

final class SlowQueryContext
{
    public function __construct(private readonly TracingService $tracing) {}

    public function forQuery(QueryExecuted $query): array
    {
        return [
            'duration_ms' => round($query->time, 2),
            'connection' => $query->connectionName,
            'sql' => mb_substr(self::safeSql($query->sql), 0, 4000),
            'sql_length' => mb_strlen($query->sql),
            'sql_fingerprint' => hash('sha256', $query->sql),
            'bindings' => self::safeBindings($query->sql, $query->bindings),
            'bindings_count' => count($query->bindings),
            'bindings_truncated' => false,
            'transaction_level' => $query->connection->transactionLevel(),
            'execution_context' => $this->tracing->executionContext(),
            'source' => self::source(),
            ...$this->tracing->slowQueryTrace($query),
        ];
    }

    public static function safeBindings(string $sql, array $bindings): array
    {
        $sensitiveQuery = preg_match('/password|token|secret|authorization|credential|private_key|api_key|email|phone|passport|\binn\b|snils|\botp\b|\bpin\b|card_number/i', $sql) === 1;
        $result = [];
        foreach (array_values($bindings) as $position => $value) {
            $item = ['position' => $position, 'type' => get_debug_type($value)];
            if ($value === null) {
                $item['value'] = null;
            } elseif (! $sensitiveQuery && (is_int($value) || is_float($value) || is_bool($value))) {
                $item['value'] = $value;
            } elseif (! $sensitiveQuery && $value instanceof DateTimeInterface) {
                $item['value'] = $value->format(DATE_ATOM);
            } elseif (! $sensitiveQuery && is_string($value) && (
                preg_match('/^(?:[0-9]{1,19}|\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:?\d{2})?)?)$/D', $value) === 1
                || in_array($value, ['created', 'queued', 'running', 'importing', 'parsed', 'succeeded', 'failed', 'completed', 'cancelled'], true)
            )) {
                $item['value'] = $value;
            } else {
                $item['redacted'] = true;
                if (is_string($value)) {
                    $item['length'] = strlen($value);
                }
            }
            $result[] = $item;
        }

        return $result;
    }

    public static function safeSql(string $sql): string
    {
        return preg_replace("/(?:E)?'(?:''|\\\\.|[^'\\\\])*'|(\\$[A-Za-z_][A-Za-z0-9_]*\\$|\\$\\$)[\\s\\S]*?\\1/i", "'[redacted]'", $sql) ?? '[sql redacted]';
    }

    private static function source(): ?array
    {
        $root = base_path().DIRECTORY_SEPARATOR;
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 96) as $frame) {
            $file = $frame['file'] ?? '';
            if (! str_starts_with($file, $root)) {
                continue;
            }
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($root)));
            if ($file !== __FILE__ && $relative !== 'app/Providers/AppServiceProvider.php'
                && preg_match('#^(?:app/|database/|tests/|routes/|artisan$)#', $relative) === 1) {
                return ['file' => $relative, 'line' => $frame['line'] ?? null];
            }
        }

        return null;
    }
}
