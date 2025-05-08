<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;
use App\Traits\HasImages;

// Импортируем константы роли
use App\Models\Role;

class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, SoftDeletes, HasImages;

    /**
     * Роли, имеющие доступ и полный контроль в Admin Panel.
     * Эти роли будут иметь неограниченный доступ.
     */
    const ADMIN_PANEL_ACCESS_ROLES = [
        Role::ROLE_SYSTEM_ADMIN,    // system_admin
        Role::ROLE_OWNER,           // organization_owner
        Role::ROLE_ADMIN,           // organization_admin
        Role::ROLE_WEB_ADMIN,       // web_admin
        Role::ROLE_ACCOUNTANT,      // accountant
        // Добавьте сюда другие SLUG ролей, если они должны иметь полный доступ к админ-панели
    ];

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
        'user_type',
        'settings',
        'last_login_at',
        'last_login_ip',
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
    protected $appends = [
        'avatar_url'
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

    /**
     * Получить роли пользователя.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)
            ->withPivot('organization_id')
            ->withTimestamps();
    }

    /**
     * Получить роли пользователя в конкретной организации.
     *
     * @param int $organizationId
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function rolesInOrganization(int $organizationId)
    {
        return $this->roles()->where('role_user.organization_id', $organizationId);
    }

    /**
     * Проверить, имеет ли пользователь указанную роль в организации.
     *
     * @param string $roleSlug
     * @param int|null $organizationId
     * @return bool
     */
    public function hasRole(string $roleSlug, ?int $organizationId = null): bool
    {
        try {
            // Если организация не указана, проверяем в текущей организации
            $effectiveOrganizationId = $organizationId;
            if (!$effectiveOrganizationId) {
                if (!$this->current_organization_id) {
                    Log::warning('[User::hasRole] Attempted to check role without organization context and no current_organization_id.', [
                        'user_id' => $this->id,
                        'role_slug' => $roleSlug
                    ]);
                    return false; // Нет контекста организации
                }
                $effectiveOrganizationId = $this->current_organization_id;
            }
            
            Log::info('[User::hasRole] Начало проверки роли', [
                'user_id' => $this->id,
                'role_slug' => $roleSlug,
                'organization_id' => $effectiveOrganizationId
            ]);
            
            $roleExists = $this->roles()
                ->where('slug', $roleSlug)
                ->where('role_user.organization_id', $effectiveOrganizationId)
                ->exists();
            
            // Log::info('[User::hasRole] Результат проверки.', ['exists' => $roleExists]); // Можно добавить для детальной отладки
            return $roleExists;

        } catch (\Throwable $e) {
            Log::error('[User::hasRole] Exception caught during role check. Returning false.', [
                'user_id' => $this->id,
                'role_slug' => $roleSlug,
                'passed_organization_id' => $organizationId,
                'current_organization_id_on_user' => $this->current_organization_id ?? 'null',
                'exception_message' => $e->getMessage(),
                'exception_class' => get_class($e),
                'exception_file' => $e->getFile() . ':' . $e->getLine()
            ]);
            return false; // В случае любой ошибки считаем, что роли нет
        }
    }

    /**
     * Проверить, имеет ли пользователь разрешение в конкретной организации.
     *
     * @param string $permissionSlug
     * @param int|null $organizationId
     * @return bool
     */
    public function hasPermission(string $permissionSlug, ?int $organizationId = null): bool
    {
        $organizationId = $organizationId ?? $this->current_organization_id;

        if (!$organizationId) {
            return false;
        }

        $roles = $this->rolesInOrganization($organizationId)->get();
        
        foreach ($roles as $role) {
            if ($role->hasPermission($permissionSlug)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Является ли пользователь системным администратором.
     *
     * @return bool
     */
    public function isSystemAdmin(): bool
    {
        // TODO: Проверить, нужно ли сверяться с таблицей ролей или достаточно user_type
        // Пока оставляем user_type для системного админа
        return $this->user_type === 'system_admin';
    }

    /**
     * Является ли пользователь администратором организации.
     *
     * @param int|null $organizationId
     * @return bool
     */
    public function isOrganizationAdmin(?int $organizationId = null): bool
    {
        if ($this->isSystemAdmin()) {
            return true;
        }

        $organizationId = $organizationId ?? $this->current_organization_id;

        if (!$organizationId) {
            return false;
        }

        // Проверяем наличие роли админа ИЛИ роли владельца
        return $this->hasRole(Role::ROLE_ADMIN, $organizationId) || $this->hasRole(Role::ROLE_OWNER, $organizationId);
    }

    /**
     * Является ли пользователь владельцем указанной организации.
     *
     * @param int $organizationId
     * @return bool
     */
    public function isOwnerOfOrganization(int $organizationId): bool
    {
        return $this->ownedOrganizations()->where('organization_id', $organizationId)->exists();
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
     * Проверяет, имеет ли пользователь роль, дающую полный доступ к админ-панели.
     *
     * @param int|null $organizationId Если null, используется текущая организация пользователя.
     * @return bool
     */
    public function isAdminPanelUser(?int $organizationId = null): bool
    {
        // Системный администратор имеет доступ всегда, вне зависимости от организации
        if ($this->isSystemAdmin()) {
            return true;
        }

        $organizationId = $organizationId ?? $this->current_organization_id;

        if (!$organizationId) {
            // Если нет контекста организации (кроме системного администратора),
            // то считаем, что доступа к админ-панели организации нет.
            return false;
        }

        // Получаем все слаги ролей пользователя в указанной организации
        $userRoleSlugs = $this->rolesInOrganization($organizationId)->pluck('slug')->toArray();

        // Проверяем, есть ли пересечение между ролями пользователя и разрешенными ролями для админ-панели
        foreach (self::ADMIN_PANEL_ACCESS_ROLES as $adminRoleSlug) {
            if (in_array($adminRoleSlug, $userRoleSlugs)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Аксессор для получения URL аватара.
     *
     * @return string|null
     */
    public function getAvatarUrlAttribute(): ?string
    {
        // Предполагаем, что дефолтный аватар находится в public/images/default-avatar.png
        // или вы настроите свой путь к дефолтному изображению.
        // Если аватары загружаются с видимостью 'public', то $temporary = false (по умолчанию)
        return $this->getImageUrl('avatar_path', asset('images/default-avatar.png'));
    }
}
