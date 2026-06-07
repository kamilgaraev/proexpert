<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\OneCBasicExchange;

use App\Enums\BillingModel;
use App\Enums\ModuleType;
use App\Modules\Contracts\BillableInterface;
use App\Modules\Contracts\ConfigurableInterface;
use App\Modules\Contracts\ModuleInterface;

final class OneCBasicExchangeModule implements ModuleInterface, ConfigurableInterface, BillableInterface
{
    public function getName(): string
    {
        return '1C: базовый обмен';
    }

    public function getSlug(): string
    {
        return 'one-c-basic-exchange';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Бесплатный ручной обмен справочниками и документами между ProHelper и 1C.';
    }

    public function getType(): ModuleType
    {
        return ModuleType::ADDON;
    }

    public function getBillingModel(): BillingModel
    {
        return BillingModel::FREE;
    }

    public function getManifest(): array
    {
        return json_decode(file_get_contents(config_path('ModuleList/addons/one-c-basic-exchange.json')), true);
    }

    public function install(): void
    {
    }

    public function uninstall(): void
    {
    }

    public function upgrade(string $fromVersion): void
    {
    }

    public function canActivate(int $organizationId): bool
    {
        return true;
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
        return $this->getManifest()['permissions'] ?? [];
    }

    public function getFeatures(): array
    {
        return $this->getManifest()['features'] ?? [];
    }

    public function getLimits(): array
    {
        return $this->getManifest()['limits'] ?? [];
    }

    public function getDefaultSettings(): array
    {
        return [
            'enabled_scopes' => [
                'counterparties',
                'employees',
                'organizations',
                'projects',
                'contracts',
                'materials',
                'nomenclature',
                'cost_categories',
                'cost_centers',
                'warehouses',
                'acts',
                'payment_documents',
                'advance_transactions',
                'procurement_documents',
                'warehouse_documents',
            ],
            'manual_only' => true,
        ];
    }

    public function validateSettings(array $settings): bool
    {
        return true;
    }

    public function applySettings(int $organizationId, array $settings): void
    {
    }

    public function getSettings(int $organizationId): array
    {
        return $this->getDefaultSettings();
    }

    public function getPrice(): float
    {
        return 0.0;
    }

    public function getCurrency(): string
    {
        return 'RUB';
    }

    public function getDurationDays(): int
    {
        return 0;
    }

    public function getPricingConfig(): array
    {
        return $this->getManifest()['pricing'] ?? [];
    }

    public function calculateCost(int $organizationId): float
    {
        return 0.0;
    }

    public function canAfford(int $organizationId): bool
    {
        return true;
    }
}
