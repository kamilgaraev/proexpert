<?php

namespace App\Services\WorkTypeMaterial;

use App\Models\WorkType;
use App\Models\Material;
use App\DTOs\WorkTypeMaterial\WorkTypeMaterialDTO;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Exceptions\BusinessLogicException;
use App\Models\User; // Для getCurrentOrgId
use Illuminate\Http\Request; // Для getCurrentOrgId
use Illuminate\Support\Facades\Log; // Для getCurrentOrgId

class WorkTypeMaterialService
{
    // Вспомогательный метод для получения ID текущей организации из запроса
    // Может быть вынесен в BaseService, если используется часто
    public function getCurrentOrgId(Request $request): int
    {
        /** @var User|null $user */
        $user = $request->user();
        $organizationId = $request->attributes->get('current_organization_id');
        if (!$organizationId && $user) {
            $organizationId = $user->current_organization_id;
        }
        
        if (!$organizationId) {
            Log::error('Failed to determine organization context in WorkTypeMaterialService', [
                'user_id' => $user?->id, 
                'request_attributes' => $request->attributes->all()
            ]);
            throw new BusinessLogicException('Контекст организации не определен.', 500);
        }
        return (int)$organizationId;
    }

    /**
     * Получить материалы, привязанные к виду работ, с их нормами.
     */
    public function getMaterialsForWorkType(WorkType $workType): Collection
    {
        // Загружаем материалы с pivot данными (default_quantity, notes)
        return $workType->materials;
    }

    /**
     * Синхронизировать материалы для вида работ.
     * @param WorkType $workType
     * @param WorkTypeMaterialDTO[] $dtos
     */
    public function syncMaterialsForWorkType(WorkType $workType, array $dtos): void
    {
        $organizationId = $workType->organization_id; // Берем из самого WorkType
        $syncData = [];

        foreach ($dtos as $dto) {
            // Проверка, что материал принадлежит той же организации, что и вид работ
            $material = Material::where('id', $dto->material_id)
                                ->where('organization_id', $organizationId)
                                ->first();
            if (!$material) {
                throw new BusinessLogicException("Материал с ID {$dto->material_id} не найден в вашей организации.", 404);
            }

            $syncData[$dto->material_id] = [
                'organization_id' => $organizationId,
                'default_quantity' => $dto->default_quantity,
                'notes' => $dto->notes,
                // work_type_id будет автоматически подставлен Laravel при sync
            ];
        }

        // Используем sync для обновления связей
        // Старые связи, не попавшие в $syncData, будут удалены.
        // Новые будут добавлены, существующие обновлены.
        $workType->materials()->sync($syncData);
    }

    /**
     * Отвязать материал от вида работ.
     */
    public function removeMaterialFromWorkType(WorkType $workType, Material $material): bool
    {
        // Проверка принадлежности материала и вида работ одной организации уже должна быть в контроллере.
        // detach вернет количество отвязанных записей.
        return $workType->materials()->detach($material->id) > 0;
    }

    /**
     * Предложить материалы для заданного вида работ и их количества.
     *
     * @param int $workTypeId
     * @param float $workTypeQuantity Объем выполняемых работ (в единицах измерения workType)
     * @param int $organizationId
     * @return Collection Коллекция объектов/массивов ['material' => Material, 'suggested_quantity' => float, 'unit' => string]
     */
    public function getSuggestedMaterials(int $workTypeId, float $workTypeQuantity, int $organizationId): Collection
    {
        $workType = WorkType::with(['materials' => function ($query) use ($organizationId) {
                $query->where('work_type_materials.organization_id', $organizationId); // Убедимся, что норма из той же организации
            }, 'materials.measurementUnit'])
            ->where('organization_id', $organizationId)
            ->find($workTypeId);

        if (!$workType) {
            throw new BusinessLogicException('Вид работ не найден.', 404);
        }

        $suggestedMaterials = new Collection();

        foreach ($workType->materials as $material) {
            /** @var \App\Models\WorkTypeMaterial $pivot */
            $pivot = $material->pivot;
            $calculatedQuantity = $pivot->default_quantity * $workTypeQuantity;
            
            $suggestedMaterials->push([
                'material_id' => $material->id,
                'material_name' => $material->name,
                'material_code' => $material->code,
                'suggested_quantity' => round($calculatedQuantity, 4), // Округляем
                'measurement_unit_short_name' => $material->measurementUnit?->short_name ?? 'шт.', // Единица измерения самого материала
                'default_norm_per_unit' => (float)$pivot->default_quantity,
                'norm_notes' => $pivot->notes,
            ]);
        }

        return $suggestedMaterials;
    }
} 