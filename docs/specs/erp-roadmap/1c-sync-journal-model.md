# Целевая модель журнала синхронизации ProHelper и 1С

Дата: 2026-06-07
Задача: PHERP-64
Статус документа: аналитика для проверки

## Контекст

Документ описывает целевую модель журнала обмена ProHelper и 1С для production-контура. Это не описание текущей реализации `OneCBasicExchange`: текущий модуль является ручным MVP с токенами, маппингами, import/export и историей запусков.

Базовые границы:

- ProHelper остается строительной ERP и операционным source of truth.
- 1С остается бухгалтерским и налоговым source of truth.
- Операционные статусы ProHelper, включая `approved`, `signed`, `paid` и операционное `posted`, не равны учетным статусам 1С.
- ProHelper не становится бухгалтерским ядром и не ведет параллельный регламентированный учет.
- Raw payload, stack trace, токены, пароли, ключи, полные банковские реквизиты и секреты нельзя показывать пользователям.

## Назначение журнала

Журнал синхронизации должен быть центральной точкой наблюдаемости обмена:

- показывать, что именно было поставлено в обмен и в каком scope;
- связывать sync operation, sync message, source object, target object и attempts;
- давать безопасную пользовательскую диагностику без раскрытия секретов;
- давать поддержке достаточно контекста для разборки инцидента;
- быть источником статусов для карточек документов, справочников, платежей, склада и BI-витрин;
- сохранять историю для аудита, сверки и расследования дублей.

Журнал не должен становиться хранилищем бухгалтерских проводок или заменять 1С.

## Основные сущности

| Сущность | Назначение | Пример |
| --- | --- | --- |
| `sync_operation` | Групповой запуск обмена или бизнес-операция, объединяющая сообщения | ручной экспорт платежей за день, плановая отправка складских движений |
| `sync_message` | Отдельное сообщение обмена по документу, справочнику или строке пакета | платежный документ, акт, маппинг контрагента |
| `sync_attempt` | Конкретная попытка доставки или приема сообщения | первая отправка, повтор после timeout |
| `source_object` | Объект-источник в ProHelper или 1С | `payment_document:123`, `1c_counterparty:GUID` |
| `target_object` | Ожидаемый или фактический объект-получатель | документ 1С, маппинг ProHelper, BI-событие |
| `sync_error` | Безопасная модель ошибки с техническим контекстом для поддержки | validation error, timeout, mapping missing |

## Sync operation

`sync_operation` отвечает за группу сообщений и пользовательскую историю запусков.

| Поле | Назначение |
| --- | --- |
| `id` | Внутренний идентификатор операции |
| `operation_key` | Детерминированный ключ бизнес-операции |
| `correlation_id` | Сквозной идентификатор operation -> message -> attempt |
| `organization_id` | Организация ProHelper |
| `project_id` | Проект, если операция ограничена объектом |
| `one_c_base_id` | Целевая база 1С или контур, если у организации несколько баз |
| `scope` | Контур обмена: documents, references, payments, warehouse, payroll, reconciliation |
| `direction` | `prohelper_to_1c`, `1c_to_prohelper`, `bidirectional`, `prohelper_to_bi`, `1c_to_bi` |
| `status` | Агрегированный статус операции |
| `trigger` | `manual`, `schedule`, `webhook`, `api`, `system_repair` |
| `actor_user_id` | Пользователь, инициировавший операцию |
| `system_actor` | Технический источник: scheduler, queue worker, 1c callback, import parser |
| `message_count` | Количество сообщений |
| `success_count`, `failed_count`, `requires_mapping_count`, `conflict_count` | Итоги обработки |
| `started_at`, `finished_at`, `created_at`, `updated_at` | Временные метки |

Агрегированный статус операции не должен затирать статусы отдельных сообщений. Частичный успех операции нормален: часть сообщений может быть `accepted`, часть - `requires_mapping` или `rejected`.

## Sync message

`sync_message` является основной строкой журнала.

| Поле | Назначение |
| --- | --- |
| `id` | Внутренний идентификатор сообщения |
| `operation_id` | Ссылка на sync operation |
| `parent_message_id` | Связь с родительским сообщением при item-level retry или корректировке |
| `organization_id`, `project_id`, `one_c_base_id` | Организационный, проектный и внешний контур |
| `direction` | Направление обмена |
| `scope` | Область обмена |
| `message_type` | Тип события из каталога обмена |
| `source_object_type`, `source_object_id` | Объект-источник |
| `source_object_version` | Версия, hash или updated_at объекта-источника |
| `target_object_type`, `target_object_id`, `target_external_id` | Объект-получатель или внешний id |
| `mapping_id` | Ссылка на маппинг справочника или документа |
| `status` | Текущий статус сообщения |
| `business_status` | Операционный статус ProHelper на момент создания |
| `sync_status` | Статус доставки и обработки обмена |
| `accounting_status` | Учетный статус 1С, если 1С вернула подтверждение |
| `idempotency_key` | Ключ защиты от дублей |
| `correlation_id` | Сквозная трассировка |
| `payload_snapshot_ref` | Ссылка на полный payload snapshot в защищенном хранилище |
| `payload_snapshot_hash` | Хеш полного payload |
| `safe_payload_preview` | Маскированная краткая сводка для UI |
| `safe_error_code`, `safe_error_message` | Понятная ошибка для пользователя |
| `technical_error_code`, `technical_error_ref` | Технический код и ссылка для поддержки |
| `retry_count`, `max_retry_count`, `next_retry_at` | Retry-состояние |
| `locked_at`, `locked_by_worker`, `lock_expires_at` | Защита от параллельной обработки |
| `sent_at`, `accepted_at`, `posted_at`, `rejected_at`, `dead_letter_at` | Важные события lifecycle |
| `created_by_user_id`, `created_by_system` | Actor/system actor |
| `created_at`, `updated_at` | Временные метки |

## Source object и target object

Журнал должен ссылаться на исходные и целевые объекты без жесткой зависимости от конкретной таблицы.

| Контур | Source object | Target object |
| --- | --- | --- |
| Справочники | contractor, supplier, material, warehouse, project, employee | справочник 1С, внешний GUID, код аналитики |
| Договоры и акты | contract, contract_performance_act | договор/акт 1С, учетный документ |
| Платежи | payment_request, payment_document, payment_transaction | платежный документ 1С, банковский факт, accounting posting |
| Закупки | purchase_request, purchase_order, purchase_receipt | заказ поставщику, поступление, счет |
| Склад | warehouse_movement, warehouse_balance adjustment, inventory_act | складской документ 1С |
| Payroll-source | payroll_period, payroll_export_package, payroll_source_row | пакет ЗУП/1С, результат приемки |
| BI | document_sync_mart row, reconciliation event | витрина или сверочный факт |

Для polymorphic-ссылок обязательно хранить `organization_id`, чтобы нельзя было открыть объект другой организации.

## Статусы сообщения

| Статус | Значение |
| --- | --- |
| `draft` | Сообщение подготовлено, но еще не готово к постановке |
| `queued` | Сообщение поставлено в очередь |
| `locked` | Сообщение взято worker-ом |
| `sent` | Сообщение отправлено в 1С |
| `accepted` | 1С приняла сообщение или зарегистрировала объект |
| `posted` | 1С провела документ, если проведение применимо |
| `rejected` | 1С вернула бизнес-отказ |
| `failed` | Техническая ошибка доставки или обработки |
| `retrying` | Запланирован автоматический retry |
| `requires_mapping` | Требуется сопоставление справочника или объекта |
| `conflict` | Найден конфликт данных или ownership |
| `dead_letter` | Автоматическая обработка прекращена |
| `manually_resolved` | Сообщение закрыто ручным решением |
| `cancelled` | Сообщение отменено до отправки по допустимому правилу |

`posted` в журнале означает подтверждение от 1С. Оно не должно выставляться только потому, что документ имеет операционный статус `approved`, `signed`, `paid` или локальное `posted` в ProHelper.

## Payload snapshot и safe payload preview

Полный payload нужен для расследований и повторов, но не для пользовательского UI.

| Вид данных | Где хранить | Кто видит |
| --- | --- | --- |
| `payload_snapshot` | Защищенное хранилище или зашифрованное поле, с hash и schema version | только поддержка с повышенным доступом |
| `safe_payload_preview` | JSON/структура с маскированными полями | пользователи с правом просмотра журнала |
| `request_headers`, `response_headers` | Только безопасные заголовки, без токенов | поддержка |
| `raw_error`, `stack_trace` | Отдельная support-only ссылка или защищенное поле | только поддержка, не UI |

Preview должен содержать бизнес-понятные значения: номер документа, дата, сумма, контрагент, проект, scope, направление, текущая причина. ИНН можно показывать по правилам доступа, расчетные счета и токены нужно маскировать.

Запрещено выводить пользователю:

- raw payload;
- stack trace;
- SQL, class names, exception trace;
- bearer/basic tokens и ключи интеграции;
- пароли, client secret, webhook secret;
- полный банковский счет, если у пользователя нет отдельного права;
- персональные и зарплатные данные сверх минимального preview.

## Error model

| Поле | Назначение |
| --- | --- |
| `safe_error_code` | Стабильный код для UI и переводов |
| `safe_error_message` | Человекочитаемая причина |
| `safe_next_action` | Что можно сделать: сопоставить, исправить, повторить, ждать |
| `technical_error_code` | Код для поддержки |
| `technical_error_ref` | Ссылка на защищенный технический лог |
| `external_error_code` | Код 1С, если он безопасен для хранения |
| `external_error_summary` | Санитизированная причина от 1С |
| `error_category` | technical, validation, mapping, conflict, authorization, schema |
| `is_retryable` | Можно ли повторять автоматически |

Пользовательский текст не должен содержать внутренние термины вроде `payload`, `exception`, `SQL`, `constraint`, если это не support-only экран.

## Индексы

Целевые индексы для реализации:

| Индекс | Назначение |
| --- | --- |
| `organization_id, created_at` | Основная история организации |
| `organization_id, status, next_retry_at` | Выборка сообщений для retry |
| `organization_id, scope, direction, status` | Фильтры журнала и дашборда |
| `organization_id, source_object_type, source_object_id` | Карточка документа или справочника |
| `organization_id, target_object_type, target_external_id` | Поиск по объекту 1С |
| `idempotency_key` unique для активных сообщений | Защита от дублей |
| `operation_key` | Агрегация повторных операций |
| `correlation_id` | Трассировка incident/debug |
| `payload_snapshot_hash` | Проверка неизменности и дедупликация |
| `organization_id, one_c_base_id, scope, created_at` | Multi-base мониторинг |

Индексы должны учитывать retention: архивные записи можно переносить в отдельную партицию или холодное хранилище.

## Retention

Целевые правила хранения:

- operational journal: 180 дней в горячем доступе;
- support/audit journal по учетным документам: не менее 3 лет или согласно договорной политике клиента;
- raw payload snapshots: минимально необходимый срок, лучше короче журнала, с возможностью отключения хранения полных payload для чувствительных scope;
- payroll-source и персональные данные: отдельная политика retention и masking;
- dead-letter и conflict records: хранить до ручного закрытия плюс audit retention;
- BI-витрины получают агрегаты и ссылки на журнал, но не raw payload.

Удаление или архивирование не должно ломать связи карточек документов: в карточке достаточно summary, последнего sync status и ссылки на архивную запись.

## RBAC и доступы

Целевые права:

| Право | Возможность |
| --- | --- |
| `one_c_exchange.journal.view` | Смотреть список журнала и безопасные preview |
| `one_c_exchange.journal.view_sensitive` | Смотреть расширенный support-only контекст без секретов |
| `one_c_exchange.journal.export` | Экспортировать безопасный отчет журнала |
| `one_c_exchange.retry` | Запускать допустимый manual retry |
| `one_c_exchange.dead_letter.manage` | Переносить dead-letter после устранения причины |
| `one_c_exchange.conflicts.manage` | Открывать и решать конфликты |
| `one_c_exchange.support_diagnostics` | Смотреть технические ссылки и correlation detail |

Даже support-доступ не должен раскрывать токены и секреты. Полный raw payload для платежей и payroll должен открываться только в техническом контуре с отдельным аудитом доступа.

## Связь с доменными контурами

| Контур | Что хранит журнал | Что журнал не делает |
| --- | --- | --- |
| Документы | sync status, accounting status, external id, причины отказов | не меняет операционный workflow без доменного правила |
| Справочники | mapping status, confidence, duplicate/conflict links | не становится MDM-источником вместо MDM |
| Платежи | доставку платежных документов, банковские факты, accounting posted status | не подтверждает фактическое списание денег без банка |
| Склад | отправку движений, приемку 1С, расхождения учета | не заменяет оперативный склад ProHelper или официальный учет 1С |
| Payroll-source | статус пакета источников и приемку внешней системой | не считает юридическую зарплату, налоги и взносы |
| BI | freshness, lag, mismatch count, ссылки на сверки | не меняет первичные факты |

## API-контракт будущего журнала

Базовый префикс: `/api/v1/admin/one-c-exchange/journal`.

### `GET /operations`

Фильтры:

| Параметр | Тип | Назначение |
| --- | --- | --- |
| `scope` | string | Контур обмена |
| `direction` | string | Направление |
| `status` | string | Статус операции |
| `date_from`, `date_to` | date-time | Период |
| `project_id` | integer | Проект |
| `one_c_base_id` | string | База 1С |
| `search` | string | Номер документа, correlation id, external id |

Пример ответа:

```json
{
  "data": [
    {
      "id": 1001,
      "operation_key": "org:15:payments:2026-06-07",
      "correlation_id": "corr_01J...",
      "scope": "payments",
      "direction": "prohelper_to_1c",
      "status": "partial_success",
      "message_count": 25,
      "success_count": 22,
      "failed_count": 1,
      "requires_mapping_count": 2,
      "started_at": "2026-06-07T09:00:00+03:00",
      "finished_at": "2026-06-07T09:04:10+03:00"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 1
  },
  "summary": {
    "oldest_pending_message_age_minutes": 12,
    "dead_letter_count": 0,
    "requires_mapping_count": 2
  }
}
```

### `GET /messages`

Фильтры: `operation_id`, `source_object_type`, `source_object_id`, `status`, `error_category`, `correlation_id`, `idempotency_key`, `external_id`.

Ответ должен отдавать только `safe_payload_preview`, безопасные ошибки и доступные действия.

### `GET /messages/{id}`

Детальная карточка:

- summary;
- source/target object;
- status timeline;
- attempts;
- safe payload preview;
- safe error;
- links to mapping/conflict/dead-letter;
- actions allowed by RBAC and status.

### `POST /messages/{id}/retry`

Разрешено только для сообщений, где `is_retryable=true`, нет активного mapping/conflict blocker и payload hash не устарел.

Request:

```json
{
  "reason": "Исправлена недоступность базы 1С",
  "force_manual": false
}
```

### `POST /messages/{id}/cancel`

Разрешено только до фактической отправки или по доменному правилу отмены. Нельзя отменять уже `accepted` или `posted` сообщение без корректирующего события.

### `GET /messages/{id}/support-diagnostics`

Support-only endpoint. Возвращает технические ссылки, но не токены и секреты. Доступ должен аудироваться.

## Admin UI сценарии

### Список журнала

Сценарии:

- фильтр по организации, проекту, scope, направлению, статусу, периоду;
- быстрые сегменты: "Требуют действия", "В retry", "Dead-letter", "Конфликты", "Проведены 1С";
- поиск по номеру документа, external id, correlation id;
- группировка по operation и раскрытие сообщений;
- безопасные статусы: "Передан в 1С", "Ожидает сопоставления", "Требует сверки", "Отражен в учете".

### Карточка сообщения

Вкладки:

- "Сводка": объект, статус, следующий шаг;
- "История": timeline статусов и attempts;
- "Данные": safe preview без raw payload;
- "Ошибки": понятная причина и действие;
- "Связи": документ, маппинг, конфликт, dead-letter;
- "Поддержка": только при повышенном праве, без секретов.

### Действия

Кнопки зависят от статуса:

- retry для технических ошибок;
- открыть маппинг при `requires_mapping`;
- открыть конфликт при `conflict`;
- requeue из `dead_letter` после устранения причины;
- экспорт безопасного отчета.

Нельзя показывать пользователю raw payload, stack trace, токены и секреты.

## Открытые вопросы

- Нужен ли отдельный справочник `one_c_base_id` для организаций с несколькими базами 1С или достаточно настройки в интеграционном профиле.
- Где хранить полный payload snapshot: encrypted DB column, S3 с server-side encryption или отдельное audit-хранилище.
- Какой retention нужен клиентам по платежам и payroll-source с учетом персональных данных.
- Требуется ли отдельный support-only интерфейс или достаточно расширенного режима в admin UI.
