# 🚀 Quick Start: Frontend Integration

## Быстрый старт для фронтенд разработчиков

### 📋 Checklist для начала работы

- [ ] Прочитать полный гайд: `@docs/FRONTEND_INTEGRATION_GUIDE.md`
- [ ] Импортировать OpenAPI spec: `docs/openapi/advanced-dashboard.yaml`
- [ ] Настроить headers (Authorization + X-Organization-ID)
- [ ] Проверить активацию модуля
- [ ] Реализовать первый endpoint

---

## ⚡ Быстрые примеры

### 1. Проверка модуля

```http
GET /api/modules/advanced-dashboard/status
Headers:
  Authorization: Bearer {token}
  X-Organization-ID: 123

✅ Активен:
{
  "success": true,
  "data": {
    "is_active": true,
    "is_trial": false
  }
}

❌ Не активен:
{
  "success": false,
  "message": "Module not active"
}
→ Показать баннер активации
```

---

### 2. Получить дашборды

```http
GET /api/v1/admin/advanced-dashboard/dashboards

Ответ:
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "My Dashboard",
      "is_default": true,
      "widgets": [...],
      "layout": {...}
    }
  ]
}

UI: Список в sidebar, звездочка на default
```

---

### 3. Создать дашборд

```http
POST /api/v1/admin/advanced-dashboard/dashboards/from-template

Body:
{
  "template": "finance",
  "name": "My Finance Dashboard"
}

→ Редирект на новый дашборд
```

**Шаблоны:** admin | finance | technical | hr

---

### 4. Cash Flow виджет

```http
GET /api/v1/admin/advanced-dashboard/analytics/financial/cash-flow?from=2025-01-01&to=2025-10-04&groupBy=month

Ответ:
{
  "data": {
    "cash_flow": [
      {
        "period": "2025-01",
        "inflow": 1500000,
        "outflow": 800000,
        "net": 700000
      }
    ],
    "summary": {
      "total_inflow": 3500000,
      "net_cash_flow": 1500000
    }
  }
}

UI: Bar Chart (inflow/outflow) + Summary cards
```

---

### 5. KPI пользователя

```http
GET /api/v1/admin/advanced-dashboard/analytics/hr/kpi?user_id=15&from=2025-01-01&to=2025-10-04

Ответ:
{
  "data": {
    "user_name": "Иван Иванов",
    "metrics": {
      "completed_works_count": 45,
      "on_time_completion_rate": 92.5,
      "quality_score": 4.5
    },
    "overall_kpi": 87.5,
    "performance_level": "excellent"
  }
}

UI: Radar chart + Gauge для overall KPI
```

---

### 6. Создать алерт

```http
POST /api/v1/admin/advanced-dashboard/alerts

Body:
{
  "name": "Budget Alert",
  "alert_type": "budget_overrun",
  "target_entity": "project",
  "target_entity_id": 5,
  "comparison_operator": "gt",
  "threshold_value": 80,
  "priority": "high"
}

UI: Form с условиями → Toast success
```

---

### 7. Экспорт в PDF

```http
POST /api/v1/admin/advanced-dashboard/export/dashboard/1/pdf

Body:
{
  "filters": {
    "from": "2025-01-01",
    "to": "2025-10-04"
  }
}

Ответ:
{
  "data": {
    "file_url": "https://storage.../export.pdf"
  }
}

UI: Loading 10-30 сек → Download button
```

---

## 🎯 Ключевые endpoints

| Действие | Метод | URL |
|----------|-------|-----|
| Список дашбордов | GET | `/dashboards` |
| Создать | POST | `/dashboards/from-template` |
| Шаблоны | GET | `/dashboards/templates` |
| Обновить layout | PUT | `/dashboards/{id}/layout` |
| Cash Flow | GET | `/analytics/financial/cash-flow` |
| P&L | GET | `/analytics/financial/profit-loss` |
| Прогноз контракта | GET | `/analytics/predictive/contract-forecast` |
| KPI | GET | `/analytics/hr/kpi` |
| Топ исполнители | GET | `/analytics/hr/top-performers` |
| Список алертов | GET | `/alerts` |
| Создать алерт | POST | `/alerts` |
| Экспорт PDF | POST | `/export/dashboard/{id}/pdf` |

---

## 🎨 UI Components

### Обязательные компоненты:

1. **Dashboard Grid**
   - Lib: `react-grid-layout` / `vue-grid-layout`
   - 12 колонок, responsive
   - Drag-and-drop

2. **Charts**
   - Lib: `Chart.js` / `ApexCharts` / `Recharts`
   - Types: Bar, Line, Area, Pie, Gauge, Radar

3. **Data Table**
   - Sorting, filtering, pagination
   - Export CSV

4. **Filters Toolbar**
   - Period picker (7/30/90 дней)
   - Multi-select (проекты, контракты)
   - Apply button

5. **Alert Form**
   - Alert type selector
   - Condition builder
   - Recipients input (chips)

---

## 🔧 Setup Steps

### 1. Install dependencies

```bash
npm install axios
npm install chart.js react-chartjs-2  # for React
npm install react-grid-layout
npm install date-fns  # date utils
```

### 2. Create API client

```typescript
const api = axios.create({
  baseURL: '/api/v1/admin/advanced-dashboard',
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json'
  }
});

// Add auth interceptor
api.interceptors.request.use(config => {
  const token = getToken();
  const orgId = getOrganizationId();
  
  config.headers.Authorization = `Bearer ${token}`;
  config.headers['X-Organization-ID'] = orgId;
  
  return config;
});
```

### 3. Create dashboard page

```
/dashboards
  - Sidebar: List of dashboards
  - Main: Grid with widgets
  - Toolbar: Filters
  - Actions: Share, Export, Settings
```

### 4. Implement widgets

```
Widget types:
- CashFlowWidget
- ProfitLossWidget
- ROIWidget
- ContractForecastWidget
- TopPerformersWidget
- UserKPIWidget
```

### 5. Test with mock data

```
Use OpenAPI spec to generate mocks:
- Postman Mock Server
- MSW (Mock Service Worker)
```

---

## 🐛 Common Issues

### 403 Module Not Active
```
→ Check /api/modules/advanced-dashboard/status
→ Show activation banner
→ Redirect to activation page
```

### 401 Unauthorized
```
→ Check JWT token validity
→ Refresh token
→ Redirect to login
```

### Charts not rendering
```
→ Check data format
→ Verify chart library version
→ Console errors
```

### Slow dashboard load
```
→ Lazy load widgets
→ Use skeleton screens
→ Cache responses (5 min)
```

---

## 📱 Responsive

**Breakpoints:**
```
xs: <576px  - 1 col
sm: 576px   - 2 cols
md: 768px   - 3 cols
lg: 992px   - 4 cols
xl: 1200px  - 4 cols
```

**Mobile:**
- Stack widgets vertically
- Simplify charts
- Bottom sheet filters
- Swipe navigation

---

## ✅ Testing Checklist

**Functional:**
- [ ] Create dashboard from template
- [ ] Drag-and-drop widgets
- [ ] Apply filters
- [ ] Create alert
- [ ] Export PDF/Excel
- [ ] Share dashboard

**UI:**
- [ ] Loading states
- [ ] Error handling
- [ ] Empty states
- [ ] Mobile responsive
- [ ] Dark mode (if supported)

**Performance:**
- [ ] Dashboard load <2s
- [ ] Widget render <500ms
- [ ] No memory leaks
- [ ] Smooth animations

---

## 🔗 Resources

**Full Guide:** `@docs/FRONTEND_INTEGRATION_GUIDE.md` (600+ строк)  
**OpenAPI:** `docs/openapi/advanced-dashboard.yaml`  
**Permissions:** `@docs/PERMISSIONS_ADVANCED_DASHBOARD.md`  

**Support:** support@prohelper.ru

---

**Готово к разработке!** 🚀

Start with implementing dashboard list and one widget, then expand from there.

