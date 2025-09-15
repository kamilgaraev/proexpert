<?php

namespace App\BusinessModules\Core\MultiOrganization;

use App\Modules\Contracts\ModuleInterface;
use App\Modules\Contracts\BillableInterface;
use App\Modules\Contracts\ConfigurableInterface;
use App\Enums\ModuleType;
use App\Enums\BillingModel;

class MultiOrganizationModule implements ModuleInterface, BillableInterface, ConfigurableInterface
{
    public function getName(): string
    {
        return 'Мультиорганизация';
    }

    public function getSlug(): string
    {
        return 'multi-organization';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Система управления холдингами и дочерними организациями';
    }

    public function getType(): ModuleType
    {
        return ModuleType::CORE;
    }

    public function getBillingModel(): BillingModel
    {
        return BillingModel::SUBSCRIPTION;
    }

    public function getManifest(): array
    {
        return json_decode(file_get_contents(config_path('ModuleList/core/multi-organization.json')), true);
    }

    public function install(): void
    {
        // Логика установки модуля
        // В данном случае модуль использует существующие таблицы
    }

    public function uninstall(): void
    {
        // Логика удаления модуля
        // Осторожно с данными - они могут быть критичными
    }

    public function upgrade(string $fromVersion): void
    {
        // Логика обновления модуля
    }

    public function canActivate(int $organizationId): bool
    {
        $organization = \App\Models\Organization::find($organizationId);
        
        if (!$organization) {
            return false;
        }

        // Проверяем что организация не является дочерней
        return !$organization->parent_organization_id;
    }

    public function getDependencies(): array
    {
        return ['organizations', 'users'];
    }

    public function getConflicts(): array
    {
        return [];
    }

    public function getPermissions(): array
    {
        return [
            'multi-organization.view',
            'multi-organization.manage',
            'multi-organization.create_holding',
            'multi-organization.manage_children',
            'multi-organization.add_child_organization',
            'multi-organization.edit_child_organization', 
            'multi-organization.delete_child_organization',
            'multi-organization.manage_child_users',
            'multi-organization.view_hierarchy',
            'multi-organization.dashboard',
            'multi-organization.settings',
            'multi-organization.reports.view',
            'multi-organization.reports.dashboard',
            'multi-organization.reports.financial',
            'multi-organization.reports.kpi',
            'multi-organization.reports.comparison',
            'multi-organization.reports.export',
            'multi-organization.cache.clear',
            'multi-organization.website.view',
            'multi-organization.website.manage',
            'multi-organization.website.create',
            'multi-organization.website.edit',
            'multi-organization.website.publish',
            'multi-organization.website.delete',
            'multi-organization.website.assets.upload',
            'multi-organization.website.assets.manage',
            'multi-organization.website.templates.access'
        ];
    }

    // BillableInterface
    public function getPrice(): float
    {
        return 5900.0;
    }

    public function getCurrency(): string
    {
        return 'RUB';
    }

    public function getDurationDays(): int
    {
        return 30;
    }

    public function getPricingConfig(): array
    {
        return [
            'base_price' => 5900,
            'currency' => 'RUB',
            'included_in_plan' => false,
            'duration_days' => 30
        ];
    }

    // ConfigurableInterface
    public function getDefaultSettings(): array
    {
        return [
            'max_child_organizations' => 10,
            'auto_approve_child_users' => false,
            'inherit_parent_permissions' => true,
            'allow_cross_organization_access' => true,
            'consolidate_reporting' => true,
            'child_organization_prefix' => '',
            'notification_settings' => [
                'notify_on_child_added' => true,
                'notify_on_user_added' => true,
                'notify_on_project_created' => false
            ]
        ];
    }

    public function validateSettings(array $settings): bool
    {
        $requiredKeys = ['max_child_organizations'];
        
        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $settings)) {
                return false;
            }
        }

        if (!is_int($settings['max_child_organizations']) || $settings['max_child_organizations'] < 1) {
            return false;
        }

        return true;
    }

    public function getFeatures(): array
    {
        return [
            'Создание холдингов',
            'Управление дочерними организациями',
            'Иерархия доступа',
            'Дашборд холдинга',
            'Управление пользователями дочерних организаций',
            'Права доступа между организациями',
            'Консолидированная отчетность'
        ];
    }

    public function getLimits(): array
    {
        return [
            'max_child_organizations' => 50,
            'max_hierarchy_levels' => 3,
            'max_users_per_child' => 100
        ];
    }

    public function calculateCost(int $organizationId): float
    {
        return $this->getPrice();
    }

    public function canAfford(int $organizationId): bool
    {
        $organization = \App\Models\Organization::find($organizationId);
        
        if (!$organization) {
            return false;
        }

        $billingEngine = app(\App\Modules\Core\BillingEngine::class);
        $module = \App\Models\Module::where('slug', $this->getSlug())->first();
        
        return $module ? $billingEngine->canAfford($organization, $module) : false;
    }

    public function applySettings(int $organizationId, array $settings): void
    {
        if (!$this->validateSettings($settings)) {
            throw new \InvalidArgumentException('Некорректные настройки модуля');
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
