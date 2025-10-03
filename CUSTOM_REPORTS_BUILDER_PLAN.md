# План реализации конструктора отчетов

## 📊 Обзор

Конструктор отчетов - функциональность модуля `advanced-reports`, позволяющая пользователям создавать кастомные отчеты с гибкой настройкой источников данных, фильтров, агрегаций и автоматической генерации.

**Модуль:** `advanced-reports`  
**Permission:** `advanced_reports.custom_reports`  
**Тип:** Feature (платная функциональность)

---

## 🏗️ Архитектура системы

### Основные компоненты

1. **Модели данных** - CustomReport, CustomReportExecution, CustomReportSchedule
2. **Сервисы бизнес-логики** - построение запросов, выполнение, планирование
3. **API контроллеры** - CRUD операции, выполнение отчетов, управление расписанием
4. **Регистр источников данных** - метаданные доступных таблиц и полей
5. **Система безопасности** - валидация, контроль доступа, защита от SQL-инъекций

---

## 📁 Структура базы данных

### Таблица: `custom_reports`

Хранение пользовательских отчетов.

```sql
- id (bigint, PK)
- name (string) - название отчета
- description (text, nullable) - описание
- organization_id (FK) - организация
- user_id (FK) - создатель
- report_category (string) - категория: finances, materials, works, projects, staff
- data_sources (json) - источники данных и их связи
  {
    "primary": "projects",
    "joins": [
      {"table": "contracts", "type": "left", "on": ["projects.id", "contracts.project_id"]},
      {"table": "organizations", "type": "inner", "on": ["projects.organization_id", "organizations.id"]}
    ]
  }
- query_config (json) - конфигурация запроса
  {
    "where": [
      {"field": "projects.status", "operator": "=", "value": "active"},
      {"field": "projects.created_at", "operator": ">=", "value": "2024-01-01"}
    ],
    "where_logic": "and"
  }
- columns_config (json) - колонки отчета
  [
    {
      "field": "projects.name",
      "label": "Название проекта",
      "order": 1,
      "format": "text",
      "width": 200
    },
    {
      "field": "projects.budget_amount",
      "label": "Бюджет",
      "order": 2,
      "format": "currency",
      "aggregation": "sum"
    }
  ]
- filters_config (json) - фильтры для пользователя
  [
    {
      "field": "projects.status",
      "label": "Статус проекта",
      "type": "select",
      "options": ["active", "completed", "on_hold"],
      "required": false,
      "default": null
    },
    {
      "field": "projects.start_date",
      "label": "Дата начала",
      "type": "date_range",
      "required": true
    }
  ]
- aggregations_config (json) - агрегации и группировки
  {
    "group_by": ["projects.status"],
    "aggregations": [
      {"field": "projects.budget_amount", "function": "sum", "alias": "total_budget"},
      {"field": "projects.id", "function": "count", "alias": "projects_count"}
    ],
    "having": [
      {"field": "total_budget", "operator": ">", "value": 100000}
    ]
  }
- sorting_config (json) - сортировка
  [
    {"field": "projects.created_at", "direction": "desc"},
    {"field": "projects.name", "direction": "asc"}
  ]
- visualization_config (json, nullable) - настройки графиков
  {
    "enabled": true,
    "chart_type": "bar",
    "x_axis": "projects.name",
    "y_axis": "total_budget",
    "colors": ["#3b82f6", "#10b981"]
  }
- is_shared (boolean) - доступен всей организации
- is_favorite (boolean) - избранный для текущего пользователя
- is_scheduled (boolean) - имеет активное расписание
- execution_count (integer) - счетчик выполнений
- last_executed_at (timestamp, nullable)
- created_at, updated_at, deleted_at
```

**Индексы:**
- `(organization_id, is_shared, deleted_at)`
- `(user_id, deleted_at)`
- `(report_category)`

---

### Таблица: `custom_report_executions`

История выполнения отчетов.

```sql
- id (bigint, PK)
- custom_report_id (FK) - отчет
- user_id (FK) - кто запустил
- organization_id (FK)
- applied_filters (json) - примененные фильтры
  {
    "projects.status": "active",
    "projects.start_date": {"from": "2024-01-01", "to": "2024-12-31"}
  }
- execution_time_ms (integer) - время выполнения в мс
- result_rows_count (integer) - количество строк результата
- export_format (string, nullable) - csv, excel, pdf
- export_file_id (string, nullable, FK to report_files)
- status (enum) - pending, processing, completed, failed, cancelled
- error_message (text, nullable)
- query_sql (text, nullable) - сохраненный SQL для отладки
- created_at (timestamp)
- completed_at (timestamp, nullable)
```

**Индексы:**
- `(custom_report_id, created_at)`
- `(user_id, created_at)`
- `(status, created_at)`

---

### Таблица: `custom_report_schedules`

Автоматическая генерация и отправка отчетов.

```sql
- id (bigint, PK)
- custom_report_id (FK)
- organization_id (FK)
- user_id (FK) - владелец расписания
- schedule_type (enum) - daily, weekly, monthly, custom_cron
- schedule_config (json)
  {
    "time": "09:00",
    "day_of_week": 1, // для weekly (1=Monday)
    "day_of_month": 1, // для monthly
    "cron_expression": "0 9 * * 1" // для custom_cron
  }
- filters_preset (json) - предустановленные фильтры для автозапуска
- recipient_emails (json) - список email для отправки
  ["user1@example.com", "user2@example.com"]
- export_format (enum) - csv, excel, pdf
- is_active (boolean)
- last_run_at (timestamp, nullable)
- next_run_at (timestamp, nullable)
- last_execution_id (FK to custom_report_executions, nullable)
- created_at, updated_at
```

**Индексы:**
- `(custom_report_id, is_active)`
- `(next_run_at, is_active)`
- `(organization_id)`

---

### Конфигурационный файл: `config/custom-reports.php`

Метаданные источников данных (альтернатива таблице).

```php
return [
    'data_sources' => [
        'projects' => [
            'table' => 'projects',
            'model' => \App\Models\Project::class,
            'label' => 'Проекты',
            'category' => 'core',
            'fields' => [
                'id' => ['type' => 'integer', 'label' => 'ID', 'filterable' => true, 'sortable' => true],
                'name' => ['type' => 'string', 'label' => 'Название', 'filterable' => true, 'sortable' => true],
                'address' => ['type' => 'string', 'label' => 'Адрес', 'filterable' => true],
                'budget_amount' => ['type' => 'decimal', 'label' => 'Бюджет', 'aggregatable' => true, 'format' => 'currency'],
                'status' => ['type' => 'enum', 'label' => 'Статус', 'filterable' => true, 'options' => ['active', 'completed', 'on_hold']],
                'start_date' => ['type' => 'date', 'label' => 'Дата начала', 'filterable' => true, 'sortable' => true],
                'end_date' => ['type' => 'date', 'label' => 'Дата окончания', 'filterable' => true, 'sortable' => true],
                'is_archived' => ['type' => 'boolean', 'label' => 'Архивный', 'filterable' => true],
                'organization_id' => ['type' => 'integer', 'label' => 'ID организации', 'filterable' => true],
            ],
            'relations' => [
                'organization' => [
                    'type' => 'belongsTo',
                    'target' => 'organizations',
                    'foreign_key' => 'organization_id',
                    'owner_key' => 'id',
                ],
                'contracts' => [
                    'type' => 'hasMany',
                    'target' => 'contracts',
                    'foreign_key' => 'project_id',
                    'local_key' => 'id',
                ],
                'materialReceipts' => [
                    'type' => 'hasMany',
                    'target' => 'material_receipts',
                    'foreign_key' => 'project_id',
                    'local_key' => 'id',
                ],
                'completedWorks' => [
                    'type' => 'hasMany',
                    'target' => 'completed_works',
                    'foreign_key' => 'project_id',
                    'local_key' => 'id',
                ],
            ],
            'default_filters' => [
                ['field' => 'organization_id', 'operator' => '=', 'value' => ':current_organization_id'],
                ['field' => 'is_archived', 'operator' => '=', 'value' => false],
            ],
        ],
        
        'contracts' => [
            'table' => 'contracts',
            'model' => \App\Models\Contract::class,
            'label' => 'Контракты',
            'category' => 'finances',
            'fields' => [
                'id' => ['type' => 'integer', 'label' => 'ID'],
                'number' => ['type' => 'string', 'label' => 'Номер контракта'],
                'date' => ['type' => 'date', 'label' => 'Дата контракта'],
                'total_amount' => ['type' => 'decimal', 'label' => 'Сумма', 'aggregatable' => true, 'format' => 'currency'],
                'status' => ['type' => 'enum', 'label' => 'Статус', 'filterable' => true],
                'project_id' => ['type' => 'integer', 'label' => 'ID проекта', 'filterable' => true],
                'contractor_id' => ['type' => 'integer', 'label' => 'ID подрядчика', 'filterable' => true],
            ],
            'relations' => [
                'project' => ['type' => 'belongsTo', 'target' => 'projects'],
                'contractor' => ['type' => 'belongsTo', 'target' => 'contractors'],
                'payments' => ['type' => 'hasMany', 'target' => 'contract_payments'],
            ],
        ],
        
        'materials' => [
            'table' => 'materials',
            'model' => \App\Models\Material::class,
            'label' => 'Материалы',
            'category' => 'materials',
            'fields' => [
                'id' => ['type' => 'integer', 'label' => 'ID'],
                'name' => ['type' => 'string', 'label' => 'Название', 'filterable' => true],
                'code' => ['type' => 'string', 'label' => 'Код', 'filterable' => true],
                'default_price' => ['type' => 'decimal', 'label' => 'Цена', 'aggregatable' => true, 'format' => 'currency'],
                'category' => ['type' => 'string', 'label' => 'Категория', 'filterable' => true],
            ],
            'relations' => [
                'organization' => ['type' => 'belongsTo', 'target' => 'organizations'],
                'measurementUnit' => ['type' => 'belongsTo', 'target' => 'measurement_units'],
                'receipts' => ['type' => 'hasMany', 'target' => 'material_receipts'],
            ],
        ],
        
        'completed_works' => [
            'table' => 'completed_works',
            'model' => \App\Models\CompletedWork::class,
            'label' => 'Выполненные работы',
            'category' => 'works',
            'fields' => [
                'id' => ['type' => 'integer', 'label' => 'ID'],
                'work_date' => ['type' => 'date', 'label' => 'Дата работы', 'filterable' => true],
                'quantity' => ['type' => 'decimal', 'label' => 'Объем', 'aggregatable' => true],
                'total_cost' => ['type' => 'decimal', 'label' => 'Стоимость', 'aggregatable' => true, 'format' => 'currency'],
                'project_id' => ['type' => 'integer', 'label' => 'ID проекта', 'filterable' => true],
                'work_type_id' => ['type' => 'integer', 'label' => 'ID вида работ', 'filterable' => true],
            ],
            'relations' => [
                'project' => ['type' => 'belongsTo', 'target' => 'projects'],
                'workType' => ['type' => 'belongsTo', 'target' => 'work_types'],
                'user' => ['type' => 'belongsTo', 'target' => 'users'],
            ],
        ],
        
        'users' => [
            'table' => 'users',
            'model' => \App\Models\User::class,
            'label' => 'Пользователи',
            'category' => 'staff',
            'fields' => [
                'id' => ['type' => 'integer', 'label' => 'ID'],
                'name' => ['type' => 'string', 'label' => 'Имя', 'filterable' => true],
                'email' => ['type' => 'string', 'label' => 'Email', 'filterable' => true],
                'created_at' => ['type' => 'datetime', 'label' => 'Дата регистрации', 'filterable' => true],
            ],
            'relations' => [
                'organizations' => ['type' => 'belongsToMany', 'target' => 'organizations'],
                'projects' => ['type' => 'belongsToMany', 'target' => 'projects'],
            ],
        ],
        
        'contractors' => [
            'table' => 'contractors',
            'model' => \App\Models\Contractor::class,
            'label' => 'Подрядчики',
            'category' => 'finances',
            'fields' => [
                'id' => ['type' => 'integer', 'label' => 'ID'],
                'name' => ['type' => 'string', 'label' => 'Название', 'filterable' => true],
                'inn' => ['type' => 'string', 'label' => 'ИНН', 'filterable' => true],
                'contact_person' => ['type' => 'string', 'label' => 'Контактное лицо'],
                'phone' => ['type' => 'string', 'label' => 'Телефон'],
            ],
            'relations' => [
                'organization' => ['type' => 'belongsTo', 'target' => 'organizations'],
                'contracts' => ['type' => 'hasMany', 'target' => 'contracts'],
            ],
        ],
        
        'material_receipts' => [
            'table' => 'material_receipts',
            'model' => \App\Models\MaterialReceipt::class,
            'label' => 'Приемки материалов',
            'category' => 'materials',
            'fields' => [
                'id' => ['type' => 'integer', 'label' => 'ID'],
                'receipt_date' => ['type' => 'date', 'label' => 'Дата приемки', 'filterable' => true],
                'quantity' => ['type' => 'decimal', 'label' => 'Количество', 'aggregatable' => true],
                'price_per_unit' => ['type' => 'decimal', 'label' => 'Цена за единицу', 'format' => 'currency'],
                'total_price' => ['type' => 'decimal', 'label' => 'Общая стоимость', 'aggregatable' => true, 'format' => 'currency'],
                'project_id' => ['type' => 'integer', 'label' => 'ID проекта', 'filterable' => true],
                'material_id' => ['type' => 'integer', 'label' => 'ID материала', 'filterable' => true],
            ],
            'relations' => [
                'project' => ['type' => 'belongsTo', 'target' => 'projects'],
                'material' => ['type' => 'belongsTo', 'target' => 'materials'],
                'supplier' => ['type' => 'belongsTo', 'target' => 'suppliers'],
            ],
        ],
        
        'time_entries' => [
            'table' => 'time_entries',
            'model' => \App\Models\TimeEntry::class,
            'label' => 'Учет рабочего времени',
            'category' => 'staff',
            'fields' => [
                'id' => ['type' => 'integer', 'label' => 'ID'],
                'date' => ['type' => 'date', 'label' => 'Дата', 'filterable' => true],
                'hours' => ['type' => 'decimal', 'label' => 'Часы', 'aggregatable' => true],
                'user_id' => ['type' => 'integer', 'label' => 'ID пользователя', 'filterable' => true],
                'project_id' => ['type' => 'integer', 'label' => 'ID проекта', 'filterable' => true],
            ],
            'relations' => [
                'user' => ['type' => 'belongsTo', 'target' => 'users'],
                'project' => ['type' => 'belongsTo', 'target' => 'projects'],
            ],
        ],
    ],
    
    'limits' => [
        'max_joins' => 7,
        'max_result_rows' => 10000,
        'query_timeout_seconds' => 30,
        'max_aggregations' => 10,
        'max_filters' => 20,
        'max_columns' => 50,
    ],
    
    'allowed_operators' => [
        '=' => 'Равно',
        '!=' => 'Не равно',
        '>' => 'Больше',
        '<' => 'Меньше',
        '>=' => 'Больше или равно',
        '<=' => 'Меньше или равно',
        'like' => 'Содержит',
        'not_like' => 'Не содержит',
        'in' => 'В списке',
        'not_in' => 'Не в списке',
        'between' => 'Между',
        'is_null' => 'Пусто',
        'is_not_null' => 'Не пусто',
    ],
    
    'aggregation_functions' => [
        'sum' => 'Сумма',
        'avg' => 'Среднее',
        'count' => 'Количество',
        'min' => 'Минимум',
        'max' => 'Максимум',
        'count_distinct' => 'Уникальные значения',
    ],
    
    'export_formats' => [
        'csv' => 'CSV',
        'excel' => 'Excel (XLSX)',
        'pdf' => 'PDF',
    ],
    
    'cache' => [
        'enabled' => true,
        'ttl' => 3600,
        'prefix' => 'custom_report',
    ],
];
```

---

## 🔧 Модели Eloquent

### CustomReport.php

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomReport extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'organization_id',
        'user_id',
        'report_category',
        'data_sources',
        'query_config',
        'columns_config',
        'filters_config',
        'aggregations_config',
        'sorting_config',
        'visualization_config',
        'is_shared',
        'is_favorite',
        'is_scheduled',
        'execution_count',
        'last_executed_at',
    ];

    protected $casts = [
        'data_sources' => 'array',
        'query_config' => 'array',
        'columns_config' => 'array',
        'filters_config' => 'array',
        'aggregations_config' => 'array',
        'sorting_config' => 'array',
        'visualization_config' => 'array',
        'is_shared' => 'boolean',
        'is_favorite' => 'boolean',
        'is_scheduled' => 'boolean',
        'execution_count' => 'integer',
        'last_executed_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function executions(): HasMany
    {
        return $this->hasMany(CustomReportExecution::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(CustomReportSchedule::class);
    }

    public function incrementExecutionCount(): void
    {
        $this->increment('execution_count');
        $this->update(['last_executed_at' => now()]);
    }
}
```

### CustomReportExecution.php

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomReportExecution extends Model
{
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'custom_report_id',
        'user_id',
        'organization_id',
        'applied_filters',
        'execution_time_ms',
        'result_rows_count',
        'export_format',
        'export_file_id',
        'status',
        'error_message',
        'query_sql',
        'completed_at',
    ];

    protected $casts = [
        'applied_filters' => 'array',
        'execution_time_ms' => 'integer',
        'result_rows_count' => 'integer',
        'completed_at' => 'datetime',
    ];

    public function customReport(): BelongsTo
    {
        return $this->belongsTo(CustomReport::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function exportFile(): BelongsTo
    {
        return $this->belongsTo(ReportFile::class, 'export_file_id');
    }
}
```

### CustomReportSchedule.php

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomReportSchedule extends Model
{
    const TYPE_DAILY = 'daily';
    const TYPE_WEEKLY = 'weekly';
    const TYPE_MONTHLY = 'monthly';
    const TYPE_CUSTOM_CRON = 'custom_cron';

    protected $fillable = [
        'custom_report_id',
        'organization_id',
        'user_id',
        'schedule_type',
        'schedule_config',
        'filters_preset',
        'recipient_emails',
        'export_format',
        'is_active',
        'last_run_at',
        'next_run_at',
        'last_execution_id',
    ];

    protected $casts = [
        'schedule_config' => 'array',
        'filters_preset' => 'array',
        'recipient_emails' => 'array',
        'is_active' => 'boolean',
        'last_run_at' => 'datetime',
        'next_run_at' => 'datetime',
    ];

    public function customReport(): BelongsTo
    {
        return $this->belongsTo(CustomReport::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lastExecution(): BelongsTo
    {
        return $this->belongsTo(CustomReportExecution::class, 'last_execution_id');
    }
}
```

---

## 🛠️ Сервисы

### ReportDataSourceRegistry.php

Регистр источников данных - предоставляет метаданные о доступных таблицах и полях.

```php
namespace App\Services\Report;

class ReportDataSourceRegistry
{
    protected array $dataSources;

    public function __construct()
    {
        $this->dataSources = config('custom-reports.data_sources');
    }

    public function getAllDataSources(): array;
    public function getDataSource(string $key): ?array;
    public function getAvailableFields(string $dataSourceKey): array;
    public function getAvailableRelations(string $dataSourceKey): array;
    public function getFieldMetadata(string $dataSourceKey, string $fieldKey): ?array;
    public function isFieldAggregatable(string $dataSourceKey, string $fieldKey): bool;
    public function isFieldFilterable(string $dataSourceKey, string $fieldKey): bool;
    public function validateDataSource(string $dataSourceKey): bool;
    public function validateRelation(string $fromSource, string $relationKey): bool;
}
```

### CustomReportBuilderService.php

Построение и валидация конфигурации отчетов.

```php
namespace App\Services\Report;

class CustomReportBuilderService
{
    public function __construct(
        protected ReportDataSourceRegistry $registry,
        protected ReportQueryBuilder $queryBuilder
    ) {}

    public function validateReportConfig(array $config): array;
    public function buildQueryFromConfig(CustomReport $report): Builder;
    public function testReportQuery(CustomReport $report, array $filters = []): array;
    public function estimateQueryComplexity(array $config): int;
    public function suggestOptimizations(array $config): array;
    public function cloneReport(CustomReport $report, int $userId): CustomReport;
}
```

### CustomReportExecutionService.php

Выполнение отчетов с применением фильтров и экспортом.

```php
namespace App\Services\Report;

class CustomReportExecutionService
{
    public function __construct(
        protected CustomReportBuilderService $builder,
        protected ReportFilterBuilder $filterBuilder,
        protected ReportAggregationBuilder $aggregationBuilder,
        protected CsvExporterService $csvExporter,
        protected ExcelExporterService $excelExporter,
        protected LoggingService $logging
    ) {}

    public function executeReport(
        CustomReport $report,
        array $filters = [],
        ?string $exportFormat = null,
        ?int $userId = null
    ): array|StreamedResponse;

    protected function createExecution(CustomReport $report, array $filters, int $userId): CustomReportExecution;
    protected function applyUserFilters(Builder $query, array $filters, array $filtersConfig): Builder;
    protected function executeQuery(Builder $query, int $limit): Collection;
    protected function formatResults(Collection $results, array $columnsConfig): array;
    protected function exportResults(Collection $results, CustomReport $report, string $format): StreamedResponse;
    protected function updateExecutionStatus(CustomReportExecution $execution, string $status, array $data = []): void;
}
```

### CustomReportSchedulerService.php

Управление расписанием автоматической генерации отчетов.

```php
namespace App\Services\Report;

class CustomReportSchedulerService
{
    public function __construct(
        protected CustomReportExecutionService $executionService,
        protected LoggingService $logging
    ) {}

    public function createSchedule(CustomReport $report, array $scheduleData): CustomReportSchedule;
    public function updateSchedule(CustomReportSchedule $schedule, array $data): CustomReportSchedule;
    public function executeScheduledReports(): void;
    public function calculateNextRunTime(CustomReportSchedule $schedule): Carbon;
    public function sendReportByEmail(CustomReport $report, array $recipients, string $filePath): void;
    public function deactivateSchedule(CustomReportSchedule $schedule): void;
}
```

### ReportQueryBuilder.php

Построение SQL запросов из конфигурации.

```php
namespace App\Services\Report\Builders;

use Illuminate\Database\Eloquent\Builder;

class ReportQueryBuilder
{
    public function __construct(
        protected ReportDataSourceRegistry $registry,
        protected ReportFilterBuilder $filterBuilder,
        protected ReportAggregationBuilder $aggregationBuilder
    ) {}

    public function buildFromConfig(array $config, int $organizationId): Builder;
    protected function buildBaseQuery(string $primarySource, int $organizationId): Builder;
    protected function applyJoins(Builder $query, array $joins): Builder;
    protected function applyWhereConditions(Builder $query, array $conditions): Builder;
    protected function applyDefaultFilters(Builder $query, string $dataSource, int $organizationId): Builder;
    protected function selectColumns(Builder $query, array $columnsConfig): Builder;
    protected function applySorting(Builder $query, array $sortingConfig): Builder;
}
```

### ReportFilterBuilder.php

Построение фильтров WHERE.

```php
namespace App\Services\Report\Builders;

class ReportFilterBuilder
{
    public function applyFilter(Builder $query, array $filter): Builder;
    protected function applyEqualsFilter(Builder $query, string $field, $value): Builder;
    protected function applyLikeFilter(Builder $query, string $field, string $value): Builder;
    protected function applyInFilter(Builder $query, string $field, array $values): Builder;
    protected function applyBetweenFilter(Builder $query, string $field, array $range): Builder;
    protected function applyNullFilter(Builder $query, string $field, bool $isNull): Builder;
    public function validateFilter(array $filter, string $dataSource): bool;
}
```

### ReportAggregationBuilder.php

Построение агрегаций и группировок.

```php
namespace App\Services\Report\Builders;

class ReportAggregationBuilder
{
    public function applyAggregations(Builder $query, array $config): Builder;
    protected function applyGroupBy(Builder $query, array $groupByFields): Builder;
    protected function applyAggregationFunctions(Builder $query, array $aggregations): Builder;
    protected function applyHavingConditions(Builder $query, array $havingConditions): Builder;
    public function validateAggregations(array $config, string $dataSource): bool;
}
```

### ReportQueryOptimizer.php

Оптимизация запросов.

```php
namespace App\Services\Report;

class ReportQueryOptimizer
{
    public function optimizeQuery(Builder $query): Builder;
    public function addEagerLoading(Builder $query, array $relations): Builder;
    public function estimateQueryCost(Builder $query): int;
    public function validateQueryPerformance(Builder $query): bool;
    public function suggestIndexes(array $config): array;
}
```

### ReportVisualizationService.php

Подготовка данных для графиков (опционально).

```php
namespace App\Services\Report;

class ReportVisualizationService
{
    public function generateChartData(Collection $results, array $chartConfig): array;
    public function getSupportedChartTypes(): array;
    protected function prepareLineChartData(Collection $results, array $config): array;
    protected function prepareBarChartData(Collection $results, array $config): array;
    protected function preparePieChartData(Collection $results, array $config): array;
}
```

---

## 🌐 API эндпоинты

### CustomReportController.php

CRUD операции для отчетов.

```
GET    /api/v1/admin/custom-reports
       Query params: category, is_shared, is_favorite, page, per_page
       Возвращает: список отчетов пользователя и общих отчетов организации

POST   /api/v1/admin/custom-reports
       Body: name, description, report_category, data_sources, query_config,
             columns_config, filters_config, aggregations_config, sorting_config,
             visualization_config, is_shared
       Возвращает: созданный отчет

GET    /api/v1/admin/custom-reports/{id}
       Возвращает: детали отчета

PUT    /api/v1/admin/custom-reports/{id}
       Body: те же поля что и при создании
       Возвращает: обновленный отчет

DELETE /api/v1/admin/custom-reports/{id}
       Soft delete отчета

POST   /api/v1/admin/custom-reports/{id}/clone
       Body: name (новое имя)
       Возвращает: клонированный отчет

POST   /api/v1/admin/custom-reports/{id}/favorite
       Добавление/удаление из избранного

POST   /api/v1/admin/custom-reports/{id}/share
       Body: is_shared (boolean)
       Управление общим доступом
```

### CustomReportBuilderController.php

Вспомогательные методы для конструктора.

```
GET    /api/v1/admin/custom-reports/builder/data-sources
       Возвращает: список всех доступных источников данных с метаданными

GET    /api/v1/admin/custom-reports/builder/data-sources/{source}/fields
       Возвращает: поля указанного источника данных

GET    /api/v1/admin/custom-reports/builder/data-sources/{source}/relations
       Возвращает: доступные связи с другими таблицами

POST   /api/v1/admin/custom-reports/builder/validate
       Body: конфигурация отчета
       Возвращает: результат валидации (ошибки или успех)

POST   /api/v1/admin/custom-reports/builder/preview
       Body: конфигурация отчета + фильтры
       Возвращает: первые 20 строк результата для предпросмотра

GET    /api/v1/admin/custom-reports/builder/operators
       Возвращает: список доступных операторов для фильтров

GET    /api/v1/admin/custom-reports/builder/aggregations
       Возвращает: список доступных агрегатных функций
```

### CustomReportExecutionController.php

Выполнение отчетов.

```
POST   /api/v1/admin/custom-reports/{id}/execute
       Body: filters (применяемые пользователем фильтры)
       Query params: export (csv|excel|pdf), limit, page
       Возвращает: результаты отчета или файл экспорта

GET    /api/v1/admin/custom-reports/{id}/executions
       Query params: page, per_page, status
       Возвращает: история выполнений отчета

GET    /api/v1/admin/custom-reports/executions/{executionId}
       Возвращает: детали конкретного выполнения

POST   /api/v1/admin/custom-reports/{id}/export
       Body: filters, format (csv|excel|pdf)
       Возвращает: файл экспорта (StreamedResponse)

DELETE /api/v1/admin/custom-reports/executions/{executionId}
       Удаление записи о выполнении (очистка истории)
```

### CustomReportScheduleController.php

Управление расписанием.

```
GET    /api/v1/admin/custom-reports/{id}/schedules
       Возвращает: список расписаний для отчета

POST   /api/v1/admin/custom-reports/{id}/schedules
       Body: schedule_type, schedule_config, filters_preset,
             recipient_emails, export_format
       Возвращает: созданное расписание

GET    /api/v1/admin/custom-reports/{id}/schedules/{scheduleId}
       Возвращает: детали расписания

PUT    /api/v1/admin/custom-reports/{id}/schedules/{scheduleId}
       Body: те же поля что и при создании
       Возвращает: обновленное расписание

DELETE /api/v1/admin/custom-reports/{id}/schedules/{scheduleId}
       Удаление расписания

POST   /api/v1/admin/custom-reports/{id}/schedules/{scheduleId}/toggle
       Активация/деактивация расписания

POST   /api/v1/admin/custom-reports/{id}/schedules/{scheduleId}/run-now
       Принудительный запуск расписания
```

---

## 🔒 Безопасность

### Защита от SQL-инъекций

1. **Whitelist источников данных** - только таблицы из config
2. **Whitelist полей** - только поля, определенные в конфигурации
3. **Whitelist операторов** - только разрешенные операторы фильтров
4. **Параметризованные запросы** - использование Query Builder Eloquent
5. **Валидация связей** - только разрешенные JOIN'ы

### Контроль доступа

1. **Middleware** - проверка `module.access:advanced-reports`
2. **Permission** - проверка `advanced_reports.custom_reports`
3. **Изоляция организаций** - автоматическая фильтрация по `organization_id`
4. **Права на отчет** - пользователь может редактировать только свои отчеты (кроме shared)

### Ограничения производительности

1. **Лимит JOIN'ов** - максимум 7 связанных таблиц
2. **Лимит строк** - максимум 10,000 строк результата
3. **Таймаут** - максимум 30 секунд выполнения
4. **Throttling** - ограничение запросов на выполнение отчетов (rate limiting)
5. **Асинхронное выполнение** - большие отчеты через очереди (Jobs)

### Валидация конфигурации

```php
// Пример валидации в CustomReportBuilderService
public function validateReportConfig(array $config): array
{
    $errors = [];

    if (!isset($config['data_sources']['primary'])) {
        $errors[] = 'Primary data source is required';
    }

    $primarySource = $config['data_sources']['primary'] ?? null;
    if ($primarySource && !$this->registry->validateDataSource($primarySource)) {
        $errors[] = "Invalid data source: {$primarySource}";
    }

    if (isset($config['data_sources']['joins'])) {
        if (count($config['data_sources']['joins']) > config('custom-reports.limits.max_joins')) {
            $errors[] = 'Too many joins';
        }
    }

    return $errors;
}
```

---

## 📋 Console Commands

### ExecuteScheduledReportsCommand.php

Выполнение отчетов по расписанию (запускается из CRON).

```php
php artisan custom-reports:execute-scheduled
```

```php
namespace App\Console\Commands;

use App\Services\Report\CustomReportSchedulerService;
use Illuminate\Console\Command;

class ExecuteScheduledReportsCommand extends Command
{
    protected $signature = 'custom-reports:execute-scheduled';
    protected $description = 'Execute scheduled custom reports';

    public function __construct(
        protected CustomReportSchedulerService $schedulerService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Executing scheduled reports...');
        
        $this->schedulerService->executeScheduledReports();
        
        $this->info('Done!');
        return Command::SUCCESS;
    }
}
```

Добавить в `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule)
{
    $schedule->command('custom-reports:execute-scheduled')
        ->everyFiveMinutes()
        ->withoutOverlapping();
}
```

### CleanupOldReportExecutionsCommand.php

Очистка старых записей о выполнении отчетов.

```php
php artisan custom-reports:cleanup-executions --days=30
```

---

## 📊 Примеры конфигураций отчетов

### Пример 1: Финансовый отчет по проектам

```json
{
  "name": "Финансовый отчет по проектам",
  "description": "Суммы по контрактам и выполненным работам по проектам",
  "report_category": "finances",
  "data_sources": {
    "primary": "projects",
    "joins": [
      {
        "table": "contracts",
        "type": "left",
        "on": ["projects.id", "contracts.project_id"]
      },
      {
        "table": "completed_works",
        "type": "left",
        "on": ["projects.id", "completed_works.project_id"]
      }
    ]
  },
  "columns_config": [
    {
      "field": "projects.name",
      "label": "Проект",
      "order": 1,
      "format": "text"
    },
    {
      "field": "projects.budget_amount",
      "label": "Бюджет",
      "order": 2,
      "format": "currency"
    },
    {
      "field": "contracts.total_amount",
      "label": "Сумма контрактов",
      "order": 3,
      "format": "currency",
      "aggregation": "sum"
    },
    {
      "field": "completed_works.total_cost",
      "label": "Стоимость работ",
      "order": 4,
      "format": "currency",
      "aggregation": "sum"
    }
  ],
  "filters_config": [
    {
      "field": "projects.status",
      "label": "Статус проекта",
      "type": "select",
      "options": ["active", "completed"],
      "required": false
    },
    {
      "field": "projects.start_date",
      "label": "Период",
      "type": "date_range",
      "required": true
    }
  ],
  "aggregations_config": {
    "group_by": ["projects.id", "projects.name", "projects.budget_amount"],
    "aggregations": [
      {
        "field": "contracts.total_amount",
        "function": "sum",
        "alias": "total_contracts"
      },
      {
        "field": "completed_works.total_cost",
        "function": "sum",
        "alias": "total_works"
      }
    ]
  },
  "sorting_config": [
    {
      "field": "total_contracts",
      "direction": "desc"
    }
  ]
}
```

### Пример 2: Отчет по использованию материалов

```json
{
  "name": "Использование материалов по проектам",
  "description": "Приход и расход материалов по проектам",
  "report_category": "materials",
  "data_sources": {
    "primary": "materials",
    "joins": [
      {
        "table": "material_receipts",
        "type": "left",
        "on": ["materials.id", "material_receipts.material_id"]
      },
      {
        "table": "projects",
        "type": "inner",
        "on": ["material_receipts.project_id", "projects.id"]
      }
    ]
  },
  "columns_config": [
    {
      "field": "materials.name",
      "label": "Материал",
      "order": 1
    },
    {
      "field": "projects.name",
      "label": "Проект",
      "order": 2
    },
    {
      "field": "material_receipts.quantity",
      "label": "Количество",
      "order": 3,
      "aggregation": "sum"
    },
    {
      "field": "material_receipts.total_price",
      "label": "Стоимость",
      "order": 4,
      "format": "currency",
      "aggregation": "sum"
    }
  ],
  "filters_config": [
    {
      "field": "material_receipts.receipt_date",
      "label": "Период",
      "type": "date_range",
      "required": true
    },
    {
      "field": "materials.category",
      "label": "Категория материала",
      "type": "text",
      "required": false
    }
  ],
  "aggregations_config": {
    "group_by": ["materials.id", "materials.name", "projects.id", "projects.name"],
    "aggregations": [
      {
        "field": "material_receipts.quantity",
        "function": "sum",
        "alias": "total_quantity"
      },
      {
        "field": "material_receipts.total_price",
        "function": "sum",
        "alias": "total_cost"
      }
    ]
  }
}
```

### Пример 3: Активность прорабов

```json
{
  "name": "Активность прорабов",
  "description": "Рабочее время и выполненные работы по прорабам",
  "report_category": "staff",
  "data_sources": {
    "primary": "users",
    "joins": [
      {
        "table": "time_entries",
        "type": "left",
        "on": ["users.id", "time_entries.user_id"]
      },
      {
        "table": "completed_works",
        "type": "left",
        "on": ["users.id", "completed_works.user_id"]
      }
    ]
  },
  "query_config": {
    "where": [
      {
        "field": "users.role",
        "operator": "=",
        "value": "foreman"
      }
    ]
  },
  "columns_config": [
    {
      "field": "users.name",
      "label": "Прораб",
      "order": 1
    },
    {
      "field": "time_entries.hours",
      "label": "Отработано часов",
      "order": 2,
      "aggregation": "sum"
    },
    {
      "field": "completed_works.id",
      "label": "Количество работ",
      "order": 3,
      "aggregation": "count"
    },
    {
      "field": "completed_works.total_cost",
      "label": "Стоимость работ",
      "order": 4,
      "format": "currency",
      "aggregation": "sum"
    }
  ],
  "filters_config": [
    {
      "field": "time_entries.date",
      "label": "Период",
      "type": "date_range",
      "required": true
    }
  ],
  "aggregations_config": {
    "group_by": ["users.id", "users.name"],
    "aggregations": [
      {
        "field": "time_entries.hours",
        "function": "sum",
        "alias": "total_hours"
      },
      {
        "field": "completed_works.id",
        "function": "count",
        "alias": "works_count"
      },
      {
        "field": "completed_works.total_cost",
        "function": "sum",
        "alias": "total_revenue"
      }
    ]
  },
  "sorting_config": [
    {
      "field": "total_revenue",
      "direction": "desc"
    }
  ]
}
```

---

## 🚀 Этапы реализации (Roadmap)

### MVP (Фаза 1) - 3-4 недели

**Цель:** Базовый функционал конструктора с одним источником данных.

1. **База данных** (3 дня)
   - Миграции: custom_reports, custom_report_executions
   - Модели Eloquent
   - Seeders для тестовых данных

2. **Конфигурация источников данных** (2 дня)
   - config/custom-reports.php
   - ReportDataSourceRegistry
   - Определение 5-7 основных источников

3. **Базовые сервисы** (5 дней)
   - CustomReportBuilderService (валидация, построение запросов)
   - ReportQueryBuilder (построение простых запросов без JOIN)
   - ReportFilterBuilder (базовые операторы: =, >, <, like, in)

4. **API эндпоинты - CRUD** (4 дня)
   - CustomReportController (create, read, update, delete, list)
   - CustomReportBuilderController (data-sources, fields, validate, preview)
   - Request классы для валидации

5. **Выполнение отчетов** (3 дня)
   - CustomReportExecutionService
   - CustomReportExecutionController (execute, export)
   - Интеграция с существующими экспортерами (CSV, Excel)

6. **Тестирование MVP** (2 дня)
   - Unit тесты для сервисов
   - API тесты для контроллеров

### Фаза 2 - 2-3 недели

**Цель:** JOIN'ы, агрегации, расписание.

7. **Поддержка JOIN'ов** (4 дня)
   - Расширение ReportQueryBuilder
   - Валидация связей между таблицами
   - Тесты

8. **Агрегации и группировки** (3 дня)
   - ReportAggregationBuilder
   - GROUP BY, HAVING
   - SUM, AVG, COUNT, MIN, MAX

9. **Расписание** (5 дней)
   - Миграция: custom_report_schedules
   - CustomReportSchedulerService
   - CustomReportScheduleController
   - ExecuteScheduledReportsCommand
   - Email рассылка отчетов

10. **Оптимизация производительности** (2 дня)
    - ReportQueryOptimizer
    - Кэширование метаданных
    - Индексы БД

### Фаза 3 - 2-3 недели

**Цель:** Визуализация, вычисляемые поля, шаблоны.

11. **Визуализация данных** (опционально, 4 дня)
    - ReportVisualizationService
    - Подготовка данных для графиков
    - API для получения chart data

12. **Вычисляемые поля** (3 дня)
    - Поддержка формул в columns_config
    - Валидация формул

13. **Шаблоны отчетов** (3 дня)
    - Предустановленные шаблоны
    - Импорт/экспорт конфигураций
    - Клонирование отчетов

14. **Версионирование** (опционально, 3 дня)
    - История изменений конфигурации
    - Откат к предыдущим версиям

### Фаза 4 - 2 недели

**Цель:** Интеграции, collaboration, доработки.

15. **Интеграции** (4 дня)
    - Отправка в Telegram
    - Webhook для внешних систем
    - API для получения данных отчета

16. **Collaborative features** (3 дия)
    - Комментарии к отчетам
    - Теги, рейтинги
    - Избранное

17. **UI подсказки для API** (2 дня)
    - Документация API (OpenAPI/Swagger)
    - Примеры запросов

18. **Финальное тестирование** (2 дня)
    - Integration тесты
    - Нагрузочное тестирование
    - Исправление багов

---

## 📝 Технические требования

### Зависимости

- Laravel 10+
- PHP 8.1+
- MySQL 8.0+ / PostgreSQL 14+
- Redis (для кэширования и очередей)
- Laravel Queue (для асинхронных отчетов)

### Производительность

- Простой отчет (без JOIN): < 1 сек
- Сложный отчет (с JOIN и агрегациями): < 5 сек
- Критический порог: 30 сек (таймаут)
- Кэш метаданных: TTL 1 час
- Кэш результатов (опционально): TTL 5 минут

### Масштабируемость

- Поддержка до 50 кастомных отчетов на организацию
- До 10 активных расписаний на организацию
- До 1000 выполнений отчета в истории (автоочистка старых)

---

## 🔍 Мониторинг и метрики

### Бизнес-метрики

- Количество созданных отчетов
- Количество выполнений отчетов (total, по периодам)
- Популярные источники данных
- Популярные категории отчетов
- Средняя частота использования отчета
- Процент отчетов с расписанием
- Процент shared отчетов

### Технические метрики

- Среднее время выполнения отчета
- P50, P95, P99 время выполнения
- Количество failed выполнений
- Количество превышений таймаута
- Размер результатов (строк, размер данных)

### Логирование

```php
// Пример логирования в CustomReportExecutionService
$this->logging->business('custom_report.executed', [
    'report_id' => $report->id,
    'report_name' => $report->name,
    'organization_id' => $report->organization_id,
    'user_id' => $userId,
    'execution_time_ms' => $executionTime,
    'result_rows_count' => $rowsCount,
    'export_format' => $exportFormat,
]);

// При ошибках
$this->logging->technical('custom_report.execution.failed', [
    'report_id' => $report->id,
    'error' => $exception->getMessage(),
    'execution_time_ms' => $executionTime,
], 'error');
```

---

## ⚠️ Риски и ограничения

### Технические риски

1. **Производительность сложных запросов**
   - *Митигация:* лимиты на JOIN'ы, таймауты, асинхронное выполнение

2. **SQL-инъекции**
   - *Митигация:* строгий whitelist, Query Builder, валидация

3. **Переусложнение интерфейса**
   - *Митигация:* пошаговый процесс, шаблоны, хорошая документация

4. **Нагрузка на БД**
   - *Митигация:* индексы, кэширование, rate limiting

### Бизнес-риски

1. **Низкое adoption rate** (пользователи не будут использовать)
   - *Митигация:* шаблоны отчетов, обучающие материалы

2. **Высокая стоимость поддержки**
   - *Митигация:* хорошее логирование, мониторинг, документация

---

## 📚 Дополнительные материалы

### Frontend (общее описание)

Поскольку у вас только API, фронтенд будет разрабатывать отдельная команда. Им потребуется:

1. **Конструктор (визард из 5-7 шагов)**
   - Выбор источника данных (drag & drop или выбор из списка)
   - Настройка JOIN'ов (визуальная схема связей)
   - Выбор колонок (drag & drop, переименование)
   - Настройка фильтров (интерактивные поля)
   - Агрегации (выбор функций)
   - Сортировка и визуализация
   - Сохранение отчета

2. **Страница выполнения отчета**
   - Форма с фильтрами пользователя
   - Таблица с результатами (с пагинацией)
   - Кнопки экспорта
   - Графики (если настроены)

3. **Список отчетов**
   - Таблица с отчетами (фильтры, сортировка)
   - Кнопки: выполнить, редактировать, клонировать, удалить
   - Индикатор: shared, favorite, scheduled

4. **Управление расписанием**
   - Форма настройки расписания
   - Список активных расписаний
   - История выполнений

### Примеры использования API

См. документацию OpenAPI/Swagger (будет создана отдельно).

### Ссылки на существующие компоненты

- `app/Services/Export/CsvExporterService.php` - экспорт CSV
- `app/Services/Export/ExcelExporterService.php` - экспорт Excel
- `app/Models/ReportTemplate.php` - существующие шаблоны
- `app/Models/ReportFile.php` - хранение файлов отчетов
- `app/Services/Logging/LoggingService.php` - логирование

---

## ✅ Контрольный список (Checklist)

### Перед началом разработки
- [ ] Согласовать структуру БД
- [ ] Согласовать конфигурацию источников данных
- [ ] Определить приоритеты функциональности (MVP vs Nice-to-have)
- [ ] Создать технические задачи в трекере

### MVP
- [ ] Миграции и модели
- [ ] Конфигурация источников данных
- [ ] ReportDataSourceRegistry
- [ ] CustomReportBuilderService (базовый)
- [ ] ReportQueryBuilder (без JOIN)
- [ ] ReportFilterBuilder (базовые операторы)
- [ ] CustomReportController (CRUD)
- [ ] CustomReportBuilderController (metadata)
- [ ] CustomReportExecutionService
- [ ] CustomReportExecutionController
- [ ] Интеграция с экспортерами
- [ ] Unit и API тесты

### Фаза 2
- [ ] Поддержка JOIN'ов
- [ ] ReportAggregationBuilder
- [ ] CustomReportSchedulerService
- [ ] CustomReportScheduleController
- [ ] ExecuteScheduledReportsCommand
- [ ] Email рассылка
- [ ] ReportQueryOptimizer
- [ ] Кэширование

### Фаза 3
- [ ] ReportVisualizationService
- [ ] Вычисляемые поля
- [ ] Шаблоны отчетов
- [ ] Импорт/экспорт конфигураций
- [ ] Версионирование

### Фаза 4
- [ ] Telegram интеграция
- [ ] Webhook API
- [ ] Комментарии и теги
- [ ] OpenAPI документация
- [ ] Финальное тестирование

---

## 🎯 Итого

Конструктор отчетов - сложная, но очень ценная функциональность для модуля продвинутых отчетов. Ключевые моменты:

1. **Безопасность превыше всего** - строгая валидация, whitelist, изоляция данных
2. **Производительность важна** - лимиты, таймауты, оптимизация, кэширование
3. **Поэтапная разработка** - начать с MVP, постепенно добавлять функции
4. **Хорошая документация** - для фронтенд-команды и пользователей
5. **Мониторинг и логирование** - отслеживание использования и производительности

**Оценка времени:** 10-14 недель (2.5-3.5 месяца) полного цикла разработки.

**Рекомендация:** Начать с MVP (1 источник, базовые фильтры, экспорт), получить обратную связь от пользователей, затем расширять функциональность.

