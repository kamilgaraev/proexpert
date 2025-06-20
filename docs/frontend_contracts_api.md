# API документация: Контракты и выполненные работы

## 🚀 **Новая система контрактов**

Реализована полная система управления контрактами с финансовым контролем, real-time уведомлениями и расширенной аналитикой.

---

## **API Endpoints**

### **1. Дашборд**

#### `GET /api/v1/admin/dashboard/contracts/requiring-attention`
Контракты требующие внимания

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "number": "К-001",
      "project_name": "Офисный центр",
      "contractor_name": "ООО Строй",
      "total_amount": 1000000,
      "completed_works_amount": 950000,
      "completion_percentage": 95,
      "remaining_amount": 50000,
      "status": "active",
      "is_nearing_limit": true,
      "attention_reason": ["Приближение к лимиту (95%)"],
      "priority": 120
    }
  ]
}
```

#### `GET /api/v1/admin/dashboard/contracts/statistics`
Общая статистика

#### `GET /api/v1/admin/dashboard/contracts/top?limit=5`
Топ контрактов по объему

#### `GET /api/v1/admin/dashboard/recent-activity?days=30`
Последняя активность

---

### **2. Контракты с расширенной фильтрацией**

#### `GET /api/v1/admin/contracts`

**Новые параметры фильтрации:**
```
?completion_from=80&completion_to=100
&amount_from=500000&amount_to=2000000
&requiring_attention=1
&is_nearing_limit=1
&is_overdue=1
&search=К-001
```

**Новые поля в ответе:**
```json
{
  "id": 1,
  "completed_works_amount": 850000,
  "remaining_amount": 150000,
  "completion_percentage": 85,
  "is_nearing_limit": false,
  "can_add_work": true
}
```

### **3. Выполненные работы с фильтрами**

#### `GET /api/v1/admin/completed-works`

**Новые параметры:**
```
?contract_id=1
&contractor_id=2
&amount_from=1000&amount_to=50000
&quantity_from=10&quantity_to=100
&with_materials=1
```

### **4. Аналитика контрактов**

#### `GET /api/v1/admin/contracts/{id}/analytics`
Подробная аналитика

#### `GET /api/v1/admin/contracts/{id}/completed-works`
Работы по контракту

### **5. Фильтры**

#### `GET /api/v1/admin/filters/contracts`
Данные для фильтров контрактов

#### `GET /api/v1/admin/filters/completed-works`
Данные для фильтров работ

#### `GET /api/v1/admin/filters/quick-stats`
Быстрая статистика

---

## **🔄 Real-time уведомления**

### WebSocket Events

#### `contract.status.changed`
```javascript
channel.listen('.contract.status.changed', (event) => {
  console.log('Статус изменен:', event.message);
  updateContractStatus(event.contract_id, event.new_status);
});
```

#### `contract.limit.warning`
```javascript
channel.listen('.contract.limit.warning', (event) => {
  showNotification(event.message, event.level);
  updateAttentionCounter();
});
```

---

## **💡 UI Компоненты**

### Дашборд карточки
```html
<div class="stat-card">
  <h3>Требуют внимания</h3>
  <div class="stat-number critical">3</div>
</div>
```

### Прогресс-бар контракта
```html
<div class="progress-bar">
  <div class="progress-fill" style="width: 85%"></div>
  <span class="progress-text">85%</span>
</div>
```

### Фильтры
```html
<div class="filter-group">
  <label>Процент выполнения</label>
  <input type="number" placeholder="от" name="completion_from">
  <input type="number" placeholder="до" name="completion_to">
</div>
```

---

## **⚠️ Важные моменты**

1. **Лимиты контрактов**: Проверяйте `can_add_work` перед добавлением работ
2. **Real-time**: Подключайтесь к каналу `organization.{id}`
3. **Ошибки**: Все ошибки содержат `error_code`
4. **Статусы**: 
   - `draft` → `active` → `completed`
   - `terminated` - досрочно расторгнут

---

## **📱 Адаптивность**

### Мобильные фильтры
```javascript
function openFiltersModal() {
  document.getElementById('filters-modal').classList.add('active');
}
```

### Компактные карточки
```html
<div class="contract-card mobile">
  <div class="contract-header">
    <h4>К-001</h4>
    <span class="status active">Активный</span>
  </div>
  <div class="progress-info">85% • 150k ₽ осталось</div>
</div>
```

---

## **🔧 Код примеры**

### Загрузка контрактов с фильтрами
```javascript
async function loadContracts(filters = {}) {
  const params = new URLSearchParams(filters);
  const response = await fetch(`/api/v1/admin/contracts?${params}`);
  return await response.json();
}
```

### Проверка лимита перед добавлением работы
```javascript
async function checkContractLimit(contractId, amount) {
  const response = await fetch(`/api/v1/admin/contracts/${contractId}/analytics`);
  const data = await response.json();
  
  return amount <= data.data.remaining_amount;
}
```

### Обновление счетчиков внимания
```javascript
async function updateAttentionCounter() {
  const response = await fetch('/api/v1/admin/dashboard/contracts/requiring-attention');
  const data = await response.json();
  
  document.getElementById('attention-count').textContent = data.data.length;
}
```

---

## **📊 Метрики для отслеживания**

- Контракты приближающиеся к лимиту (90%+)
- Просроченные контракты  
- Средний процент выполнения
- Сумма в работе vs завершенная
- Активность по дням/неделям

---

Используйте эту документацию для создания современного интерфейса с real-time обновлениями и расширенной аналитикой! 