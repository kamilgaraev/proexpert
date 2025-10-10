# AI Assistant Module

Модуль умного ассистента на базе GPT-4o-mini для анализа проектов, генерации отчетов и автоматизации задач.

## Возможности

- Чат-интерфейс через REST API и WebSocket
- Интеграция с существующими данными (проекты, контракты, материалы)
- Умный контекст на основе запросов пользователя
- Лимиты на уровне организации
- История диалогов
- Трекинг использования и расходов

## Установка

### 1. Настройка окружения

Добавьте в `.env`:

```env
# OpenAI API
OPENAI_API_KEY=sk-your-api-key-here
OPENAI_MODEL=gpt-4o-mini
OPENAI_MAX_TOKENS=2000

# AI Assistant
AI_ASSISTANT_ENABLED=true
AI_ASSISTANT_DEFAULT_LIMIT=5000
AI_ASSISTANT_CACHE_TTL=3600
```

### 2. Запуск миграций

```bash
php artisan migrate
```

### 3. Активация модуля

Модуль уже зарегистрирован в `bootstrap/providers.php`.

## API Endpoints

### POST /api/v1/ai-assistant/chat

Отправка сообщения AI-ассистенту.

**Request:**
```json
{
  "message": "Какие проекты в зоне риска?",
  "conversation_id": 123  // опционально
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "conversation_id": 123,
    "message": {
      "id": 456,
      "role": "assistant",
      "content": "Обнаружено 2 проекта в зоне риска...",
      "created_at": "2025-10-10T12:00:00.000000Z"
    },
    "tokens_used": 1250,
    "usage": {
      "monthly_limit": 5000,
      "used": 142,
      "remaining": 4858,
      "percentage_used": 2.8
    }
  }
}
```

### GET /api/v1/ai-assistant/conversations

Получить список диалогов пользователя.

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 123,
      "title": "Какие проекты в зоне риска?...",
      "created_at": "2025-10-10T12:00:00.000000Z",
      "updated_at": "2025-10-10T12:05:00.000000Z"
    }
  ]
}
```

### GET /api/v1/ai-assistant/conversations/{id}

Получить историю конкретного диалога.

**Response:**
```json
{
  "success": true,
  "data": {
    "conversation": {
      "id": 123,
      "title": "Какие проекты в зоне риска?...",
      "created_at": "2025-10-10T12:00:00.000000Z"
    },
    "messages": [
      {
        "id": 1,
        "role": "user",
        "content": "Какие проекты в зоне риска?",
        "created_at": "2025-10-10T12:00:00.000000Z"
      },
      {
        "id": 2,
        "role": "assistant",
        "content": "Обнаружено 2 проекта...",
        "tokens_used": 1250,
        "created_at": "2025-10-10T12:00:05.000000Z"
      }
    ]
  }
}
```

### DELETE /api/v1/ai-assistant/conversations/{id}

Удалить диалог.

**Response:**
```json
{
  "success": true,
  "message": "Conversation deleted"
}
```

### GET /api/v1/ai-assistant/usage

Получить статистику использования для организации.

**Response:**
```json
{
  "success": true,
  "data": {
    "monthly_limit": 5000,
    "used": 142,
    "remaining": 4858,
    "percentage_used": 2.8,
    "tokens_used": 178500,
    "cost_rub": 14.25
  }
}
```

## Использование на фронтенде (React)

### Простой запрос

```javascript
const response = await fetch('/api/v1/ai-assistant/chat', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'Authorization': `Bearer ${token}`
  },
  body: JSON.stringify({
    message: 'Какие проекты в зоне риска?'
  })
});

const data = await response.json();
console.log(data.data.message.content);
```

### С продолжением диалога

```javascript
// Первый запрос
const response1 = await fetch('/api/v1/ai-assistant/chat', {
  method: 'POST',
  headers: { /* ... */ },
  body: JSON.stringify({
    message: 'Покажи бюджет проектов'
  })
});

const { conversation_id } = await response1.json().data;

// Следующий запрос в том же диалоге
const response2 = await fetch('/api/v1/ai-assistant/chat', {
  method: 'POST',
  headers: { /* ... */ },
  body: JSON.stringify({
    message: 'А какие из них в зоне риска?',
    conversation_id: conversation_id
  })
});
```

## Архитектура

### Основные компоненты

```
AIAssistantService
├── LLMProvider (OpenAI)
├── ConversationManager (История)
├── ContextBuilder (Контекст из БД)
├── IntentRecognizer (Распознавание намерений)
├── UsageTracker (Лимиты и статистика)
└── Actions
    ├── Projects (GetProjectStatus, GetProjectBudget, AnalyzeProjectRisks)
    ├── Contracts (SearchContracts, GetContractDetails)
    └── Materials (CheckMaterialStock, ForecastMaterialNeeds)
```

### Интеграция с существующими сервисами

Модуль использует готовые сервисы из `AdvancedDashboard`:
- `ProjectsStatusWidgetProvider`
- `ProjectsBudgetWidgetProvider`
- `ProjectsRisksWidgetProvider`
- `MaterialsLowStockWidgetProvider`
- `MaterialsForecastWidgetProvider`
- И другие...

## Лимиты и биллинг

- Модуль: **₽3,990/мес** (подписка)
- Лимит по умолчанию: **5,000 запросов/месяц**
- Лимиты считаются на уровне организации
- Себестоимость: ~₽100/месяц при полном использовании лимита

## Мониторинг

Все запросы логируются через `LoggingService`:

```php
// Business logs
'ai.assistant.request'
'ai.assistant.success'

// Technical logs
'ai.openai.request'
'ai.openai.success'
'ai.openai.error'
```

## Примеры запросов

AI-ассистент понимает следующие типы запросов:

**Статус проектов:**
- "Какие проекты сейчас активны?"
- "Покажи текущие проекты"
- "Что происходит с проектами?"

**Бюджет:**
- "Сколько потрачено по проектам?"
- "Какой бюджет осталось?"
- "Покажи расходы"

**Риски:**
- "Какие проекты в зоне риска?"
- "Есть ли проблемы?"
- "Что может сорваться?"

**Контракты:**
- "Покажи контракт №45/2025"
- "Найди договоры со СтройИнвест"

**Материалы:**
- "Какие материалы заканчиваются?"
- "Хватит ли цемента?"
- "Что нужно закупить?"

## Разработка

### Добавление нового Action

1. Создайте класс в `Actions/`
2. Реализуйте метод `execute()`
3. Используйте существующие сервисы и провайдеры
4. Обновите `IntentRecognizer` при необходимости

Пример:

```php
namespace App\BusinessModules\Features\AIAssistant\Actions\Custom;

class CustomAction
{
    public function execute(int $organizationId, ?array $params = []): array
    {
        // Ваша логика
        return [
            'data' => $result
        ];
    }
}
```

## Безопасность

- Все endpoints защищены `auth:api` middleware
- Проверка принадлежности диалогов пользователю
- Лимиты на уровне организации
- Валидация всех входных данных

## Производительность

- Кеширование контекста организации (5 мин)
- Кеширование статистики использования (10 мин)
- Асинхронная обработка через очереди (опционально)
- Оптимизация токенов в контексте

## Troubleshooting

### "OpenAI API key not configured"

Проверьте `.env`:
```env
OPENAI_API_KEY=sk-...
```

### "AI_LIMIT_EXCEEDED"

Организация исчерпала месячный лимит. Можно:
1. Увеличить лимит в конфигурации модуля
2. Дождаться начала следующего месяца
3. Обновить подписку

### Медленные ответы

1. Проверьте размер контекста
2. Уменьшите `max_tokens` в конфиге
3. Используйте кеширование частых запросов

## Roadmap

- [ ] WebSocket real-time ответы
- [ ] Telegram бот интеграция
- [ ] RAG система для документов
- [ ] Function calling для действий
- [ ] Голосовой ввод
- [ ] Проактивные уведомления

