<?php

namespace App\BusinessModules\Core\Users;

use App\Modules\Contracts\ModuleInterface;
use App\Modules\Contracts\ConfigurableInterface;
use App\Enums\ModuleType;
use App\Enums\BillingModel;

class UsersModule implements ModuleInterface, ConfigurableInterface
{
    public function getName(): string
    {
        return 'Управление пользователями';
    }

    public function getSlug(): string
    {
        return 'users';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Система управления пользователями и ролями';
    }

    public function getType(): ModuleType
    {
        return ModuleType::CORE;
    }

    public function getBillingModel(): BillingModel
    {
        return BillingModel::FREE;
    }

    public function getManifest(): array
    {
        return json_decode(file_get_contents(config_path('ModuleList/core/users.json')), true);
    }

    public function install(): void
    {
        // Модуль использует существующие таблицы users
    }

    public function uninstall(): void
    {
        // Системный модуль нельзя удалить
        throw new \Exception('Системный модуль не может быть удален');
    }

    public function upgrade(string $fromVersion): void
    {
        // Логика обновления модуля
    }

    public function canActivate(int $organizationId): bool
    {
        // Проверяем что модуль organizations активирован
        $accessController = app(\App\Modules\Core\AccessController::class);
        return $accessController->hasModuleAccess($organizationId, 'organizations');
    }

    public function getDependencies(): array
    {
        return ['organizations'];
    }

    public function getConflicts(): array
    {
        return [];
    }

    public function getPermissions(): array
    {
        return [
            'users.view',
            'users.create', 
            'users.edit',
            'users.delete',
            'users.roles',
            'users.permissions'
        ];
    }

    public function getFeatures(): array
    {
        return [
            'Создание пользователей',
            'Управление ролями', 
            'Система разрешений',
            'Авторизация и аутентификация'
        ];
    }

    public function getLimits(): array
    {
        return [];
    }

    // ConfigurableInterface
    public function getDefaultSettings(): array
    {
        return [
            'password_min_length' => 8,
            'require_strong_password' => true,
            'enable_two_factor' => false,
            'password_expiry_days' => 90,
            'max_login_attempts' => 5,
            'lockout_duration_minutes' => 15,
            'session_lifetime' => 120, // минуты
            'allow_multiple_sessions' => true,
            'role_settings' => [
                'allow_custom_roles' => true,
                'max_roles_per_organization' => 10,
                'default_role' => 'employee'
            ],
            'notification_settings' => [
                'welcome_email' => true,
                'password_reset_email' => true,
                'role_change_notification' => true
            ]
        ];
    }

    public function validateSettings(array $settings): bool
    {
        if (isset($settings['password_min_length']) && 
            (!is_int($settings['password_min_length']) || $settings['password_min_length'] < 6)) {
            return false;
        }

        if (isset($settings['max_login_attempts']) && 
            (!is_int($settings['max_login_attempts']) || $settings['max_login_attempts'] < 3)) {
            return false;
        }

        if (isset($settings['session_lifetime']) && 
            (!is_int($settings['session_lifetime']) || $settings['session_lifetime'] < 30)) {
            return false;
        }

        return true;
    }

    public function applySettings(int $organizationId, array $settings): void
    {
        if (!$this->validateSettings($settings)) {
            throw new \InvalidArgumentException('Некорректные настройки модуля пользователей');
        }

        $activation = \App\Models\OrganizationModuleActivation::where('organization_id', $organizationId)
            ->whereHas('module', function ($query) {
                $query->where('slug', $this->getSlug());
            })
            ->first();

        if ($activation) {
            $currentSettings = $activation->module_settings ?? [];
            $activation->update([
                'module_settings' => array_merge($currentSettings, $settings)
            ]);
        }
    }

    public function getSettings(int $organizationId): array
    {
        $activation = \App\Models\OrganizationModuleActivation::where('organization_id', $organizationId)
            ->whereHas('module', function ($query) {
                $query->where('slug', $this->getSlug());
            })
            ->first();

        if (!$activation) {
            return $this->getDefaultSettings();
        }

        return array_merge(
            $this->getDefaultSettings(),
            $activation->module_settings ?? []
        );
    }
}
