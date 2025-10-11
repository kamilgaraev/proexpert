<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\MeasurementUnits;

use App\BusinessModules\Features\AIAssistant\Services\WriteAction;
use App\BusinessModules\Features\AIAssistant\Services\ActionResult;
use App\DTOs\MeasurementUnit\MeasurementUnitDTO;
use App\Models\User;
use App\Services\MeasurementUnit\MeasurementUnitService;
use App\Services\Logging\LoggingService;

/**
 * Действие обновления единицы измерения через ИИ
 */
class UpdateMeasurementUnitAction extends WriteAction
{
    protected string $entity = 'measurement_unit';
    protected string $operation = 'update';

    public function __construct(
        LoggingService $logging,
        private MeasurementUnitService $measurementUnitService
    ) {
        parent::__construct($logging);
    }

    /**
     * Выполнить обновление единицы измерения
     */
    public function execute(int $organizationId, array $params, User $user): ActionResult
    {
        // Проверка разрешений
        if (!$this->validatePermissions($user, 'update_measurement_unit', $params['id'] ?? null)) {
            return $this->error('Недостаточно прав для обновления единиц измерения');
        }

        // Валидация входных параметров
        $validation = $this->validateParams($params);
        if (!$validation['valid']) {
            return $this->error($validation['error']);
        }

        return $this->executeInTransaction(function () use ($organizationId, $params, $user) {
            try {
                // Создаем DTO только с полями для обновления
                $updateData = array_filter([
                    'name' => $params['name'] ?? null,
                    'short_name' => $params['short_name'] ?? null,
                    'type' => $params['type'] ?? null,
                    'description' => $params['description'] ?? null,
                    'is_default' => $params['is_default'] ?? null,
                ], fn($value) => $value !== null);

                if (empty($updateData)) {
                    return $this->error('Не указаны поля для обновления');
                }

                // Создаем DTO
                $dto = new MeasurementUnitDTO(...$updateData);

                // Обновляем единицу измерения
                $measurementUnit = $this->measurementUnitService->updateMeasurementUnit(
                    $params['id'],
                    $dto,
                    $organizationId
                );

                if (!$measurementUnit) {
                    return $this->error('Единица измерения не найдена или не может быть обновлена');
                }

                return $this->success([
                    'id' => $measurementUnit->id,
                    'name' => $measurementUnit->name,
                    'short_name' => $measurementUnit->short_name,
                    'type' => $measurementUnit->type,
                    'description' => $measurementUnit->description,
                    'is_default' => $measurementUnit->is_default,
                ], [
                    'user_id' => $user->id,
                    'organization_id' => $organizationId,
                    'entity_id' => $measurementUnit->id
                ]);

            } catch (\Exception $e) {
                return $this->error("Ошибка обновления единицы измерения: " . $e->getMessage());
            }
        });
    }

    /**
     * Валидация входных параметров
     */
    private function validateParams(array $params): array
    {
        if (empty($params['id']) || !is_numeric($params['id'])) {
            return ['valid' => false, 'error' => 'Не указан ID единицы измерения'];
        }

        $allowedTypes = ['material', 'work', 'other'];
        if (!empty($params['type']) && !in_array($params['type'], $allowedTypes)) {
            return ['valid' => false, 'error' => 'Неверный тип единицы измерения. Допустимые: material, work, other'];
        }

        if (!empty($params['name']) && strlen($params['name']) > 255) {
            return ['valid' => false, 'error' => 'Название единицы измерения слишком длинное'];
        }

        if (!empty($params['short_name']) && strlen($params['short_name']) > 50) {
            return ['valid' => false, 'error' => 'Сокращенное название слишком длинное'];
        }

        return ['valid' => true];
    }
}
