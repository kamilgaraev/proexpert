# Документация API: Подотчетные средства и Интеграция (Admin Panel)

**Базовый путь:** `/api/v1/admin`

**Аутентификация:** Требуется JWT токен (`Bearer <token>`) в заголовке `Authorization`. Токен должен быть для `api_admin`.

**Авторизация:** Все эндпоинты требуют прав доступа к админ-панели (`can:access-admin-panel`). Конкретные права для операций (создание, редактирование, утверждение) проверяются дополнительно через политики или middleware.

**Контекст организации:** Все запросы выполняются в контексте текущей организации пользователя (`Auth::user()->current_organization_id`), который определяется автоматически middleware.

---

## 1. Транзакции подотчетных средств (`/advance-transactions`)

Эти эндпоинты управляются контроллером `App\Http\Controllers\Api\V1\Admin\AdvanceAccountTransactionController`.

### 1.1. Получение списка доступных пользователей

*   **Эндпоинт:** `GET /advance-transactions/available-users`
*   **Метод контроллера:** `getAvailableUsers`
*   **Описание:** Возвращает список пользователей текущей организации, которым можно выдавать подотчетные средства (с ролями прораб, начальник участка и т.д.). Используется для заполнения полей выбора.
*   **Параметры запроса (Query):**
    *   `search` (string, опционально): Строка для поиска по имени или должности пользователя.
*   **Успешный ответ (200):**
    ```json
    {
      "data": [
        {
          "id": 45,
          "name": "Иванов Иван",
          "current_balance": 5000.00,
          "has_overdue_balance": false,
          "position": "Прораб",
          "avatar_path": "path/to/avatar.jpg", 
          "avatar_url": "http://.../storage/path/to/avatar.jpg" 
        }
      ]
    }
    ```

### 1.2. Получение списка доступных проектов

*   **Эндпоинт:** `GET /advance-transactions/available-projects`
*   **Метод контроллера:** `getAvailableProjects`
*   **Описание:** Возвращает список активных проектов текущей организации. Используется для заполнения полей выбора.
*   **Параметры запроса (Query):**
    *   `user_id` (integer, опционально): Фильтр по проектам, назначенным конкретному пользователю.
    *   `search` (string, опционально): Строка для поиска по названию, адресу или внешнему коду проекта.
*   **Успешный ответ (200):**
    ```json
    {
      "data": [
        {
          "id": 12,
          "name": "ЖК Солнечный",
          "external_code": "PRJ-123",
          "status": "active",
          "address": "г. Город, ул. Солнечная, 1"
        }
      ]
    }
    ```

### 1.3. Получение списка транзакций

*   **Эндпоинт:** `GET /advance-transactions`
*   **Метод контроллера:** `index`
*   **Описание:** Возвращает пагинированный список транзакций подотчетных средств для текущей организации с возможностью фильтрации. Ответ форматируется через `AdvanceTransactionCollection`.
*   **Параметры запроса (Query):**
    *   `user_id` (integer, опционально): Фильтр по пользователю.
    *   `project_id` (integer, опционально): Фильтр по проекту.
    *   `type` (string, опционально): Фильтр по типу (`issue`, `expense`, `return`).
    *   `reporting_status` (string, опционально): Фильтр по статусу (`pending`, `reported`, `approved`).
    *   `date_from` (string, опционально): Дата начала периода (YYYY-MM-DD).
    *   `date_to` (string, опционально): Дата конца периода (YYYY-MM-DD).
    *   `per_page` (integer, опционально): Количество записей на страницу (по умолчанию 15).
    *   `page` (integer, опционально): Номер страницы.
*   **Успешный ответ (200):**
    ```json
    {
      "data": [ /* Массив объектов AdvanceTransactionResource */ ],
      "links": { /* Ссылки пагинации */ }, 
      "meta": { /* Мета-информация пагинации */ }  
    }
    ```

### 1.4. Создание транзакции

*   **Эндпоинт:** `POST /advance-transactions`
*   **Метод контроллера:** `store`
*   **Описание:** Создает новую транзакцию. Валидация выполняется классом `CreateAdvanceTransactionRequest`. Ответ форматируется через `AdvanceTransactionResource`.
*   **Тело запроса (JSON):**
    *   `user_id` (integer, обязательно): ID пользователя.
    *   `project_id` (integer, опционально): ID проекта.
    *   `type` (string, обязательно): Тип (`issue`, `expense`, `return`).
    *   `amount` (numeric, обязательно): Сумма (> 0).
    *   `description` (string, опционально): Описание (макс. 255).
    *   `document_number` (string, опционально): Номер документа (макс. 100).
    *   `document_date` (string, опционально): Дата документа (YYYY-MM-DD).
*   **Успешный ответ (201):**
    ```json
    {
      "success": true,
      "message": "Transaction created successfully",
      "data": { /* Объект AdvanceTransactionResource */ }
    }
    ```
*   **Ошибка валидации (422).**
*   **Ошибка сервера (500):** `{"success": false, "message": "..."}`

### 1.5. Получение деталей транзакции

*   **Эндпоинт:** `GET /advance-transactions/{transaction}`
*   **Метод контроллера:** `show`
*   **Описание:** Возвращает детальную информацию по транзакции. Ответ форматируется через `AdvanceTransactionResource`.
*   **Параметры пути:**
    *   `{transaction}` (integer, обязательно): ID транзакции.
*   **Успешный ответ (200):**
    ```json
    {
      "data": { /* Объект AdvanceTransactionResource */ }
    }
    ```
*   **Ошибка (404):** Если транзакция не найдена или не принадлежит организации.

### 1.6. Обновление транзакции

*   **Эндпоинт:** `PUT /advance-transactions/{transaction}`
*   **Метод контроллера:** `update`
*   **Описание:** Обновляет существующую транзакцию. Валидация выполняется `UpdateAdvanceTransactionRequest`. Обновлять можно только транзакции в статусе `pending`.
*   **Параметры пути:**
    *   `{transaction}` (integer, обязательно): ID транзакции.
*   **Тело запроса (JSON):**
    *   `description` (string, опционально): Описание (макс. 255).
    *   `document_number` (string, опционально): Номер документа (макс. 100).
    *   `document_date` (string, опционально): Дата документа (YYYY-MM-DD).
    *   `external_code` (string, опционально): Внешний код (макс. 100).
    *   `accounting_data` (array, опционально): Доп. данные для бухгалтерии.
*   **Успешный ответ (200):**
    ```json
    {
      "success": true,
      "message": "Transaction updated successfully",
      "data": { /* Объект AdvanceTransactionResource */ }
    }
    ```
*   **Ошибка валидации (422).**
*   **Ошибка (404):** Если транзакция не найдена.
*   **Ошибка сервера (500):** `{"success": false, "message": "..."}`

### 1.7. Удаление транзакции

*   **Эндпоинт:** `DELETE /advance-transactions/{transaction}`
*   **Метод контроллера:** `destroy`
*   **Описание:** Удаляет транзакцию (мягкое удаление). Удалять можно только транзакции в статусе `pending`.
*   **Параметры пути:**
    *   `{transaction}` (integer, обязательно): ID транзакции.
*   **Успешный ответ (200):**
    ```json
    {
      "success": true,
      "message": "Transaction deleted successfully"
    }
    ```
*   **Ошибка (404):** Если транзакция не найдена.
*   **Ошибка сервера (500):** `{"success": false, "message": "..."}` (например, при попытке удалить не `pending` транзакцию).

### 1.8. Создание отчета по транзакции

*   **Эндпоинт:** `POST /advance-transactions/{transaction}/report`
*   **Метод контроллера:** `report`
*   **Описание:** Устанавливает статус транзакции `reported`. Валидация `TransactionReportRequest`.
*   **Параметры пути:**
    *   `{transaction}` (integer, обязательно): ID транзакции.
*   **Тело запроса (JSON):**
    *   `description` (string, обязательно): Описание отчета (макс. 255).
    *   `document_number` (string, обязательно): Номер документа отчета (макс. 100).
    *   `document_date` (string, обязательно): Дата документа отчета (YYYY-MM-DD).
    *   `files` (array, опционально): Массив файлов для прикрепления (см. 1.10).
*   **Успешный ответ (200):**
    ```json
    {
      "success": true,
      "message": "Transaction reported successfully",
      "data": { /* Объект AdvanceTransactionResource */ }
    }
    ```
*   **Ошибка валидации (422).**
*   **Ошибка (404):** Если транзакция не найдена.
*   **Ошибка сервера (500):** `{"success": false, "message": "..."}`

### 1.9. Утверждение отчета по транзакции

*   **Эндпоинт:** `POST /advance-transactions/{transaction}/approve`
*   **Метод контроллера:** `approve`
*   **Описание:** Устанавливает статус транзакции `approved`. Валидация `TransactionApprovalRequest`.
*   **Параметры пути:**
    *   `{transaction}` (integer, обязательно): ID транзакции.
*   **Тело запроса (JSON):**
    *   `accounting_data` (array, опционально): Доп. данные для бухгалтерии.
*   **Успешный ответ (200):**
    ```json
    {
      "success": true,
      "message": "Transaction approved successfully",
      "data": { /* Объект AdvanceTransactionResource */ }
    }
    ```
*   **Ошибка валидации (422).**
*   **Ошибка (404):** Если транзакция не найдена.
*   **Ошибка сервера (500):** `{"success": false, "message": "..."}` (например, если транзакция не в статусе `reported`).

### 1.10. Прикрепление файлов к транзакции

*   **Эндпоинт:** `POST /advance-transactions/{transaction}/attachments`
*   **Метод контроллера:** `attachFiles`
*   **Описание:** Прикрепляет один или несколько файлов к транзакции.
*   **Параметры пути:**
    *   `{transaction}` (integer, обязательно): ID транзакции.
*   **Тело запроса (multipart/form-data):**
    *   `files[]` (file[], обязательно): Массив файлов (макс. размер 10MB на файл).
*   **Успешный ответ (200):**
    ```json
    {
      "success": true,
      "message": "Files attached successfully",
      "data": { /* Объект AdvanceTransactionResource с обновленным attachment_ids */ }
    }
    ```
*   **Ошибка валидации (422):** Если файлы не переданы или превышают размер.
*   **Ошибка (404):** Если транзакция не найдена.
*   **Ошибка сервера (500):** `{"success": false, "message": "..."}`

### 1.11. Открепление файла от транзакции

*   **Эндпоинт:** `DELETE /advance-transactions/{transaction}/attachments/{fileId}`
*   **Метод контроллера:** `detachFile`
*   **Описание:** Открепляет файл от транзакции. Нельзя откреплять файлы от утвержденных транзакций.
*   **Параметры пути:**
    *   `{transaction}` (integer, обязательно): ID транзакции.
    *   `{fileId}` (integer, обязательно): ID файла.
*   **Успешный ответ (200):**
    ```json
    {
      "success": true,
      "message": "File detached successfully",
      "data": { /* Объект AdvanceTransactionResource с обновленным attachment_ids */ }
    }
    ```
*   **Ошибка (404):** Если транзакция или файл не найдены.
*   **Ошибка сервера (500):** `{"success": false, "message": "..."}` (например, файл не прикреплен к этой транзакции, или транзакция утверждена).

---

## 2. Баланс пользователей (`/users/{user}`)

Эти эндпоинты управляются контроллером `App\Http\Controllers\Api\V1\Admin\UserController`.

### 2.1. Получение баланса пользователя

*   **Эндпоинт:** `GET /users/{user}/advance-balance`
*   **Метод контроллера:** `getAdvanceBalance`
*   **Описание:** Возвращает текущее состояние подотчетного баланса пользователя.
*   **Параметры пути:**
    *   `{user}` (integer, обязательно): ID пользователя.
*   **Успешный ответ (200):**
    ```json
    {
      "data": {
        "user_id": 45,
        "name": "Иванов Иван",
        "current_balance": 5000.00,
        "total_issued": 25000.00,
        "total_reported": 20000.00,
        "has_overdue_balance": false,
        "last_transaction_at": "2023-06-20 11:00:00" 
      }
    }
    ```
*   **Ошибка (404):** Если пользователь не найден или не принадлежит организации.

### 2.2. Получение истории транзакций пользователя

*   **Эндпоинт:** `GET /users/{user}/advance-transactions`
*   **Метод контроллера:** `getAdvanceTransactions`
*   **Описание:** Возвращает пагинированный список транзакций конкретного пользователя. Ответ форматируется через `AdvanceTransactionCollection`.
*   **Параметры пути:**
    *   `{user}` (integer, обязательно): ID пользователя.
*   **Параметры запроса (Query):**
    *   `date_from` (string, опционально): Дата начала периода (YYYY-MM-DD).
    *   `date_to` (string, опционально): Дата конца периода (YYYY-MM-DD).
    *   `type` (string, опционально): Фильтр по типу (`issue`, `expense`, `return`).
    *   `reporting_status` (string, опционально): Фильтр по статусу (`pending`, `reported`, `approved`).
    *   `per_page` (integer, опционально): Количество записей на страницу (по умолчанию 15).
    *   `page` (integer, опционально): Номер страницы.
*   **Успешный ответ (200):** (Формат `AdvanceTransactionCollection`)
    ```json
    {
      "data": [ /* Массив объектов AdvanceTransactionResource */ ],
      "links": { ... }, 
      "meta": { ... }  
    }
    ```
*   **Ошибка (404):** Если пользователь не найден.

### 2.3. Выдача средств пользователю

*   **Эндпоинт:** `POST /users/{user}/issue-funds`
*   **Метод контроллера:** `issueFunds`
*   **Описание:** Создает транзакцию типа `issue` для указанного пользователя.
*   **Параметры пути:**
    *   `{user}` (integer, обязательно): ID пользователя.
*   **Тело запроса (JSON):**
    *   `amount` (numeric, обязательно): Сумма (> 0).
    *   `project_id` (integer, опционально): ID проекта.
    *   `description` (string, опционально): Описание (макс. 255).
    *   `document_number` (string, опционально): Номер документа (макс. 100).
    *   `document_date` (string, опционально): Дата документа (YYYY-MM-DD).
*   **Успешный ответ (201):**
    ```json
    {
      "success": true,
      "message": "Funds issued successfully",
      "data": { /* Объект AdvanceTransactionResource */ }
    }
    ```
*   **Ошибка валидации (422).**
*   **Ошибка (404):** Если пользователь не найден.
*   **Ошибка сервера (500):** `{"success": false, "message": "..."}`

### 2.4. Возврат средств от пользователя

*   **Эндпоинт:** `POST /users/{user}/return-funds`
*   **Метод контроллера:** `returnFunds`
*   **Описание:** Создает транзакцию типа `return` для указанного пользователя.
*   **Параметры пути:**
    *   `{user}` (integer, обязательно): ID пользователя.
*   **Тело запроса (JSON):**
    *   `amount` (numeric, обязательно): Сумма (> 0).
    *   `project_id` (integer, опционально): ID проекта.
    *   `description` (string, опционально): Описание (макс. 255).
    *   `document_number` (string, опционально): Номер документа (макс. 100).
    *   `document_date` (string, опционально): Дата документа (YYYY-MM-DD).
*   **Успешный ответ (201):**
    ```json
    {
      "success": true,
      "message": "Funds returned successfully",
      "data": { /* Объект AdvanceTransactionResource */ }
    }
    ```
*   **Ошибка валидации (422).**
*   **Ошибка (400):** Если сумма возврата превышает текущий баланс пользователя (`{"success": false, "message": "Insufficient funds for return"}`).
*   **Ошибка (404):** Если пользователь не найден.
*   **Ошибка сервера (500):** `{"success": false, "message": "..."}`

---

## 3. Отчеты по подотчетным средствам (`/reports/advance-accounts`)

Эти эндпоинты управляются контроллером `App\Http\Controllers\Api\V1\Admin\AdvanceAccountReportController`.

### 3.1. Сводный отчет

*   **Эндпоинт:** `GET /reports/advance-accounts/summary`
*   **Метод контроллера:** `summary`
*   **Описание:** Возвращает сводный отчет по подотчетным средствам для текущей организации.
*   **Параметры запроса (Query):**
    *   `date_from` (string, опционально): Дата начала периода (YYYY-MM-DD).
    *   `date_to` (string, опционально): Дата конца периода (YYYY-MM-DD).
*   **Успешный ответ (200):**
    ```json
    {
      "title": "Сводный отчет по подотчетным средствам",
      "period": { "from": "YYYY-MM-DD", "to": "YYYY-MM-DD" },
      "transaction_summary": { 
        "issue": { "pending": { "count": 5, "total_amount": 10000 }, ... },
        "expense": { "reported": { "count": 10, "total_amount": 8000 }, ... },
        // ...
      },
      "top_users": [ /* Список пользователей с наибольшим балансом */ ],
      "generated_at": "YYYY-MM-DD HH:MM:SS"
    }
    ```

### 3.2. Отчет по пользователю

*   **Эндпоинт:** `GET /reports/advance-accounts/users/{userId}`
*   **Метод контроллера:** `userReport`
*   **Описание:** Возвращает детальный отчет по подотчетным средствам конкретного пользователя.
*   **Параметры пути:**
    *   `{userId}` (integer, обязательно): ID пользователя.
*   **Параметры запроса (Query):**
    *   `date_from` (string, опционально): Дата начала периода (YYYY-MM-DD).
    *   `date_to` (string, опционально): Дата конца периода (YYYY-MM-DD).
*   **Успешный ответ (200):**
    ```json
    {
      "title": "Отчет по подотчетным средствам пользователя",
      "user": { /* Данные пользователя */ },
      "period": { "from": "...", "to": "..." },
      "summary": { "total_issued": ..., "total_expense": ..., ... },
      "transactions": [ /* Массив транзакций пользователя */ ],
      "project_summary": [ /* Сводка по проектам */ ],
      "generated_at": "..."
    }
    ```
*   **Ошибка (404):** Если пользователь не найден.

### 3.3. Отчет по проекту

*   **Эндпоинт:** `GET /reports/advance-accounts/projects/{projectId}`
*   **Метод контроллера:** `projectReport`
*   **Описание:** Возвращает детальный отчет по подотчетным средствам, связанным с конкретным проектом.
*   **Параметры пути:**
    *   `{projectId}` (integer, обязательно): ID проекта.
*   **Параметры запроса (Query):**
    *   `date_from` (string, опционально): Дата начала периода (YYYY-MM-DD).
    *   `date_to` (string, опционально): Дата конца периода (YYYY-MM-DD).
*   **Успешный ответ (200):**
    ```json
    {
      "title": "Отчет по подотчетным средствам проекта",
      "project": { /* Данные проекта */ },
      "period": { "from": "...", "to": "..." },
      "summary": { "total_issued": ..., "total_expense": ..., ... },
      "transactions": [ /* Массив транзакций проекта */ ],
      "user_summary": [ /* Сводка по пользователям */ ],
      "generated_at": "..."
    }
    ```
*   **Ошибка (404):** Если проект не найден.

### 3.4. Отчет по просроченным средствам

*   **Эндпоинт:** `GET /reports/advance-accounts/overdue`
*   **Метод контроллера:** `overdueReport`
*   **Описание:** Возвращает отчет по пользователям и транзакциям с просроченными отчетами.
*   **Параметры запроса (Query):**
    *   `overdue_days` (integer, опционально): Количество дней просрочки (по умолчанию 30).
*   **Успешный ответ (200):**
    ```json
    {
      "title": "Отчет по просроченным подотчетным средствам",
      "cutoff_date": "YYYY-MM-DD",
      "overdue_days": 30,
      "users_with_overdue_balance": [ /* Список пользователей */ ],
      "overdue_transactions": [ /* Список просроченных транзакций */ ],
      "summary": { "user_count": ..., "transaction_count": ..., "total_overdue_amount": ... },
      "generated_at": "..."
    }
    ```

### 3.5. Экспорт отчета

*   **Эндпоинт:** `GET /reports/advance-accounts/export/{format}`
*   **Метод контроллера:** `export`
*   **Описание:** Экспортирует выбранный тип отчета в указанном формате. *На данный момент поддерживается только `json`.*
*   **Параметры пути:**
    *   `{format}` (string, обязательно): Формат (`json`).
*   **Параметры запроса (Query):**
    *   `report_type` (string, обязательно): Тип отчета (`summary`, `user`, `project`, `overdue`).
    *   `date_from` (string, опционально): Дата начала периода.
    *   `date_to` (string, опционально): Дата конца периода.
    *   `user_id` (integer, опционально): ID пользователя (для `report_type=user`).
    *   `project_id` (integer, опционально): ID проекта (для `report_type=project`).
*   **Успешный ответ (200):**
    *   Заголовок `Content-Type: application/json`
    *   Заголовок `Content-Disposition: attachment; filename="..."`
    *   Тело: JSON с данными соответствующего отчета.
*   **Ошибка (500):** Если экспорт не удался.

---

## 4. Интеграция с бухгалтерскими системами (`/accounting`)

Эти эндпоинты управляются контроллером `App\Http\Controllers\Api\V1\Admin\AccountingIntegrationController`.

### 4.1. Импорт пользователей

*   **Эндпоинт:** `POST /accounting/import-users`
*   **Метод контроллера:** `importUsers`
*   **Описание:** Запускает процесс импорта/обновления данных пользователей из внешней бухгалтерской системы для текущей организации.
*   **Успешный ответ (200):**
    ```json
    {
      "success": true,
      "message": "Импорт пользователей завершен",
      "stats": { "total": 50, "created": 0, "updated": 48, "skipped": 2, "errors": 0 }
    }
    ```
*   **Ошибка (500):** `{"success": false, "message": "Ошибка при импорте..."}`

### 4.2. Импорт проектов

*   **Эндпоинт:** `POST /accounting/import-projects`
*   **Метод контроллера:** `importProjects`
*   **Описание:** Запускает процесс импорта/обновления данных проектов из внешней бухгалтерской системы для текущей организации.
*   **Успешный ответ (200):**
    ```json
    {
      "success": true,
      "message": "Импорт проектов завершен",
      "stats": { "total": 10, "created": 3, "updated": 7, "skipped": 0, "errors": 0 }
    }
    ```
*   **Ошибка (500):** `{"success": false, "message": "Ошибка при импорте..."}`

### 4.3. Импорт материалов

*   **Эндпоинт:** `POST /accounting/import-materials`
*   **Метод контроллера:** `importMaterials`
*   **Описание:** Запускает процесс импорта/обновления данных материалов из внешней бухгалтерской системы для текущей организации.
*   **Успешный ответ (200):**
    ```json
    {
      "success": true,
      "message": "Импорт материалов завершен",
      "stats": { "total": 100, "created": 0, "updated": 95, "skipped": 5, "errors": 0 }
    }
    ```
*   **Ошибка (500):** `{"success": false, "message": "Ошибка при импорте..."}`

### 4.4. Экспорт транзакций

*   **Эндпоинт:** `POST /accounting/export-transactions`
*   **Метод контроллера:** `exportTransactions`
*   **Описание:** Запускает процесс экспорта утвержденных транзакций подотчетных средств в бухгалтерскую систему для текущей организации.
*   **Тело запроса (JSON):**
    *   `start_date` (string, опционально): Дата начала периода для экспорта (YYYY-MM-DD).
    *   `end_date` (string, опционально): Дата конца периода для экспорта (YYYY-MM-DD).
*   **Успешный ответ (200):**
    ```json
    {
      "success": true,
      "message": "Экспорт транзакций завершен",
      "stats": { "total": 15, "exported": 15, "errors": 0 }
    }
    ```
*   **Ошибка (500):** `{"success": false, "message": "Ошибка при экспорте..."}`

### 4.5. Статус синхронизации

*   **Эндпоинт:** `GET /accounting/sync-status`
*   **Метод контроллера:** `getSyncStatus`
*   **Описание:** Возвращает информацию о последнем статусе синхронизации (заглушка, реальная логика не реализована).
*   **Успешный ответ (200):**
    ```json
    {
      "success": true,
      "message": "Синхронизация работает нормально",
      "last_sync": {
        "timestamp": "YYYY-MM-DD HH:MM:SS",
        "status": "completed",
        "users_synced": true,
        "projects_synced": true,
        "materials_synced": true,
        "transactions_synced": true
      }
    }
    ```

---

### Формат объекта `AdvanceTransactionResource`

```json
 {
    "id": 124, 
    "user_id": 45,
    "organization_id": 12,
    "project_id": 5,
    "type": "issue",
    "amount": 15000.00,
    "description": "Выдача на материалы",
    "document_number": "РКО-123",
    "document_date": "2023-06-20",
    "balance_after": 20000.00, 
    "reporting_status": "pending",
    "reported_at": null,
    "approved_at": null,
    "created_by_user_id": 14, 
    "approved_by_user_id": null,
    "external_code": null,
    "accounting_data": null,
    "attachment_ids": null, // Строка с ID файлов через запятую
    "created_at": "2023-06-20T11:00:00",
    "updated_at": "2023-06-20T11:00:00",
    // Связанные данные (включаются при запросе деталей или если явно загружены)
    "user": { "id": 45, "name": "Иванов И.И." },
    "project": { "id": 5, "name": "ЖК Солнечный", "external_code": "P-123" },
    "created_by": { "id": 14, "name": "Администратор" },
    "approved_by": null // Или объект пользователя { "id": ..., "name": "..." }
 }
```

</rewritten_file> 