<?php

namespace App\BusinessModules\Addons\Integrations;

use App\Modules\Contracts\ModuleInterface;
use App\Modules\Contracts\ConfigurableInterface;
use App\Modules\Contracts\BillableInterface;
use App\Enums\ModuleType;
use App\Enums\BillingModel;

class IntegrationsModule implements ModuleInterface, ConfigurableInterface, BillableInterface
{
    public function getName(): string
    {
        return 'Интеграции';
    }

    public function getSlug(): string
    {
        return 'integrations';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Доступ к интеграциям с внешними сервисами';
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
        return json_decode(file_get_contents(config_path('ModuleList/addons/integrations.json')), true);
    }

    public function install(): void
    {
        // Логика установки модуля интеграций
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
            'integrations.1c',
            'integrations.crm', 
            'integrations.webhooks',
            'integrations.api',
            'integrations.export',
            'integrations.import'
        ];
    }

    public function getFeatures(): array
    {
        return [
            'Интеграция с 1С',
            'Интеграция с CRM', 
            'Webhooks',
            'API доступ',
            'Автоматический экспорт данных',
            'Импорт из внешних систем'
        ];
    }

    public function getLimits(): array
    {
        return [
            'api_requests_per_month' => 10000,
            'webhook_endpoints' => 5,
            'integrations_count' => 10
        ];
    }

    public function getDefaultSettings(): array
    {
        return [
            'api_rate_limit' => 1000,
            'webhook_retry_attempts' => 3,
            'integration_timeout' => 30,
            'enable_logging' => true,
            'auto_sync_interval' => 3600
        ];
    }

    public function validateSettings(array $settings): bool
    {
        if (isset($settings['api_rate_limit']) && 
            (!is_int($settings['api_rate_limit']) || $settings['api_rate_limit'] < 1)) {
            return false;
        }
        
        return true;
    }

    public function applySettings(int $organizationId, array $settings): void
    {
        if (!$this->validateSettings($settings)) {
            throw new \InvalidArgumentException('Некорректные настройки модуля интеграций');
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

    public function getPrice(): float
    {
        $manifest = $this->getManifest();
        return $manifest['pricing']['base_price'] ?? 2900;
    }

    public function getCurrency(): string
    {
        $manifest = $this->getManifest();
        return $manifest['pricing']['currency'] ?? 'RUB';
    }

    public function getDurationDays(): int
    {
        $manifest = $this->getManifest();
        return $manifest['pricing']['duration_days'] ?? 30;
    }

    public function getPricingConfig(): array
    {
        $manifest = $this->getManifest();
        return $manifest['pricing'] ?? [];
    }

    public function calculateCost(int $organizationId): float
    {
        return $this->getPrice();
    }

    public function canAfford(int $organizationId): bool
    {
        return true;
    }
}
