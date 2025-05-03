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

class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

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
     * Получить идентификатор, который будет сохранен в JWT.
     *
     * @return mixed
     */
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /**
     * Возвращает массив пользовательских данных для добавления в JWT.
     *
     * @return array
     */
    public function getJWTCustomClaims()
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'user_type' => $this->user_type,
            'organization_id' => $this->current_organization_id,
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
        return $this->organizations()->wherePivot('is_owner', true);
    }

    /**
     * Получить активные организации пользователя.
     */
    public function activeOrganizations()
    {
        return $this->organizations()->wherePivot('is_active', true);
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
        return $this->roles()->wherePivot('organization_id', $organizationId);
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
        $query = $this->roles()->where('slug', $roleSlug);

        if ($organizationId) {
            $query->wherePivot('organization_id', $organizationId);
        }

        return $query->exists();
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

        return $this->hasRole('organization_admin', $organizationId);
    }
}
