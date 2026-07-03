# Модель маппингов справочников МОСТ и 1С

Дата: 2026-06-07
Задача: PHERP-68
Статус документа: аналитика для проверки

## Контекст

Документ описывает целевую модель сопоставления справочников МОСТ и 1С. Текущий `OneCBasicExchange` уже имеет базовые mappings по `organization`, `scope`, `external_id`, `local_type`, `local_id`, но production-контур требует confidence score, статусы, историю, duplicate detection, роли владельцев данных и UI-процесс ручного сопоставления.

Границы:

- МОСТ остается операционным source of truth по строительным объектам, управленческим справочникам, MDM и workflow.
- 1С остается бухгалтерским и налоговым source of truth по регламентированным реквизитам, учетным кодам и юридически значимым статусам.
- Операционные статусы МОСТ не равны учетным статусам 1С.
- МОСТ не становится бухгалтерским ядром.
- Raw payload, stack trace, токены и секреты нельзя показывать пользователям.

## Области маппинга

| Scope | Local object МОСТ | External object 1С | Source of truth |
| --- | --- | --- | --- |
| `organizations` | организация/юридическое лицо МОСТ | организация 1С | юридические и налоговые реквизиты - 1С, операционные настройки - МОСТ |
| `counterparties` | contractor, supplier, party snapshot | контрагент 1С | MDM/МОСТ для операционных связей, 1С для учетных реквизитов |
| `projects` | project/object | проект, объект или аналитика затрат 1С | МОСТ по lifecycle объекта, 1С по учетному коду |
| `contracts` | contract reference link | договор 1С | МОСТ по операционному договору, 1С по учетной карточке |
| `materials` | material, nomenclature | номенклатура 1С | MDM/МОСТ для стройки, 1С для учетной номенклатуры |
| `warehouses` | warehouse, zone/cell при необходимости | склад 1С | МОСТ по площадке, 1С по учетному складу |
| `budget_articles` | cost category, estimate position category | статья затрат/номенклатурная группа 1С | МОСТ для бюджета, 1С для учета затрат |
| `cfo` | ЦФО/подразделение/аналитика проекта | ЦФО или подразделение 1С | определяется внедрением |
| `employees` | workforce employee, external payroll ref | сотрудник/физлицо/табельный номер 1С/ЗУП | МОСТ по явке и выработке, 1С/ЗУП по кадровому и зарплатному учету |

Договоры включены как reference link, потому что документы актов, платежей и закупок часто требуют внешний id договора. При этом договор остается документом со своим lifecycle, а не обычным справочником.

## Целевая сущность mapping

| Поле | Назначение |
| --- | --- |
| `id` | Идентификатор маппинга |
| `organization_id` | Организация МОСТ |
| `one_c_base_id` | База 1С |
| `scope` | Контур маппинга |
| `local_object_type`, `local_object_id` | Объект МОСТ |
| `local_display_name` | Безопасное имя для UI |
| `external_object_type`, `external_id` | Объект 1С |
| `external_code`, `external_display_name` | Код и имя 1С |
| `status` | `active`, `inactive`, `needs_review`, `conflict`, `superseded`, `archived` |
| `confidence_score` | 0-100 |
| `mapping_method` | `auto`, `manual`, `import`, `1c_callback`, `mdm_merge` |
| `owner_role` | Владелец данных |
| `source_hash`, `external_hash` | Контроль актуальности |
| `validation_status` | `valid`, `warning`, `blocking` |
| `version` | Версия маппинга |
| `previous_mapping_id` | История замены |
| `safe_payload_preview` | Маскированная сводка сравнения |
| `created_by_user_id`, `approved_by_user_id` | Кто создал и подтвердил |
| `created_at`, `updated_at`, `validated_at`, `archived_at` | Временные метки |

Unique-правило: один активный local object в одном `organization_id + one_c_base_id + scope` не должен указывать на несколько external objects. Один external object не должен быть активным маппингом для нескольких local objects без явного merge/duplicate resolution.

## Роли владельцев данных

| Scope | Владелец |
| --- | --- |
| Организации | бухгалтерия/администратор организации |
| Контрагенты | MDM steward, снабжение, бухгалтерия по реквизитам |
| Проекты | PMO/руководитель проекта, финконтроль по учетному коду |
| Договоры | договорной отдел/бухгалтерия |
| Материалы | MDM steward, склад, снабжение |
| Склады | склад/логистика, бухгалтерия по учетному складу |
| Статьи бюджета и ЦФО | финконтроль/бухгалтерия |
| Сотрудники/payroll-source references | HR/зарплатный бухгалтер |

Права на ручное сопоставление должны быть раздельными по scope. Например, сотрудник склада не должен менять payroll mapping.

## Confidence score

Confidence score показывает вероятность корректного автоматического сопоставления.

| Балл | Значение | Действие |
| --- | --- | --- |
| 95-100 | Сильное совпадение по external id или подтвержденному MDM ключу | можно auto-activate, если scope разрешает |
| 80-94 | Совпали несколько реквизитов, но нет надежного id | needs review |
| 60-79 | Есть частичное совпадение | только ручное подтверждение |
| 1-59 | Низкая уверенность | показывать как кандидат, не выбирать автоматически |
| 0 | Сопоставление невозможно или опасно | blocking issue |

Факторы:

- exact match по ИНН/КПП для контрагентов;
- normalized name match;
- external code;
- номер договора + контрагент + дата;
- артикул/код/единица измерения для материалов;
- табельный номер или `external_payroll_ref` для сотрудников;
- project accounting code;
- отсутствие активного дубля.

## Auto mapping

Автоматический маппинг допустим только если:

- confidence score выше порога scope;
- нет активного дубля local/external object;
- владелец данных разрешил auto mode для scope;
- совпали обязательные validation fields;
- external object не архивирован;
- mapping не затрагивает чувствительные payroll или банковские данные без отдельного правила.

Auto mapping должен оставлять audit trail и быть обратимым через supersede/archive, а не физическое удаление.

## Manual mapping

Ручной сценарий:

1. Пользователь открывает очередь `requires_mapping`.
2. Система показывает local object, безопасные кандидаты 1С и confidence factors.
3. Пользователь выбирает existing external object или создает запрос на создание в 1С.
4. Система валидирует обязательные поля и дубли.
5. Маппинг получает `active` или `needs_review`, если требуется второй уровень подтверждения.
6. Заблокированные sync messages становятся доступны для requeue.

Для sensitive scope нужен dual control: один пользователь предложил, другой подтвердил.

## Duplicate detection

Источники дублей:

- MDM duplicate groups;
- одинаковые ИНН/КПП, разные названия;
- одинаковый external id, разные local objects;
- одинаковый материал с разными единицами измерения;
- сотрудник с одинаковым ФИО и датой рождения, но разным табельным номером;
- договор с одинаковым номером и контрагентом в разных проектах;
- склад 1С сопоставлен с несколькими площадками без явного правила.

Статусы duplicate review:

- `candidate`;
- `needs_review`;
- `confirmed_duplicate`;
- `not_duplicate`;
- `merged`;
- `superseded`.

Merge должен идти через MDM-процесс, dry-run и audit. Mapping registry только использует результат, но не подменяет MDM.

## Active/inactive mappings

| Статус | Значение |
| --- | --- |
| `active` | Используется для обмена |
| `inactive` | Временно не используется, но история сохранена |
| `needs_review` | Требуется проверка владельца данных |
| `conflict` | Есть противоречие local/external object |
| `superseded` | Заменен новым mapping |
| `archived` | Историческая запись, недоступна для новых сообщений |

Нельзя физически удалять mapping, если по нему есть sync messages, документы или audit events.

## Versioning и history

Каждое изменение mapping должно фиксировать:

- кто изменил;
- что изменилось;
- причину;
- старое и новое значение;
- affected messages/documents;
- source/external hash до и после;
- дату подтверждения владельцем.

При изменении active mapping старые сообщения не переписываются. Новые сообщения используют новую версию.

## Validation rules по scope

| Scope | Обязательные проверки |
| --- | --- |
| Организации | ИНН, КПП, ОГРН при наличии, legal name, активность external organization |
| Контрагенты | ИНН/КПП или другой устойчивый идентификатор, тип контрагента, статус MDM |
| Проекты | organization, project code, отсутствие дубля accounting code |
| Договоры | counterparty mapping, organization mapping, contract number/date, сумма или тип договора |
| Материалы | единица измерения, артикул/код, normalized name, НДС/категория если требуется 1С |
| Склады | organization, warehouse type, active status, связь с площадкой |
| Статьи бюджета | код статьи, тип затрат, связь с estimate/cost category |
| ЦФО | код ЦФО, organization, owner department |
| Сотрудники | `external_payroll_ref` или табельный номер, активность, payroll period rules |

Если validation blocking, документы не должны уходить в 1С.

## Conflict cases

| Конфликт | Действие |
| --- | --- |
| Local object уже связан с другим external id | остановить сообщения, открыть conflict |
| External id связан с другим local object | duplicate review или manual relink |
| 1С вернула архивированный объект | mapping inactive, requires review |
| Изменился ИНН/КПП контрагента | проверить owner field, возможно новый контрагент |
| Материал совпал по названию, но отличается единица | blocking conflict |
| Сотрудник совпал по ФИО, но разный табельный номер | ручная проверка HR |
| Договор найден по номеру, но другой контрагент | blocking conflict |
| Project accounting code занят | PMO/финконтроль review |

## Admin UI сценарии

### Реестр маппингов

Фильтры:

- scope;
- status;
- confidence range;
- owner role;
- organization/base;
- project;
- search по local/external name, code, id.

Колонки:

- local object;
- external object;
- status;
- confidence;
- method;
- owner;
- last validation;
- affected messages.

### Карточка маппинга

Блоки:

- local summary;
- external summary;
- validation checks;
- confidence factors;
- duplicate candidates;
- history;
- affected documents/messages;
- allowed actions.

### Очередь сопоставления

Сценарии:

- массово подтвердить high-confidence candidates;
- ручной поиск объекта 1С;
- создать запрос на создание внешнего объекта;
- пометить как duplicate;
- архивировать устаревший mapping;
- вернуть заблокированные messages в requeue.

## API будущего реестра маппингов

Базовый префикс: `/api/v1/admin/one-c-exchange/reference-mappings`.

| Endpoint | Назначение |
| --- | --- |
| `GET /` | Список маппингов |
| `GET /{id}` | Карточка маппинга |
| `POST /candidates/search` | Поиск кандидатов |
| `POST /` | Создание ручного mapping |
| `PATCH /{id}` | Обновление статуса или metadata |
| `POST /{id}/approve` | Подтверждение |
| `POST /{id}/archive` | Архивирование |
| `POST /{id}/supersede` | Замена новым mapping |
| `GET /{id}/history` | История |
| `GET /duplicates` | Очередь дублей |

Пример создания:

```json
{
  "scope": "counterparties",
  "local_object_type": "contractor",
  "local_object_id": 321,
  "external_id": "1c-guid-...",
  "external_code": "00001234",
  "reason": "Совпали ИНН и КПП"
}
```

Ответ должен возвращать safe labels, validation result, confidence score и доступные действия.

## Открытые вопросы

- Какие scope допускают auto-activate без второго подтверждения.
- Нужен ли отдельный `one_c_base_id` в mapping registry с первого этапа.
- Какие персональные поля сотрудников можно показывать в mapping UI.
- Как синхронизировать MDM duplicate groups и mapping conflicts без дублирования workflow.
