# Коэффициенты (Rate Coefficients)

Полное руководство для фронтенд-разработчиков: как добавить работу с коэффициентами (в том числе типа `material_norms`) в админ-панель, а также примеры тел API-запросов и ответов.

---

## 1. UI-разделы

### 1.1. Список коэффициентов

* **URL страницы:** `/admin/rate-coefficients`
* Таблица:
  | Колонка | Поле ответа | Примечание |
  |---------|-------------|------------|
  | ID | `id` |  |
  | Название | `name` |  |
  | Код | `code` |  |
  | Значение | `value` | Отображать с точностью 4 знака. |
  | Тип | `type` | `percentage` / `fixed_addition` |
  | Назначение | `applies_to` | `material_norms`, `work_costs`, `labor_hours`, `general` |
  | Область действия | `scope` | `global_org`, `project`, `work_type_category`, `work_type`, `material_category`, `material` |
  | Валиден с | `valid_from` |  |
  | Валиден по | `valid_to` |  |
  | Активен | `is_active` | чек-бокс |
  | Действия | — | Кнопки «✏️» «🗑» |

* **Фильтры** (query-параметры, показываются в панели над таблицей):
  * `name`, `code` — input.
  * `type`, `applies_to`, `scope` — select.
  * `is_active` — чек-бокс «Только активные».
  * `valid_from` / `valid_to` — два date-picker’а (с / по).
  * Кнопка «Сбросить фильтры» сбрасывает query-параметры.

### 1.2. Форма создания / редактирования

| Поле | Тип | Обяз. | Примечание |
|------|-----|-------|------------|
| Название | text | ✔ | `name` |
| Код | text | — | `code` |
| Значение | number (step 0.0001) | ✔ | `value` |
| Тип | select | ✔ | enum `percentage` / `fixed_addition` |
| Назначение | select | ✔ | enum `material_norms`, … |
| Область действия | select | ✔ | enum `global_org`, … |
| Доп. условия (`conditions`) | UI зависит от `scope` | — | JSON сохраняется целиком |
| Валиден с | date | — | `valid_from` |
| Валиден по | date | — | `valid_to` (≥ `valid_from`) |
| Активен | switch | — | `is_active` |
| Описание | textarea | — | `description` |

> ⚠️ Валидация на клиенте должна повторять правила из backend-классов `StoreRateCoefficientRequest` / `UpdateRateCoefficientRequest`.

### 1.3. Доп. условия (UI-подсказки)

| `scope` | Ключ JSON | Что рисуем |
|---------|-----------|-----------|
| `project` | `project_ids: number[]` | multiselect проектов |
| `material` | `material_ids: number[]` | multiselect материалов |
| `material_category` | `material_category_ids: number[]` | multiselect категорий |

---

## 2. API-эндпоинты

> Все запросы требуют заголовок `Authorization: Bearer <token>`

### 2.1. Список коэффициентов

```
GET /api/v1/admin/rate-coefficients?page=1&per_page=15&name=бетон
```

Ответ `200 OK`
```json
{
  "data": [
    {
      "id": 7,
      "name": "Повышенный расход бетона",
      "code": "BETON_15",
      "value": 15.0,
      "type": "percentage",
      "applies_to": "material_norms",
      "scope": "material_category",
      "description": "Для тяжёлых бетонов",
      "is_active": true,
      "valid_from": "2024-01-01",
      "valid_to": null,
      "conditions": {"material_category_ids": [3]},
      "created_at": "2024-05-10 09:15:23",
      "updated_at": "2024-05-10 09:15:23"
    }
  ],
  "links": {
    "first": "…?page=1",
    "last": "…?page=5",
    "prev": null,
    "next": "…?page=2"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 5,
    "path": "…/rate-coefficients",
    "per_page": 15,
    "to": 15,
    "total": 72
  }
}
```

### 2.2. Получить один коэффициент
```
GET /api/v1/admin/rate-coefficients/{id}
```
`200 OK` — тело как один объект выше. 
`404 Not Found` — если id не принадлежит организации.

### 2.3. Создать
```
POST /api/v1/admin/rate-coefficients
Content-Type: application/json
```
Body
```json
{
  "name": "10 % на норму арматуры",
  "code": "ARMATURA_10",
  "value": 10,
  "type": "percentage",
  "applies_to": "material_norms",
  "scope": "material",
  "conditions": {"material_ids": [42]},
  "description": "Сложная геометрия",
  "is_active": true,
  "valid_from": "2024-06-01"
}
```
Ответ `201 Created` — объект коэффициента. 
Ошибки `422 Unprocessable Entity` содержат поле `errors`.

### 2.4. Обновить
```
PUT /api/v1/admin/rate-coefficients/{id}
```
Body (частичное обновление):
```json
{
  "value": 12.5,
  "description": "Уточнено КД",
  "valid_to": "2024-12-31"
}
```
Ответ `200 OK` — обновлённый объект.

### 2.5. Удалить
```
DELETE /api/v1/admin/rate-coefficients/{id}
```
Ответ `204 No Content`.

---

## 3. Формат enum-значений

| Поле | Возможные значения |
|------|--------------------|
| `type` | `percentage`, `fixed_addition` |
| `applies_to` | `material_norms`, `work_costs`, `labor_hours`, `general` |
| `scope` | `global_org`, `project`, `work_type_category`, `work_type`, `material_category`, `material` |

---

## 4. Отображение в отчётах

Сервер уже применяет коэффициенты, поэтому:
* поле `production_norm` в ответах отчёта по материалам **уже скорректировано**; фронт показывает как есть;
* при необходимости добавить в UI колонку «Коэффициенты» — потребуется расширение API.

---

## 5. Чек-лист интеграции

- [ ] Создать новую страницу в меню в справочниках «Коэффициенты».
- [ ] Реализовать таблицу + фильтры + пагинацию.
- [ ] Реализовать форму Create/Edit, включая динамические поля `conditions`.
- [ ] Подключить запросы к API согласно разделу 2.
- [ ] Обновить отчёт «Материалы» — норму выводить из ответа без собственных расчётов. 