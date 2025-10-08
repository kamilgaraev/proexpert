<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Enums;

enum WidgetCategory: string
{
    case FINANCIAL = 'financial';
    case PROJECTS = 'projects';
    case CONTRACTS = 'contracts';
    case MATERIALS = 'materials';
    case HR = 'hr';
    case PREDICTIVE = 'predictive';
    case ACTIVITY = 'activity';
    case PERFORMANCE = 'performance';

    public function getCacheTag(): string
    {
        return 'widget:' . $this->value;
    }

    public function getColor(): string
    {
        return match($this) {
            self::FINANCIAL => '#10B981',
            self::PROJECTS => '#3B82F6',
            self::CONTRACTS => '#8B5CF6',
            self::MATERIALS => '#F59E0B',
            self::HR => '#EF4444',
            self::PREDICTIVE => '#6366F1',
            self::ACTIVITY => '#14B8A6',
            self::PERFORMANCE => '#EC4899',
        };
    }

    public function getIcon(): string
    {
        return match($this) {
            self::FINANCIAL => 'dollar-sign',
            self::PROJECTS => 'folder',
            self::CONTRACTS => 'file-text',
            self::MATERIALS => 'package',
            self::HR => 'users',
            self::PREDICTIVE => 'trending-up',
            self::ACTIVITY => 'activity',
            self::PERFORMANCE => 'zap',
        };
    }

    public function getName(): string
    {
        return match($this) {
            self::FINANCIAL => 'Финансовая аналитика',
            self::PROJECTS => 'Проекты',
            self::CONTRACTS => 'Контракты',
            self::MATERIALS => 'Материалы',
            self::HR => 'HR и KPI',
            self::PREDICTIVE => 'Предиктивная аналитика',
            self::ACTIVITY => 'Активность',
            self::PERFORMANCE => 'Производительность',
        };
    }

    public function getDescription(): string
    {
        return match($this) {
            self::FINANCIAL => 'Виджеты для финансового анализа и прогнозирования',
            self::PROJECTS => 'Аналитика по проектам и их выполнению',
            self::CONTRACTS => 'Управление и анализ контрактов',
            self::MATERIALS => 'Учет и прогнозирование материалов',
            self::HR => 'Аналитика персонала и KPI',
            self::PREDICTIVE => 'Прогнозы и предсказания на основе данных',
            self::ACTIVITY => 'История действий и событий в системе',
            self::PERFORMANCE => 'Мониторинг производительности системы',
        };
    }
}

