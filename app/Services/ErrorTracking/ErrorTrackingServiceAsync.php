<?php

namespace App\Services\ErrorTracking;

use App\Jobs\TrackErrorJob;
use Throwable;

/**
 * Асинхронная версия Error Tracking через Queue
 * Более отказоустойчиво - ошибки пишутся в Redis Queue, 
 * даже если БД временно недоступна
 */
class ErrorTrackingServiceAsync
{
    /**
     * Отследить exception асинхронно через очередь
     */
    public function track(Throwable $exception, array $context = []): void
    {
        try {
            // Отправить в очередь (Redis), не блокирует основной поток
            TrackErrorJob::dispatch(
                get_class($exception),
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine(),
                $exception->getTraceAsString(),
                $context
            )->onQueue('logging'); // Отдельная очередь для логирования
            
        } catch (\Exception $e) {
            // Если даже queue не работает - пишем в файл
            $this->fallbackToFile($exception, $context, $e);
        }
    }

    /**
     * Fallback - запись в файл если всё упало
     */
    private function fallbackToFile(Throwable $exception, array $context, \Exception $queueError): void
    {
        try {
            $logFile = storage_path('logs/error-tracking-fallback.log');
            
            $data = json_encode([
                'timestamp' => now()->toIso8601String(),
                'exception_class' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
                'context' => $context,
                'queue_error' => $queueError->getMessage(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            
            file_put_contents($logFile, $data . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (\Exception $e) {
            // Если даже файл не пишется - просто логируем в stderr
            error_log("ERROR TRACKING COMPLETELY FAILED: " . $exception->getMessage());
        }
    }
}

