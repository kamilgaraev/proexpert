<?php

namespace App\BusinessModules\Services\DataFilters;

use App\Modules\Contracts\ModuleInterface;
use App\Modules\Contracts\ConfigurableInterface;
use App\Enums\ModuleType;
use App\Enums\BillingModel;

class DataFiltersModule implements ModuleInterface, ConfigurableInterface
{
    public function getName(): string
    {
        return 'Система фильтрации';
    }

    public function getSlug(): string
    {
        return 'data-filters';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Расширенная система фильтрации данных';
    }

    public function getType(): ModuleType
    {
        return ModuleType::SERVICE;
    }

    public function getBillingModel(): BillingModel
    {
        return BillingModel::FREE;
    }

    public function getManifest(): array
    {
        return json_decode(file_get_contents(config_path('ModuleList/services/data-filters.json')), true);
    }

    public function install(): void
    {
        // Базовый системный модуль автоматически активируется
    }

    public function uninstall(): void
    {
        // Системный модуль нельзя удалять
        throw new \Exception('Системный модуль нельзя удалять');
    }

    public function upgrade(string $fromVersion): void
    {
        // Логика обновления модуля
    }

    public function canActivate(int $organizationId): bool
    {
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
            'filters.contracts.view',
            'filters.completed_works.view',
            'filters.quick_stats.view',
            'filters.custom.create',
            'filters.custom.save',
            'filters.export'
        ];
    }

    public function getFeatures(): array
    {
        return [
            'Фильтрация контрактов по различным критериям',
            'Фильтрация выполненных работ',
            'Быстрая статистика по фильтрам',
            'Создание пользовательских фильтров',
            'Сохранение часто используемых фильтров',
            'Экспорт отфильтрованных данных',
            'Комбинированные фильтры',
            'Автоматическое применение фильтров',
            'Уведомления по условиям фильтров',
            'Интеграция со всеми модулями'
        ];
    }

    public function getLimits(): array
    {
        return [
            'max_saved_filters' => 50,
            'max_filter_conditions' => 20
        ];
    }

    public function getDefaultSettings(): array
    {
        return [
            'filter_settings' => [
                'enable_quick_filters' => true,
                'save_filter_history' => true,
                'auto_apply_saved_filters' => false,
                'show_filter_suggestions' => true,
                'max_saved_filters_per_user' => 50
            ],
            'export_settings' => [
                'include_filter_metadata' => true,
                'max_export_rows' => 50000,
                'allowed_formats' => ['csv', 'xlsx', 'json']
            ],
            'ui_settings' => [
                'show_advanced_filters' => true,
                'enable_filter_presets' => true,
                'show_filter_statistics' => true
            ]
        ];
    }

    public function validateSettings(array $settings): bool
    {
        if (isset($settings['filter_settings']['max_saved_filters_per_user']) && 
            (!is_int($settings['filter_settings']['max_saved_filters_per_user']) || 
             $settings['filter_settings']['max_saved_filters_per_user'] < 1)) {
            return false;
        }

        return true;
    }

    public function applySettings(int $organizationId, array $settings): void
    {
        if (!$this->validateSettings($settings)) {
            throw new \InvalidArgumentException('Некорректные настройки модуля системы фильтрации');
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
