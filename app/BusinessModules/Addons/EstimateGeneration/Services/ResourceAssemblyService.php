<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

class ResourceAssemblyService
{
    public function enrich(array $workItems): array
    {
        foreach ($workItems as &$workItem) {
            $quantity = max((float) $workItem['quantity'], 1);
            $workItem['materials'] = [
                $this->resource($workItem['key'] . '-material-1', 'Основной материал', 'material', $workItem['unit'], $quantity, 1250, 'Шаблон ресурса по виду работ', 0.68),
            ];
            $workItem['labor'] = [
                $this->resource($workItem['key'] . '-labor-1', 'Труд рабочих', 'labor', 'чел-ч', round($quantity * 1.6, 2), 650, 'Норматив времени по шаблону', 0.62),
            ];
            $workItem['machinery'] = [
                $this->resource($workItem['key'] . '-machinery-1', 'Механизация', 'machinery', 'маш-ч', round($quantity * 0.25, 2), 2200, 'Шаблон машинного времени', 0.57),
            ];
        }

        return $workItems;
    }

    protected function resource(string $key, string $name, string $resourceType, string $unit, float $quantity, float $unitPrice, string $source, float $confidence): array
    {
        return [
            'key' => $key,
            'name' => $name,
            'resource_type' => $resourceType,
            'unit' => $unit,
            'quantity' => round($quantity, 2),
            'quantity_basis' => $source,
            'unit_price' => $unitPrice,
            'total_price' => round($quantity * $unitPrice, 2),
            'source' => $source,
            'confidence' => $confidence,
        ];
    }
}
