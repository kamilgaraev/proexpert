<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    use HasFactory;

    // Slugs ролей
    const ROLE_OWNER = 'organization_owner';
    const ROLE_ADMIN = 'organization_admin'; // Используем 'organization_admin' как было в User::isOrganizationAdmin
    const ROLE_FOREMAN = 'foreman';
    const ROLE_WEB_ADMIN = 'web_admin';
    const ROLE_ACCOUNTANT = 'accountant';
    const ROLE_SYSTEM_ADMIN = 'system_admin'; // Добавим для полноты

    // Типы ролей
    const TYPE_SYSTEM = 'system';
    const TYPE_ORGANIZATION = 'organization';

    /**
     * Атрибуты, которые можно массово назначать.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'type',
        'is_active',
        'organization_id',
    ];

    /**
     * Атрибуты, которые должны быть приведены к типам.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Получить организацию, к которой принадлежит роль.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Получить пользователей, имеющих эту роль.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('organization_id')
            ->withTimestamps();
    }

    /**
     * Получить разрешения, связанные с данной ролью.
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class)->withTimestamps();
    }

    /**
     * Проверить, является ли роль системной.
     *
     * @return bool
     */
    public function isSystem(): bool
    {
        return $this->type === 'system';
    }

    /**
     * Проверить, есть ли у роли указанное разрешение.
     *
     * @param string $permissionSlug
     * @return bool
     */
    public function hasPermission(string $permissionSlug): bool
    {
        return $this->permissions->contains('slug', $permissionSlug);
    }
}
