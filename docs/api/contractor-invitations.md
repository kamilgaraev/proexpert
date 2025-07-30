# API Документация: Система приглашений подрядчиков

## Обзор

Система приглашений подрядчиков позволяет организациям находить и приглашать других зарегистрированных компаний для сотрудничества в качестве подрядчиков. Система включает:

- Поиск и фильтрацию организаций
- Отправку приглашений с лимитами по подписке
- Принятие/отклонение приглашений
- Автоматическое создание взаимных подрядных связей
- Уведомления и синхронизацию данных

## Базовые URL

- **Админка**: `api/v1/admin/`
- **Личный кабинет**: `api/v1/landing/`

## Аутентификация

Все защищенные эндпоинты требуют JWT токен в заголовке:
```
Authorization: Bearer {jwt_token}
```

## Админка (API для поиска и отправки приглашений)

### 1. Поиск организаций

**Эндпоинт**: `GET /admin/organizations/search`

**Описание**: Поиск доступных для приглашения организаций с фильтрами и сортировкой.

**Параметры запроса**:
```json
{
  "search": "string|nullable|max:255", // Поиск по названию, городу
  "city": "string|nullable|max:100",   // Фильтр по городу
  "country": "string|nullable|max:100", // Фильтр по стране
  "verified": "boolean|nullable",       // Только верифицированные
  "exclude_invited": "boolean|nullable", // Исключить уже приглашенных
  "exclude_existing_contractors": "boolean|nullable", // Исключить существующих подрядчиков
  "sort_by": "string|nullable|in:relevance,name,city,connections,verified",
  "per_page": "integer|nullable|min:1|max:50"
}
```

**Пример запроса**:
```bash
GET /api/v1/admin/organizations/search?search=строитель&city=Москва&verified=true&per_page=20&sort_by=relevance
```

**Ответ**:
```json
{
  "success": true,
  "data": [
    {
      "id": 123,
      "name": "ООО Строитель",
      "legal_name": "Общество с ограниченной ответственностью Строитель",
      "city": "Москва",
      "country": "Россия",
      "is_verified": true,
      "is_active": true,
      "contractor_connections_count": 15,
      "availability_status": {
        "can_invite": true,
        "existing_invitation": null,
        "existing_contractor": null,
        "reverse_invitation": null,
        "is_mutual": false
      }
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 3,
    "per_page": 20,
    "total": 58,
    "filters": {
      "search": "строитель",
      "city": "Москва",
      "verified": true
    },
    "sort_by": "relevance"
  }
}
```

### 2. Автодополнение организаций

**Эндпоинт**: `GET /admin/organizations/suggestions`

**Описание**: Быстрые подсказки для поля поиска организаций.

**Параметры запроса**:
```json
{
  "query": "string|required|min:2|max:100", // Поисковая строка
  "limit": "integer|nullable|min:1|max:20"  // Количество результатов
}
```

**Пример запроса**:
```bash
GET /api/v1/admin/organizations/suggestions?query=стро&limit=10
```

**Ответ**:
```json
{
  "success": true,
  "data": [
    {
      "id": 123,
      "name": "ООО Строитель",
      "city": "Москва",
      "is_verified": true
    },
    {
      "id": 124,
      "name": "Стройкомплект",
      "city": "Санкт-Петербург",
      "is_verified": false
    }
  ]
}
```

### 3. Рекомендации организаций

**Эндпоинт**: `GET /admin/organizations/recommendations`

**Описание**: Рекомендуемые организации на основе региона и активности.

**Параметры запроса**:
```json
{
  "limit": "integer|nullable|min:1|max:20" // Количество рекомендаций
}
```

**Ответ**:
```json
{
  "success": true,
  "data": [
    {
      "id": 125,
      "name": "Местный Подрядчик",
      "city": "Москва", // Тот же город что у текущей организации
      "is_verified": true,
      "contractor_connections_count": 8,
      "availability_status": {
        "can_invite": true,
        "existing_invitation": null,
        "existing_contractor": null,
        "reverse_invitation": null,
        "is_mutual": false
      }
    }
  ]
}
```

### 4. Проверка доступности организации

**Эндпоинт**: `GET /admin/organizations/{id}/availability`

**Описание**: Проверка возможности пригласить конкретную организацию.

**Ответ**:
```json
{
  "success": true,
  "data": {
    "can_invite": true,
    "existing_invitation": null, // или объект приглашения
    "existing_contractor": null, // или объект подрядчика
    "reverse_invitation": null,  // или обратное приглашение
    "is_mutual": false
  }
}
```

### 5. Отправка приглашения

**Эндпоинт**: `POST /admin/contractor-invitations`

**Описание**: Отправка приглашения организации для сотрудничества.

**Тело запроса**:
```json
{
  "invited_organization_id": 123, // ID приглашаемой организации (required)
  "message": "Приглашаем к сотрудничеству по проекту...", // Сообщение (optional, max:1000)
  "metadata": { // Дополнительные данные (optional)
    "project_type": "строительство",
    "budget_range": "1-5 млн"
  }
}
```

**Ответ при успехе**:
```json
{
  "success": true,
  "data": {
    "id": 456,
    "status": "pending",
    "invitation_message": "Приглашаем к сотрудничеству...",
    "expires_at": "2024-01-15T12:00:00.000000Z",
    "created_at": "2024-01-08T12:00:00.000000Z",
    "is_expired": false,
    "can_be_accepted": true,
    "invitation_url": "https://app.com/contractor-invitations/abc123token",
    "invited_organization": {
      "id": 123,
      "name": "ООО Строитель",
      "city": "Москва",
      "is_verified": true
    },
    "invited_by": {
      "id": 1,
      "name": "Иван Иванов",
      "email": "ivan@company.com"
    }
  },
  "message": "Приглашение успешно отправлено"
}
```

**Ошибки**:
```json
{
  "success": false,
  "message": "Достигнут лимит приглашений подрядчиков по вашему тарифному плану"
}
```

### 6. Список приглашений

**Эндпоинт**: `GET /admin/contractor-invitations`

**Описание**: Получение списка отправленных или полученных приглашений.

**Параметры запроса**:
```json
{
  "type": "string|in:sent,received", // Тип приглашений (по умолчанию sent)
  "status": "string|nullable|in:pending,accepted,declined,expired",
  "date_from": "date|nullable", // Фильтр по дате от
  "date_to": "date|nullable",   // Фильтр по дате до
  "per_page": "integer|nullable|min:1|max:50"
}
```

**Ответ**:
```json
{
  "success": true,
  "data": {
    "data": [
      {
        "id": 456,
        "status": "pending",
        "invitation_message": "Приглашаем к сотрудничеству...",
        "expires_at": "2024-01-15T12:00:00.000000Z",
        "created_at": "2024-01-08T12:00:00.000000Z",
        "invited_organization": {
          "id": 123,
          "name": "ООО Строитель",
          "city": "Москва"
        }
      }
    ],
    "pagination": {
      "current_page": 1,
      "last_page": 2,
      "per_page": 15,
      "total": 23,
      "has_more_pages": true
    },
    "meta": {
      "count_by_status": {
        "pending": 8,
        "accepted": 12,
        "declined": 2,
        "expired": 1
      }
    }
  },
  "meta": {
    "type": "sent",
    "filters": {}
  }
}
```

### 7. Детали приглашения

**Эндпоинт**: `GET /admin/contractor-invitations/{id}`

**Ответ**:
```json
{
  "success": true,
  "data": {
    "id": 456,
    "status": "accepted",
    "invitation_message": "Приглашаем к сотрудничеству...",
    "expires_at": "2024-01-15T12:00:00.000000Z",
    "created_at": "2024-01-08T12:00:00.000000Z",
    "accepted_at": "2024-01-09T14:30:00.000000Z",
    "invited_organization": {
      "id": 123,
      "name": "ООО Строитель",
      "legal_name": "Общество с ограниченной ответственностью Строитель",
      "city": "Москва",
      "is_verified": true
    },
    "contractor": {
      "id": 789,
      "name": "ООО Строитель",
      "connected_at": "2024-01-09T14:30:00.000000Z",
      "last_sync_at": "2024-01-10T10:00:00.000000Z"
    }
  }
}
```

### 8. Отмена приглашения

**Эндпоинт**: `PATCH /admin/contractor-invitations/{id}/cancel`

**Описание**: Отмена отправленного приглашения (только pending статус).

**Ответ**:
```json
{
  "success": true,
  "message": "Приглашение отменено"
}
```

### 9. Статистика приглашений

**Эндпоинт**: `GET /admin/contractor-invitations/stats`

**Ответ**:
```json
{
  "success": true,
  "data": {
    "sent": {
      "total": 25,
      "pending": 5,
      "accepted": 18,
      "declined": 2
    },
    "received": {
      "total": 12,
      "pending": 2,
      "accepted": 8,
      "declined": 2
    }
  }
}
```

## Личный кабинет (API для работы с входящими приглашениями)

### 1. Список входящих приглашений

**Эндпоинт**: `GET /landing/contractor-invitations`

**Параметры запроса**:
```json
{
  "status": "string|nullable|in:pending,accepted,declined,expired",
  "date_from": "date|nullable",
  "date_to": "date|nullable",
  "per_page": "integer|nullable|min:1|max:50"
}
```

**Ответ**:
```json
{
  "success": true,
  "data": {
    "data": [
      {
        "id": 456,
        "status": "pending",
        "invitation_message": "Приглашаем к сотрудничеству...",
        "expires_at": "2024-01-15T12:00:00.000000Z",
        "created_at": "2024-01-08T12:00:00.000000Z",
        "is_expired": false,
        "can_be_accepted": true,
        "from_organization": {
          "id": 789,
          "name": "Заказчик Строй",
          "legal_name": "ООО Заказчик Строй",
          "city": "Москва",
          "is_verified": true,
          "description": "Строительная компания с 15-летним опытом",
          "logo_path": "/logos/customer-logo.png"
        },
        "invited_by": {
          "name": "Петр Петров",
          "email": "petr@customer.com"
        }
      }
    ],
    "pagination": {
      "current_page": 1,
      "last_page": 1,
      "per_page": 15,
      "total": 8,
      "has_more_pages": false
    }
  },
  "meta": {
    "type": "received",
    "filters": {}
  }
}
```

### 2. Детали приглашения по токену

**Эндпоинт**: `GET /landing/contractor-invitations/{token}`

**Описание**: Получение деталей приглашения по публичному токену (для перехода по ссылке из email).

**Ответ**:
```json
{
  "success": true,
  "data": {
    "id": 456,
    "token": "abc123token456def",
    "status": "pending",
    "invitation_message": "Приглашаем к долгосрочному сотрудничеству по строительным проектам...",
    "expires_at": "2024-01-15T12:00:00.000000Z",
    "created_at": "2024-01-08T12:00:00.000000Z",
    "is_expired": false,
    "can_be_accepted": true,
    "from_organization": {
      "id": 789,
      "name": "Заказчик Строй",
      "legal_name": "ООО Заказчик Строй",
      "city": "Москва",
      "is_verified": true,
      "description": "Строительная компания с опытом работы более 15 лет...",
      "logo_path": "/logos/customer-logo.png"
    },
    "invited_by": {
      "name": "Петр Петров",
      "email": "petr@customer.com"
    },
    "metadata": {
      "project_type": "строительство",
      "budget_range": "1-5 млн"
    }
  }
}
```

**Ошибка при истекшем приглашении**:
```json
{
  "success": false,
  "message": "Срок действия приглашения истек"
}
```

### 3. Принятие приглашения

**Эндпоинт**: `POST /landing/contractor-invitations/{token}/accept`

**Описание**: Принятие приглашения и создание взаимных подрядных связей.

**Ответ при успехе**:
```json
{
  "success": true,
  "data": {
    "contractor": {
      "id": 999,
      "name": "Заказчик Строй",
      "connected_at": "2024-01-09T14:30:00.000000Z"
    },
    "message": "Приглашение принято. Теперь вы можете работать с данной организацией как подрядчик."
  }
}
```

**Ошибки**:
```json
{
  "success": false,
  "message": "Приглашение недействительно или истекло"
}
```

### 4. Отклонение приглашения

**Эндпоинт**: `POST /landing/contractor-invitations/{token}/decline`

**Тело запроса** (опциональное):
```json
{
  "reason": "Не подходит профиль деятельности" // max:500 символов
}
```

**Ответ**:
```json
{
  "success": true,
  "message": "Приглашение отклонено"
}
```

### 5. Статистика для личного кабинета

**Эндпоинт**: `GET /landing/contractor-invitations/stats`

**Ответ**:
```json
{
  "success": true,
  "data": {
    "received_invitations": {
      "total": 12,
      "pending": 2,
      "accepted": 8,
      "declined": 2
    },
    "sent_invitations": {
      "total": 5,
      "pending": 1,
      "accepted": 3,
      "declined": 1
    }
  }
}
```

## Коды ошибок

### HTTP статус коды
- `200` - Успешный запрос
- `201` - Ресурс создан
- `400` - Ошибка валидации запроса
- `401` - Требуется аутентификация
- `403` - Доступ запрещен / лимиты превышены
- `404` - Ресурс не найден
- `410` - Ресурс истек (для приглашений)
- `500` - Внутренняя ошибка сервера

### Специфичные ошибки системы
- `SUBSCRIPTION_LIMIT_EXCEEDED` - Превышен лимит приглашений по подписке
- `INVITATION_EXPIRED` - Приглашение истекло
- `INVITATION_ALREADY_PROCESSED` - Приглашение уже обработано
- `ORGANIZATION_NOT_AVAILABLE` - Организация недоступна для приглашения
- `DUPLICATE_INVITATION` - Активное приглашение уже существует

## Лимиты и ограничения

### Лимиты по подписке (месячные)
- **Бесплатный тариф**: 5 приглашений
- **Базовый тариф**: 25 приглашений  
- **Премиум тариф**: 100 приглашений
- **Корпоративный**: без ограничений

### Временные ограничения
- **Срок действия приглашения**: 7 дней
- **Кэширование поиска**: 10 минут
- **Кэширование статистики**: 15 минут
- **Bulk операции**: до 100 организаций за раз

### Технические лимиты
- **Поиск**: до 50 результатов на страницу
- **Сообщение в приглашении**: до 1000 символов
- **Метаданные**: до 10 полей по 255 символов
- **Причина отклонения**: до 500 символов

## Уведомления

### Email уведомления
Автоматически отправляются при создании приглашения всем владельцам приглашаемой организации:
- Красивый HTML шаблон с информацией о приглашающей организации
- Прямая ссылка для принятия/отклонения
- Предупреждение о сроке действия
- Описание преимуществ сотрудничества

### Push уведомления в базе данных
Сохраняются в таблице `notifications` для отображения в интерфейсе приложения.

## Синхронизация данных

При принятии приглашения автоматически:
1. Создается подрядчик в организации-отправителе
2. Создается обратный подрядчик в принявшей организации  
3. Настраивается автосинхронизация контактных данных (каждые 24 часа)
4. Отправляются уведомления обеим сторонам

Синхронизируемые поля: `name`, `phone`, `email`, `legal_address`.

## Мониторинг и аналитика

### Логирование
Все критичные операции логируются:
- Создание/принятие/отклонение приглашений
- Ошибки валидации и бизнес-логики
- Превышение лимитов подписки
- Проблемы с отправкой уведомлений

### Метрики для мониторинга
- Количество активных приглашений
- Конверсия приглашений в подрядчиков
- Время ответа API эндпоинтов
- Использование лимитов по организациям