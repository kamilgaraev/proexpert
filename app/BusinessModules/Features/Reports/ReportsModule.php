<?php

namespace App\BusinessModules\Features\Reports;

use App\BusinessModules\Core\BaseModule;
use App\BusinessModules\Interfaces\ModuleInterface;
use App\BusinessModules\Interfaces\BillableInterface;
use App\BusinessModules\Interfaces\ConfigurableInterface;

class ReportsModule extends BaseModule implements ModuleInterface, BillableInterface, ConfigurableInterface
{
    public function getName(): string
    {
        return 'Отчеты';
    }

    public function getSlug(): string
    {
        return 'reports';
    }

    public function getVersion(): string
    {
        return '2.0.0';
    }

    public function getDescription(): string
    {
        return 'Унифицированный модуль отчетности, включающий базовые отчеты и расширенную аналитику.';
    }

    public function getType(): string
    {
        return 'feature';
    }

    public function getCategory(): string
    {
        return 'analytics';
    }

    public function getDependencies(): array
    {
        return [
            'organizations',
            'users',
            'projects',
        ];
    }

    public function getPermissions(): array
    {
        return [
            'reports.view' => 'Просмотр отчетов',
            'reports.export' => 'Экспорт отчетов',
            'reports.manage_templates' => 'Управление шаблонами',
            'reports.custom_reports' => 'Конструктор отчетов',
            'reports.share' => 'Возможность делиться отчетами',
            'reports.schedule' => 'Расписание отчетов',
            'reports.official_reports' => 'Официальные отчеты',
            'reports.predictive' => 'Прогнозная аналитика',
        ];
    }

    public function getFeatures(): array
    {
        return [
            'basic_reports' => 'Базовые отчеты (материалы, работы, проекты)',
            'custom_builder' => 'Конструктор произвольных отчетов',
            'financial_analytics' => 'Финансовая аналитика и KPI',
            'official_forms' => 'Генерация официальных форм (М-29 и др.)',
            'predictive_analytics' => 'Прогнозирование и ML-аналитика',
            'scheduled_reports' => 'Автоматическая рассылка отчетов',
        ];
    }

    public function getBillingModel(): string
    {
        return 'subscription';
    }

    public function getLimits(): array
    {
        return [
            'max_custom_reports' => [
                'label' => 'Макс. количество своих отчетов',
                'type' => 'numeric',
                'default' => 5,
            ],
            'max_scheduled_reports' => [
                'label' => 'Макс. количество активных расписаний',
                'type' => 'numeric',
                'default' => 1,
            ],
        ];
    }

    public function getSettings(): array
    {
        return [
            'default_export_format' => [
                'type' => 'select',
                'label' => 'Формат экспорта по умолчанию',
                'options' => ['pdf', 'xlsx', 'csv'],
                'default' => 'pdf',
            ],
            'enable_external_data_sources' => [
                'type' => 'boolean',
                'label' => 'Разрешить внешние источники данных',
                'default' => false,
            ],
        ];
    }
}
