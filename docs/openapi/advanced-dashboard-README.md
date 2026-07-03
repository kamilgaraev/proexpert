# Advanced Dashboard API Documentation

## 📚 Обзор

OpenAPI 3.0 спецификация для модуля "Продвинутый дашборд" МОСТ.

**Файл спецификации:** `advanced-dashboard.yaml`

## 🚀 Быстрый старт

### Просмотр документации

**Swagger UI:**
```bash
# Локально
npx @redocly/cli preview-docs docs/openapi/advanced-dashboard.yaml

# Или через Docker
docker run -p 8080:8080 -v $(pwd)/docs/openapi:/usr/share/nginx/html/api swaggerapi/swagger-ui
```

**ReDoc:**
```bash
npx @redocly/cli preview-docs docs/openapi/advanced-dashboard.yaml --theme=redoc
```

**Online:**
- Swagger Editor: https://editor.swagger.io/
- Загрузите файл `advanced-dashboard.yaml`

### Генерация клиента

**TypeScript/JavaScript:**
```bash
npm install -g @openapitools/openapi-generator-cli

openapi-generator-cli generate \
  -i docs/openapi/advanced-dashboard.yaml \
  -g typescript-axios \
  -o ./generated/api
```

**PHP:**
```bash
openapi-generator-cli generate \
  -i docs/openapi/advanced-dashboard.yaml \
  -g php \
  -o ./generated/php-client
```

## 📊 Статистика API

| Категория | Endpoints |
|-----------|-----------|
| Dashboards | 14 |
| Financial Analytics | 5 |
| Predictive Analytics | 3 |
| HR & KPI | 3 |
| Alerts | 9 |
| Export | 8 |
| **ИТОГО** | **42** |

## 🔐 Аутентификация

Все endpoints требуют:

**1. JWT токен:**
```http
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGc...
```

**2. Organization Context:**
```http
X-Organization-ID: 123
```

**3. Активация модуля:**
- Модуль должен быть активирован для организации
- Middleware: `advanced_dashboard.active`

## 📝 Примеры запросов

### Создать дашборд
```bash
curl -X POST https://api.prohelper.ru/api/v1/admin/advanced-dashboard/dashboards \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Organization-ID: 123" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "My Financial Dashboard",
    "description": "Dashboard for financial analytics",
    "visibility": "private"
  }'
```

### Получить Cash Flow
```bash
curl -X GET "https://api.prohelper.ru/api/v1/admin/advanced-dashboard/analytics/financial/cash-flow?from=2025-01-01&to=2025-10-04" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Organization-ID: 123"
```

### Создать алерт
```bash
curl -X POST https://api.prohelper.ru/api/v1/admin/advanced-dashboard/alerts \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Organization-ID: 123" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Budget Alert",
    "alert_type": "budget_overrun",
    "target_entity": "project",
    "target_entity_id": 5,
    "comparison_operator": "gt",
    "threshold_value": 80,
    "notification_channels": ["email"],
    "priority": "high"
  }'
```

## 🎯 Категории API

### 1. Dashboards (14 endpoints)
Управление дашбордами:
- CRUD операции
- Шаблоны (admin, finance, technical, hr)
- Расшаривание (team, organization)
- Клонирование
- Layout/виджеты/фильтры

### 2. Financial Analytics (5 endpoints)
Финансовая аналитика:
- Cash Flow (приток/отток)
- P&L (прибыль/убытки)
- ROI (рентабельность)
- Revenue Forecast (прогноз доходов)
- Receivables/Payables (дебиторка/кредиторка)

### 3. Predictive Analytics (3 endpoints)
Предиктивная аналитика:
- Contract Forecast (прогноз завершения)
- Budget Risk (риск превышения бюджета)
- Material Needs (прогноз потребности)

### 4. HR & KPI (3 endpoints)
HR аналитика:
- User KPI (6 метрик)
- Top Performers (рейтинг)
- Resource Utilization (загрузка)

### 5. Alerts (9 endpoints)
Система алертов:
- CRUD операции
- 7 типов алертов
- Включение/выключение
- История срабатываний
- Проверка всех алертов

### 6. Export (8 endpoints)
Экспорт и отчеты:
- PDF/Excel экспорт
- Scheduled reports (CRUD)
- Доступные форматы

## 🔍 Фильтрация и параметры

### Временные фильтры
```
from=2025-01-01&to=2025-10-04
```

### Entity фильтры
```
project_id=5
contract_id=10
user_id=15
```

### Группировка
```
groupBy=day|week|month
```

### Лимиты
```
limit=50
months=6
```

## ⚠️ Коды ошибок

| Код | Описание |
|-----|----------|
| 200 | OK |
| 201 | Created |
| 400 | Bad Request (валидация) |
| 401 | Unauthorized (нет токена) |
| 403 | Forbidden (модуль не активен / нет прав) |
| 404 | Not Found |
| 429 | Too Many Requests (rate limit) |
| 500 | Internal Server Error |

## 📦 Схемы данных

### Dashboard
```json
{
  "id": 1,
  "user_id": 10,
  "organization_id": 5,
  "name": "My Dashboard",
  "slug": "my-dashboard",
  "layout": {},
  "widgets": [],
  "is_shared": false,
  "visibility": "private",
  "is_default": true,
  "created_at": "2025-10-04T12:00:00Z"
}
```

### Alert
```json
{
  "id": 1,
  "name": "Budget Alert",
  "alert_type": "budget_overrun",
  "target_entity": "project",
  "target_entity_id": 5,
  "comparison_operator": "gt",
  "threshold_value": 80,
  "priority": "high",
  "is_active": true
}
```

## 🎨 Swagger UI скриншоты

После запуска Swagger UI вы увидите:
- Интерактивную документацию
- Try it out функцию
- Схемы запросов/ответов
- Примеры для всех endpoints

## 🔗 Связанные ресурсы

- **Основная документация:** `@docs/ADVANCED_DASHBOARD_MVP_READY.md`
- **Спецификация:** `@docs/specs/dashboard-improvements-spec.md`
- **План:** `@docs/plans/dashboard-improvements-plan.md`
- **Module README:** `app/BusinessModules/Features/AdvancedDashboard/README.md`

## 📞 Поддержка

Email: support@prohelper.ru  
Docs: https://docs.prohelper.ru

---

**Версия:** 1.0.0  
**Дата:** 4 октября 2025  
**Статус:** Production Ready ✅

