<?php

namespace App\BusinessModules\Addons\FileManagement;

use App\Modules\Contracts\ModuleInterface;
use App\Modules\Contracts\ConfigurableInterface;
use App\Enums\ModuleType;
use App\Enums\BillingModel;

class FileManagementModule implements ModuleInterface, ConfigurableInterface
{
    public function getName(): string
    {
        return 'Управление файлами';
    }

    public function getSlug(): string
    {
        return 'file-management';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Система управления персональными файлами и отчетами';
    }

    public function getType(): ModuleType
    {
        return ModuleType::ADDON;
    }

    public function getBillingModel(): BillingModel
    {
        return BillingModel::SUBSCRIPTION;
    }

    public function getManifest(): array
    {
        return json_decode(file_get_contents(config_path('ModuleList/addons/file-management.json')), true);
    }

    public function install(): void
    {
        // Модуль использует существующие таблицы файлов
    }

    public function uninstall(): void
    {
        // Платный модуль можно отключить, данные сохраняются
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
            'personal_files.view',
            'personal_files.create_folder',
            'personal_files.upload',
            'personal_files.delete',
            'report_files.view',
            'report_files.delete',
            'report_files.update'
        ];
    }

    public function getFeatures(): array
    {
        return [
            'Персональное файловое хранилище',
            'Создание папок и структуры',
            'Загрузка файлов различных форматов',
            'Удаление и управление файлами',
            'Управление файлами отчетов',
            'Просмотр метаданных файлов',
            'Контроль версий файлов',
            'Совместный доступ к файлам',
            'Резервное копирование',
            'Интеграция с облачными хранилищами'
        ];
    }

    public function getLimits(): array
    {
        return [
            'max_storage_gb' => 50,
            'max_file_size_mb' => 100,
            'max_files_per_user' => 1000,
            'retention_days' => 365
        ];
    }

    public function getDefaultSettings(): array
    {
        return [
            'storage_settings' => [
                'default_quota_gb' => 10,
                'max_file_size_mb' => 100,
                'allowed_extensions' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'png', 'zip'],
                'auto_cleanup_enabled' => true,
                'retention_days' => 365
            ],
            'security_settings' => [
                'scan_uploaded_files' => true,
                'encrypt_sensitive_files' => false,
                'require_approval_for_sharing' => false,
                'log_file_access' => true
            ],
            'backup_settings' => [
                'enable_auto_backup' => false,
                'backup_frequency_days' => 7,
                'backup_retention_months' => 3,
                'cloud_backup_enabled' => false
            ]
        ];
    }

    public function validateSettings(array $settings): bool
    {
        if (isset($settings['storage_settings']['max_file_size_mb']) && 
            (!is_numeric($settings['storage_settings']['max_file_size_mb']) || 
             $settings['storage_settings']['max_file_size_mb'] < 1)) {
            return false;
        }

        return true;
    }

    public function applySettings(int $organizationId, array $settings): void
    {
        if (!$this->validateSettings($settings)) {
            throw new \InvalidArgumentException('Некорректные настройки модуля управления файлами');
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
