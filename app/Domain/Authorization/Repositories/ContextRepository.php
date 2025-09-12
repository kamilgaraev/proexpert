<?php

namespace App\Domain\Authorization\Repositories;

use App\Domain\Authorization\Models\AuthorizationContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Репозиторий для управления контекстами авторизации
 */
class ContextRepository
{
    /**
     * Получить системный контекст
     */
    public function getSystemContext(): AuthorizationContext
    {
        return Cache::remember('system_context', 3600, function () {
            return AuthorizationContext::getSystemContext();
        });
    }

    /**
     * Получить контекст организации
     */
    public function getOrganizationContext(int $organizationId): AuthorizationContext
    {
        $cacheKey = "organization_context_{$organizationId}";
        
        return Cache::remember($cacheKey, 3600, function () use ($organizationId) {
            return AuthorizationContext::getOrganizationContext($organizationId);
        });
    }

    /**
     * Получить контекст проекта
     */
    public function getProjectContext(int $projectId, int $organizationId): AuthorizationContext
    {
        $cacheKey = "project_context_{$projectId}_{$organizationId}";
        
        return Cache::remember($cacheKey, 3600, function () use ($projectId, $organizationId) {
            return AuthorizationContext::getProjectContext($projectId, $organizationId);
        });
    }

    /**
     * Найти контекст по ID
     */
    public function findById(int $contextId): ?AuthorizationContext
    {
        return Cache::remember("context_{$contextId}", 3600, function () use ($contextId) {
            return AuthorizationContext::find($contextId);
        });
    }

    /**
     * Получить все контексты организации (включая проекты)
     */
    public function getOrganizationContexts(int $organizationId): Collection
    {
        $cacheKey = "organization_contexts_{$organizationId}";
        
        return Cache::remember($cacheKey, 1800, function () use ($organizationId) {
            $orgContext = $this->getOrganizationContext($organizationId);
            
            return AuthorizationContext::where('parent_context_id', $orgContext->id)
                ->orWhere('id', $orgContext->id)
                ->get();
        });
    }

    /**
     * Получить иерархию контекста (от текущего к корню)
     */
    public function getContextHierarchy(AuthorizationContext $context): array
    {
        $cacheKey = "context_hierarchy_{$context->id}";
        
        return Cache::remember($cacheKey, 3600, function () use ($context) {
            return $context->getHierarchy();
        });
    }

    /**
     * Получить дочерние контексты
     */
    public function getChildContexts(AuthorizationContext $context): Collection
    {
        return Cache::remember("child_contexts_{$context->id}", 1800, function () use ($context) {
            return $context->childContexts;
        });
    }

    /**
     * Проверить, является ли контекст дочерним
     */
    public function isChildContext(AuthorizationContext $child, AuthorizationContext $parent): bool
    {
        $cacheKey = "is_child_{$child->id}_{$parent->id}";
        
        return Cache::remember($cacheKey, 3600, function () use ($child, $parent) {
            return $child->isChildOf($parent);
        });
    }

    /**
     * Создать новый контекст
     */
    public function create(
        string $type,
        ?int $resourceId = null,
        ?AuthorizationContext $parentContext = null,
        ?array $metadata = null
    ): AuthorizationContext {
        $context = AuthorizationContext::create([
            'type' => $type,
            'resource_id' => $resourceId,
            'parent_context_id' => $parentContext?->id,
            'metadata' => $metadata
        ]);

        // Очищаем связанные кеши
        $this->clearContextCaches($context);

        return $context;
    }

    /**
     * Обновить контекст
     */
    public function update(AuthorizationContext $context, array $data): bool
    {
        $result = $context->update($data);

        if ($result) {
            $this->clearContextCaches($context);
        }

        return $result;
    }

    /**
     * Удалить контекст
     */
    public function delete(AuthorizationContext $context): bool
    {
        // Сначала обновляем дочерние контексты
        $context->childContexts()->update([
            'parent_context_id' => $context->parent_context_id
        ]);

        // Деактивируем все назначения в этом контексте
        $context->assignments()->update(['is_active' => false]);

        $result = $context->delete();

        if ($result) {
            $this->clearContextCaches($context);
        }

        return $result;
    }

    /**
     * Получить контексты по типу
     */
    public function getContextsByType(string $type): Collection
    {
        return Cache::remember("contexts_by_type_{$type}", 1800, function () use ($type) {
            return AuthorizationContext::where('type', $type)->get();
        });
    }

    /**
     * Получить контексты с назначениями ролей
     */
    public function getContextsWithAssignments(): Collection
    {
        return Cache::remember('contexts_with_assignments', 900, function () {
            return AuthorizationContext::whereHas('assignments', function ($query) {
                $query->where('is_active', true);
            })->get();
        });
    }

    /**
     * Получить статистику контекстов
     */
    public function getContextStats(): array
    {
        return Cache::remember('context_stats', 1800, function () {
            return [
                'total' => AuthorizationContext::count(),
                'by_type' => AuthorizationContext::groupBy('type')
                    ->selectRaw('type, count(*) as count')
                    ->pluck('count', 'type')
                    ->toArray(),
                'with_assignments' => AuthorizationContext::whereHas('assignments', function ($query) {
                    $query->where('is_active', true);
                })->count(),
                'system_contexts' => 1, // Всегда один системный контекст
                'organization_contexts' => AuthorizationContext::where('type', 'organization')->count(),
                'project_contexts' => AuthorizationContext::where('type', 'project')->count(),
            ];
        });
    }

    /**
     * Найти или создать контекст
     */
    public function findOrCreate(
        string $type,
        ?int $resourceId = null,
        ?AuthorizationContext $parentContext = null,
        ?array $metadata = null
    ): AuthorizationContext {
        $existing = AuthorizationContext::where('type', $type)
            ->where('resource_id', $resourceId)
            ->where('parent_context_id', $parentContext?->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        return $this->create($type, $resourceId, $parentContext, $metadata);
    }

    /**
     * Очистить кеши контекста
     */
    public function clearContextCaches(AuthorizationContext $context): void
    {
        // Очищаем кеш самого контекста
        Cache::forget("context_{$context->id}");
        Cache::forget("context_hierarchy_{$context->id}");
        Cache::forget("child_contexts_{$context->id}");

        // Очищаем кеши по типу и ресурсу
        if ($context->type === 'organization' && $context->resource_id) {
            Cache::forget("organization_context_{$context->resource_id}");
            Cache::forget("organization_contexts_{$context->resource_id}");
        }

        if ($context->type === 'project' && $context->resource_id) {
            Cache::forget("project_context_{$context->resource_id}_*");
        }

        // Очищаем родительские кеши
        if ($context->parent_context_id) {
            Cache::forget("child_contexts_{$context->parent_context_id}");
        }

        // Очищаем общие кеши
        Cache::forget("contexts_by_type_{$context->type}");
        Cache::forget('contexts_with_assignments');
        Cache::forget('context_stats');
    }

    /**
     * Очистить все кеши контекстов
     */
    public function clearAllCaches(): void
    {
        Cache::tags(['contexts'])->flush();
    }
}
