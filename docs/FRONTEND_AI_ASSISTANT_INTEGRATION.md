# Инструкция по интеграции AI-Ассистента для фронтенд-разработчика

## 📋 Общая концепция

Необходимо реализовать **плавающий чат-ассистент** в виде шарика в правом нижнем углу экрана. При клике открывается чат с AI-помощником.

## 🎨 UI/UX Требования

### Внешний вид

**Плавающая кнопка (FAB - Floating Action Button):**
- **Позиция:** Правый нижний угол экрана (fixed position)
- **Отступы:** ~24px от краев
- **Размер:** 56x56px или 64x64px
- **Иконка:** Иконка бота/чата (например, `💬` или bot icon)
- **Цвет:** Акцентный цвет вашей темы (синий/фиолетовый)
- **z-index:** Высокий (999 или выше), чтобы был поверх всего контента
- **Тень:** Material Design shadow для объема
- **Анимация:** Плавное появление при загрузке страницы

**Окно чата:**
- **Размер:** 400px ширина × 600px высота (desktop)
- **Позиция:** Справа внизу, над кнопкой
- **Mobile:** На весь экран или 90% высоты
- **Компоненты:**
  - Заголовок с названием "AI Ассистент" и кнопкой закрытия
  - Область сообщений (скролл)
  - Поле ввода внизу
  - Индикатор "AI думает..." при загрузке

### Поведение

1. **При клике на шарик:**
   - Открывается окно чата с плавной анимацией (slide-up или fade-in)
   - Шарик остается видимым или превращается в иконку закрытия

2. **При отправке сообщения:**
   - Сообщение пользователя добавляется в чат
   - Показывается индикатор загрузки "AI думает..."
   - После получения ответа - добавляется сообщение от AI
   - Автоскролл к последнему сообщению

3. **При закрытии:**
   - Окно скрывается с анимацией
   - История диалога сохраняется (conversation_id)
   - При повторном открытии - загружается история

---

## 🔌 API Endpoints

### Базовый URL

**Для Личного кабинета (ЛК):**
```
https://your-domain.com/api/v1/ai-assistant
```

**Для Админ-панели:**
```
https://your-domain.com/api/v1/admin/ai-assistant
```

### Авторизация

**Для ЛК:**
```
Authorization: Bearer YOUR_ACCESS_TOKEN
```

**Для Админ-панели:**
```
Authorization: Bearer YOUR_ADMIN_ACCESS_TOKEN
```

> **Примечание:** API endpoints идентичны для обоих интерфейсов, отличается только префикс (`/api/v1/ai-assistant` vs `/api/v1/admin/ai-assistant`)

---

## 📡 API Методы

### 1. Отправить сообщение AI

**Endpoint:** 
- ЛК: `POST /api/v1/ai-assistant/chat`
- Админ: `POST /api/v1/admin/ai-assistant/chat`

**Описание:** Отправляет сообщение AI и получает ответ. Создает новый диалог или продолжает существующий.

**Request Body:**
```json
{
  "message": "Какие проекты в зоне риска?",
  "conversation_id": 123  // опционально, для продолжения диалога
}
```

**Response (Success - 200):**
```json
{
  "success": true,
  "data": {
    "conversation_id": 123,
    "message": {
      "id": 456,
      "role": "assistant",
      "content": "Обнаружено 2 проекта в зоне риска:\n\n1. **ЖК «Северный»** - просрочка на 5 дней...",
      "tokens_used": 1250,
      "created_at": "2025-10-10T12:00:00.000000Z"
    },
    "usage": {
      "monthly_limit": 5000,
      "used": 142,
      "remaining": 4858,
      "percentage_used": 2.8
    }
  }
}
```

**Response (Error - 422):**
```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "message": ["Поле message обязательно для заполнения"]
  }
}
```

**Response (Error - 429 - Лимит исчерпан):**
```json
{
  "success": false,
  "error": "AI_LIMIT_EXCEEDED",
  "message": "Месячный лимит запросов исчерпан",
  "data": {
    "limit": 5000,
    "used": 5000,
    "reset_at": "2025-11-01T00:00:00.000000Z"
  }
}
```

---

### 2. Получить список диалогов

**Endpoint:** 
- ЛК: `GET /api/v1/ai-assistant/conversations`
- Админ: `GET /api/v1/admin/ai-assistant/conversations`

**Описание:** Получить список всех диалогов текущего пользователя (последние сверху).

**Query Parameters:**
- `page` (опционально) - номер страницы (по умолчанию 1)
- `per_page` (опционально) - количество на странице (по умолчанию 15)

**Request Example:**
```
GET /api/v1/ai-assistant/conversations?page=1&per_page=10
```

**Response (Success - 200):**
```json
{
  "success": true,
  "data": [
    {
      "id": 123,
      "title": "Какие проекты в зоне риска?...",
      "created_at": "2025-10-10T12:00:00.000000Z",
      "updated_at": "2025-10-10T12:05:00.000000Z"
    },
    {
      "id": 122,
      "title": "Покажи бюджет проектов...",
      "created_at": "2025-10-09T15:30:00.000000Z",
      "updated_at": "2025-10-09T15:35:00.000000Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 10,
    "total": 25
  }
}
```

---

### 3. Получить историю диалога

**Endpoint:** 
- ЛК: `GET /api/v1/ai-assistant/conversations/{id}`
- Админ: `GET /api/v1/admin/ai-assistant/conversations/{id}`

**Описание:** Получить полную историю конкретного диалога со всеми сообщениями.

**Request Example:**
```
GET /api/v1/ai-assistant/conversations/123
```

**Response (Success - 200):**
```json
{
  "success": true,
  "data": {
    "conversation": {
      "id": 123,
      "title": "Какие проекты в зоне риска?...",
      "created_at": "2025-10-10T12:00:00.000000Z",
      "updated_at": "2025-10-10T12:05:00.000000Z"
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
        "content": "Обнаружено 2 проекта в зоне риска...",
        "tokens_used": 1250,
        "created_at": "2025-10-10T12:00:05.000000Z"
      },
      {
        "id": 3,
        "role": "user",
        "content": "Расскажи подробнее про первый проект",
        "created_at": "2025-10-10T12:01:00.000000Z"
      },
      {
        "id": 4,
        "role": "assistant",
        "content": "ЖК «Северный» находится в зоне риска по следующим причинам...",
        "tokens_used": 980,
        "created_at": "2025-10-10T12:01:04.000000Z"
      }
    ]
  }
}
```

**Response (Error - 404):**
```json
{
  "success": false,
  "message": "Диалог не найден"
}
```

---

### 4. Удалить диалог

**Endpoint:** 
- ЛК: `DELETE /api/v1/ai-assistant/conversations/{id}`
- Админ: `DELETE /api/v1/admin/ai-assistant/conversations/{id}`

**Описание:** Удалить диалог и всю его историю.

**Request Example:**
```
DELETE /api/v1/ai-assistant/conversations/123
```

**Response (Success - 200):**
```json
{
  "success": true,
  "message": "Conversation deleted"
}
```

**Response (Error - 404):**
```json
{
  "success": false,
  "message": "Диалог не найден"
}
```

---

### 5. Статистика использования

**Endpoint:** 
- ЛК: `GET /api/v1/ai-assistant/usage`
- Админ: `GET /api/v1/admin/ai-assistant/usage`

**Описание:** Получить статистику использования AI-ассистента для организации (текущий месяц).

**Request Example:**
```
GET /api/v1/ai-assistant/usage
```

**Response (Success - 200):**
```json
{
  "success": true,
  "data": {
    "monthly_limit": 5000,
    "used": 142,
    "remaining": 4858,
    "percentage_used": 2.8,
    "tokens_used": 178500,
    "cost_rub": 71.40
  }
}
```

---

## 💡 Рекомендации и напутствия

### 1. Управление состоянием

**Рекомендуется использовать:**
- **React Context** или **Zustand** для глобального состояния чата
- **React Query / SWR** для кеширования и управления API запросами

**Пример структуры состояния:**
```typescript
interface ChatState {
  isOpen: boolean;
  currentConversationId: number | null;
  messages: Message[];
  isLoading: boolean;
  conversations: Conversation[];
}
```

### 2. Оптимизация UX

**Обязательно:**
- ✅ Показывать индикатор загрузки при отправке сообщения
- ✅ Автоскролл к последнему сообщению
- ✅ Сохранять `conversation_id` для продолжения диалога
- ✅ Обрабатывать ошибки (особенно лимит запросов)
- ✅ Показывать процент использования лимита

**Желательно:**
- 📌 Markdown рендеринг в ответах AI (используйте `react-markdown`)
- 📌 Кнопка "Новый диалог" для начала свежего разговора
- 📌 Список предыдущих диалогов (sidebar)
- 📌 Копирование ответов в буфер обмена
- 📌 Подсветка кода в ответах

### 3. Обработка ошибок

**Типичные ошибки:**

| Код | Ошибка | Что показать пользователю |
|-----|--------|---------------------------|
| 401 | Unauthorized | "Необходимо войти в систему" |
| 422 | Validation Error | Показать ошибки валидации |
| 429 | Rate Limit | "Лимит запросов исчерпан. Попробуйте завтра или обновите тариф" |
| 500 | Server Error | "Произошла ошибка. Попробуйте позже" |

### 4. Производительность

**Оптимизации:**
- Ленивая загрузка компонента чата (React.lazy)
- Виртуализация списка сообщений для длинных диалогов (react-window)
- Debounce для поля ввода (если будет автодополнение)

### 5. Accessibility (A11y)

- ♿ Поддержка клавиатурной навигации (Enter для отправки)
- ♿ ARIA-labels для кнопок
- ♿ Правильная семантика HTML
- ♿ Focus management при открытии/закрытии

---

## 🎯 Примеры кода

### Базовый React компонент (TypeScript)

```typescript
import React, { useState } from 'react';
import axios from 'axios';

interface Message {
  id: number;
  role: 'user' | 'assistant';
  content: string;
  created_at: string;
}

const AIAssistant: React.FC = () => {
  const [isOpen, setIsOpen] = useState(false);
  const [messages, setMessages] = useState<Message[]>([]);
  const [input, setInput] = useState('');
  const [isLoading, setIsLoading] = useState(false);
  const [conversationId, setConversationId] = useState<number | null>(null);

  const sendMessage = async () => {
    if (!input.trim()) return;

    const userMessage = {
      id: Date.now(),
      role: 'user' as const,
      content: input,
      created_at: new Date().toISOString(),
    };

    setMessages([...messages, userMessage]);
    setInput('');
    setIsLoading(true);

    try {
      const response = await axios.post('/api/v1/ai-assistant/chat', {
        message: input,
        conversation_id: conversationId,
      });

      const { conversation_id, message } = response.data.data;
      
      setConversationId(conversation_id);
      setMessages((prev) => [...prev, message]);
    } catch (error) {
      console.error('AI Error:', error);
      // Показать уведомление об ошибке
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <>
      {/* Плавающая кнопка */}
      <button
        className="fixed bottom-6 right-6 w-14 h-14 bg-blue-600 rounded-full shadow-lg hover:bg-blue-700"
        onClick={() => setIsOpen(!isOpen)}
      >
        💬
      </button>

      {/* Окно чата */}
      {isOpen && (
        <div className="fixed bottom-24 right-6 w-96 h-[600px] bg-white rounded-lg shadow-2xl flex flex-col">
          {/* Заголовок */}
          <div className="p-4 border-b flex justify-between items-center">
            <h3 className="font-semibold">AI Ассистент</h3>
            <button onClick={() => setIsOpen(false)}>✕</button>
          </div>

          {/* Сообщения */}
          <div className="flex-1 overflow-y-auto p-4 space-y-4">
            {messages.map((msg) => (
              <div
                key={msg.id}
                className={`flex ${msg.role === 'user' ? 'justify-end' : 'justify-start'}`}
              >
                <div
                  className={`max-w-[80%] p-3 rounded-lg ${
                    msg.role === 'user'
                      ? 'bg-blue-600 text-white'
                      : 'bg-gray-100 text-gray-900'
                  }`}
                >
                  {msg.content}
                </div>
              </div>
            ))}
            {isLoading && (
              <div className="flex justify-start">
                <div className="bg-gray-100 p-3 rounded-lg">
                  AI думает...
                </div>
              </div>
            )}
          </div>

          {/* Поле ввода */}
          <div className="p-4 border-t">
            <div className="flex gap-2">
              <input
                type="text"
                value={input}
                onChange={(e) => setInput(e.target.value)}
                onKeyPress={(e) => e.key === 'Enter' && sendMessage()}
                placeholder="Задайте вопрос..."
                className="flex-1 px-3 py-2 border rounded-lg"
                disabled={isLoading}
              />
              <button
                onClick={sendMessage}
                disabled={isLoading || !input.trim()}
                className="px-4 py-2 bg-blue-600 text-white rounded-lg disabled:opacity-50"
              >
                ➤
              </button>
            </div>
          </div>
        </div>
      )}
    </>
  );
};

export default AIAssistant;
```

---

## 🚀 Интеграция в проект

### Шаг 1: Добавить компонент

```typescript
// App.tsx или Layout.tsx
import AIAssistant from './components/AIAssistant';

function App() {
  return (
    <>
      {/* Ваш основной контент */}
      <MainContent />
      
      {/* AI Ассистент - будет на всех страницах */}
      <AIAssistant />
    </>
  );
}
```

### Шаг 2: Настроить axios

```typescript
// api/client.ts
import axios from 'axios';

const apiClient = axios.create({
  baseURL: process.env.REACT_APP_API_URL || 'http://localhost:8000/api/v1',
  headers: {
    'Content-Type': 'application/json',
  },
});

// Добавить токен автоматически
apiClient.interceptors.request.use((config) => {
  const token = localStorage.getItem('authToken');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

export default apiClient;
```

---

## 📚 Полезные библиотеки

**Рекомендуемые:**
- **react-markdown** - рендеринг Markdown в ответах AI
- **react-syntax-highlighter** - подсветка кода
- **framer-motion** - анимации для чата
- **react-query** - управление API запросами
- **zustand** - легковесное управление состоянием

**Пример установки:**
```bash
npm install react-markdown react-syntax-highlighter framer-motion @tanstack/react-query zustand
```

---

## ✅ Чек-лист готовности

- [ ] Плавающая кнопка в правом нижнем углу
- [ ] Открытие/закрытие окна чата
- [ ] Отправка сообщений через POST /chat
- [ ] Отображение ответов AI
- [ ] Индикатор загрузки "AI думает..."
- [ ] Сохранение conversation_id для продолжения диалога
- [ ] Автоскролл к новым сообщениям
- [ ] Обработка ошибок (валидация, лимиты)
- [ ] Адаптивность для мобильных устройств
- [ ] Markdown рендеринг (опционально)

---

## 🆘 Поддержка

При возникновении вопросов или проблем:
1. Проверьте console браузера на ошибки
2. Проверьте Network tab в DevTools
3. Убедитесь, что токен авторизации передается
4. Проверьте формат тел запросов

**Удачи в разработке! 🚀**

