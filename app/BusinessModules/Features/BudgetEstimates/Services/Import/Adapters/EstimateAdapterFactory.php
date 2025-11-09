<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Adapters;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\Adapters\GrandSmetaAdapter;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Adapters\RIKAdapter;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Adapters\FERAdapter;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Adapters\SmartSmetaAdapter;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Adapters\UniversalAdapter;
use Illuminate\Support\Facades\Log;

/**
 * Фабрика адаптеров для разных типов смет
 * 
 * Выбирает подходящий адаптер на основе типа сметы
 */
class EstimateAdapterFactory
{
    /**
     * @var EstimateAdapterInterface[]
     */
    private array $adapters = [];
    
    public function __construct()
    {
        // Регистрируем все доступные адаптеры
        $this->adapters = [
            new GrandSmetaAdapter(),
            new RIKAdapter(),
            new FERAdapter(),
            new SmartSmetaAdapter(),
            new UniversalAdapter(), // Fallback - последний
        ];
    }
    
    /**
     * Создать адаптер для указанного типа сметы
     * 
     * @param string $estimateType Тип сметы: 'grandsmeta', 'rik', 'fer', 'smartsmeta', 'custom'
     * @return EstimateAdapterInterface
     */
    public function create(string $estimateType): EstimateAdapterInterface
    {
        Log::info('[EstimateAdapterFactory] Creating adapter', [
            'type' => $estimateType,
        ]);
        
        // Ищем подходящий адаптер
        foreach ($this->adapters as $adapter) {
            if ($adapter->supports($estimateType)) {
                Log::info('[EstimateAdapterFactory] Adapter found', [
                    'type' => $estimateType,
                    'adapter' => get_class($adapter),
                ]);
                
                return $adapter;
            }
        }
        
        // Fallback на UniversalAdapter
        Log::warning('[EstimateAdapterFactory] No specific adapter found, using UniversalAdapter', [
            'type' => $estimateType,
        ]);
        
        return new UniversalAdapter();
    }
    
    /**
     * Получить список всех зарегистрированных адаптеров
     * 
     * @return EstimateAdapterInterface[]
     */
    public function getAllAdapters(): array
    {
        return $this->adapters;
    }
    
    /**
     * Получить список поддерживаемых типов смет
     * 
     * @return array
     */
    public function getSupportedTypes(): array
    {
        return [
            'grandsmeta',
            'rik',
            'fer',
            'smartsmeta',
            'custom',
        ];
    }
}

