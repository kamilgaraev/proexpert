<?php

namespace App\BusinessModules\Features\Notifications;

use App\Modules\Contracts\ModuleInterface;
use App\Modules\Contracts\BillableInterface;
use App\Enums\ModuleType;
use App\Enums\BillingModel;

class NotificationModule implements ModuleInterface, BillableInterface
{
    public function getName(): string
    {
        return 'Система уведомлений';
    }

    public function getSlug(): string
    {
        return 'notifications';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Унифицированная система уведомлений с поддержкой Email, Telegram, In-App Push, шаблонов и аналитики';
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
        return [
            'name' => $this->getName(),
            'slug' => $this->getSlug(),
            'version' => $this->getVersion(),
            'description' => $this->getDescription(),
            'type' => $this->getType()->value,
            'billing_model' => $this->getBillingModel()->value,
            'features' => $this->getFeatures(),
            'permissions' => $this->getPermissions(),
        ];
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
        return [
            'organizations',
            'users',
        ];
    }

    public function getConflicts(): array
    {
        return [];
    }

    public function getPermissions(): array
    {
        return [
            'notifications.view',
            'notifications.manage_preferences',
            'notifications.manage_templates',
            'notifications.view_analytics',
            'notifications.manage_webhooks',
        ];
    }

    public function getFeatures(): array
    {
        return [
            'Multi-channel delivery (Email, Telegram, In-App, WebSocket)',
            'Customizable templates with variables',
            'User preferences management',
            'Analytics and tracking',
            'Webhooks for integrations',
            'Priority queues',
            'Rate limiting',
            'Quiet hours',
        ];
    }

    public function getLimits(): array
    {
        return [
            'max_notifications_per_hour' => 100,
            'max_notifications_per_day' => 500,
            'max_templates_per_organization' => 50,
            'max_webhooks_per_organization' => 10,
            'data_retention_days' => 90,
        ];
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
        return 30;
    }

    public function getPricingConfig(): array
    {
        return [
            'base_price' => 0,
            'currency' => 'RUB',
            'included_in_plans' => ['all'],
            'duration_days' => 30,
            'trial_days' => 0,
            'is_core_feature' => true,
        ];
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

