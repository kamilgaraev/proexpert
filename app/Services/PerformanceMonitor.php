<?php

namespace App\Services;

class PerformanceMonitor
{
    /**
     * Время начала измерения
     *
     * @var float
     */
    protected float $startTime;
    
    /**
     * Название операции
     *
     * @var string
     */
    protected string $operation;
    
    /**
     * Дополнительный контекст
     *
     * @var array
     */
    protected array $context;
    
    /**
     * Конструктор.
     *
     * @param string $operation
     * @param array $context
     */
    public function __construct(string $operation, array $context = [])
    {
        $this->operation = $operation;
        $this->context = $context;
        $this->startTime = microtime(true);
    }
    
    /**
     * Создает новый экземпляр класса и запускает мониторинг.
     *
     * @param string $operation
     * @param array $context
     * @return self
     */
    public static function start(string $operation, array $context = []): self
    {
        return new self($operation, $context);
    }
    
    /**
     * Останавливает мониторинг и логирует результаты.
     *
     * @param array $additionalContext
     * @return float Длительность операции в секундах
     */
    public function stop(array $additionalContext = []): float
    {
        $duration = microtime(true) - $this->startTime;
        
        $context = array_merge($this->context, $additionalContext);
        
        LogService::telemetry($this->operation, $duration, $context);
        
        return $duration;
    }
    
    /**
     * Выполняет замыкание и измеряет его производительность.
     *
     * @param string $operation
     * @param callable $callback
     * @param array $context
     * @return mixed
     */
    public static function measure(string $operation, callable $callback, array $context = [])
    {
        $monitor = self::start($operation, $context);
        
        try {
            $result = $callback();
            
            // Если результат - промис, добавляем обработчик
            if (is_object($result) && method_exists($result, 'then')) {
                return $result->then(
                    function ($value) use ($monitor) {
                        $monitor->stop(['status' => 'success']);
                        return $value;
                    },
                    function ($reason) use ($monitor) {
                        $monitor->stop(['status' => 'error', 'error' => (string)$reason]);
                        throw $reason;
                    }
                );
            }
            
            $monitor->stop(['status' => 'success']);
            return $result;
        } catch (\Throwable $e) {
            $monitor->stop(['status' => 'error', 'error' => $e->getMessage()]);
            throw $e;
        }
    }
} 