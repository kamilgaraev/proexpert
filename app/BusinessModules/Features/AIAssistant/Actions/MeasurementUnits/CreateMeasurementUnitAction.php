<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\MeasurementUnits;

use App\BusinessModules\Features\AIAssistant\Services\WriteAction;
use App\BusinessModules\Features\AIAssistant\Services\ActionResult;
use App\DTOs\MeasurementUnit\MeasurementUnitDTO;
use App\Models\User;
use App\Services\MeasurementUnit\MeasurementUnitService;
use App\Services\Logging\LoggingService;

/**
 * Действие создания единицы измерения через ИИ
 */
class CreateMeasurementUnitAction extends WriteAction
{
    protected string $entity = 'measurement_unit';
    protected string $operation = 'create';

    public function __construct(
        LoggingService $logging,
        private MeasurementUnitService $measurementUnitService
    ) {
        parent::__construct($logging);
    }

    /**
     * Выполнить создание единицы измерения
     */
    public function execute(int $organizationId, array $params, User $user): ActionResult
    {
        // Проверка разрешений
        if (!$this->validatePermissions($user, 'create_measurement_unit')) {
            return $this->error('Недостаточно прав для создания единиц измерения');
        }

        // Валидация входных параметров
        $validation = $this->validateParams($params);
        if (!$validation['valid']) {
            return $this->error($validation['error']);
        }

        return $this->executeInTransaction(function () use ($organizationId, $params, $user) {
            try {
                // Создаем DTO
                $dto = new MeasurementUnitDTO(
                    name: $params['name'],
                    short_name: $params['short_name'],
                    type: $params['type'] ?? 'material',
                    description: $params['description'] ?? null,
                    is_default: $params['is_default'] ?? false
                );

                // Создаем единицу измерения
                $measurementUnit = $this->measurementUnitService->createMeasurementUnit($dto, $organizationId);

                return $this->success([
                    'id' => $measurementUnit->id,
                    'name' => $measurementUnit->name,
                    'short_name' => $measurementUnit->short_name,
                    'type' => $measurementUnit->type,
                    'is_default' => $measurementUnit->is_default,
                ], [
                    'user_id' => $user->id,
                    'organization_id' => $organizationId,
                    'entity_id' => $measurementUnit->id
                ]);

            } catch (\Exception $e) {
                return $this->error("Ошибка создания единицы измерения: " . $e->getMessage());
            }
        });
    }

    /**
     * Валидация входных параметров
     */
    private function validateParams(array $params): array
    {
        if (empty($params['name'])) {
            return ['valid' => false, 'error' => 'Не указано название единицы измерения'];
        }

        if (empty($params['short_name'])) {
            return ['valid' => false, 'error' => 'Не указано сокращенное название единицы измерения'];
        }

        if (strlen($params['name']) > 255) {
            return ['valid' => false, 'error' => 'Название единицы измерения слишком длинное'];
        }

        if (strlen($params['short_name']) > 50) {
            return ['valid' => false, 'error' => 'Сокращенное название слишком длинное'];
        }

        $allowedTypes = ['material', 'work', 'other'];
        if (!empty($params['type']) && !in_array($params['type'], $allowedTypes)) {
            return ['valid' => false, 'error' => 'Неверный тип единицы измерения. Допустимые: material, work, other'];
        }

        return ['valid' => true];
    }
}
