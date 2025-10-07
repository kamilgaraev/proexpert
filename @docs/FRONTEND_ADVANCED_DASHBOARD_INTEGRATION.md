# Интеграция виджетов Advanced Dashboard - Руководство для Frontend

**Дата:** 7 октября 2025  
**Версия API:** v1  
**Базовый URL:** `/api/v1/admin/advanced-dashboard`

---

## 📋 Оглавление

1. [Обзор](#обзор)
2. [Аутентификация](#аутентификация)
3. [Финансовые виджеты](#финансовые-виджеты)
4. [Предиктивные виджеты](#предиктивные-виджеты)
5. [HR & KPI виджеты](#hr--kpi-виджеты)
6. [Экспорт дашбордов](#экспорт-дашбордов)
7. [Управление алертами](#управление-алертами)
8. [Типы данных](#типы-данных)
9. [Обработка ошибок](#обработка-ошибок)

---

## Обзор

Advanced Dashboard предоставляет **11 новых виджетов** с аналитикой и возможностью экспорта в PDF/Excel.

### Новые возможности:
- ✅ 5 виджетов финансовой аналитики
- ✅ 3 виджета предиктивной аналитики
- ✅ 3 виджета HR/KPI аналитики
- ✅ Экспорт в PDF (через DomPDF)
- ✅ Экспорт в Excel (через PhpSpreadsheet)
- ✅ Система алертов с историей
- ✅ Кастомные метрики для алертов

---

## Аутентификация

Все запросы требуют:
```javascript
headers: {
  'Authorization': 'Bearer {token}',
  'X-Organization-ID': '{organizationId}',
  'Content-Type': 'application/json',
  'Accept': 'application/json'
}
```

---

## Финансовые виджеты

### 1. Cash Flow Widget (Движение денежных средств)

**Endpoint:** `GET /analytics/financial/cash-flow`

**Параметры:**
```typescript
interface CashFlowParams {
  from: string;        // YYYY-MM-DD
  to: string;          // YYYY-MM-DD
  project_id?: number; // опционально
}
```

**Пример запроса:**
```javascript
const response = await fetch('/api/v1/admin/advanced-dashboard/analytics/financial/cash-flow?from=2025-01-01&to=2025-10-07', {
  headers: {
    'Authorization': `Bearer ${token}`,
    'X-Organization-ID': organizationId
  }
});

const data = await response.json();
```

**Формат ответа:**
```typescript
interface CashFlowResponse {
  success: true;
  data: {
    period: {
      from: string;  // ISO 8601
      to: string;    // ISO 8601
    };
    total_inflow: number;
    total_outflow: number;
    net_cash_flow: number;
    monthly_breakdown: Array<{
      month: string;           // "2025-01"
      month_name: string;      // "Январь 2025"
      inflow: number;
      outflow: number;
      net: number;
    }>;
    inflow_by_category: Array<{
      category: string;        // "Контракты", "Авансовые платежи", "Оплата за работы"
      amount: number;
      percentage: number;
    }>;
    outflow_by_category: Array<{
      category: string;        // "Материалы", "Зарплаты", "Подрядчики"
      amount: number;
      percentage: number;
    }>;
  };
}
```

**Пример отображения:**
```jsx
function CashFlowWidget({ data }) {
  return (
    <div className="widget-card">
      <h3>Движение денежных средств</h3>
      
      <div className="summary">
        <div className="metric">
          <label>Приток</label>
          <span className="positive">{formatMoney(data.total_inflow)}</span>
        </div>
        <div className="metric">
          <label>Отток</label>
          <span className="negative">{formatMoney(data.total_outflow)}</span>
        </div>
        <div className="metric">
          <label>Чистый поток</label>
          <span className={data.net_cash_flow > 0 ? 'positive' : 'negative'}>
            {formatMoney(data.net_cash_flow)}
          </span>
        </div>
      </div>

      {/* График по месяцам */}
      <BarChart data={data.monthly_breakdown} />

      {/* Pie chart по категориям */}
      <div className="categories">
        <div>
          <h4>Приток по категориям</h4>
          <PieChart data={data.inflow_by_category} />
        </div>
        <div>
          <h4>Отток по категориям</h4>
          <PieChart data={data.outflow_by_category} />
        </div>
      </div>
    </div>
  );
}
```

---

### 2. Profit & Loss Widget (Прибыли и убытки)

**Endpoint:** `GET /analytics/financial/profit-loss`

**Параметры:** Те же что у Cash Flow

**Формат ответа:**
```typescript
interface ProfitLossResponse {
  success: true;
  data: {
    period: { from: string; to: string };
    revenue: number;                    // Выручка
    cost_of_goods_sold: number;         // Себестоимость
    gross_profit: number;               // Валовая прибыль
    gross_profit_margin: number;        // Маржа валовой прибыли (%)
    operating_expenses: number;         // Операционные расходы
    operating_profit: number;           // Операционная прибыль
    operating_profit_margin: number;    // Маржа операционной прибыли (%)
    net_profit: number;                 // Чистая прибыль
    net_profit_margin: number;          // Маржа чистой прибыли (%)
    by_project: Array<{
      project_id: number;
      project_name: string;
      revenue: number;
      cogs: number;
      profit: number;
      margin: number;                   // %
    }>;
  };
}
```

**Пример отображения:**
```jsx
function ProfitLossWidget({ data }) {
  return (
    <div className="widget-card">
      <h3>Прибыли и убытки</h3>
      
      {/* Waterfall chart */}
      <div className="waterfall">
        <div className="step revenue">
          <label>Выручка</label>
          <span>{formatMoney(data.revenue)}</span>
        </div>
        <div className="step negative">
          <label>Себестоимость</label>
          <span>-{formatMoney(data.cost_of_goods_sold)}</span>
        </div>
        <div className="step positive">
          <label>Валовая прибыль</label>
          <span>{formatMoney(data.gross_profit)}</span>
          <small>{data.gross_profit_margin}%</small>
        </div>
        <div className="step negative">
          <label>Операционные расходы</label>
          <span>-{formatMoney(data.operating_expenses)}</span>
        </div>
        <div className="step result">
          <label>Чистая прибыль</label>
          <span>{formatMoney(data.net_profit)}</span>
          <small>{data.net_profit_margin}%</small>
        </div>
      </div>

      {/* Таблица по проектам */}
      <table className="projects-table">
        <thead>
          <tr>
            <th>Проект</th>
            <th>Выручка</th>
            <th>Прибыль</th>
            <th>Маржа</th>
          </tr>
        </thead>
        <tbody>
          {data.by_project.map(project => (
            <tr key={project.project_id}>
              <td>{project.project_name}</td>
              <td>{formatMoney(project.revenue)}</td>
              <td className={project.profit > 0 ? 'positive' : 'negative'}>
                {formatMoney(project.profit)}
              </td>
              <td>{project.margin}%</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
```

---

### 3. ROI Widget (Рентабельность инвестиций)

**Endpoint:** `GET /analytics/financial/roi`

**Параметры:**
```typescript
interface ROIParams {
  project_id?: number;  // если не указан - по всем проектам
  from?: string;        // YYYY-MM-DD
  to?: string;          // YYYY-MM-DD
}
```

**Формат ответа:**
```typescript
interface ROIResponse {
  success: true;
  data: {
    period: { from: string; to: string };
    total_investment: number;
    total_profit: number;
    total_roi_percentage: number;
    projects_count: number;
    projects: Array<{
      project_id: number;
      project_name: string;
      investment: number;
      revenue: number;
      profit: number;
      roi_percentage: number;
    }>;
    top_performers: Array<{...}>;      // Топ-5 проектов
    worst_performers: Array<{...}>;    // Худшие 5 проектов
  };
}
```

**Пример отображения:**
```jsx
function ROIWidget({ data }) {
  return (
    <div className="widget-card">
      <h3>ROI</h3>
      
      <div className="summary-card">
        <div className="roi-total">
          <span className="label">Общий ROI</span>
          <span className={`value ${data.total_roi_percentage > 0 ? 'positive' : 'negative'}`}>
            {data.total_roi_percentage}%
          </span>
        </div>
        <div className="details">
          <div>Инвестиции: {formatMoney(data.total_investment)}</div>
          <div>Прибыль: {formatMoney(data.total_profit)}</div>
        </div>
      </div>

      {/* Топ проекты */}
      <div className="top-projects">
        <h4>Лучшие проекты</h4>
        {data.top_performers.map(project => (
          <div key={project.project_id} className="project-item">
            <span>{project.project_name}</span>
            <span className="roi positive">{project.roi_percentage}%</span>
          </div>
        ))}
      </div>

      {/* Scatter plot */}
      <ScatterChart 
        data={data.projects}
        xAxis="investment"
        yAxis="roi_percentage"
      />
    </div>
  );
}
```

---

### 4. Revenue Forecast Widget (Прогноз доходов)

**Endpoint:** `GET /analytics/financial/revenue-forecast`

**Параметры:**
```typescript
interface ForecastParams {
  months?: number;  // Количество месяцев для прогноза (по умолчанию 6)
}
```

**Формат ответа:**
```typescript
interface RevenueForecastResponse {
  success: true;
  data: {
    forecast_months: number;
    forecast_from: string;
    historical_data: Array<{
      month: string;
      amount: number;
    }>;
    contract_based_forecast: Array<{
      month: string;
      amount: number;
      contracts_count: number;
    }>;
    trend_forecast: Array<{
      month: string;
      amount: number;
    }>;
    combined_forecast: Array<{
      month: string;
      amount: number;
    }>;
    total_forecasted_revenue: number;
    confidence_level: number;  // 0.0 - 1.0
  };
}
```

**Пример отображения:**
```jsx
function RevenueForecastWidget({ data }) {
  const chartData = [
    ...data.historical_data.map(d => ({ ...d, type: 'historical' })),
    ...data.combined_forecast.map(d => ({ ...d, type: 'forecast' }))
  ];

  return (
    <div className="widget-card">
      <h3>Прогноз доходов</h3>
      
      <div className="forecast-summary">
        <div className="metric">
          <label>Прогноз на {data.forecast_months} мес.</label>
          <span>{formatMoney(data.total_forecasted_revenue)}</span>
        </div>
        <div className="confidence">
          <label>Уровень доверия</label>
          <ProgressBar value={data.confidence_level * 100} />
          <span>{(data.confidence_level * 100).toFixed(0)}%</span>
        </div>
      </div>

      {/* Line chart с историей и прогнозом */}
      <LineChart 
        data={chartData}
        historicalColor="#007bff"
        forecastColor="#28a745"
        forecastStyle="dashed"
      />

      {/* Легенда */}
      <div className="legend">
        <span className="historical">Исторические данные</span>
        <span className="forecast">Прогноз (70% контракты + 30% тренд)</span>
      </div>
    </div>
  );
}
```

---

### 5. Receivables/Payables Widget (Дебиторка/Кредиторка)

**Endpoint:** `GET /analytics/financial/receivables-payables`

**Формат ответа:**
```typescript
interface ReceivablesPayablesResponse {
  success: true;
  data: {
    as_of_date: string;
    receivables: {
      total: number;
      current: number;           // 0-30 дней
      overdue_30: number;        // 30-60 дней
      overdue_60: number;        // 60-90 дней
      overdue_90_plus: number;   // 90+ дней
      by_contract: Array<{
        contract_id: number;
        contract_name: string;
        amount: number;
        due_date: string;
        days_overdue: number;
        status: 'current' | 'overdue_30' | 'overdue_60' | 'overdue_90_plus';
      }>;
    };
    payables: {
      total: number;
      current: number;
      overdue_30: number;
      overdue_60: number;
      overdue_90_plus: number;
      by_supplier: Array<{
        supplier: string;
        total_amount: number;
        items: Array<{
          material_id: number;
          material_name: string;
          amount: number;
          due_date: string;
          days_overdue: number;
          status: string;
        }>;
      }>;
    };
    net_position: number;  // receivables - payables
  };
}
```

**Пример отображения:**
```jsx
function ReceivablesPayablesWidget({ data }) {
  return (
    <div className="widget-card">
      <h3>Дебиторская и кредиторская задолженность</h3>
      
      <div className="net-position">
        <span>Чистая позиция:</span>
        <span className={data.net_position > 0 ? 'positive' : 'negative'}>
          {formatMoney(data.net_position)}
        </span>
      </div>

      <div className="two-columns">
        {/* Дебиторка */}
        <div className="receivables">
          <h4>Дебиторская задолженность</h4>
          <div className="total">{formatMoney(data.receivables.total)}</div>
          
          <div className="aging">
            <div className="current">
              <label>Текущая (0-30 дней)</label>
              <span>{formatMoney(data.receivables.current)}</span>
            </div>
            <div className="overdue-30">
              <label>30-60 дней</label>
              <span>{formatMoney(data.receivables.overdue_30)}</span>
            </div>
            <div className="overdue-60">
              <label>60-90 дней</label>
              <span>{formatMoney(data.receivables.overdue_60)}</span>
            </div>
            <div className="overdue-90">
              <label>90+ дней</label>
              <span>{formatMoney(data.receivables.overdue_90_plus)}</span>
            </div>
          </div>

          {/* Список контрактов */}
          <table className="contracts-list">
            {data.receivables.by_contract.map(contract => (
              <tr key={contract.contract_id} className={contract.status}>
                <td>{contract.contract_name}</td>
                <td>{formatMoney(contract.amount)}</td>
                <td>{contract.days_overdue > 0 ? `${contract.days_overdue} дн.` : '-'}</td>
              </tr>
            ))}
          </table>
        </div>

        {/* Кредиторка */}
        <div className="payables">
          <h4>Кредиторская задолженность</h4>
          <div className="total">{formatMoney(data.payables.total)}</div>
          
          {/* Аналогично receivables */}
          {/* ... */}
        </div>
      </div>
    </div>
  );
}
```

---

## Предиктивные виджеты

### 6. Contract Forecast Widget (Прогноз завершения контракта)

**Endpoint:** `GET /analytics/predictive/contract-forecast`

**Параметры:**
```typescript
interface ContractForecastParams {
  contract_id: number;  // обязательно
}
```

**Формат ответа:**
```typescript
interface ContractForecastResponse {
  success: true;
  data: {
    contract_id: number;
    contract_name: string;
    current_progress: number;           // 0-100
    planned_completion_date: string;    // ISO 8601
    estimated_completion_date: string;  // ISO 8601
    deviation_days: number;             // отрицательное = опережение, положительное = задержка
    risk_level: 'low' | 'medium' | 'high' | 'critical';
    confidence: number;                 // 0.0 - 1.0
    forecast_data: {
      slope: number;
      intercept: number;
      r_squared: number;
    };
    progress_history: Array<{
      date: string;
      progress: number;
    }>;
  };
}
```

**Пример отображения:**
```jsx
function ContractForecastWidget({ contractId }) {
  const { data } = useQuery(['contract-forecast', contractId], () =>
    fetchContractForecast(contractId)
  );

  const riskColors = {
    low: '#28a745',
    medium: '#ffc107',
    high: '#fd7e14',
    critical: '#dc3545'
  };

  return (
    <div className="widget-card">
      <h3>Прогноз завершения контракта</h3>
      
      <div className="progress-circle">
        <CircularProgress value={data.current_progress} />
        <span>{data.current_progress}%</span>
      </div>

      <div className="dates-comparison">
        <div className="planned">
          <label>Плановая дата</label>
          <span>{formatDate(data.planned_completion_date)}</span>
        </div>
        <div className="estimated">
          <label>Прогнозируемая дата</label>
          <span>{formatDate(data.estimated_completion_date)}</span>
        </div>
        <div className={`deviation ${data.deviation_days > 0 ? 'late' : 'early'}`}>
          {data.deviation_days > 0 
            ? `Задержка ${data.deviation_days} дн.`
            : `Опережение ${Math.abs(data.deviation_days)} дн.`
          }
        </div>
      </div>

      <div className="risk-indicator" style={{ backgroundColor: riskColors[data.risk_level] }}>
        <span>Риск: {data.risk_level.toUpperCase()}</span>
      </div>

      {/* График истории прогресса с линией тренда */}
      <LineChart 
        data={data.progress_history}
        showTrendLine={true}
        confidence={data.confidence}
      />
    </div>
  );
}
```

---

### 7. Budget Risk Widget (Риск превышения бюджета)

**Endpoint:** `GET /analytics/predictive/budget-risk`

**Параметры:**
```typescript
interface BudgetRiskParams {
  project_id: number;  // обязательно
}
```

**Формат ответа:**
```typescript
interface BudgetRiskResponse {
  success: true;
  data: {
    project_id: number;
    project_name: string;
    budget: number;
    actual_spending: number;
    budget_usage_percentage: number;
    projected_total_cost: number;
    projected_overrun: number;
    projected_overrun_percentage: number;
    risk_level: 'low' | 'medium' | 'high' | 'critical';
    spending_history: Array<{
      date: string;
      amount: number;
    }>;
    forecast: {
      slope: number;
      confidence: number;
    };
  };
}
```

---

### 8. Material Needs Widget (Прогноз потребности в материалах)

**Endpoint:** `GET /analytics/predictive/material-needs`

**Параметры:**
```typescript
interface MaterialNeedsParams {
  months?: number;  // количество месяцев для прогноза (по умолчанию 3)
}
```

**Формат ответа:**
```typescript
interface MaterialNeedsResponse {
  success: true;
  data: {
    forecast_months: number;
    historical_usage: Array<{
      month: string;
      total_quantity: number;
      total_amount: number;
      materials_count: number;
    }>;
    forecasted_needs: Array<{
      month: string;
      estimated_quantity: number;
      estimated_amount: number;
    }>;
    top_materials: Array<{
      material_id: number;
      material_name: string;
      avg_monthly_usage: number;
      forecasted_need: number;
    }>;
  };
}
```

---

## HR & KPI виджеты

### 9. Employee KPI Widget

**Endpoint:** `GET /analytics/hr/kpi`

**Параметры:**
```typescript
interface KPIParams {
  user_id?: number;  // если не указан - текущий пользователь
  from: string;      // YYYY-MM-DD
  to: string;        // YYYY-MM-DD
}
```

**Формат ответа:**
```typescript
interface KPIResponse {
  success: true;
  data: {
    user_id: number;
    user_name: string;
    period: { from: string; to: string };
    metrics: {
      completed_works_count: number;
      work_volume: number;
      on_time_completion_rate: number;    // %
      quality_score: number;              // 0-100
      revenue_generated: number;
      cost_efficiency: number;            // %
    };
    overall_kpi: number;                  // 0-100
    performance_level: 'exceptional' | 'high' | 'good' | 'average' | 'low';
  };
}
```

**Пример отображения:**
```jsx
function EmployeeKPIWidget({ userId, from, to }) {
  const { data } = useQuery(['kpi', userId, from, to], () =>
    fetchKPI(userId, from, to)
  );

  return (
    <div className="widget-card">
      <h3>KPI: {data.user_name}</h3>
      
      <div className="overall-kpi">
        <RadialGauge value={data.overall_kpi} />
        <div className="level">{data.performance_level}</div>
      </div>

      <div className="metrics-grid">
        <div className="metric">
          <label>Выполнено работ</label>
          <span>{data.metrics.completed_works_count}</span>
        </div>
        <div className="metric">
          <label>В срок</label>
          <span>{data.metrics.on_time_completion_rate}%</span>
        </div>
        <div className="metric">
          <label>Качество</label>
          <span>{data.metrics.quality_score}/100</span>
        </div>
        <div className="metric">
          <label>Выручка</label>
          <span>{formatMoney(data.metrics.revenue_generated)}</span>
        </div>
        <div className="metric">
          <label>Эффективность</label>
          <span>{data.metrics.cost_efficiency}%</span>
        </div>
      </div>

      {/* Radar chart для визуализации всех метрик */}
      <RadarChart 
        data={[
          { metric: 'Работы', value: Math.min(100, data.metrics.completed_works_count * 2) },
          { metric: 'В срок', value: data.metrics.on_time_completion_rate },
          { metric: 'Качество', value: data.metrics.quality_score },
          { metric: 'Эффективность', value: data.metrics.cost_efficiency },
        ]}
      />
    </div>
  );
}
```

---

### 10. Top Performers Widget

**Endpoint:** `GET /analytics/hr/top-performers`

**Параметры:**
```typescript
interface TopPerformersParams {
  from: string;
  to: string;
  limit?: number;  // по умолчанию 10
}
```

**Формат ответа:**
```typescript
interface TopPerformersResponse {
  success: true;
  data: {
    period: { from: string; to: string };
    total_employees: number;
    average_kpi: number;
    top_performers: Array<{
      user_id: number;
      user_name: string;
      metrics: { /* те же что в KPI */ };
      overall_kpi: number;
      performance_level: string;
    }>;
  };
}
```

---

### 11. Resource Utilization Widget

**Endpoint:** `GET /analytics/hr/resource-utilization`

**Параметры:**
```typescript
interface UtilizationParams {
  from: string;
  to: string;
}
```

**Формат ответа:**
```typescript
interface UtilizationResponse {
  success: true;
  data: {
    period: { from: string; to: string };
    total_employees: number;
    average_utilization: number;  // %
    employees: Array<{
      user_id: number;
      user_name: string;
      worked_days: number;
      total_working_days: number;
      utilization_rate: number;   // %
      status: 'underutilized' | 'optimal' | 'overutilized';
    }>;
    underutilized: Array<{...}>;  // < 50%
    optimal: Array<{...}>;        // 50-90%
    overutilized: Array<{...}>;   // > 90%
  };
}
```

---

## Экспорт дашбордов

### Экспорт в PDF

**Endpoint:** `POST /export/dashboard/{id}/pdf`

**Пример запроса:**
```javascript
const exportToPDF = async (dashboardId) => {
  const response = await fetch(
    `/api/v1/admin/advanced-dashboard/export/dashboard/${dashboardId}/pdf`,
    {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${token}`,
        'X-Organization-ID': organizationId,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({
        filters: {
          from: '2025-01-01',
          to: '2025-10-07',
          project_id: null
        },
        widgets: null,  // null = все виджеты дашборда
        include_raw_data: false
      })
    }
  );

  const result = await response.json();
  
  // Скачать файл
  const fileUrl = `/storage/${result.data.path}`;
  window.open(fileUrl, '_blank');
};
```

**Формат ответа:**
```typescript
interface ExportResponse {
  success: true;
  data: {
    path: string;           // "exports/dashboards/my-dashboard_1234567890.pdf"
    filename: string;       // "my-dashboard_1234567890.pdf"
    size: number;           // размер в байтах
    mime_type: string;      // "application/pdf"
    download_url: string;   // полный URL для скачивания
  };
}
```

---

### Экспорт в Excel

**Endpoint:** `POST /export/dashboard/{id}/excel`

Параметры и ответ аналогичны PDF, но:
- файл будет `.xlsx`
- mime_type: `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`

**Особенности Excel экспорта:**
- Каждый виджет на отдельном листе или в отдельной секции
- Таблицы с форматированием (заголовки, цвета)
- Данные можно редактировать и анализировать
- Поддержка больших объемов данных

**Пример компонента экспорта:**
```jsx
function ExportButtons({ dashboardId }) {
  const [exporting, setExporting] = useState(false);

  const handleExport = async (format) => {
    setExporting(true);
    try {
      const response = await fetch(
        `/api/v1/admin/advanced-dashboard/export/dashboard/${dashboardId}/${format}`,
        {
          method: 'POST',
          headers: {
            'Authorization': `Bearer ${token}`,
            'X-Organization-ID': organizationId,
            'Content-Type': 'application/json'
          }
        }
      );

      const result = await response.json();
      
      if (result.success) {
        // Скачать файл
        window.location.href = result.data.download_url;
        
        toast.success(`Дашборд экспортирован в ${format.toUpperCase()}`);
      }
    } catch (error) {
      toast.error('Ошибка экспорта');
    } finally {
      setExporting(false);
    }
  };

  return (
    <div className="export-buttons">
      <button 
        onClick={() => handleExport('pdf')}
        disabled={exporting}
      >
        <FileDownloadIcon />
        Экспорт PDF
      </button>
      
      <button 
        onClick={() => handleExport('excel')}
        disabled={exporting}
      >
        <TableIcon />
        Экспорт Excel
      </button>
    </div>
  );
}
```

---

## Управление алертами

### Создание алерта

**Endpoint:** `POST /alerts`

**Пример запроса:**
```javascript
const createAlert = async () => {
  const response = await fetch('/api/v1/admin/advanced-dashboard/alerts', {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${token}`,
      'X-Organization-ID': organizationId,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      name: 'Превышение бюджета проекта',
      alert_type: 'budget_overrun',
      target_entity: 'project',
      target_entity_id: 123,
      comparison_operator: 'gt',
      threshold_value: 90,  // 90% бюджета
      priority: 'high',
      notification_channels: ['email', 'in_app'],
      recipients: ['user@example.com'],
      is_active: true,
      cooldown_minutes: 60
    })
  });

  return response.json();
};
```

**Типы алертов:**
```typescript
type AlertType = 
  | 'budget_overrun'      // Превышение бюджета
  | 'deadline_risk'       // Риск срыва сроков
  | 'low_stock'           // Низкий остаток материалов
  | 'contract_completion' // Завершение контракта
  | 'payment_overdue'     // Просроченные платежи
  | 'kpi_threshold'       // Порог KPI
  | 'custom';             // Кастомные условия
```

**Кастомные метрики для алертов:**
```typescript
const customMetrics = [
  'active_projects_count',      // Количество активных проектов
  'total_contracts_value',      // Общая стоимость контрактов
  'material_spending_rate',     // Темп расходов на материалы
  'average_contract_progress',  // Средний прогресс контрактов
  'overdue_contracts_count'     // Количество просроченных контрактов
];

// Пример кастомного алерта
{
  alert_type: 'custom',
  conditions: {
    metric: 'material_spending_rate'
  },
  comparison_operator: 'gt',
  threshold_value: 1000000  // > 1млн в месяц
}
```

### История алертов

**Endpoint:** `GET /alerts/{id}/history`

**Формат ответа:**
```typescript
interface AlertHistoryResponse {
  success: true;
  data: {
    alert_id: number;
    alert_name: string;
    total_triggers: number;
    last_triggered_at: string;
    is_triggered: boolean;
    history: Array<{
      id: number;
      status: 'triggered' | 'resolved';
      trigger_value: number;
      message: string;
      triggered_at: string;
      resolved_at: string | null;
      trigger_data: object;
    }>;
  };
}
```

---

## Типы данных

### Общие типы

```typescript
// Период
interface Period {
  from: string;  // ISO 8601
  to: string;    // ISO 8601
}

// Метрика с трендом
interface MetricWithTrend {
  value: number;
  previous_value: number;
  change: number;
  change_percentage: number;
  trend: 'up' | 'down' | 'stable';
}

// Статус риска
type RiskLevel = 'low' | 'medium' | 'high' | 'critical';

// Уровень производительности
type PerformanceLevel = 'exceptional' | 'high' | 'good' | 'average' | 'low';
```

---

## Обработка ошибок

### Стандартный формат ошибки

```typescript
interface ErrorResponse {
  success: false;
  message: string;
  errors?: {
    [field: string]: string[];
  };
  upgrade_required?: boolean;  // если модуль не активирован
  module?: {
    slug: string;
    name: string;
    price: number;
    currency: string;
    trial_available: boolean;
    trial_days: number;
  };
}
```

### Коды ошибок

```typescript
// 400 - Ошибка валидации
{
  success: false,
  message: 'Validation error',
  errors: {
    from: ['Поле from обязательно'],
    to: ['Дата to должна быть после from']
  }
}

// 402 - Модуль не активирован
{
  success: false,
  upgrade_required: true,
  module: {
    slug: 'advanced-dashboard',
    name: 'Продвинутый дашборд',
    price: 4990,
    currency: 'RUB',
    trial_available: true,
    trial_days: 7
  },
  message: 'Для доступа к этой функции активируйте модуль "Продвинутый дашборд"'
}

// 404 - Не найдено
{
  success: false,
  message: 'Dashboard not found'
}

// 500 - Ошибка сервера
{
  success: false,
  message: 'Internal server error'
}
```

### Пример обработки ошибок

```typescript
async function fetchWidgetData<T>(url: string): Promise<T> {
  try {
    const response = await fetch(url, {
      headers: {
        'Authorization': `Bearer ${token}`,
        'X-Organization-ID': organizationId
      }
    });

    const data = await response.json();

    if (!data.success) {
      if (data.upgrade_required) {
        // Показать модальное окно с предложением активировать модуль
        showUpgradeModal(data.module);
        throw new Error('Module not activated');
      }

      throw new Error(data.message || 'Unknown error');
    }

    return data.data;
    
  } catch (error) {
    console.error('Widget data fetch error:', error);
    toast.error(error.message);
    throw error;
  }
}
```

---

## Утилиты для фронтенда

### Форматирование денег

```typescript
function formatMoney(amount: number, currency: string = 'RUB'): string {
  return new Intl.NumberFormat('ru-RU', {
    style: 'currency',
    currency: currency,
    minimumFractionDigits: 0,
    maximumFractionDigits: 2
  }).format(amount);
}
```

### Форматирование дат

```typescript
function formatDate(dateString: string): string {
  const date = new Date(dateString);
  return new Intl.DateTimeFormat('ru-RU', {
    year: 'numeric',
    month: 'long',
    day: 'numeric'
  }).format(date);
}
```

### Цвета для статусов

```typescript
const STATUS_COLORS = {
  risk: {
    low: '#28a745',
    medium: '#ffc107',
    high: '#fd7e14',
    critical: '#dc3545'
  },
  performance: {
    exceptional: '#28a745',
    high: '#20c997',
    good: '#17a2b8',
    average: '#ffc107',
    low: '#dc3545'
  },
  trend: {
    up: '#28a745',
    down: '#dc3545',
    stable: '#6c757d'
  }
};
```

---

## Примеры компонентов

### Универсальный виджет-контейнер

```tsx
interface WidgetProps {
  title: string;
  loading?: boolean;
  error?: Error | null;
  onRefresh?: () => void;
  onExport?: () => void;
  children: React.ReactNode;
}

function Widget({ title, loading, error, onRefresh, onExport, children }: WidgetProps) {
  if (loading) {
    return (
      <div className="widget-card loading">
        <Spinner />
      </div>
    );
  }

  if (error) {
    return (
      <div className="widget-card error">
        <h3>{title}</h3>
        <div className="error-message">{error.message}</div>
        {onRefresh && <button onClick={onRefresh}>Попробовать снова</button>}
      </div>
    );
  }

  return (
    <div className="widget-card">
      <div className="widget-header">
        <h3>{title}</h3>
        <div className="actions">
          {onRefresh && <button onClick={onRefresh}><RefreshIcon /></button>}
          {onExport && <button onClick={onExport}><DownloadIcon /></button>}
        </div>
      </div>
      <div className="widget-content">
        {children}
      </div>
    </div>
  );
}
```

### Хук для загрузки данных виджета

```typescript
function useWidgetData<T>(
  endpoint: string,
  params?: Record<string, any>,
  options?: { refetchInterval?: number }
) {
  return useQuery<T>(
    [endpoint, params],
    async () => {
      const queryString = new URLSearchParams(params).toString();
      const url = `/api/v1/admin/advanced-dashboard${endpoint}${queryString ? `?${queryString}` : ''}`;
      
      const response = await fetch(url, {
        headers: {
          'Authorization': `Bearer ${getToken()}`,
          'X-Organization-ID': getOrganizationId()
        }
      });

      const data = await response.json();
      
      if (!data.success) {
        throw new Error(data.message);
      }
      
      return data.data;
    },
    {
      refetchInterval: options?.refetchInterval,
      staleTime: 5 * 60 * 1000, // 5 минут
    }
  );
}

// Использование
function MyCashFlowWidget() {
  const { data, isLoading, error, refetch } = useWidgetData<CashFlowResponse['data']>(
    '/analytics/financial/cash-flow',
    { from: '2025-01-01', to: '2025-10-07' }
  );

  return (
    <Widget title="Cash Flow" loading={isLoading} error={error} onRefresh={refetch}>
      {data && <CashFlowChart data={data} />}
    </Widget>
  );
}
```

---

## Чек-лист интеграции

- [ ] Настроить аутентификацию с заголовками
- [ ] Создать утилиты для форматирования (деньги, даты)
- [ ] Реализовать обработку ошибок (включая upgrade_required)
- [ ] Создать переиспользуемый компонент Widget
- [ ] Создать хуки для загрузки данных
- [ ] Интегрировать библиотеку графиков (Chart.js / ApexCharts / Recharts)
- [ ] Реализовать 11 виджетов аналитики
- [ ] Добавить функционал экспорта PDF/Excel
- [ ] Реализовать управление алертами
- [ ] Добавить тесты для критичных компонентов

---

**Документация подготовлена:** 7 октября 2025  
**Версия:** 1.0  
**Контакт:** Backend Team


