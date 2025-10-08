<!-- 4cab82cd-0045-4981-8378-8f3cd28b5c28 ea587c70-bb3b-4a30-a941-635a0f5a562c -->
# Обновление шаблонов дашбордов

## Проблема

После рефакторинга виджетов шаблоны дашбордов используют несуществующие типы виджетов:

- Technical: `completed_works`, `materials_usage`, `low_stock`
- HR: `kpi`

## Файл для изменения

`app/BusinessModules/Features/AdvancedDashboard/Services/DashboardLayoutService.php` - метод `getTemplateConfig()` (строки 398-495)

## Изменения в шаблонах

### Admin шаблон (строки 399-420)

Текущие виджеты корректны:

- `contracts_overview` ✓
- `projects_status` ✓
- `recent_activity` ✓

### Finance шаблон (строки 421-447)

Текущие виджеты корректны:

- `cash_flow` ✓
- `profit_loss` ✓
- `roi` ✓
- `revenue_forecast` ✓

### Technical шаблон (строки 448-469)

Заменить:

- `completed_works` → `projects_completion`
- `materials_usage` → `materials_consumption`
- `low_stock` → `materials_low_stock`

### HR шаблон (строки 470-491)

Заменить:

- `kpi` → `employee_kpi`

Остальные виджеты корректны:

- `top_performers` ✓
- `resource_utilization` ✓

## Дополнительно

Добавить актуальные виджеты для более полного представления в шаблонах:

- Technical: добавить `contracts_performance`
- HR: добавить `employee_workload`

### To-dos

- [ ] Создать Enums: WidgetType (52 типа) и WidgetCategory (8 категорий)
- [ ] Создать WidgetProviderInterface и DTOs (WidgetDataRequest, WidgetDataResponse)
- [ ] Создать WidgetRegistry для регистрации провайдеров
- [ ] Создать WidgetService для оркестрации (кеш, валидация, логирование)
- [ ] Реализовать 7 Financial провайдеров (перенести логику из FinancialAnalyticsService)
- [ ] Реализовать 7 Projects провайдеров
- [ ] Реализовать 7 Contracts провайдеров
- [ ] Реализовать 7 Materials провайдеров
- [ ] Реализовать 7 HR провайдеров (перенести из KPICalculationService)
- [ ] Реализовать 7 Predictive провайдеров (перенести из PredictiveAnalyticsService)
- [ ] Реализовать 5 Activity провайдеров
- [ ] Реализовать 5 Performance провайдеров
- [ ] Создать WidgetsController с методами getData, getBatch, getMetadata
- [ ] Обновить WidgetsRegistryController для новой структуры
- [ ] Обновить DashboardLayoutService - шаблоны с новыми типами виджетов
- [ ] Обновить DashboardCacheService для tagged cache по категориям
- [ ] Обновить routes.php - новые endpoints для виджетов
- [ ] Обновить AdvancedDashboardServiceProvider - регистрация всех провайдеров
- [ ] Удалить старые файлы: FinancialAnalyticsService, PredictiveAnalyticsService, KPICalculationService, AdvancedDashboardController
- [ ] Обновить документацию модуля с описанием всех 52 виджетов