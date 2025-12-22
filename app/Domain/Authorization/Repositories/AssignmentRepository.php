<?php

namespace App\Domain\Authorization\Repositories;

use App\Models\User;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Models\AuthorizationContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * Репозиторий для работы с назначениями ролей (с кешированием)
 */
class AssignmentRepository
{
    /**
     * Получить назначения ролей пользователя с кешированием
     */
    public function getUserAssignments(User $user, ?AuthorizationContext $context = null): Collection
    {
        $cacheKey = $this->getCacheKey('user_assignments', $user->id, $context?->id);
        
        return Cache::remember($cacheKey, 300, function () use ($user, $context) {
            $query = $user->roleAssignments()->active()->with(['context', 'conditions']);
            
            if ($context) {
                // Получаем назначения в указанном контексте и всех родительских
                $contextIds = collect($context->getHierarchy())->pluck('id');
                $query->whereIn('context_id', $contextIds);
            }
            
            return $query->get();
        });
    }

    /**
     * Получить активные назначения роли
     */
    public function getRoleAssignments(
        string $roleSlug, 
        string $roleType = UserRoleAssignment::TYPE_SYSTEM,
        ?AuthorizationContext $context = null
    ): Collection {
        $cacheKey = $this->getCacheKey('role_assignments', $roleSlug, $context?->id, $roleType);
        
        return Cache::remember($cacheKey, 300, function () use ($roleSlug, $roleType, $context) {
            $query = UserRoleAssignment::where('role_slug', $roleSlug)
                ->where('role_type', $roleType)
                ->active()
                ->with(['user', 'context', 'conditions']);
            
            if ($context) {
                $query->where('context_id', $context->id);
            }
            
            return $query->get();
        });
    }

    /**
     * Создать назначение роли (или реактивировать существующее)
     */
    public function createAssignment(
        User $user,
        string $roleSlug,
        AuthorizationContext $context,
        string $roleType = UserRoleAssignment::TYPE_SYSTEM,
        ?User $assignedBy = null,
        ?Carbon $expiresAt = null
    ): UserRoleAssignment {
        // Используем updateOrCreate для атомарного создания или обновления
        $assignment = UserRoleAssignment::updateOrCreate(
            [
                'user_id' => $user->id,
                'role_slug' => $roleSlug,
                'context_id' => $context->id,
            ],
            [
                'role_type' => $roleType,
                'assigned_by' => $assignedBy?->id,
                'expires_at' => $expiresAt,
                'is_active' => true
            ]
        );

        // Очищаем кеш
        $this->clearUserCache($user);
        $this->clearRoleCache($roleSlug, $roleType, $context);

        return $assignment;
    }

    /**
     * Обновить назначение роли
     */
    public function updateAssignment(UserRoleAssignment $assignment, array $data): bool
    {
        $result = $assignment->update($data);

        if ($result) {
            // Очищаем кеш
            $this->clearUserCache($assignment->user);
            $this->clearRoleCache($assignment->role_slug, $assignment->role_type, $assignment->context);
        }

        return $result;
    }

    /**
     * Деактивировать назначение
     */
    public function deactivateAssignment(UserRoleAssignment $assignment): bool
    {
        $result = $assignment->update(['is_active' => false]);

        if ($result) {
            // Очищаем кеш
            $this->clearUserCache($assignment->user);
            $this->clearRoleCache($assignment->role_slug, $assignment->role_type, $assignment->context);
        }

        return $result;
    }

    /**
     * Получить назначения роли пользователя в контексте
     */
    public function getUserRoleInContext(
        User $user, 
        string $roleSlug, 
        AuthorizationContext $context
    ): ?UserRoleAssignment {
        return $user->roleAssignments()
            ->where('role_slug', $roleSlug)
            ->where('context_id', $context->id)
            ->active()
            ->first();
    }

    /**
     * Проверить, есть ли у пользователя роль в контексте
     */
    public function hasRoleInContext(User $user, string $roleSlug, AuthorizationContext $context): bool
    {
        $cacheKey = $this->getCacheKey('has_role', $user->id, $context->id, $roleSlug);
        
        return Cache::remember($cacheKey, 300, function () use ($user, $roleSlug, $context) {
            return $user->roleAssignments()
                ->where('role_slug', $roleSlug)
                ->where('context_id', $context->id)
                ->active()
                ->exists();
        });
    }

    /**
     * Получить пользователей с указанной ролью
     */
    public function getUsersWithRole(
        string $roleSlug,
        string $roleType = UserRoleAssignment::TYPE_SYSTEM,
        ?AuthorizationContext $context = null
    ): Collection {
        $assignments = $this->getRoleAssignments($roleSlug, $roleType, $context);
        return $assignments->pluck('user')->unique('id');
    }

    /**
     * Получить количество активных назначений роли
     */
    public function getAssignmentCount(
        string $roleSlug,
        string $roleType = UserRoleAssignment::TYPE_SYSTEM,
        ?AuthorizationContext $context = null
    ): int {
        $cacheKey = $this->getCacheKey('assignment_count', $roleSlug, $context?->id, $roleType);
        
        return Cache::remember($cacheKey, 300, function () use ($roleSlug, $roleType, $context) {
            $query = UserRoleAssignment::where('role_slug', $roleSlug)
                ->where('role_type', $roleType)
                ->active();
                
            if ($context) {
                $query->where('context_id', $context->id);
            }
            
            return $query->count();
        });
    }

    /**
     * Получить истекающие назначения
     */
    public function getExpiringAssignments(int $daysAhead = 7): Collection
    {
        return UserRoleAssignment::active()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', Carbon::now()->addDays($daysAhead)->toDateTimeString())
            ->with(['user', 'context'])
            ->get();
    }

    /**
     * Деактивировать истекшие назначения
     */
    public function deactivateExpiredAssignments(): int
    {
        $expiredAssignments = UserRoleAssignment::active()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', Carbon::now()->toDateTimeString())
            ->get();

        $count = 0;
        foreach ($expiredAssignments as $assignment) {
            if ($this->deactivateAssignment($assignment)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Очистить кеш пользователя
     */
    public function clearUserCache(User $user): void
    {
        Cache::tags(["user_{$user->id}"])->flush();
    }

    /**
     * Очистить кеш роли
     */
    public function clearRoleCache(
        string $roleSlug, 
        string $roleType, 
        ?AuthorizationContext $context = null
    ): void {
        $tags = ["role_{$roleSlug}_{$roleType}"];
        if ($context) {
            $tags[] = "context_{$context->id}";
        }
        Cache::tags($tags)->flush();
    }

    /**
     * Очистить весь кеш назначений
     */
    public function clearAllCache(): void
    {
        Cache::tags(['assignments'])->flush();
    }

    /**
     * Получить ключ кеша
     */
    protected function getCacheKey(string $prefix, ...$params): string
    {
        $key = $prefix . '_' . implode('_', array_filter($params));
        return md5($key);
    }
}
