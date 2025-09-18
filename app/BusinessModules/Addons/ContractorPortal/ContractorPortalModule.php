<?php

namespace App\BusinessModules\Addons\ContractorPortal;

use App\Modules\Contracts\ModuleInterface;
use App\Modules\Contracts\ConfigurableInterface;
use App\Enums\ModuleType;
use App\Enums\BillingModel;

class ContractorPortalModule implements ModuleInterface, ConfigurableInterface
{
    public function getName(): string
    {
        return 'Портал подрядчиков';
    }

    public function getSlug(): string
    {
        return 'contractor-portal';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Система работы с внешними подрядчиками и приглашений';
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
        return json_decode(file_get_contents(config_path('ModuleList/addons/contractor-portal.json')), true);
    }

    public function install(): void
    {
        // Модуль использует существующие таблицы приглашений подрядчиков
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
               $accessController->hasModuleAccess($organizationId, 'catalog-management');
    }

    public function getDependencies(): array
    {
        return ['organizations', 'users', 'catalog-management'];
    }

    public function getConflicts(): array
    {
        return [];
    }

    public function getPermissions(): array
    {
        return [
            'contractor_invitations.view',
            'contractor_invitations.create',
            'contractor_invitations.edit',
            'contractor_invitations.delete',
            'contractor_invitations.stats',
            'contractor_invitations.cancel',
            'organizations.search',
            'organizations.suggestions',
            'organizations.recommendations',
            'organizations.availability.check'
        ];
    }

    public function getFeatures(): array
    {
        return [
            'Управление приглашениями подрядчиков',
            'Просмотр статистики приглашений',
            'Отмена приглашений',
            'Поиск организаций в базе',
            'Получение рекомендаций подрядчиков',
            'Проверка доступности организаций',
            'Интеграция с внешними реестрами',
            'Рейтинговая система подрядчиков',
            'История сотрудничества',
            'Автоматические уведомления'
        ];
    }

    public function getLimits(): array
    {
        return [
            'max_invitations_per_month' => 200,
            'max_search_requests_per_day' => 500,
            'max_concurrent_invitations' => 50
        ];
    }

    public function getDefaultSettings(): array
    {
        return [
            'invitation_settings' => [
                'auto_approve_known_contractors' => false,
                'require_verification' => true,
                'invitation_expiry_days' => 30,
                'max_concurrent_invitations' => 50,
                'allow_self_registration' => false
            ],
            'search_settings' => [
                'enable_external_search' => true,
                'search_radius_km' => 100,
                'include_rating_filter' => true,
                'min_contractor_rating' => 3.0,
                'max_search_results' => 100
            ],
            'notification_settings' => [
                'notify_on_invitation_sent' => true,
                'notify_on_invitation_accepted' => true,
                'notify_on_invitation_declined' => true,
                'email_notifications' => true,
                'sms_notifications' => false
            ],
            'integration_settings' => [
                'enable_external_registries' => false,
                'sync_contractor_data' => true,
                'auto_update_ratings' => true,
                'cache_search_results_hours' => 24
            ]
        ];
    }

    public function validateSettings(array $settings): bool
    {
        if (isset($settings['invitation_settings']['invitation_expiry_days']) && 
            (!is_int($settings['invitation_settings']['invitation_expiry_days']) || 
             $settings['invitation_settings']['invitation_expiry_days'] < 1)) {
            return false;
        }

        if (isset($settings['search_settings']['min_contractor_rating']) && 
            (!is_numeric($settings['search_settings']['min_contractor_rating']) || 
             $settings['search_settings']['min_contractor_rating'] < 0 || 
             $settings['search_settings']['min_contractor_rating'] > 5)) {
            return false;
        }

        return true;
    }

    public function applySettings(int $organizationId, array $settings): void
    {
        if (!$this->validateSettings($settings)) {
            throw new \InvalidArgumentException('Некорректные настройки модуля портала подрядчиков');
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
