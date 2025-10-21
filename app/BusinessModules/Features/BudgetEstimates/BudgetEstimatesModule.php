<?php

namespace App\BusinessModules\Features\BudgetEstimates;

use App\Modules\Contracts\ModuleInterface;
use App\Modules\Contracts\ConfigurableInterface;
use App\Enums\ModuleType;
use App\Enums\BillingModel;

class BudgetEstimatesModule implements ModuleInterface, ConfigurableInterface
{
    public function getName(): string
    {
        return 'Сметное дело';
    }

    public function getSlug(): string
    {
        return 'budget-estimates';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Полный цикл работы со сметами: создание, импорт из Excel, расчеты, версионирование и экспорт';
    }

    public function getType(): ModuleType
    {
        return ModuleType::FEATURE;
    }

    public function getBillingModel(): BillingModel
    {
        return BillingModel::SUBSCRIPTION;
    }

    public function getManifest(): array
    {
        return json_decode(file_get_contents(config_path('ModuleList/features/budget-estimates.json')), true);
    }

    public function install(): void
    {
        // Модуль использует существующие миграции
    }

    public function uninstall(): void
    {
        // Данные смет сохраняются при деактивации модуля
    }

    public function upgrade(string $fromVersion): void
    {
        // Логика обновления модуля
    }

    public function canActivate(int $organizationId): bool
    {
        $accessController = app(\App\Modules\Core\AccessController::class);
        return $accessController->hasModuleAccess($organizationId, 'organizations') &&
               $accessController->hasModuleAccess($organizationId, 'users') &&
               $accessController->hasModuleAccess($organizationId, 'project-management') &&
               $accessController->hasModuleAccess($organizationId, 'contract-management');
    }

    public function getDependencies(): array
    {
        return ['organizations', 'users', 'project-management', 'contract-management'];
    }

    public function getConflicts(): array
    {
        return [];
    }

    public function getPermissions(): array
    {
        return [
            'estimates.view',
            'estimates.view_all',
            'estimates.create',
            'estimates.edit',
            'estimates.edit_approved',
            'estimates.delete',
            'estimates.approve',
            'estimates.import',
            'estimates.export',
            'estimates.templates.manage',
            'estimates.analytics',
            'estimates.versions.create',
            'estimates.versions.compare',
        ];
    }

    public function getFeatures(): array
    {
        return [
            'Создание и управление сметами',
            'Импорт из Excel и других форматов',
            'Автоматические расчеты с коэффициентами',
            'Управление разделами и позициями',
            'Версионирование и история изменений',
            'Шаблоны смет',
            'Экспорт в Excel и PDF',
            'Интеграция с проектами и контрактами',
            'Аналитика и сравнение смет',
            'Умное сопоставление позиций при импорте',
        ];
    }

    public function getLimits(): array
    {
        return [
            'max_estimates' => null,
            'max_items_per_estimate' => 10000,
            'max_sections_per_estimate' => 500,
            'max_templates' => 50,
        ];
    }

    public function getDefaultSettings(): array
    {
        return [
            'estimate_settings' => [
                'auto_generate_numbers' => true,
                'number_template' => 'СМ-{year}-{number}',
                'default_vat_rate' => 20,
                'default_overhead_rate' => 15,
                'default_profit_rate' => 12,
                'require_approval' => true,
                'allow_editing_approved' => false,
            ],
            'import_settings' => [
                'auto_match_confidence_threshold' => 85,
                'auto_create_work_types' => false,
                'store_import_files' => true,
                'file_retention_days' => 90,
            ],
            'export_settings' => [
                'default_format' => 'excel',
                'include_justifications' => true,
                'watermark_drafts' => true,
            ],
            'calculation_settings' => [
                'round_precision' => 2,
                'recalculate_on_change' => true,
            ],
        ];
    }

    public function validateSettings(array $settings): bool
    {
        if (isset($settings['estimate_settings']['default_vat_rate'])) {
            $vat = $settings['estimate_settings']['default_vat_rate'];
            if (!is_numeric($vat) || $vat < 0 || $vat > 100) {
                return false;
            }
        }

        if (isset($settings['import_settings']['auto_match_confidence_threshold'])) {
            $threshold = $settings['import_settings']['auto_match_confidence_threshold'];
            if (!is_numeric($threshold) || $threshold < 0 || $threshold > 100) {
                return false;
            }
        }

        return true;
    }

    public function applySettings(int $organizationId, array $settings): void
    {
        if (!$this->validateSettings($settings)) {
            throw new \InvalidArgumentException('Некорректные настройки модуля смет');
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

