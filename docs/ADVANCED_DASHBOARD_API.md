# Advanced Dashboard API Documentation

## Оглавление

- [Общая информация](#общая-информация)
- [Аутентификация](#аутентификация)
- [Endpoints для виджетов](#endpoints-для-виджетов)
- [Категории виджетов](#категории-виджетов)
- [Типы виджетов](#типы-виджетов)
- [Рекомендации по интеграции](#рекомендации-по-интеграции)

---

## Общая информация

Base URL: `/api/v1/admin/advanced-dashboard`

Все endpoints требуют авторизации через JWT токен и активацию модуля Advanced Dashboard.

**Статус реализации:** ✅ Все 52 виджета в 8 категориях полностью реализованы и готовы к использованию.

**Важная особенность для SaaS:** Все виджеты (включая Performance) показывают данные **только вашей организации**. Данные других организаций изолированы и недоступны.

**Headers:**
```
Authorization: Bearer {jwt_token}
Content-Type: application/json
Accept: application/json
```

**Общий формат ответа:**
```json
{
  "success": true|false,
  "data": {},
  "message": "Error message (опционально)"
}
```

---

## Endpoints для виджетов

### 1. Получить данные виджета

**GET** `/widgets/{type}/data`

Получить данные конкретного виджета.

**Path Parameters:**
- `type` (string, required) - тип виджета (см. [Типы виджетов](#типы-виджетов))

**Query Parameters:**
- `from` (date, optional) - начальная дата периода (YYYY-MM-DD)
- `to` (date, optional) - конечная дата периода (YYYY-MM-DD)
- `project_id` (integer, optional) - ID проекта для фильтрации
- `contract_id` (integer, optional) - ID контракта для фильтрации
- `filters[key]` (mixed, optional) - дополнительные фильтры
- `options[key]` (mixed, optional) - опции виджета

**Пример запроса:**
```http
GET /api/v1/admin/advanced-dashboard/widgets/cash_flow/data?from=2024-01-01&to=2024-12-31&project_id=5
```

**Пример ответа:**
```json
{
  "widget_type": "cash_flow",
  "data": {
    "period": {
      "from": "2024-01-01T00:00:00Z",
      "to": "2024-12-31T23:59:59Z"
    },
    "total_inflow": 1500000.00,
    "total_outflow": 980000.00,
    "net_cash_flow": 520000.00,
    "monthly_breakdown": [
      {
        "month": "2024-01",
        "inflow": 120000,
        "outflow": 85000,
        "net_flow": 35000
      }
    ],
    "inflow_by_category": [
      {
        "category": "Контракты",
        "amount": 1200000,
        "percentage": 80
      }
    ]
  },
  "generated_at": "2024-10-08T12:00:00Z",
  "metadata": {
    "id": "cash_flow",
    "name": "Движение денежных средств",
    "category": "financial"
  },
  "cached": false,
  "success": true,
  "message": null
}
```

---

### 2. Получить данные нескольких виджетов (batch)

**POST** `/widgets/batch`

Получить данные нескольких виджетов за один запрос.

**Request Body:**
```json
{
  "widgets": [
    {
      "type": "cash_flow",
      "from": "2024-01-01",
      "to": "2024-12-31",
      "project_id": 5,
      "filters": {},
      "options": {}
    },
    {
      "type": "profit_loss",
      "from": "2024-01-01",
      "to": "2024-12-31"
    }
  ]
}
```

**Пример ответа:**
```json
{
  "success": true,
  "results": [
    {
      "widget_type": "cash_flow",
      "data": { ... },
      "success": true
    },
    {
      "widget_type": "profit_loss",
      "data": { ... },
      "success": true
    }
  ]
}
```

---

### 3. Получить метаданные виджета

**GET** `/widgets/{type}/metadata`

Получить информацию о структуре данных виджета, параметрах и описании.

**Path Parameters:**
- `type` (string, required) - тип виджета

**Пример ответа:**
```json
{
  "success": true,
  "metadata": {
    "id": "cash_flow",
    "name": "Движение денежных средств",
    "description": "Анализ притока и оттока денежных средств",
    "category": "financial",
    "icon": "dollar-sign",
    "default_size": { "w": 6, "h": 3 },
    "min_size": { "w": 4, "h": 2 },
    "is_ready": true,
    "params": [
      {
        "name": "from",
        "type": "date",
        "required": true,
        "description": "Начальная дата периода"
      },
      {
        "name": "to",
        "type": "date",
        "required": true,
        "description": "Конечная дата периода"
      }
    ],
    "response_structure": {
      "total_inflow": "number",
      "total_outflow": "number",
      "net_cash_flow": "number",
      "monthly_breakdown": "array"
    }
  }
}
```

---

### 4. Получить реестр виджетов

**GET** `/widgets/registry`

Получить список всех доступных виджетов и категорий.

**Пример ответа:**
```json
{
  "success": true,
  "data": {
    "widgets": [
      {
        "id": "cash_flow",
        "name": "Движение денежных средств",
        "category": "financial",
        "is_ready": true
      },
      ...
    ],
    "categories": [
      {
        "id": "financial",
        "name": "Финансовая аналитика",
        "description": "Виджеты для финансового анализа",
        "icon": "dollar-sign",
        "widgets_count": 7
      },
      ...
    ],
    "stats": {
      "total_widgets": 52,
      "ready_widgets": 52,
      "categories_count": 8
    }
  }
}
```

---

### 5. Получить список категорий

**GET** `/widgets/categories`

Получить список всех категорий виджетов.

**Пример ответа:**
```json
{
  "success": true,
  "categories": [
    {
      "id": "financial",
      "name": "Финансовая аналитика",
      "description": "Виджеты для финансового анализа и прогнозирования",
      "icon": "dollar-sign",
      "widgets_count": 7
    },
    ...
  ]
}
```

---

## Категории виджетов

### 1. Financial (Финансовая аналитика)
**ID:** `financial`  
**Цвет:** `#10B981`  
**Иконка:** `dollar-sign`

Виджеты для финансового анализа, cash flow, P&L, ROI.

### 2. Projects (Проекты)
**ID:** `projects`  
**Цвет:** `#3B82F6`  
**Иконка:** `folder`

Аналитика по проектам, статусы, бюджеты, риски.

### 3. Contracts (Контракты)
**ID:** `contracts`  
**Цвет:** `#8B5CF6`  
**Иконка:** `file-text`

Управление и анализ контрактов, платежи, исполнение.

### 4. Materials (Материалы)
**ID:** `materials`  
**Цвет:** `#F59E0B`  
**Иконка:** `package`

Учет материалов, остатки, прогнозирование потребности.

### 5. HR (HR и KPI)
**ID:** `hr`  
**Цвет:** `#EF4444`  
**Иконка:** `users`

Аналитика персонала, KPI, загрузка ресурсов.

### 6. Predictive (Предиктивная аналитика)
**ID:** `predictive`  
**Цвет:** `#6366F1`  
**Иконка:** `trending-up`

Прогнозы и предсказания на основе данных.

### 7. Activity (Активность)
**ID:** `activity`  
**Цвет:** `#14B8A6`  
**Иконка:** `activity`

История действий и событий в системе.

### 8. Performance (Производительность)
**ID:** `performance`  
**Цвет:** `#EC4899`  
**Иконка:** `zap`

Метрики использования платформы вашей организацией (не глобальные метрики системы).

---

## Типы виджетов

### Financial (7 виджетов)

#### 1. cash_flow
Движение денежных средств
- Параметры: `from`, `to`, `project_id` (optional)
- Возвращает: притоки, оттоки, net cash flow, разбивка по месяцам

#### 2. profit_loss
Прибыль и убытки (P&L)
- Параметры: `from`, `to`, `project_id` (optional)
- Возвращает: выручка, себестоимость, валовая прибыль, операционная прибыль

#### 3. roi
Рентабельность инвестиций
- Параметры: `project_id` (optional), `from`, `to`
- Возвращает: ROI по проектам, топ-5 лучших/худших

#### 4. revenue_forecast
Прогноз доходов
- Параметры: `months` (default: 6)
- Возвращает: прогноз на N месяцев на основе контрактов и трендов

#### 5. receivables_payables
Дебиторская/Кредиторская задолженность
- Параметры: нет (текущее состояние)
- Возвращает: дебиторка, кредиторка с разбивкой по срокам

#### 6. expense_breakdown
Разбивка расходов
- Параметры: `from`, `to`, `project_id` (optional)
- Возвращает: расходы по категориям (материалы, зарплаты, подрядчики)

#### 7. financial_health
Финансовое здоровье
- Параметры: `period_months` (default: 12)
- Возвращает: тренды выручки, маржи, cash flow, рекомендации

### Projects (7 виджетов)

#### 8. projects_overview
Обзор проектов
- Возвращает: количество, статусы, общий бюджет

#### 9. projects_status
Статусы проектов
- Возвращает: разбивка по статусам с количеством

#### 10. projects_timeline
График проектов
- Возвращает: проекты на временной шкале

#### 11. projects_budget
Бюджеты проектов
- Возвращает: бюджеты vs факт по проектам

#### 12. projects_completion
Прогресс выполнения
- Возвращает: % выполнения по проектам

#### 13. projects_risks
Риски проектов
- Возвращает: проекты с рисками (бюджет, сроки)

#### 14. projects_map
Проекты на карте
- Возвращает: географическое расположение проектов

### Contracts (7 виджетов)

#### 15. contracts_overview
Обзор контрактов
- Возвращает: общая статистика по контрактам

#### 16. contracts_status
Статусы контрактов
- Возвращает: разбивка по статусам

#### 17. contracts_payments
Платежи по контрактам
- Возвращает: история и планируемые платежи

#### 18. contracts_performance
Исполнение контрактов
- Возвращает: % исполнения, задержки

#### 19. contracts_upcoming
Предстоящие контракты
- Возвращает: контракты с близкими дедлайнами

#### 20. contracts_completion_forecast
Прогноз завершения
- Возвращает: прогноз дат завершения

#### 21. contracts_by_contractor
По подрядчикам
- Возвращает: статистика по подрядчикам

### Materials (7 виджетов)

#### 22. materials_inventory
Остатки материалов
- Возвращает: текущие остатки по складам

#### 23. materials_consumption
Расход материалов
- Параметры: `from`, `to`
- Возвращает: расход за период

#### 24. materials_forecast
Прогноз потребности
- Параметры: `months` (default: 3)
- Возвращает: прогноз потребности

#### 25. materials_low_stock
Низкие остатки
- Параметры: `threshold` (default: 10)
- Возвращает: материалы с низкими остатками

#### 26. materials_top_used
Топ материалов
- Параметры: `from`, `to`
- Возвращает: наиболее используемые материалы

#### 27. materials_by_project
По проектам
- Параметры: `from`, `to`
- Возвращает: расход материалов по проектам

#### 28. materials_suppliers
Поставщики
- Параметры: `from`, `to`
- Возвращает: статистика по поставщикам

### HR (7 виджетов)

#### 29. employee_kpi
KPI сотрудников
- Параметры: `from`, `to`, `employee_id` (optional)
- Возвращает: KPI метрики сотрудника

#### 30. top_performers
Топ исполнители
- Параметры: `from`, `to`, `limit` (default: 10)
- Возвращает: рейтинг сотрудников

#### 31. resource_utilization
Загрузка ресурсов
- Параметры: `from`, `to`
- Возвращает: загрузка по сотрудникам

#### 32. employee_workload
Нагрузка сотрудников
- Возвращает: текущая нагрузка

#### 33. employee_attendance
Посещаемость
- Параметры: `from`, `to`
- Возвращает: статистика посещаемости

#### 34. employee_efficiency
Эффективность
- Параметры: `from`, `to`
- Возвращает: метрики эффективности

#### 35. team_performance
Производительность команды
- Параметры: `from`, `to`
- Возвращает: общие метрики команды

### Predictive (7 виджетов)

#### 36. budget_risk
Риски бюджета
- Параметры: `threshold` (default: 80 - процент использования бюджета)
- Возвращает: проекты с рисками превышения бюджета

#### 37. deadline_risk
Риски сроков
- Параметры: `days_threshold` (default: 7 - дней до дедлайна)
- Возвращает: контракты с рисками срыва сроков

#### 38. resource_demand
Прогноз потребности в ресурсах
- Параметры: `months` (default: 3)
- Возвращает: прогноз потребности в сотрудниках на N месяцев

#### 39. cash_flow_forecast
Прогноз денежных потоков
- Параметры: `months` (default: 6)
- Возвращает: прогноз cash flow на основе трендов и контрактов

#### 40. project_completion
Прогноз завершения проектов
- Возвращает: прогнозируемые даты завершения на основе скорости выполнения

#### 41. cost_overrun
Анализ превышения затрат
- Возвращает: проекты с превышением бюджета и прогноз переб��асхода

#### 42. trend_analysis
Трендовый анализ
- Параметры: `months` (default: 12)
- Возвращает: тренды выручки, проектов, производительности

### Activity (5 виджетов)

#### 43. recent_activity
Недавняя активность
- Параметры: `limit` (default: 50)
- Возвращает: последние действия в системе вашей организации

#### 44. system_events
События системы
- Параметры: `from`, `to`, `limit` (default: 100)
- Возвращает: важные события (создание контрактов, проектов, работ)

#### 45. user_actions
Действия пользователей
- Параметры: `from`, `to`, `limit` (default: 100)
- Возвращает: история действий пользователей вашей организации

#### 46. notifications
Уведомления
- Параметры: `limit` (default: 20)
- Возвращает: уведомления пользователей организации

#### 47. audit_log
Журнал аудита
- Параметры: `from`, `to`, `limit` (default: 50)
- Возвращает: детальный журнал изменений в системе

### Performance (5 виджетов)

> **Важно:** Все Performance виджеты показывают данные **вашей организации**, а не глобальные метрики системы.

#### 48. system_metrics
Использование платформы
- Возвращает: объем данных организации, активные пользователи, модули, возраст аккаунта

#### 49. api_performance
Активность организации
- Возвращает: метрики активности вашей организации за последние 24 часа и 7 дней

#### 50. database_stats
Объем данных
- Возвращает: количество записей организации по сущностям, рост за 30 дней

#### 51. cache_stats
Статистика кеширования
- Возвращает: количество закешированных ключей вашей организации, эффективность кеша

#### 52. response_times
Производительность запросов
- Возвращает: скорость загрузки данных вашей организации, рекомендации по оптимизации

**Пример ответа виджета cache_stats:**
```json
{
  "cache_stats": {
    "driver": "Redis",
    "organization_id": 123,
    "total_keys": 45,
    "keys_by_category": {
      "widget": 20,
      "dashboard": 10,
      "data": 15
    },
    "cache_enabled": true
  }
}
```

**Пример ответа виджета database_stats:**
```json
{
  "database_stats": {
    "organization_id": 123,
    "records_by_entity": {
      "projects": {"count": 150, "active": 45},
      "contracts": {"count": 320, "active": 180},
      "completed_works": {"count": 5420, "last_30_days": 450}
    },
    "total_records": 5890,
    "growth_30_days": {
      "new_projects": 12,
      "new_contracts": 28,
      "new_works": 450
    }
  }
}
```

**Пример ответа виджета response_times:**
```json
{
  "query_performance": {
    "organization_id": 123,
    "measurements_ms": {
      "projects_load": 45.2,
      "works_with_projects": 120.5,
      "financial_aggregation": 85.3,
      "grouped_analytics": 156.8
    },
    "average_ms": 101.95,
    "max_ms": 156.8,
    "status": "good"
  },
  "analysis": {
    "overall_status": "good",
    "slowest_query": "grouped_analytics",
    "recommendations": [
      "Производительность запросов в норме."
    ]
  }
}
```

---

## Важные особенности SaaS

### Изоляция данных организаций

**Каждый виджет работает только с данными вашей организации:**

- ✅ Financial виджеты - только контракты и проекты вашей организации
- ✅ HR виджеты - только сотрудники вашей организации
- ✅ Performance виджеты - только метрики использования вашей организации
- ✅ Cache Stats - только закешированные ключи вашей организации
- ✅ Activity виджеты - только события вашей организации

**Как это работает:**

Все запросы к API автоматически фильтруются по `organization_id` из JWT токена пользователя. Вам **не нужно** передавать `organization_id` в параметрах - он определяется автоматически из авторизационного токена.

**Безопасность:**

- Невозможно получить данные другой организации
- Все SQL запросы содержат фильтр `WHERE organization_id = ?`
- Кеш изолирован по организациям через tagged cache

---

## Рекомендации по интеграции

### 1. Кеширование

Все виджеты кешируются на **5 минут** (300 секунд) по умолчанию.

**Рекомендации:**
- Используйте поле `cached` в ответе для индикации источника данных
- Для real-time данных можно добавить параметр `no_cache=1` (будет реализовано)
- Инвалидация кеша происходит автоматически при изменении данных

### 2. Batch запросы

Для загрузки дашборда с несколькими виджетами **всегда используйте batch endpoint**.

**Преимущества:**
- Один HTTP запрос вместо N
- Параллельная обработка на backend
- Меньше нагрузки на сеть

**Пример:**
```javascript
// Вместо 3 запросов:
fetch('/widgets/cash_flow/data')
fetch('/widgets/profit_loss/data')
fetch('/widgets/roi/data')

// Делайте 1 batch запрос:
fetch('/widgets/batch', {
  method: 'POST',
  body: JSON.stringify({
    widgets: [
      { type: 'cash_flow', from: '2024-01-01', to: '2024-12-31' },
      { type: 'profit_loss', from: '2024-01-01', to: '2024-12-31' },
      { type: 'roi', from: '2024-01-01', to: '2024-12-31' }
    ]
  })
})
```

### 3. Обработка ошибок

Всегда проверяйте поле `success` в ответе.

**Типы ошибок:**
- `400` - Неверные параметры
- `404` - Виджет не найден
- `500` - Ошибка сервера

**Пример обработки:**
```javascript
const response = await fetch('/widgets/cash_flow/data?from=2024-01-01&to=2024-12-31');
const data = await response.json();

if (!data.success) {
  console.error('Widget error:', data.message);
  // Показать заглушку или сообщение об ошибке
}
```

### 4. Динамические параметры

Некоторые виджеты поддерживают дополнительные параметры через `filters` и `options`.

**Пример:**
```javascript
{
  type: 'materials_low_stock',
  options: {
    threshold: 5  // Вместо дефолтных 10
  }
}
```

### 5. Оптимизация UI

**Рекомендации:**
- Показывайте скелетоны во время загрузки
- Используйте поле `cached` для индикации свежести данных
- Для виджетов с `generated_at` показывайте время последнего обновления
- Реализуйте retry logic для failed запросов

### 6. Периоды данных

Многие виджеты принимают `from` и `to`.

**По умолчанию:**
- Если не указаны - используется текущий месяц
- Формат даты: `YYYY-MM-DD`
- Timezone: UTC (конвертируйте на клиенте)

**Рекомендуемые периоды:**
- Краткосрочная аналитика: последний месяц
- Среднесрочная: последний квартал (3 месяца)
- Долгосрочная: последний год

### 7. Responsive виджеты

Каждый виджет имеет `default_size` и `min_size`.

**Используйте для:**
- Grid layout (React Grid Layout, vue-grid-layout)
- Минимальные размеры для корректного отображения
- Адаптивный дизайн

**Пример размеров:**
```json
{
  "default_size": { "w": 6, "h": 3 },  // ширина 6 колонок, высота 3 строки
  "min_size": { "w": 4, "h": 2 }       // минимум 4x2
}
```

---

## Примеры интеграции

### React Example
```jsx
import { useState, useEffect } from 'react';

function CashFlowWidget({ from, to, projectId }) {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchData = async () => {
      const params = new URLSearchParams({
        from,
        to,
        ...(projectId && { project_id: projectId })
      });
      
      const response = await fetch(
        `/api/v1/admin/advanced-dashboard/widgets/cash_flow/data?${params}`,
        {
          headers: {
            'Authorization': `Bearer ${getToken()}`,
            'Accept': 'application/json'
          }
        }
      );
      
      const result = await response.json();
      
      if (result.success) {
        setData(result.data);
      }
      
      setLoading(false);
    };
    
    fetchData();
  }, [from, to, projectId]);

  if (loading) return <WidgetSkeleton />;
  if (!data) return <WidgetError />;

  return (
    <div className="widget">
      <h3>Cash Flow</h3>
      <div>Net: {data.net_cash_flow}</div>
      {/* Render chart */}
    </div>
  );
}
```

### Vue Example
```vue
<template>
  <div class="widget" v-if="!loading">
    <h3>{{ metadata.name }}</h3>
    <chart :data="widgetData" v-if="widgetData" />
  </div>
</template>

<script>
export default {
  data() {
    return {
      widgetData: null,
      metadata: null,
      loading: true
    }
  },
  async mounted() {
    const response = await this.$http.get(
      `/advanced-dashboard/widgets/cash_flow/data`,
      { params: { from: '2024-01-01', to: '2024-12-31' } }
    );
    
    this.widgetData = response.data.data;
    this.metadata = response.data.metadata;
    this.loading = false;
  }
}
</script>
```

---

## Changelog

**v1.0.0** (2024-10-08)
- ✅ Полная реализация всех 52 виджетов в 8 категориях
- ✅ Все виджеты показывают данные конкретной организации (SaaS-ready)
- ✅ Performance виджеты переработаны для отображения метрик организации
- ✅ Predictive виджеты: полная реализация прогнозирования на основе ML
- ✅ Activity виджеты: полная история действий и события организации
- ✅ Batch endpoints для эффективной загрузки множества виджетов
- ✅ Кеширование с tagged cache для каждой организации
- ✅ Полная API документация с примерами

---

## Поддержка

При возникновении проблем обращайтесь к backend команде или создавайте issue в репозитории.

