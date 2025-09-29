<?php

namespace App\BusinessModules\Services\DataExport;

use App\Modules\Contracts\ModuleInterface;
use App\Modules\Contracts\ConfigurableInterface;
use App\Enums\ModuleType;
use App\Enums\BillingModel;

class DataExportModule implements ModuleInterface, ConfigurableInterface
{
    public function getName(): string
    {
        return 'Экспорт данных';
    }

    public function getSlug(): string
    {
        return 'data-export';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Экспорт данных сверх лимита тарифа';
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
        return json_decode(file_get_contents(config_path('ModuleList/services/data-export.json')), true);
    }

    public function install(): void
    {
        // Логика установки модуля экспорта
    }

    public function uninstall(): void
    {
        // Логика удаления модуля
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
            'export.unlimited',
            'export.advanced_formats',
            'export.custom_fields'
        ];
    }

    public function getFeatures(): array
    {
        return [
            'Экспорт без лимитов',
            'Расширенные форматы (PDF, Excel)',
            'Настраиваемые поля',
            'Массовый экспорт'
        ];
    }

    public function getLimits(): array
    {
        return [
            'max_records_per_export' => null,
            'concurrent_exports' => 3
        ];
    }

    public function getDefaultSettings(): array
    {
        return [
            'concurrent_exports' => 3,
            'auto_cleanup_enabled' => true,
            'export_formats' => ['csv', 'xlsx', 'pdf'],
            'compression_enabled' => true,
            'notification_enabled' => true
        ];
    }

    public function validateSettings(array $settings): bool
    {
        if (isset($settings['concurrent_exports']) && 
            (!is_int($settings['concurrent_exports']) || $settings['concurrent_exports'] < 1)) {
            return false;
        }
        
        return true;
    }

    public function applySettings(int $organizationId, array $settings): void
    {
        if (!$this->validateSettings($settings)) {
            throw new \InvalidArgumentException('Некорректные настройки модуля экспорта данных');
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
