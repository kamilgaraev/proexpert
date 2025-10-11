<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\MeasurementUnits;

use App\Services\MeasurementUnit\MeasurementUnitService;

/**
 * Действие получения списка единиц измерения через ИИ
 */
class GetMeasurementUnitsAction
{
    public function __construct(
        private MeasurementUnitService $measurementUnitService
    ) {}

    /**
     * Выполнить получение списка единиц измерения
     */
    public function execute(int $organizationId, ?array $params = []): array
    {
        $perPage = $params['per_page'] ?? 20;
        $filters = [];

        // Фильтр по типу если указан
        if (!empty($params['type'])) {
            $filters['type'] = $params['type'];
        }

        $measurementUnits = $this->measurementUnitService->getAllMeasurementUnits($organizationId, $perPage, $filters);

        $units = $measurementUnits->map(function ($unit) {
            return [
                'id' => $unit->id,
                'name' => $unit->name,
                'short_name' => $unit->short_name, // Внутреннее поле модели
                'code' => $unit->short_name, // Для совместимости с API
                'type' => $unit->type,
                'description' => $unit->description,
                'is_default' => $unit->is_default,
                'is_system' => $unit->is_system,
            ];
        })->toArray();

        return [
            'units' => $units,
            'total' => $measurementUnits->total(),
            'current_page' => $measurementUnits->currentPage(),
            'per_page' => $measurementUnits->perPage(),
            'last_page' => $measurementUnits->lastPage(),
        ];
    }
}
