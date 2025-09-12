<?php

namespace App\BusinessModules\Core\Organizations;

use App\Modules\Contracts\ModuleInterface;
use App\Modules\Contracts\ConfigurableInterface;
use App\Enums\ModuleType;
use App\Enums\BillingModel;

class OrganizationsModule implements ModuleInterface, ConfigurableInterface
{
    public function getName(): string
    {
        return 'Управление организациями';
    }

    public function getSlug(): string
    {
        return 'organizations';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Базовая функциональность для управления организациями';
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
        return json_decode(file_get_contents(config_path('ModuleList/core/organizations.json')), true);
    }

    public function install(): void
    {
        // Модуль использует существующие таблицы organizations
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
        // Системный модуль должен быть активен всегда
        return true;
    }

    public function getDependencies(): array
    {
        return [];
    }

    public function getConflicts(): array
    {
        return [];
    }

    public function getPermissions(): array
    {
        return [
            'organizations.view',
            'organizations.create', 
            'organizations.edit',
            'organizations.delete',
            'organizations.settings'
        ];
    }

    public function getFeatures(): array
    {
        return [
            'Создание организаций',
            'Управление настройками', 
            'Базовая конфигурация'
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
            'allow_public_registration' => false,
            'require_email_verification' => true,
            'default_timezone' => 'Europe/Moscow',
            'default_currency' => 'RUB',
            'max_users_per_organization' => 100,
            'enable_api_access' => true,
            'session_timeout' => 480, // минуты
            'organization_settings' => [
                'allow_logo_upload' => true,
                'allow_custom_branding' => false,
                'require_tax_number' => true
            ]
        ];
    }

    public function validateSettings(array $settings): bool
    {
        if (isset($settings['max_users_per_organization']) && 
            (!is_int($settings['max_users_per_organization']) || $settings['max_users_per_organization'] < 1)) {
            return false;
        }

        if (isset($settings['session_timeout']) && 
            (!is_int($settings['session_timeout']) || $settings['session_timeout'] < 30)) {
            return false;
        }

        return true;
    }

    public function applySettings(int $organizationId, array $settings): void
    {
        if (!$this->validateSettings($settings)) {
            throw new \InvalidArgumentException('Некорректные настройки модуля организаций');
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
