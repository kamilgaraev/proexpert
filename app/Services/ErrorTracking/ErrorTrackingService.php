<?php

namespace App\Services\ErrorTracking;

use App\Models\ApplicationError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ErrorTrackingService
{
    /**
     * Отследить exception с автоматической дедупликацией
     */
    public function track(Throwable $exception, array $context = []): void
    {
        try {
            // Генерируем хеш для группировки одинаковых ошибок
            $errorHash = $this->generateErrorHash($exception);
            
            // Проверяем, существует ли уже такая ошибка
            $existing = ApplicationError::where('error_hash', $errorHash)->first();
            
            if ($existing) {
                // Обновляем счетчик и время последнего появления
                $existing->increment('occurrences');
                $existing->update([
                    'last_seen_at' => now(),
                ]);
            } else {
                // Создаем новую запись об ошибке
                ApplicationError::create([
                    'error_hash' => $errorHash,
                    'error_group' => $this->generateErrorGroup($exception),
                    'exception_class' => get_class($exception),
                    'message' => $exception->getMessage(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                    'stack_trace' => $exception->getTraceAsString(),
                    
                    'organization_id' => $context['organization_id'] ?? null,
                    'user_id' => $context['user_id'] ?? null,
                    'module' => $context['module'] ?? $this->detectModule(),
                    
                    'url' => request()->fullUrl(),
                    'method' => request()->method(),
                    'ip' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                    
                    'context' => $this->buildContext($context),
                    
                    'severity' => $this->determineSeverity($exception),
                    'status' => 'unresolved',
                    
                    'occurrences' => 1,
                    'first_seen_at' => now(),
                    'last_seen_at' => now(),
                ]);
            }
        } catch (\Exception $e) {
            // Если не удалось залогировать ошибку - не ломаем приложение
            Log::error('error_tracking.failed', [
                'error' => $e->getMessage(),
                'original_exception' => get_class($exception),
            ]);
        }
    }

    /**
     * Генерировать уникальный хеш для группировки одинаковых ошибок
     * Группируем по: класс exception + файл + строка
     */
    private function generateErrorHash(Throwable $exception): string
    {
        return md5(
            get_class($exception) .
            $exception->getFile() .
            $exception->getLine()
        );
    }

    /**
     * Генерировать читаемое название группы ошибок
     */
    private function generateErrorGroup(Throwable $exception): string
    {
        $class = class_basename($exception);
        $file = basename($exception->getFile());
        
        return "{$class} in {$file}:{$exception->getLine()}";
    }

    /**
     * Определить важность ошибки
     */
    private function determineSeverity(Throwable $exception): string
    {
        // Критические ошибки
        $criticalExceptions = [
            \RuntimeException::class,
            \ErrorException::class,
            \PDOException::class,
            \Illuminate\Database\QueryException::class,
        ];

        foreach ($criticalExceptions as $criticalClass) {
            if ($exception instanceof $criticalClass) {
                return 'critical';
            }
        }

        // Предупреждения
        $warningExceptions = [
            \InvalidArgumentException::class,
            \LogicException::class,
        ];

        foreach ($warningExceptions as $warningClass) {
            if ($exception instanceof $warningClass) {
                return 'warning';
            }
        }

        // По умолчанию - error
        return 'error';
    }

    /**
     * Определить модуль из URL или namespace
     */
    private function detectModule(): string
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

    /**
     * Построить контекст с дополнительной информацией
     */
    private function buildContext(array $context): array
    {
        return array_merge([
            'request_id' => request()->id(),
            'session_id' => session()->getId(),
            'env' => config('app.env'),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
        ], $context);
    }

    /**
     * Получить статистику ошибок
     */
    public function getStatistics(array $filters = []): array
    {
        $query = ApplicationError::query();

        // Применить фильтры
        if (isset($filters['organization_id'])) {
            $query->where('organization_id', $filters['organization_id']);
        }

        if (isset($filters['module'])) {
            $query->where('module', $filters['module']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['severity'])) {
            $query->where('severity', $filters['severity']);
        }

        if (isset($filters['days'])) {
            $query->where('last_seen_at', '>=', now()->subDays($filters['days']));
        }

        return [
            'total' => $query->count(),
            'unresolved' => (clone $query)->where('status', 'unresolved')->count(),
            'critical' => (clone $query)->where('severity', 'critical')->count(),
            'by_severity' => (clone $query)
                ->select('severity', DB::raw('count(*) as count'))
                ->groupBy('severity')
                ->pluck('count', 'severity')
                ->toArray(),
            'by_module' => (clone $query)
                ->select('module', DB::raw('count(*) as count'))
                ->groupBy('module')
                ->orderByDesc('count')
                ->limit(10)
                ->pluck('count', 'module')
                ->toArray(),
        ];
    }

    /**
     * Получить последние ошибки
     */
    public function getRecent(int $limit = 50, array $filters = []): \Illuminate\Database\Eloquent\Collection
    {
        $query = ApplicationError::query()
            ->with(['organization', 'user'])
            ->orderBy('last_seen_at', 'desc');

        // Применить фильтры
        if (isset($filters['organization_id'])) {
            $query->where('organization_id', $filters['organization_id']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        } else {
            // По умолчанию показываем только нерешенные
            $query->where('status', 'unresolved');
        }

        if (isset($filters['severity'])) {
            $query->where('severity', $filters['severity']);
        }

        if (isset($filters['module'])) {
            $query->where('module', $filters['module']);
        }

        return $query->limit($limit)->get();
    }

    /**
     * Получить топ ошибок по количеству появлений
     */
    public function getTopErrors(int $limit = 10, array $filters = []): \Illuminate\Database\Eloquent\Collection
    {
        $query = ApplicationError::query()
            ->where('status', 'unresolved')
            ->orderBy('occurrences', 'desc');

        if (isset($filters['organization_id'])) {
            $query->where('organization_id', $filters['organization_id']);
        }

        if (isset($filters['days'])) {
            $query->where('last_seen_at', '>=', now()->subDays($filters['days']));
        }

        return $query->limit($limit)->get();
    }
}

