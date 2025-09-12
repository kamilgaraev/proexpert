<?php

namespace App\Services\Materials;

use App\Repositories\Interfaces\MaterialRepositoryInterface;
use App\Repositories\Interfaces\MeasurementUnitRepositoryInterface;
use App\Services\LogService;
use App\Services\PerformanceMonitor;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class MaterialService
{
    protected MaterialRepositoryInterface $materialRepository;
    protected ?MeasurementUnitRepositoryInterface $measurementUnitRepository;

    /**
     * Конструктор сервиса материалов
     *
     * @param MaterialRepositoryInterface $materialRepository
     * @param MeasurementUnitRepositoryInterface|null $measurementUnitRepository
     */
    public function __construct(
        MaterialRepositoryInterface $materialRepository,
        ?MeasurementUnitRepositoryInterface $measurementUnitRepository = null
    ) {
        $this->materialRepository = $materialRepository;
        $this->measurementUnitRepository = $measurementUnitRepository;
    }

    /**
     * Получить список материалов для организации
     *
     * @param int $organizationId
     * @param bool $onlyActive Только активные материалы
     * @return array
     */
    public function getMaterialsForOrganization(int $organizationId, bool $onlyActive = false): array
    {
        return PerformanceMonitor::measure('material_service.get_materials', function() use ($organizationId, $onlyActive) {
            try {
                $user = Auth::user();

                // Если пользователь не аутентифицирован, возвращаем ошибку
                if (!$user) {
                    return [
                        'success' => false,
                        'message' => 'Пользователь не аутентифицирован',
                        'status_code' => 401
                    ];
                }

                // Проверяем права доступа к материалам организации (новая система авторизации)
                if (!$user->hasPermission('materials.view', ['organization_id' => $organizationId])) {
                    
                    LogService::authLog('materials_access_denied', [
                        'user_id' => $user->id,
                        'organization_id' => $organizationId,
                        'ip' => request()->ip()
                    ]);
                    
                    return [
                        'success' => false,
                        'message' => 'Нет доступа к материалам организации',
                        'status_code' => 403
                    ];
                }

                // Получаем материалы
                $materials = $onlyActive ? 
                    $this->materialRepository->getActiveMaterials($organizationId) : 
                    $this->materialRepository->getMaterialsForOrganization($organizationId);

                return [
                    'success' => true,
                    'materials' => $materials,
                    'status_code' => 200
                ];
            } catch (\Exception $e) {
                LogService::exception($e, [
                    'action' => 'get_materials_for_organization',
                    'organization_id' => $organizationId,
                    'only_active' => $onlyActive
                ]);

                return [
                    'success' => false,
                    'message' => 'Ошибка при получении материалов',
                    'status_code' => 500
                ];
            }
        });
    }

    /**
     * Получить материалы по категории
     *
     * @param int $organizationId
     * @param string $category
     * @return array
     */
    public function getMaterialsByCategory(int $organizationId, string $category): array
    {
        return PerformanceMonitor::measure('material_service.get_materials_by_category', function() use ($organizationId, $category) {
            try {
                $user = Auth::user();

                // Если пользователь не аутентифицирован, возвращаем ошибку
                if (!$user) {
                    return [
                        'success' => false,
                        'message' => 'Пользователь не аутентифицирован',
                        'status_code' => 401
                    ];
                }

                // Проверяем права доступа к материалам организации (новая система авторизации)
                if (!$user->hasPermission('materials.view', ['organization_id' => $organizationId])) {
                    
                    LogService::authLog('materials_access_denied', [
                        'user_id' => $user->id,
                        'organization_id' => $organizationId,
                        'category' => $category,
                        'ip' => request()->ip()
                    ]);
                    
                    return [
                        'success' => false,
                        'message' => 'Нет доступа к материалам организации',
                        'status_code' => 403
                    ];
                }

                // Получаем материалы по категории
                $materials = $this->materialRepository->getMaterialsByCategory($organizationId, $category);

                return [
                    'success' => true,
                    'materials' => $materials,
                    'status_code' => 200
                ];
            } catch (\Exception $e) {
                LogService::exception($e, [
                    'action' => 'get_materials_by_category',
                    'organization_id' => $organizationId,
                    'category' => $category
                ]);

                return [
                    'success' => false,
                    'message' => 'Ошибка при получении материалов',
                    'status_code' => 500
                ];
            }
        });
    }

    /**
     * Получить детальную информацию о материале
     *
     * @param int $materialId
     * @param array $relations Связи для загрузки
     * @return array
     */
    public function getMaterialDetails(int $materialId, array $relations = []): array
    {
        return PerformanceMonitor::measure('material_service.get_material_details', function() use ($materialId, $relations) {
            try {
                $user = Auth::user();

                // Если пользователь не аутентифицирован, возвращаем ошибку
                if (!$user) {
                    return [
                        'success' => false,
                        'message' => 'Пользователь не аутентифицирован',
                        'status_code' => 401
                    ];
                }

                // Загружаем материал с указанными связями
                $defaultRelations = ['measurementUnit', 'organization'];
                $relations = array_merge($defaultRelations, $relations);
                $material = $this->materialRepository->findWithRelations($materialId, $relations);

                if (!$material) {
                    return [
                        'success' => false,
                        'message' => 'Материал не найден',
                        'status_code' => 404
                    ];
                }

                // Проверяем доступ пользователя к материалу (новая система авторизации)
                $hasAccess = $user->hasPermission('materials.view', ['organization_id' => $material->organization_id]);

                if (!$hasAccess) {
                    LogService::authLog('material_access_denied', [
                        'user_id' => $user->id,
                        'material_id' => $material->id,
                        'organization_id' => $material->organization_id,
                        'ip' => request()->ip()
                    ]);

                    return [
                        'success' => false,
                        'message' => 'Нет доступа к материалу',
                        'status_code' => 403
                    ];
                }

                return [
                    'success' => true,
                    'material' => $material,
                    'status_code' => 200
                ];
            } catch (\Exception $e) {
                LogService::exception($e, [
                    'action' => 'get_material_details',
                    'material_id' => $materialId
                ]);

                return [
                    'success' => false,
                    'message' => 'Ошибка при получении информации о материале',
                    'status_code' => 500
                ];
            }
        });
    }

    /**
     * Создать новый материал
     *
     * @param array $data Данные материала
     * @return array
     */
    public function createMaterial(array $data): array
    {
        return PerformanceMonitor::measure('material_service.create_material', function() use ($data) {
            try {
                $user = Auth::user();

                // Если пользователь не аутентифицирован, возвращаем ошибку
                if (!$user) {
                    return [
                        'success' => false,
                        'message' => 'Пользователь не аутентифицирован',
                        'status_code' => 401
                    ];
                }

                $organizationId = $data['organization_id'] ?? null;

                // Проверяем, имеет ли пользователь право создавать материалы (новая система авторизации)
                if (!$user->hasPermission('materials.create', ['organization_id' => $organizationId])) {
                    
                    LogService::authLog('material_creation_denied', [
                        'user_id' => $user->id,
                        'organization_id' => $organizationId,
                        'ip' => request()->ip()
                    ]);
                    
                    return [
                        'success' => false,
                        'message' => 'Нет прав на создание материалов',
                        'status_code' => 403
                    ];
                }

                // Проверяем существование единицы измерения, если указана
                if (isset($data['measurement_unit_id']) && $this->measurementUnitRepository) {
                    $measurementUnit = $this->measurementUnitRepository->find($data['measurement_unit_id']);
                    if (!$measurementUnit) {
                        return [
                            'success' => false,
                            'message' => 'Указанная единица измерения не найдена',
                            'status_code' => 400
                        ];
                    }
                }

                // Создаем материал
                $material = $this->materialRepository->create($data);

                if (!$material) {
                    return [
                        'success' => false,
                        'message' => 'Не удалось создать материал',
                        'status_code' => 500
                    ];
                }

                LogService::info('material_created', [
                    'user_id' => $user->id,
                    'material_id' => $material->id,
                    'organization_id' => $material->organization_id,
                    'ip' => request()->ip()
                ]);

                return [
                    'success' => true,
                    'material' => $material,
                    'status_code' => 201
                ];
            } catch (\Exception $e) {
                LogService::exception($e, [
                    'action' => 'create_material',
                    'data' => $data
                ]);

                return [
                    'success' => false,
                    'message' => 'Ошибка при создании материала',
                    'status_code' => 500
                ];
            }
        });
    }

    /**
     * Обновить материал
     *
     * @param int $materialId
     * @param array $data
     * @return array
     */
    public function updateMaterial(int $materialId, array $data): array
    {
        return PerformanceMonitor::measure('material_service.update_material', function() use ($materialId, $data) {
            try {
                $user = Auth::user();

                // Если пользователь не аутентифицирован, возвращаем ошибку
                if (!$user) {
                    return [
                        'success' => false,
                        'message' => 'Пользователь не аутентифицирован',
                        'status_code' => 401
                    ];
                }

                // Получаем материал
                $material = $this->materialRepository->find($materialId);

                if (!$material) {
                    return [
                        'success' => false,
                        'message' => 'Материал не найден',
                        'status_code' => 404
                    ];
                }

                // Проверяем, имеет ли пользователь право редактировать материал (новая система авторизации)
                $hasAccess = $user->hasPermission('materials.edit', ['organization_id' => $material->organization_id]);

                if (!$hasAccess) {
                    LogService::authLog('material_update_denied', [
                        'user_id' => $user->id,
                        'material_id' => $material->id,
                        'organization_id' => $material->organization_id,
                        'ip' => request()->ip()
                    ]);

                    return [
                        'success' => false,
                        'message' => 'Нет прав на редактирование материала',
                        'status_code' => 403
                    ];
                }

                // Проверяем существование единицы измерения, если изменена
                if (isset($data['measurement_unit_id']) && $this->measurementUnitRepository) {
                    $measurementUnit = $this->measurementUnitRepository->find($data['measurement_unit_id']);
                    if (!$measurementUnit) {
                        return [
                            'success' => false,
                            'message' => 'Указанная единица измерения не найдена',
                            'status_code' => 400
                        ];
                    }
                }

                // Обновляем материал
                $updated = $this->materialRepository->update($materialId, $data);

                if (!$updated) {
                    return [
                        'success' => false,
                        'message' => 'Не удалось обновить материал',
                        'status_code' => 500
                    ];
                }

                // Загружаем обновленный материал
                $material = $this->materialRepository->find($materialId);

                LogService::info('material_updated', [
                    'user_id' => $user->id,
                    'material_id' => $material->id,
                    'organization_id' => $material->organization_id,
                    'ip' => request()->ip()
                ]);

                return [
                    'success' => true,
                    'material' => $material,
                    'status_code' => 200
                ];
            } catch (\Exception $e) {
                LogService::exception($e, [
                    'action' => 'update_material',
                    'material_id' => $materialId,
                    'data' => $data
                ]);

                return [
                    'success' => false,
                    'message' => 'Ошибка при обновлении материала',
                    'status_code' => 500
                ];
            }
        });
    }
} 