<?php

namespace App\Domain\Authorization\Models;

use App\Models\User;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Модель кастомной роли организации
 * 
 * @property int $id
 * @property int $organization_id
 * @property string $name Название роли
 * @property string $slug Слаг роли
 * @property string|null $description
 * @property array $system_permissions Системные права
 * @property array $module_permissions Модульные права
 * @property array $interface_access Доступ к интерфейсам
 * @property array|null $conditions ABAC условия
 * @property bool $is_active
 * @property int $created_by
 */
class OrganizationCustomRole extends Model
{
    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'description',
        'system_permissions',
        'module_permissions',
        'interface_access',
        'conditions',
        'is_active',
        'created_by'
    ];

    protected $casts = [
        'system_permissions' => 'array',
        'module_permissions' => 'array',
        'interface_access' => 'array',
        'conditions' => 'array',
        'is_active' => 'boolean'
    ];

    /**
     * Организация
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Кто создал роль
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Назначения этой роли пользователям
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(UserRoleAssignment::class, 'role_slug', 'slug')
            ->where('role_type', UserRoleAssignment::TYPE_CUSTOM);
    }

    /**
     * Scope: только активные роли
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: роли конкретной организации
     */
    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Автоматическое создание слага при создании роли
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $role) {
            if (empty($role->slug)) {
                $role->slug = self::generateSlug($role->name, $role->organization_id);
            }
        });
    }

    /**
     * Генерация уникального слага для роли
     */
    public static function generateSlug(string $name, int $organizationId): string
    {
        $baseSlug = Str::slug($name, '_');
        $slug = $baseSlug;
        $counter = 1;

        while (static::where('organization_id', $organizationId)
                    ->where('slug', $slug)
                    ->exists()) {
            $slug = $baseSlug . '_' . $counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Проверить, есть ли у роли системное право
     */
    public function hasSystemPermission(string $permission): bool
    {
        return in_array($permission, $this->system_permissions ?? []);
    }

    /**
     * Проверить, есть ли у роли модульное право
     */
    public function hasModulePermission(string $module, string $permission): bool
    {
        $modulePermissions = $this->module_permissions[$module] ?? [];
        
        // Проверяем точное совпадение или wildcard
        return in_array($permission, $modulePermissions) || 
               in_array($module . '.*', $modulePermissions) ||
               in_array('*', $modulePermissions);
    }

    /**
     * Проверить, есть ли доступ к интерфейсу
     */
    public function hasInterfaceAccess(string $interface): bool
    {
        return in_array($interface, $this->interface_access ?? []);
    }

    /**
     * Получить все права роли
     */
    public function getAllPermissions(): array
    {
        $permissions = $this->system_permissions ?? [];
        
        foreach ($this->module_permissions ?? [] as $module => $modulePerms) {
            foreach ($modulePerms as $perm) {
                $permissions[] = $module . '.' . $perm;
            }
        }
        
        return array_unique($permissions);
    }

    /**
     * Создать новую кастомную роль
     */
    public static function createRole(
        int $organizationId,
        string $name,
        array $systemPermissions = [],
        array $modulePermissions = [],
        array $interfaceAccess = ['lk'],
        ?array $conditions = null,
        ?string $description = null,
        ?User $createdBy = null
    ): self {
        return static::create([
            'organization_id' => $organizationId,
            'name' => $name,
            'description' => $description,
            'system_permissions' => $systemPermissions,
            'module_permissions' => $modulePermissions,
            'interface_access' => $interfaceAccess,
            'conditions' => $conditions,
            'created_by' => $createdBy?->id,
            'is_active' => true
        ]);
    }

    /**
     * Клонировать роль в другую организацию
     */
    public function cloneTo(int $targetOrganizationId, ?User $createdBy = null): self
    {
        return static::create([
            'organization_id' => $targetOrganizationId,
            'name' => $this->name,
            'description' => $this->description,
            'system_permissions' => $this->system_permissions,
            'module_permissions' => $this->module_permissions,
            'interface_access' => $this->interface_access,
            'conditions' => $this->conditions,
            'created_by' => $createdBy?->id ?? $this->created_by,
            'is_active' => true
        ]);
    }

    /**
     * Деактивировать роль
     */
    public function deactivate(): bool
    {
        // Деактивируем все назначения этой роли
        $this->assignments()->update(['is_active' => false]);
        
        return $this->update(['is_active' => false]);
    }
}
