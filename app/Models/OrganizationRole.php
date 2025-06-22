<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class OrganizationRole extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'description',
        'permissions',
        'color',
        'is_active',
        'is_system',
        'display_order',
    ];

    protected $casts = [
        'permissions' => 'array',
        'is_active' => 'boolean',
        'is_system' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($role) {
            if (empty($role->slug)) {
                $role->slug = Str::slug($role->name);
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_role_user')
            ->withPivot('assigned_at', 'assigned_by_user_id')
            ->withTimestamps();
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions ?? []);
    }

    public function addPermission(string $permission): void
    {
        $permissions = $this->permissions ?? [];
        if (!in_array($permission, $permissions)) {
            $permissions[] = $permission;
            $this->permissions = $permissions;
        }
    }

    public function removePermission(string $permission): void
    {
        $permissions = $this->permissions ?? [];
        $this->permissions = array_values(array_filter($permissions, fn($p) => $p !== $permission));
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order')->orderBy('name');
    }

    public function getFormattedPermissionsAttribute(): array
    {
        $allPermissions = $this->getAllAvailablePermissions();
        $rolePermissions = $this->permissions ?? [];
        
        return collect($allPermissions)->map(function ($permission) use ($rolePermissions) {
            return [
                'slug' => $permission['slug'],
                'name' => $permission['name'],
                'description' => $permission['description'],
                'group' => $permission['group'],
                'granted' => in_array($permission['slug'], $rolePermissions),
            ];
        })->groupBy('group')->toArray();
    }

    public static function getAllAvailablePermissions(): array
    {
        return [
            [
                'slug' => 'users.view',
                'name' => 'Просмотр пользователей',
                'description' => 'Просмотр списка пользователей организации',
                'group' => 'Пользователи'
            ],
            [
                'slug' => 'users.create',
                'name' => 'Создание пользователей',
                'description' => 'Создание новых пользователей и отправка приглашений',
                'group' => 'Пользователи'
            ],
            [
                'slug' => 'users.edit',
                'name' => 'Редактирование пользователей',
                'description' => 'Редактирование данных пользователей',
                'group' => 'Пользователи'
            ],
            [
                'slug' => 'users.delete',
                'name' => 'Удаление пользователей',
                'description' => 'Удаление пользователей из организации',
                'group' => 'Пользователи'
            ],
            [
                'slug' => 'roles.view',
                'name' => 'Просмотр ролей',
                'description' => 'Просмотр списка ролей организации',
                'group' => 'Роли'
            ],
            [
                'slug' => 'roles.create',
                'name' => 'Создание ролей',
                'description' => 'Создание новых ролей',
                'group' => 'Роли'
            ],
            [
                'slug' => 'roles.edit',
                'name' => 'Редактирование ролей',
                'description' => 'Редактирование ролей и их разрешений',
                'group' => 'Роли'
            ],
            [
                'slug' => 'roles.delete',
                'name' => 'Удаление ролей',
                'description' => 'Удаление ролей организации',
                'group' => 'Роли'
            ],
            [
                'slug' => 'projects.view',
                'name' => 'Просмотр проектов',
                'description' => 'Просмотр списка проектов',
                'group' => 'Проекты'
            ],
            [
                'slug' => 'projects.create',
                'name' => 'Создание проектов',
                'description' => 'Создание новых проектов',
                'group' => 'Проекты'
            ],
            [
                'slug' => 'projects.edit',
                'name' => 'Редактирование проектов',
                'description' => 'Редактирование данных проектов',
                'group' => 'Проекты'
            ],
            [
                'slug' => 'projects.delete',
                'name' => 'Удаление проектов',
                'description' => 'Удаление проектов',
                'group' => 'Проекты'
            ],
            [
                'slug' => 'contracts.view',
                'name' => 'Просмотр договоров',
                'description' => 'Просмотр списка договоров',
                'group' => 'Договоры'
            ],
            [
                'slug' => 'contracts.create',
                'name' => 'Создание договоров',
                'description' => 'Создание новых договоров',
                'group' => 'Договоры'
            ],
            [
                'slug' => 'contracts.edit',
                'name' => 'Редактирование договоров',
                'description' => 'Редактирование данных договоров',
                'group' => 'Договоры'
            ],
            [
                'slug' => 'contracts.delete',
                'name' => 'Удаление договоров',
                'description' => 'Удаление договоров',
                'group' => 'Договоры'
            ],
            [
                'slug' => 'materials.view',
                'name' => 'Просмотр материалов',
                'description' => 'Просмотр каталога материалов',
                'group' => 'Материалы'
            ],
            [
                'slug' => 'materials.create',
                'name' => 'Создание материалов',
                'description' => 'Добавление новых материалов',
                'group' => 'Материалы'
            ],
            [
                'slug' => 'materials.edit',
                'name' => 'Редактирование материалов',
                'description' => 'Редактирование данных материалов',
                'group' => 'Материалы'
            ],
            [
                'slug' => 'materials.delete',
                'name' => 'Удаление материалов',
                'description' => 'Удаление материалов из каталога',
                'group' => 'Материалы'
            ],
            [
                'slug' => 'reports.view',
                'name' => 'Просмотр отчетов',
                'description' => 'Просмотр отчетов и аналитики',
                'group' => 'Отчеты'
            ],
            [
                'slug' => 'reports.export',
                'name' => 'Экспорт отчетов',
                'description' => 'Экспорт отчетов в различных форматах',
                'group' => 'Отчеты'
            ],
            [
                'slug' => 'finance.view',
                'name' => 'Просмотр финансов',
                'description' => 'Просмотр финансовых данных',
                'group' => 'Финансы'
            ],
            [
                'slug' => 'finance.manage',
                'name' => 'Управление финансами',
                'description' => 'Управление финансовыми операциями',
                'group' => 'Финансы'
            ],
            [
                'slug' => 'settings.view',
                'name' => 'Просмотр настроек',
                'description' => 'Просмотр настроек организации',
                'group' => 'Настройки'
            ],
            [
                'slug' => 'settings.edit',
                'name' => 'Редактирование настроек',
                'description' => 'Изменение настроек организации',
                'group' => 'Настройки'
            ],
        ];
    }
}
