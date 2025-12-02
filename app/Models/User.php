<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;
use App\Traits\HasImages;

// Импорты для новой системы авторизации добавлены ниже

class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, SoftDeletes, HasImages;

    // Константы для новой системы авторизации (роли определяются в JSON файлах)

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'position',
        'avatar_path',
        'is_active',
        'current_organization_id',
        // 'user_type', // Удалена в новой системе авторизации - роли через UserRoleAssignment
        'settings',
        'last_login_at',
        'last_login_ip',
        'has_completed_onboarding',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    // Добавляем avatar_url к сериализации модели
    // ОТКЛЮЧЕНО для производительности - добавляется только когда нужно
    protected $appends = [
        // 'avatar_url' // Закомментировано - генерируется через кеш в getAvatarUrlAttribute
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'settings' => 'json',
            'last_login_at' => 'datetime',
            'last_transaction_at' => 'datetime',
            'has_completed_onboarding' => 'boolean',
        ];
    }

    /**
     * Get the identifier that will be stored in the subject claim of the JWT.
     *
     * @return mixed
     */
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /**
     * Return a key value array, containing any custom claims to be added to the JWT.
     *
     * @return array
     */
    public function getJWTCustomClaims()
    {
        // Добавляем ID текущей организации пользователя в токен
        return [
            'organization_id' => $this->current_organization_id,
            // Можно добавить другие нужные claim'ы
            // 'user_type' => $this->user_type,
        ];
    }

    /**
     * Получить организации, к которым принадлежит пользователь.
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)
            ->withPivot(['is_owner', 'is_active', 'settings'])
            ->withTimestamps();
    }

    /**
     * Получить текущую организацию пользователя.
     */
    public function currentOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'current_organization_id');
    }

    /**
     * Получить организации, владельцем которых является пользователь.
     */
    public function ownedOrganizations()
    {
        return $this->organizations()->where('organization_user.is_owner', true);
    }

    /**
     * Получить активные организации пользователя.
     */
    public function activeOrganizations()
    {
        return $this->organizations()->where('organization_user.is_active', true);
    }

    // Связи для новой системы авторизации добавлены ниже в разделе авторизации







    /**
     * Проверяет, принадлежит ли пользователь указанной организации (активное членство)
     */
    public function belongsToOrganization(int $organizationId): bool
    {
        return $this->organizations()
            ->where('organization_user.organization_id', $organizationId)
            ->where('organization_user.is_active', true)
            ->exists();
    }

    /**
     * Получить проекты, на которые назначен пользователь.
     */
    public function assignedProjects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_user')
            ->withPivot('role')
            ->withTimestamps();
    }


    /**
     * === НОВАЯ СИСТЕМА АВТОРИЗАЦИИ ===
     */

    /**
     * Связь с назначениями ролей
     */
    public function roleAssignments(): HasMany
    {
        return $this->hasMany(\App\Domain\Authorization\Models\UserRoleAssignment::class);
    }

    /**
     * Проверить, есть ли у пользователя право (переопределяем родительский метод)
     */
    public function can($abilities, $arguments = []): bool
    {
        // Если передан один аргумент как строка - используем новую систему
        if (is_string($abilities) && empty($arguments)) {
            return app(\App\Domain\Authorization\Services\AuthorizationService::class)
                ->can($this, $abilities, null);
        }
        
        // Если передан контекст - используем новую систему
        if (is_string($abilities) && is_array($arguments)) {
            return app(\App\Domain\Authorization\Services\AuthorizationService::class)
                ->can($this, $abilities, $arguments);
        }

        // Иначе вызываем родительский метод для совместимости
        return parent::can($abilities, $arguments);
    }

    /**
     * Проверить право в новой системе авторизации  
     */
    public function hasPermission(string $permission, ?array $context = null): bool
    {
        return app(\App\Domain\Authorization\Services\AuthorizationService::class)
            ->can($this, $permission, $context);
    }

    /**
     * Проверить, есть ли у пользователя роль
     */
    public function hasRole(string $roleSlug, ?int $contextId = null): bool
    {
        return app(\App\Domain\Authorization\Services\AuthorizationService::class)
            ->hasRole($this, $roleSlug, $contextId);
    }

    /**
     * Проверить, есть ли доступ к интерфейсу
     */
    public function canAccessInterface(string $interface, ?\App\Domain\Authorization\Models\AuthorizationContext $context = null): bool
    {
        return app(\App\Domain\Authorization\Services\AuthorizationService::class)
            ->canAccessInterface($this, $interface, $context);
    }

    /**
     * Получить все роли пользователя в контексте
     */
    public function getRoles(?\App\Domain\Authorization\Models\AuthorizationContext $context = null): Collection
    {
        return app(\App\Domain\Authorization\Services\AuthorizationService::class)
            ->getUserRoles($this, $context);
    }

    /**
     * Получить все права пользователя
     */
    public function getPermissions(?\App\Domain\Authorization\Models\AuthorizationContext $context = null): array
    {
        return app(\App\Domain\Authorization\Services\AuthorizationService::class)
            ->getUserPermissions($this, $context);
    }

    /**
     * Получить контексты, в которых у пользователя есть роли
     */
    public function getContexts(): Collection
    {
        return app(\App\Domain\Authorization\Services\AuthorizationService::class)
            ->getUserContexts($this);
    }

    /**
     * Является ли пользователь системным администратором
     */
    public function isSystemAdmin(): bool
    {
        $systemContext = \App\Domain\Authorization\Models\AuthorizationContext::getSystemContext();
        
        return $this->hasRole('super_admin', $systemContext->id) || 
               $this->hasRole('system_admin', $systemContext->id);
               // Убрали проверку user_type так как колонка удалена в новой системе авторизации
    }

    /**
     * Является ли пользователь владельцем организации
     * Проверяет роль в контексте организации и в иерархии (родительские организации)
     */
    public function isOrganizationOwner(?int $organizationId = null): bool
    {
        if ($this->isSystemAdmin()) {
            return true;
        }

        $orgId = $organizationId ?? $this->current_organization_id;
        if (!$orgId) {
            return false;
        }

        // 1. Прямая проверка роли в контексте организации
        $context = \App\Domain\Authorization\Models\AuthorizationContext::getOrganizationContext($orgId);
        if ($this->hasRole('organization_owner', $context->id)) {
            return true;
        }

        // 2. Проверка роли в родительских организациях (иерархия)
        $targetOrg = \App\Models\Organization::find($orgId);
        if (!$targetOrg) {
            return false;
        }

        // Получаем все организации, где у пользователя есть роль organization_owner
        $ownedOrgContexts = $this->roleAssignments()
            ->active()
            ->where('role_slug', 'organization_owner')
            ->whereHas('context', function ($query) {
                $query->where('type', \App\Domain\Authorization\Models\AuthorizationContext::TYPE_ORGANIZATION);
            })
            ->with('context')
            ->get()
            ->pluck('context.resource_id')
            ->filter();

        // Проверяем, является ли целевая организация дочерней от любой организации, где пользователь владелец
        foreach ($ownedOrgContexts as $ownedOrgId) {
            if ($ownedOrgId == $orgId) {
                return true; // Прямое совпадение (уже проверили выше, но на всякий случай)
            }

            // Проверяем иерархию: является ли orgId дочерней от ownedOrgId
            $currentOrg = $targetOrg;
            while ($currentOrg && $currentOrg->parent_organization_id) {
                if ($currentOrg->parent_organization_id == $ownedOrgId) {
                    return true;
                }
                $currentOrg = $currentOrg->parentOrganization;
            }
        }

        return false;
    }

    /**
     * Является ли пользователь администратором организации
     */
    public function isOrganizationAdmin(?int $organizationId = null): bool
    {
        if ($this->isSystemAdmin() || $this->isOrganizationOwner($organizationId)) {
            return true;
        }

        $orgId = $organizationId ?? $this->current_organization_id;
        if (!$orgId) {
            return false;
        }

        $context = \App\Domain\Authorization\Models\AuthorizationContext::getOrganizationContext($orgId);
        return $this->hasRole('organization_admin', $context->id);
    }

    /**
     * Может ли пользователь управлять другим пользователем
     */
    public function canManageUser(User $targetUser, ?\App\Domain\Authorization\Models\AuthorizationContext $context = null): bool
    {
        return app(\App\Domain\Authorization\Services\AuthorizationService::class)
            ->canManageUser($this, $targetUser, $context ?? $this->getCurrentOrganizationContext());
    }

    /**
     * Получить текущий контекст организации пользователя
     */
    public function getCurrentOrganizationContext(): ?\App\Domain\Authorization\Models\AuthorizationContext
    {
        if (!$this->current_organization_id) {
            return null;
        }

        return \App\Domain\Authorization\Models\AuthorizationContext::getOrganizationContext($this->current_organization_id);
    }

    /**
     * Получить роли пользователя в текущей организации
     */
    public function getCurrentOrganizationRoles(): Collection
    {
        $context = $this->getCurrentOrganizationContext();
        return $context ? $this->getRoles($context) : collect();
    }

    /**
     * Получить слаги ролей пользователя для указанной организации
     */
    public function getRoleSlugs(?int $organizationId = null): array
    {
        if ($this->isSystemAdmin()) {
            return ['super_admin']; // Системный админ имеет все права
        }

        $orgId = $organizationId ?? $this->current_organization_id;
        if (!$orgId) {
            return [];
        }

        $context = \App\Domain\Authorization\Models\AuthorizationContext::getOrganizationContext($orgId);
        return $this->getRoles($context)->pluck('role_slug')->toArray();
    }

    /**
     * Проверить, имеет ли пользователь доступ к админ-панели
     */
    public function isAdminPanelUser(?int $organizationId = null): bool
    {
        // Системный админ всегда имеет доступ
        if ($this->isSystemAdmin()) {
            return true;
        }

        // Проверяем доступ к админ интерфейсу
        $orgContext = null;
        if ($organizationId || $this->current_organization_id) {
            $orgId = $organizationId ?? $this->current_organization_id;
            $orgContext = \App\Domain\Authorization\Models\AuthorizationContext::getOrganizationContext($orgId);
        }

        return $this->canAccessInterface('admin', $orgContext);
    }

    /**
     * === СОВМЕСТИМОСТЬ СО СТАРЫМ API ===
     * Эти методы сохранены для обратной совместимости
     */

    /**
     * @deprecated Используйте hasPermission() из новой системы авторизации
     */
    public function hasPermissionDeprecated(string $permissionSlug, ?int $organizationId = null): bool
    {
        $orgId = $organizationId ?? $this->current_organization_id;
        $context = $orgId ? ['organization_id' => $orgId] : null;
        
        return $this->hasPermission($permissionSlug, $context);
    }

    /**
     * @deprecated Используйте isOrganizationOwner()
     */
    public function isOwnerOfOrganization(int $organizationId): bool
    {
        return $this->isOrganizationOwner($organizationId);
    }

    /**
     * Аксессор для получения URL аватара с кешированием.
     * 
     * ОПТИМИЗИРОВАНО: Кеш на 55 минут (подписанная ссылка живет 60 минут)
     *
     * @return string|null
     */
    public function getAvatarUrlAttribute(): ?string
    {
        // Если нет аватара - сразу возвращаем дефолтный
        if (!$this->avatar_path) {
            return asset('images/default-avatar.png');
        }

        // Кеш на 55 минут (подписанная ссылка S3 живет 60 минут)
        $cacheKey = "user_avatar_url_{$this->id}_{$this->updated_at?->timestamp}";
        
        return cache()->remember($cacheKey, 3300, function() {
            // Генерируем временную ссылку (60 минут)
            return $this->getImageUrl('avatar_path', asset('images/default-avatar.png'), true, 60);
        });
    }

    /**
     * Получить организацию для работы с изображениями (аватарами).
     * Переопределяем метод из HasImages трейта.
     *
     * @return \App\Models\Organization|null
     */
    protected function getOrganizationForImages(): ?\App\Models\Organization
    {
        \Illuminate\Support\Facades\Log::info('[User] getOrganizationForImages called', [
            'user_id' => $this->id,
            'current_organization_id' => $this->current_organization_id,
        ]);

        // Сначала пытаемся получить текущую организацию пользователя
        if ($this->current_organization_id) {
            // Ищем организацию по ID вместо использования отношения (может не быть загружено)
            $organization = Organization::find($this->current_organization_id);
            if ($organization) {
                \Illuminate\Support\Facades\Log::info('[User] Found organization by current_organization_id', [
                    'user_id' => $this->id,
                    'org_id' => $organization->id,
                    'org_name' => $organization->name,
                ]);
                return $organization;
            }
        }

        // Если не удалось, берем первую организацию пользователя
        $firstOrg = $this->organizations()->first();
        \Illuminate\Support\Facades\Log::info('[User] Using first organization', [
            'user_id' => $this->id,
            'org_id' => $firstOrg?->id,
            'org_name' => $firstOrg?->name,
        ]);
        return $firstOrg;
    }
}
