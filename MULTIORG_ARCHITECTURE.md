# 🏗️ Архитектура модуля "Мультиорганизация" для высоконагруженных систем

## 🎯 Принципы проектирования

### 1. **Event-Driven Architecture (EDA)**
- Асинхронная обработка через Laravel Events/Queues
- Разделение чтения и записи (CQRS)
- Event Sourcing для аудита и восстановления состояний

### 2. **Микросервисная архитектура внутри монолита**
- Доменно-ориентированное проектирование (DDD)
- Слабая связанность между модулями
- Независимое масштабирование компонентов

### 3. **Высокая производительность**
- Агрессивное кэширование (Redis)
- Оптимизация запросов к БД
- Асинхронная генерация отчетов
- Предварительные вычисления (materialized views)

## 🗂️ Структура модулей

```
app/BusinessModules/Enterprise/MultiOrganization/
├── Core/                           # Ядро системы
│   ├── Domain/                     # Доменные модели
│   │   ├── Models/
│   │   │   ├── HoldingAggregate.php
│   │   │   ├── OrganizationUnit.php
│   │   │   └── HierarchyPath.php
│   │   ├── Events/                 # Доменные события
│   │   │   ├── ChildOrganizationAdded.php
│   │   │   ├── OrganizationDataUpdated.php
│   │   │   └── HierarchyChanged.php
│   │   ├── Services/               # Доменные сервисы
│   │   │   ├── HoldingService.php
│   │   │   └── HierarchyService.php
│   │   └── Repositories/           # Интерфейсы репозиториев
│   ├── Infrastructure/             # Инфраструктура
│   │   ├── Repositories/           # Реализации репозиториев
│   │   ├── Caching/               # Стратегии кэширования
│   │   └── EventHandlers/         # Обработчики событий
│   └── Application/               # Слой приложения
│       ├── Commands/              # Command handlers
│       ├── Queries/               # Query handlers
│       └── DTOs/                  # Data Transfer Objects
├── Reporting/                     # Модуль отчетности (ПРИОРИТЕТ)
│   ├── Domain/
│   │   ├── ReportEngine.php
│   │   ├── DataAggregator.php
│   │   └── KPICalculator.php
│   ├── Infrastructure/
│   │   ├── ReportCache/           # Кэш отчетов
│   │   ├── DataWarehouse/         # Аналитическая БД
│   │   └── ExportEngines/         # Excel, PDF экспорт
│   └── Application/
│       ├── ReportBuilders/        # Построители отчетов
│       ├── Schedulers/            # Планировщики отчетов
│       └── APIs/                  # API для отчетов
├── Analytics/                     # Модуль аналитики
│   ├── Domain/
│   │   ├── MetricsEngine.php
│   │   ├── TrendAnalyzer.php
│   │   └── Forecaster.php
│   ├── Infrastructure/
│   │   ├── ClickHouse/            # Аналитическая БД
│   │   ├── MetricsCollector/      # Сбор метрик
│   │   └── Calculators/           # Вычислители KPI
│   └── Application/
│       ├── DashboardBuilders/
│       ├── AlertManagers/
│       └── APIs/
├── WebsiteBuilder/                # Модуль сайтов
│   ├── Domain/
│   │   ├── SiteAggregate.php
│   │   ├── PageBuilder.php
│   │   └── ContentManager.php
│   └── Infrastructure/
│       ├── Themes/
│       ├── ContentBlocks/
│       └── SEOEngine/
└── ProcessManagement/             # Управление процессами
    ├── Domain/
    │   ├── WorkflowEngine.php
    │   └── ApprovalChain.php
    └── Infrastructure/
        ├── StateMachine/
        └── NotificationEngine/
```

## 🗄️ Архитектура данных

### Основные БД (PostgreSQL)
```sql
-- Основные таблицы
organizations               # Существующая
organization_groups         # Существующая
organization_access_permissions  # Только что созданная

-- Новые таблицы для отчетности
organization_metrics        # Кэшированные метрики
consolidated_reports        # Предвычисленные отчеты
report_schedules           # Расписания генерации отчетов
kpi_definitions           # Определения KPI
data_snapshots            # Снимки данных для сравнения

-- Таблицы для сайтов
holding_sites             # Настройки сайтов
site_content_blocks       # Блоки контента
site_themes              # Темы оформления
```

### Аналитическая БД (ClickHouse)
```sql
-- Временные ряды для аналитики
organization_events       # События организаций
daily_metrics            # Ежедневные метрики
project_analytics        # Аналитика по проектам
financial_timeseries     # Финансовые временные ряды
```

### Кэширование (Redis)
```redis
# Структуры кэша
holding:{id}:dashboard     # Дашборд холдинга (TTL: 1 час)
holding:{id}:hierarchy     # Иерархия (TTL: 24 часа)
org:{id}:metrics          # Метрики организации (TTL: 30 мин)
report:{id}:data          # Данные отчета (TTL: 6 часов)
```

## ⚡ Производительность и масштабирование

### 1. **Асинхронная обработка**
```php
// События для асинхронной обработки
Event::dispatch(new OrganizationDataUpdated($organization));

// Обработчики событий
class UpdateConsolidatedMetrics implements ShouldQueue
{
    public function handle(OrganizationDataUpdated $event)
    {
        // Пересчет консолидированных метрик
        $this->metricsCalculator->recalculate($event->organization);
        
        // Инвалидация кэша
        $this->cache->invalidateHoldingCache($event->organization->holding_id);
    }
}
```

### 2. **Предварительные вычисления**
```php
// Материализованные представления для отчетов
class ConsolidatedMetricsCalculator
{
    public function calculateDaily(): void
    {
        // Пересчет метрик раз в день
        DB::statement('REFRESH MATERIALIZED VIEW consolidated_daily_metrics');
        
        // Кэширование в Redis
        $this->cacheService->storeDailyMetrics($metrics);
    }
}
```

### 3. **Оптимизация запросов**
```php
// Eager loading для предотвращения N+1
$holdings = OrganizationGroup::with([
    'parentOrganization.childOrganizations.projects',
    'parentOrganization.childOrganizations.contracts'
])->get();

// Использование индексов для быстрого поиска
// Migration
$table->index(['parent_organization_id', 'created_at']);
$table->index(['organization_type', 'is_holding']);
```

## 🔄 Event-Driven Architecture

### Ключевые события
```php
// Доменные события
class ChildOrganizationAdded extends Event
{
    public function __construct(
        public Organization $parentOrg,
        public Organization $childOrg,
        public User $createdBy
    ) {}
}

class OrganizationMetricsUpdated extends Event
{
    public function __construct(
        public int $organizationId,
        public array $metrics,
        public Carbon $calculatedAt
    ) {}
}

class ReportGenerated extends Event
{
    public function __construct(
        public int $reportId,
        public string $reportType,
        public array $recipients
    ) {}
}
```

### Обработчики событий
```php
// Обновление консолидированных данных
class UpdateHoldingDashboard implements ShouldQueue
{
    public function handle(ChildOrganizationAdded $event): void
    {
        // Обновляем дашборд холдинга
        $this->holdingService->updateDashboard($event->parentOrg);
        
        // Отправляем уведомления
        $this->notificationService->notifyHoldingManagers($event);
    }
}

// Генерация отчетов
class GenerateScheduledReports implements ShouldQueue
{
    public function handle(OrganizationMetricsUpdated $event): void
    {
        $scheduledReports = $this->reportScheduler
            ->getActiveSchedules($event->organizationId);
            
        foreach ($scheduledReports as $schedule) {
            GenerateReport::dispatch($schedule);
        }
    }
}
```

## 🚀 API архитектура

### RESTful API с GraphQL для сложных запросов
```php
// REST для CRUD операций
Route::apiResource('holdings', HoldingController::class);
Route::apiResource('holdings.organizations', ChildOrganizationController::class);

// GraphQL для сложных аналитических запросов
# schema.graphql
type Holding {
    id: ID!
    name: String!
    organizations: [Organization!]!
    metrics: HoldingMetrics!
    reports(type: ReportType, period: DateRange): [Report!]!
}
```

### Command Query Responsibility Segregation (CQRS)
```php
// Commands (запись)
class CreateChildOrganizationCommand
{
    public function __construct(
        public int $holdingId,
        public array $organizationData,
        public array $ownerData
    ) {}
}

// Queries (чтение)
class GetHoldingDashboardQuery
{
    public function __construct(
        public int $holdingId,
        public ?DateRange $period = null
    ) {}
}

// Отдельные обработчики
class CreateChildOrganizationHandler
{
    public function handle(CreateChildOrganizationCommand $command): Organization
    {
        // Бизнес-логика создания
    }
}

class HoldingDashboardQueryHandler
{
    public function handle(GetHoldingDashboardQuery $query): HoldingDashboard
    {
        // Чтение из кэша или вычисление
        return $this->cache->remember("dashboard:{$query->holdingId}", 3600, fn() => 
            $this->dashboardBuilder->build($query)
        );
    }
}
```

## 📊 Мониторинг и метрики

### Application Performance Monitoring
```php
// Метрики производительности
class PerformanceMetrics
{
    public function trackReportGeneration(string $reportType, float $duration): void
    {
        Metrics::histogram('report_generation_duration')
            ->withTags(['type' => $reportType])
            ->observe($duration);
    }
    
    public function trackCacheHitRate(string $cacheKey, bool $hit): void
    {
        Metrics::counter('cache_requests_total')
            ->withTags(['key_type' => $this->getCacheKeyType($cacheKey), 'hit' => $hit])
            ->increment();
    }
}
```

### Health Checks
```php
class MultiOrgHealthCheck implements HealthCheck
{
    public function check(): HealthStatus
    {
        return HealthStatus::create()
            ->checkDatabase()
            ->checkRedis()
            ->checkQueues()
            ->checkClickHouse()
            ->status();
    }
}
```

## 🔧 Конфигурация и управление

### Feature Flags
```php
// Включение/выключение фич на лету
class FeatureFlags
{
    public function isAdvancedAnalyticsEnabled(int $organizationId): bool
    {
        return $this->configService->isFeatureEnabled(
            'advanced_analytics', 
            $organizationId
        );
    }
    
    public function isRealTimeReportsEnabled(): bool
    {
        return $this->configService->isGlobalFeatureEnabled('realtime_reports');
    }
}
```

### Конфигурация модуля
```php
// config/multi_organization.php
return [
    'cache' => [
        'ttl' => [
            'dashboard' => 3600,
            'hierarchy' => 86400,
            'metrics' => 1800,
            'reports' => 21600,
        ]
    ],
    'queues' => [
        'reports' => 'reports',
        'analytics' => 'analytics',
        'notifications' => 'notifications',
    ],
    'limits' => [
        'max_organizations_per_holding' => 100,
        'max_report_history_days' => 365,
        'max_concurrent_report_generation' => 5,
    ]
];
```

## 🧪 Тестирование

### Модульное тестирование
```php
class HoldingServiceTest extends TestCase
{
    /** @test */
    public function it_calculates_consolidated_metrics_correctly(): void
    {
        // Given
        $holding = $this->createHoldingWithOrganizations(3);
        
        // When
        $metrics = $this->holdingService->calculateConsolidatedMetrics($holding);
        
        // Then
        $this->assertEquals(3, $metrics->organizationsCount);
        $this->assertGreaterThan(0, $metrics->totalRevenue);
    }
}
```

### Интеграционное тестирование
```php
class ReportGenerationIntegrationTest extends TestCase
{
    /** @test */
    public function it_generates_holding_report_end_to_end(): void
    {
        // Given
        $holding = $this->createHoldingWithData();
        
        // When
        $command = new GenerateHoldingReportCommand($holding->id, 'monthly');
        $report = $this->commandBus->handle($command);
        
        // Then
        $this->assertInstanceOf(Report::class, $report);
        $this->assertEquals('monthly', $report->type);
        $this->assertNotEmpty($report->data);
    }
}
```

---

## 🎯 Следующие шаги

1. **Создать базовую структуру модулей** (1 неделя)
2. **Настроить Event-Driven инфраструктуру** (1 неделя)  
3. **Реализовать базовые репозитории и сервисы** (1 неделя)
4. **Создать систему кэширования** (1 неделя)

**Готов начать с какого-то конкретного компонента?** Предлагаю начать с **ядра системы** и **базового модуля отчетности**.
