<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\MeasurementUnits;

use App\BusinessModules\Features\AIAssistant\Services\WriteAction;
use App\BusinessModules\Features\AIAssistant\Services\ActionResult;
use App\DTOs\MeasurementUnit\MeasurementUnitDTO;
use App\Models\User;
use App\Services\MeasurementUnit\MeasurementUnitService;
use App\Services\Logging\LoggingService;

/**
 * Действие массового создания единиц измерения через ИИ
 */
class MassCreateMeasurementUnitsAction extends WriteAction
{
    protected string $entity = 'measurement_unit';
    protected string $operation = 'mass_create';

    public function __construct(
        LoggingService $logging,
        private MeasurementUnitService $measurementUnitService
    ) {
        parent::__construct($logging);
    }

    /**
     * Выполнить массовое создание единиц измерения
     */
    public function execute(int $organizationId, array $params, User $user): ActionResult
    {
        // Проверка разрешений
        if (!$this->validatePermissions($user, 'mass_create_measurement_units')) {
            return $this->error('Недостаточно прав для массового создания единиц измерения');
        }

        // Валидация входных параметров
        $validation = $this->validateParams($params);
        if (!$validation['valid']) {
            return $this->error($validation['error']);
        }

        return $this->executeInTransaction(function () use ($organizationId, $params, $user) {
            try {
                $createdUnits = [];
                $errors = [];
                $createdCount = 0;

                foreach ($params['units'] as $index => $unitData) {
                    try {
                        // Создаем DTO для каждой единицы
                        $dto = new MeasurementUnitDTO(
                            name: $unitData['name'],
                            short_name: $unitData['short_name'],
                            type: $unitData['type'] ?? 'material',
                            description: $unitData['description'] ?? null,
                            is_default: $unitData['is_default'] ?? false
                        );

                        // Создаем единицу измерения
                        $measurementUnit = $this->measurementUnitService->createMeasurementUnit($dto, $organizationId);

                        $createdUnits[] = [
                            'index' => $index + 1,
                            'id' => $measurementUnit->id,
                            'name' => $measurementUnit->name,
                            'short_name' => $measurementUnit->short_name,
                            'type' => $measurementUnit->type,
                        ];

                        $createdCount++;

                    } catch (\Exception $e) {
                        $errors[] = [
                            'index' => $index + 1,
                            'name' => $unitData['name'],
                            'error' => $e->getMessage()
                        ];
                    }
                }

                $result = [
                    'total_requested' => count($params['units']),
                    'created_count' => $createdCount,
                    'errors_count' => count($errors),
                    'created_units' => $createdUnits,
                    'errors' => $errors,
                ];

                $metadata = [
                    'user_id' => $user->id,
                    'organization_id' => $organizationId,
                    'total_requested' => count($params['units']),
                    'created_count' => $createdCount,
                    'errors_count' => count($errors)
                ];

                if ($createdCount > 0) {
                    if (count($errors) > 0) {
                        // Частично успешно
                        return ActionResult::success($result, $metadata);
                    } else {
                        // Полностью успешно
                        return $this->success($result, $metadata);
                    }
                } else {
                    // Все операции провалились
                    return $this->error('Не удалось создать ни одной единицы измерения. Ошибки: ' . implode(', ', array_column($errors, 'error')), $metadata);
                }

            } catch (\Exception $e) {
                return $this->error("Ошибка массового создания единиц измерения: " . $e->getMessage());
            }
        });
    }

    /**
     * Валидация входных параметров
     */
    private function validateParams(array $params): array
    {
        if (empty($params['units']) || !is_array($params['units'])) {
            return ['valid' => false, 'error' => 'Не указан список единиц измерения для создания'];
        }

        if (count($params['units']) > 20) {
            return ['valid' => false, 'error' => 'Нельзя создать более 20 единиц измерения за один раз'];
        }

        if (count($params['units']) < 1) {
            return ['valid' => false, 'error' => 'Необходимо указать хотя бы одну единицу измерения'];
        }

        foreach ($params['units'] as $index => $unit) {
            if (empty($unit['name'])) {
                return ['valid' => false, 'error' => "Единица №" . ($index + 1) . ": не указано название"];
            }

            if (empty($unit['short_name'])) {
                return ['valid' => false, 'error' => "Единица №" . ($index + 1) . ": не указано сокращенное название"];
            }

            if (strlen($unit['name']) > 255) {
                return ['valid' => false, 'error' => "Единица №" . ($index + 1) . ": название слишком длинное"];
            }

            if (strlen($unit['short_name']) > 50) {
                return ['valid' => false, 'error' => "Единица №" . ($index + 1) . ": сокращенное название слишком длинное"];
            }

            $allowedTypes = ['material', 'work', 'other'];
            if (!empty($unit['type']) && !in_array($unit['type'], $allowedTypes)) {
                return ['valid' => false, 'error' => "Единица №" . ($index + 1) . ": неверный тип. Допустимые: material, work, other"];
            }
        }

        return ['valid' => true];
    }
}
