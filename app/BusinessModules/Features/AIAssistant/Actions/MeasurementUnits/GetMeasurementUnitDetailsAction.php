<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\MeasurementUnits;

use App\Services\MeasurementUnit\MeasurementUnitService;

/**
 * Действие получения детальной информации о единице измерения через ИИ
 */
class GetMeasurementUnitDetailsAction
{
    public function __construct(
        private MeasurementUnitService $measurementUnitService
    ) {}

    /**
     * Выполнить получение деталей единицы измерения
     */
    public function execute(int $organizationId, ?array $params = []): ?array
    {
        if (empty($params['id'])) {
            return null;
        }

        $measurementUnit = $this->measurementUnitService->getMeasurementUnitById($params['id'], $organizationId);

        if (!$measurementUnit) {
            return null;
        }

        return [
            'id' => $measurementUnit->id,
            'name' => $measurementUnit->name,
            'short_name' => $measurementUnit->short_name,
            'type' => $measurementUnit->type,
            'description' => $measurementUnit->description,
            'is_default' => $measurementUnit->is_default,
            'is_system' => $measurementUnit->is_system,
            'created_at' => $measurementUnit->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $measurementUnit->updated_at?->format('Y-m-d H:i:s'),
            // Статистика использования
            'materials_count' => $measurementUnit->materials()->count(),
            'work_types_count' => $measurementUnit->workTypes()->count(),
        ];
    }
}
