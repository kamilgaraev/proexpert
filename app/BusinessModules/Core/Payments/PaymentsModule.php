<?php

namespace App\BusinessModules\Core\Payments;

use App\Enums\BillingModel;
use App\Enums\ModuleType;
use App\Modules\Contracts\ModuleInterface;

class PaymentsModule implements ModuleInterface
{
    public function getName(): string
    {
        return 'Платежи и взаиморасчеты';
    }

    public function getSlug(): string
    {
        return 'payments';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Базовая система управления счетами, платежами и взаиморасчетами';
    }

    public function getType(): ModuleType
    {
        return ModuleType::CORE;
    }

    public function getBillingModel(): BillingModel
    {
        return BillingModel::FREE;
    }

    public function getManifest(): array
    {
        $manifestPath = config_path('ModuleList/core/payments.json');
        
        if (!file_exists($manifestPath)) {
            return [];
        }

        return json_decode(file_get_contents($manifestPath), true) ?? [];
    }

    public function install(): void
    {
        // Системный модуль, миграции загружаются автоматически
    }

    public function uninstall(): void
    {
        // Системный модуль нельзя удалить
        throw new \RuntimeException('Системный модуль Payments нельзя удалить');
    }

    public function upgrade(string $fromVersion): void
    {
        // Логика обновления модуля
    }

    public function canActivate(int $organizationId): bool
    {
        // Системный модуль всегда активен
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
        return [
            'payments.view',
            'payments.invoice.create',
            'payments.invoice.edit',
            'payments.invoice.cancel',
            'payments.transaction.register',
            'payments.transaction.approve',
            'payments.reports.view',
        ];
    }

    public function getFeatures(): array
    {
        return [
            'Управление счетами (invoices)',
            'Регистрация платежей',
            'Частичные оплаты',
            'Графики платежей',
            'Взаиморасчеты в холдинге',
            'Дебиторская/кредиторская задолженность',
            'Автоматическая просрочка',
            'Уведомления о платежах',
        ];
    }

    public function getLimits(): array
    {
        return [];
    }
}

