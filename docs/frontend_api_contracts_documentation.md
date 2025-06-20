# Документация API для фронтенда - Контракты и выполненные работы

## Обзор системы

Реализована полная система управления контрактами с:

- **Финансовым контролем** - автоматическое отслеживание лимитов и остатков
- **Real-time уведомлениями** - WebSocket события для мгновенных обновлений
- **Расширенной фильтрацией** - множественные критерии поиска
- **Аналитикой и дашбордом** - статистика и контракты требующие внимания

---

## 🚀 Новые API endpoints

### **Дашборд контрактов**

#### `GET /api/v1/admin/dashboard/contracts/requiring-attention`
Получить контракты требующие внимания

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
      "end_date": "2024-12-31",
      "is_nearing_limit": true,
      "is_overdue": false,
      "is_completed": false,
      "attention_reason": ["Приближение к лимиту (95%)"],
      "priority": 120
    }
  ]
}
```

#### `GET /api/v1/admin/dashboard/contracts/statistics`
Общая статистика по контрактам

**Response:**
```json
{
  "success": true,
  "data": {
    "contracts": {
      "total": 15,
      "active": 8,
      "completed": 5,
      "draft": 2,
      "requiring_attention": 3,
      "total_amount": 15000000,
      "avg_amount": 1000000
    },
    "completed_works": {
      "total": 120,
      "confirmed": 100,
      "confirmed_amount": 8500000
    }
  }
}
```

#### `GET /api/v1/admin/dashboard/contracts/top?limit=5`
Топ контрактов по объему

#### `GET /api/v1/admin/dashboard/recent-activity?days=30`
Последняя активность по выполненным работам

---

### **Расширенная фильтрация контрактов**

#### `GET /api/v1/admin/contracts`

**Новые параметры фильтрации:**
```
GET /api/v1/admin/contracts?
  contractor_id=1&
  project_id=2&
  status=active&
  type=contract&
  completion_from=80&
  completion_to=100&
  amount_from=500000&
  amount_to=2000000&
  requiring_attention=1&
  is_nearing_limit=1&
  is_overdue=1&
  search=К-001&
  sort_by=completion_percentage&
  sort_direction=desc&
  per_page=20
```

**Параметры:**

| Параметр | Тип | Описание |
|----------|-----|----------|
| `completion_from` | number | Процент выполнения от |
| `completion_to` | number | Процент выполнения до |
| `amount_from` | number | Сумма контракта от |
| `amount_to` | number | Сумма контракта до |
| `requiring_attention` | boolean | Требуют внимания |
| `is_nearing_limit` | boolean | Приближаются к лимиту (90%+) |
| `is_overdue` | boolean | Просроченные |
| `search` | string | Поиск по номеру/проекту/подрядчику |

**Сортировка:**
- `created_at`, `date`, `total_amount`, `number`, `status`

---

### **Расширенная фильтрация выполненных работ**

#### `GET /api/v1/admin/completed-works`

**Новые параметры фильтрации:**
```
GET /api/v1/admin/completed-works?
  project_id=1&
  contract_id=2&
  work_type_id=3&
  user_id=4&
  contractor_id=5&
  status=confirmed&
  completion_date_from=2024-01-01&
  completion_date_to=2024-12-31&
  amount_from=1000&
  amount_to=50000&
  quantity_from=10&
  quantity_to=100&
  with_materials=1&
  search=бетон&
  sortBy=completion_date&
  sortDirection=desc&
  perPage=20
```

**Новые параметры:**

| Параметр | Тип | Описание |
|----------|-----|----------|
| `contract_id` | number | Фильтр по контракту |
| `contractor_id` | number | Фильтр по подрядчику |
| `amount_from/to` | number | Диапазон суммы работы |
| `quantity_from/to` | number | Диапазон количества |
| `with_materials` | boolean | Только работы с материалами |
| `search` | string | Поиск по описанию/комментарию |

---

### **Данные для фильтров**

#### `GET /api/v1/admin/filters/contracts`
Получить все данные для фильтров контрактов

**Response:**
```json
{
  "success": true,
  "data": {
    "statuses": [
      {"value": "draft", "label": "Черновик"},
      {"value": "active", "label": "Активный"},
      {"value": "completed", "label": "Завершен"}
    ],
    "types": [
      {"value": "contract", "label": "Контракт"},
      {"value": "agreement", "label": "Соглашение"}
    ],
    "projects": [
      {"value": 1, "label": "Офисный центр"}
    ],
    "contractors": [
      {"value": 1, "label": "ООО Строй"}
    ]
  }
}
```

#### `GET /api/v1/admin/filters/completed-works`
Данные для фильтров выполненных работ

#### `GET /api/v1/admin/filters/quick-stats`
Быстрая статистика для индикаторов

---

### **Аналитика контрактов**

#### `GET /api/v1/admin/contracts/{id}/analytics`
Подробная аналитика по контракту

**Response:**
```json
{
  "success": true,
  "data": {
    "contract_id": 1,
    "contract_number": "К-001",
    "total_amount": 1000000,
    "completed_works_amount": 850000,
    "remaining_amount": 150000,
    "completion_percentage": 85,
    "total_paid_amount": 800000,
    "total_performed_amount": 850000,
    "status": "active",
    "is_nearing_limit": false,
    "can_add_work": true,
    "completed_works_count": 25,
    "confirmed_works_count": 22
  }
}
```

#### `GET /api/v1/admin/contracts/{id}/completed-works`
Выполненные работы по контракту

---

## 🔄 Real-time уведомления (WebSocket)

### Подключение к каналу
```javascript
// Подключение к каналу организации
const channel = window.Echo.private(`organization.${organizationId}`);
```

### События контрактов

#### `contract.status.changed`
Изменение статуса контракта
```javascript
channel.listen('.contract.status.changed', (event) => {
  console.log('Статус контракта изменен:', event);
  // event.contract_id, event.old_status, event.new_status
  
  // Обновить UI
  updateContractInList(event.contract_id, {
    status: event.new_status,
    completion_percentage: event.completion_percentage
  });
  
  // Показать уведомление
  showNotification(event.message, 'info');
});
```

#### `contract.limit.warning`
Предупреждение о приближении к лимиту
```javascript
channel.listen('.contract.limit.warning', (event) => {
  console.log('Предупреждение по контракту:', event);
  
  // Показать уведомление в зависимости от уровня
  const alertType = {
    'critical': 'error',
    'high': 'warning', 
    'medium': 'warning',
    'low': 'info'
  }[event.level];
  
  showNotification(event.message, alertType);
  
  // Обновить счетчик "требуют внимания"
  updateAttentionCounter();
  
  // Добавить в список контрактов требующих внимания
  addToAttentionList({
    id: event.contract_id,
    number: event.contract_number,
    completion_percentage: event.completion_percentage,
    level: event.level
  });
});
```

---

## 💡 Рекомендации по UI/UX

### **Дашборд**

#### Карточки статистики
```html
<div class="stats-grid">
  <div class="stat-card">
    <h3>Контракты требуют внимания</h3>
    <div class="stat-number critical">3</div>
    <div class="stat-trend">↑ 2 за неделю</div>
  </div>
  
  <div class="stat-card">
    <h3>Активные контракты</h3>
    <div class="stat-number">8</div>
    <div class="stat-amount">15.2М ₽</div>
  </div>
</div>
```

#### Контракты требующие внимания
```html
<div class="attention-contracts">
  <div class="attention-item priority-critical">
    <div class="contract-info">
      <h4>К-001 - Офисный центр</h4>
      <p>ООО Строй</p>
    </div>
    <div class="progress-bar">
      <div class="progress-fill" style="width: 98%"></div>
      <span class="progress-text">98%</span>
    </div>
    <div class="attention-badges">
      <span class="badge critical">КРИТИЧНО</span>
      <span class="badge">98% исчерпан</span>
    </div>
  </div>
</div>
```

### **Фильтры**

#### UI для расширенных фильтров
```html
<div class="filters-panel">
  <!-- Быстрые фильтры -->
  <div class="quick-filters">
    <button class="filter-btn active" data-filter="all">
      Все <span class="count">15</span>
    </button>
    <button class="filter-btn" data-filter="requiring-attention">
      Требуют внимания <span class="count critical">3</span>
    </button>
    <button class="filter-btn" data-filter="active">
      Активные <span class="count">8</span>
    </button>
  </div>
  
  <!-- Расширенные фильтры -->
  <div class="advanced-filters">
    <div class="filter-group">
      <label>Процент выполнения</label>
      <div class="range-inputs">
        <input type="number" placeholder="от" name="completion_from">
        <input type="number" placeholder="до" name="completion_to">
      </div>
    </div>
    
    <div class="filter-group">
      <label>Сумма контракта</label>
      <div class="range-inputs">
        <input type="number" placeholder="от" name="amount_from">
        <input type="number" placeholder="до" name="amount_to">
      </div>
    </div>
  </div>
</div>
```

### **Уведомления**

#### Система уведомлений
```javascript
class NotificationSystem {
  constructor() {
    this.container = document.getElementById('notifications');
  }
  
  show(message, type = 'info', duration = 5000) {
    const notification = document.createElement('div');
    notification.className = `notification ${type}`;
    notification.innerHTML = `
      <div class="notification-content">
        <i class="icon ${this.getIcon(type)}"></i>
        <span>${message}</span>
      </div>
      <button class="notification-close">&times;</button>
    `;
    
    this.container.appendChild(notification);
    
    // Автоматическое скрытие
    setTimeout(() => {
      this.hide(notification);
    }, duration);
  }
  
  getIcon(type) {
    const icons = {
      'error': 'fa-exclamation-triangle',
      'warning': 'fa-exclamation-circle', 
      'info': 'fa-info-circle',
      'success': 'fa-check-circle'
    };
    return icons[type] || icons.info;
  }
}
```

---

## 📊 Новые поля в API ответах

### **Контракт (Contract)**
```json
{
  "id": 1,
  "number": "К-001",
  "status": "active",
  "total_amount": 1000000,
  
  // НОВЫЕ ПОЛЯ
  "completed_works_amount": 850000,    // Сумма подтвержденных работ
  "remaining_amount": 150000,          // Оставшаяся сумма
  "completion_percentage": 85,         // Процент выполнения
  "is_nearing_limit": false,          // Приближение к лимиту (90%+)
  "can_add_work": true,               // Можно ли добавлять работы
  
  // Связи
  "project": { "id": 1, "name": "Офисный центр" },
  "contractor": { "id": 1, "name": "ООО Строй" }
}
```

### **Выполненная работа (CompletedWork)**
```json
{
  "id": 1,
  "quantity": 100,
  "total_amount": 50000,
  "status": "confirmed",
  
  // Расширенные связи
  "contract": {
    "id": 1,
    "number": "К-001",
    "contractor": { "id": 1, "name": "ООО Строй" }
  },
  "materials": [
    {
      "id": 1,
      "name": "Цемент",
      "quantity": 50,
      "price": 500,
      "total_amount": 25000
    }
  ]
}
```

---

## 🔧 Валидация и ошибки

### **Ошибки при превышении лимитов**
```json
{
  "success": false,
  "message": "Невозможно добавить работу: превышен лимит контракта",
  "error_code": "CONTRACT_LIMIT_EXCEEDED",
  "data": {
    "contract_id": 1,
    "contract_limit": 1000000,
    "current_amount": 950000,
    "requested_amount": 100000,
    "available_amount": 50000
  }
}
```

### **Проверка лимитов перед добавлением**
```javascript
// Перед отправкой формы
async function checkContractLimit(contractId, amount) {
  const response = await fetch(`/api/v1/admin/contracts/${contractId}/analytics`);
  const data = await response.json();
  
  if (!data.data.can_add_work) {
    showError('Контракт завершен или расторгнут');
    return false;
  }
  
  if (amount > data.data.remaining_amount) {
    showWarning(`Доступно только ${data.data.remaining_amount} ₽`);
    return false;
  }
  
  return true;
}
```

---

## 🎯 Статусы и их значения

### **Статусы контрактов**
- `draft` - Черновик (можно редактировать)
- `active` - Активный (можно добавлять работы)
- `completed` - Завершен (100% выполнен)
- `on_hold` - На паузе (временно приостановлен)
- `terminated` - Расторгнут (закрыт досрочно)

### **Статусы выполненных работ**
- `pending` - Ожидает подтверждения
- `confirmed` - Подтверждено (учитывается в лимитах)
- `rejected` - Отклонено

---

## 📱 Адаптивность

### **Мобильная версия фильтров**
```html
<!-- Мобильная кнопка фильтров -->
<button class="mobile-filters-btn" onclick="openFiltersModal()">
  <i class="fa fa-filter"></i>
  Фильтры
  <span class="filters-count">3</span>
</button>

<!-- Модальное окно фильтров -->
<div class="filters-modal mobile-only">
  <!-- Содержимое фильтров -->
</div>
```

### **Компактный вид контрактов**
```html
<div class="contract-card mobile">
  <div class="contract-header">
    <h4>К-001</h4>
    <span class="status-badge active">Активный</span>
  </div>
  <div class="contract-progress">
    <div class="progress-bar">
      <div class="progress-fill" style="width: 85%"></div>
    </div>
    <span class="progress-text">85% • 150k ₽ осталось</span>
  </div>
  <div class="contract-meta">
    <span>ООО Строй • Офисный центр</span>
  </div>
</div>
```

---

## 🚀 Производительность

### **Пагинация и виртуализация**
```javascript
// Для больших списков используйте виртуальную прокрутку
const virtualList = new VirtualList({
  container: '#contracts-list',
  itemHeight: 120,
  renderItem: (contract) => renderContractCard(contract),
  loadMore: () => loadNextPage()
});
```

### **Кэширование фильтров**
```javascript
// Кэшируем данные фильтров
const FiltersCache = {
  cache: new Map(),
  
  async getFilterData(type) {
    if (this.cache.has(type)) {
      return this.cache.get(type);
    }
    
    const data = await fetch(`/api/v1/admin/filters/${type}`);
    this.cache.set(type, data);
    return data;
  }
};
```

---

## 🔄 Обновление данных

### **Автоматическое обновление списков**
```javascript
// Обновление при получении WebSocket событий
channel.listen('.contract.status.changed', (event) => {
  // Обновляем конкретный элемент вместо перезагрузки всего списка
  updateContractInList(event.contract_id, {
    status: event.new_status,
    completion_percentage: event.completion_percentage
  });
});

// Периодическое обновление счетчиков
setInterval(async () => {
  const stats = await fetch('/api/v1/admin/filters/quick-stats');
  updateDashboardCounters(stats.data);
}, 60000); // каждую минуту
```

---

## ⚠️ Важные замечания

1. **Все суммы** возвращаются как float, форматируйте на фронтенде
2. **Даты** в формате ISO 8601 (YYYY-MM-DD)
3. **WebSocket** требует аутентификации через токен
4. **Фильтры** сохраняйте в localStorage для удобства
5. **Ошибки** всегда содержат поле `error_code` для обработки

---

## 📋 Чек-лист реализации

### Обязательные компоненты:
- [ ] Дашборд с карточками статистики
- [ ] Список контрактов с расширенными фильтрами
- [ ] Индикаторы процента выполнения
- [ ] Система уведомлений (toast/снэкбар)
- [ ] Модальные окна для фильтров (мобильная версия)

### Желательные компоненты:
- [ ] Графики выполнения контрактов
- [ ] Календарь с важными датами
- [ ] Экспорт отфильтрованных данных
- [ ] Массовые операции
- [ ] Автосохранение фильтров

### WebSocket интеграция:
- [ ] Подключение к каналу организации
- [ ] Обработка событий изменения статуса
- [ ] Обработка предупреждений о лимитах
- [ ] Автоматическое обновление счетчиков
- [ ] Восстановление соединения при разрыве

---

Эта документация покрывает все новые возможности системы контрактов. Используйте её как основу для разработки пользовательского интерфейса с современным UX и real-time обновлениями. 