# API Документация: Верификация организаций

## Обзор

API для управления данными организации и их верификации через DaData. Включает автоматическую верификацию по ИНН и адресу, а также автокомплит для форм.

**Базовый URL:** `/api/v1/landing/`

**Авторизация:** Bearer Token (JWT)

---

## Эндпоинты управления организацией

### 1. Получение данных организации с верификацией

```http
GET /organization/verification
```

**Описание:** Получает полную информацию об организации текущего пользователя, включая статус верификации.

**Заголовки:**
```
Authorization: Bearer {jwt_token}
Content-Type: application/json
```

**Ответ (успех):**
```json
{
  "success": true,
  "message": "Данные организации получены",
  "data": {
    "organization": {
      "id": 1,
      "name": "ООО Строительная компания",
      "legal_name": "Общество с ограниченной ответственностью \"Строительная компания\"",
      "tax_number": "7707083893",
      "registration_number": "1027700132195",
      "phone": "+7(495)123-45-67",
      "email": "info@company.ru",
      "address": "г. Москва, ул. Тверская, д. 1",
      "city": "Москва",
      "postal_code": "125009",
      "country": "Россия",
      "description": "Строительные работы",
      "logo_path": null,
      "is_active": true,
      "subscription_expires_at": "2025-12-31T23:59:59.000000Z",
      "verification": {
        "is_verified": true,
        "verified_at": "2025-06-22T10:30:00.000000Z",
        "verification_status": "verified",
        "verification_status_text": "Верифицирована",
        "verification_score": 100,
        "verification_data": {
          "score": 100,
          "inn_verification": {
            "success": true,
            "message": "Организация найдена",
            "data": {
              "inn": "7707083893",
              "ogrn": "1027700132195",
              "name": "ОБЩЕСТВО С ОГРАНИЧЕННОЙ ОТВЕТСТВЕННОСТЬЮ \"СТРОИТЕЛЬНАЯ КОМПАНИЯ\"",
              "status": "ACTIVE"
            }
          },
          "address_verification": {
            "success": true,
            "message": "Адрес обработан успешно"
          }
        },
        "verification_notes": "Результат верификации: 100/100 баллов",
        "can_be_verified": true
      },
      "created_at": "2025-01-01T00:00:00.000000Z",
      "updated_at": "2025-06-22T10:30:00.000000Z"
    }
  }
}
```

**Статусы верификации:**
- `pending` - Ожидает верификации
- `verified` - Верифицирована (90-100 баллов)
- `partially_verified` - Частично верифицирована (70-89 баллов)
- `needs_review` - Требует проверки (50-69 баллов)
- `failed` - Верификация не пройдена (0-49 баллов)

---

### 2. Обновление данных организации

```http
PATCH /organization/verification
```

**Описание:** Обновляет данные организации. При наличии ИНН и адреса автоматически запускает верификацию.

**Заголовки:**
```
Authorization: Bearer {jwt_token}
Content-Type: application/json
```

**Тело запроса:**
```json
{
  "name": "ООО Новое название",
  "legal_name": "Общество с ограниченной ответственностью \"Новое название\"",
  "tax_number": "7707083893",
  "registration_number": "1027700132195",
  "phone": "+7(495)123-45-67",
  "email": "info@newcompany.ru",
  "address": "г. Москва, ул. Арбат, д. 10",
  "city": "Москва",
  "postal_code": "119019",
  "country": "Россия",
  "description": "Обновленное описание"
}
```

**Правила валидации:**
- `name` - обязательно, строка, 2-255 символов
- `legal_name` - опционально, строка, 2-255 символов
- `tax_number` - опционально, 10 или 12 цифр (ИНН)
- `registration_number` - опционально, 13 или 15 цифр (ОГРН/ОГРНИП)
- `phone` - опционально, российский формат телефона
- `email` - опционально, валидный email
- `address` - опционально, строка, 10-500 символов
- `city` - опционально, строка, 2-100 символов, только буквы
- `postal_code` - опционально, ровно 6 цифр
- `country` - опционально, строка, 2-100 символов

**Ответ (успех):**
```json
{
  "success": true,
  "message": "Данные организации обновлены",
  "data": {
    "organization": {
      // Обновленные данные организации
    }
  }
}
```

**Ответ (ошибка валидации):**
```json
{
  "success": false,
  "message": "Ошибки валидации",
  "errors": {
    "tax_number": ["ИНН должен содержать 10 цифр для организации или 12 цифр для ИП"],
    "postal_code": ["Почтовый индекс должен содержать ровно 6 цифр"]
  }
}
```

---

### 3. Получение рекомендаций по верификации

```http
GET /organization/verification/recommendations
```

**Описание:** Получает детальные рекомендации о том, какие поля нужно заполнить или исправить для улучшения верификации.

**Заголовки:**
```
Authorization: Bearer {jwt_token}
Content-Type: application/json
```

**Ответ (успех):**
```json
{
  "success": true,
  "message": "Рекомендации по верификации получены",
  "data": {
    "current_score": 70,
    "max_score": 100,
    "status": "partially_verified",
    "status_text": "Частично верифицирована",
    "missing_fields": [
      {
        "field": "legal_name",
        "name": "Полное наименование",
        "description": "Полное юридическое наименование организации",
        "weight": 15,
        "required": false
      },
      {
        "field": "registration_number",
        "name": "ОГРН",
        "description": "Основной государственный регистрационный номер",
        "weight": 15,
        "required": false
      }
    ],
    "field_issues": [
      {
        "field": "postal_code",
        "name": "Почтовый индекс",
        "description": "Почтовый индекс должен содержать ровно 6 цифр",
        "current_value": "12345",
        "weight": 10
      }
    ],
    "verification_issues": [
      {
        "type": "warning",
        "message": "Адрес не полностью соответствует данным ФИАС",
        "severity": "medium"
      }
    ],
    "can_auto_verify": true,
    "potential_score_increase": 40
  }
}
```

**Описание полей ответа:**
- `current_score` - текущая оценка верификации (0-100)
- `max_score` - максимально возможная оценка (100)
- `status` - текущий статус верификации
- `status_text` - текстовое описание статуса
- `missing_fields` - список незаполненных полей
- `field_issues` - список полей с некорректными данными
- `verification_issues` - проблемы, выявленные при верификации через DaData
- `can_auto_verify` - можно ли запустить автоматическую верификацию
- `potential_score_increase` - на сколько баллов может увеличиться оценка

**Веса полей для верификации:**
- ИНН: 70 баллов (обязательное поле)
- Адрес: 30 баллов (обязательное поле)
- Полное наименование: 15 баллов
- ОГРН: 15 баллов
- Город: 10 баллов
- Почтовый индекс: 10 баллов

**Примечание:** Email и телефон не участвуют в процессе верификации, но могут быть указаны для контактной информации.

---

### 4. Запрос верификации организации

```http
POST /organization/verification/request
```

**Описание:** Запускает процесс верификации организации через DaData API.

**Заголовки:**
```
Authorization: Bearer {jwt_token}
Content-Type: application/json
```

**Ответ (успех):**
```json
{
  "success": true,
  "message": "Верификация завершена",
  "data": {
    "verification_result": {
      "inn_verification": {
        "success": true,
        "message": "Организация найдена",
        "data": {
          "inn": "7707083893",
          "ogrn": "1027700132195",
          "name": "ОБЩЕСТВО С ОГРАНИЧЕННОЙ ОТВЕТСТВЕННОСТЬЮ \"КОМПАНИЯ\"",
          "short_name": "ООО \"КОМПАНИЯ\"",
          "legal_name": "ОБЩЕСТВО С ОГРАНИЧЕННОЙ ОТВЕТСТВЕННОСТЬЮ \"КОМПАНИЯ\"",
          "status": "ACTIVE",
          "address": "г Москва, ул Тверская, д 1",
          "management": "ИВАНОВ ИВАН ИВАНОВИЧ",
          "registration_date": "2002-07-12",
          "liquidation_date": null
        }
      },
      "address_verification": {
        "success": true,
        "message": "Адрес обработан успешно",
        "data": {
          "source": "г. Москва, ул. Тверская, д. 1",
          "result": "г Москва, ул Тверская, д 1",
          "postal_code": "125009",
          "country": "Россия",
          "region": "Москва",
          "city": "Москва",
          "street": "Тверская",
          "house": "1",
          "qc": 0
        }
      },
      "overall_status": "verified",
      "verification_score": 100,
      "errors": [],
      "warnings": []
    },
    "organization": {
      // Обновленные данные организации с верификацией
    }
  }
}
```

**Ответ (недостаточно данных):**
```json
{
  "success": false,
  "message": "Для автоматической верификации необходимо указать ИНН и адрес организации"
}
```

---

## Эндпоинты DaData API

### 4. Автокомплит организаций

```http
POST /dadata/suggest/organizations
```

**Описание:** Поиск организаций по названию или ИНН для автокомплита форм.

**Заголовки:**
```
Authorization: Bearer {jwt_token}
Content-Type: application/json
```

**Тело запроса:**
```json
{
  "query": "Сбербанк"
}
```

**Ответ:**
```json
{
  "success": true,
  "message": "Результаты поиска получены",
  "data": [
    {
      "value": "ПАО СБЕРБАНК",
      "unrestricted_value": "ПУБЛИЧНОЕ АКЦИОНЕРНОЕ ОБЩЕСТВО \"СБЕРБАНК РОССИИ\"",
      "data": {
        "inn": "7707083893",
        "ogrn": "1027700132195",
        "name": {
          "full": "ПУБЛИЧНОЕ АКЦИОНЕРНОЕ ОБЩЕСТВО \"СБЕРБАНК РОССИИ\"",
          "short": "ПАО СБЕРБАНК",
          "full_with_opf": "ПУБЛИЧНОЕ АКЦИОНЕРНОЕ ОБЩЕСТВО \"СБЕРБАНК РОССИИ\""
        },
        "address": {
          "unrestricted_value": "г Москва, ул Вавилова, д 19"
        },
        "state": {
          "status": "ACTIVE"
        }
      }
    }
  ]
}
```

---

### 5. Автокомплит адресов

```http
POST /dadata/suggest/addresses
```

**Описание:** Поиск адресов для автокомплита форм.

**Заголовки:**
```
Authorization: Bearer {jwt_token}
Content-Type: application/json
```

**Тело запроса:**
```json
{
  "query": "Москва Тверская"
}
```

**Ответ:**
```json
{
  "success": true,
  "message": "Результаты поиска адресов получены",
  "data": [
    {
      "value": "г Москва, ул Тверская",
      "unrestricted_value": "125009, г Москва, ул Тверская",
      "data": {
        "postal_code": "125009",
        "country": "Россия",
        "region": "Москва",
        "city": "Москва",
        "street": "Тверская",
        "qc": 1
      }
    }
  ]
}
```

---

### 6. Стандартизация адреса

```http
POST /dadata/clean/address
```

**Описание:** Проверка и стандартизация введённого адреса.

**Заголовки:**
```
Authorization: Bearer {jwt_token}
Content-Type: application/json
```

**Тело запроса:**
```json
{
  "address": "мск тверская 1"
}
```

**Ответ:**
```json
{
  "success": true,
  "message": "Адрес обработан успешно",
  "data": {
    "source": "мск тверская 1",
    "result": "г Москва, ул Тверская, д 1",
    "postal_code": "125009",
    "country": "Россия",
    "region": "Москва",
    "city": "Москва",
    "street": "Тверская",
    "house": "1",
    "qc": 0,
    "qc_complete": 0,
    "unparsed_parts": null
  }
}
```

**Коды качества (qc):**
- `0` - Точное соответствие
- `1` - Не все поля заполнены
- `2` - Есть предположения по дополнению
- `3` - Адрес определен не полностью
- `4` - Не распознан

---

## Коды ошибок

| Код | Описание |
|-----|----------|
| 200 | Успешно |
| 400 | Ошибка валидации или неверные параметры |
| 401 | Не авторизован |
| 404 | Организация не найдена |
| 422 | Ошибки валидации данных |
| 500 | Внутренняя ошибка сервера |

---

## Примеры использования

### Последовательность для верификации:

1. **Получить данные организации:**
   ```javascript
   const response = await fetch('/api/v1/landing/organization/verification', {
     headers: { 'Authorization': `Bearer ${token}` }
   });
   ```

2. **Обновить данные (если нужно):**
   ```javascript
   const updateResponse = await fetch('/api/v1/landing/organization/verification', {
     method: 'PATCH',
     headers: {
       'Authorization': `Bearer ${token}`,
       'Content-Type': 'application/json'
     },
     body: JSON.stringify({
       tax_number: '7707083893',
       address: 'г. Москва, ул. Тверская, д. 1'
     })
   });
   ```

3. **Запросить верификацию:**
   ```javascript
   const verifyResponse = await fetch('/api/v1/landing/organization/verification/request', {
     method: 'POST',
     headers: { 'Authorization': `Bearer ${token}` }
   });
   ```

### Автокомплит в формах:

```javascript
// Поиск организаций
const searchOrganizations = async (query) => {
  const response = await fetch('/api/v1/landing/dadata/suggest/organizations', {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({ query })
  });
  return response.json();
};

// Поиск адресов
const searchAddresses = async (query) => {
  const response = await fetch('/api/v1/landing/dadata/suggest/addresses', {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({ query })
  });
  return response.json();
};
```

---

## Рекомендации по UI/UX

### Статус верификации:
- 🟢 **Верифицирована** - зеленый значок
- 🟡 **Частично верифицирована** - желтый значок  
- 🔴 **Требует проверки** - красный значок
- ⚪ **Ожидает верификации** - серый значок

### Автокомплит:
- Показывать результаты после ввода 3+ символов
- Дебаунс 300мс для API запросов
- Выделять найденные совпадения в тексте
- Кешировать результаты на 5 минут

### Валидация:
- Валидация в реальном времени для ИНН/ОГРН
- Автоформатирование телефонов
- Подсказки по правильному формату полей 