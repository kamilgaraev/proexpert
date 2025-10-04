# Структура модуля "Advanced Dashboard"

## Директория модуля

```
app/BusinessModules/Features/AdvancedDashboard/
├── AdvancedDashboardModule.php
├── AdvancedDashboardServiceProvider.php
├── routes.php
├── migrations/
│   ├── 2025_10_10_000001_create_dashboards_table.php
│   ├── 2025_10_10_000002_create_dashboard_alerts_table.php
│   └── 2025_10_10_000003_create_scheduled_reports_table.php
├── Services/
│   ├── FinancialAnalyticsService.php
│   ├── PredictiveAnalyticsService.php
│   ├── KPICalculationService.php
│   ├── DashboardLayoutService.php
│   ├── AlertsService.php
│   ├── DashboardExportService.php
│   └── Widgets/
│       ├── AbstractWidgetProvider.php
│       ├── FinancialWidgets/
│       │   ├── CashFlowWidget.php
│       │   ├── ProfitLossWidget.php
│       │   ├── ROIWidget.php
│       │   ├── RevenueForecastWidget.php
│       │   └── ReceivablesPayablesWidget.php
│       ├── PredictiveWidgets/
│       │   ├── ContractForecastWidget.php
│       │   ├── BudgetRiskWidget.php
│       │   └── MaterialNeedsWidget.php
│       └── HRWidgets/
│           ├── KPIWidget.php
│           ├── TopPerformersWidget.php
│           └── ResourceUtilizationWidget.php
├── Http/
│   ├── Controllers/
│   │   ├── AdvancedDashboardController.php
│   │   ├── DashboardManagementController.php
│   │   ├── AlertsController.php
│   │   └── ExportController.php
│   ├── Requests/
│   │   ├── CreateDashboardRequest.php
│   │   ├── UpdateDashboardLayoutRequest.php
│   │   ├── CreateAlertRequest.php
│   │   └── ScheduleReportRequest.php
│   └── Middleware/
│       └── EnsureAdvancedDashboardActive.php
├── Models/
│   ├── Dashboard.php
│   ├── DashboardAlert.php
│   └── ScheduledReport.php
├── Jobs/
│   ├── CalculatePredictiveAnalytics.php
│   ├── CalculateKPIMetrics.php
│   ├── SendScheduledReport.php
│   └── CheckAlertConditions.php
├── Events/
│   ├── DashboardCreated.php
│   ├── AlertTriggered.php
│   └── ReportGenerated.php
├── Listeners/
│   ├── InvalidateDashboardCache.php
│   └── SendAlertNotification.php
└── README.md
```

## Основной класс модуля

```php
<?php
// app/BusinessModules/Features/AdvancedDashboard/AdvancedDashboardModule.php

namespace App\BusinessModules\Features\AdvancedDashboard;

use App\Modules\Contracts\ModuleInterface;
use App\Modules\Contracts\ConfigurableInterface;
use App\Enums\ModuleType;
use App\Enums\BillingModel;

class AdvancedDashboardModule implements ModuleInterface, ConfigurableInterface
{
    public function getName(): string
    {
        return 'Продвинутый дашборд';
    }

    public function getSlug(): string
    {
        return 'advanced-dashboard';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Расширенный дашборд с финансовой аналитикой, прогнозами и множественными дашбордами';
    }

    public function getType(): ModuleType
    {
        return ModuleType::FEATURE;
    }

    public function getBillingModel(): BillingModel
    {
        return BillingModel::SUBSCRIPTION;
    }

    public function getManifest(): array
    {
        return json_decode(
            file_get_contents(config_path('ModuleList/features/advanced-dashboard.json')), 
            true
        );
    }

    public function install(): void
    {
        // Запуск миграций
        // Создание демо-дашбордов
    }

    public function uninstall(): void
    {
        // Очистка данных модуля
    }

    public function upgrade(string $fromVersion): void
    {
        // Логика обновления модуля
    }

    public function canActivate(int $organizationId): bool
    {
        $accessController = app(\App\Modules\Core\AccessController::class);
        return $accessController->hasModuleAccess($organizationId, 'dashboard-widgets');
    }

    public function getDependencies(): array
    {
        return ['dashboard-widgets', 'organizations', 'users'];
    }

    public function getConflicts(): array
    {
        return [];
    }

    public function getPermissions(): array
    {
        return [
            'advanced_dashboard.view',
            'advanced_dashboard.financial_analytics',
            'advanced_dashboard.predictive_analytics',
            'advanced_dashboard.kpi_tracking',
            'advanced_dashboard.multiple_dashboards',
            'advanced_dashboard.custom_dashboards',
            'advanced_dashboard.dashboard_sharing',
            'advanced_dashboard.real_time_updates',
            'advanced_dashboard.alerts',
            'advanced_dashboard.export_pdf',
            'advanced_dashboard.export_excel',
            'advanced_dashboard.scheduled_reports',
            'advanced_dashboard.api_access',
            'advanced_dashboard.drill_down',
            'advanced_dashboard.comparison_analytics'
        ];
    }

    public function getFeatures(): array
    {
        return [
            'Финансовая аналитика (Cash Flow, P&L, ROI)',
            'Предиктивная аналитика и прогнозы',
            'HR-аналитика и KPI сотрудников',
            'Сравнительная аналитика',
            'До 10 именованных дашбордов',
            'Drag-and-drop редактор',
            'Система умных алертов',
            'Real-time обновления',
            'Экспорт в PDF/Excel',
            'Планировщик отчетов',
            'API доступ',
            'История данных за 12 месяцев'
        ];
    }

    public function getLimits(): array
    {
        return [
            'max_dashboards_per_organization' => 10,
            'max_dashboards_per_user' => 5,
            'max_widgets_per_dashboard' => 30,
            'max_alerts_per_user' => 20,
            'max_scheduled_reports' => 15,
            'data_retention_months' => 12,
            'real_time_connections' => 50,
            'api_requests_per_hour' => 1000
        ];
    }

    public function getDefaultSettings(): array
    {
        return [
            'financial_analytics' => [
                'enabled' => true,
                'cash_flow_forecast_months' => 3,
                'show_confidence_interval' => true
            ],
            'predictive_analytics' => [
                'enabled' => true,
                'prediction_algorithm' => 'linear_regression',
                'min_data_points' => 5
            ],
            'real_time' => [
                'enabled' => true,
                'update_interval_seconds' => 30,
                'websocket_enabled' => true
            ],
            'alerts' => [
                'enabled' => true,
                'email_notifications' => true,
                'push_notifications' => true
            ],
            'export' => [
                'pdf_quality' => 'high',
                'include_branding' => true,
                'watermark' => false
            ]
        ];
    }

    public function validateSettings(array $settings): bool
    {
        // Валидация настроек модуля
        return true;
    }

    public function applySettings(int $organizationId, array $settings): void
    {
        // Применение настроек модуля
    }

    public function getSettings(int $organizationId): array
    {
        // Получение настроек модуля
        return $this->getDefaultSettings();
    }
}
```

## Service Provider

```php
<?php
// app/BusinessModules/Features/AdvancedDashboard/AdvancedDashboardServiceProvider.php

namespace App\BusinessModules\Features\AdvancedDashboard;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;

class AdvancedDashboardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Регистрация сервисов
        $this->app->singleton(
            \App\BusinessModules\Features\AdvancedDashboard\Services\FinancialAnalyticsService::class
        );
        
        $this->app->singleton(
            \App\BusinessModules\Features\AdvancedDashboard\Services\PredictiveAnalyticsService::class
        );
        
        $this->app->singleton(
            \App\BusinessModules\Features\AdvancedDashboard\Services\KPICalculationService::class
        );
        
        $this->app->singleton(
            \App\BusinessModules\Features\AdvancedDashboard\Services\DashboardLayoutService::class
        );
        
        $this->app->singleton(
            \App\BusinessModules\Features\AdvancedDashboard\Services\AlertsService::class
        );
        
        $this->app->singleton(
            \App\BusinessModules\Features\AdvancedDashboard\Services\DashboardExportService::class
        );
    }

    public function boot(): void
    {
        // Загрузка миграций
        $this->loadMigrationsFrom(__DIR__ . '/migrations');
        
        // Регистрация роутов
        $this->registerRoutes();
        
        // Регистрация Events & Listeners
        $this->registerEventListeners();
        
        // Регистрация команд
        $this->registerCommands();
    }
    
    protected function registerRoutes(): void
    {
        Route::middleware(['api', 'auth:api_admin', 'module.required:advanced-dashboard'])
            ->prefix('api/v1/admin/advanced-dashboard')
            ->name('admin.advanced_dashboard.')
            ->group(__DIR__ . '/routes.php');
    }
    
    protected function registerEventListeners(): void
    {
        // Event::listen(...);
    }
    
    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                // Console commands
            ]);
        }
    }
}
```

## Middleware для проверки активации модуля

```php
<?php
// app/BusinessModules/Features/AdvancedDashboard/Http/Middleware/EnsureAdvancedDashboardActive.php

namespace App\BusinessModules\Features\AdvancedDashboard\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Modules\Core\AccessController;

class EnsureAdvancedDashboardActive
{
    protected AccessController $accessController;
    
    public function __construct(AccessController $accessController)
    {
        $this->accessController = $accessController;
    }
    
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $organizationId = $user->current_organization_id;
        
        if (!$this->accessController->hasModuleAccess($organizationId, 'advanced-dashboard')) {
            return response()->json([
                'success' => false,
                'upgrade_required' => true,
                'module' => [
                    'slug' => 'advanced-dashboard',
                    'name' => 'Продвинутый дашборд',
                    'price' => 4990,
                    'currency' => 'RUB',
                    'trial_available' => true,
                    'trial_days' => 14
                ],
                'message' => 'Для доступа к этой функции активируйте модуль "Продвинутый дашборд"'
            ], 402); // Payment Required
        }
        
        return $next($request);
    }
}
```

## Routes

```php
<?php
// app/BusinessModules/Features/AdvancedDashboard/routes.php

use Illuminate\Support\Facades\Route;
use App\BusinessModules\Features\AdvancedDashboard\Http\Controllers\AdvancedDashboardController;
use App\BusinessModules\Features\AdvancedDashboard\Http\Controllers\DashboardManagementController;
use App\BusinessModules\Features\AdvancedDashboard\Http\Controllers\AlertsController;
use App\BusinessModules\Features\AdvancedDashboard\Http\Controllers\ExportController;

// Финансовая аналитика
Route::prefix('financial')->name('financial.')->group(function () {
    Route::get('cashflow', [AdvancedDashboardController::class, 'getCashFlow']);
    Route::get('profit-loss', [AdvancedDashboardController::class, 'getProfitAndLoss']);
    Route::get('roi', [AdvancedDashboardController::class, 'getROI']);
    Route::get('forecast', [AdvancedDashboardController::class, 'getRevenueForecast']);
    Route::get('receivables-payables', [AdvancedDashboardController::class, 'getReceivablesPayables']);
});

// Предиктивная аналитика
Route::prefix('predictive')->name('predictive.')->group(function () {
    Route::get('contract-forecast/{id}', [AdvancedDashboardController::class, 'predictContractCompletion']);
    Route::get('budget-risks', [AdvancedDashboardController::class, 'predictBudgetOverruns']);
    Route::get('material-needs', [AdvancedDashboardController::class, 'predictMaterialNeeds']);
});

// KPI и HR-аналитика
Route::prefix('kpi')->name('kpi.')->group(function () {
    Route::get('user/{id}', [AdvancedDashboardController::class, 'getUserKPI']);
    Route::get('top-performers', [AdvancedDashboardController::class, 'getTopPerformers']);
    Route::get('resource-utilization', [AdvancedDashboardController::class, 'getResourceUtilization']);
});

// Управление дашбордами
Route::prefix('dashboards')->name('dashboards.')->group(function () {
    Route::get('/', [DashboardManagementController::class, 'index']);
    Route::post('/', [DashboardManagementController::class, 'store']);
    Route::get('/{id}', [DashboardManagementController::class, 'show']);
    Route::put('/{id}', [DashboardManagementController::class, 'update']);
    Route::delete('/{id}', [DashboardManagementController::class, 'destroy']);
    Route::post('/{id}/clone', [DashboardManagementController::class, 'clone']);
    Route::post('/{id}/share', [DashboardManagementController::class, 'share']);
    Route::get('/templates/list', [DashboardManagementController::class, 'templates']);
});

// Алерты
Route::apiResource('alerts', AlertsController::class);
Route::get('alerts/history', [AlertsController::class, 'history']);

// Экспорт
Route::prefix('export')->name('export.')->group(function () {
    Route::post('pdf', [ExportController::class, 'exportPDF']);
    Route::post('excel', [ExportController::class, 'exportExcel']);
});

// Планировщик отчетов
Route::apiResource('scheduled-reports', ScheduledReportController::class);
```

## Миграции

```php
<?php
// migrations/2025_10_10_000001_create_dashboards_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('dashboards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('organization_id');
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->json('layout'); // Виджеты и их расположение
            $table->boolean('is_default')->default(false);
            $table->boolean('is_shared')->default(false);
            $table->string('template_id', 50)->nullable(); // financial, operational, hr
            $table->json('global_filters')->nullable(); // Глобальные фильтры дашборда
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('cascade');
            
            $table->index(['user_id', 'organization_id']);
            $table->index('is_default');
        });
        
        // Таблица для шаринга дашбордов
        Schema::create('dashboard_shares', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('dashboard_id');
            $table->unsignedBigInteger('shared_with_user_id');
            $table->enum('permission', ['view', 'edit'])->default('view');
            $table->timestamps();
            
            $table->foreign('dashboard_id')->references('id')->on('dashboards')->onDelete('cascade');
            $table->foreign('shared_with_user_id')->references('id')->on('users')->onDelete('cascade');
            
            $table->unique(['dashboard_id', 'shared_with_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_shares');
        Schema::dropIfExists('dashboards');
    }
};
```

```php
<?php
// migrations/2025_10_10_000002_create_dashboard_alerts_table.php

return new class extends Migration {
    public function up(): void
    {
        Schema::create('dashboard_alerts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('organization_id');
            $table->string('name', 100);
            $table->string('type', 50); // budget_overrun, deadline_risk, contract_limit
            $table->json('conditions'); // {metric: 'budget', operator: '>', threshold: 90}
            $table->enum('severity', ['low', 'medium', 'high', 'critical']);
            $table->boolean('is_active')->default(true);
            $table->boolean('email_enabled')->default(true);
            $table->boolean('push_enabled')->default(true);
            $table->timestamp('last_triggered_at')->nullable();
            $table->integer('trigger_count')->default(0);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('cascade');
            
            $table->index(['user_id', 'is_active']);
        });
        
        // История срабатывания алертов
        Schema::create('alert_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('alert_id');
            $table->json('data'); // Данные, которые вызвали алерт
            $table->timestamp('triggered_at');
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by_user_id')->nullable();
            
            $table->foreign('alert_id')->references('id')->on('dashboard_alerts')->onDelete('cascade');
            $table->index(['alert_id', 'triggered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_history');
        Schema::dropIfExists('dashboard_alerts');
    }
};
```

```php
<?php
// migrations/2025_10_10_000003_create_scheduled_reports_table.php

return new class extends Migration {
    public function up(): void
    {
        Schema::create('scheduled_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('dashboard_id');
            $table->unsignedBigInteger('user_id');
            $table->string('name', 100);
            $table->enum('frequency', ['daily', 'weekly', 'monthly']); 
            $table->string('day_of_week')->nullable(); // 'monday', 'friday'
            $table->integer('day_of_month')->nullable(); // 1-31
            $table->time('time'); // Время отправки
            $table->enum('format', ['pdf', 'excel']);
            $table->json('recipients'); // Email адреса
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('next_send_at')->nullable();
            $table->timestamps();

            $table->foreign('dashboard_id')->references('id')->on('dashboards')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            
            $table->index(['is_active', 'next_send_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_reports');
    }
};
```

---

**Версия**: 1.0.0  
**Дата**: 4 октября 2025

