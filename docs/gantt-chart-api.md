# 🚀 API Руководство: Система графика работ

## 📋 Быстрый старт

### Базовый URL
```
https://api.prohelper.pro/api/v1/schedules
```

### Аутентификация
Все запросы требуют JWT токен в заголовке:
```http
Authorization: Bearer YOUR_JWT_TOKEN
```

## 🔧 Основные операции

### 1. Получить список графиков
```http
GET /api/v1/schedules
```

**Параметры:**
- `per_page` (int) - количество на странице (по умолчанию 15, максимум 100)
- `status` (string) - фильтр по статусу: `draft`, `active`, `paused`, `completed`, `cancelled`
- `project_id` (int) - ID проекта
- `is_template` (bool) - только шаблоны
- `search` (string) - поиск по названию и описанию
- `date_from` (date) - начальная дата диапазона
- `date_to` (date) - конечная дата диапазона
- `sort_by` (string) - поле сортировки
- `sort_order` (string) - направление: `asc` или `desc`

**Пример запроса:**
```javascript
const response = await fetch('/api/v1/schedules?status=active&per_page=20', {
  headers: {
    'Authorization': 'Bearer ' + token,
    'Accept': 'application/json'
  }
});
```

**Ответ:**
```json
{
  "data": [
    {
      "id": 123,
      "name": "График строительства дома",
      "status": "active",
      "overall_progress_percent": 35.5,
      "critical_path_calculated": true,
      "ui_data": {
        "can_edit": true,
        "progress_color": "#3B82F6"
      }
    }
  ],
  "meta": {
    "current_page": 1,
    "total": 42,
    "per_page": 20
  }
}
```

### 2. Создать новый график
```http
POST /api/v1/schedules
```

**Тело запроса:**
```json
{
  "project_id": 456,
  "name": "График реконструкции офиса",
  "description": "Поэтапная реконструкция офисного помещения",
  "planned_start_date": "2025-03-01",
  "planned_end_date": "2025-06-30",
  "status": "draft",
  "calculation_settings": {
    "auto_schedule": true,
    "working_days_per_week": 5,
    "working_hours_per_day": 8
  }
}
```

### 3. Получить детали графика
```http
GET /api/v1/schedules/{id}
```

**Ответ включает полную информацию:**
```json
{
  "data": {
    "id": 123,
    "name": "График строительства дома",
    "planned_start_date": "2025-02-01",
    "planned_end_date": "2025-12-31",
    "status": "active",
    "tasks": [
      {
        "id": 789,
        "name": "Заливка фундамента",
        "status": "in_progress",
        "is_critical": true,
        "progress_percent": 75.0
      }
    ],
    "dependencies": [
      {
        "id": 456,
        "predecessor_task_id": 789,
        "successor_task_id": 790,
        "dependency_type": "FS"
      }
    ]
  }
}
```

### 4. Обновить график
```http
PUT /api/v1/schedules/{id}
```

### 5. Удалить график
```http
DELETE /api/v1/schedules/{id}
```

## ⚡ Специальные операции

### Рассчитать критический путь
```http
POST /api/v1/schedules/{id}/critical-path
```

**Ответ:**
```json
{
  "message": "Критический путь рассчитан",
  "data": {
    "duration": 280,
    "tasks": [
      {
        "id": 789,
        "name": "Фундамент",
        "is_critical": true
      }
    ],
    "statistics": {
      "total_tasks": 25,
      "critical_tasks": 8,
      "critical_percentage": 32.0
    }
  }
}
```

### Сохранить базовый план
```http
POST /api/v1/schedules/{id}/baseline
```

### Очистить базовый план
```http
DELETE /api/v1/schedules/{id}/baseline
```

### Создать из шаблона
```http
POST /api/v1/schedules/from-template
```

**Тело запроса:**
```json
{
  "template_id": 123,
  "project_id": 456,
  "name": "Новый график из шаблона",
  "planned_start_date": "2025-04-01",
  "planned_end_date": "2025-10-31"
}
```

## 📊 Дополнительные эндпоинты

### Получить шаблоны
```http
GET /api/v1/schedules/templates
```

### Статистика по графикам
```http
GET /api/v1/schedules/statistics
```

**Ответ:**
```json
{
  "data": {
    "total_schedules": 42,
    "active_schedules": 15,
    "completed_schedules": 20,
    "avg_progress": 67.5,
    "with_overdue_tasks": 3
  }
}
```

### Графики с просрочками
```http
GET /api/v1/schedules/overdue
```

### Недавно обновленные
```http
GET /api/v1/schedules/recent?limit=10
```

## 🎨 UI Integration Guide

### Состояния загрузки
```javascript
const [schedules, setSchedules] = useState([]);
const [loading, setLoading] = useState(false);
const [error, setError] = useState(null);

const fetchSchedules = async () => {
  setLoading(true);
  try {
    const response = await api.get('/schedules');
    setSchedules(response.data.data);
  } catch (err) {
    setError(err.message);
  } finally {
    setLoading(false);
  }
};
```

### Обработка ошибок
```javascript
// Типичные коды ошибок
switch (error.status) {
  case 404:
    showMessage('График не найден');
    break;
  case 422:
    showValidationErrors(error.data.errors);
    break;
  case 500:
    showMessage('Ошибка сервера при расчете критического пути');
    break;
}
```

### Цветовое кодирование
```javascript
// Используйте цвета из ui_data
const getTaskColor = (task) => {
  if (task.is_critical) return '#EF4444'; // красный
  if (task.status === 'completed') return '#10B981'; // зеленый
  if (task.status === 'in_progress') return '#3B82F6'; // синий
  return '#6B7280'; // серый
};
```

### Прогресс бары
```javascript
const ProgressBar = ({ schedule }) => (
  <div className="w-full bg-gray-200 rounded-full h-2">
    <div 
      className="h-2 rounded-full transition-all duration-300"
      style={{ 
        width: `${schedule.overall_progress_percent}%`,
        backgroundColor: schedule.ui_data.progress_color
      }}
    />
  </div>
);
```

## 🔄 Real-time обновления

### WebSocket подключение
```javascript
const ws = new WebSocket('wss://api.prohelper.pro/ws');

ws.onmessage = (event) => {
  const data = JSON.parse(event.data);
  
  if (data.type === 'schedule_updated') {
    updateScheduleInList(data.schedule);
  }
  
  if (data.type === 'critical_path_calculated') {
    refreshScheduleDetails(data.schedule_id);
  }
};
```

## 📱 Мобильная адаптация

### Responsive компоненты
```javascript
const GanttChart = () => {
  const [isMobile, setIsMobile] = useState(window.innerWidth < 768);
  
  return (
    <div className={`gantt-container ${isMobile ? 'mobile' : 'desktop'}`}>
      {isMobile ? <MobileGanttView /> : <DesktopGanttView />}
    </div>
  );
};
```

## 🧪 Тестирование

### Unit тесты API вызовов
```javascript
import { renderHook, waitFor } from '@testing-library/react';
import { useSchedules } from './useSchedules';

test('должен загружать список графиков', async () => {
  const { result } = renderHook(() => useSchedules());
  
  await waitFor(() => {
    expect(result.current.schedules).toHaveLength(5);
    expect(result.current.loading).toBe(false);
  });
});
```

### Mock данные для разработки
```javascript
const mockSchedule = {
  id: 123,
  name: 'Тестовый график',
  status: 'active',
  overall_progress_percent: 45.5,
  critical_path_calculated: true,
  ui_data: {
    can_edit: true,
    progress_color: '#3B82F6',
    status_color: '#3B82F6'
  }
};
```

## ⚠️ Важные моменты

### Производительность
- Используйте пагинацию для больших списков
- Кешируйте данные графиков локально
- Загружайте детали только при необходимости

### Безопасность
- Всегда проверяйте `ui_data.can_edit` перед показом кнопок редактирования
- Валидируйте права доступа на фронтенде
- Не храните чувствительные данные в localStorage

### UX рекомендации
- Показывайте индикаторы загрузки для долгих операций (расчет критического пути)
- Используйте оптимистичные обновления для быстрых изменений
- Предупреждайте пользователей о потере данных при закрытии

---

**💡 Совет:** Начните с простого списка графиков, затем добавляйте сложные функции по мере необходимости. API предоставляет всю необходимую информацию для создания профессиональной диаграммы Ганта. 