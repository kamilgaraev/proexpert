# 🚀 Frontend Integration Guide: Advanced Dashboard

## 📋 Обзор

Полное руководство по интеграции фронтенда с модулем "Продвинутый дашборд".

**Дата:** 4 октября 2025  
**Версия API:** 1.0.0  
**Base URL:** `/api/v1/admin/advanced-dashboard`

---

## 🔐 Аутентификация

### Требуемые Headers

**Все запросы должны содержать:**

```http
Authorization: Bearer {JWT_TOKEN}
X-Organization-ID: {ORGANIZATION_ID}
Content-Type: application/json
Accept: application/json
```

### Проверка активации модуля

**Перед использованием проверьте активацию:**

```http
GET /api/modules/advanced-dashboard/status

Response 200:
{
  "success": true,
  "data": {
    "is_active": true,
    "is_trial": false,
    "expires_at": null
  }
}

Response 403:
{
  "success": false,
  "message": "Module not active"
}
```

**Рекомендация:**
- Проверяйте статус при загрузке приложения
- Показывайте баннер активации если `is_active: false`
- Показывайте уведомление о trial если `is_trial: true`

---

## 📊 1. Дашборды (Dashboards)

### 1.1. Получить список дашбордов

```http
GET /dashboards

Response 200:
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "My Financial Dashboard",
      "slug": "my-financial-dashboard",
      "description": "Dashboard for financial analytics",
      "layout": {
        "lg": [{"i": "widget-1", "x": 0, "y": 0, "w": 6, "h": 4}]
      },
      "widgets": [
        {
          "id": "widget-1",
          "type": "cash-flow",
          "settings": {}
        }
      ],
      "filters": {
        "period": "30d",
        "project_ids": [1, 2, 3]
      },
      "is_shared": false,
      "visibility": "private",
      "is_default": true,
      "views_count": 42,
      "created_at": "2025-10-04T12:00:00Z"
    }
  ]
}
```

**UI рекомендации:**
- Отображайте список в sidebar или dropdown
- Показывайте `is_default` бейдж
- Сортируйте по `is_default` → `created_at`

---

### 1.2. Создать дашборд

```http
POST /dashboards

Request:
{
  "name": "My Dashboard",
  "description": "Optional description",
  "visibility": "private",  // private | team | organization
  "layout": {},
  "widgets": []
}

Response 201:
{
  "success": true,
  "message": "Dashboard created successfully",
  "data": {
    "id": 2,
    "name": "My Dashboard",
    ...
  }
}

Error 403:
{
  "success": false,
  "message": "Dashboard limit exceeded (max 10 per user)"
}
```

**Лимиты модуля:**
- Максимум 10 дашбордов на пользователя
- Проверяйте лимит перед созданием

---

### 1.3. Создать из шаблона

```http
POST /dashboards/from-template

Request:
{
  "template": "finance",  // admin | finance | technical | hr
  "name": "My Finance Dashboard"  // optional
}

Response 201:
{
  "success": true,
  "message": "Dashboard created from template",
  "data": {
    "id": 3,
    "template": "finance",
    "widgets": [
      // Предзаполненные виджеты
    ]
  }
}
```

**Доступные шаблоны:**

```http
GET /dashboards/templates

Response 200:
{
  "success": true,
  "data": [
    {
      "id": "admin",
      "name": "Административный",
      "description": "Контракты, проекты, статистика",
      "preview_url": "/previews/admin.png",
      "widgets": [
        {"type": "contracts-stats"},
        {"type": "projects-overview"}
      ]
    },
    {
      "id": "finance",
      "name": "Финансовый",
      "description": "Cash Flow, P&L, ROI",
      "widgets": [
        {"type": "cash-flow"},
        {"type": "profit-loss"}
      ]
    },
    {
      "id": "technical",
      "name": "Технический",
      "description": "Загрузка ресурсов, прогресс",
      "widgets": [
        {"type": "resource-utilization"},
        {"type": "contract-forecast"}
      ]
    },
    {
      "id": "hr",
      "name": "HR",
      "description": "KPI, топ исполнители",
      "widgets": [
        {"type": "top-performers"},
        {"type": "user-kpi"}
      ]
    }
  ]
}
```

**UI рекомендации:**
- Показывайте preview для каждого шаблона
- Используйте карточки с превью
- Опция "Пустой дашборд" + 4 шаблона

---

### 1.4. Обновить дашборд

```http
PUT /dashboards/{id}

Request:
{
  "name": "Updated Name",
  "description": "Updated description",
  "visibility": "team"
}

Response 200:
{
  "success": true,
  "message": "Dashboard updated successfully",
  "data": {...}
}
```

---

### 1.5. Обновить layout (drag-and-drop)

```http
PUT /dashboards/{id}/layout

Request:
{
  "layout": {
    "lg": [
      {"i": "widget-1", "x": 0, "y": 0, "w": 6, "h": 4},
      {"i": "widget-2", "x": 6, "y": 0, "w": 6, "h": 4}
    ],
    "md": [...],
    "sm": [...]
  }
}

Response 200:
{
  "success": true,
  "message": "Layout updated successfully"
}
```

**Layout структура:**
- `i` - ID виджета (string)
- `x` - позиция X в сетке (0-11)
- `y` - позиция Y
- `w` - ширина (1-12)
- `h` - высота (1-N)

**UI библиотеки:**
- React: `react-grid-layout`
- Vue: `vue-grid-layout`
- Angular: `angular-gridster2`

**Рекомендации:**
- Сохраняйте layout при каждом изменении (debounce 500ms)
- Поддерживайте responsive (lg, md, sm)
- Показывайте "Saving..." индикатор

---

### 1.6. Обновить виджеты

```http
PUT /dashboards/{id}/widgets

Request:
{
  "widgets": [
    {
      "id": "widget-1",
      "type": "cash-flow",
      "settings": {
        "groupBy": "month",
        "chartType": "bar"
      }
    },
    {
      "id": "widget-2",
      "type": "profit-loss",
      "settings": {
        "showChart": true
      }
    }
  ]
}

Response 200:
{
  "success": true,
  "message": "Widgets updated successfully"
}
```

**Типы виджетов:**
- `cash-flow` - Cash Flow Chart
- `profit-loss` - P&L Widget
- `roi` - ROI Dashboard
- `revenue-forecast` - Revenue Forecast
- `receivables-payables` - Дебиторка/Кредиторка
- `contract-forecast` - Прогноз контракта
- `budget-risk` - Риск бюджета
- `material-needs` - Потребность материалов
- `top-performers` - Топ исполнители
- `user-kpi` - KPI пользователя
- `resource-utilization` - Загрузка ресурсов

---

### 1.7. Обновить глобальные фильтры

```http
PUT /dashboards/{id}/filters

Request:
{
  "filters": {
    "period": "30d",           // 7d | 30d | 90d | custom
    "from": "2025-01-01",      // если custom
    "to": "2025-10-04",
    "project_ids": [1, 2, 3],
    "contract_ids": [5, 6]
  }
}

Response 200:
{
  "success": true,
  "message": "Filters updated successfully"
}
```

**UI рекомендации:**
- Toolbar с фильтрами вверху дашборда
- Period picker (7/30/90 дней + custom)
- Multi-select для проектов/контрактов
- Apply кнопка для применения
- Сохраняйте фильтры автоматически

---

### 1.8. Расшарить дашборд

```http
POST /dashboards/{id}/share

Request:
{
  "share_type": "team",        // team | organization
  "user_ids": [10, 20, 30]     // для share_type=team
}

Response 200:
{
  "success": true,
  "message": "Dashboard shared successfully"
}
```

**UI flow:**
- Кнопка "Share" в header дашборда
- Modal с выбором share_type
- Multi-select пользователей для team
- Показывать список расшаренных

```http
DELETE /dashboards/{id}/share

Response 200:
{
  "success": true,
  "message": "Dashboard unshared successfully"
}
```

---

### 1.9. Клонировать дашборд

```http
POST /dashboards/{id}/duplicate

Request:
{
  "name": "Copy of My Dashboard"  // optional
}

Response 201:
{
  "success": true,
  "message": "Dashboard duplicated successfully",
  "data": {
    "id": 5,
    "name": "Copy of My Dashboard"
  }
}
```

**UI:**
- Dropdown menu → "Duplicate"
- Редирект на новый дашборд после создания

---

### 1.10. Установить как default

```http
POST /dashboards/{id}/make-default

Response 200:
{
  "success": true,
  "message": "Dashboard set as default successfully"
}
```

**UI:**
- Checkbox "Make default" в настройках
- Показывать звездочку ⭐ на default дашборде

---

### 1.11. Удалить дашборд

```http
DELETE /dashboards/{id}

Response 200:
{
  "success": true,
  "message": "Dashboard deleted successfully"
}

Error 400:
{
  "success": false,
  "message": "Cannot delete default dashboard"
}
```

**UI:**
- Confirmation modal перед удалением
- Нельзя удалить default (сначала сделать другой default)

---

## 💰 2. Финансовая аналитика

### 2.1. Cash Flow

```http
GET /analytics/financial/cash-flow?from=2025-01-01&to=2025-10-04&groupBy=month

Query params:
- from: date (required)
- to: date (required)
- project_id: integer (optional)
- groupBy: day | week | month (default: month)

Response 200:
{
  "success": true,
  "data": {
    "period": {
      "from": "2025-01-01",
      "to": "2025-10-04"
    },
    "cash_flow": [
      {
        "period": "2025-01",
        "inflow": 1500000.00,
        "outflow": 800000.00,
        "net": 700000.00
      },
      {
        "period": "2025-02",
        "inflow": 2000000.00,
        "outflow": 1200000.00,
        "net": 800000.00
      }
    ],
    "summary": {
      "total_inflow": 3500000.00,
      "total_outflow": 2000000.00,
      "net_cash_flow": 1500000.00
    }
  }
}
```

**UI рекомендации:**
- Используйте Bar Chart (2 series: inflow/outflow)
- Или Waterfall Chart для net flow
- Показывайте summary cards вверху
- Форматируйте числа с разделителями (1 500 000 ₽)

**Chart libraries:**
- Chart.js
- ApexCharts
- Recharts (React)
- ECharts

---

### 2.2. Profit & Loss (P&L)

```http
GET /analytics/financial/profit-loss?from=2025-01-01&to=2025-10-04

Query params:
- from: date (required)
- to: date (required)
- project_id: integer (optional)

Response 200:
{
  "success": true,
  "data": {
    "period": {...},
    "revenue": 5000000.00,
    "costs": 3000000.00,
    "gross_profit": 2000000.00,
    "expenses": 500000.00,
    "net_profit": 1500000.00,
    "profit_margin": 30.0,  // %
    "breakdown": {
      "by_project": [
        {
          "project_id": 1,
          "project_name": "Project Alpha",
          "revenue": 2000000,
          "costs": 1200000,
          "profit": 800000,
          "margin": 40.0
        }
      ]
    }
  }
}
```

**UI рекомендации:**
- Waterfall Chart для P&L структуры
- Cards для ключевых метрик
- Таблица breakdown по проектам
- Color coding: прибыль зеленый, убыток красный

---

### 2.3. ROI

```http
GET /analytics/financial/roi?project_id=5

Query params:
- project_id: integer (optional, если нет - все проекты)

Response 200:
{
  "success": true,
  "data": {
    "roi": 45.5,  // %
    "total_revenue": 5000000.00,
    "total_costs": 3450000.00,
    "profit": 1550000.00,
    "projects": [
      {
        "project_id": 1,
        "project_name": "Project Alpha",
        "roi": 66.7,
        "revenue": 2000000,
        "costs": 1200000,
        "profit": 800000,
        "status": "high"  // high | medium | low
      }
    ]
  }
}
```

**UI рекомендации:**
- Gauge chart для общего ROI
- Таблица проектов с сортировкой
- Color coding: >30% green, 15-30% yellow, <15% red
- Sparklines для тренда

---

### 2.4. Revenue Forecast

```http
GET /analytics/financial/revenue-forecast?months=6

Query params:
- months: integer (1-24, default: 6)

Response 200:
{
  "success": true,
  "data": {
    "forecast_months": 6,
    "current_revenue": 5000000.00,
    "forecast": [
      {
        "month": "2025-11",
        "expected_revenue": 5500000.00,
        "confidence": "high",  // high | medium | low
        "min_estimate": 5000000.00,
        "max_estimate": 6000000.00
      }
    ],
    "total_forecast": 35000000.00
  }
}
```

**UI рекомендации:**
- Line Chart с confidence bands (min-max)
- Разные цвета для разных confidence levels
- Показывайте historical + forecast
- Легенда с пояснениями

---

### 2.5. Receivables & Payables

```http
GET /analytics/financial/receivables-payables

Response 200:
{
  "success": true,
  "data": {
    "receivables": {
      "total": 2000000.00,
      "overdue": 500000.00,
      "current": 1500000.00,
      "aging": {
        "0-30": 1000000,
        "31-60": 400000,
        "61-90": 100000,
        "90+": 500000
      }
    },
    "payables": {
      "total": 1500000.00,
      "overdue": 200000.00,
      "current": 1300000.00,
      "aging": {...}
    },
    "net_position": 500000.00
  }
}
```

**UI рекомендации:**
- Две колонки: Receivables | Payables
- Pie chart для aging breakdown
- Таблица с детализацией
- Highlight overdue красным

---

## 🔮 3. Предиктивная аналитика

### 3.1. Contract Forecast

```http
GET /analytics/predictive/contract-forecast?contract_id=10

Query params:
- contract_id: integer (required)

Response 200:
{
  "success": true,
  "data": {
    "contract_id": 10,
    "contract_name": "Contract ABC",
    "current_progress": 65.5,  // %
    "predicted_completion_date": "2025-12-15",
    "original_deadline": "2025-12-31",
    "days_remaining": 76,
    "days_ahead_behind": 16,  // положительное - опережение
    "confidence": 0.85,  // 0-1
    "r_squared": 0.92,
    "risk_level": "low",  // low | medium | high
    "progress_history": [
      {"date": "2025-09-01", "progress": 45.0},
      {"date": "2025-10-01", "progress": 65.5}
    ],
    "forecast_chart": [
      {"date": "2025-10-04", "progress": 65.5},
      {"date": "2025-11-04", "progress": 80.0},
      {"date": "2025-12-15", "progress": 100.0}
    ]
  }
}
```

**UI рекомендации:**
- Line chart: historical + forecast
- Показывайте confidence interval
- Card с predicted date vs deadline
- Color: green (опережение), yellow (в срок), red (задержка)
- Показывайте R² для оценки точности

---

### 3.2. Budget Risk

```http
GET /analytics/predictive/budget-risk?contract_id=10

Response 200:
{
  "success": true,
  "data": {
    "contract_id": 10,
    "budget_total": 10000000.00,
    "current_spent": 6500000.00,
    "predicted_total": 11500000.00,
    "overrun_amount": 1500000.00,
    "overrun_percentage": 15.0,
    "overrun_risk": "high",  // low | medium | high | critical
    "recommendations": [
      "Пересмотреть бюджет материалов",
      "Оптимизировать трудозатраты"
    ],
    "spending_history": [
      {"month": "2025-09", "spent": 5000000},
      {"month": "2025-10", "spent": 6500000}
    ],
    "forecast_chart": [
      {"progress": 65, "spent": 6500000},
      {"progress": 100, "spent": 11500000}
    ]
  }
}
```

**UI рекомендации:**
- Progress bar: spent/budget
- Gauge для overrun risk
- Area chart: budget vs actual vs forecast
- Alert box с recommendations
- Color coding по risk level

---

### 3.3. Material Needs

```http
GET /analytics/predictive/material-needs?months=3

Query params:
- months: integer (1-12, default: 3)

Response 200:
{
  "success": true,
  "data": {
    "forecast_months": 3,
    "materials": [
      {
        "material_id": 1,
        "material_name": "Цемент М500",
        "unit": "тонна",
        "current_balance": 50.0,
        "average_monthly_usage": 30.0,
        "predicted_need": 90.0,
        "shortage_risk": "high",  // low | medium | high
        "reorder_date": "2025-10-15",
        "recommended_quantity": 100.0
      }
    ],
    "total_materials": 25,
    "high_risk_count": 5
  }
}
```

**UI рекомендации:**
- Таблица материалов с сортировкой по risk
- Color coding: green (достаточно), yellow (мало), red (нехватка)
- Badge с shortage_risk
- Action button "Заказать" для high risk

---

## 👥 4. HR & KPI Analytics

### 4.1. User KPI

```http
GET /analytics/hr/kpi?user_id=15&from=2025-01-01&to=2025-10-04

Query params:
- user_id: integer (required)
- from: date (required)
- to: date (required)

Response 200:
{
  "success": true,
  "data": {
    "user_id": 15,
    "user_name": "Иван Иванов",
    "period": {...},
    "metrics": {
      "completed_works_count": 45,
      "work_volume": 2500000.00,
      "on_time_completion_rate": 92.5,  // %
      "quality_score": 4.5,  // 0-5
      "revenue_generated": 3000000.00,
      "cost_efficiency": 1.2  // revenue/cost ratio
    },
    "overall_kpi": 87.5,  // 0-100
    "performance_level": "excellent",  // excellent | good | average | below_average | poor
    "trend": "improving",  // improving | stable | declining
    "comparison_to_avg": +15.5  // % отличие от среднего
  }
}
```

**UI рекомендации:**
- Radar chart для 6 метрик
- Большая цифра overall_kpi с gauge
- Badge для performance_level
- Comparison bar: user vs average
- Trend indicator (↑/→/↓)

**Performance levels colors:**
- excellent: green
- good: light green
- average: yellow
- below_average: orange
- poor: red

---

### 4.2. Top Performers

```http
GET /analytics/hr/top-performers?from=2025-01-01&to=2025-10-04&limit=10

Query params:
- from: date (required)
- to: date (required)
- limit: integer (1-50, default: 10)

Response 200:
{
  "success": true,
  "data": {
    "period": {...},
    "performers": [
      {
        "rank": 1,
        "user_id": 15,
        "user_name": "Иван Иванов",
        "avatar_url": "/avatars/15.jpg",
        "kpi_score": 92.5,
        "completed_works": 45,
        "work_volume": 2500000.00,
        "badges": ["🏆 Top 1", "⭐ Best Quality"]
      },
      {
        "rank": 2,
        ...
      }
    ],
    "total_evaluated": 50
  }
}
```

**UI рекомендации:**
- Leaderboard с avatars
- Показывайте top 3 с медалями 🥇🥈🥉
- Animated numbers для scores
- Badges для достижений
- Scroll для длинного списка

---

### 4.3. Resource Utilization

```http
GET /analytics/hr/resource-utilization?from=2025-01-01&to=2025-10-04

Response 200:
{
  "success": true,
  "data": {
    "period": {...},
    "overall_utilization": 75.5,  // %
    "users": [
      {
        "user_id": 15,
        "user_name": "Иван Иванов",
        "utilization": 92.0,  // %
        "workload_status": "optimal",  // underutilized | optimal | overloaded
        "hours_worked": 184,
        "available_hours": 200,
        "active_projects": 3
      }
    ],
    "distribution": {
      "underutilized": 5,  // count
      "optimal": 35,
      "overloaded": 10
    }
  }
}
```

**UI рекомендации:**
- Heatmap calendar для utilization
- Cards для distribution
- Таблица пользователей с progress bars
- Color coding:
  - underutilized (<60%): blue
  - optimal (60-90%): green
  - overloaded (>90%): red

---

## 🔔 5. Alerts (Алерты)

### 5.1. Получить список алертов

```http
GET /alerts?is_active=true&priority=high

Query params:
- dashboard_id: integer (optional)
- type: budget_overrun | deadline_risk | low_stock | contract_completion | payment_overdue | kpi_threshold | custom
- is_active: boolean
- priority: low | medium | high | critical

Response 200:
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "Budget Alert",
      "description": "Notify when budget exceeds 80%",
      "alert_type": "budget_overrun",
      "target_entity": "project",
      "target_entity_id": 5,
      "comparison_operator": "gt",
      "threshold_value": 80,
      "threshold_unit": "%",
      "notification_channels": ["email", "in_app"],
      "recipients": ["admin@example.com"],
      "cooldown_minutes": 60,
      "priority": "high",
      "is_active": true,
      "is_triggered": false,
      "last_triggered_at": null,
      "trigger_count": 0,
      "created_at": "2025-10-04T12:00:00Z"
    }
  ]
}
```

**UI рекомендации:**
- Список с фильтрами (type, priority, status)
- Toggle switch для is_active
- Badge для priority с цветами
- Показывайте trigger_count
- Last triggered timestamp

---

### 5.2. Создать алерт

```http
POST /alerts

Request:
{
  "dashboard_id": 1,  // optional
  "name": "Budget Alert",
  "description": "Notify when budget exceeds 80%",
  "alert_type": "budget_overrun",
  "target_entity": "project",
  "target_entity_id": 5,
  "comparison_operator": "gt",  // gt | gte | lt | lte | eq | neq
  "threshold_value": 80,
  "threshold_unit": "%",
  "notification_channels": ["email", "in_app"],
  "recipients": ["admin@example.com"],
  "cooldown_minutes": 60,
  "priority": "high",
  "is_active": true
}

Response 201:
{
  "success": true,
  "message": "Alert created successfully",
  "data": {...}
}
```

**Alert types:**
- `budget_overrun` - Превышение бюджета
- `deadline_risk` - Риск срыва срока
- `low_stock` - Низкий остаток материалов
- `contract_completion` - Завершение контракта
- `payment_overdue` - Просрочка платежа
- `kpi_threshold` - Порог KPI
- `custom` - Кастомный

**UI Form:**
```
1. Basic Info
   - Name (required)
   - Description (optional)
   
2. Condition
   - Alert Type (dropdown)
   - Target Entity (project | contract | material | user)
   - Select entity (autocomplete)
   - Operator (> | >= | < | <= | == | !=)
   - Threshold value (number)
   - Unit (%, ₽, дней, шт)
   
3. Notifications
   - Channels (checkboxes: email, in_app, webhook)
   - Recipients (chips input)
   - Cooldown (minutes, default 60)
   
4. Priority
   - Radio: low | medium | high | critical
   
5. Status
   - Toggle: Active/Inactive
```

---

### 5.3. Включить/выключить алерт

```http
POST /alerts/{id}/toggle

Request:
{
  "is_active": true
}

Response 200:
{
  "success": true,
  "message": "Alert enabled successfully"
}
```

**UI:**
- Toggle switch в списке
- Confirmation для critical alerts

---

### 5.4. Сбросить алерт

```http
POST /alerts/{id}/reset

Response 200:
{
  "success": true,
  "message": "Alert reset successfully"
}
```

**Что делает:**
- Сбрасывает `is_triggered` → false
- Очищает `last_triggered_at`
- Обнуляет `trigger_count`

**UI:**
- Кнопка "Reset" для triggered alerts

---

### 5.5. История срабатываний

```http
GET /alerts/{id}/history?limit=50

Response 200:
{
  "success": true,
  "data": [
    {
      "triggered_at": "2025-10-04T15:30:00Z",
      "current_value": 85.5,
      "threshold_value": 80,
      "message": "Budget exceeded threshold",
      "notified_channels": ["email", "in_app"]
    }
  ]
}
```

**UI:**
- Timeline view
- Показывайте actual value vs threshold
- Иконки для channels

---

### 5.6. Проверить все алерты (manual)

```http
POST /alerts/check-all

Response 200:
{
  "success": true,
  "message": "Alerts checked successfully",
  "data": {
    "checked": 10,
    "triggered": 2,
    "errors": 0
  }
}
```

**UI:**
- Admin кнопка "Check All"
- Показывайте результаты в toast

---

## 📄 6. Export

### 6.1. Доступные форматы

```http
GET /export/formats

Response 200:
{
  "success": true,
  "data": {
    "pdf": {
      "supported": true,
      "max_size_mb": 50,
      "quality": "high"
    },
    "excel": {
      "supported": true,
      "max_size_mb": 100,
      "format": "xlsx"
    }
  }
}
```

---

### 6.2. Экспорт в PDF

```http
POST /export/dashboard/{id}/pdf

Request:
{
  "filters": {
    "from": "2025-01-01",
    "to": "2025-10-04"
  },
  "widgets": ["widget-1", "widget-2"]  // optional, если нет - все
}

Response 200:
{
  "success": true,
  "message": "Dashboard exported successfully",
  "data": {
    "file_path": "exports/dashboard-1-20251004.pdf",
    "file_url": "https://storage.prohelper.ru/exports/dashboard-1-20251004.pdf",
    "format": "pdf",
    "size_bytes": 2456789
  }
}
```

**UI flow:**
1. Кнопка "Export" → Dropdown (PDF / Excel)
2. Modal с опциями (фильтры, виджеты)
3. Loading spinner (может занять 10-30 сек)
4. Success toast + Download button
5. Автоскачивание файла

**Рекомендации:**
- Показывайте прогресс
- Храните ссылку для повторного скачивания
- Открывайте в новой вкладке для preview

---

### 6.3. Экспорт в Excel

```http
POST /export/dashboard/{id}/excel

Request:
{
  "filters": {...},
  "widgets": [...],
  "include_raw_data": true  // включить raw data в отдельные sheets
}

Response 200:
{
  "success": true,
  "message": "Dashboard exported successfully",
  "data": {
    "file_path": "exports/dashboard-1-20251004.xlsx",
    "file_url": "https://...",
    "format": "excel",
    "sheets": ["Summary", "Cash Flow", "P&L", "Raw Data"]
  }
}
```

---

### 6.4. Scheduled Reports

#### Список

```http
GET /export/scheduled-reports?is_active=true

Response 200:
{
  "success": true,
  "data": [
    {
      "id": 1,
      "dashboard_id": 1,
      "name": "Weekly Finance Report",
      "description": "Send every Monday",
      "frequency": "weekly",
      "time_of_day": "09:00:00",
      "days_of_week": [1],  // Monday
      "export_formats": ["pdf", "excel"],
      "recipients": ["cfo@example.com"],
      "is_active": true,
      "next_run_at": "2025-10-07T09:00:00Z",
      "last_run_at": "2025-09-30T09:00:00Z",
      "last_run_status": "success",
      "run_count": 12,
      "success_count": 12,
      "failure_count": 0
    }
  ]
}
```

#### Создать

```http
POST /export/scheduled-reports

Request:
{
  "dashboard_id": 1,
  "name": "Weekly Finance Report",
  "description": "Send every Monday at 9 AM",
  "frequency": "weekly",  // daily | weekly | monthly | custom
  "time_of_day": "09:00:00",
  "days_of_week": [1],  // для weekly: 0-6 (Sun-Sat)
  "day_of_month": null,  // для monthly: 1-31
  "cron_expression": null,  // для custom
  "export_formats": ["pdf", "excel"],
  "recipients": ["cfo@example.com"],
  "cc_recipients": ["finance@example.com"],
  "email_subject": "Weekly Finance Report",
  "email_body": "Please find attached...",
  "filters": {...},
  "widgets": [...],
  "include_raw_data": false,
  "is_active": true
}

Response 201:
{
  "success": true,
  "message": "Scheduled report created successfully",
  "data": {...}
}
```

**UI Form:**
```
1. Basic
   - Select Dashboard (required)
   - Report Name (required)
   - Description
   
2. Schedule
   - Frequency (daily/weekly/monthly/custom)
   - Time (HH:MM)
   - Days of week (для weekly)
   - Day of month (для monthly)
   - Cron expression (для custom)
   
3. Export
   - Formats (checkboxes: PDF, Excel)
   - Include raw data (toggle)
   - Filters (inherited from dashboard)
   
4. Email
   - Recipients (chips, required)
   - CC (chips, optional)
   - Subject (template)
   - Body (textarea)
   
5. Status
   - Active (toggle)
```

**Schedule examples:**
- Daily at 9 AM: `daily`, `09:00:00`
- Weekly Mon/Wed/Fri: `weekly`, `09:00:00`, `[1,3,5]`
- Monthly 1st day: `monthly`, `09:00:00`, `1`
- Custom cron: `custom`, `0 9 * * 1,3,5`

---

## 🎨 UI/UX Рекомендации

### Dashboard Layout

**Grid система:**
- 12 колонок
- Responsive breakpoints:
  - lg: ≥1200px (12 cols)
  - md: ≥996px (10 cols)
  - sm: ≥768px (6 cols)
  - xs: <768px (4 cols)

**Виджеты:**
- Минимальный размер: 2x2 (cols x rows)
- Рекомендуемый: 4x3 для charts
- Полная ширина: 12x4 для tables

### Цветовая палитра

**Status colors:**
```
Success: #10B981 (green)
Warning: #F59E0B (yellow)
Error: #EF4444 (red)
Info: #3B82F6 (blue)
```

**Performance levels:**
```
Excellent: #10B981
Good: #84CC16
Average: #F59E0B
Below Average: #F97316
Poor: #EF4444
```

**Chart colors:**
```
Primary: #3B82F6
Secondary: #8B5CF6
Success: #10B981
Warning: #F59E0B
Danger: #EF4444
```

### Loading States

**Skeleton screens:**
- Dashboard list: Cards skeleton
- Widget loading: Chart skeleton
- Table loading: Table skeleton

**Spinners:**
- Page load: Full-screen spinner
- Widget refresh: Small spinner overlay
- Button action: Button spinner

### Error Handling

**Error types:**
```
401 Unauthorized → Redirect to login
403 Module Not Active → Show activation banner
404 Not Found → Show 404 page
400 Validation Error → Show field errors
429 Rate Limit → Show retry message
500 Server Error → Show error page
```

**Toast notifications:**
- Success: 3 sec auto-dismiss
- Error: 5 sec or manual dismiss
- Info: 3 sec auto-dismiss
- Warning: 5 sec or manual dismiss

### Performance

**Optimization:**
- Lazy load виджетов (IntersectionObserver)
- Debounce для фильтров (500ms)
- Throttle для scroll events (100ms)
- Cache API responses (5 min TTL)
- Paginate tables (50 rows per page)

**Bundle size:**
- Code splitting по routes
- Lazy load chart libraries
- Tree shaking unused code

---

## 🔄 Workflow Examples

### 1. Создание первого дашборда

```
1. User opens Advanced Dashboard
2. Check module status: GET /api/modules/advanced-dashboard/status
3. If not active → Show activation prompt
4. If active → GET /dashboards (empty)
5. Show "Create Dashboard" CTA
6. User clicks → Modal with templates
7. User selects template: POST /dashboards/from-template
8. Redirect to new dashboard
9. Show tutorial overlay
```

### 2. Настройка дашборда

```
1. Open dashboard: GET /dashboards/{id}
2. Render widgets based on dashboard.widgets
3. User drag-and-drops widget
4. Debounce 500ms
5. PUT /dashboards/{id}/layout
6. Show "Saving..." → "Saved ✓"
7. User adds new widget
8. PUT /dashboards/{id}/widgets
9. Render new widget
```

### 3. Применение фильтров

```
1. User changes period in toolbar
2. Update local state
3. User clicks "Apply"
4. PUT /dashboards/{id}/filters
5. For each widget:
   - Show loading skeleton
   - Fetch data with new filters
   - Render updated chart
6. Cache responses for 5 min
```

### 4. Создание алерта

```
1. User clicks "Add Alert"
2. Modal form
3. Select alert type → Show relevant fields
4. Fill conditions
5. POST /alerts
6. Success → Add to alerts list
7. Background: cron checks alert every 10 min
8. If triggered → Show notification
```

### 5. Экспорт отчета

```
1. User clicks "Export" → Dropdown
2. Select format (PDF/Excel)
3. Modal with options
4. POST /export/dashboard/{id}/pdf
5. Show progress (polling or websocket)
6. Success → Download file
7. Keep link for re-download
```

---

## 🐛 Error Scenarios

### Module Not Active

```
Error 403:
{
  "success": false,
  "message": "Advanced Dashboard module is not active for this organization"
}

UI:
1. Show banner: "Activate Advanced Dashboard to unlock features"
2. CTA button: "Start 7-day trial" or "Activate" 
3. Disable all features
4. Redirect to activation page
```

### Dashboard Limit Exceeded

```
Error 403:
{
  "success": false,
  "message": "Dashboard limit exceeded (max 10 per user)"
}

UI:
1. Show modal: "You've reached the limit"
2. Message: "Delete unused dashboards or upgrade plan"
3. List current dashboards with "Delete" buttons
4. Upgrade CTA
```

### Validation Error

```
Error 400:
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "name": ["The name field is required"],
    "threshold_value": ["Must be a number"]
  }
}

UI:
1. Highlight error fields in red
2. Show error messages under fields
3. Focus first error field
4. Keep other filled values
```

### Rate Limit

```
Error 429:
{
  "success": false,
  "message": "Too many requests. Try again in 60 seconds"
}

UI:
1. Disable submit button
2. Show countdown timer
3. Re-enable after cooldown
```

---

## 📱 Mobile Considerations

**Responsive breakpoints:**
```
xs: <576px - 1 column
sm: ≥576px - 2 columns
md: ≥768px - 3 columns
lg: ≥992px - 4 columns
xl: ≥1200px - 4 columns
```

**Mobile optimizations:**
- Simplify charts (меньше data points)
- Stack widgets vertically
- Full-width cards
- Bottom sheet для filters
- Swipe gestures для navigation

---

## 🔧 Testing

**Manual testing checklist:**
```
□ Create dashboard from each template
□ Drag-and-drop widgets
□ Apply different filters
□ Create each type of alert
□ Export to PDF and Excel
□ Share dashboard (team/org)
□ Set default dashboard
□ Check mobile responsive
□ Test with empty state
□ Test error scenarios
```

**Performance benchmarks:**
- Dashboard load: <2 sec
- Widget render: <500ms
- Filter apply: <1 sec
- Export: <30 sec
- First paint: <1 sec

---

## 📚 Additional Resources

**OpenAPI Spec:**
- `docs/openapi/advanced-dashboard.yaml`
- Import to Postman/Insomnia для testing

**Permissions:**
- `@docs/PERMISSIONS_ADVANCED_DASHBOARD.md`
- Check user permissions before showing features

**Module README:**
- `app/BusinessModules/Features/AdvancedDashboard/README.md`

---

## ❓ FAQ

**Q: Как часто обновляются данные?**  
A: Данные кешируются 5 минут. Manual refresh кнопка force reload.

**Q: Можно ли кастомизировать виджеты?**  
A: Да, через `PUT /dashboards/{id}/widgets` с settings.

**Q: Сколько дашбордов можно создать?**  
A: Максимум 10 на пользователя (FREE версия).

**Q: Работает ли offline?**  
A: Нет, требуется internet connection.

**Q: Как часто проверяются алерты?**  
A: Каждые 10 минут через cron.

**Q: Размер файлов export?**  
A: PDF до 50MB, Excel до 100MB.

---

**Версия:** 1.0.0  
**Дата:** 4 октября 2025  
**Статус:** Production Ready ✅  

**Поддержка:** support@prohelper.ru  
**Docs:** https://docs.prohelper.ru

