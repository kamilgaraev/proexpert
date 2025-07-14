# Обновление контрактного модуля (backend v2025-07-14)

## 1. Что изменилось

1. Поле `type` удалено из модели `Contract`. Теперь договор, доп. соглашение и спецификация — самостоятельные сущности.
2. Появились два новых ресурса:
   * **SupplementaryAgreement** (`/agreements`)
   * **Specification** (`/specifications`)
3. В ответе `GET /contracts/{id}` сервер дополнительно возвращает:
   * `agreements[]` — массив доп. соглашений (полностью, без пагинации)
   * `specifications[]` — список привязанных спецификаций
4. Контрактам доступен новый эндпоинт привязки/отвязки спецификаций: `POST|DELETE /contracts/{id}/specifications/{spec_id}`.

## 2. Навигация и структура интерфейса

```
└─ Договоры (Contracts)
   ├─ Список договоров
   │   └─ Карточка / Страница договора
   │       ├─ Вкладка «Общие сведения»
   │       ├─ Вкладка «Доп. соглашения»   ← новая
   │       ├─ Вкладка «Спецификации»      ← новая
   │       └─ Вкладка «Акты / Платежи / ...»
   ├─ Доп. соглашения (global list) – опционально
   └─ Спецификации (global list)

└─ Справочники (Catalogs)
   ├─ …
   ├─ Договоры          ← новая под-вкладка (быстрый поиск/импорт номеров)
   ├─ Доп. соглашения   ← новая под-вкладка
   └─ Спецификации      ← новая под-вкладка
```

### 2.1. Вкладка «Доп. соглашения» (в карточке договора)

| Колонка | Пример | Примечание |
|---------|--------|------------|
| № | СОГ-01 | Клик → просмотр/редактирование |
| Дата | 12.07.2025 | |
| Δ Суммы | +1 500 000 ₽ | Цвет: зелёный >0, красный <0 |
| Кол-во изменений | 4 | из `subject_changes.length` |

Кнопка «Создать» → модальное окно (форма `SupplementaryAgreement`). После сохранения обновить таблицу и перерисовать фин. показатели договора.

### 2.2. Вкладка «Спецификации» (в карточке договора)

* Таблица привязанных спецификаций (№, дата, сумма, статус).
* Кнопка «Добавить» открывает диалог выбора из глобального списка (autocomplete по номеру). Можно фильтровать по статусу `approved`.
* Для каждой строки доступны действия «Открыть» и «Отвязать».

### 2.3. Справочники

Добавить три под-вкладки в раздел *Справочники*:

1. **Договоры** — сокращённый реестр (только номера, суммы, проект) для ссылок.
2. **Доп. соглашения** — глобальный список; позволяет смотреть/редактировать без перехода в договор.
3. **Спецификации** — полный CRUD; фильтр по статусу, поиску по номеру.

## 3. Изменения состояния (Vuex / Pinia)

| Store | State | Actions |
|-------|-------|---------|
| contracts | `contracts`, `currentContract` | `fetch`, `create`, `update`, `attachSpecification` |
| agreements | `agreements`, `byContract[contractId]` | `fetchByContract`, `create`, `update`, `remove` |
| specifications | `specifications` | `fetch`, `create`, `update`, `remove` |

После успешного создания/редактирования соглашения необходимо диспатчить `contracts/recalculateTotals` — backend отдаёт актуальные данные, фронт лишь обновляет store.

## 4. Компоненты UI

1. `AgreementTable.vue` + `AgreementFormModal.vue`
2. `SpecificationTable.vue` + `SpecificationSearchModal.vue`
3. Обновлённая `ContractDetail.vue` (два новых таба)
4. Каталог-реестры: `CatalogAgreements.vue`, `CatalogSpecifications.vue`, `CatalogContracts.vue`

## 5. REST-клиенты

| Метод | URL | Клиентский вызов |
|-------|-----|------------------|
| GET | `/contracts/{id}/agreements` | `api.getAgreements(contractId)` |
| POST | `/agreements` | `api.createAgreement(payload)` |
| PUT | `/agreements/{id}` | `api.updateAgreement(id, payload)` |
| DELETE | `/agreements/{id}` | `api.deleteAgreement(id)` |
| GET | `/specifications` | `api.getSpecifications(params)` |
| POST | `/specifications` | `api.createSpecification(payload)` |
| PUT | `/specifications/{id}` | `api.updateSpecification(id, payload)` |
| DELETE | `/specifications/{id}` | `api.deleteSpecification(id)` |
| POST | `/contracts/{id}/specifications/{spec_id}` | `api.attachSpecification(contractId, specId)` |
| DELETE | `/contracts/{id}/specifications/{spec_id}` | `api.detachSpecification(contractId, specId)` |

## 6. Графики и аналитика

* **Фин. сводка** договора теперь рассчитывает: `total_with_agreements`, `specifications_amount`.
* На дашборде добавить виджет «Топ спецификаций по сумме».

## 7. Локализация

Добавить строки в `ru.json` и `en.json` для:
`agreements`, `specifications`, `change_amount`, `add_agreement`, `attach_specification`, статусы `draft|approved|archived`.

## 8. План миграции фронтенда

1. Смёржить ветку backend-changes; сгенерировать нового клиента из OpenAPI.
2. Создать новые store-модули; подключить к основному хранилищу.
3. Рефакторить `ContractDetail.vue` – удалить логику `type`, подключить новые табы.
4. Постепенно заменить прямые поля контракта на агрегаты из API.
5. Пройтись по сценариям QA:
   * Создание соглашения изменяет сумму контракта
   * Привязка/отвязка спецификации отображается в таблице и графике
   * Пагинация реестров работает, фильтры применяются
6. Обновить Wiki и onboarding-доки. 

## 9. Примеры запросов и ответов

### 9.1. Создание доп. соглашения

**POST** `/api/v1/admin/agreements`

```json
{
  "contract_id": 12,
  "number": "СОГ-02",
  "agreement_date": "2025-07-14",
  "change_amount": 1500000,
  "subject_changes": [
    "Увеличение сроков выполнения",
    "Корректировка сметы на материалы"
  ]
}
```

Ответ **201 Created**
```json
{
  "id": 34,
  "contract_id": 12,
  "number": "СОГ-02",
  "agreement_date": "2025-07-14",
  "change_amount": 1500000,
  "subject_changes": [
    "Увеличение сроков выполнения",
    "Корректировка сметы на материалы"
  ],
  "created_at": "2025-07-14T10:15:03+03:00",
  "updated_at": "2025-07-14T10:15:03+03:00"
}
```

### 9.2. Список соглашений контракта

**GET** `/api/v1/admin/contracts/12/agreements`

Ответ **200 OK**
```json
{
  "data": [
    {
      "id": 34,
      "number": "СОГ-02",
      "agreement_date": "2025-07-14",
      "change_amount": 1500000
    },
    {
      "id": 29,
      "number": "СОГ-01",
      "agreement_date": "2025-06-01",
      "change_amount": -500000
    }
  ],
  "meta": {
    "total": 2
  }
}
```

### 9.3. Создание спецификации

**POST** `/api/v1/admin/specifications`
```json
{
  "number": "СПЦ-2025-05",
  "spec_date": "2025-07-10",
  "total_amount": 7800000,
  "scope_items": [
    "Поставка кабеля 4×50 – 8 км",
    "Монтаж опор — 15 шт."
  ],
  "status": "approved"
}
```
Ответ **201 Created**
```json
{
  "id": 57,
  "number": "СПЦ-2025-05",
  "spec_date": "2025-07-10",
  "total_amount": 7800000,
  "scope_items": [
    "Поставка кабеля 4×50 – 8 км",
    "Монтаж опор — 15 шт."
  ],
  "status": "approved",
  "created_at": "2025-07-14T10:18:11+03:00",
  "updated_at": "2025-07-14T10:18:11+03:00"
}
```

### 9.4. Привязка спецификации к договору

**POST** `/api/v1/admin/contracts/12/specifications/57`

Ответ **200 OK**
```json
{
  "message": "Specification attached"
}
```

После привязки повторный вызов `GET /contracts/12` вернёт раздел `specifications` с новой записью. 