# Очередь обмена, idempotency и retry ProHelper и 1С

Дата: 2026-06-07
Задача: PHERP-66
Статус документа: аналитика для проверки

## Контекст

Документ задает целевую модель очереди обмена ProHelper и 1С. Текущий `OneCBasicExchange` выполняет ручные import/export операции и не содержит production-очередь, ordering, dead-letter, lease-locking и промышленную idempotency-модель.

Границы:

- ProHelper остается операционной ERP.
- 1С остается бухгалтерским и налоговым source of truth.
- Операционные статусы ProHelper не равны учетным статусам 1С.
- ProHelper не становится бухгалтерским ядром.
- Raw payload, stack trace, токены и секреты нельзя показывать пользователям.

## Разделение command, event, message и attempt

| Уровень | Назначение | Пример | Повторяемость |
| --- | --- | --- | --- |
| Command | Намерение выполнить действие | "отправить утвержденные платежи за 07.06" | может быть вызван повторно, но должен схлопываться по `operation_key` |
| Event | Бизнес-факт, из которого рождается обмен | "акт утвержден", "поступление принято на склад" | неизменяемый факт, повторная обработка безопасна |
| Message | Конкретное сообщение для 1С или ProHelper | "экспорт акта 123 в базу A" | защищается `idempotency_key` |
| Attempt | Одна техническая попытка доставки message | HTTP request, file upload, callback processing | может повторяться до лимита |

Очередь должна работать на уровне `message`, а не на уровне UI-команды. UI-команда может породить несколько сообщений, а каждое сообщение может иметь несколько attempts.

## Operation key

`operation_key` нужен, чтобы не создавать несколько одинаковых групповых операций при повторном клике, расписании или restart worker-а.

Правило формирования:

```text
organization:{organization_id}:base:{one_c_base_id}:scope:{scope}:command:{command_type}:period:{period_or_batch}:actor:{actor_kind}
```

Примеры:

- `organization:15:base:main:scope:payments:command:export_approved:period:2026-06-07:actor:schedule`
- `organization:15:base:main:scope:warehouse:command:export_movements:period:2026-W23:actor:manual`
- `organization:15:base:zup:scope:payroll:command:export_period:period:2026-05:actor:user:7`

Правила:

- повторная команда с тем же `operation_key` должна возвращать существующую активную operation;
- новая operation создается только при новом периоде, scope, базе 1С, action или явном repair-mode;
- для manual repair нужен новый `operation_key`, но сообщения внутри все равно защищаются `idempotency_key`.

## Idempotency key

`idempotency_key` защищает от дублей документов и справочников.

Базовая формула:

```text
org:{organization_id}:base:{one_c_base_id}:direction:{direction}:scope:{scope}:entity:{type}:{id}:action:{action}:version:{business_version}
```

Где `business_version`:

- для документов - версия документа, source hash или controlled `updated_at`;
- для справочников - версия MDM/mapping;
- для платежей - версия утвержденного платежного документа, а не любое изменение UI;
- для warehouse movements - неизменяемый movement id и accounting export version;
- для payroll package - period id, package version и source hash.

Правила:

- retry всегда идет с тем же `idempotency_key`;
- если payload изменился после `sent`, нужен новый version и новое корректирующее сообщение, а не тихая перезапись;
- `idempotency_key` должен передаваться в 1С, если формат обмена это поддерживает;
- если 1С не поддерживает ключ, ProHelper сверяет контрольные поля: external id, номер, дата, сумма, контрагент, организация, hash табличной части;
- duplicate с совпадающими контрольными полями считается безопасным повтором;
- duplicate с расхождениями создает conflict.

## Correlation id

`correlation_id` связывает:

- UI-действие или расписание;
- sync operation;
- messages;
- attempts;
- callback от 1С;
- записи журнала и конфликты;
- BI/reconciliation события.

Для ручного запуска correlation id можно показывать пользователю как "код обращения в поддержку". Он не должен содержать персональные данные, номера счетов или секреты.

## Очередь сообщений

Целевая очередь должна поддерживать:

- partitioning по `organization_id`, `one_c_base_id`, `scope`;
- lease-locking сообщений;
- backoff и `next_retry_at`;
- dead-letter после исчерпания policy;
- pause/hold по scope, организации или базе 1С;
- dependency graph для сообщений, которым нужны справочники;
- partial success на уровне элементов batch;
- безопасный manual requeue.

## Ordering

Ordering нужен не везде одинаково.

| Контур | Правило порядка |
| --- | --- |
| Справочники | Сначала организация, контрагент, проект, материал, склад, сотрудник, затем документы |
| Договоры | Сначала маппинг сторон и проекта, затем договор, затем акты и платежи |
| Платежи | Заявка/документ после утверждения, банковский факт отдельно, accounting posted status возвращает 1С |
| Склад | Приход до списания по связанным партиям, инвентаризация не должна обгонять движения внутри периода |
| Payroll-source | Сначала закрытие/lock периода и source hash, затем package, затем acceptance/rejection |
| BI | Только read model, не блокирует operational exchange |

Для независимых документов допустима параллельная обработка, но внутри одного source object нужно сохранять порядок версий.

## Locking

Сообщение может быть взято в работу только одним worker-ом.

Целевые поля:

- `locked_at`;
- `locked_by_worker`;
- `lock_expires_at`;
- `attempt_id`;
- `processing_started_at`.

Правила:

- worker берет только сообщения со статусом `queued` или `retrying`, где `next_retry_at <= now`;
- lease истекает при зависшем worker-е;
- повторный worker обязан проверить `idempotency_key` и состояние target object перед отправкой;
- ручной retry не должен запускать параллельную попытку, если активный lock еще жив.

## Deduplication

Deduplication выполняется на нескольких слоях:

| Слой | Правило |
| --- | --- |
| Command | один активный `operation_key` |
| Message | один активный `idempotency_key` |
| Attempt | повтор не создает новый target object |
| Callback | ответ 1С схлопывается по `correlation_id` или внешнему id |
| Import | файл/пакет проверяется по checksum и source system id |

Если пришел повторный callback 1С с тем же статусом, он обновляет timestamps и audit, но не меняет бизнес-состояние повторно.

## Retry policy

| Категория | Автоматический retry | Manual requeue | Комментарий |
| --- | --- | --- | --- |
| Сетевая ошибка | да | да | backoff с тем же idempotency key |
| Timeout | ограниченно | да | перед повтором проверить, не приняла ли 1С документ |
| Rate limit / overload | да | да | увеличить backoff и снизить parallelism |
| 5xx от шлюза | да | да | если нет признака бизнес-отказа |
| Validation error 1С | нет | после исправления данных | новое сообщение или manual requeue по правилу |
| Requires mapping | нет | после маппинга | requeue только после активного mapping |
| Conflict | нет | после решения конфликта | retry запрещен до audit resolution |
| Unauthorized | нет | после исправления доступа | scope hold |
| Schema mismatch | нет | после обновления схемы | scope hold |
| Already posted in 1С | нет | только корректировка | нельзя повторно создавать документ |
| Payroll legal rejection | нет | после решения HR/payroll owner | ProHelper не считает зарплату сам |

Backoff по умолчанию: сразу, 1 минута, 5 минут, 15 минут, 1 час, 3 часа. После лимита сообщение уходит в `dead_letter` или conflict review.

## Dead-letter

`dead_letter` означает, что автоматическая обработка прекращена.

Причины:

- превышен лимит попыток;
- неоднозначный timeout после проверки состояния 1С;
- повторяющийся schema mismatch;
- scope поставлен на hold;
- сообщение устарело относительно source object;
- detected duplicate conflict.

Из `dead_letter` нельзя возвращать сообщение без:

- проверки актуального source hash;
- проверки mapping/conflict blockers;
- указания причины ручного requeue;
- audit trail пользователя;
- сохранения исходной истории attempts.

## Manual requeue

Ручной requeue разрешен только для пользователей с отдельным правом и только если действие безопасно.

Request будущего API:

```json
{
  "reason": "Сопоставлен контрагент и исправлены реквизиты",
  "expected_effect": "Повторная отправка платежного документа",
  "use_same_idempotency_key": true
}
```

Правила:

- для technical retry используется тот же `idempotency_key`;
- после изменения бизнес-полей создается новая версия и новый message;
- для `posted` документов requeue запрещен, нужна корректировка;
- для payroll-source requeue запрещен, если source hash изменился после lock периода.

## Частичный успех

Batch может завершиться частично:

- accepted items фиксируются как успешные;
- rejected items получают item-level message;
- requires_mapping items блокируются до маппинга;
- operation получает агрегированный `partial_success`;
- повтор batch не должен заново отправлять accepted items.

Пример: экспорт 20 складских движений, 18 приняты, 2 требуют маппинга номенклатуры. Повтор после маппинга должен отправить только 2 сообщения.

## Таймауты

Timeout является опасным состоянием: неизвестно, приняла ли 1С документ.

Правила:

- attempt получает статус `timeout`;
- message получает `retrying` или `requires_confirmation`;
- перед повтором нужно прочитать состояние 1С по idempotency key, external id или контрольным полям, если интеграция это поддерживает;
- если подтвердить состояние нельзя, сообщение уходит в manual review после ограниченного числа попыток;
- пользователю показывается безопасный текст: "Ожидаем подтверждение от 1С".

## Защита от дублей в 1С и ProHelper

В ProHelper:

- уникальность активного `idempotency_key`;
- unique mapping по organization, one_c_base, scope, local object, external object;
- source hash перед retry;
- запрет повторной обработки callback.

В 1С:

- хранить idempotency key в дополнительном реквизите или регистре интеграции;
- проверять номер, дату, сумму, контрагента, организацию, проект;
- возвращать existing external id при повторе с тем же ключом;
- возвращать conflict, если ключ или контрольные поля указывают на разные объекты.

## Lifecycle job, message, attempt

| Уровень | Статусы |
| --- | --- |
| Job/operation | `created`, `building_messages`, `queued`, `running`, `partial_success`, `completed`, `failed`, `cancelled` |
| Message | `draft`, `queued`, `locked`, `sent`, `accepted`, `posted`, `rejected`, `failed`, `retrying`, `requires_mapping`, `conflict`, `dead_letter`, `manually_resolved` |
| Attempt | `created`, `started`, `delivered`, `timeout`, `failed`, `completed`, `cancelled` |

Message lifecycle не должен автоматически менять операционный статус документа. Доменный сервис документа должен сам решить, какие поля обновить после ответа 1С.

## Мониторинг и метрики

Минимальный набор:

- `queued_count` по organization/scope/direction;
- `oldest_pending_message_age`;
- `retrying_count`;
- `failed_count`;
- `dead_letter_count`;
- `requires_mapping_count`;
- `conflict_count`;
- `average_attempt_count`;
- `timeout_rate`;
- `duplicate_detected_count`;
- `schema_version_mismatch_count`;
- `accounting_posted_lag`;
- `bank_fact_lag`;
- `payroll_package_acceptance_lag`;
- `reconciliation_mismatch_count`.

Alert-примеры:

- старейшее сообщение payments старше 30 минут;
- dead-letter > 0 по платежам;
- warehouse movements не выгружаются больше 4 часов;
- payroll package не принят внешней системой в SLA;
- schema mismatch по любому scope.

## Тестовые сценарии отказов

| Сценарий | Ожидание |
| --- | --- |
| Повторный клик "Экспортировать" | создается одна operation или возвращается существующая |
| Worker упал после отправки, но до записи ответа | retry проверяет состояние 1С и не создает дубль |
| 1С вернула timeout, документ фактически создан | повтор принимает existing external id |
| 1С вернула duplicate с другой суммой | создается conflict, retry запрещен |
| Нет маппинга контрагента | message `requires_mapping`, автоматического retry нет |
| Частичный успех batch | accepted items не переотправляются |
| Истек lock worker-а | другой worker может взять message после lease expiration |
| Payroll source hash изменился после lock | requeue запрещен, нужен новый пакет |
| Складское списание обогнало приход | ordering блокирует списание до прихода |
| Схема 1С устарела | scope hold, безопасная ошибка пользователю |

## API будущей очереди

Базовый префикс: `/api/v1/admin/one-c-exchange/queue`.

| Endpoint | Назначение |
| --- | --- |
| `GET /messages` | Очередь сообщений с фильтрами |
| `GET /messages/{id}` | Детали message и attempts |
| `POST /messages/{id}/retry` | Безопасный retry |
| `POST /messages/{id}/requeue` | Возврат из dead-letter после проверки |
| `POST /messages/{id}/hold` | Поставить message на hold |
| `POST /scopes/{scope}/pause` | Пауза scope для организации/базы |
| `POST /scopes/{scope}/resume` | Возобновление scope |
| `GET /metrics` | Метрики очереди |

Все действия должны возвращать стандартизированный `AdminResponse`, safe message и audit id.

## Открытые вопросы

- Использовать ли встроенную Laravel queue как транспорт или отдельную таблицу outbox/inbox для строгой idempotency и аудита.
- Какой SLA нужен по payments, warehouse, payroll и BI для разных тарифов.
- Поддерживает ли целевая обработка 1С хранение idempotency key и lookup по нему.
- Нужен ли отдельный test-contour 1С для автоматических failure сценариев.
