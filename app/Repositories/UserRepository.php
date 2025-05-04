<?php

namespace App\Repositories;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Role;
use Illuminate\Support\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class UserRepository extends BaseRepository implements UserRepositoryInterface
{
    /**
     * UserRepository constructor.
     */
    public function __construct()
    {
        parent::__construct(User::class);
    }

    public function findByEmail(string $email)
    {
        return $this->model->where('email', $email)->first();
    }

    public function findWithRoles(int $id)
    {
        return $this->model->with(['roles', 'organizations'])->find($id);
    }

    public function getUsersInOrganization(int $organizationId)
    {
        return $this->model->whereHas('organizations', function ($query) use ($organizationId) {
            $query->where('organization_id', $organizationId);
        })->get();
    }

    public function attachToOrganization(int $userId, int $organizationId, bool $isOwner = false, bool $isActive = true)
    {
        $user = $this->find($userId);
        
        if (!$user) {
            return false;
        }
        
        $user->organizations()->attach($organizationId, [
            'is_owner' => $isOwner,
            'is_active' => $isActive
        ]);
        
        return $user;
    }

    public function assignRole(int $userId, int $roleId, ?int $organizationId = null)
    {
        $user = $this->find($userId);
        
        if (!$user) {
            return false;
        }
        
        $pivotData = $organizationId ? ['organization_id' => $organizationId] : [];
        $user->roles()->attach($roleId, $pivotData);
        
        return $user;
    }

    /**
     * Найти пользователей с определенной ролью в организации
     *
     * @param int $organizationId
     * @param string $roleName
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function findByRoleInOrganization(int $organizationId, string $roleName)
    {
        // Лог типа ПЕРЕД вызовом whereHas - УДАЛЯЕМ
        /* Log::debug('[UserRepository] findByRoleInOrganization called', [
            'organizationId_param' => $organizationId,
            'organizationId_param_type' => gettype($organizationId),
            'roleName' => $roleName
        ]); */

        $intOrganizationId = (int) $organizationId; // Убедимся, что ID целочисленный

        // Строим запрос
        $query = $this->model->whereHas('roles', function ($subQuery) use ($roleName, $intOrganizationId) {
            $subQuery->where('slug', $roleName)
                  // Используем where() с явным указанием таблицы и столбца
                  ->where('role_user.organization_id', $intOrganizationId); 
        });
        
        // Логируем SQL и биндинги ПЕРЕД выполнением - УДАЛЯЕМ
        /* Log::debug('[UserRepository] SQL Query for findByRoleInOrganization', [
            'sql' => $query->toSql(),
            'bindings' => $query->getBindings(),
            'roleName' => $roleName,
            'organizationId' => $intOrganizationId
        ]); */

        // Выполняем запрос
        return $query->get();

        // Предыдущий вариант с wherePivot, который был некорректен
        /*
        return $this->model->whereHas('roles', function ($query) use ($roleName, $intOrganizationId) {
            $query->where('slug', $roleName)
                  ->wherePivot('organization_id', $intOrganizationId); // Проверяем pivot
        })->get();
        */

        // Старый вариант с двумя whereHas, который мог быть неточным:
        /*
        return $this->model->whereHas('organizations', function ($q) use ($intOrganizationId) {
            Log::debug('[UserRepository] Inside whereHas(organizations) closure', [
                'organizationId_in_closure' => $intOrganizationId,
                'organizationId_in_closure_type' => gettype($intOrganizationId)
            ]);
            $q->where('organization_user.organization_id', $intOrganizationId); // Уточняем таблицу 
        })->whereHas('roles', function ($q) use ($roleName) {
             Log::debug('[UserRepository] Inside whereHas(roles) closure', [
                'roleName_in_closure' => $roleName,
                'roleName_in_closure_type' => gettype($roleName)
            ]);
            $q->where('slug', $roleName); 
        })->get();
        */
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
        $user = $this->find($userId);
        if (!$user) {
            return false;
        }
        // Используем detach, передавая ID роли и условие для pivot-таблицы
        $detachedCount = $user->roles()->wherePivot('organization_id', $organizationId)->detach($roleId);
        // detach возвращает количество удаленных записей
        return $detachedCount > 0;
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
        $user = $this->find($userId);
        if (!$user) {
            return false;
        }
        // Используем detach для связи organizations
        $detachedCount = $user->organizations()->detach($organizationId);
        // detach возвращает количество удаленных записей
        return $detachedCount > 0;
    }

    /**
     * Check if a user has a specific role within a specific organization.
     */
    public function hasRoleInOrganization(int $userId, int $roleId, int $organizationId): bool
    {
        $user = $this->find($userId);
        if (!$user) {
            return false;
        }

        // Проверяем существование связи в pivot-таблице role_user
        return $user->roles()
                    ->wherePivot('organization_id', $organizationId)
                    ->where('roles.id', $roleId) // Уточняем ID роли в таблице roles
                    ->exists();
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
        $query = $this->model->whereHas('roles', function ($q) use ($roleSlug, $organizationId) {
            $q->where('slug', $roleSlug)
              ->where('role_user.organization_id', $organizationId);
        });

        // Применяем фильтры (если есть)
        if (!empty($filters['name'])) {
            $query->where('name', 'ilike', '%' . $filters['name'] . '%');
        }
        if (isset($filters['is_active'])) { // Фильтр по активности в организации
            $query->whereHas('organizations', function ($orgQuery) use ($organizationId, $filters) {
                $orgQuery->where('organization_user.organization_id', $organizationId)
                         ->where('organization_user.is_active', (bool)$filters['is_active']);
            });
        } else {
             // Убедимся, что пользователь в принципе привязан к организации
            $query->whereHas('organizations', function ($orgQuery) use ($organizationId) {
                $orgQuery->where('organization_user.organization_id', $organizationId);
            });
        }

        // Сортировка
        $query->orderBy($sortBy, $sortDirection);

        return $query->paginate($perPage);
    }

    /**
     * Получить данные по активности прорабов (из логов).
     */
    public function getForemanActivity(int $organizationId, array $filters = []): Collection
    {
        // Базовый запрос для логов материалов
        $materialLogsQuery = DB::table('material_usage_logs as mul')
            ->select(
                'mul.user_id',
                DB::raw('count(*) as material_logs_count'),
                DB::raw('0 as work_logs_count') // Заглушка для UNION
            )
            ->join('projects as p', 'mul.project_id', '=', 'p.id')
            ->where('p.organization_id', $organizationId)
            ->groupBy('mul.user_id');

        // Базовый запрос для логов работ
        $workLogsQuery = DB::table('work_completion_logs as wcl')
            ->select(
                'wcl.user_id',
                DB::raw('0 as material_logs_count'), // Заглушка для UNION
                DB::raw('count(*) as work_logs_count')
            )
            ->join('projects as p', 'wcl.project_id', '=', 'p.id')
            ->where('p.organization_id', $organizationId)
            ->groupBy('wcl.user_id');

        // Применение общих фильтров к обоим запросам
        foreach ([$materialLogsQuery, $workLogsQuery] as $query) {
            if (!empty($filters['project_id'])) {
                // Имя таблицы разное, используем алиас 'p'
                $query->where('p.id', $filters['project_id']);
            }
            if (!empty($filters['user_id'])) {
                // Имя колонки user_id одинаковое
                $query->where($this->getLogUserColumn($query), $filters['user_id']);
            }
            if (!empty($filters['date_from'])) {
                $query->where($this->getLogDateColumn($query), '>=', $filters['date_from']);
            }
            if (!empty($filters['date_to'])) {
                $query->where($this->getLogDateColumn($query), '<=', $filters['date_to']);
            }
        }

        // Объединяем запросы через UNION ALL
        $unionQuery = $materialLogsQuery->unionAll($workLogsQuery);

        // Финальная агрегация после UNION
        $finalQuery = DB::query()->fromSub($unionQuery, 'activity')
            ->select(
                'activity.user_id',
                DB::raw('SUM(activity.material_logs_count) as total_material_logs'),
                DB::raw('SUM(activity.work_logs_count) as total_work_logs'),
                'users.name as user_name' // Добавляем имя пользователя
            )
            ->join('users', 'activity.user_id', '=', 'users.id')
            ->groupBy('activity.user_id', 'users.name')
            ->orderBy('users.name');

        return $finalQuery->get();
    }

    // Вспомогательные методы для получения имен колонок логов
    private function getLogUserColumn($query): string
    {
        // Определяем таблицу по алиасу или имени
        $from = $query->from;
        return ($from === 'material_usage_logs as mul' || $from === 'material_usage_logs') ? 'mul.user_id' : 'wcl.user_id';
    }

    private function getLogDateColumn($query): string
    {
        $from = $query->from;
        return ($from === 'material_usage_logs as mul' || $from === 'material_usage_logs') ? 'mul.usage_date' : 'wcl.completion_date';
    }
} 