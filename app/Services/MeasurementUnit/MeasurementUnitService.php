<?php

namespace App\Services\MeasurementUnit;

use App\DTOs\MeasurementUnit\MeasurementUnitDTO;
use App\Repositories\Interfaces\MeasurementUnitRepositoryInterface;
use App\Models\MeasurementUnit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Exception;

class MeasurementUnitService
{
    protected MeasurementUnitRepositoryInterface $measurementUnitRepository;

    public function __construct(MeasurementUnitRepositoryInterface $measurementUnitRepository)
    {
        $this->measurementUnitRepository = $measurementUnitRepository;
    }

    public function getAllMeasurementUnits(int $organizationId, int $perPage, array $filters = []): LengthAwarePaginator
    {
        // Добавляем organization_id в массив фильтров, который ожидает базовый репозиторий
        $repositoryFilters = $filters; // Копируем существующие фильтры
        $repositoryFilters['organization_id'] = $organizationId;
        // Также можно добавить фильтр для is_system, если это требуется на уровне сервиса
        // $repositoryFilters['include_system'] = true; // Пример, если репозиторий это поддерживает

        // Передаем фильтры и параметры пагинации в правильном порядке
        return $this->measurementUnitRepository->getAllPaginated(
            filters: $repositoryFilters, 
            perPage: $perPage
            // sortBy и sortDirection можно также передавать из контроллера через $filters или отдельные параметры
        );
    }

    public function getMeasurementUnitById(int $id, int $organizationId): ?MeasurementUnit
    {
        $unit = $this->measurementUnitRepository->findById($id, $organizationId);
        if ($unit && $unit->organization_id !== $organizationId) {
            // Дополнительная проверка, хотя findById должен это учитывать
            return null;
        }
        return $unit;
    }

    public function createMeasurementUnit(MeasurementUnitDTO $dto, int $organizationId): MeasurementUnit
    {
        $data = $dto->toArray();
        $data['organization_id'] = $organizationId;

        // Обработка is_default: если новая запись is_default=true, все остальные для этой организации должны стать false
        if (!empty($data['is_default']) && $data['is_default'] === true) {
            $this->measurementUnitRepository->resetDefaultFlag($organizationId, $data['type'] ?? 'material');
        }

        return $this->measurementUnitRepository->create($data);
    }

    public function updateMeasurementUnit(int $id, MeasurementUnitDTO $dto, int $organizationId): ?MeasurementUnit
    {
        $measurementUnit = $this->getMeasurementUnitById($id, $organizationId);
        if (!$measurementUnit) {
            return null;
        }

        // Не позволяем изменять системные единицы
        if ($measurementUnit->is_system) {
            throw new Exception("System measurement units cannot be modified.");
        }

        $data = array_filter($dto->toArray(), fn($value) => $value !== null);

        // Обработка is_default
        if (isset($data['is_default']) && $data['is_default'] === true) {
            if (!$measurementUnit->is_default) { // Если меняем на true, а раньше было false
                $this->measurementUnitRepository->resetDefaultFlag($organizationId, $measurementUnit->type, $id);
            }
        } else if (isset($data['is_default']) && $data['is_default'] === false) {
            // Если пытаются снять флаг is_default, а он единственный - это нужно предотвратить или выбрать другую по умолчанию
            // Пока просто позволяем, но это место для возможного улучшения логики.
            // Если это была единственная единица по умолчанию, и ее меняют на false, то не будет единицы по умолчанию.
        }

        // Не позволяем менять тип, если есть связанные материалы/работы (или обрабатывать это отдельно)
        if (isset($data['type']) && $data['type'] !== $measurementUnit->type) {
            if ($measurementUnit->materials()->exists() || $measurementUnit->workTypes()->exists()) {
                throw new Exception("Cannot change type of measurement unit that is already in use.");
            }
        }

        $this->measurementUnitRepository->update($id, $data);
        return $this->getMeasurementUnitById($id, $organizationId); // Возвращаем обновленную модель
    }

    public function deleteMeasurementUnit(int $id, int $organizationId): bool
    {
        $measurementUnit = $this->getMeasurementUnitById($id, $organizationId);
        if (!$measurementUnit) {
            return false;
        }

        if ($measurementUnit->is_system) {
            throw new Exception("System measurement units cannot be deleted.");
        }

        // Проверка, используется ли единица измерения
        if ($measurementUnit->materials()->exists() || $measurementUnit->workTypes()->exists()) {
            throw new Exception("Cannot delete measurement unit that is in use.");
        }

        return $this->measurementUnitRepository->delete($id);
    }

    /**
     * Получает единицы измерения, специфичные для материалов, для указанной организации.
     * Это замена для MaterialController@getMeasurementUnits
     */
    public function getMaterialMeasurementUnits(int $organizationId): Collection
    {
        return $this->measurementUnitRepository->getUnitsByType($organizationId, 'material');
    }
} 