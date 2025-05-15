<?php

namespace App\Repositories;

use App\Models\User;
use App\Repositories\Interfaces\UserRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Role;
use Illuminate\Support\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as PaginationLengthAwarePaginator;

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
        return $this->model->with('roles')->find($id);
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
     * @return void
     */
    public function attachToOrganization(int $userId, int $organizationId, bool $isOwner = false): void
    {
        $user = $this->model->find($userId);
        if ($user) {
            // Привязываем пользователя к организации
            $user->organizations()->attach($organizationId, ['is_owner' => $isOwner]);
            
            // Присваиваем роль владельца (Owner) только если $isOwner = true
            if ($isOwner) {
                try {
                    // Находим роль Owner по slug
                    $ownerRole = Role::where('slug', Role::ROLE_OWNER)->first();
                    if ($ownerRole) {
                        $this->assignRole($userId, $ownerRole->id, $organizationId);
                        Log::info("Assigned owner role to user", [
                            'user_id' => $userId,
                            'organization_id' => $organizationId,
                            'role_id' => $ownerRole->id
                        ]);
                    } else {
                        Log::error("Owner role not found in the system");
                    }
                } catch (\Exception $e) {
                    Log::error("Failed to assign owner role: " . $e->getMessage(), [
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

    public function assignRole(int $userId, int $roleId, int $organizationId): void
    {
        $user = $this->model->find($userId);
        if ($user) {
            // Проверяем, существует ли уже такая связь с ролью, чтобы избежать дублирования
            $exists = $user->roles()
                          ->where('role_user.organization_id', $organizationId)
                          ->where('role_user.role_id', $roleId)
                          ->exists();

            if (!$exists) {
                $user->roles()->attach($roleId, ['organization_id' => $organizationId]);
            }
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
        // TODO: Implementar логику для получения активности прорабов из логов
        // Например, через запрос к таблицам MaterialUsageLog и WorkCompletionLog
        // с группировкой и фильтрацией
        return collect(); // Временная заглушка
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
} 