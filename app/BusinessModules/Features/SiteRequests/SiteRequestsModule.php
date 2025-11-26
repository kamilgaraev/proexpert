<?php

namespace App\BusinessModules\Features\SiteRequests;

use App\Modules\Contracts\ModuleInterface;
use App\Modules\Contracts\BillableInterface;
use App\Modules\Contracts\ConfigurableInterface;
use App\Enums\ModuleType;
use App\Enums\BillingModel;
use Illuminate\Support\Facades\Cache;

/**
 * Модуль "Заявки с объекта"
 * 
 * Система управления заявками с строительных объектов:
 * материалы, персонал, техника
 */
class SiteRequestsModule implements ModuleInterface, BillableInterface, ConfigurableInterface
{
    private const CACHE_TTL = 3600; // 1 час

    /**
     * Название модуля
     */
    public function getName(): string
    {
        return 'Заявки с объекта';
    }

    /**
     * Уникальный slug модуля
     */
    public function getSlug(): string
    {
        return 'site-requests';
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
        return 'Система управления заявками с строительных объектов: материалы, персонал, техника';
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
            file_get_contents(config_path('ModuleList/features/site-requests.json')),
            true
        );
    }

    /**
     * Установка модуля
     */
    public function install(): void
    {
        // Миграции будут выполнены автоматически
        // Создание базовых статусов для новых организаций
    }

    /**
     * Удаление модуля
     */
    public function uninstall(): void
    {
        // Очистка данных модуля
        // ВНИМАНИЕ: данные пользователей не удаляются, только отключается доступ
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
        // Проверяем что модуль project-management активирован
        $accessController = app(\App\Modules\Core\AccessController::class);
        return $accessController->hasModuleAccess($organizationId, 'project-management');
    }

    /**
     * Зависимости модуля
     */
    public function getDependencies(): array
    {
        return [
            'organizations',
            'users',
            'project-management',
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
            'site_requests.view',
            'site_requests.create',
            'site_requests.edit',
            'site_requests.delete',
            'site_requests.approve',
            'site_requests.assign',
            'site_requests.change_status',
            'site_requests.files.upload',
            'site_requests.files.delete',
            'site_requests.statistics',
            'site_requests.export',
            'site_requests.templates.manage',
            'site_requests.calendar.view',
        ];
    }

    /**
     * Возможности модуля
     */
    public function getFeatures(): array
    {
        return [
            'Управление заявками с объектов',
            'Заявки на материалы (с привязкой к каталогу)',
            'Заявки на персонал (с расчетом стоимости)',
            'Заявки на технику (с графиком аренды)',
            'Прикрепление фото к заявкам (до 10 файлов, до 5MB)',
            'Шаблоны заявок (быстрое создание)',
            'Офлайн-режим с синхронизацией',
            'Гибкий workflow (настраиваемые статусы)',
            'Статистика и аналитика по заявкам',
            'Фильтрация и поиск',
            'Push-уведомления при смене статуса',
            'История изменений (audit log)',
            'Интеграция с календарем событий',
            'Отображение заявок в календаре проекта',
            'Планирование ресурсов через календарь',
        ];
    }

    /**
     * Лимиты модуля
     */
    public function getLimits(): array
    {
        return [
            'max_requests_per_month' => null,
            'max_files_per_request' => 10,
            'max_file_size_mb' => 5,
            'max_personnel_in_request' => 50,
            'retention_days' => 365,
            'max_templates' => 20,
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
        return 2490.0;
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
            'base_price' => 2490,
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
            'enable_calendar_sync' => true,
            'enable_templates' => true,

            // Лимиты
            'max_files_per_request' => 10,
            'max_file_size_mb' => 5,
            'max_templates' => 20,

            // Workflow
            'require_approval' => true,
            'auto_assign_enabled' => false,
            'default_priority' => 'medium',

            // Уведомления
            'notify_on_create' => true,
            'notify_on_status_change' => true,
            'notify_on_assign' => true,
            'notify_on_overdue' => true,

            // Интеграции
            'enable_catalog_integration' => true,
            'enable_schedule_integration' => true,

            // Кеширование
            'cache_ttl' => 300,
        ];
    }

    /**
     * Валидация настроек
     */
    public function validateSettings(array $settings): bool
    {
        // Валидация лимитов
        if (isset($settings['max_files_per_request'])) {
            if (!is_int($settings['max_files_per_request']) ||
                $settings['max_files_per_request'] < 1 ||
                $settings['max_files_per_request'] > 20) {
                return false;
            }
        }

        if (isset($settings['max_file_size_mb'])) {
            if (!is_int($settings['max_file_size_mb']) ||
                $settings['max_file_size_mb'] < 1 ||
                $settings['max_file_size_mb'] > 10) {
                return false;
            }
        }

        if (isset($settings['max_templates'])) {
            if (!is_int($settings['max_templates']) ||
                $settings['max_templates'] < 1 ||
                $settings['max_templates'] > 50) {
                return false;
            }
        }

        // Валидация приоритета по умолчанию
        if (isset($settings['default_priority'])) {
            $validPriorities = ['low', 'medium', 'high', 'urgent'];
            if (!in_array($settings['default_priority'], $validPriorities)) {
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
            throw new \InvalidArgumentException('Некорректные настройки модуля заявок с объекта');
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
            Cache::forget("site_requests_settings_{$organizationId}");
        }
    }

    /**
     * Получить настройки
     */
    public function getSettings(int $organizationId): array
    {
        return Cache::remember(
            "site_requests_settings_{$organizationId}",
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

    // ============================================
    // Вспомогательные методы
    // ============================================

    /**
     * Проверить доступ к модулю catalog-management
     */
    public function hasCatalogManagement(int $organizationId): bool
    {
        $accessController = app(\App\Modules\Core\AccessController::class);
        return $accessController->hasModuleAccess($organizationId, 'catalog-management');
    }

    /**
     * Проверить доступ к модулю schedule-management
     */
    public function hasScheduleManagement(int $organizationId): bool
    {
        $accessController = app(\App\Modules\Core\AccessController::class);
        return $accessController->hasModuleAccess($organizationId, 'schedule-management');
    }

    /**
     * Проверить доступ к модулю notifications
     */
    public function hasNotifications(int $organizationId): bool
    {
        $accessController = app(\App\Modules\Core\AccessController::class);
        return $accessController->hasModuleAccess($organizationId, 'notifications');
    }

    /**
     * Проверить доступ к модулю ai-assistant
     */
    public function hasAIAssistant(int $organizationId): bool
    {
        $accessController = app(\App\Modules\Core\AccessController::class);
        return $accessController->hasModuleAccess($organizationId, 'ai-assistant');
    }
}

