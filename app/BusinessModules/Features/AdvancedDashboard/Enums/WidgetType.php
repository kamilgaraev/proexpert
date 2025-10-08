<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Enums;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Financial\CashFlowWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Financial\ProfitLossWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Financial\ROIWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Financial\RevenueForecastWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Financial\ReceivablesPayablesWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Financial\ExpenseBreakdownWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Financial\FinancialHealthWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Projects\ProjectsOverviewWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Projects\ProjectsStatusWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Projects\ProjectsTimelineWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Projects\ProjectsBudgetWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Projects\ProjectsCompletionWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Projects\ProjectsRisksWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Projects\ProjectsMapWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Contracts\ContractsOverviewWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Contracts\ContractsStatusWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Contracts\ContractsPaymentsWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Contracts\ContractsPerformanceWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Contracts\ContractsUpcomingWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Contracts\ContractsCompletionForecastWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Contracts\ContractsByContractorWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Materials\MaterialsInventoryWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Materials\MaterialsConsumptionWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Materials\MaterialsForecastWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Materials\MaterialsLowStockWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Materials\MaterialsTopUsedWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Materials\MaterialsByProjectWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Materials\MaterialsSuppliersWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\HR\EmployeeKPIWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\HR\TopPerformersWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\HR\ResourceUtilizationWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\HR\EmployeeWorkloadWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\HR\EmployeeAttendanceWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\HR\EmployeeEfficiencyWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\HR\TeamPerformanceWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Predictive\BudgetRiskWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Predictive\DeadlineRiskWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Predictive\ResourceDemandWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Predictive\CashFlowForecastWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Predictive\ProjectCompletionWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Predictive\CostOverrunWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Predictive\TrendAnalysisWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Activity\RecentActivityWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Activity\SystemEventsWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Activity\UserActionsWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Activity\NotificationsWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Activity\AuditLogWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Performance\SystemMetricsWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Performance\ApiPerformanceWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Performance\DatabaseStatsWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Performance\CacheStatsWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Performance\ResponseTimesWidgetProvider;

enum WidgetType: string
{
    case CASH_FLOW = 'cash_flow';
    case PROFIT_LOSS = 'profit_loss';
    case ROI = 'roi';
    case REVENUE_FORECAST = 'revenue_forecast';
    case RECEIVABLES_PAYABLES = 'receivables_payables';
    case EXPENSE_BREAKDOWN = 'expense_breakdown';
    case FINANCIAL_HEALTH = 'financial_health';

    case PROJECTS_OVERVIEW = 'projects_overview';
    case PROJECTS_STATUS = 'projects_status';
    case PROJECTS_TIMELINE = 'projects_timeline';
    case PROJECTS_BUDGET = 'projects_budget';
    case PROJECTS_COMPLETION = 'projects_completion';
    case PROJECTS_RISKS = 'projects_risks';
    case PROJECTS_MAP = 'projects_map';

    case CONTRACTS_OVERVIEW = 'contracts_overview';
    case CONTRACTS_STATUS = 'contracts_status';
    case CONTRACTS_PAYMENTS = 'contracts_payments';
    case CONTRACTS_PERFORMANCE = 'contracts_performance';
    case CONTRACTS_UPCOMING = 'contracts_upcoming';
    case CONTRACTS_COMPLETION_FORECAST = 'contracts_completion_forecast';
    case CONTRACTS_BY_CONTRACTOR = 'contracts_by_contractor';

    case MATERIALS_INVENTORY = 'materials_inventory';
    case MATERIALS_CONSUMPTION = 'materials_consumption';
    case MATERIALS_FORECAST = 'materials_forecast';
    case MATERIALS_LOW_STOCK = 'materials_low_stock';
    case MATERIALS_TOP_USED = 'materials_top_used';
    case MATERIALS_BY_PROJECT = 'materials_by_project';
    case MATERIALS_SUPPLIERS = 'materials_suppliers';

    case EMPLOYEE_KPI = 'employee_kpi';
    case TOP_PERFORMERS = 'top_performers';
    case RESOURCE_UTILIZATION = 'resource_utilization';
    case EMPLOYEE_WORKLOAD = 'employee_workload';
    case EMPLOYEE_ATTENDANCE = 'employee_attendance';
    case EMPLOYEE_EFFICIENCY = 'employee_efficiency';
    case TEAM_PERFORMANCE = 'team_performance';

    case BUDGET_RISK = 'budget_risk';
    case DEADLINE_RISK = 'deadline_risk';
    case RESOURCE_DEMAND = 'resource_demand';
    case CASH_FLOW_FORECAST = 'cash_flow_forecast';
    case PROJECT_COMPLETION = 'project_completion';
    case COST_OVERRUN = 'cost_overrun';
    case TREND_ANALYSIS = 'trend_analysis';

    case RECENT_ACTIVITY = 'recent_activity';
    case SYSTEM_EVENTS = 'system_events';
    case USER_ACTIONS = 'user_actions';
    case NOTIFICATIONS = 'notifications';
    case AUDIT_LOG = 'audit_log';

    case SYSTEM_METRICS = 'system_metrics';
    case API_PERFORMANCE = 'api_performance';
    case DATABASE_STATS = 'database_stats';
    case CACHE_STATS = 'cache_stats';
    case RESPONSE_TIMES = 'response_times';

    public function getCategory(): WidgetCategory
    {
        return match($this) {
            self::CASH_FLOW, self::PROFIT_LOSS, self::ROI, self::REVENUE_FORECAST,
            self::RECEIVABLES_PAYABLES, self::EXPENSE_BREAKDOWN, self::FINANCIAL_HEALTH 
                => WidgetCategory::FINANCIAL,

            self::PROJECTS_OVERVIEW, self::PROJECTS_STATUS, self::PROJECTS_TIMELINE,
            self::PROJECTS_BUDGET, self::PROJECTS_COMPLETION, self::PROJECTS_RISKS,
            self::PROJECTS_MAP 
                => WidgetCategory::PROJECTS,

            self::CONTRACTS_OVERVIEW, self::CONTRACTS_STATUS, self::CONTRACTS_PAYMENTS,
            self::CONTRACTS_PERFORMANCE, self::CONTRACTS_UPCOMING, self::CONTRACTS_COMPLETION_FORECAST,
            self::CONTRACTS_BY_CONTRACTOR 
                => WidgetCategory::CONTRACTS,

            self::MATERIALS_INVENTORY, self::MATERIALS_CONSUMPTION, self::MATERIALS_FORECAST,
            self::MATERIALS_LOW_STOCK, self::MATERIALS_TOP_USED, self::MATERIALS_BY_PROJECT,
            self::MATERIALS_SUPPLIERS 
                => WidgetCategory::MATERIALS,

            self::EMPLOYEE_KPI, self::TOP_PERFORMERS, self::RESOURCE_UTILIZATION,
            self::EMPLOYEE_WORKLOAD, self::EMPLOYEE_ATTENDANCE, self::EMPLOYEE_EFFICIENCY,
            self::TEAM_PERFORMANCE 
                => WidgetCategory::HR,

            self::BUDGET_RISK, self::DEADLINE_RISK, self::RESOURCE_DEMAND,
            self::CASH_FLOW_FORECAST, self::PROJECT_COMPLETION, self::COST_OVERRUN,
            self::TREND_ANALYSIS 
                => WidgetCategory::PREDICTIVE,

            self::RECENT_ACTIVITY, self::SYSTEM_EVENTS, self::USER_ACTIONS,
            self::NOTIFICATIONS, self::AUDIT_LOG 
                => WidgetCategory::ACTIVITY,

            self::SYSTEM_METRICS, self::API_PERFORMANCE, self::DATABASE_STATS,
            self::CACHE_STATS, self::RESPONSE_TIMES 
                => WidgetCategory::PERFORMANCE,
        };
    }

    public function getProviderClass(): string
    {
        return match($this) {
            self::CASH_FLOW => CashFlowWidgetProvider::class,
            self::PROFIT_LOSS => ProfitLossWidgetProvider::class,
            self::ROI => ROIWidgetProvider::class,
            self::REVENUE_FORECAST => RevenueForecastWidgetProvider::class,
            self::RECEIVABLES_PAYABLES => ReceivablesPayablesWidgetProvider::class,
            self::EXPENSE_BREAKDOWN => ExpenseBreakdownWidgetProvider::class,
            self::FINANCIAL_HEALTH => FinancialHealthWidgetProvider::class,

            self::PROJECTS_OVERVIEW => ProjectsOverviewWidgetProvider::class,
            self::PROJECTS_STATUS => ProjectsStatusWidgetProvider::class,
            self::PROJECTS_TIMELINE => ProjectsTimelineWidgetProvider::class,
            self::PROJECTS_BUDGET => ProjectsBudgetWidgetProvider::class,
            self::PROJECTS_COMPLETION => ProjectsCompletionWidgetProvider::class,
            self::PROJECTS_RISKS => ProjectsRisksWidgetProvider::class,
            self::PROJECTS_MAP => ProjectsMapWidgetProvider::class,

            self::CONTRACTS_OVERVIEW => ContractsOverviewWidgetProvider::class,
            self::CONTRACTS_STATUS => ContractsStatusWidgetProvider::class,
            self::CONTRACTS_PAYMENTS => ContractsPaymentsWidgetProvider::class,
            self::CONTRACTS_PERFORMANCE => ContractsPerformanceWidgetProvider::class,
            self::CONTRACTS_UPCOMING => ContractsUpcomingWidgetProvider::class,
            self::CONTRACTS_COMPLETION_FORECAST => ContractsCompletionForecastWidgetProvider::class,
            self::CONTRACTS_BY_CONTRACTOR => ContractsByContractorWidgetProvider::class,

            self::MATERIALS_INVENTORY => MaterialsInventoryWidgetProvider::class,
            self::MATERIALS_CONSUMPTION => MaterialsConsumptionWidgetProvider::class,
            self::MATERIALS_FORECAST => MaterialsForecastWidgetProvider::class,
            self::MATERIALS_LOW_STOCK => MaterialsLowStockWidgetProvider::class,
            self::MATERIALS_TOP_USED => MaterialsTopUsedWidgetProvider::class,
            self::MATERIALS_BY_PROJECT => MaterialsByProjectWidgetProvider::class,
            self::MATERIALS_SUPPLIERS => MaterialsSuppliersWidgetProvider::class,

            self::EMPLOYEE_KPI => EmployeeKPIWidgetProvider::class,
            self::TOP_PERFORMERS => TopPerformersWidgetProvider::class,
            self::RESOURCE_UTILIZATION => ResourceUtilizationWidgetProvider::class,
            self::EMPLOYEE_WORKLOAD => EmployeeWorkloadWidgetProvider::class,
            self::EMPLOYEE_ATTENDANCE => EmployeeAttendanceWidgetProvider::class,
            self::EMPLOYEE_EFFICIENCY => EmployeeEfficiencyWidgetProvider::class,
            self::TEAM_PERFORMANCE => TeamPerformanceWidgetProvider::class,

            self::BUDGET_RISK => BudgetRiskWidgetProvider::class,
            self::DEADLINE_RISK => DeadlineRiskWidgetProvider::class,
            self::RESOURCE_DEMAND => ResourceDemandWidgetProvider::class,
            self::CASH_FLOW_FORECAST => CashFlowForecastWidgetProvider::class,
            self::PROJECT_COMPLETION => ProjectCompletionWidgetProvider::class,
            self::COST_OVERRUN => CostOverrunWidgetProvider::class,
            self::TREND_ANALYSIS => TrendAnalysisWidgetProvider::class,

            self::RECENT_ACTIVITY => RecentActivityWidgetProvider::class,
            self::SYSTEM_EVENTS => SystemEventsWidgetProvider::class,
            self::USER_ACTIONS => UserActionsWidgetProvider::class,
            self::NOTIFICATIONS => NotificationsWidgetProvider::class,
            self::AUDIT_LOG => AuditLogWidgetProvider::class,

            self::SYSTEM_METRICS => SystemMetricsWidgetProvider::class,
            self::API_PERFORMANCE => ApiPerformanceWidgetProvider::class,
            self::DATABASE_STATS => DatabaseStatsWidgetProvider::class,
            self::CACHE_STATS => CacheStatsWidgetProvider::class,
            self::RESPONSE_TIMES => ResponseTimesWidgetProvider::class,
        };
    }

    public function getMetadata(): array
    {
        $metadata = match($this) {
            self::CASH_FLOW => [
                'name' => 'Движение денежных средств',
                'description' => 'Анализ притока и оттока денежных средств с разбивкой по категориям и месяцам',
                'icon' => 'trending-up',
                'default_size' => ['w' => 6, 'h' => 3],
                'min_size' => ['w' => 4, 'h' => 2],
            ],
            self::PROFIT_LOSS => [
                'name' => 'Прибыль и убытки (P&L)',
                'description' => 'Отчет о прибылях и убытках с маржинальностью и разбивкой по проектам',
                'icon' => 'bar-chart',
                'default_size' => ['w' => 6, 'h' => 3],
                'min_size' => ['w' => 4, 'h' => 2],
            ],
            self::ROI => [
                'name' => 'Рентабельность (ROI)',
                'description' => 'Расчет ROI по проектам с топ-5 лучших и худших',
                'icon' => 'percent',
                'default_size' => ['w' => 6, 'h' => 3],
                'min_size' => ['w' => 4, 'h' => 2],
            ],
            self::REVENUE_FORECAST => [
                'name' => 'Прогноз доходов',
                'description' => 'Прогноз выручки на основе контрактов и исторических данных',
                'icon' => 'trending-up',
                'default_size' => ['w' => 6, 'h' => 3],
                'min_size' => ['w' => 4, 'h' => 2],
            ],
            self::RECEIVABLES_PAYABLES => [
                'name' => 'Дебиторка / Кредиторка',
                'description' => 'Анализ дебиторской и кредиторской задолженности с разбивкой по срокам',
                'icon' => 'file-text',
                'default_size' => ['w' => 12, 'h' => 4],
                'min_size' => ['w' => 6, 'h' => 3],
            ],
            self::EXPENSE_BREAKDOWN => [
                'name' => 'Разбивка расходов',
                'description' => 'Детальная разбивка расходов по категориям и проектам',
                'icon' => 'pie-chart',
                'default_size' => ['w' => 6, 'h' => 3],
                'min_size' => ['w' => 4, 'h' => 2],
            ],
            self::FINANCIAL_HEALTH => [
                'name' => 'Финансовое здоровье',
                'description' => 'Общие показатели финансового состояния организации',
                'icon' => 'heart',
                'default_size' => ['w' => 6, 'h' => 3],
                'min_size' => ['w' => 4, 'h' => 2],
            ],

            self::PROJECTS_OVERVIEW => [
                'name' => 'Обзор проектов',
                'description' => 'Общая статистика по всем проектам организации',
                'icon' => 'folder',
                'default_size' => ['w' => 6, 'h' => 2],
                'min_size' => ['w' => 4, 'h' => 2],
            ],
            self::PROJECTS_STATUS => [
                'name' => 'Статусы проектов',
                'description' => 'Распределение проектов по статусам',
                'icon' => 'pie-chart',
                'default_size' => ['w' => 6, 'h' => 2],
                'min_size' => ['w' => 4, 'h' => 2],
            ],
            self::PROJECTS_TIMELINE => [
                'name' => 'График проектов',
                'description' => 'Временная шкала выполнения проектов',
                'icon' => 'calendar',
                'default_size' => ['w' => 12, 'h' => 4],
                'min_size' => ['w' => 6, 'h' => 3],
            ],
            self::PROJECTS_BUDGET => [
                'name' => 'Бюджеты проектов',
                'description' => 'Анализ бюджетов проектов и их использования',
                'icon' => 'dollar-sign',
                'default_size' => ['w' => 6, 'h' => 3],
                'min_size' => ['w' => 4, 'h' => 2],
            ],
            self::PROJECTS_COMPLETION => [
                'name' => 'Прогресс выполнения',
                'description' => 'Процент выполнения по каждому проекту',
                'icon' => 'target',
                'default_size' => ['w' => 6, 'h' => 3],
                'min_size' => ['w' => 4, 'h' => 2],
            ],
            self::PROJECTS_RISKS => [
                'name' => 'Риски проектов',
                'description' => 'Выявление и отслеживание рисков по проектам',
                'icon' => 'alert-triangle',
                'default_size' => ['w' => 6, 'h' => 3],
                'min_size' => ['w' => 4, 'h' => 2],
            ],
            self::PROJECTS_MAP => [
                'name' => 'Карта проектов',
                'description' => 'Географическое расположение проектов',
                'icon' => 'map',
                'default_size' => ['w' => 12, 'h' => 5],
                'min_size' => ['w' => 6, 'h' => 4],
            ],

            self::CONTRACTS_OVERVIEW => [
                'name' => 'Обзор контрактов',
                'description' => 'Общая статистика по контрактам',
                'icon' => 'file-text',
                'default_size' => ['w' => 6, 'h' => 2],
                'min_size' => ['w' => 4, 'h' => 2],
            ],
            self::CONTRACTS_STATUS => [
                'name' => 'Статусы контрактов',
                'description' => 'Распределение контрактов по статусам',
                'icon' => 'pie-chart',
                'default_size' => ['w' => 6, 'h' => 2],
                'min_size' => ['w' => 4, 'h' => 2],
            ],
            self::CONTRACTS_PAYMENTS => [
                'name' => 'Платежи по контрактам',
                'description' => 'График платежей и задолженностей по контрактам',
                'icon' => 'credit-card',
                'default_size' => ['w' => 12, 'h' => 3],
                'min_size' => ['w' => 6, 'h' => 2],
            ],
            self::CONTRACTS_PERFORMANCE => [
                'name' => 'Исполнение контрактов',
                'description' => 'Анализ качества исполнения контрактов',
                'icon' => 'check-circle',
                'default_size' => ['w' => 6, 'h' => 3],
                'min_size' => ['w' => 4, 'h' => 2],
            ],
            self::CONTRACTS_UPCOMING => [
                'name' => 'Предстоящие контракты',
                'description' => 'Контракты, требующие внимания в ближайшее время',
                'icon' => 'clock',
                'default_size' => ['w' => 6, 'h' => 3],
                'min_size' => ['w' => 4, 'h' => 2],
            ],
            self::CONTRACTS_COMPLETION_FORECAST => [
                'name' => 'Прогноз завершения',
                'description' => 'Прогноз даты завершения контрактов',
                'icon' => 'calendar',
                'default_size' => ['w' => 6, 'h' => 3],
                'min_size' => ['w' => 4, 'h' => 2],
            ],
            self::CONTRACTS_BY_CONTRACTOR => [
                'name' => 'По подрядчикам',
                'description' => 'Анализ контрактов в разрезе подрядчиков',
                'icon' => 'users',
                'default_size' => ['w' => 12, 'h' => 4],
                'min_size' => ['w' => 6, 'h' => 3],
            ],

            self::MATERIALS_INVENTORY => [
                'name' => 'Остатки материалов',
                'description' => 'Текущие остатки материалов на складах',
                'icon' => 'package',
                'default_size' => ['w' => 6, 'h' => 3],
                'min_size' => ['w' => 4, 'h' => 2],
            ],
            self::MATERIALS_CONSUMPTION => [
                'name' => 'Расход материалов',
                'description' => 'Динамика расхода материалов по проектам',
                'icon' => 'trending-down',
                'default_size' => ['w' => 6, 'h' => 3],
                'min_size' => ['w' => 4, 'h' => 2],
            ],
            self::MATERIALS_FORECAST => [
                'name' => 'Прогноз потребности',
                'description' => 'Прогноз будущей потребности в материалах',
                'icon' => 'trending-up',
                'default_size' => ['w' => 12, 'h' => 4],
                'min_size' => ['w' => 6, 'h' => 3],
            ],
            self::MATERIALS_LOW_STOCK => [
                'name' => 'Низкие остатки',
                'description' => 'Материалы с критически низкими остатками',
                'icon' => 'alert-triangle',
                'default_size' => ['w' => 6, 'h' => 3],
                'min_size' => ['w' => 4, 'h' => 2],
            ],
            self::MATERIALS_TOP_USED => [
                'name' => 'Топ материалов',
                'description' => 'Наиболее используемые материалы',
                'icon' => 'star',
                'default_size' => ['w' => 6, 'h' => 3],
                'min_size' => ['w' => 4, 'h' => 2],
            ],
            self::MATERIALS_BY_PROJECT => [
                'name' => 'По проектам',
                'description' => 'Использование материалов в разрезе проектов',
                'icon' => 'folder',
                'default_size' => ['w' => 12, 'h' => 4],
                'min_size' => ['w' => 6, 'h' => 3],
            ],
            self::MATERIALS_SUPPLIERS => [
                'name' => 'Поставщики',
                'description' => 'Анализ поставщиков материалов',
                'icon' => 'truck',
                'default_size' => ['w' => 6, 'h' => 3],
                'min_size' => ['w' => 4, 'h' => 2],
            ],

            self::EMPLOYEE_KPI => [
                'name' => 'KPI сотрудников',
                'description' => 'Ключевые показатели эффективности сотрудников',
                'icon' => 'users',
                'default_size' => ['w' => 6, 'h' => 3],
                'min_size' => ['w' => 4, 'h' => 2],
            ],
            self::TOP_PERFORMERS => [
                'name' => 'Топ исполнители',
                'description' => 'Рейтинг лучших сотрудников',
                'icon' => 'award',
                'default_size' => ['w' => 6, 'h' => 3],
                'min_size' => ['w' => 4, 'h' => 2],
            ],
            self::RESOURCE_UTILIZATION => [
                'name' => 'Загрузка ресурсов',
                'description' => 'Анализ занятости и загрузки сотрудников',
                'icon' => 'activity',
                'default_size' => ['w' => 12, 'h' => 4],
                'min_size' => ['w' => 6, 'h' => 3],
            ],
            self::EMPLOYEE_WORKLOAD => [
                'name' => 'Нагрузка сотрудников',
                'description' => 'Распределение задач между сотрудниками',
                'icon' => 'bar-chart',
                'default_size' => ['w' => 6, 'h' => 3],
                'min_size' => ['w' => 4, 'h' => 2],
            ],
            self::EMPLOYEE_ATTENDANCE => [
                'name' => 'Посещаемость',
                'description' => 'Анализ посещаемости и времени работы',
                'icon' => 'clock',
                'default_size' => ['w' => 6, 'h' => 3],
                'min_size' => ['w' => 4, 'h' => 2],
            ],
            self::EMPLOYEE_EFFICIENCY => [
                'name' => 'Эффективность',
                'description' => 'Показатели эффективности работы сотрудников',
                'icon' => 'zap',
                'default_size' => ['w' => 6, 'h' => 3],
                'min_size' => ['w' => 4, 'h' => 2],
            ],
            self::TEAM_PERFORMANCE => [
                'name' => 'Производительность команды',
                'description' => 'Анализ производительности команд и отделов',
                'icon' => 'users',
                'default_size' => ['w' => 12, 'h' => 4],
                'min_size' => ['w' => 6, 'h' => 3],
            ],

            self::BUDGET_RISK => [
                'name' => 'Риски бюджета',
                'description' => 'Анализ рисков превышения бюджета',
                'icon' => 'alert-triangle',
                'default_size' => ['w' => 6, 'h' => 3],
                'min_size' => ['w' => 4, 'h' => 2],
            ],
            self::DEADLINE_RISK => [
                'name' => 'Риски сроков',
                'description' => 'Прогноз рисков срыва сроков',
                'icon' => 'clock',
                'default_size' => ['w' => 6, 'h' => 3],
                'min_size' => ['w' => 4, 'h' => 2],
            ],
            self::RESOURCE_DEMAND => [
                'name' => 'Потребность в ресурсах',
                'description' => 'Прогноз потребности в ресурсах',
                'icon' => 'trending-up',
                'default_size' => ['w' => 6, 'h' => 3],
                'min_size' => ['w' => 4, 'h' => 2],
            ],
            self::CASH_FLOW_FORECAST => [
                'name' => 'Прогноз cash flow',
                'description' => 'Прогноз движения денежных средств',
                'icon' => 'trending-up',
                'default_size' => ['w' => 12, 'h' => 4],
                'min_size' => ['w' => 6, 'h' => 3],
            ],
            self::PROJECT_COMPLETION => [
                'name' => 'Завершение проектов',
                'description' => 'Прогноз завершения проектов',
                'icon' => 'check-circle',
                'default_size' => ['w' => 6, 'h' => 3],
                'min_size' => ['w' => 4, 'h' => 2],
            ],
            self::COST_OVERRUN => [
                'name' => 'Превышение затрат',
                'description' => 'Анализ и прогноз превышения плановых затрат',
                'icon' => 'alert-circle',
                'default_size' => ['w' => 6, 'h' => 3],
                'min_size' => ['w' => 4, 'h' => 2],
            ],
            self::TREND_ANALYSIS => [
                'name' => 'Анализ трендов',
                'description' => 'Анализ тенденций и паттернов в данных',
                'icon' => 'trending-up',
                'default_size' => ['w' => 12, 'h' => 4],
                'min_size' => ['w' => 6, 'h' => 3],
            ],

            self::RECENT_ACTIVITY => [
                'name' => 'Недавняя активность',
                'description' => 'Последние действия в системе',
                'icon' => 'activity',
                'default_size' => ['w' => 12, 'h' => 3],
                'min_size' => ['w' => 6, 'h' => 2],
            ],
            self::SYSTEM_EVENTS => [
                'name' => 'События системы',
                'description' => 'Важные системные события',
                'icon' => 'bell',
                'default_size' => ['w' => 6, 'h' => 3],
                'min_size' => ['w' => 4, 'h' => 2],
            ],
            self::USER_ACTIONS => [
                'name' => 'Действия пользователей',
                'description' => 'История действий пользователей',
                'icon' => 'user',
                'default_size' => ['w' => 6, 'h' => 3],
                'min_size' => ['w' => 4, 'h' => 2],
            ],
            self::NOTIFICATIONS => [
                'name' => 'Уведомления',
                'description' => 'Центр уведомлений системы',
                'icon' => 'bell',
                'default_size' => ['w' => 6, 'h' => 3],
                'min_size' => ['w' => 4, 'h' => 2],
            ],
            self::AUDIT_LOG => [
                'name' => 'Журнал аудита',
                'description' => 'Детальный журнал всех действий',
                'icon' => 'file-text',
                'default_size' => ['w' => 12, 'h' => 4],
                'min_size' => ['w' => 6, 'h' => 3],
            ],

            self::SYSTEM_METRICS => [
                'name' => 'Использование платформы',
                'description' => 'Метрики использования платформы вашей организацией',
                'icon' => 'server',
                'default_size' => ['w' => 6, 'h' => 3],
                'min_size' => ['w' => 4, 'h' => 2],
            ],
            self::API_PERFORMANCE => [
                'name' => 'Активность организации',
                'description' => 'Метрики активности и использования системы вашей организацией',
                'icon' => 'zap',
                'default_size' => ['w' => 6, 'h' => 3],
                'min_size' => ['w' => 4, 'h' => 2],
            ],
            self::DATABASE_STATS => [
                'name' => 'Объем данных',
                'description' => 'Статистика по объему данных вашей организации',
                'icon' => 'database',
                'default_size' => ['w' => 6, 'h' => 3],
                'min_size' => ['w' => 4, 'h' => 2],
            ],
            self::CACHE_STATS => [
                'name' => 'Статистика кеширования',
                'description' => 'Информация о кешировании данных вашей организации',
                'icon' => 'layers',
                'default_size' => ['w' => 6, 'h' => 3],
                'min_size' => ['w' => 4, 'h' => 2],
            ],
            self::RESPONSE_TIMES => [
                'name' => 'Производительность запросов',
                'description' => 'Анализ скорости загрузки данных вашей организации',
                'icon' => 'clock',
                'default_size' => ['w' => 12, 'h' => 4],
                'min_size' => ['w' => 6, 'h' => 3],
            ],
        };

        return array_merge(['id' => $this->value, 'category' => $this->getCategory()->value], $metadata);
    }
}

