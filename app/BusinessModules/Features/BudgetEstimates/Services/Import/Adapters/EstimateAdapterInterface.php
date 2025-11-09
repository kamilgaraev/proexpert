<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Adapters;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportDTO;

/**
 * Интерфейс адаптера для обработки конкретного типа сметы
 * 
 * Каждый адаптер содержит специфичную логику для своего типа:
 * - ГрандСмета: парсинг ОТ/ЭМ/М, базисный/текущий уровень
 * - РИК: индексы СМР, ресурсная часть
 * - ФЕР/ГЭСН: расценки, нормативы
 * - Произвольная: гибкий маппинг без кодов
 */
interface EstimateAdapterInterface
{
    /**
     * Проверить, поддерживает ли адаптер данный тип сметы
     * 
     * @param string $estimateType Тип сметы: 'grandsmeta', 'rik', 'fer', 'custom'
     * @return bool
     */
    public function supports(string $estimateType): bool;
    
    /**
     * Адаптировать данные импорта под специфику типа сметы
     * 
     * Преобразует общий EstimateImportDTO в формат,
     * оптимизированный для данного типа сметы
     * 
     * @param EstimateImportDTO $dto Общие данные импорта
     * @param array $metadata Дополнительные метаданные (заголовки, структура и т.д.)
     * @return EstimateImportDTO Адаптированные данные
     */
    public function adapt(EstimateImportDTO $dto, array $metadata): EstimateImportDTO;
    
    /**
     * Получить список специфичных полей для этого типа сметы
     * 
     * @return array Массив дополнительных полей, которые адаптер может обработать
     */
    public function getSpecificFields(): array;
}

