# API Документация: Мобильное приложение "Прораб-Финанс Мост" (v1)

## Аутентификация

Все запросы к защищенным эндпоинтам мобильного API требуют аутентификации прораба.
Аутентификация происходит через JWT токен, который должен передаваться в заголовке:
`Authorization: Bearer <token>`

Для доступа к большинству эндпоинтов (кроме логина) также требуется право `can:access-mobile-app`, которое проверяется на сервере.

## Базовый URL

Все URL API начинаются с: `/api/v1/mobile` (Этот префикс **уже включен** в URL эндпоинтов ниже)

## 1. Авторизация

### 1.1. Вход пользователя (прораба)

-   **URL:** `/api/v1/mobile/auth/login`
-   **Метод:** `POST`
-   **Описание:** Аутентифицирует прораба и возвращает JWT токен.
-   **Тело запроса (JSON):**
    ```json
    {
        "email": "foreman@example.com",
        "password": "password123"
    }
    ```
-   **Успешный ответ (200 OK):**
    ```json
    {
        "success": true,
        "message": "Вход выполнен успешно",
        "data": {
            "token": "eyJ0eXAiOiJKV1Qi...",
            "user": {
                "id": 8,
                "name": "Иванов Иван",
                "email": "foreman@example.com",
                "phone": "+79011234567",
                "position": "Прораб",
                "avatar_url": "http://example.com/path/to/avatar.jpg", // Полный URL
                "current_organization_id": 1
            }
        }
    }
    ```
-   **Ошибки:**
    -   `401 Unauthorized`: Неверный email или пароль.
    -   `403 Forbidden`: У пользователя нет доступа к мобильному приложению.
    -   `422 Unprocessable Entity`: Ошибки валидации входных данных.
    -   `500 Internal Server Error`.

### 1.2. Получение данных о текущем пользователе

-   **URL:** `/api/v1/mobile/auth/me`
-   **Метод:** `GET`
-   **Заголовки:** `Authorization: Bearer <token>`
-   **Описание:** Возвращает информацию о текущем аутентифицированном пользователе (прорабе).
-   **Успешный ответ (200 OK):**
    ```json
    {
        "success": true,
        "data": { 
            "user": { 
                "id": 8,
                "name": "Иванов Иван",
                "email": "foreman@example.com",
                "phone": "+79011234567",
                "position": "Прораб",
                "avatar_url": "http://example.com/path/to/avatar.jpg",
                "current_organization_id": 1
            }
        }
    }
    ```
-   **Ошибки:** `401 Unauthorized`, `404 Not Found`.

### 1.3. Обновление JWT токена

-   **URL:** `/api/v1/mobile/auth/refresh`
-   **Метод:** `POST`
-   **Заголовки:** `Authorization: Bearer <token>`
-   **Описание:** Обновляет истекший или истекающий JWT токен.
-   **Успешный ответ (200 OK):**
    ```json
    {
        "success": true,
        "message": "Токен успешно обновлен",
        "data": {
            "token": "new_eyJ0eXAiOiJKV1Qi..."
        }
    }
    ```
-   **Ошибки:** `401 Unauthorized`.

### 1.4. Выход пользователя

-   **URL:** `/api/v1/mobile/auth/logout`
-   **Метод:** `POST`
-   **Заголовки:** `Authorization: Bearer <token>`
-   **Описание:** Инвалидирует текущий JWT токен пользователя.
-   **Успешный ответ (200 OK):**
    ```json
    {
        "success": true,
        "message": "Выход выполнен успешно"
    }
    ```
-   **Ошибки:** `401 Unauthorized`.

## 2. Проекты (Объекты)

### 2.1. Получение списка назначенных проектов

-   **URL:** `/api/v1/mobile/projects`
-   **Метод:** `GET`
-   **Заголовки:** `Authorization: Bearer <token>`
-   **Описание:** Возвращает список проектов, на которые назначен текущий прораб. (Непагинированный список).
-   **Успешный ответ (200 OK):**
    ```json
    {
        "data": [
            {
                "id": 1,
                "name": "Стройка ЖК 'Солнечный'",
                "address": "г. Город, ул. Примерная, 1"
            }
        ]
    }
    ```
-   **Ошибки:** `401 Unauthorized`, `403 Forbidden`, `500 Internal Server Error`.

## 3. Справочники

### 3.1. Получение списка видов работ

-   **URL:** `/api/v1/mobile/work-types`
-   **Метод:** `GET`
-   **Заголовки:** `Authorization: Bearer <token>`
-   **Описание:** Возвращает список активных видов работ для организации текущего прораба. (Непагинированный список).
-   **Успешный ответ (200 OK):**
    ```json
    {
        "data": [
            {
                "id": 10,
                "name": "Штукатурка стен",
                "measurement_unit_id": 1,
                "measurement_unit_name": "Квадратный метр",
                "measurement_unit_symbol": "м²"
            }
        ]
    }
    ```
-   **Ошибки:** `401`, `403`, `500`.

### 3.2. Получение списка материалов

-   **URL:** `/api/v1/mobile/materials`
-   **Метод:** `GET`
-   **Заголовки:** `Authorization: Bearer <token>`
-   **Описание:** Возвращает список активных материалов для организации текущего прораба. (Непагинированный список).
-   **Успешный ответ (200 OK):**
    ```json
    {
        "data": [
            {
                "id": 101,
                "name": "Цемент М500",
                "code": "CEM-500",
                "measurement_unit_id": 2,
                "measurement_unit_name": "Килограмм",
                "measurement_unit_symbol": "кг",
                "category": "Строительные смеси"
            }
        ]
    }
    ```
-   **Ошибки:** `401`, `403`, `500`.

### 3.3. Получение списка поставщиков

-   **URL:** `/api/v1/mobile/suppliers`
-   **Метод:** `GET`
-   **Заголовки:** `Authorization: Bearer <token>`
-   **Описание:** Возвращает список активных поставщиков для организации текущего прораба. (Непагинированный список).
-   **Успешный ответ (200 OK):**
    ```json
    {
        "data": [
            {
                "id": 25,
                "name": "ООО 'СтройСнаб'"
            }
        ]
    }
    ```
-   **Ошибки:** `401`, `403`, `500`.

## 4. Остатки Материалов

### 4.1. Получение остатков материалов по проекту

-   **URL:** `/api/v1/mobile/projects/{projectId}/material-balances`
-   **Метод:** `GET`
-   **Заголовки:** `Authorization: Bearer <token>`
-   **Параметры пути:**
    -   `projectId` (integer, required): ID проекта.
-   **Описание:** Возвращает текущие остатки материалов на указанном проекте.
-   **Успешный ответ (200 OK):**
    ```json
    {
        "data": [
            {
                "material_id": 101,
                "material_name": "Цемент М500",
                "measurement_unit_id": 2,
                "measurement_unit_symbol": "кг",
                "current_balance": 150.75
            }
        ]
    }
    ```
-   **Ошибки:** `401`, `403`, `404 Not Found`, `500`.

## 5. Логирование Операций

### 5.1. Запись лога: Приемка материалов

-   **URL:** `/api/v1/mobile/logs/material-receipts`
-   **Метод:** `POST`
-   **Заголовки:** `Authorization: Bearer <token>`, `Content-Type: multipart/form-data`.
-   **Описание:** Регистрирует операцию приемки материалов.
-   **Тело запроса (form-data):**
    -   `project_id` (integer, required)
    -   `material_id` (integer, required)
    -   `supplier_id` (integer, required)
    -   `quantity` (numeric, required, min:0.001)
    -   `usage_date` (string, required, формат: `YYYY-MM-DD`)
    -   `invoice_number` (string, nullable, max:100)
    -   `invoice_date` (string, nullable, формат: `YYYY-MM-DD`)
    -   `photo` (file, nullable, image, mimes:jpg,jpeg,png, max:5MB)
    -   `notes` (string, nullable, max:1000)
    -   `unit_price` (numeric, nullable)
    -   `total_price` (numeric, nullable)
-   **Успешный ответ (201 Created):**
    ```json
    {
        // MobileMaterialUsageLogResource
        "id": 1,
        "project_id": 1,
        "project_name": "Стройка ЖК 'Солнечный'",
        "material_id": 101,
        "material_name": "Цемент М500",
        "measurement_unit_symbol": "кг",
        "user_id": 8,
        "user_name": "Иванов Иван",
        "operation_type": "receipt",
        "quantity": 100.50,
        "usage_date": "2024-07-23",
        "supplier_id": 25,
        "supplier_name": "ООО 'СтройСнаб'",
        "document_number": "НН-001", // Ранее invoice_number, сейчас document_number в модели лога
        "invoice_date": "2024-07-22",
        "photo_url": "http://example.com/s3/material_log_photos/filename.jpg",
        "notes": "Приемка на склад объекта",
        "unit_price": 10.50,
        "total_price": 1055.25,
        "created_at": "YYYY-MM-DDTHH:mm:ss.ssssssZ",
        "updated_at": "YYYY-MM-DDTHH:mm:ss.ssssssZ"
    }
    ```
-   **Ошибки:** `401`, `403`, `422 Unprocessable Entity`, `500`.

### 5.2. Запись лога: Списание материалов

-   **URL:** `/api/v1/mobile/logs/material-write-offs`
-   **Метод:** `POST`
-   **Заголовки:** `Authorization: Bearer <token>`, `Content-Type: application/json`.
-   **Описание:** Регистрирует операцию списания материалов.
-   **Тело запроса (JSON):**
    -   `project_id` (integer, required)
    -   `material_id` (integer, required)
    -   `quantity` (numeric, required, min:0.001)
    -   `usage_date` (string, required, формат: `YYYY-MM-DD`)
    -   `work_type_id` (integer, nullable)
    -   `notes` (string, nullable, max:1000)
-   **Успешный ответ (201 Created):**
    ```json
    {
        // MobileMaterialUsageLogResource
        "id": 2,
        "project_id": 1,
        "project_name": "Стройка ЖК 'Солнечный'",
        "material_id": 101,
        "material_name": "Цемент М500",
        "measurement_unit_symbol": "кг",
        "user_id": 8,
        "user_name": "Иванов Иван",
        "operation_type": "write_off",
        "quantity": 50.0,
        "usage_date": "2024-07-24",
        "work_type_id": 10,
        "work_type_name": "Штукатурка стен",
        "photo_url": null, 
        "notes": "Списание на штукатурные работы, 1 этаж",
        "created_at": "YYYY-MM-DDTHH:mm:ss.ssssssZ",
        "updated_at": "YYYY-MM-DDTHH:mm:ss.ssssssZ"
    }
    ```
-   **Ошибки:** `401`, `403`, `400 Bad Request` (недостаточно материала), `422`, `500`.

### 5.3. Запись лога: Фиксация выполненных работ

-   **URL:** `/api/v1/mobile/logs/work-completion`
-   **Метод:** `POST`
-   **Заголовки:** `Authorization: Bearer <token>`, `Content-Type: multipart/form-data` (если передается `photo`).
-   **Описание:** Регистрирует факт выполнения работ.
-   **Тело запроса (form-data):**
    -   `project_id` (integer, required)
    -   `work_type_id` (integer, required)
    -   `quantity` (numeric, required, min:0.001)
    -   `completion_date` (string, required, формат: `YYYY-MM-DD`)
    -   `performers_description` (string, nullable, max:500)
    -   `photo` (file, nullable, image, mimes:jpg,jpeg,png, max:5MB)
    -   `notes` (string, nullable, max:1000)
    -   `unit_price` (numeric, nullable)
    -   `total_price` (numeric, nullable)
-   **Успешный ответ (201 Created):**
    ```json
    {
        // MobileWorkCompletionLogResource
        "id": 3,
        "project_id": 1,
        "project_name": "Стройка ЖК 'Солнечный'",
        "work_type_id": 10,
        "work_type_name": "Штукатурка стен",
        "measurement_unit_symbol": "м²",
        "user_id": 8,
        "user_name": "Иванов Иван",
        "quantity": 120.5,
        "completion_date": "2024-07-25",
        "performers_description": "Бригада №1, Сидоров А.А.",
        "photo_url": "http://example.com/s3/work_completion_photos/another_file.jpg",
        "notes": "Выполнена штукатурка стен в квартире 5",
        "unit_price": 500.00,
        "total_price": 60250.00,
        "created_at": "YYYY-MM-DDTHH:mm:ss.ssssssZ",
        "updated_at": "YYYY-MM-DDTHH:mm:ss.ssssssZ"
    }
    ```
-   **Ошибки:** `401`, `403`, `422`, `500`.

## 6. Важные замечания для мобильной разработки

-   **Загрузка файлов (фото):** При отправке фото используйте `Content-Type: multipart/form-data`. Поля, не являющиеся файлами, также должны быть частью form-data.
-   **Остатки материалов:** Перед списанием материала мобильное приложение **должно** запросить актуальные остатки по проекту (`/api/v1/mobile/projects/{projectId}/material-balances`) и не позволять пользователю вводить количество больше доступного. Серверная валидация также присутствует.
-   **Офлайн-режим:** Текущая версия API не предоставляет специальных эндпоинтов для пакетной синхронизации. Мобильное приложение должно самостоятельно накапливать операции в офлайн-режиме и отправлять их на соответствующие эндпоинты (`/api/v1/mobile/logs/...`) при восстановлении соединения. Рекомендуется реализовать механизм предотвращения дублирования записей (например, по уникальному ID операции, генерируемому на клиенте и передаваемому на сервер для проверки).
-   **Даты:** Все даты передаются и принимаются в формате `YYYY-MM-DD`.
-   **Ошибки валидации (422 Unprocessable Entity):** Ответ будет содержать стандартную структуру Laravel:
    ```json
    {
        "message": "The given data was invalid.", // Или кастомное сообщение из FormRequest
        "errors": {
            "field_name": ["Сообщение об ошибке для поля"]
        }
    }
    ```
-   **Общие ошибки (400, 401, 403, 404, 500):** Ответ будет иметь структуру:
    ```json
    {
        "success": false,
        "message": "Текст ошибки"
        // "data": null (может отсутствовать или быть null)
    }
    ``` 