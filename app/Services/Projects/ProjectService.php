<?php

namespace App\Services\Projects;

use App\Models\User;
use App\Repositories\Interfaces\ProjectRepositoryInterface;
use App\Services\LogService;
use App\Services\PerformanceMonitor;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ProjectService
{
    protected ProjectRepositoryInterface $projectRepository;

    /**
     * Конструктор сервиса проектов
     *
     * @param ProjectRepositoryInterface $projectRepository
     */
    public function __construct(ProjectRepositoryInterface $projectRepository)
    {
        $this->projectRepository = $projectRepository;
    }

    /**
     * Получить список проектов для организации с применением прав доступа пользователя
     *
     * @param int $organizationId
     * @param User|null $user
     * @return array
     */
    public function getProjectsForOrganization(int $organizationId, ?User $user = null): array
    {
        return PerformanceMonitor::measure('project_service.get_projects', function() use ($organizationId, $user) {
            try {
                $user = $user ?? Auth::user();

                // Если пользователь не аутентифицирован, возвращаем ошибку
                if (!$user) {
                    return [
                        'success' => false,
                        'message' => 'Пользователь не аутентифицирован',
                        'status_code' => 401
                    ];
                }

                // Для системного админа или администратора организации возвращаем все проекты
                $authService = app(\App\Domain\Authorization\Services\AuthorizationService::class);
                $isAdmin = $authService->hasRole($user, 'system_admin') ||
                          $authService->hasRole($user, 'organization_admin', $organizationId) ||
                          $authService->hasRole($user, 'organization_owner', $organizationId);
                
                if ($isAdmin) {
                    $projects = $this->projectRepository->getProjectsForOrganization($organizationId);
                } else {
                    // Для обычных пользователей возвращаем только назначенные им проекты
                    $projects = $this->projectRepository->getProjectsForUser($user->id, $organizationId);
                }

                return [
                    'success' => true,
                    'projects' => $projects,
                    'status_code' => 200
                ];
            } catch (\Exception $e) {
                LogService::exception($e, [
                    'action' => 'get_projects_for_organization',
                    'organization_id' => $organizationId,
                    'user_id' => $user ? $user->id : null
                ]);

                return [
                    'success' => false,
                    'message' => 'Ошибка при получении проектов',
                    'status_code' => 500
                ];
            }
        });
    }

    /**
     * Получить детальную информацию о проекте
     *
     * @param int $projectId
     * @param array $relations Связи для загрузки
     * @return array
     */
    public function getProjectDetails(int $projectId, array $relations = []): array
    {
        return PerformanceMonitor::measure('project_service.get_project_details', function() use ($projectId, $relations) {
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

                // Загружаем проект с указанными связями
                $defaultRelations = ['users', 'organization'];
                $relations = array_merge($defaultRelations, $relations);
                $project = $this->projectRepository->findWithRelations($projectId, $relations);

                if (!$project) {
                    return [
                        'success' => false,
                        'message' => 'Проект не найден',
                        'status_code' => 404
                    ];
                }

                // Проверяем доступ пользователя к проекту
                $authService = app(\App\Domain\Authorization\Services\AuthorizationService::class);
                $hasAccess = $authService->hasRole($user, 'system_admin') ||
                    $authService->hasRole($user, 'organization_admin', $project->organization_id) ||
                    $authService->hasRole($user, 'organization_owner', $project->organization_id) ||
                    $project->users->contains('id', $user->id);

                if (!$hasAccess) {
                    LogService::authLog('project_access_denied', [
                        'user_id' => $user->id,
                        'project_id' => $project->id,
                        'ip' => request()->ip()
                    ]);

                    return [
                        'success' => false,
                        'message' => 'Нет доступа к проекту',
                        'status_code' => 403
                    ];
                }

                return [
                    'success' => true,
                    'project' => $project,
                    'status_code' => 200
                ];
            } catch (\Exception $e) {
                LogService::exception($e, [
                    'action' => 'get_project_details',
                    'project_id' => $projectId
                ]);

                return [
                    'success' => false,
                    'message' => 'Ошибка при получении информации о проекте',
                    'status_code' => 500
                ];
            }
        });
    }

    /**
     * Создать новый проект
     *
     * @param array $data Данные проекта
     * @param array $userIds ID пользователей для назначения на проект
     * @return array
     */
    public function createProject(array $data, array $userIds = []): array
    {
        return PerformanceMonitor::measure('project_service.create_project', function() use ($data, $userIds) {
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

                // Проверяем, имеет ли пользователь право создавать проекты
                $authService = app(\App\Domain\Authorization\Services\AuthorizationService::class);
                $canCreateProjects = $authService->hasRole($user, 'system_admin') ||
                                   $authService->hasRole($user, 'organization_admin', $organizationId) ||
                                   $authService->hasRole($user, 'organization_owner', $organizationId) ||
                                   $authService->can($user, 'projects.create', ['context_type' => 'organization', 'context_id' => $organizationId]);
                
                if (!$canCreateProjects) {
                    LogService::authLog('project_creation_denied', [
                        'user_id' => $user->id,
                        'organization_id' => $organizationId,
                        'ip' => request()->ip()
                    ]);

                    return [
                        'success' => false,
                        'message' => 'Нет прав на создание проектов',
                        'status_code' => 403
                    ];
                }

                DB::beginTransaction();

                // Создаем проект
                $project = $this->projectRepository->create($data);

                // Назначаем пользователей на проект, если указаны
                if ($project && !empty($userIds)) {
                    $pivotData = [];
                    foreach ($userIds as $userId) {
                        $pivotData[$userId] = ['role' => 'member'];
                    }
                    
                    // Добавляем текущего пользователя как менеджера проекта, если он не в списке
                    if (!in_array($user->id, $userIds)) {
                        $pivotData[$user->id] = ['role' => 'project_manager'];
                    }
                    
                    $project->users()->attach($pivotData);
                }

                DB::commit();

                LogService::info('project_created', [
                    'user_id' => $user->id,
                    'project_id' => $project->id,
                    'organization_id' => $project->organization_id,
                    'ip' => request()->ip()
                ]);

                return [
                    'success' => true,
                    'project' => $project,
                    'status_code' => 201
                ];
            } catch (\Exception $e) {
                DB::rollBack();

                LogService::exception($e, [
                    'action' => 'create_project',
                    'data' => $data
                ]);

                return [
                    'success' => false,
                    'message' => 'Ошибка при создании проекта',
                    'status_code' => 500
                ];
            }
        });
    }

    /**
     * Обновить проект
     *
     * @param int $projectId
     * @param array $data
     * @return array
     */
    public function updateProject(int $projectId, array $data): array
    {
        return PerformanceMonitor::measure('project_service.update_project', function() use ($projectId, $data) {
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

                // Получаем проект
                $project = $this->projectRepository->find($projectId);

                if (!$project) {
                    return [
                        'success' => false,
                        'message' => 'Проект не найден',
                        'status_code' => 404
                    ];
                }

                // Проверяем, имеет ли пользователь право редактировать проект
                $authService = app(\App\Domain\Authorization\Services\AuthorizationService::class);
                $hasAccess = $authService->hasRole($user, 'system_admin') ||
                    $authService->hasRole($user, 'organization_admin', $project->organization_id) ||
                    $authService->hasRole($user, 'organization_owner', $project->organization_id) ||
                    ($project->users->contains('id', $user->id) && 
                     $project->users->where('id', $user->id)->first()->pivot->role === 'project_manager');

                if (!$hasAccess) {
                    LogService::authLog('project_update_denied', [
                        'user_id' => $user->id,
                        'project_id' => $project->id,
                        'ip' => request()->ip()
                    ]);

                    return [
                        'success' => false,
                        'message' => 'Нет прав на редактирование проекта',
                        'status_code' => 403
                    ];
                }

                // Обновляем проект
                $updated = $this->projectRepository->update($projectId, $data);

                if (!$updated) {
                    return [
                        'success' => false,
                        'message' => 'Не удалось обновить проект',
                        'status_code' => 500
                    ];
                }

                // Загружаем обновленный проект
                $project = $this->projectRepository->find($projectId);

                LogService::info('project_updated', [
                    'user_id' => $user->id,
                    'project_id' => $project->id,
                    'ip' => request()->ip()
                ]);

                return [
                    'success' => true,
                    'project' => $project,
                    'status_code' => 200
                ];
            } catch (\Exception $e) {
                LogService::exception($e, [
                    'action' => 'update_project',
                    'project_id' => $projectId,
                    'data' => $data
                ]);

                return [
                    'success' => false,
                    'message' => 'Ошибка при обновлении проекта',
                    'status_code' => 500
                ];
            }
        });
    }

    /**
     * Назначить пользователя на проект с указанной ролью
     *
     * @param int $projectId
     * @param int $userId
     * @param string $role
     * @return array
     */
    public function assignUserToProject(int $projectId, int $userId, string $role = 'member'): array
    {
        return PerformanceMonitor::measure('project_service.assign_user', function() use ($projectId, $userId, $role) {
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

                // Получаем проект
                $project = $this->projectRepository->find($projectId);

                if (!$project) {
                    return [
                        'success' => false,
                        'message' => 'Проект не найден',
                        'status_code' => 404
                    ];
                }

                // Проверяем, имеет ли пользователь право управлять участниками проекта
                $authService = app(\App\Domain\Authorization\Services\AuthorizationService::class);
                $hasAccess = $authService->hasRole($user, 'system_admin') ||
                    $authService->hasRole($user, 'organization_admin', $project->organization_id) ||
                    $authService->hasRole($user, 'organization_owner', $project->organization_id) ||
                    ($project->users->contains('id', $user->id) && 
                     $project->users->where('id', $user->id)->first()->pivot->role === 'project_manager');

                if (!$hasAccess) {
                    LogService::authLog('project_assign_user_denied', [
                        'user_id' => $user->id,
                        'project_id' => $project->id,
                        'assign_user_id' => $userId,
                        'ip' => request()->ip()
                    ]);

                    return [
                        'success' => false,
                        'message' => 'Нет прав на управление участниками проекта',
                        'status_code' => 403
                    ];
                }

                // Добавляем пользователя в проект или обновляем его роль
                $project->users()->syncWithoutDetaching([
                    $userId => ['role' => $role]
                ]);

                LogService::info('user_assigned_to_project', [
                    'user_id' => $user->id,
                    'project_id' => $project->id,
                    'assigned_user_id' => $userId,
                    'role' => $role,
                    'ip' => request()->ip()
                ]);

                return [
                    'success' => true,
                    'message' => 'Пользователь назначен на проект',
                    'status_code' => 200
                ];
            } catch (\Exception $e) {
                LogService::exception($e, [
                    'action' => 'assign_user_to_project',
                    'project_id' => $projectId,
                    'user_id' => $userId,
                    'role' => $role
                ]);

                return [
                    'success' => false,
                    'message' => 'Ошибка при назначении пользователя на проект',
                    'status_code' => 500
                ];
            }
        });
    }
} 