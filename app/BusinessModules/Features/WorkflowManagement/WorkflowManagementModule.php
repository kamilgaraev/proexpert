<?php

namespace App\BusinessModules\Features\WorkflowManagement;

use App\Modules\Contracts\ModuleInterface;
use App\Modules\Contracts\ConfigurableInterface;
use App\Enums\ModuleType;
use App\Enums\BillingModel;

class WorkflowManagementModule implements ModuleInterface, ConfigurableInterface
{
    public function getName(): string
    {
        return 'Управление рабочими процессами';
    }

    public function getSlug(): string
    {
        return 'workflow-management';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Система управления заявками и выполненными работами';
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
        return json_decode(file_get_contents(config_path('ModuleList/features/workflow-management.json')), true);
    }

    public function install(): void
    {
        // Модуль использует существующие таблицы заявок и выполненных работ
    }

    public function uninstall(): void
    {
        // Модуль можно отключить, данные сохраняются
    }

    public function upgrade(string $fromVersion): void
    {
        // Логика обновления модуля
    }

    public function canActivate(int $organizationId): bool
    {
        // Проверяем что необходимые модули активированы
        $accessController = app(\App\Modules\Core\AccessController::class);
        return $accessController->hasModuleAccess($organizationId, 'organizations') &&
               $accessController->hasModuleAccess($organizationId, 'users') &&
               $accessController->hasModuleAccess($organizationId, 'project-management') &&
               $accessController->hasModuleAccess($organizationId, 'catalog-management');
    }

    public function getDependencies(): array
    {
        return ['organizations', 'users', 'project-management', 'catalog-management'];
    }

    public function getConflicts(): array
    {
        return [];
    }

    public function getPermissions(): array
    {
        return [
            'site_requests.view',
            'site_requests.create',
            'site_requests.edit',
            'site_requests.delete',
            'site_requests.files.upload',
            'site_requests.files.delete',
            'site_requests.statistics',
            'completed_works.view',
            'completed_works.create',
            'completed_works.edit',
            'completed_works.delete', 
            'completed_works.materials.sync',
            'completed_works.work_type_defaults.view'
        ];
    }

    public function getFeatures(): array
    {
        return [
            'Управление заявками с объектов',
            'Заявки на персонал',
            'Прикрепление файлов к заявкам',
            'Статистика по заявкам',
            'Управление выполненными работами',
            'Синхронизация материалов с работами', 
            'Просмотр дефолтных материалов по типам работ',
            'Фильтрация и поиск',
            'Уведомления по заявкам'
        ];
    }

    public function getLimits(): array
    {
        return [
            'max_site_requests_per_month' => 500,
            'max_completed_works_per_month' => 1000,
            'max_file_size_mb' => 50,
            'max_files_per_request' => 10
        ];
    }

    public function getDefaultSettings(): array
    {
        return [
            'request_settings' => [
                'auto_assign_requests' => false,
                'require_approval' => true,
                'allow_priority_override' => false,
                'enable_deadline_tracking' => true,
                'auto_close_completed' => false
            ],
            'file_settings' => [
                'allowed_extensions' => ['pdf', 'jpg', 'png', 'doc', 'docx', 'xls', 'xlsx'],
                'max_file_size_mb' => 50,
                'max_files_per_request' => 10,
                'auto_compress_images' => true,
                'store_in_cloud' => true
            ],
            'notification_settings' => [
                'new_request_created' => true,
                'request_assigned' => true,
                'request_status_changed' => true,
                'work_completed' => true,
                'deadline_approaching' => true,
                'email_notifications' => true,
                'sms_notifications' => false
            ],
            'workflow_settings' => [
                'enable_request_routing' => true,
                'auto_sync_materials' => true,
                'track_work_duration' => true,
                'require_completion_photos' => false,
                'enable_quality_control' => false
            ],
            'analytics_settings' => [
                'track_response_time' => true,
                'track_completion_time' => true,
                'generate_daily_reports' => false,
                'retention_period_days' => 365
            ]
        ];
    }

    public function validateSettings(array $settings): bool
    {
        if (isset($settings['file_settings']['max_file_size_mb']) && 
            (!is_numeric($settings['file_settings']['max_file_size_mb']) || 
             $settings['file_settings']['max_file_size_mb'] < 1 || 
             $settings['file_settings']['max_file_size_mb'] > 100)) {
            return false;
        }

        if (isset($settings['file_settings']['max_files_per_request']) && 
            (!is_int($settings['file_settings']['max_files_per_request']) || 
             $settings['file_settings']['max_files_per_request'] < 1 || 
             $settings['file_settings']['max_files_per_request'] > 50)) {
            return false;
        }

        if (isset($settings['analytics_settings']['retention_period_days']) && 
            (!is_int($settings['analytics_settings']['retention_period_days']) || 
             $settings['analytics_settings']['retention_period_days'] < 30)) {
            return false;
        }

        return true;
    }

    public function applySettings(int $organizationId, array $settings): void
    {
        if (!$this->validateSettings($settings)) {
            throw new \InvalidArgumentException('Некорректные настройки модуля управления рабочими процессами');
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
