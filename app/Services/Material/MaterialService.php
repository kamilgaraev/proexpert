<?php

namespace App\Services\Material;

use App\Repositories\Interfaces\MaterialRepositoryInterface;
use App\Repositories\Interfaces\MeasurementUnitRepositoryInterface;
use App\Repositories\Interfaces\Log\MaterialUsageLogRepositoryInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Collection;
use App\Exceptions\BusinessLogicException;
use Illuminate\Http\Request;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use App\Http\Resources\Api\V1\Admin\MeasurementUnitResource;
use App\Models\Material;

class MaterialService
{
    protected MaterialRepositoryInterface $materialRepository;
    protected MeasurementUnitRepositoryInterface $measurementUnitRepository;
    protected MaterialUsageLogRepositoryInterface $materialUsageLogRepository;

    public function __construct(
        MaterialRepositoryInterface $materialRepository,
        MeasurementUnitRepositoryInterface $measurementUnitRepository,
        MaterialUsageLogRepositoryInterface $materialUsageLogRepository
    ) {
        $this->materialRepository = $materialRepository;
        $this->measurementUnitRepository = $measurementUnitRepository;
        $this->materialUsageLogRepository = $materialUsageLogRepository;
    }

    /**
     * Helper для получения ID организации из запроса.
     */
    protected function getCurrentOrgId(Request $request): int
    {
        /** @var \App\Models\User|null $user */
        $user = $request->user(); 
        $organizationId = $request->attributes->get('current_organization_id');
        if (!$organizationId && $user) {
            $organizationId = $user->current_organization_id;
        }
        if (!$organizationId) {
            Log::error('Failed to determine organization context in MaterialService', ['user_id' => $user?->id, 'request_attributes' => $request->attributes->all()]);
            throw new BusinessLogicException('Контекст организации не определен.', 500);
        }
        return (int)$organizationId;
    }

    public function getAllActive(Request $request): Collection
    {
        $user = $request->user();
        if (!$user || !$user->current_organization_id) {
            // Если getCurrentOrgId не может получить ID из $request (например, $request->user() пуст)
            // то он выбросит BusinessLogicException. Это более последовательно.
            // throw new BusinessLogicException('Не удалось определить организацию пользователя.');
        }
        $organizationId = $this->getCurrentOrgId($request);
        return $this->materialRepository->getActiveMaterials($organizationId);
    }

    public function createMaterial(array $data, Request $request): \App\Models\Material
    {
        $organizationId = $this->getCurrentOrgId($request);
        $data['organization_id'] = $organizationId;

        // Проверяем measurement_unit_id, если репозиторий доступен
        if (isset($data['measurement_unit_id'])) {
            if (!$this->measurementUnitRepository->find($data['measurement_unit_id'])) {
                throw new BusinessLogicException('Указанная единица измерения не найдена', 400);
            }
        }
        // TODO: Возможно, добавить проверку доступности ед.изм для организации

        return $this->materialRepository->create($data);
    }

    public function findMaterialById(int $id, Request $request): ?\App\Models\Material
    {
        $organizationId = $this->getCurrentOrgId($request);
        $material = $this->materialRepository->find($id);
        if (!$material || $material->organization_id !== $organizationId) {
            return null;
        }
        return $material;
    }

    public function updateMaterial(int $id, array $data, Request $request): bool
    {
        // Проверка принадлежности к организации через findMaterialById
        $material = $this->findMaterialById($id, $request);
        if (!$material) {
             throw new BusinessLogicException('Материал не найден или не принадлежит вашей организации.', 404);
        }
        
        // Проверяем measurement_unit_id, если он передан и репозиторий доступен
        if (isset($data['measurement_unit_id'])) {
            if (!$this->measurementUnitRepository->find($data['measurement_unit_id'])) {
                throw new BusinessLogicException('Указанная единица измерения не найдена', 400);
            }
        }

        // Убедимся, что organization_id не меняется
        unset($data['organization_id']);
        
        return $this->materialRepository->update($id, $data);
    }

    public function deleteMaterial(int $id, Request $request): bool
    {
        // Проверка принадлежности к организации через findMaterialById
         $material = $this->findMaterialById($id, $request);
         if (!$material) {
             throw new BusinessLogicException('Материал не найден или не принадлежит вашей организации.', 404);
         }
        // TODO: Проверка, используется ли материал где-либо
        return $this->materialRepository->delete($id);
    }

    /**
     * Получить пагинированный список материалов для текущей организации.
     */
    public function getMaterialsPaginated(Request $request, int $perPage = 15): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $organizationId = $this->getCurrentOrgId($request);
        
        $filters = [
            'name' => $request->query('name'),
            'category' => $request->query('category'),
            'is_active' => $request->query('is_active'), // Принимаем 'true', 'false', '1', '0' или null
        ];
        if (isset($filters['is_active'])) {
            $filters['is_active'] = filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        } else {
             unset($filters['is_active']); 
        }
        $filters = array_filter($filters, fn($value) => !is_null($value) && $value !== '');

        $sortBy = $request->query('sort_by', 'name');
        $sortDirection = $request->query('sort_direction', 'asc');

        // TODO: Валидация sortBy и sortDirection
        $allowedSortBy = ['name', 'category', 'created_at', 'updated_at'];
        if (!in_array(strtolower($sortBy), $allowedSortBy)) {
            $sortBy = 'name';
        }
        if (!in_array(strtolower($sortDirection), ['asc', 'desc'])) {
            $sortDirection = 'asc';
        }

        return $this->materialRepository->getMaterialsForOrganizationPaginated(
            $organizationId,
            $perPage,
            $filters,
            $sortBy,
            $sortDirection
        );
    }

    /**
     * Получить балансы материалов для указанного проекта.
     *
     * @param int $organizationId
     * @param int $projectId
     * @return Collection
     */
    public function getMaterialBalancesForProject(int $organizationId, int $projectId): Collection
    {
        // Получаем все логи прихода и расхода для данного проекта
        // Предполагаем, что в MaterialUsageLogRepository есть метод для получения всех логов по фильтрам
        // или мы используем getPaginatedLogs с очень большим perPage, что не идеально, но для начала.
        // Идеально: $allLogs = $this->materialUsageLogRepository->getAllLogsForProject($projectId, $organizationId);
        
        // Временное решение: используем пагинатор с большим числом записей
        // ВАЖНО: это неэффективно для большого количества логов. Нужен специальный метод в репозитории.
        $logsPaginator = $this->materialUsageLogRepository->getPaginatedLogs(
            $organizationId, 
            100000, // Очень большое число для получения всех записей
            ['project_id' => $projectId],
            'usage_date', 
            'asc'
        );
        $allLogs = collect($logsPaginator->items());

        $balances = [];

        foreach ($allLogs as $log) {
            if (!isset($balances[$log->material_id])) {
                $balances[$log->material_id] = [
                    'material_id' => $log->material_id,
                    'material_name' => $log->material?->name, // Предполагается, что связь material загружена или доступна
                    'measurement_unit_id' => $log->material?->measurementUnit?->id,
                    'measurement_unit_symbol' => $log->material?->measurementUnit?->symbol,
                    'current_balance' => 0,
                ];
            }

            if ($log->operation_type === 'receipt') {
                $balances[$log->material_id]['current_balance'] += $log->quantity;
            } elseif ($log->operation_type === 'write_off') {
                $balances[$log->material_id]['current_balance'] -= $log->quantity;
            }
        }

        // Оставляем только материалы с положительным балансом или все, в зависимости от требований
        // return collect(array_values($balances))->filter(fn($item) => $item['current_balance'] > 0);
        return collect(array_values($balances));
    }

    // ЗАГЛУШКИ ДЛЯ НЕДОСТАЮЩИХ МЕТОДОВ
    public function getMaterialBalancesByMaterial(int $materialId, int $perPage = 15, ?int $projectId = null, string $sortBy = 'created_at', string $sortDirection = 'desc'): array
    {
        Log::warning('Method MaterialService@getMaterialBalancesByMaterial called but not implemented.', ['material_id' => $materialId]);
        return [
            'data' => [],
            'links' => [],
            'meta' => [],
            'message' => 'Functionality to get material balances is not yet implemented.'
        ];
    }

    public function getMeasurementUnits(Request $request): \Illuminate\Http\Resources\Json\AnonymousResourceCollection | array
    {
        try {
            $organizationId = $this->getCurrentOrgId($request);
            $units = $this->measurementUnitRepository->getByOrganization($organizationId);
            
            // Предполагается, что у вас есть или будет ресурс MeasurementUnitResource
            // Если его нет, можно просто вернуть $units->toArray() или $units
            if (class_exists(MeasurementUnitResource::class)) {
                return MeasurementUnitResource::collection($units);
            }
            Log::info('MeasurementUnitResource not found, returning raw collection/array for measurement units.');
            return $units->toArray(); // или return $units; если хотите вернуть коллекцию Eloquent
        } catch (BusinessLogicException $e) { // Сначала ловим BusinessLogicException, если getCurrentOrgId ее бросит
            Log::warning('BusinessLogicException in MaterialService@getMeasurementUnits: ' . $e->getMessage());
            return ['message' => $e->getMessage(), 'success' => false]; 
        } catch (\Throwable $e) {
            Log::error('Error in MaterialService@getMeasurementUnits: ' . $e->getMessage());
            return ['message' => 'Не удалось получить список единиц измерения.', 'success' => false];
        }
    }

    public function importMaterialsFromFile(\Illuminate\Http\UploadedFile $file): array
    {
        Log::warning('Method MaterialService@importMaterialsFromFile called but not implemented.', ['filename' => $file->getClientOriginalName()]);
        // TODO: Реализовать логику импорта материалов из файла
        return [
            'success' => false,
            'message' => 'Functionality to import materials is not yet implemented.',
            'imported_count' => 0,
            'errors_count' => 0,
            'errors_list' => []
        ];
    }
} 