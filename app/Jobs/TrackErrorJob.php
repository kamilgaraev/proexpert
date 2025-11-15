<?php

namespace App\Jobs;

use App\Services\ErrorTracking\ErrorTrackingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TrackErrorJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Количество попыток выполнения job
     */
    public int $tries = 3;

    /**
     * Таймаут выполнения (секунды)
     */
    public int $timeout = 30;

    /**
     * Через сколько повторить при неудаче (секунды)
     */
    public int $backoff = 10;

    /**
     * Данные ошибки
     */
    private string $exceptionClass;
    private string $message;
    private string $file;
    private int $line;
    private string $stackTrace;
    private array $context;

    /**
     * Create a new job instance.
     */
    public function __construct(
        string $exceptionClass,
        string $message,
        string $file,
        int $line,
        string $stackTrace,
        array $context = []
    ) {
        $this->exceptionClass = $exceptionClass;
        $this->message = $message;
        $this->file = $file;
        $this->line = $line;
        $this->stackTrace = $stackTrace;
        $this->context = $context;
    }

    /**
     * Execute the job.
     */
    public function handle(ErrorTrackingService $errorTrackingService): void
    {
        // Создать временный exception объект для передачи в сервис
        $exception = new \Exception($this->message);
        
        // Использовать рефлексию чтобы установить правильные значения
        $reflection = new \ReflectionClass($exception);
        
        $fileProperty = $reflection->getProperty('file');
        $fileProperty->setAccessible(true);
        $fileProperty->setValue($exception, $this->file);
        
        $lineProperty = $reflection->getProperty('line');
        $lineProperty->setAccessible(true);
        $lineProperty->setValue($exception, $this->line);
        
        // Записать в БД
        $errorTrackingService->track($exception, $this->context);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        // Если job провалилась после всех попыток - пишем в лог
        \Log::error('error_tracking.job.failed_permanently', [
            'exception_class' => $this->exceptionClass,
            'message' => $this->message,
            'file' => $this->file,
            'line' => $this->line,
            'attempts' => $this->attempts(),
            'failure_reason' => $exception->getMessage(),
        ]);
    }
}

