<?php

namespace App\BusinessModules\Features\ContractManagement;

use App\Modules\Contracts\ModuleInterface;
use App\Modules\Contracts\ConfigurableInterface;
use App\Enums\ModuleType;
use App\Enums\BillingModel;

class ContractManagementModule implements ModuleInterface, ConfigurableInterface
{
    public function getName(): string
    {
        return 'Управление контрактами';
    }

    public function getSlug(): string
    {
        return 'contract-management';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Система управления контрактами, соглашениями и спецификациями';
    }

    public function getType(): ModuleType
    {
        return ModuleType::FEATURE;
    }

    public function getBillingModel(): BillingModel
    {
        return BillingModel::FREE;
    }

    public function getManifest(): array
    {
        return json_decode(file_get_contents(config_path('ModuleList/features/contract-management.json')), true);
    }

    public function install(): void
    {
        // Модуль использует существующие таблицы контрактов
    }

    public function uninstall(): void
    {
        // Системный модуль нельзя удалить
        throw new \Exception('Системный модуль управления контрактами не может быть удален');
    }

    public function upgrade(string $fromVersion): void
    {
        // Логика обновления модуля
    }

    public function canActivate(int $organizationId): bool
    {
        // Проверяем что базовые модули активированы
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
            'contracts.view',
            'contracts.create',
            'contracts.edit', 
            'contracts.delete',
            'contracts.analytics',
            'contracts.completed_works.view',
            'contracts.performance_acts.view',
            'contracts.performance_acts.create',
            'contracts.performance_acts.edit',
            'contracts.performance_acts.delete',
            'contracts.performance_acts.export',
            'contracts.payments.view',
            'contracts.payments.create',
            'contracts.payments.edit',
            'contracts.payments.delete',
            'agreements.view',
            'agreements.create',
            'agreements.edit',
            'agreements.delete',
            'specifications.view',
            'specifications.create',
            'specifications.edit',
            'specifications.delete'
        ];
    }

    public function getFeatures(): array
    {
        return [
            'Создание и управление контрактами',
            'Детальная аналитика контрактов',
            'Просмотр выполненных работ',
            'Управление актами выполненных работ',
            'Экспорт актов в PDF и Excel',
            'Управление платежами по контрактам',
            'Дополнительные соглашения',
            'Спецификации к контрактам',
            'Полная детализация контрактов'
        ];
    }

    public function getLimits(): array
    {
        return [
            'max_contracts' => null,
            'max_agreements_per_contract' => 20,
            'max_specifications_per_contract' => 50
        ];
    }

    public function getDefaultSettings(): array
    {
        return [
            'contract_numbering' => [
                'auto_generate_numbers' => true,
                'number_prefix' => 'КТ',
                'year_in_number' => true,
                'reset_yearly' => true
            ],
            'workflow_settings' => [
                'require_approval' => false,
                'auto_create_acts' => false,
                'allow_retroactive_acts' => true,
                'performance_tracking' => true
            ],
            'notification_settings' => [
                'contract_created' => true,
                'contract_expiring' => true,
                'payment_due' => true,
                'act_created' => true,
                'agreement_added' => true
            ],
            'export_settings' => [
                'default_format' => 'pdf',
                'include_signatures' => true,
                'watermark_drafts' => true,
                'archive_exports' => true
            ],
            'payment_settings' => [
                'track_payment_schedule' => true,
                'alert_overdue_payments' => true,
                'auto_calculate_penalties' => false
            ]
        ];
    }

    public function validateSettings(array $settings): bool
    {
        if (isset($settings['max_agreements_per_contract']) && 
            (!is_int($settings['max_agreements_per_contract']) || $settings['max_agreements_per_contract'] < 1)) {
            return false;
        }

        if (isset($settings['max_specifications_per_contract']) && 
            (!is_int($settings['max_specifications_per_contract']) || $settings['max_specifications_per_contract'] < 1)) {
            return false;
        }

        $allowedFormats = ['pdf', 'excel', 'word'];
        if (isset($settings['export_settings']['default_format']) && 
            !in_array($settings['export_settings']['default_format'], $allowedFormats)) {
            return false;
        }

        return true;
    }

    public function applySettings(int $organizationId, array $settings): void
    {
        if (!$this->validateSettings($settings)) {
            throw new \InvalidArgumentException('Некорректные настройки модуля управления контрактами');
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
