<?php

namespace App\BusinessModules\Features\BasicWarehouse\Contracts;

/**
 * Интерфейс для предоставления данных склада в модули отчетов
 * 
 * Складские модули (BasicWarehouse, AdvancedWarehouse) предоставляют данные,
 * а модули отчетов (BasicReports, AdvancedReports) форматируют и экспортируют их
 */
interface WarehouseReportDataProvider
{
    /**
     * Получить данные по остаткам на складе
     * 
     * @param int $organizationId ID организации
     * @param array $filters Фильтры для выборки
     * @return array Массив данных об остатках
     */
    public function getStockData(int $organizationId, array $filters = []): array;

    /**
     * Получить данные по движению активов
     * 
     * @param int $organizationId ID организации
     * @param array $filters Фильтры для выборки (date_from, date_to, asset_type, warehouse_id)
     * @return array Массив данных о движениях
     */
    public function getMovementsData(int $organizationId, array $filters = []): array;

    /**
     * Получить данные инвентаризации
     * 
     * @param int $organizationId ID организации
     * @param array $filters Фильтры для выборки (date_from, date_to, warehouse_id, status)
     * @return array Массив данных инвентаризации
     */
    public function getInventoryData(int $organizationId, array $filters = []): array;

    /**
     * Получить данные аналитики оборачиваемости (только для AdvancedWarehouse)
     * 
     * @param int $organizationId ID организации
     * @param array $filters Фильтры для выборки
     * @return array Массив данных аналитики
     */
    public function getTurnoverAnalytics(int $organizationId, array $filters = []): array;

    /**
     * Получить прогноз потребности в материалах (только для AdvancedWarehouse)
     * 
     * @param int $organizationId ID организации
     * @param array $filters Фильтры для выборки (horizon_days, asset_ids)
     * @return array Массив данных прогноза
     */
    public function getForecastData(int $organizationId, array $filters = []): array;

    /**
     * Получить ABC/XYZ анализ запасов (только для AdvancedWarehouse)
     * 
     * @param int $organizationId ID организации
     * @param array $filters Фильтры для выборки
     * @return array Массив данных ABC/XYZ анализа
     */
    public function getAbcXyzAnalysis(int $organizationId, array $filters = []): array;
}

