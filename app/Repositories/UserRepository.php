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

    public function attachToOrganization(int $userId, int $organizationId, bool $isOwner = false, bool $setCurrent = false): void
    {
        $user = $this->model->find($userId);
        if ($user) {
            $user->organizations()->attach($organizationId, [/* 'is_owner' => $isOwner - убираем, если нет в интерфейсе */]);
            // if ($setCurrent) {
            //     $user->current_organization_id = $organizationId;
            //     $user->save();
            // }
        }
    }

    public function assignRole(int $userId, int $roleId, int $organizationId): void
    {
        $user = $this->model->find($userId);
        if ($user) {
            // Проверяем, существует ли уже такая связь с ролью, чтобы избежать дублирования
            $exists = $user->roles()
                          ->wherePivot('organization_id', $organizationId)
                          ->wherePivot('role_id', $roleId)
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
                      ->wherePivot('organization_id', $organizationId);
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
            return $user->roles()->wherePivot('organization_id', $organizationId)->detach($roleId) > 0;
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
            $user->roles()->wherePivot('organization_id', $organizationId)->detach();
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
                        ->wherePivot('organization_id', $organizationId)
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
                $q->wherePivot('organization_id', $organizationId);
            });

        // Применяем фильтры (пример)
        if (!empty($filters['name'])) {
            $query->where('name', 'like', '%' . $filters['name'] . '%');
        }
        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        $query->orderBy($sortBy, $sortDirection);

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
                      ->wherePivot('organization_id', $organizationId);
            })
            ->paginate($perPage);
    }
} 