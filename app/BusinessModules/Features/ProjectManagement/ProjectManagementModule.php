<?php

namespace App\BusinessModules\Features\ProjectManagement;

use App\Modules\Contracts\ModuleInterface;
use App\Modules\Contracts\ConfigurableInterface;
use App\Enums\ModuleType;
use App\Enums\BillingModel;

class ProjectManagementModule implements ModuleInterface, ConfigurableInterface
{
    public function getName(): string
    {
        return 'Управление проектами';
    }

    public function getSlug(): string
    {
        return 'project-management';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Основная система управления строительными проектами';
    }

    public function getType(): ModuleType
    {
        return ModuleType::FEATURE;
    }

    public function getBillingModel(): BillingModel
    {
        return BillingModel::FREE;
    }

    public function getManifest(): array
    {
        return json_decode(file_get_contents(config_path('ModuleList/features/project-management.json')), true);
    }

    public function install(): void
    {
        // Модуль использует существующие таблицы проектов
    }

    public function uninstall(): void
    {
        // Системный модуль нельзя удалить
        throw new \Exception('Системный модуль управления проектами не может быть удален');
    }

    public function upgrade(string $fromVersion): void
    {
        // Логика обновления модуля
    }

    public function canActivate(int $organizationId): bool
    {
        // Проверяем что базовые модули активированы
        $accessController = app(\App\Modules\Core\AccessController::class);
        return $accessController->hasModuleAccess($organizationId, 'organizations') &&
               $accessController->hasModuleAccess($organizationId, 'users');
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
            'projects.view',
            'projects.create', 
            'projects.edit',
            'projects.delete',
            'projects.archive',
            'projects.analytics',
            'projects.foremen.assign',
            'projects.foremen.detach',
            'projects.materials.view',
            'projects.work_types.view',
            'projects.organizations.manage',
            'projects.child_works.view',
            'projects.statistics'
        ];
    }

    public function getFeatures(): array
    {
        return [
            'Создание и управление проектами',
            'Назначение прорабов на проекты', 
            'Управление материалами проекта',
            'Управление типами работ',
            'Связь с организациями',
            'Аналитика по проектам',
            'Детализация работ дочерних организаций',
            'Статистика и отчетность'
        ];
    }

    public function getLimits(): array
    {
        return [
            'max_projects' => null,
            'max_foremen_per_project' => 10,
            'max_organizations_per_project' => 5
        ];
    }

    public function getDefaultSettings(): array
    {
        return [
            'auto_assign_foreman' => false,
            'allow_multiple_foremen' => true,
            'project_status_tracking' => true,
            'budget_control_enabled' => true,
            'analytics_retention_days' => 365,
            'notification_settings' => [
                'project_created' => true,
                'foreman_assigned' => true,
                'budget_exceeded' => true,
                'status_changed' => true
            ],
            'project_settings' => [
                'allow_project_archiving' => true,
                'require_budget_approval' => false,
                'auto_calculate_completion' => true,
                'enable_child_organizations' => true
            ],
            'material_settings' => [
                'sync_with_catalog' => true,
                'track_material_usage' => true,
                'alert_on_shortage' => true
            ]
        ];
    }

    public function validateSettings(array $settings): bool
    {
        if (isset($settings['max_foremen_per_project']) && 
            (!is_int($settings['max_foremen_per_project']) || $settings['max_foremen_per_project'] < 1)) {
            return false;
        }

        if (isset($settings['analytics_retention_days']) && 
            (!is_int($settings['analytics_retention_days']) || $settings['analytics_retention_days'] < 30)) {
            return false;
        }

        if (isset($settings['max_organizations_per_project']) && 
            (!is_int($settings['max_organizations_per_project']) || $settings['max_organizations_per_project'] < 1)) {
            return false;
        }

        return true;
    }

    public function applySettings(int $organizationId, array $settings): void
    {
        if (!$this->validateSettings($settings)) {
            throw new \InvalidArgumentException('Некорректные настройки модуля управления проектами');
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
