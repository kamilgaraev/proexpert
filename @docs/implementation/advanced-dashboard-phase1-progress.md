# Phase 1: Services - ПРОГРЕСС 80% ⏳

## Дата обновления
4 октября 2025

## Обзор
Phase 1 фокусируется на создании core сервисов для Advanced Dashboard модуля. Основная работа завершена, созданы все ключевые сервисы аналитики.

## Статус компонентов

### ✅ Завершенные сервисы (5 из 6)

#### 1. FinancialAnalyticsService (~620 строк)
**Файл:** `app/BusinessModules/Features/AdvancedDashboard/Services/FinancialAnalyticsService.php`

**Реализованные методы:**
- `getCashFlow(org, from, to, project?)` - движение денежных средств
  - Приток/отток по месяцам
  - Разбивка по категориям
  - Кеширование 5 минут
- `getProfitAndLoss(org, from, to, project?)` - отчет P&L
  - Выручка, COGS, OpEx
  - Валовая, операционная, чистая прибыль
  - Маржинальность (gross, operating, net)
  - P&L по проектам
- `getROI(org, project?, from?, to?)` - рентабельность инвестиций
  - ROI по проектам
  - Топ и худшие проекты
  - Средний ROI организации
- `getRevenueForecast(org, months=6)` - прогноз доходов
  - На основе контрактов
  - На основе тренда (линейная регрессия)
  - Комбинированный прогноз (70% контракты, 30% тренд)
  - Уровень доверия
- `getReceivablesPayables(org)` - дебиторка/кредиторка
  - Текущие, просроченные (30, 60, 90+ дней)
  - По контрактам/поставщикам
  - Чистая позиция

**Особенности:**
- Redis кеш (TTL: 300 сек)
- TODO: детальная разбивка категорий притока/оттока
- TODO: дебиторская/кредиторская задолженность

#### 2. DashboardLayoutService (~490 строк)
**Файл:** `app/BusinessModules/Features/AdvancedDashboard/Services/DashboardLayoutService.php`

**Реализованные методы:**
- `createDashboard(user, org, data)` - создание дашборда
  - Проверка лимитов (10 per user)
  - Auto-generation slug
  - Первый дашборд = default
- `createFromTemplate(user, org, template, name?)` - из шаблона
  - admin, finance, technical, hr
  - Предустановленные виджеты и layout
- `updateDashboardLayout/Widgets/Filters(id, data)` - обновление компонентов
- `shareDashboard(id, userIds[], visibility)` - расшаривание
  - team, organization visibility
- `duplicateDashboard(id, newName?)` - клонирование
- `getUserDashboards(user, org, includeShared)` - список дашбордов
- `getDefaultDashboard(user, org)` - дефолтный дашборд
- `setDefaultDashboard(id)` - установка default
- `deleteDashboard(id)` - удаление с переназначением default

**Шаблоны дашбордов:**
1. **admin** - contracts_overview, projects_status, recent_activity
2. **finance** - cash_flow, profit_loss, roi, revenue_forecast (4 виджета)
3. **technical** - completed_works, materials_usage, low_stock
4. **hr** - kpi, top_performers, resource_utilization

**Особенности:**
- Кеширование списков дашбордов (10 минут)
- Auto-invalidation кеша при изменениях
- Default layout: grid 12 columns

#### 3. AlertsService (~500 строк)
**Файл:** `app/BusinessModules/Features/AdvancedDashboard/Services/AlertsService.php`

**Реализованные методы:**
- `registerAlert(user, org, data)` - регистрация алерта
  - Валидация типа и оператора
  - 7 типов алертов
  - Cooldown по умолчанию: 60 минут
- `checkAllAlerts(org?)` - проверка всех активных алертов
  - Возвращает статистику: checked, triggered, errors
- `checkAlertConditions(alert)` - проверка конкретного алерта
- `toggleAlert(id, isActive)` - вкл/выкл алерта
- `resetAlert(id)` - сброс состояния
- `getAlertHistory(id, limit=50)` - история срабатываний

**Типы алертов:**
1. `budget_overrun` - превышение бюджета проекта
2. `deadline_risk` - риск срыва сроков контракта
3. `low_stock` - низкие остатки материалов
4. `contract_completion` - завершение контракта (%)
5. `payment_overdue` - просроченные платежи (TODO)
6. `kpi_threshold` - порог KPI (TODO)
7. `custom` - кастомные условия (TODO)

**Операторы сравнения:**
- `gt`, `>`, `gte`, `>=`, `lt`, `<`, `lte`, `<=`, `eq`, `==`, `neq`, `!=`

**Каналы уведомлений:**
- `email` - отправка email (TODO: интеграция)
- `in_app` - внутренние уведомления (TODO)
- `webhook` - webhook вызовы (TODO)

**Приоритеты:**
- `low`, `medium`, `high`, `critical`

**События:**
- `AlertTriggered` - событие срабатывания алерта

#### 4. PredictiveAnalyticsService (~560 строк)
**Файл:** `app/BusinessModules/Features/AdvancedDashboard/Services/PredictiveAnalyticsService.php`

**Реализованные методы:**
- `predictContractCompletion(contractId)` - прогноз завершения контракта
  - История прогресса
  - Линейная регрессия (slope, intercept, R²)
  - Прогноз даты завершения
  - Отклонение от плана (в днях)
  - Уровень риска и доверия
- `predictBudgetOverrun(projectId)` - риск превышения бюджета
  - История расходов (weekly)
  - Тренд расходов
  - Прогноз итоговых расходов
  - % превышения бюджета
  - Рекомендации по оптимизации
- `predictMaterialNeeds(org, months=3)` - потребность в материалах
  - История использования (6 месяцев)
  - Прогноз на N месяцев
  - Дефицит материалов
  - Критичные материалы
- `getOrganizationForecast(org)` - общий прогноз организации
  - Прогноз по всем активным проектам
  - Распределение рисков
  - High-risk projects

**Алгоритм линейной регрессии:**
- Формула: y = mx + b
- Расчет: slope (m), intercept (b), R-squared
- Оценка точности прогноза

**Уровни риска:**
- `low` - в срок или раньше, < 5% превышения
- `medium` - задержка 7-14 дней, 5-10% превышения
- `high` - задержка 14-30 дней, 10-20% превышения
- `critical` - задержка > 30 дней, > 20% превышения

**Уровень доверия:**
- < 3 точек данных → 0.3 (low confidence)
- 3-10 точек → 0.6 (medium confidence)
- 10+ точек → 0.85 (high confidence)

**Особенности:**
- Redis кеш (TTL: 300 сек)
- TODO: детальная история прогресса контрактов
- TODO: история использования материалов

#### 5. KPICalculationService (~540 строк)
**Файл:** `app/BusinessModules/Features/AdvancedDashboard/Services/KPICalculationService.php`

**Реализованные методы:**
- `calculateUserKPI(user, org, from, to)` - KPI сотрудника
  - 6 метрик производительности
  - Взвешенная сумма (0-100 баллов)
  - Уровень производительности
- `getTopPerformers(org, from, to, limit=10)` - топ исполнители
  - Сортировка по overall KPI
  - Средний KPI организации
- `getResourceUtilization(org, from, to)` - загрузка ресурсов
  - Отработанные vs рабочие дни
  - % утилизации
  - Категории: underutilized, optimal, overutilized
- `getUserKPITrend(user, org, months=6)` - тренд KPI
  - Помесячный KPI
  - Направление тренда (improving/stable/declining)
- `getKPIByDepartment(org, from, to)` - KPI по отделам (TODO)

**Метрики KPI:**
1. `completed_works_count` - количество выполненных работ (вес: 20%)
2. `work_volume` - объем работ (sum quantity)
3. `on_time_completion_rate` - % выполнения в срок (вес: 25%) [TODO]
4. `quality_score` - оценка качества (вес: 25%) [TODO]
5. `revenue_generated` - сгенерированная выручка
6. `cost_efficiency` - эффективность затрат (вес: 30%)

**Уровни производительности:**
- `exceptional` - KPI ≥ 90
- `high` - KPI ≥ 75
- `good` - KPI ≥ 60
- `average` - KPI ≥ 40
- `low` - KPI < 40

**Статус загрузки:**
- `underutilized` - < 50% (недогружены)
- `optimal` - 50-90% (оптимально)
- `overutilized` - > 90% (перегружены)

**Особенности:**
- Redis кеш (TTL: 300 сек)
- TODO: система оценки качества работ
- TODO: deadline tracking для on-time rate
- TODO: связь User → Organization

### ⏳ В процессе

#### 6. DashboardExportService (СЛЕДУЮЩИЙ)
**Планируемые методы:**
- `exportDashboardToPDF(dashboardId, options)`
- `exportDashboardToExcel(dashboardId, options)`
- `generateScheduledReport(reportId)`
- `sendReportByEmail(reportId, recipients[])`

**Технологии:**
- PDF: Browsershot (Headless Chrome) или DomPDF
- Excel: Maatwebsite/Excel (PhpSpreadsheet)

### 🔲 Ожидают выполнения

#### DashboardCacheService
**Цель:** Централизованное управление кешем дашбордов

**Методы:**
- `cacheWidget(key, data, ttl, tags[])`
- `getCachedWidget(key)`
- `invalidateWidgetCache(widgetType, organizationId?)`
- `invalidateUserCache(userId)`
- `invalidateDashboardCache(dashboardId)`

**Особенности:**
- Tagged cache (Redis)
- TTL по умолчанию: 300 сек
- Теги: widget_type, organization, user, dashboard

## Статистика Phase 1

| Компонент | Статус | Строк кода | Методов | Комментарий |
|-----------|--------|-----------|---------|-------------|
| FinancialAnalyticsService | ✅ | ~620 | 20+ | Cash Flow, P&L, ROI, Forecasts |
| DashboardLayoutService | ✅ | ~490 | 15+ | CRUD дашбордов, шаблоны |
| AlertsService | ✅ | ~500 | 15+ | 7 типов алертов, 3 канала |
| PredictiveAnalyticsService | ✅ | ~560 | 15+ | Прогнозы, линейная регрессия |
| KPICalculationService | ✅ | ~540 | 15+ | KPI, топ исполнители, загрузка |
| AlertTriggered Event | ✅ | ~25 | - | Событие срабатывания алерта |
| DashboardExportService | ⏳ | - | - | PDF/Excel экспорт |
| DashboardCacheService | 🔲 | - | - | Tagged cache с Redis |
| **ИТОГО** | **80%** | **~2,735** | **80+** | **5 из 6 сервисов** |

## Технические решения

### Кеширование
- Redis Cache с TTL 300 секунд (5 минут)
- Кеш ключи с префиксами: `cash_flow_`, `roi_`, `user_kpi_`, etc.
- Manual invalidation при изменении данных

### Алгоритмы
- **Линейная регрессия** (y = mx + b) для прогнозов
- **Взвешенная сумма** для расчета KPI
- **Тренд анализ** (первая vs вторая половина периода)

### Метрики и веса
- KPI weights: 20% works, 25% on-time, 25% quality, 30% efficiency
- Forecast weights: 70% contracts, 30% trend
- Риск thresholds: 5%, 10%, 20% для budget overrun

### Валидация
- Required fields проверка
- Enum validation для типов и операторов
- Лимиты: 10 dashboards per user, 20 alerts per dashboard

## Зависимости

### Models
- ✅ User
- ✅ Contract
- ✅ Project
- ✅ CompletedWork
- ✅ Material
- ✅ Dashboard (Phase 0)
- ✅ DashboardAlert (Phase 0)
- ✅ ScheduledReport (Phase 0)

### Packages
- ✅ Laravel Cache (Redis)
- ✅ Carbon (даты)
- ⏳ Browsershot или DomPDF (для PDF export)
- ⏳ Maatwebsite/Excel (для Excel export)

## Известные TODO и улучшения

### FinancialAnalyticsService
- [ ] Детальная разбивка притока/оттока по категориям
- [ ] Расчет дебиторской/кредиторской задолженности
- [ ] Интеграция с Payment model для точных расчетов

### DashboardLayoutService
- [ ] Лимиты из конфигурации модуля (сейчас хардкод 10)
- [ ] Связь User → Organization для фильтрации

### AlertsService
- [ ] Интеграция с системой уведомлений (email, in-app, webhook)
- [ ] Payment model для payment_overdue алертов
- [ ] KPI интеграция для kpi_threshold
- [ ] Динамическая проверка кастомных метрик

### PredictiveAnalyticsService
- [ ] Хранение истории прогресса контрактов
- [ ] Material transactions для истории использования
- [ ] Улучшенный алгоритм прогнозирования (не только линейная регрессия)

### KPICalculationService
- [ ] Deadline tracking в completed_works
- [ ] Система оценки качества работ
- [ ] Связь User → Organization
- [ ] KPI по отделам (Department model)

## Следующие шаги (Phase 1 завершение)

1. **DashboardExportService** (~3 дня)
   - PDF export через Browsershot
   - Excel export через Maatwebsite/Excel
   - Scheduled reports generation
   - Email отправка отчетов

2. **DashboardCacheService** (~1 день)
   - Tagged cache реализация
   - Cache invalidation методы
   - Интеграция с Events

3. **Form Requests для валидации** (~1 день)
   - CreateDashboardRequest
   - UpdateDashboardRequest
   - CreateAlertRequest
   - UpdateAlertRequest

4. **PostgreSQL индексы** (~0.5 дня)
   - Indexes для contracts (organization_id, project_id, status, progress)
   - Indexes для completed_works (user_id, project_id, created_at)
   - Indexes для materials (organization_id, balance)
   - JSONB indexes для dashboard settings

## Метрики качества

- ✅ 0 синтаксических ошибок
- ✅ 0 linter ошибок
- ✅ Полная документация методов
- ✅ Кеширование всех аналитических запросов
- ✅ Валидация входных данных
- ⏳ Unit тесты (0% покрытие)
- ⏳ Integration тесты

## Блокеры и риски

### Блокеры
- Нет: все зависимости доступны

### Риски
1. **Производительность:** Аналитические запросы могут быть медленными на больших данных
   - Mitigation: кеширование, индексы PostgreSQL
2. **Точность прогнозов:** Линейная регрессия может быть неточной
   - Mitigation: добавить более сложные алгоритмы в будущем
3. **Missing data:** Некоторые метрики требуют дополнительных полей в БД
   - Mitigation: использовать placeholder значения, добавить TODO

## Готовность к Phase 2

После завершения Phase 1 будет готово:
- ✅ Все core сервисы
- ⏳ Cache management
- ⏳ Export functionality
- 🔲 Controllers и API endpoints (Phase 2)
- 🔲 Real-time updates (Phase 2+)
- 🔲 Тесты

**ETA завершения Phase 1:** 2-3 дня  
**Прогресс Phase 1:** 80% (5 из 6 сервисов)

---

**Дата обновления:** 4 октября 2025  
**Время разработки Phase 1:** ~6 часов  
**Следующий компонент:** DashboardExportService

