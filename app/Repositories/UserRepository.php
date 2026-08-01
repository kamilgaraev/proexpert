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
use Illuminate\Support\Str;

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
        return $this->model
            ->whereRaw('LOWER(email) = ?', [Str::lower(trim($email))])
            ->first();
    }

    public function findWithRoles(int $id): ?User
    {
        // Используем новую систему авторизации
        return $this->model->with(['roleAssignments' => function($query) {
            $organizationId = request()->attributes->get('current_organization_id');
            if ($organizationId) {
                $query->whereHas('context', function($contextQuery) use ($organizationId) {
                    $contextQuery->where('type', 'organization')
                                 ->where('resource_id', $organizationId);
                })->where('is_active', true);
            }
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

        try {
            // Получаем или создаем контекст организации
            $context = AuthorizationContext::getOrganizationContext($organizationId);

            // Используем updateOrCreate для атомарного создания или обновления роли
            $roleAssignment = UserRoleAssignment::updateOrCreate(
                [
                    'user_id' => $userId,
                    'role_slug' => $roleSlug,
                    'context_id' => $context->id,
                ],
                [
                    'role_type' => 'system', // Системная роль из JSON
                    'assigned_by' => auth()->id(),
                    'is_active' => true,
                    'expires_at' => null, // Сбрасываем срок действия при повторном назначении
                ]
            );

            if ($roleAssignment->wasRecentlyCreated) {
                Log::info("[UserRepository] assignRoleToUser: Role assigned (new auth system)", [
                    'user_id' => $userId,
                    'role_slug' => $roleSlug,
                    'organization_id' => $organizationId,
                    'context_id' => $context->id
                ]);
            } else {
                Log::info("[UserRepository] assignRoleToUser: Role reactivated (new auth system)", [
                    'user_id' => $userId,
                    'role_slug' => $roleSlug,
                    'organization_id' => $organizationId,
                    'context_id' => $context->id,
                    'was_inactive' => !$roleAssignment->getOriginal('is_active')
                ]);
            }
        } catch (\Exception $e) {
            // Таблицы новой системы авторизации еще не созданы - это нормально
            if (str_contains($e->getMessage(), 'does not exist') || str_contains($e->getMessage(), 'Undefined table')) {
                Log::info("[UserRepository] assignRoleToUser: New auth tables not ready, skipping role assignment", [
                    'user_id' => $userId,
                    'role_slug' => $roleSlug,
                    'organization_id' => $organizationId,
                    'error' => 'Auth tables not created yet'
                ]);
                return;
            }
            
            // Любая другая ошибка - пробрасываем дальше
            throw $e;
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
        // Получаем контекст организации
        $context = AuthorizationContext::getOrganizationContext($organizationId);
        
        return $this->model
            ->whereHas('roleAssignments', function ($query) use ($roleSlug, $context) {
                $query->where('role_slug', $roleSlug)
                      ->where('context_id', $context->id)
                      ->where('is_active', true);
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

        // Получаем контекст организации
        $context = AuthorizationContext::getOrganizationContext($organizationId);

        return $this->model
            ->whereHas('roleAssignments', function ($query) use ($roleSlugs, $context) {
                $query->whereIn('role_slug', $roleSlugs) // Используем whereIn для массива слагов
                      ->where('context_id', $context->id)
                      ->where('is_active', true);
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
            // Отзываем роль в новой системе авторизации
            $context = AuthorizationContext::getOrganizationContext($organizationId);
            
            $updated = UserRoleAssignment::where([
                'user_id' => $userId,
                'context_id' => $context->id,
                'is_active' => true
            ])->update(['is_active' => false]);
            
            Log::info("[UserRepository] revokeRole completed (new auth system)", [
                'user_id' => $user->id, 'organization_id' => $organizationId, 'updated' => $updated
            ]);
            
            return $updated > 0;
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
            // Отключаем все роли пользователя в этой организации
            $context = AuthorizationContext::getOrganizationContext($organizationId);
            
            $disabledRoles = UserRoleAssignment::where([
                'user_id' => $userId,
                'context_id' => $context->id,
                'is_active' => true
            ])->update(['is_active' => false]);
            
            Log::info("[UserRepository] detachFromOrganization - roles disabled (new auth system)", [
                'user_id' => $userId, 'organization_id' => $organizationId, 'disabled_roles' => $disabledRoles
            ]);
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
            // Проверяем роль в новой системе авторизации
            $context = AuthorizationContext::getOrganizationContext($organizationId);
            
            // Поскольку у нас теперь роли по slug, а не по ID, нужна другая логика
            // Этот метод устарел и не должен использоваться с новой системой
            Log::info("[UserRepository] hasRoleInOrganization called with role_id - method deprecated", [
                'user_id' => $userId, 'role_id' => $roleId, 'organization_id' => $organizationId
            ]);
            return false; // Устаревший метод не поддерживается
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
        // Получаем контекст организации
        $context = AuthorizationContext::getOrganizationContext($organizationId);
        
        $query = $this->model->query()
            ->whereHas('roleAssignments', function ($q) use ($roleSlug, $context) {
                $q->where('role_slug', $roleSlug);
                $q->where('context_id', $context->id);
                $q->where('is_active', true);
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

    public function paginateInOrganization(
        int $organizationId,
        int $perPage = 15,
        array $filters = [],
        string $sortBy = 'name',
        string $sortDirection = 'asc'
    ): LengthAwarePaginator
    {
        $context = AuthorizationContext::getOrganizationContext($organizationId);

        $query = $this->model->query()
            ->whereHas('organizations', function ($q) use ($organizationId) {
                $q->where('organization_user.organization_id', $organizationId);
            })
            ->with([
                'organizations',
                'assignedProjects' => function ($q) {
                    $q->wherePivot('is_active', true);
                },
                'roleAssignments' => function ($q) use ($context) {
                    $q->where('context_id', $context->id)
                        ->where('is_active', true);
                },
            ]);

        if (!empty($filters['name'])) {
            $query->where('name', 'like', '%' . $filters['name'] . '%');
        }

        if (!empty($filters['email'])) {
            $query->where('email', 'like', '%' . $filters['email'] . '%');
        }

        if (isset($filters['is_active'])) {
            $isActiveFilter = $filters['is_active'];
            if (is_string($isActiveFilter)) {
                if (strtolower($isActiveFilter) === 'true') {
                    $isActiveFilter = true;
                } elseif (strtolower($isActiveFilter) === 'false') {
                    $isActiveFilter = false;
                }
            }

            if (is_bool($isActiveFilter)) {
                $query->where('is_active', $isActiveFilter);
            }
        }

        if (!empty($filters['role'])) {
            $query->whereHas('roleAssignments', function ($q) use ($filters, $context) {
                $q->where('role_slug', $filters['role'])
                    ->where('context_id', $context->id)
                    ->where('is_active', true);
            });
        }

        $allowedSortBy = ['id', 'name', 'email', 'created_at', 'is_active'];
        $tableName = $this->model->getTable();
        $validatedSortBy = in_array($sortBy, $allowedSortBy, true) ? $sortBy : 'created_at';
        $validatedSortDirection = strtolower($sortDirection) === 'desc' ? 'desc' : 'asc';

        return $query
            ->orderBy($tableName . '.' . $validatedSortBy, $validatedSortDirection)
            ->paginate($perPage);
    }

    public function paginateOptionsInOrganization(
        int $organizationId,
        int $perPage = 100,
        array $filters = [],
        string $sortBy = 'name',
        string $sortDirection = 'asc'
    ): LengthAwarePaginator
    {
        $context = AuthorizationContext::getOrganizationContext($organizationId);

        $query = $this->model->query()
            ->select([
                'users.id',
                'users.name',
                'users.email',
                'users.phone',
                'users.position',
                'users.is_active',
                'users.created_at',
            ])
            ->whereHas('organizations', function ($q) use ($organizationId) {
                $q->where('organization_user.organization_id', $organizationId);
            })
            ->with([
                'roleAssignments' => function ($q) use ($context) {
                    $q->where('context_id', $context->id)
                        ->where('is_active', true);
                },
            ]);

        if (!empty($filters['name'])) {
            $query->where('name', 'like', '%' . $filters['name'] . '%');
        }

        if (!empty($filters['email'])) {
            $query->where('email', 'like', '%' . $filters['email'] . '%');
        }

        if (isset($filters['is_active'])) {
            $isActiveFilter = $filters['is_active'];
            if (is_string($isActiveFilter)) {
                if (strtolower($isActiveFilter) === 'true') {
                    $isActiveFilter = true;
                } elseif (strtolower($isActiveFilter) === 'false') {
                    $isActiveFilter = false;
                }
            }

            if (is_bool($isActiveFilter)) {
                $query->where('is_active', $isActiveFilter);
            }
        }

        if (!empty($filters['role'])) {
            $query->whereHas('roleAssignments', function ($q) use ($filters, $context) {
                $q->where('role_slug', $filters['role'])
                    ->where('context_id', $context->id)
                    ->where('is_active', true);
            });
        }

        $allowedSortBy = ['id', 'name', 'email', 'created_at', 'is_active'];
        $tableName = $this->model->getTable();
        $validatedSortBy = in_array($sortBy, $allowedSortBy, true) ? $sortBy : 'name';
        $validatedSortDirection = strtolower($sortDirection) === 'desc' ? 'desc' : 'asc';

        return $query
            ->orderBy($tableName . '.' . $validatedSortBy, $validatedSortDirection)
            ->paginate($perPage);
    }

    // Добавляем реализацию недостающего метода
    public function findByRoleInOrganizationPaginated(int $organizationId, string $roleSlug, int $perPage = 15): PaginationLengthAwarePaginator
    {
        // Получаем контекст организации
        $context = AuthorizationContext::getOrganizationContext($organizationId);
        
        return $this->model
            ->whereHas('roleAssignments', function ($query) use ($roleSlug, $context) {
                $query->where('role_slug', $roleSlug)
                      ->where('context_id', $context->id)
                      ->where('is_active', true);
            })
            ->paginate($perPage);
    }

} 
