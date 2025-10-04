# Модуль "Продвинутый дашборд" (Advanced Dashboard)

## Описание

Модуль расширенной аналитики и кастомизации дашборда с финансовой аналитикой, предиктивными моделями, HR-аналитикой, множественными дашбордами и real-time обновлениями.

## Версия

1.0.0

## Тип модуля

Feature (Функциональный модуль)

## Модель оплаты

- **Тип:** Subscription (подписка)
- **Стоимость:** 4990 ₽/месяц
- **Trial период:** 7 дней бесплатно
- **Включен в тарифы:** Профи, Энтерпрайз

## Зависимости

- `dashboard-widgets` - базовый дашборд
- `organizations` - система организаций
- `users` - система пользователей
- `contracts` - контракты
- `completed-works` - выполненные работы
- `projects` - проекты
- `materials` - материалы

## Основные функции

### 📊 Множественные дашборды
- До 10 именованных дашбордов на пользователя
- Шаблоны для разных ролей (admin, finance, technical)
- Кастомизация layout (drag-and-drop)
- Расшаривание дашбордов между пользователями

### 💰 Финансовая аналитика
- **Cash Flow** - движение денежных средств
- **P&L** - прибыли и убытки
- **ROI** - рентабельность инвестиций
- **Revenue Forecast** - прогноз доходов
- **Receivables/Payables** - дебиторка/кредиторка

### 🔮 Предиктивная аналитика
- Прогноз завершения контрактов
- Выявление рисков превышения бюджета
- Прогнозирование потребностей в материалах

### 👥 HR-аналитика
- KPI сотрудников
- Топ исполнителей
- Загрузка ресурсов

### ⚡ Real-time обновления
- WebSocket поддержка
- Автоматическое обновление виджетов
- Настраиваемый интервал обновления

### 🔔 Система алертов
- До 20 алертов на дашборд
- Настраиваемые условия срабатывания
- Email и in-app уведомления
- Cooldown период между уведомлениями

### 📤 Экспорт и отчеты
- Экспорт в PDF и Excel
- Планировщик отчетов (daily, weekly, monthly)
- Автоматическая отправка по email

### 🔌 API доступ
- REST API для интеграций
- Rate limiting (100 req/min)
- Token-based аутентификация

## Структура модуля

```
app/BusinessModules/Features/AdvancedDashboard/
├── AdvancedDashboardModule.php          # Основной класс модуля
├── AdvancedDashboardServiceProvider.php # Service Provider
├── README.md                            # Документация
├── routes.php                           # API маршруты
│
├── migrations/                          # Миграции БД
│   ├── 2025_10_10_000001_create_dashboards_table.php
│   ├── 2025_10_10_000002_create_dashboard_alerts_table.php
│   └── 2025_10_10_000003_create_scheduled_reports_table.php
│
├── Models/                              # Модели
│   ├── Dashboard.php
│   ├── DashboardAlert.php
│   └── ScheduledReport.php
│
├── Services/                            # Сервисы (TODO)
│   ├── FinancialAnalyticsService.php
│   ├── PredictiveAnalyticsService.php
│   ├── KPICalculationService.php
│   ├── DashboardLayoutService.php
│   ├── AlertsService.php
│   └── DashboardExportService.php
│
├── Http/
│   ├── Controllers/                     # Контроллеры (TODO)
│   │   ├── AdvancedDashboardController.php
│   │   ├── DashboardManagementController.php
│   │   ├── AlertsController.php
│   │   └── ExportController.php
│   ├── Requests/                        # Form Requests (TODO)
│   └── Middleware/                      # Middleware
│       └── EnsureAdvancedDashboardActive.php
│
├── Jobs/                                # Асинхронные задачи (TODO)
├── Events/                              # События (TODO)
└── Listeners/                           # Слушатели событий (TODO)
```

## Базы данных

### Таблица `dashboards`
Хранит настройки и layout дашбордов пользователей.

**Ключевые поля:**
- `user_id`, `organization_id` - владелец
- `name`, `description` - название и описание
- `layout` (JSON) - сетка layout
- `widgets` (JSON) - настройки виджетов
- `is_default` - дашборд по умолчанию
- `is_shared` - расшарен ли дашборд
- `visibility` - private/team/organization

### Таблица `dashboard_alerts`
Система алертов и уведомлений.

**Ключевые поля:**
- `alert_type` - тип алерта (budget_overrun, deadline_risk, low_stock)
- `conditions` (JSON) - условия срабатывания
- `threshold_value` - пороговое значение
- `notification_channels` (JSON) - каналы уведомлений
- `is_active`, `is_triggered` - статусы

### Таблица `scheduled_reports`
Планировщик автоматической отправки отчетов.

**Ключевые поля:**
- `frequency` - daily, weekly, monthly, custom
- `cron_expression` - для custom расписания
- `export_formats` (JSON) - pdf, excel
- `recipients` (JSON) - получатели
- `next_run_at` - следующий запуск

## API Endpoints

### Dashboard Management
- `GET /api/v1/admin/advanced-dashboard/dashboards` - список дашбордов
- `POST /api/v1/admin/advanced-dashboard/dashboards` - создать дашборд
- `GET /api/v1/admin/advanced-dashboard/dashboards/{id}` - получить дашборд
- `PUT /api/v1/admin/advanced-dashboard/dashboards/{id}` - обновить дашборд
- `DELETE /api/v1/admin/advanced-dashboard/dashboards/{id}` - удалить дашборд
- `POST /api/v1/admin/advanced-dashboard/dashboards/{id}/duplicate` - дублировать
- `POST /api/v1/admin/advanced-dashboard/dashboards/{id}/make-default` - сделать дефолтным

### Financial Analytics
- `GET /api/v1/admin/advanced-dashboard/analytics/financial/cash-flow`
- `GET /api/v1/admin/advanced-dashboard/analytics/financial/profit-loss`
- `GET /api/v1/admin/advanced-dashboard/analytics/financial/roi`
- `GET /api/v1/admin/advanced-dashboard/analytics/financial/revenue-forecast`

### Predictive Analytics
- `GET /api/v1/admin/advanced-dashboard/analytics/predictive/contract-forecast`
- `GET /api/v1/admin/advanced-dashboard/analytics/predictive/budget-risk`

### HR Analytics
- `GET /api/v1/admin/advanced-dashboard/analytics/hr/kpi`
- `GET /api/v1/admin/advanced-dashboard/analytics/hr/top-performers`

### Alerts
- `GET /api/v1/admin/advanced-dashboard/alerts` - список алертов
- `POST /api/v1/admin/advanced-dashboard/alerts` - создать алерт
- `PUT /api/v1/admin/advanced-dashboard/alerts/{id}` - обновить
- `DELETE /api/v1/admin/advanced-dashboard/alerts/{id}` - удалить
- `POST /api/v1/admin/advanced-dashboard/alerts/{id}/toggle` - вкл/выкл

### Export
- `POST /api/v1/admin/advanced-dashboard/export/dashboard/{id}/pdf`
- `POST /api/v1/admin/advanced-dashboard/export/dashboard/{id}/excel`
- `GET /api/v1/admin/advanced-dashboard/export/scheduled-reports`

## Лимиты

- **max_dashboards_per_user:** 10
- **max_alerts_per_dashboard:** 20
- **data_retention_months:** 36
- **max_api_requests_per_minute:** 100

## Настройки модуля

Модуль поддерживает гибкую конфигурацию через `module_settings`:

```php
[
    'enable_financial_analytics' => true,
    'enable_predictive_analytics' => true,
    'enable_realtime_updates' => true,
    'widget_refresh_interval' => 300, // секунд
    'enable_alerts' => true,
    'enable_api_access' => true,
    'api_rate_limit_per_minute' => 100,
    'max_dashboards_per_user' => 10,
    // ... другие настройки
]
```

## Разработка

### Phase 0 (✅ Завершена)
- [x] Основной класс модуля
- [x] Service Provider
- [x] Миграции таблиц
- [x] Модели
- [x] Middleware
- [x] Routes

### Phase 1 (В процессе)
- [ ] Сервисы (Financial, Predictive, KPI)
- [ ] Контроллеры
- [ ] Кеширование

### Phase 2+
- [ ] WebSocket интеграция
- [ ] Jobs и очереди
- [ ] Экспорт в PDF/Excel
- [ ] Тестирование

## Активация модуля

```bash
# Через API
POST /api/modules/advanced-dashboard/activate-trial
POST /api/modules/advanced-dashboard/activate

# Проверка доступа
GET /api/modules/advanced-dashboard/trial-availability
```

## Деактивация

При деактивации модуля:
- Дашборды сохраняются, но становятся недоступны
- Алерты отключаются
- Scheduled reports останавливаются
- Данные не удаляются

## Поддержка

Документация: `@docs/specs/advanced-dashboard-monetization-spec.md`
План реализации: `@docs/plans/dashboard-improvements-plan.md`

---

**Дата создания:** 4 октября 2025  
**Версия:** 1.0.0  
**Статус:** В разработке (Phase 0 завершена)

