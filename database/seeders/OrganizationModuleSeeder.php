<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\OrganizationModule;

class OrganizationModuleSeeder extends Seeder
{
    public function run(): void
    {
        $modules = [
            // Аналитика и отчеты
            [
                'name' => 'BI-Аналитика',
                'slug' => 'bi_analytics',
                'description' => 'Расширенная бизнес-аналитика с интерактивными дашбордами',
                'price' => 4900,
                'features' => ['Интерактивные дашборды', 'Кастомные отчеты', 'Экспорт в Excel', 'Сравнение план/факт'],
                'permissions' => ['analytics.view', 'analytics.export', 'dashboard.advanced'],
                'category' => 'analytics',
                'icon' => 'chart-bar',
                'is_premium' => true,
                'display_order' => 1,
            ],
            [
                'name' => 'Продвинутая отчетность',
                'slug' => 'advanced_reports',
                'description' => 'Расширенные возможности создания и настройки отчетов',
                'price' => 2900,
                'features' => ['Конструктор отчетов', 'Автоматизация', 'Шаблоны отчетов', 'Планировщик'],
                'permissions' => ['reports.advanced', 'reports.constructor', 'reports.automation'],
                'category' => 'analytics',
                'icon' => 'document-chart-bar',
                'is_premium' => true,
                'display_order' => 2,
            ],

            // Интеграции
            [
                'name' => '1С Интеграция',
                'slug' => '1c_integration',
                'description' => 'Двусторонняя синхронизация с 1С',
                'price' => 5900,
                'features' => ['Синхронизация справочников', 'Выгрузка актов', 'Обмен документами', 'Автоматический импорт'],
                'permissions' => ['integration.1c', 'integration.export', 'integration.import'],
                'category' => 'integrations',
                'icon' => 'arrow-path',
                'is_premium' => true,
                'display_order' => 1,
            ],
            [
                'name' => 'API интеграции',
                'slug' => 'api_integrations',
                'description' => 'Расширенные возможности API для интеграции с внешними системами',
                'price' => 3900,
                'features' => ['REST API', 'Webhooks', 'Кастомные эндпоинты', 'Документация API'],
                'permissions' => ['api.advanced', 'api.webhooks', 'api.custom'],
                'category' => 'integrations',
                'icon' => 'code-bracket',
                'is_premium' => true,
                'display_order' => 2,
            ],

            // Автоматизация
            [
                'name' => 'Автоматизация процессов',
                'slug' => 'process_automation',
                'description' => 'Настройка автоматических процессов и уведомлений',
                'price' => 3500,
                'features' => ['Конструктор процессов', 'Автоуведомления', 'Условная логика', 'Триггеры'],
                'permissions' => ['automation.processes', 'automation.notifications', 'automation.triggers'],
                'category' => 'automation',
                'icon' => 'cog-6-tooth',
                'is_premium' => true,
                'display_order' => 1,
            ],
            [
                'name' => 'Умные уведомления',
                'slug' => 'smart_notifications',
                'description' => 'Интеллектуальная система уведомлений',
                'price' => 1900,
                'features' => ['SMS уведомления', 'Email рассылки', 'Push уведомления', 'Telegram бот'],
                'permissions' => ['notifications.sms', 'notifications.email', 'notifications.push'],
                'category' => 'automation',
                'icon' => 'bell',
                'is_premium' => true,
                'display_order' => 2,
            ],

            // Кастомизация
            [
                'name' => 'White Label',
                'slug' => 'white_label',
                'description' => 'Персонализация платформы под бренд организации',
                'price' => 9900,
                'features' => ['Кастомный логотип', 'Цветовая схема', 'Домен', 'Персонализация интерфейса'],
                'permissions' => ['branding.logo', 'branding.colors', 'branding.domain'],
                'category' => 'customization',
                'icon' => 'paint-brush',
                'is_premium' => true,
                'display_order' => 1,
            ],
            [
                'name' => 'Кастомные поля',
                'slug' => 'custom_fields',
                'description' => 'Добавление пользовательских полей в формы',
                'price' => 2500,
                'features' => ['Конструктор полей', 'Разные типы данных', 'Валидация', 'Отображение в отчетах'],
                'permissions' => ['fields.custom', 'fields.create', 'fields.manage'],
                'category' => 'customization',
                'icon' => 'squares-plus',
                'is_premium' => true,
                'display_order' => 2,
            ],

            // Безопасность
            [
                'name' => 'Расширенная безопасность',
                'slug' => 'advanced_security',
                'description' => 'Дополнительные возможности безопасности',
                'price' => 4500,
                'features' => ['2FA аутентификация', 'Аудит действий', 'IP ограничения', 'Журнал безопасности'],
                'permissions' => ['security.2fa', 'security.audit', 'security.ip_restrictions'],
                'category' => 'security',
                'icon' => 'shield-check',
                'is_premium' => true,
                'display_order' => 1,
            ],

            // Поддержка
            [
                'name' => 'Премиум поддержка',
                'slug' => 'premium_support',
                'description' => 'Приоритетная поддержка с персональным менеджером',
                'price' => 3900,
                'features' => ['Персональный менеджер', 'SLA 4 часа', 'Приоритетные тикеты', 'Телефонная поддержка'],
                'permissions' => ['support.premium', 'support.priority', 'support.phone'],
                'category' => 'support',
                'icon' => 'user-circle',
                'is_premium' => true,
                'display_order' => 1,
            ],

            // Организационная структура
            [
                'name' => 'Мультиорганизация',
                'slug' => 'multi_organization',
                'description' => 'Создание холдинговой структуры с дочерними организациями',
                'price' => 14900,
                'features' => ['Неограниченные дочерние организации', 'Консолидированные отчеты', 'Централизованное управление', 'Иерархия доступов'],
                'permissions' => ['multi_org.create', 'multi_org.manage', 'multi_org.reports', 'multi_org.access_all'],
                'category' => 'organization',
                'icon' => 'building-office-2',
                'is_premium' => true,
                'display_order' => 1,
            ],

            // Бесплатные модули
            [
                'name' => 'Базовые отчеты',
                'slug' => 'basic_reports',
                'description' => 'Стандартные отчеты по проектам и материалам',
                'price' => 0,
                'features' => ['Отчет по материалам', 'Отчет по проектам', 'Экспорт в PDF'],
                'permissions' => ['reports.basic', 'reports.export_pdf'],
                'category' => 'analytics',
                'icon' => 'document-text',
                'is_premium' => false,
                'display_order' => 10,
            ],
        ];

        foreach ($modules as $moduleData) {
            OrganizationModule::updateOrCreate([
                'slug' => $moduleData['slug']
            ], $moduleData);
        }
    }
} 