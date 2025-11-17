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
            // Дашборд
            'payments.dashboard.view' => 'Просмотр финансового дашборда',
            
            // Счета (Invoices)
            'payments.invoice.view' => 'Просмотр счетов своих проектов',
            'payments.invoice.view_all' => 'Просмотр всех счетов организации',
            'payments.invoice.create' => 'Создание счетов',
            'payments.invoice.edit' => 'Редактирование счетов',
            'payments.invoice.delete' => 'Удаление счетов',
            'payments.invoice.cancel' => 'Отмена счетов',
            'payments.invoice.issue' => 'Выставление счетов (draft → issued)',
            'payments.invoice.export' => 'Экспорт счетов',
            
            // Транзакции (Payment Transactions)
            'payments.transaction.view' => 'Просмотр платежных транзакций',
            'payments.transaction.view_all' => 'Просмотр всех транзакций организации',
            'payments.transaction.register' => 'Регистрация платежей',
            'payments.transaction.edit' => 'Редактирование транзакций',
            'payments.transaction.delete' => 'Удаление транзакций',
            'payments.transaction.approve' => 'Утверждение платежей',
            'payments.transaction.reject' => 'Отклонение платежей',
            'payments.transaction.refund' => 'Возврат платежей',
            
            // Графики платежей (Payment Schedules)
            'payments.schedule.view' => 'Просмотр графиков платежей',
            'payments.schedule.create' => 'Создание графиков платежей',
            'payments.schedule.edit' => 'Редактирование графиков',
            'payments.schedule.delete' => 'Удаление графиков',
            
            // Счета контрагентов (Counterparty Accounts)
            'payments.counterparty_account.view' => 'Просмотр счетов контрагентов',
            'payments.counterparty_account.manage' => 'Управление счетами контрагентов',
            'payments.counterparty_account.reconcile' => 'Проведение взаиморасчетов',
            
            // Сверка (Reconciliation)
            'payments.reconciliation.view' => 'Просмотр актов сверки',
            'payments.reconciliation.perform' => 'Выполнение сверки',
            'payments.reconciliation.approve' => 'Утверждение сверки',
            
            // Отчеты
            'payments.reports.view' => 'Просмотр финансовых отчетов',
            'payments.reports.export' => 'Экспорт отчетов',
            'payments.reports.financial_analytics' => 'Финансовая аналитика и прогнозы',
            
            // Настройки
            'payments.settings.view' => 'Просмотр настроек модуля',
            'payments.settings.manage' => 'Управление настройками модуля',
        ];
    }
    
    /**
     * Получить права для конкретной роли
     */
    public function getPermissionsForRole(string $role): array
    {
        $manifest = $this->getManifest();
        $rolePermissions = $manifest['permission_roles'] ?? [];
        
        return $rolePermissions[$role] ?? [];
    }
    
    /**
     * Получить все доступные роли
     */
    public function getAvailableRoles(): array
    {
        $manifest = $this->getManifest();
        $rolePermissions = $manifest['permission_roles'] ?? [];
        
        return array_keys($rolePermissions);
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

