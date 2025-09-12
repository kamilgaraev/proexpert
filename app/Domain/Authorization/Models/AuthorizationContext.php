<?php

namespace App\Domain\Authorization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Модель контекста авторизации
 * 
 * @property int $id
 * @property string $type Тип контекста: system, organization, project
 * @property int|null $resource_id ID ресурса (organization_id или project_id)
 * @property int|null $parent_context_id Родительский контекст
 * @property array|null $metadata Метаданные контекста
 */
class AuthorizationContext extends Model
{
    protected $fillable = [
        'type',
        'resource_id', 
        'parent_context_id',
        'metadata'
    ];

    protected $casts = [
        'metadata' => 'array'
    ];

    /**
     * Типы контекстов
     */
    const TYPE_SYSTEM = 'system';
    const TYPE_ORGANIZATION = 'organization';
    const TYPE_PROJECT = 'project';

    /**
     * Назначения ролей в этом контексте
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(UserRoleAssignment::class, 'context_id');
    }

    /**
     * Родительский контекст
     */
    public function parentContext(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_context_id');
    }

    /**
     * Дочерние контексты
     */
    public function childContexts(): HasMany
    {
        return $this->hasMany(self::class, 'parent_context_id');
    }

    /**
     * Ресурс контекста (организация или проект)
     */
    public function resource(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Получить системный контекст (единственный)
     */
    public static function getSystemContext(): self
    {
        return static::firstOrCreate([
            'type' => self::TYPE_SYSTEM,
            'resource_id' => null,
            'parent_context_id' => null
        ]);
    }

    /**
     * Получить контекст организации
     */
    public static function getOrganizationContext(int $organizationId): self
    {
        return static::firstOrCreate([
            'type' => self::TYPE_ORGANIZATION,
            'resource_id' => $organizationId,
            'parent_context_id' => self::getSystemContext()->id
        ]);
    }

    /**
     * Получить контекст проекта
     */
    public static function getProjectContext(int $projectId, int $organizationId): self
    {
        $orgContext = self::getOrganizationContext($organizationId);
        
        return static::firstOrCreate([
            'type' => self::TYPE_PROJECT,
            'resource_id' => $projectId,
            'parent_context_id' => $orgContext->id
        ]);
    }

    /**
     * Проверить, является ли контекст дочерним по отношению к указанному
     */
    public function isChildOf(self $context): bool
    {
        $current = $this;
        
        while ($current->parent_context_id) {
            if ($current->parent_context_id === $context->id) {
                return true;
            }
            $current = $current->parentContext;
        }
        
        return false;
    }

    /**
     * Получить полную иерархию контекста (от корня к текущему)
     */
    public function getHierarchy(): array
    {
        $hierarchy = [$this];
        $current = $this;
        
        while ($current->parentContext) {
            $current = $current->parentContext;
            array_unshift($hierarchy, $current);
        }
        
        return $hierarchy;
    }
}
