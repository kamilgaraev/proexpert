# UI-гайд: «Работы дочерних организаций»

## 1. Видимость раздела
* Показывать вкладку/меню-item только если API-метаданные пользователя содержат permission `projects.view_child_works`.
* Дополнительно проверяем, что текущая роль — `Owner`, `Admin` или `Accountant` (см. `x-roles` из OpenAPI).

```ts
const canViewChildWorks =
  user.permissions.includes('projects.view_child_works') &&
  ['Owner', 'Admin', 'Accountant'].includes(user.role);
```

## 2. Эндпоинт
`GET /api/v1/admin/projects/{projectId}/child-works`

### Query-параметры
| Параметр              | Тип             | Описание                           |
|-----------------------|-----------------|------------------------------------|
| `child_organization_id` | int \| int[] | Фильтр по дочерним организациям    |
| `work_type_id`          | int \| int[] | Фильтр по видам работ              |
| `status`                | string\|string[] | Статус работы (`draft/confirmed`) |
| `date_from`             | `YYYY-MM-DD`  | Начало периода                     |
| `date_to`               | `YYYY-MM-DD`  | Конец периода                      |
| `search`                | string        | Поиск по `notes`                   |
| `per_page`              | int (default 50) | Размер страницы                |

## 3. Структура ответа
```json
{
  "success": true,
  "data": [
    {
      "id": 123,
      "project_id": 1,
      "child_organization": { "id": 42, "name": "ООО Бетон" },
      "work_type": { "id": 7, "name": "Заливка бетона" },
      "quantity": 12.5,
      "price": 3500.00,
      "total_amount": 43750.00,
      "completion_date": "2025-07-10",
      "status": "confirmed"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 5,
    "per_page": 50,
    "total": 227
  }
}
```

## 4. UX-паттерн
1. **Вкладка «Работы дочерних организаций»** внутри карточки проекта.
2. Таблица с колонками:
   * Дочерняя организация
   * Вид работ
   * Кол-во
   * Цена / Сумма
   * Дата выполнения
   * Статус
3. Фильтры сверху (select2 + date-range + search-input).
4. Под таблицей пагинация.

## 5. Ограничения производительности
* При изменении любого фильтра — дебаунс 300 мс, затем запрос с актуальным набором параметров.
* Всегда отправлять `per_page`, даже если пользователь выбрал «50 / стр». Не давать выбирать >100.
* Не грузить материалы/файлы в этом разделе — понадобятся отдельные детализации по клику.

## 6. Дальнейшее расширение
* Если появится мобильный клиент — ре-используем тот же эндпоинт, добавив JWT-scope `child_works:read`.
* Для экрана аналитики можно запрашивать агрегаты через `/aggregate=true` (зарезервировано, пока не реализовано). 

## Приложение A. OpenAPI-фрагмент
Ниже ключевые строки из спецификации — можно не открывать `docs/openapi`.

```yaml
/projects/{id}/child-works:
  get:
    summary: "Получить детализированные работы дочерних организаций проекта"
    x-roles: [Owner, Admin, Accountant]
    x-permissions: [projects.view_child_works]
    parameters:
      - in: path
        name: id
        required: true
        schema: { type: integer }
      - in: query
        name: child_organization_id
        schema:
          oneOf:
            - type: integer
            - type: array
              items: { type: integer }
      - in: query
        name: work_type_id
        schema:
          oneOf:
            - type: integer
            - type: array
              items: { type: integer }
      - in: query
        name: status
        schema:
          oneOf:
            - type: string
            - type: array
              items: { type: string }
      - in: query { name: date_from, schema: { type: string, format: date } }
      - in: query { name: date_to,   schema: { type: string, format: date } }
      - in: query { name: search,    schema: { type: string } }
      - in: query { name: per_page,  schema: { type: integer, default: 50 } }
    responses:
      "200":
        content:
          application/json:
            schema:
              allOf:
                - $ref: '../components/schemas/ApiResponse.yaml'
                - type: object
                  properties:
                    data:
                      $ref: '../components/schemas/CrossOrgCompletedWorkCollection.yaml'
                    meta:
                      $ref: '../components/schemas/PaginationMeta.yaml'
```

> **Важно:** `x-roles` / `x-permissions` совпадают с проверкой в UI-коде из раздела 1. 