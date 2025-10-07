# Advanced Dashboard API - Полная документация для Frontend

**Версия:** 1.0.1  
**Дата:** 7 октября 2025  
**Базовый URL:** `/api/v1/admin/advanced-dashboard`

---

## 🔐 Аутентификация

Все запросы требуют заголовки:
```javascript
{
  'Authorization': 'Bearer {JWT_TOKEN}',
  'X-Organization-ID': '{ORGANIZATION_ID}',
  'Content-Type': 'application/json',
  'Accept': 'application/json'
}
```

---

## 📊 1. Реестр виджетов

### GET `/widgets/registry`

Получить список всех доступных виджетов с их статусом.

**Параметры:** Нет

**Пример запроса:**
```javascript
const response = await fetch('/api/v1/admin/advanced-dashboard/widgets/registry', {
  headers: {
    'Authorization': `Bearer ${token}`,
    'X-Organization-ID': organizationId
  }
});
const data = await response.json();
```

**Ответ 200:**
```json
{
  "success": true,
  "data": {
    "widgets": [
      {
        "id": "cash_flow",
        "name": "Движение денежных средств",
        "description": "Анализ притока и оттока денежных средств",
        "category": "financial",
        "is_ready": true,
        "endpoint": "/analytics/financial/cash-flow",
        "icon": "trending-up",
        "default_size": {"w": 6, "h": 3},
        "min_size": {"w": 4, "h": 2}
      }
    ],
    "categories": [
      {
        "id": "financial",
        "name": "Финансовая аналитика",
        "description": "Виджеты для финансового анализа",
        "color": "#10B981",
        "icon": "dollar-sign"
      }
    ],
    "stats": {
      "total_widgets": 11,
      "ready_widgets": 11,
      "in_development": 0
    }
  }
}
```

---

## 💰 2. Финансовые виджеты

### GET `/analytics/financial/cash-flow`

Анализ движения денежных средств.

**Обязательные параметры:**
- `from` (string) - Дата начала в формате `YYYY-MM-DD`
- `to` (string) - Дата окончания в формате `YYYY-MM-DD`

**Опциональные параметры:**
- `project_id` (integer) - ID проекта для фильтрации

**Пример запроса:**
```javascript
const params = new URLSearchParams({
  from: '2025-01-01',
  to: '2025-10-07'
});

const response = await fetch(`/api/v1/admin/advanced-dashboard/analytics/financial/cash-flow?${params}`, {
  headers: {
    'Authorization': `Bearer ${token}`,
    'X-Organization-ID': organizationId
  }
});
```

**Ответ 200:**
```json
{
  "success": true,
  "data": {
    "period": {
      "from": "2025-01-01T00:00:00.000000Z",
      "to": "2025-10-07T23:59:59.000000Z"
    },
    "total_inflow": 5000000,
    "total_outflow": 3500000,
    "net_cash_flow": 1500000,
    "monthly_breakdown": [
      {
        "month": "2025-01",
        "month_name": "Январь 2025",
        "inflow": 500000,
        "outflow": 350000,
        "net": 150000
      }
    ],
    "inflow_by_category": [
      {
        "category": "Контракты",
        "amount": 3000000,
        "percentage": 60
      },
      {
        "category": "Авансовые платежи",
        "amount": 1500000,
        "percentage": 30
      },
      {
        "category": "Оплата за работы",
        "amount": 500000,
        "percentage": 10
      }
    ],
    "outflow_by_category": [
      {
        "category": "Материалы",
        "amount": 2000000,
        "percentage": 57.14
      },
      {
        "category": "Зарплаты",
        "amount": 1000000,
        "percentage": 28.57
      },
      {
        "category": "Подрядчики",
        "amount": 500000,
        "percentage": 14.29
      }
    ]
  }
}
```

**Ошибка 422:**
```json
{
  "success": false,
  "message": "The from field is required. (and 1 more error)",
  "errors": {
    "from": ["The from field is required."],
    "to": ["The to field is required."]
  }
}
```

---

### GET `/analytics/financial/profit-loss`

Отчет о прибылях и убытках.

**Обязательные параметры:**
- `from` (string) - Дата начала `YYYY-MM-DD`
- `to` (string) - Дата окончания `YYYY-MM-DD`

**Опциональные параметры:**
- `project_id` (integer) - ID проекта

**Пример запроса:**
```javascript
const params = new URLSearchParams({
  from: '2025-01-01',
  to: '2025-10-07'
});

const response = await fetch(`/api/v1/admin/advanced-dashboard/analytics/financial/profit-loss?${params}`, {
  headers: {
    'Authorization': `Bearer ${token}`,
    'X-Organization-ID': organizationId
  }
});
```

**Ответ 200:**
```json
{
  "success": true,
  "data": {
    "period": {
      "from": "2025-01-01T00:00:00.000000Z",
      "to": "2025-10-07T23:59:59.000000Z"
    },
    "revenue": 5000000,
    "cost_of_goods_sold": 2000000,
    "gross_profit": 3000000,
    "gross_margin_percentage": 60,
    "operating_expenses": 1000000,
    "operating_profit": 2000000,
    "operating_margin_percentage": 40,
    "other_income": 100000,
    "other_expenses": 50000,
    "net_profit": 2050000,
    "net_margin_percentage": 41,
    "breakdown_by_project": [
      {
        "project_id": 1,
        "project_name": "Проект А",
        "revenue": 2000000,
        "costs": 800000,
        "profit": 1200000,
        "margin_percentage": 60
      }
    ]
  }
}
```

---

### GET `/analytics/financial/roi`

Расчет рентабельности инвестиций.

**Обязательные параметры:**
- `from` (string) - Дата начала `YYYY-MM-DD`
- `to` (string) - Дата окончания `YYYY-MM-DD`

**Опциональные параметры:**
- `project_id` (integer) - ID проекта

**Пример запроса:**
```javascript
const params = new URLSearchParams({
  from: '2025-01-01',
  to: '2025-10-07'
});

const response = await fetch(`/api/v1/admin/advanced-dashboard/analytics/financial/roi?${params}`, {
  headers: {
    'Authorization': `Bearer ${token}`,
    'X-Organization-ID': organizationId
  }
});
```

**Ответ 200:**
```json
{
  "success": true,
  "data": {
    "period": {
      "from": "2025-01-01T00:00:00.000000Z",
      "to": "2025-10-07T23:59:59.000000Z"
    },
    "overall_roi": 45.5,
    "total_invested": 10000000,
    "total_returned": 14550000,
    "total_profit": 4550000,
    "projects": [
      {
        "project_id": 1,
        "project_name": "Проект А",
        "invested": 2000000,
        "returned": 3000000,
        "profit": 1000000,
        "roi_percentage": 50
      }
    ],
    "top_performers": [
      {
        "project_id": 1,
        "project_name": "Проект А",
        "roi_percentage": 50
      }
    ],
    "worst_performers": [
      {
        "project_id": 5,
        "project_name": "Проект Е",
        "roi_percentage": -10
      }
    ]
  }
}
```

---

### GET `/analytics/financial/revenue-forecast`

Прогноз доходов на 6 месяцев.

**Обязательные параметры:** Нет

**Опциональные параметры:**
- `months` (integer) - Количество месяцев прогноза (по умолчанию 6)
- `project_id` (integer) - ID проекта

**Пример запроса:**
```javascript
const params = new URLSearchParams({
  months: '6'
});

const response = await fetch(`/api/v1/admin/advanced-dashboard/analytics/financial/revenue-forecast?${params}`, {
  headers: {
    'Authorization': `Bearer ${token}`,
    'X-Organization-ID': organizationId
  }
});
```

**Ответ 200:**
```json
{
  "success": true,
  "data": {
    "forecast_period_months": 6,
    "forecast": [
      {
        "month": "2025-11",
        "month_name": "Ноябрь 2025",
        "forecasted_revenue": 800000,
        "based_on_contracts": 600000,
        "based_on_trend": 200000,
        "confidence_level": 0.85
      }
    ],
    "total_forecasted_revenue": 4800000,
    "average_monthly_revenue": 800000,
    "confidence_level": 0.85,
    "method": "combined",
    "contracts_contribution_percentage": 70,
    "trend_contribution_percentage": 30
  }
}
```

---

### GET `/analytics/financial/receivables-payables`

Дебиторская и кредиторская задолженность.

**Обязательные параметры:** Нет

**Опциональные параметры:**
- `as_of_date` (string) - Дата на которую рассчитывать (по умолчанию сегодня) `YYYY-MM-DD`

**Пример запроса:**
```javascript
const params = new URLSearchParams({
  as_of_date: '2025-10-07'
});

const response = await fetch(`/api/v1/admin/advanced-dashboard/analytics/financial/receivables-payables?${params}`, {
  headers: {
    'Authorization': `Bearer ${token}`,
    'X-Organization-ID': organizationId
  }
});
```

**Ответ 200:**
```json
{
  "success": true,
  "data": {
    "as_of_date": "2025-10-07",
    "receivables": {
      "total": 2000000,
      "current": 1000000,
      "overdue_30": 500000,
      "overdue_60": 300000,
      "overdue_90_plus": 200000,
      "by_contract": [
        {
          "contract_id": 1,
          "contract_number": "К-001",
          "client_name": "ООО Клиент",
          "amount": 500000,
          "due_date": "2025-11-01",
          "days_overdue": 0,
          "aging_category": "current"
        }
      ]
    },
    "payables": {
      "total": 1500000,
      "current": 800000,
      "overdue_30": 400000,
      "overdue_60": 200000,
      "overdue_90_plus": 100000,
      "by_supplier": [
        {
          "supplier_id": 1,
          "supplier_name": "ООО Поставщик",
          "amount": 300000,
          "due_date": "2025-10-15",
          "days_overdue": 0,
          "aging_category": "current"
        }
      ]
    },
    "net_position": 500000,
    "working_capital_ratio": 1.33
  }
}
```

---

## 🔮 3. Предиктивные виджеты

### GET `/analytics/predictive/contract-forecast`

Прогноз завершения контрактов.

**Обязательные параметры:** Нет

**Опциональные параметры:**
- `contract_id` (integer) - ID конкретного контракта

**Пример запроса:**
```javascript
const response = await fetch('/api/v1/admin/advanced-dashboard/analytics/predictive/contract-forecast', {
  headers: {
    'Authorization': `Bearer ${token}`,
    'X-Organization-ID': organizationId
  }
});
```

**Ответ 200:**
```json
{
  "success": true,
  "data": {
    "contracts": [
      {
        "contract_id": 1,
        "contract_number": "К-001",
        "project_name": "Проект А",
        "current_progress_percentage": 65,
        "planned_end_date": "2025-12-31",
        "predicted_end_date": "2026-01-15",
        "days_delay": 15,
        "confidence_level": 0.82,
        "risk_level": "medium",
        "completion_speed_percentage": 85,
        "factors": [
          "Текущая скорость выполнения ниже плановой",
          "Прогресс замедлился в последние 30 дней"
        ]
      }
    ],
    "summary": {
      "total_contracts": 10,
      "on_track": 6,
      "at_risk": 3,
      "critical": 1
    }
  }
}
```

---

### GET `/analytics/predictive/budget-risk`

Анализ рисков превышения бюджета.

**Обязательные параметры:** Нет

**Опциональные параметры:**
- `project_id` (integer) - ID проекта

**Пример запроса:**
```javascript
const response = await fetch('/api/v1/admin/advanced-dashboard/analytics/predictive/budget-risk', {
  headers: {
    'Authorization': `Bearer ${token}`,
    'X-Organization-ID': organizationId
  }
});
```

**Ответ 200:**
```json
{
  "success": true,
  "data": {
    "projects": [
      {
        "project_id": 1,
        "project_name": "Проект А",
        "total_budget": 5000000,
        "spent_amount": 3500000,
        "spent_percentage": 70,
        "completion_percentage": 65,
        "predicted_total_cost": 5384615,
        "predicted_overrun": 384615,
        "overrun_percentage": 7.69,
        "risk_level": "medium",
        "burn_rate_per_day": 50000,
        "remaining_budget": 1500000,
        "days_until_budget_exhausted": 30,
        "factors": [
          "Скорость расхода выше плановой",
          "Прогресс отстает от бюджета"
        ]
      }
    ],
    "summary": {
      "total_projects": 10,
      "low_risk": 6,
      "medium_risk": 3,
      "high_risk": 1,
      "total_predicted_overrun": 1000000
    }
  }
}
```

---

### GET `/analytics/predictive/material-needs`

Прогноз потребности в материалах.

**Обязательные параметры:** Нет

**Опциональные параметры:**
- `months` (integer) - Количество месяцев прогноза (по умолчанию 3)
- `project_id` (integer) - ID проекта

**Пример запроса:**
```javascript
const params = new URLSearchParams({
  months: '3'
});

const response = await fetch(`/api/v1/admin/advanced-dashboard/analytics/predictive/material-needs?${params}`, {
  headers: {
    'Authorization': `Bearer ${token}`,
    'X-Organization-ID': organizationId
  }
});
```

**Ответ 200:**
```json
{
  "success": true,
  "data": {
    "forecast_period_months": 3,
    "materials": [
      {
        "material_id": 1,
        "material_name": "Цемент М500",
        "unit": "кг",
        "current_stock": 10000,
        "average_monthly_consumption": 5000,
        "predicted_needs": [
          {
            "month": "2025-11",
            "predicted_quantity": 5200,
            "confidence_level": 0.85
          }
        ],
        "total_predicted_need": 15600,
        "reorder_recommended": true,
        "reorder_quantity": 20000,
        "reorder_date": "2025-10-15",
        "stock_out_risk_date": "2025-12-01"
      }
    ],
    "summary": {
      "total_materials_tracked": 50,
      "materials_need_reorder": 15,
      "total_predicted_cost": 2000000
    }
  }
}
```

---

## 👥 4. HR & KPI виджеты

### GET `/analytics/hr/kpi`

KPI сотрудников.

**Обязательные параметры:**
- `from` (string) - Дата начала `YYYY-MM-DD`
- `to` (string) - Дата окончания `YYYY-MM-DD`

**Опциональные параметры:**
- `user_id` (integer) - ID конкретного пользователя

**Пример запроса:**
```javascript
const params = new URLSearchParams({
  from: '2025-01-01',
  to: '2025-10-07'
});

const response = await fetch(`/api/v1/admin/advanced-dashboard/analytics/hr/kpi?${params}`, {
  headers: {
    'Authorization': `Bearer ${token}`,
    'X-Organization-ID': organizationId
  }
});
```

**Ответ 200:**
```json
{
  "success": true,
  "data": {
    "period": {
      "from": "2025-01-01T00:00:00.000000Z",
      "to": "2025-10-07T23:59:59.000000Z"
    },
    "employees": [
      {
        "user_id": 1,
        "user_name": "Иванов Иван",
        "role": "Прораб",
        "total_works_completed": 50,
        "total_works_amount": 1000000,
        "on_time_completion_rate": 92,
        "quality_score": 4.5,
        "average_completion_days": 3.5,
        "active_projects_count": 5,
        "kpi_score": 85
      }
    ],
    "summary": {
      "total_employees": 25,
      "average_kpi_score": 78,
      "total_works_completed": 1250,
      "average_on_time_rate": 85
    }
  }
}
```

---

### GET `/analytics/hr/top-performers`

Топ исполнителей.

**Обязательные параметры:**
- `from` (string) - Дата начала `YYYY-MM-DD`
- `to` (string) - Дата окончания `YYYY-MM-DD`

**Опциональные параметры:**
- `limit` (integer) - Количество топ-исполнителей (по умолчанию 10)

**Пример запроса:**
```javascript
const params = new URLSearchParams({
  from: '2025-01-01',
  to: '2025-10-07',
  limit: '10'
});

const response = await fetch(`/api/v1/admin/advanced-dashboard/analytics/hr/top-performers?${params}`, {
  headers: {
    'Authorization': `Bearer ${token}`,
    'X-Organization-ID': organizationId
  }
});
```

**Ответ 200:**
```json
{
  "success": true,
  "data": {
    "period": {
      "from": "2025-01-01T00:00:00.000000Z",
      "to": "2025-10-07T23:59:59.000000Z"
    },
    "top_performers": [
      {
        "rank": 1,
        "user_id": 1,
        "user_name": "Иванов Иван",
        "role": "Прораб",
        "total_works_completed": 50,
        "total_works_amount": 1000000,
        "on_time_completion_rate": 95,
        "quality_score": 4.8,
        "overall_score": 92
      }
    ],
    "criteria": {
      "works_completed_weight": 30,
      "amount_weight": 20,
      "on_time_weight": 25,
      "quality_weight": 25
    }
  }
}
```

---

### GET `/analytics/hr/resource-utilization`

Загрузка ресурсов.

**Обязательные параметры:**
- `from` (string) - Дата начала `YYYY-MM-DD`
- `to` (string) - Дата окончания `YYYY-MM-DD`

**Опциональные параметры:**
- `user_id` (integer) - ID пользователя
- `role` (string) - Роль для фильтрации

**Пример запроса:**
```javascript
const params = new URLSearchParams({
  from: '2025-01-01',
  to: '2025-10-07'
});

const response = await fetch(`/api/v1/admin/advanced-dashboard/analytics/hr/resource-utilization?${params}`, {
  headers: {
    'Authorization': `Bearer ${token}`,
    'X-Organization-ID': organizationId
  }
});
```

**Ответ 200:**
```json
{
  "success": true,
  "data": {
    "period": {
      "from": "2025-01-01T00:00:00.000000Z",
      "to": "2025-10-07T23:59:59.000000Z"
    },
    "employees": [
      {
        "user_id": 1,
        "user_name": "Иванов Иван",
        "role": "Прораб",
        "total_assigned_projects": 5,
        "active_projects": 3,
        "total_assigned_hours": 800,
        "worked_hours": 720,
        "utilization_percentage": 90,
        "available_capacity_hours": 80,
        "status": "fully_utilized"
      }
    ],
    "summary": {
      "total_employees": 25,
      "average_utilization": 75,
      "underutilized_count": 5,
      "optimal_utilization_count": 15,
      "overutilized_count": 5
    },
    "by_role": [
      {
        "role": "Прораб",
        "count": 10,
        "average_utilization": 85
      }
    ]
  }
}
```

---

## 📈 5. Управление дашбордами

### GET `/dashboards`

Получить список дашбордов пользователя.

**Обязательные параметры:** Нет

**Опциональные параметры:**
- `include_shared` (boolean) - Включить расшаренные дашборды (по умолчанию true)

**Пример запроса:**
```javascript
const params = new URLSearchParams({
  include_shared: 'true'
});

const response = await fetch(`/api/v1/admin/advanced-dashboard/dashboards?${params}`, {
  headers: {
    'Authorization': `Bearer ${token}`,
    'X-Organization-ID': organizationId
  }
});
```

**Ответ 200:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "Мой финансовый дашборд",
      "description": "Обзор финансовых показателей",
      "slug": "my-finance",
      "template": "finance",
      "is_default": true,
      "is_shared": false,
      "visibility": "private",
      "widgets": [
        {
          "id": "cash-flow-widget",
          "type": "cash_flow",
          "position": {"x": 0, "y": 0, "w": 6, "h": 3},
          "settings": {
            "refresh_interval": 300
          }
        }
      ],
      "layout": {
        "type": "grid",
        "columns": 12,
        "rows": "auto",
        "gap": 16
      },
      "filters": {
        "date_range": {
          "from": "2025-01-01",
          "to": "2025-10-07"
        }
      },
      "created_at": "2025-10-01T10:00:00.000000Z",
      "updated_at": "2025-10-07T15:30:00.000000Z"
    }
  ]
}
```

---

### POST `/dashboards`

Создать новый дашборд.

**Обязательные параметры:**
- `name` (string) - Название дашборда (макс. 255 символов)

**Опциональные параметры:**
- `description` (string) - Описание (макс. 1000 символов)
- `slug` (string) - URL slug (макс. 255 символов)
- `layout` (object) - Конфигурация layout
- `widgets` (array) - Массив виджетов
- `filters` (object) - Глобальные фильтры
- `template` (string) - Шаблон: `admin`, `finance`, `technical`, `hr`, `custom`
- `refresh_interval` (integer) - Интервал обновления в секундах (30-3600)
- `enable_realtime` (boolean) - Включить real-time обновления
- `visibility` (string) - Видимость: `private`, `team`, `organization`

**Пример запроса:**
```javascript
const response = await fetch('/api/v1/admin/advanced-dashboard/dashboards', {
  method: 'POST',
  headers: {
    'Authorization': `Bearer ${token}`,
    'X-Organization-ID': organizationId,
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({
    name: 'Мой дашборд',
    description: 'Персональный дашборд',
    template: 'finance',
    visibility: 'private'
  })
});
```

**Ответ 201:**
```json
{
  "success": true,
  "message": "Dashboard created successfully",
  "data": {
    "id": 2,
    "name": "Мой дашборд",
    "description": "Персональный дашборд",
    "slug": "moj-dashbord",
    "template": "finance",
    "is_default": false,
    "visibility": "private",
    "created_at": "2025-10-07T16:00:00.000000Z"
  }
}
```

---

### GET `/dashboards/templates`

Получить доступные шаблоны дашбордов.

**Параметры:** Нет

**Ответ 200:**
```json
{
  "success": true,
  "data": [
    {
      "id": "admin",
      "name": "Административный дашборд",
      "description": "Общий обзор всех проектов и контрактов",
      "widgets_count": 3
    },
    {
      "id": "finance",
      "name": "Финансовый дашборд",
      "description": "Финансовая аналитика и прогнозы",
      "widgets_count": 4
    },
    {
      "id": "technical",
      "name": "Технический дашборд",
      "description": "Выполненные работы и материалы",
      "widgets_count": 3
    },
    {
      "id": "hr",
      "name": "HR дашборд",
      "description": "KPI сотрудников и загрузка ресурсов",
      "widgets_count": 3
    }
  ]
}
```

---

## 🔔 6. Управление алертами

### GET `/alerts`

Получить список алертов.

**Опциональные параметры:**
- `dashboard_id` (integer) - Фильтр по дашборду
- `is_active` (boolean) - Только активные

**Ответ 200:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "dashboard_id": 1,
      "name": "Превышение бюджета",
      "description": "Оповещение при превышении бюджета на 10%",
      "trigger_type": "threshold",
      "trigger_config": {
        "metric": "budget_spent_percentage",
        "operator": "greater_than",
        "value": 90
      },
      "is_active": true,
      "notification_channels": ["email", "telegram"],
      "last_triggered_at": null,
      "trigger_count": 0,
      "created_at": "2025-10-01T10:00:00.000000Z"
    }
  ]
}
```

---

### POST `/alerts`

Создать новый алерт.

**Обязательные параметры:**
- `dashboard_id` (integer) - ID дашборда
- `name` (string) - Название алерта
- `trigger_type` (string) - Тип триггера: `threshold`, `change`, `anomaly`, `schedule`, `custom`
- `trigger_config` (object) - Конфигурация триггера

**Опциональные параметры:**
- `description` (string) - Описание
- `notification_channels` (array) - Каналы: `email`, `telegram`, `slack`, `webhook`
- `is_active` (boolean) - Активен (по умолчанию true)

**Пример запроса:**
```javascript
const response = await fetch('/api/v1/admin/advanced-dashboard/alerts', {
  method: 'POST',
  headers: {
    'Authorization': `Bearer ${token}`,
    'X-Organization-ID': organizationId,
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({
    dashboard_id: 1,
    name: 'Критический бюджет',
    trigger_type: 'threshold',
    trigger_config: {
      metric: 'budget_spent_percentage',
      operator: 'greater_than',
      value: 95
    },
    notification_channels: ['email', 'telegram']
  })
});
```

**Ответ 201:**
```json
{
  "success": true,
  "message": "Alert created successfully",
  "data": {
    "id": 2,
    "dashboard_id": 1,
    "name": "Критический бюджет",
    "is_active": true,
    "created_at": "2025-10-07T16:00:00.000000Z"
  }
}
```

---

## 📥 7. Экспорт

### POST `/export/dashboard/{id}/pdf`

Экспортировать дашборд в PDF.

**Обязательные параметры:**
- `id` (path parameter) - ID дашборда

**Опциональные параметры (в body):**
- `orientation` (string) - Ориентация: `portrait`, `landscape` (по умолчанию portrait)
- `paper_size` (string) - Размер: `a4`, `a3`, `letter` (по умолчанию a4)
- `include_charts` (boolean) - Включить графики (по умолчанию true)

**Пример запроса:**
```javascript
const response = await fetch('/api/v1/admin/advanced-dashboard/export/dashboard/1/pdf', {
  method: 'POST',
  headers: {
    'Authorization': `Bearer ${token}`,
    'X-Organization-ID': organizationId,
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({
    orientation: 'landscape',
    paper_size: 'a4'
  })
});
```

**Ответ 200:**
```json
{
  "success": true,
  "message": "PDF export completed",
  "data": {
    "file_path": "exports/dashboards/moj-dashbord_1696700000.pdf",
    "download_url": "/storage/exports/dashboards/moj-dashbord_1696700000.pdf",
    "file_size": 2048576,
    "expires_at": "2025-10-14T16:00:00.000000Z"
  }
}
```

---

### POST `/export/dashboard/{id}/excel`

Экспортировать дашборд в Excel.

**Обязательные параметры:**
- `id` (path parameter) - ID дашборда

**Опциональные параметры (в body):**
- `include_charts` (boolean) - Включить графики (по умолчанию true)
- `separate_sheets` (boolean) - Разделить виджеты по листам (по умолчанию true)

**Пример запроса:**
```javascript
const response = await fetch('/api/v1/admin/advanced-dashboard/export/dashboard/1/excel', {
  method: 'POST',
  headers: {
    'Authorization': `Bearer ${token}`,
    'X-Organization-ID': organizationId,
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({
    separate_sheets: true
  })
});
```

**Ответ 200:**
```json
{
  "success": true,
  "message": "Excel export completed",
  "data": {
    "file_path": "exports/dashboards/moj-dashbord_1696700000.xlsx",
    "download_url": "/storage/exports/dashboards/moj-dashbord_1696700000.xlsx",
    "file_size": 1048576,
    "expires_at": "2025-10-14T16:00:00.000000Z"
  }
}
```

---

## ❌ Коды ошибок

### 400 Bad Request
```json
{
  "success": false,
  "message": "Organization context is required"
}
```

### 401 Unauthorized
```json
{
  "success": false,
  "message": "Unauthorized",
  "code": "UNAUTHORIZED"
}
```

### 403 Forbidden (Модуль не активирован)
```json
{
  "success": false,
  "message": "Advanced Dashboard module is not active for this organization",
  "code": "MODULE_NOT_ACTIVE",
  "required_module": "advanced-dashboard",
  "hint": "Activate the Advanced Dashboard module to access this feature"
}
```

### 404 Not Found
```json
{
  "success": false,
  "message": "Dashboard not found"
}
```

### 422 Validation Error
```json
{
  "success": false,
  "message": "The from field is required. (and 1 more error)",
  "errors": {
    "from": ["The from field is required."],
    "to": ["The to field is required."]
  }
}
```

### 500 Internal Server Error
```json
{
  "success": false,
  "message": "Internal server error occurred",
  "error": "Error details (только в dev режиме)"
}
```

---

## 📝 Примеры использования

### React/TypeScript пример

```typescript
// types.ts
export interface CashFlowParams {
  from: string; // YYYY-MM-DD
  to: string;   // YYYY-MM-DD
  project_id?: number;
}

export interface CashFlowResponse {
  success: boolean;
  data: {
    period: {
      from: string;
      to: string;
    };
    total_inflow: number;
    total_outflow: number;
    net_cash_flow: number;
    // ... остальные поля
  };
}

// api.ts
const API_BASE = '/api/v1/admin/advanced-dashboard';

export async function fetchCashFlow(
  params: CashFlowParams,
  token: string,
  organizationId: number
): Promise<CashFlowResponse> {
  const searchParams = new URLSearchParams({
    from: params.from,
    to: params.to,
    ...(params.project_id && { project_id: params.project_id.toString() })
  });

  const response = await fetch(
    `${API_BASE}/analytics/financial/cash-flow?${searchParams}`,
    {
      headers: {
        'Authorization': `Bearer ${token}`,
        'X-Organization-ID': organizationId.toString(),
        'Accept': 'application/json'
      }
    }
  );

  if (!response.ok) {
    const error = await response.json();
    throw new Error(error.message);
  }

  return response.json();
}

// component.tsx
function CashFlowWidget() {
  const [data, setData] = useState<CashFlowResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const loadData = async () => {
      try {
        setLoading(true);
        const result = await fetchCashFlow(
          {
            from: '2025-01-01',
            to: '2025-10-07'
          },
          authToken,
          currentOrganizationId
        );
        setData(result);
      } catch (err) {
        setError(err.message);
      } finally {
        setLoading(false);
      }
    };

    loadData();
  }, []);

  if (loading) return <div>Загрузка...</div>;
  if (error) return <div>Ошибка: {error}</div>;
  if (!data) return null;

  return (
    <div>
      <h3>Cash Flow</h3>
      <p>Приток: {data.data.total_inflow.toLocaleString('ru-RU')} ₽</p>
      <p>Отток: {data.data.total_outflow.toLocaleString('ru-RU')} ₽</p>
      <p>Чистый поток: {data.data.net_cash_flow.toLocaleString('ru-RU')} ₽</p>
    </div>
  );
}
```

---

## ✅ Чек-лист для фронтенда

- [ ] Всегда передавать `from` и `to` для финансовых и HR виджетов
- [ ] Использовать формат дат `YYYY-MM-DD`
- [ ] Добавлять заголовок `X-Organization-ID` ко всем запросам
- [ ] Обрабатывать ошибку 403 (модуль не активирован)
- [ ] Обрабатывать ошибку 422 (валидация параметров)
- [ ] Показывать `is_ready: false` виджеты как "В разработке"
- [ ] Кешировать ответ `/widgets/registry` (обновлять раз в час)
- [ ] Использовать `confidence_level` для отображения надежности прогнозов
- [ ] Отображать загрузочные состояния при запросах
- [ ] Показывать пользователю понятные сообщения об ошибках

---

**Обновлено:** 7 октября 2025  
**Версия документа:** 2.0  
**Контакт:** API Support Team

