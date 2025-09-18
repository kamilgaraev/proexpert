<?php

namespace App\BusinessModules\Addons\AdvanceAccounting;

use App\Modules\Contracts\ModuleInterface;
use App\Modules\Contracts\ConfigurableInterface;
use App\Enums\ModuleType;
use App\Enums\BillingModel;

class AdvanceAccountingModule implements ModuleInterface, ConfigurableInterface
{
    public function getName(): string
    {
        return 'Подотчетные средства';
    }

    public function getSlug(): string
    {
        return 'advance-accounting';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Система управления подотчетными средствами и авансовыми отчетами';
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
        return json_decode(file_get_contents(config_path('ModuleList/addons/advance-accounting.json')), true);
    }

    public function install(): void
    {
        // Модуль использует существующие таблицы подотчетных средств
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
        // Проверяем что необходимые модули активированы
        $accessController = app(\App\Modules\Core\AccessController::class);
        return $accessController->hasModuleAccess($organizationId, 'organizations') &&
               $accessController->hasModuleAccess($organizationId, 'users') &&
               $accessController->hasModuleAccess($organizationId, 'project-management');
    }

    public function getDependencies(): array
    {
        return ['organizations', 'users', 'project-management'];
    }

    public function getConflicts(): array
    {
        return [];
    }

    public function getPermissions(): array
    {
        return [
            'advance_transactions.view',
            'advance_transactions.create',
            'advance_transactions.edit',
            'advance_transactions.delete',
            'advance_transactions.report',
            'advance_transactions.approve',
            'advance_transactions.files.manage',
            'advance_settings.view',
            'advance_settings.edit',
            'users.advance_balance.view',
            'users.advance_transactions.view',
            'users.funds.issue',
            'users.funds.return',
            'reports.advance_accounts.view',
            'reports.advance_accounts.export',
            'accounting.integration.manage'
        ];
    }

    public function getFeatures(): array
    {
        return [
            'Управление подотчетными средствами',
            'Авансовые отчеты сотрудников',
            'Одобрение и учет транзакций',
            'Прикрепление документов к операциям',
            'Настройки подотчетных счетов',
            'Контроль балансов пользователей',
            'Выдача и возврат средств',
            'Отчетность по подотчетным средствам',
            'Экспорт в Excel и PDF',
            'Интеграция с бухгалтерскими системами',
            'Отчеты по просроченным средствам',
            'Импорт пользователей и проектов'
        ];
    }

    public function getLimits(): array
    {
        return [
            'max_transactions_per_month' => 1000,
            'max_users_with_advance' => 100,
            'max_file_size_mb' => 25,
            'max_files_per_transaction' => 5
        ];
    }

    public function getDefaultSettings(): array
    {
        return [
            'transaction_settings' => [
                'require_approval' => true,
                'auto_approve_limit' => 10000,
                'allow_negative_balance' => false,
                'max_advance_amount' => 100000,
                'require_receipts' => true
            ],
            'notification_settings' => [
                'notify_on_transaction_created' => true,
                'notify_on_approval_required' => true,
                'notify_on_balance_low' => true,
                'notify_on_overdue' => true,
                'email_notifications' => true,
                'sms_notifications' => false
            ],
            'reporting_settings' => [
                'generate_monthly_reports' => true,
                'include_personal_data' => false,
                'auto_export_format' => 'excel',
                'retention_period_months' => 36
            ],
            'integration_settings' => [
                'enable_1c_sync' => false,
                'enable_sbis_sync' => false,
                'sync_frequency_hours' => 24,
                'auto_create_accounting_entries' => false
            ],
            'security_settings' => [
                'encrypt_financial_data' => true,
                'require_two_factor_for_approval' => false,
                'log_all_operations' => true,
                'restrict_ip_access' => false
            ]
        ];
    }

    public function validateSettings(array $settings): bool
    {
        if (isset($settings['transaction_settings']['auto_approve_limit']) && 
            (!is_numeric($settings['transaction_settings']['auto_approve_limit']) || 
             $settings['transaction_settings']['auto_approve_limit'] < 0)) {
            return false;
        }

        if (isset($settings['transaction_settings']['max_advance_amount']) && 
            (!is_numeric($settings['transaction_settings']['max_advance_amount']) || 
             $settings['transaction_settings']['max_advance_amount'] < 1000)) {
            return false;
        }

        if (isset($settings['integration_settings']['sync_frequency_hours']) && 
            (!is_int($settings['integration_settings']['sync_frequency_hours']) || 
             $settings['integration_settings']['sync_frequency_hours'] < 1)) {
            return false;
        }

        return true;
    }

    public function applySettings(int $organizationId, array $settings): void
    {
        if (!$this->validateSettings($settings)) {
            throw new \InvalidArgumentException('Некорректные настройки модуля подотчетных средств');
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
