<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;

class WidgetsRegistryController extends Controller
{
    public function getRegistry(): JsonResponse
    {
        $widgets = [
            [
                'id' => 'cash_flow',
                'name' => 'Движение денежных средств',
                'description' => 'Анализ притока и оттока денежных средств с разбивкой по категориям и месяцам',
                'category' => 'financial',
                'is_ready' => true,
                'endpoint' => '/analytics/financial/cash-flow',
                'icon' => 'trending-up',
                'default_size' => ['w' => 6, 'h' => 3],
                'min_size' => ['w' => 4, 'h' => 2],
            ],
            [
                'id' => 'profit_loss',
                'name' => 'Прибыль и убытки (P&L)',
                'description' => 'Отчет о прибылях и убытках с маржинальностью и разбивкой по проектам',
                'category' => 'financial',
                'is_ready' => true,
                'endpoint' => '/analytics/financial/profit-loss',
                'icon' => 'bar-chart',
                'default_size' => ['w' => 6, 'h' => 3],
                'min_size' => ['w' => 4, 'h' => 2],
            ],
            [
                'id' => 'roi',
                'name' => 'Рентабельность (ROI)',
                'description' => 'Расчет ROI по проектам с топ-5 лучших и худших',
                'category' => 'financial',
                'is_ready' => true,
                'endpoint' => '/analytics/financial/roi',
                'icon' => 'percent',
                'default_size' => ['w' => 6, 'h' => 3],
                'min_size' => ['w' => 4, 'h' => 2],
            ],
            [
                'id' => 'revenue_forecast',
                'name' => 'Прогноз доходов',
                'description' => 'Прогноз выручки на основе контрактов и исторических данных (6 месяцев)',
                'category' => 'financial',
                'is_ready' => true,
                'endpoint' => '/analytics/financial/revenue-forecast',
                'icon' => 'trending-up',
                'default_size' => ['w' => 6, 'h' => 3],
                'min_size' => ['w' => 4, 'h' => 2],
            ],
            [
                'id' => 'receivables_payables',
                'name' => 'Дебиторка / Кредиторка',
                'description' => 'Анализ дебиторской и кредиторской задолженности с разбивкой по срокам',
                'category' => 'financial',
                'is_ready' => true,
                'endpoint' => '/analytics/financial/receivables-payables',
                'icon' => 'file-text',
                'default_size' => ['w' => 12, 'h' => 4],
                'min_size' => ['w' => 6, 'h' => 3],
            ],
            [
                'id' => 'contract_forecast',
                'name' => 'Прогноз завершения контрактов',
                'description' => 'Предсказание дат завершения контрактов на основе текущего прогресса',
                'category' => 'predictive',
                'is_ready' => true,
                'endpoint' => '/analytics/predictive/contract-forecast',
                'icon' => 'calendar',
                'default_size' => ['w' => 6, 'h' => 3],
                'min_size' => ['w' => 4, 'h' => 2],
            ],
            [
                'id' => 'budget_risk',
                'name' => 'Риски бюджета',
                'description' => 'Анализ рисков превышения бюджета по проектам',
                'category' => 'predictive',
                'is_ready' => true,
                'endpoint' => '/analytics/predictive/budget-risk',
                'icon' => 'alert-triangle',
                'default_size' => ['w' => 6, 'h' => 3],
                'min_size' => ['w' => 4, 'h' => 2],
            ],
            [
                'id' => 'material_needs',
                'name' => 'Прогноз потребности в материалах',
                'description' => 'Предсказание будущей потребности в материалах',
                'category' => 'predictive',
                'is_ready' => true,
                'endpoint' => '/analytics/predictive/material-needs',
                'icon' => 'package',
                'default_size' => ['w' => 12, 'h' => 4],
                'min_size' => ['w' => 6, 'h' => 3],
            ],
            [
                'id' => 'employee_kpi',
                'name' => 'KPI сотрудников',
                'description' => 'Ключевые показатели эффективности сотрудников',
                'category' => 'hr',
                'is_ready' => true,
                'endpoint' => '/analytics/hr/kpi',
                'icon' => 'users',
                'default_size' => ['w' => 6, 'h' => 3],
                'min_size' => ['w' => 4, 'h' => 2],
            ],
            [
                'id' => 'top_performers',
                'name' => 'Топ исполнителей',
                'description' => 'Рейтинг лучших сотрудников по выполненным работам',
                'category' => 'hr',
                'is_ready' => true,
                'endpoint' => '/analytics/hr/top-performers',
                'icon' => 'award',
                'default_size' => ['w' => 6, 'h' => 3],
                'min_size' => ['w' => 4, 'h' => 2],
            ],
            [
                'id' => 'resource_utilization',
                'name' => 'Загрузка ресурсов',
                'description' => 'Анализ занятости и загрузки сотрудников по проектам',
                'category' => 'hr',
                'is_ready' => true,
                'endpoint' => '/analytics/hr/resource-utilization',
                'icon' => 'activity',
                'default_size' => ['w' => 12, 'h' => 4],
                'min_size' => ['w' => 6, 'h' => 3],
            ],
        ];
        
        return response()->json([
            'success' => true,
            'data' => [
                'widgets' => $widgets,
                'categories' => [
                    [
                        'id' => 'financial',
                        'name' => 'Финансовая аналитика',
                        'description' => 'Виджеты для финансового анализа',
                        'color' => '#10B981',
                        'icon' => 'dollar-sign',
                    ],
                    [
                        'id' => 'predictive',
                        'name' => 'Предиктивная аналитика',
                        'description' => 'Прогнозы и предсказания',
                        'color' => '#8B5CF6',
                        'icon' => 'trending-up',
                    ],
                    [
                        'id' => 'hr',
                        'name' => 'HR и KPI',
                        'description' => 'Аналитика персонала',
                        'color' => '#F59E0B',
                        'icon' => 'users',
                    ],
                ],
                'stats' => [
                    'total_widgets' => count($widgets),
                    'ready_widgets' => count(array_filter($widgets, fn($w) => $w['is_ready'])),
                    'in_development' => count(array_filter($widgets, fn($w) => !$w['is_ready'])),
                ],
            ],
        ]);
    }
    
    public function getWidgetInfo(string $widgetId): JsonResponse
    {
        $widgetsMap = [
            'cash_flow' => [
                'id' => 'cash_flow',
                'name' => 'Движение денежных средств',
                'description' => 'Анализ притока и оттока денежных средств',
                'category' => 'financial',
                'is_ready' => true,
                'endpoint' => '/analytics/financial/cash-flow',
                'params' => [
                    ['name' => 'from', 'type' => 'date', 'required' => true],
                    ['name' => 'to', 'type' => 'date', 'required' => true],
                    ['name' => 'project_id', 'type' => 'integer', 'required' => false],
                ],
                'response_structure' => [
                    'total_inflow' => 'number',
                    'total_outflow' => 'number',
                    'net_cash_flow' => 'number',
                    'monthly_breakdown' => 'array',
                    'inflow_by_category' => 'array',
                    'outflow_by_category' => 'array',
                ],
            ],
            // Можно добавить детали для других виджетов
        ];
        
        $widget = $widgetsMap[$widgetId] ?? null;
        
        if (!$widget) {
            return response()->json([
                'success' => false,
                'message' => 'Widget not found',
            ], 404);
        }
        
        return response()->json([
            'success' => true,
            'data' => $widget,
        ]);
    }
}

