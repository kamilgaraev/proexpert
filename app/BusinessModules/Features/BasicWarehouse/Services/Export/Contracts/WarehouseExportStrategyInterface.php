<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services\Export\Contracts;

/**
 * Интерфейс для стратегий экспорта складских документов
 */
interface WarehouseExportStrategyInterface
{
    /**
     * Экспорт документа
     * 
     * @param mixed $model Модель для экспорта (WarehouseMovement, InventoryAct и т.д.)
     * @return string Путь к файлу на S3
     */
    public function export($model): string;

    /**
     * Возвращает поддерживаемый тип документа
     */
    public function getSupportedType(): string;
}
