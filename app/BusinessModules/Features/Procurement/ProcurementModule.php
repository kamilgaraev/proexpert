<?php

namespace App\BusinessModules\Features\Procurement;

use App\Modules\Contracts\ModuleInterface;
use App\Modules\Contracts\BillableInterface;
use App\Modules\Contracts\ConfigurableInterface;
use App\Enums\ModuleType;
use App\Enums\BillingModel;
use Illuminate\Support\Facades\Cache;

/**
 * Модуль "Управление закупками"
 * 
 * Система управления процессом закупок материалов:
 * от заявок до оплаты счетов поставщиков и приема материалов на склад
 */
class ProcurementModule implements ModuleInterface, BillableInterface, ConfigurableInterface
{
    private const CACHE_TTL = 3600; // 1 час

    /**
     * Название модуля
     */
    public function getName(): string
    {
        return 'Управление закупками';
    }

    /**
     * Уникальный slug модуля
     */
    public function getSlug(): string
    {
        return 'procurement';
    }

    /**
     * Версия модуля
     */
    public function getVersion(): string
    {
        return '1.0.0';
    }

    /**
     * Описание модуля
     */
    public function getDescription(): string
    {
        return 'Система управления процессом закупок материалов: от заявок до оплаты счетов поставщиков и приема материалов на склад';
    }

    /**
     * Тип модуля
     */
    public function getType(): ModuleType
    {
        return ModuleType::FEATURE;
    }

    /**
     * Модель биллинга
     */
    public function getBillingModel(): BillingModel
    {
        return BillingModel::SUBSCRIPTION;
    }

    /**
     * Получить манифест модуля
     */
    public function getManifest(): array
    {
        return json_decode(
            file_get_contents(config_path('ModuleList/features/procurement.json')),
            true
        );
    }

    /**
     * Установка модуля
     */
    public function install(): void
    {
        // Миграции будут выполнены автоматически
    }

    /**
     * Удаление модуля
     */
    public function uninstall(): void
    {
        // ВНИМАНИЕ: данные не удаляются, только отключается доступ
    }

    /**
     * Обновление модуля
     */
    public function upgrade(string $fromVersion): void
    {
        // Логика обновления модуля при изменении версии
    }

    /**
     * Проверка возможности активации
     */
    public function canActivate(int $organizationId): bool
    {
        $accessController = app(\App\Modules\Core\AccessController::class);
        
        // Проверяем зависимости
        foreach ($this->getDependencies() as $dependency) {
            if (!$accessController->hasModuleAccess($organizationId, $dependency)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Зависимости модуля
     */
    public function getDependencies(): array
    {
        return [
            'organizations',
            'users',
            'basic-warehouse',
            'site-requests',
        ];
    }

    /**
     * Конфликтующие модули
     */
    public function getConflicts(): array
    {
        return [];
    }

    /**
     * Permissions модуля
     */
    public function getPermissions(): array
    {
        return [
            'procurement.view',
            'procurement.manage',
            'procurement.purchase_requests.view',
            'procurement.purchase_requests.create',
            'procurement.purchase_requests.edit',
            'procurement.purchase_requests.delete',
            'procurement.purchase_requests.approve',
            'procurement.purchase_orders.view',
            'procurement.purchase_orders.create',
            'procurement.purchase_orders.edit',
            'procurement.purchase_orders.delete',
            'procurement.purchase_orders.send',
            'procurement.supplier_proposals.view',
            'procurement.supplier_proposals.create',
            'procurement.supplier_proposals.accept',
            'procurement.contracts.view',
            'procurement.contracts.create',
            'procurement.contracts.edit',
            'procurement.dashboard.view',
            'procurement.statistics.view',
        ];
    }

    /**
     * Возможности модуля
     */
    public function getFeatures(): array
    {
        return [
            'Создание заявок на закупку из заявок с объекта',
            'Управление заказами поставщикам',
            'Прием и обработка коммерческих предложений',
            'Создание договоров поставки',
            'Интеграция с модулем платежей (автоматическое создание счетов)',
            'Интеграция со складом (автоматический прием материалов)',
            'Выбор поставщиков и сравнение КП',
            'Workflow закупок с отслеживанием статусов',
            'Дашборд и статистика по закупкам',
            'История всех операций',
        ];
    }

    /**
     * Лимиты модуля
     */
    public function getLimits(): array
    {
        return [
            'max_purchase_requests_per_month' => null,
            'max_purchase_orders_per_month' => null,
            'max_supplier_proposals_per_order' => 10,
            'retention_days' => 365,
        ];
    }

    // ============================================
    // BillableInterface
    // ============================================

    /**
     * Цена модуля
     */
    public function getPrice(): float
    {
        return 3990.0;
    }

    /**
     * Валюта
     */
    public function getCurrency(): string
    {
        return 'RUB';
    }

    /**
     * Длительность подписки в днях
     */
    public function getDurationDays(): int
    {
        return 30;
    }

    /**
     * Конфигурация ценообразования
     */
    public function getPricingConfig(): array
    {
        return [
            'base_price' => 3990,
            'currency' => 'RUB',
            'included_in_plans' => ['business', 'profi', 'enterprise'],
            'duration_days' => 30,
            'trial_days' => 7,
        ];
    }

    /**
     * Расчет стоимости для организации
     */
    public function calculateCost(int $organizationId): float
    {
        return $this->getPrice();
    }

    /**
     * Проверка платежеспособности
     */
    public function canAfford(int $organizationId): bool
    {
        $organization = \App\Models\Organization::find($organizationId);

        if (!$organization) {
            return false;
        }

        $billingEngine = app(\App\Modules\Core\BillingEngine::class);
        $module = \App\Models\Module::where('slug', $this->getSlug())->first();

        return $module ? $billingEngine->canAfford($organization, $module) : false;
    }

    // ============================================
    // ConfigurableInterface
    // ============================================

    /**
     * Настройки по умолчанию
     */
    public function getDefaultSettings(): array
    {
        return [
            // Общие настройки
            'enable_notifications' => true,
            'auto_create_purchase_request' => true,
            'auto_create_invoice' => true,
            'auto_receive_to_warehouse' => true,

            // Workflow
            'require_approval' => true,
            'require_supplier_selection' => true,
            'default_currency' => 'RUB',

            // Уведомления
            'notify_on_request_created' => true,
            'notify_on_order_sent' => true,
            'notify_on_proposal_received' => true,
            'notify_on_material_received' => true,

            // Интеграции
            'enable_site_requests_integration' => true,
            'enable_payments_integration' => true,
            'enable_warehouse_integration' => true,

            // Кеширование
            'cache_ttl' => 300,
        ];
    }

    /**
     * Валидация настроек
     */
    public function validateSettings(array $settings): bool
    {
        // Валидация валюты
        if (isset($settings['default_currency'])) {
            $validCurrencies = ['RUB', 'USD', 'EUR'];
            if (!in_array($settings['default_currency'], $validCurrencies)) {
                return false;
            }
        }

        // Валидация TTL кеша
        if (isset($settings['cache_ttl'])) {
            if (!is_int($settings['cache_ttl']) ||
                $settings['cache_ttl'] < 60) {
                return false;
            }
        }

        return true;
    }

    /**
     * Применить настройки
     */
    public function applySettings(int $organizationId, array $settings): void
    {
        if (!$this->validateSettings($settings)) {
            throw new \InvalidArgumentException(trans_message('procurement.settings_invalid'));
        }

        $activation = \App\Models\OrganizationModuleActivation::where('organization_id', $organizationId)
            ->whereHas('module', function ($query) {
                $query->where('slug', $this->getSlug());
            })
            ->first();

        if ($activation) {
            $currentSettings = $activation->module_settings ?? [];
            $activation->update([
                'module_settings' => array_merge($currentSettings, $settings),
            ]);

            // Инвалидация кеша настроек
            Cache::forget("procurement_settings_{$organizationId}");
        }
    }

    /**
     * Получить настройки
     */
    public function getSettings(int $organizationId): array
    {
        return Cache::remember(
            "procurement_settings_{$organizationId}",
            self::CACHE_TTL,
            function () use ($organizationId) {
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
        );
    }
}

