<?php

namespace App\BusinessModules\Services\ReportTemplates;

use App\Modules\Contracts\ModuleInterface;
use App\Modules\Contracts\ConfigurableInterface;
use App\Enums\ModuleType;
use App\Enums\BillingModel;

class ReportTemplatesModule implements ModuleInterface, ConfigurableInterface
{
    public function getName(): string
    {
        return 'Шаблоны отчетов';
    }

    public function getSlug(): string
    {
        return 'report-templates';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Система управления шаблонами отчетов';
    }

    public function getType(): ModuleType
    {
        return ModuleType::SERVICE;
    }

    public function getBillingModel(): BillingModel
    {
        return BillingModel::ONE_TIME;
    }

    public function getManifest(): array
    {
        return json_decode(file_get_contents(config_path('ModuleList/services/report-templates.json')), true);
    }

    public function install(): void
    {
        // Разовый модуль предоставляет постоянную функциональность
    }

    public function uninstall(): void
    {
        // Разовые модули нельзя удалять после покупки
        throw new \Exception('Разовые модули нельзя удалять после покупки');
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
               $accessController->hasModuleAccess($organizationId, 'reports');
    }

    public function getDependencies(): array
    {
        return ['organizations', 'users', 'reports'];
    }

    public function getConflicts(): array
    {
        return [];
    }

    public function getPermissions(): array
    {
        return [
            'report_templates.view',
            'report_templates.create',
            'report_templates.edit',
            'report_templates.delete',
            'report_templates.set_default',
            'report_templates.export',
            'report_templates.import'
        ];
    }

    public function getFeatures(): array
    {
        return [
            'Создание кастомных шаблонов отчетов',
            'Редактирование существующих шаблонов',
            'Установка шаблонов по умолчанию',
            'Библиотека готовых шаблонов',
            'Экспорт шаблонов между организациями',
            'Импорт шаблонов из внешних источников',
            'Предварительный просмотр отчетов',
            'Версионирование шаблонов',
            'Совместное редактирование',
            'Интеграция со всеми модулями отчетности'
        ];
    }

    public function getLimits(): array
    {
        return [
            'max_templates' => 100,
            'max_template_size_mb' => 10,
            'version_history_count' => 10
        ];
    }

    public function getDefaultSettings(): array
    {
        return [
            'template_settings' => [
                'default_format' => 'xlsx',
                'enable_versioning' => true,
                'auto_save_drafts' => true,
                'preview_generation_enabled' => true,
                'max_template_size_mb' => 10
            ],
            'sharing_settings' => [
                'allow_template_sharing' => true,
                'require_approval_for_public' => true,
                'enable_collaborative_editing' => false,
                'template_library_access' => true
            ],
            'export_settings' => [
                'include_sample_data' => false,
                'export_with_dependencies' => true,
                'compress_exports' => true
            ]
        ];
    }

    public function validateSettings(array $settings): bool
    {
        if (isset($settings['template_settings']['max_template_size_mb']) && 
            (!is_numeric($settings['template_settings']['max_template_size_mb']) || 
             $settings['template_settings']['max_template_size_mb'] < 1)) {
            return false;
        }

        return true;
    }

    public function applySettings(int $organizationId, array $settings): void
    {
        if (!$this->validateSettings($settings)) {
            throw new \InvalidArgumentException('Некорректные настройки модуля шаблонов отчетов');
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
