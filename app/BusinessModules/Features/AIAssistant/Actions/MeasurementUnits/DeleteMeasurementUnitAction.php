<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\MeasurementUnits;

use App\BusinessModules\Features\AIAssistant\Services\WriteAction;
use App\BusinessModules\Features\AIAssistant\Services\ActionResult;
use App\Models\User;
use App\Services\MeasurementUnit\MeasurementUnitService;

/**
 * Действие удаления единицы измерения через ИИ
 */
class DeleteMeasurementUnitAction extends WriteAction
{
    protected string $entity = 'measurement_unit';
    protected string $operation = 'delete';

    public function __construct(
        $logging,
        private MeasurementUnitService $measurementUnitService
    ) {
        parent::__construct($logging);
    }

    /**
     * Выполнить удаление единицы измерения
     */
    public function execute(int $organizationId, array $params, User $user): ActionResult
    {
        // Проверка разрешений
        if (!$this->validatePermissions($user, 'delete_measurement_unit', $params['id'] ?? null)) {
            return $this->error('Недостаточно прав для удаления единиц измерения');
        }

        // Валидация входных параметров
        $validation = $this->validateParams($params);
        if (!$validation['valid']) {
            return $this->error($validation['error']);
        }

        return $this->executeInTransaction(function () use ($organizationId, $params, $user) {
            try {
                // Получаем информацию о единице измерения перед удалением
                $measurementUnit = $this->measurementUnitService->getMeasurementUnitById(
                    $params['id'],
                    $organizationId
                );

                if (!$measurementUnit) {
                    return $this->error('Единица измерения не найдена');
                }

                // Сохраняем данные для ответа
                $deletedData = [
                    'id' => $measurementUnit->id,
                    'name' => $measurementUnit->name,
                    'short_name' => $measurementUnit->short_name,
                    'type' => $measurementUnit->type,
                ];

                // Удаляем единицу измерения
                $deleted = $this->measurementUnitService->deleteMeasurementUnit(
                    $params['id'],
                    $organizationId
                );

                if (!$deleted) {
                    return $this->error('Не удалось удалить единицу измерения');
                }

                return $this->success($deletedData, [
                    'user_id' => $user->id,
                    'organization_id' => $organizationId,
                    'entity_id' => $params['id']
                ]);

            } catch (\Exception $e) {
                return $this->error("Ошибка удаления единицы измерения: " . $e->getMessage());
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

        return ['valid' => true];
    }
}
