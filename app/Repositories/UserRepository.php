<?php

namespace App\Repositories;

use App\Models\User;
use App\Repositories\Interfaces\UserRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as PaginationLengthAwarePaginator;
use App\Models\Models\Log\MaterialUsageLog;
use App\Models\CompletedWork;

class UserRepository extends BaseRepository implements UserRepositoryInterface
{
    /**
     * UserRepository constructor.
     */
    public function __construct()
    {
        parent::__construct(User::class);
    }

    // Implementations for methods from the old RepositoryInterface
    public function all(array $columns = ['*']): Collection
    {
        return parent::getAll($columns);
    }

    public function find(int $modelId, array $columns = ['*'], array $relations = [], array $appends = []): ?User
    {
        return parent::find($modelId, $columns, $relations, $appends);
    }

    public function findBy(string $field, mixed $value, array $columns = ['*']): Collection
    {
        return $this->model->where($field, $value)->get($columns);
    }

    // create(array $data) - parent::create(array $payload) has same name, different signature.
    // PHP might not flag as missing abstract IF name matches. Assuming it's not one of the 4.

    // update(int $id, array $data) - parent::update(int $modelId, array $payload) has same name, different signature.
    // Assuming it's not one of the 4.

    public function delete(int $modelId): bool
    {
        return parent::delete($modelId);
    }
    // End of RepositoryInterface methods

    public function findByEmail(string $email): ?User
    {
        return $this->model->where('email', $email)->first();
    }

    public function findWithRoles(int $id): ?User
    {
        return $this->model->with(['roles' => function($query) {
            $query->select('roles.id', 'roles.name', 'roles.slug')
                  ->where('role_user.organization_id', request()->attributes->get('current_organization_id'));
        }])->find($id);
    }

    public function getUsersInOrganization(int $organizationId)
    {
        return $this->model->whereHas('organizations', function ($query) use ($organizationId) {
            $query->where('organization_id', $organizationId);
        })->get();
    }

    /**
     * Привязать пользователя к организации.
     *
     * @param int $userId ID пользователя
     * @param int $organizationId ID организации
     * @param bool $isOwner Установить пользователя как владельца организации
     * @param bool $isActive Установить активность пользователя
     * @return void
     */
    public function attachToOrganization(int $userId, int $organizationId, bool $isOwner = false, bool $isActive = true): void
    {
        $user = $this->model->find($userId);
        if ($user) {
            // Проверяем, есть ли уже связь
            $exists = $user->organizations()->where('organization_user.organization_id', $organizationId)->exists();
            if (!$exists) {
                $user->organizations()->attach($organizationId, [
                    'is_owner' => $isOwner,
                    'is_active' => $isActive
                ]);
                Log::info("[UserRepository] attachToOrganization: User attached to org", [
                    'user_id' => $userId,
                    'organization_id' => $organizationId,
                    'is_owner' => $isOwner,
                    'is_active' => $isActive
                ]);
            } else {
                Log::debug("[UserRepository] attachToOrganization: User already attached to org", [
                    'user_id' => $userId,
                    'organization_id' => $organizationId
                ]);
            }
            // Присваиваем роль владельца (Owner) только если $isOwner = true - новая система авторизации
            if ($isOwner) {
                try {
                    $this->assignRoleToUser($userId, 'organization_owner', $organizationId);
                    Log::info("[UserRepository] Assigned owner role to user (new auth system)", [
                        'user_id' => $userId,
                        'organization_id' => $organizationId,
                        'role_slug' => 'organization_owner'
                    ]);
                } catch (\Exception $e) {
                    Log::error("[UserRepository] Failed to assign owner role: " . $e->getMessage(), [
                        'user_id' => $userId,
                        'organization_id' => $organizationId,
                        'exception' => $e->getMessage()
                    ]);
                }
            }
            // Устанавливаем текущую организацию для пользователя
            $user->current_organization_id = $organizationId;
            $user->save();
        }
    }

    /**
     * @deprecated Используйте assignRoleToUser() с новой системой авторизации
     */
    public function assignRole(int $userId, int $roleId, int $organizationId): void
    {
        Log::warning("[UserRepository] assignRole is deprecated - use assignRoleToUser with new auth system", [
            'user_id' => $userId,
            'role_id' => $roleId,
            'organization_id' => $organizationId
        ]);
        
        // TODO: Пока оставляем для совместимости, но нужно перевести на новую систему
    }

    /**
     * Назначить роль пользователю в новой системе авторизации
     */
    public function assignRoleToUser(int $userId, string $roleSlug, int $organizationId): void
    {
        $user = $this->model->find($userId);
        if (!$user) {
            throw new \Exception("User not found: $userId");
        }

        // Получаем или создаем контекст организации
        $context = AuthorizationContext::getOrganizationContext($organizationId);

        // Проверяем, не назначена ли уже роль
        $existing = UserRoleAssignment::where([
            'user_id' => $userId,
            'role_slug' => $roleSlug,
            'context_id' => $context->id,
            'is_active' => true
        ])->exists();

        if (!$existing) {
            UserRoleAssignment::create([
                'user_id' => $userId,
                'role_slug' => $roleSlug,
                'role_type' => 'system', // Системная роль из JSON
                'context_id' => $context->id,
                'assigned_by' => auth()->id(),
                'is_active' => true
            ]);

            Log::info("[UserRepository] assignRoleToUser: Role assigned (new auth system)", [
                'user_id' => $userId,
                'role_slug' => $roleSlug,
                'organization_id' => $organizationId,
                'context_id' => $context->id
            ]);
        } else {
            Log::debug("[UserRepository] assignRoleToUser: Role already assigned", [
                'user_id' => $userId,
                'role_slug' => $roleSlug,
                'organization_id' => $organizationId
            ]);
        }
    }

    /**
     * Найти пользователей с определенной ролью в организации
     *
     * @param int $organizationId
     * @param string $roleName
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function findByRoleInOrganization(int $organizationId, string $roleSlug): Collection
    {
        return $this->model
            ->whereHas('roles', function ($query) use ($roleSlug, $organizationId) {
                $query->where('slug', $roleSlug)
                      ->where('role_user.organization_id', $organizationId);
            })
            ->get();
    }

    /**
     * Найти пользователей с одной из указанных ролей в организации.
     *
     * @param int $organizationId
     * @param array<string> $roleSlugs Массив слагов ролей
     * @return Collection
     */
    public function findByRolesInOrganization(int $organizationId, array $roleSlugs): Collection
    {
        if (empty($roleSlugs)) {
            return new Collection(); // Возвращаем пустую коллекцию, если массив ролей пуст
        }

        return $this->model
            ->whereHas('roles', function ($query) use ($roleSlugs, $organizationId) {
                $query->whereIn('slug', $roleSlugs) // Используем whereIn для массива слагов
                      ->where('role_user.organization_id', $organizationId);
            })
            ->get();
    }

    /**
     * Отозвать роль у пользователя в рамках организации.
     *
     * @param int $userId ID пользователя.
     * @param int $roleId ID роли.
     * @param int $organizationId ID организации.
     * @return bool True если роль была отозвана, false если связь не найдена.
     */
    public function revokeRole(int $userId, int $roleId, int $organizationId): bool
    {
        $user = $this->model->find($userId);
        if ($user) {
            // В случае detach мы используем специальный синтаксис с условиями
            return $user->roles()->where('role_user.organization_id', $organizationId)->detach($roleId) > 0;
        }
        return false;
    }

    /**
     * Отсоединить пользователя от организации.
     *
     * @param int $userId ID пользователя.
     * @param int $organizationId ID организации.
     * @return bool True если пользователь был отсоединен, false если связь не найдена.
     */
    public function detachFromOrganization(int $userId, int $organizationId): bool
    {
        $user = $this->model->find($userId);
        if ($user) {
            // Удаляем все роли пользователя в этой организации перед откреплением
            $user->roles()->where('role_user.organization_id', $organizationId)->detach();
            // Открепляем от организации
            $detached = $user->organizations()->detach($organizationId) > 0;
            // Если это была текущая организация, сбрасываем ее
            // if ($user->current_organization_id === $organizationId) {
            //     $user->current_organization_id = null;
            //     $user->save();
            // }
            return $detached;
        }
        return false;
    }

    /**
     * Check if a user has a specific role within a specific organization.
     */
    public function hasRoleInOrganization(int $userId, int $roleId, int $organizationId): bool
    {
        $user = $this->model->find($userId);
        if ($user) {
            return $user->roles()
                        ->where('role_user.organization_id', $organizationId)
                        ->where('role_id', $roleId)
                        ->exists();
        }
        return false;
    }

    public function paginateByRoleInOrganization(
        string $roleSlug,
        int $organizationId,
        int $perPage = 15,
        array $filters = [],
        string $sortBy = 'name',
        string $sortDirection = 'asc'
    ): LengthAwarePaginator
    {
        $query = $this->model->query()
            ->whereHas('roles', function ($q) use ($roleSlug, $organizationId) {
                $q->where('slug', $roleSlug);
                $q->where('role_user.organization_id', $organizationId);
            });

        if (!empty($filters['name'])) {
            $query->where('name', 'like', '%' . $filters['name'] . '%');
        }
        if (isset($filters['is_active'])) {
            $isActiveFilter = $filters['is_active'];
            if (is_string($isActiveFilter)) {
                if (strtolower($isActiveFilter) === 'true') $isActiveFilter = true;
                elseif (strtolower($isActiveFilter) === 'false') $isActiveFilter = false;
            }
            if (is_bool($isActiveFilter)) { 
                 $query->where('is_active', $isActiveFilter);
            }
        }
        
        $allowedSortBy = ['id', 'name', 'email', 'created_at', 'is_active']; 
        $tableName = $this->model->getTable();
        $validatedSortBy = in_array($sortBy, $allowedSortBy) ? $sortBy : 'created_at'; 
        $validatedSortDirection = strtolower($sortDirection) === 'desc' ? 'desc' : 'asc';

        $query->orderBy($tableName . '.' . $validatedSortBy, $validatedSortDirection);

        return $query->paginate($perPage);
    }

    /**
     * Получить данные по активности прорабов (из логов).
     */
    public function getForemanActivity(int $organizationId, array $filters = []): Collection
    {
        $query = $this->model->whereHas('roles', function ($roleQuery) use ($organizationId) {
            $roleQuery->where('slug', 'foreman')
                      ->where('role_user.organization_id', $organizationId);
        })->with(['roles']);

        if (!empty($filters['user_id'])) {
            $query->where('id', $filters['user_id']);
        }

        $foremen = $query->get();

        $activityData = collect();

        foreach ($foremen as $foreman) {
            $materialUsageQuery = MaterialUsageLog::where('user_id', $foreman->id);
            $completedWorksQuery = CompletedWork::where('user_id', $foreman->id);

            if (!empty($filters['project_id'])) {
                $materialUsageQuery->where('project_id', $filters['project_id']);
                $completedWorksQuery->where('project_id', $filters['project_id']);
            }

            if (!empty($filters['date_from'])) {
                $materialUsageQuery->whereDate('usage_date', '>=', $filters['date_from']);
                $completedWorksQuery->whereDate('completion_date', '>=', $filters['date_from']);
            }

            if (!empty($filters['date_to'])) {
                $materialUsageQuery->whereDate('usage_date', '<=', $filters['date_to']);
                $completedWorksQuery->whereDate('completion_date', '<=', $filters['date_to']);
            }

            $materialUsageCount = $materialUsageQuery->count();
            $completedWorksCount = $completedWorksQuery->count();
            $completedWorksSum = $completedWorksQuery->sum('total_amount');

            $lastActivity = collect([
                $materialUsageQuery->latest('usage_date')->first()?->usage_date,
                $completedWorksQuery->latest('completion_date')->first()?->completion_date
            ])->filter()->max();

            $activityData->push([
                'user_id' => $foreman->id,
                'user_name' => $foreman->name,
                'user_email' => $foreman->email,
                'material_usage_operations' => $materialUsageCount,
                'completed_works_count' => $completedWorksCount,
                'completed_works_total_sum' => $completedWorksSum ?? 0,
                'last_activity_date' => $lastActivity,
                'is_active' => $foreman->is_active,
            ]);
        }

        return $activityData->sortByDesc('completed_works_total_sum');
    }

    // Добавляем реализацию недостающего метода
    public function findByRoleInOrganizationPaginated(int $organizationId, string $roleSlug, int $perPage = 15): PaginationLengthAwarePaginator
    {
        return $this->model
            ->whereHas('roles', function ($query) use ($roleSlug, $organizationId) {
                $query->where('slug', $roleSlug)
                      ->where('role_user.organization_id', $organizationId);
            })
            ->paginate($perPage);
    }

    /**
     * Получить детальные данные по использованию материалов прорабами.
     */
    public function getForemanMaterialLogs(int $organizationId, array $filters = []): Collection
    {
        $query = MaterialUsageLog::whereHas('user.roles', function ($roleQuery) use ($organizationId) {
            $roleQuery->where('slug', 'foreman')
                      ->where('role_user.organization_id', $organizationId);
        })
        ->with(['project:id,name', 'material:id,name', 'user:id,name']);

        if (!empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (!empty($filters['project_id'])) {
            $query->where('project_id', $filters['project_id']);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('usage_date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('usage_date', '<=', $filters['date_to']);
        }

        return $query->orderBy('usage_date', 'desc')->get()->map(function ($log) {
            return [
                'user_id' => $log->user_id,
                'usage_date' => $log->usage_date,
                'project_name' => $log->project->name ?? '',
                'material_name' => $log->material->name ?? '',
                'quantity' => $log->quantity,
                'operation_type' => $log->operation_type,
                'notes' => $log->notes,
            ];
        });
    }

    /**
     * Получить детальные данные по выполненным работам прорабов.
     */
    public function getForemanCompletedWorks(int $organizationId, array $filters = []): Collection
    {
        $query = CompletedWork::whereHas('user.roles', function ($roleQuery) use ($organizationId) {
            $roleQuery->where('slug', 'foreman')
                      ->where('role_user.organization_id', $organizationId);
        })
        ->with(['project:id,name', 'workType:id,name', 'user:id,name']);

        if (!empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (!empty($filters['project_id'])) {
            $query->where('project_id', $filters['project_id']);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('completion_date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('completion_date', '<=', $filters['date_to']);
        }

        return $query->orderBy('completion_date', 'desc')->get()->map(function ($work) {
            return [
                'user_id' => $work->user_id,
                'completion_date' => $work->completion_date,
                'project_name' => $work->project->name ?? '',
                'work_type_name' => $work->workType->name ?? '',
                'quantity' => $work->quantity,
                'total_amount' => $work->total_amount,
                'status' => $work->status,
            ];
        });
    }
} 