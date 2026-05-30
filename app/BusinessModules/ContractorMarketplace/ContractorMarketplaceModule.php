<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace;

use App\Enums\BillingModel;
use App\Enums\ModuleType;
use App\Modules\Contracts\ModuleInterface;

class ContractorMarketplaceModule implements ModuleInterface
{
    public function getName(): string
    {
        return 'Marketplace подрядчиков';
    }

    public function getSlug(): string
    {
        return 'contractor-marketplace';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Закрытый каталог подрядчиков, профили, категории работ, рейтинги и проектный найм.';
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
        return [];
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
        return ['contractor-portal', 'project-management'];
    }

    public function getConflicts(): array
    {
        return [];
    }

    public function getPermissions(): array
    {
        return [
            'contractor_marketplace.categories.view',
            'contractor_marketplace.search.view',
            'contractor_marketplace.profile.view',
            'contractor_marketplace.profile.edit',
            'contractor_marketplace.profile.publish',
            'contractor_marketplace.offers.view',
            'contractor_marketplace.offers.create',
            'contractor_marketplace.offers.cancel',
            'contractor_marketplace.offers.review',
            'contractor_marketplace.offers.respond',
        ];
    }

    public function getFeatures(): array
    {
        return [
            'Категории работ подрядчиков',
            'Профили подрядчиков marketplace',
            'Публикация в закрытый каталог',
            'Оферы найма подрядчиков в проект',
        ];
    }

    public function getLimits(): array
    {
        return [];
    }
}
