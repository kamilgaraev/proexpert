# PHERP-84. Нормализованные источники платежного календаря

## Цель

PHERP-84 формирует backend-слой источников для платежного календаря PHERP-23 и последующего прогноза кассового разрыва. Слой не заменяет бухгалтерский учет и не моделирует регламентированные операции. Его задача — собрать управленческие денежные ожидания и факты из существующих моделей МОСТ в единый `PaymentCalendarItem`.

Связь с PHERP-86: каждый календарный элемент должен без потери ключевых измерений превращаться в `CashGapForecastItem`. Для этого обязательны `organization_id`, `cash_flow_key`, `probability` и `original_date`.

## Нормализованная модель

`PaymentCalendarItem` содержит:

| Поле | Назначение |
| --- | --- |
| `organization_id` | Организация-владелец денежного потока. Обязательное поле и главный фильтр. |
| `date` | Рабочая дата календаря. Для графика оплат это дата строки графика, для перенесенного документа — `scheduled_at`, для факта — дата списания или проведения. |
| `original_date` | Исходная дата, если рабочая дата была изменена. Для переноса документа берется `due_date`, если она отличается от рабочей даты. |
| `direction` | `inflow` или `outflow`. |
| `bucket` | `fact`, `scheduled`, `approved`, `reserved`, `overdue`, `budget_plan`, `manual`. |
| `amount` | Исходная сумма источника. |
| `remaining_amount` | Сумма, которая участвует в будущем календаре и cash gap. Для факта равна `amount`; для частично оплаченного документа равна остатку. |
| `currency` | Валюта источника, по умолчанию `RUB`. |
| `probability` | Вероятность включения потока в сценарий. Для фактов и исходящих обязательств — `1.0`; для ожидаемых входящих документов зависит от статуса; для бюджета — `0.6`. |
| `status` | Статус исходной модели без технического преобразования в роль или бухгалтерскую стадию. |
| `source_type` | Нормализованный тип источника: `payment_document`, `payment_schedule`, `payment_transaction`, `budget_limit_reservation`, `budget_amount`. |
| `source_id` | Идентификатор строки-источника. |
| `cash_flow_key` | Стабильный ключ денежного потока для дедупликации между календарем и PHERP-86. |
| `project_id` | Проект, если он есть в текущей модели. |
| `counterparty_id` | Контрагент, если его можно получить из платежного документа, транзакции, резерва или бюджетной строки. |
| `budget_article_id` | Статья бюджета, если источник связан с БДДС или бюджетным лимитом. |
| `responsibility_center_id` | ЦФО, если источник связан с бюджетным контуром. |
| `editable` | Признак, что источник можно менять из календарного сценария в будущем UI. Факты, резервы и бюджетный план не редактируются через календарь. |
| `drill_down` | Минимальные идентификаторы для перехода к исходной сущности. |

## Реализованные источники

### Платежные документы и заявки

Источник: `App\BusinessModules\Core\Payments\Models\PaymentDocument`.

Используются активные статусы:

- `submitted`
- `pending_approval`
- `approved`
- `scheduled`
- `partially_paid`

Не включаются `draft`, `paid`, `rejected`, `cancelled`. Полностью оплаченные документы попадают в календарь фактов через транзакции.

Правила:

- `date` = `scheduled_at ?? due_date ?? document_date`;
- `original_date` = `due_date`, если она отличается от `date`;
- `direction`: `incoming -> inflow`, `outgoing -> outflow`;
- `amount` = `amount`;
- `remaining_amount` = `remaining_amount`, а если поле пустое — `amount - paid_amount`;
- `bucket = overdue`, если рабочая дата прошла или заполнен `overdue_since`;
- `bucket = scheduled` для статуса `scheduled`;
- остальные активные документы дают `bucket = approved`;
- `cash_flow_key = payment-document:{id}`.

### Графики оплат

Источник: `App\BusinessModules\Core\Payments\Models\PaymentSchedule`.

Берутся только неоплаченные строки `status = pending`, связанные с платежным документом нужной организации.

Правила:

- дата строки графика имеет приоритет над датой документа;
- `date` = `payment_schedules.due_date`;
- `original_date` = рабочая дата документа, если она отличается от даты строки графика;
- `remaining_amount` = `amount - paid_amount`;
- `bucket = overdue`, если дата строки графика прошла;
- иначе `bucket = scheduled`;
- `cash_flow_key = payment-document:{payment_document_id}:payment-schedule:{schedule_id}`.

Если документ разбит на строки графика, агрегированный документ и его резерв не должны одновременно увеличивать будущие обязательства. Сервис сбора исключает агрегированный документ и резерв этого документа, когда в периоде есть активные строки графика.

### Фактические платежные транзакции

Источник: `App\BusinessModules\Core\Payments\Models\PaymentTransaction`.

Берутся только `status = completed`.

Правила:

- `date` = `value_date ?? transaction_date`;
- `bucket = fact`;
- `amount = remaining_amount = amount`;
- направление берется из связанного платежного документа, а если он не загружен — определяется по участию организации как плательщика или получателя;
- `cash_flow_key = payment-transaction:{id}`.

Фактические транзакции не заменяют будущий остаток частично оплаченного документа. Факт и будущий остаток имеют разные ключи, потому что это разные денежные движения.

### Активные бюджетные резервы

Источник: `App\BusinessModules\Features\Budgeting\Models\BudgetLimitReservation`.

Берутся только `status = reserved`.

Правила:

- резерв всегда считается исходящим управленческим обязательством;
- если есть платежный документ, дата берется из него;
- иначе дата берется из `period_month`;
- `amount = remaining_amount = amount`;
- `cash_flow_key = payment-document:{payment_document_id}`, если резерв связан с документом;
- иначе `cash_flow_key = budget-limit-reservation:{id}`.

Резервы со статусами `released` и `converted` не являются активным будущим обязательством и не включаются в календарь.

### БДДС план и прогноз

Источники:

- `App\BusinessModules\Features\Budgeting\Models\BudgetVersion`;
- `App\BusinessModules\Features\Budgeting\Models\BudgetLine`;
- `App\BusinessModules\Features\Budgeting\Models\BudgetAmount`;
- `App\BusinessModules\Features\Budgeting\Models\BudgetArticle`.

Без новой миграции доступны месячные строки бюджета:

- `BudgetVersion.organization_id`;
- `BudgetVersion.budget_kind`;
- `BudgetVersion.status`;
- `BudgetLine.project_id`;
- `BudgetLine.counterparty_id`;
- `BudgetLine.budget_article_id`;
- `BudgetLine.responsibility_center_id`;
- `BudgetAmount.month`;
- `BudgetAmount.plan_amount`;
- `BudgetAmount.forecast_amount`;
- `BudgetAmount.currency`;
- `BudgetArticle.flow_direction`.

Берутся версии:

- `budget_kind` = `bdds` или `consolidated`;
- `status` = `approved` или `active`.

Правила:

- `date` = первый день месяца из `BudgetAmount.month`;
- `amount` = `forecast_amount`, если он больше нуля, иначе `plan_amount`;
- направление определяется по `BudgetArticle.flow_direction`: `income`/`inflow` -> `inflow`, `expense`/`outflow` -> `outflow`;
- `bucket = budget_plan`;
- `cash_flow_key = budget-plan:{budget_line_id}:{month}:{currency}`.

Ограничение: текущая модель БДДС хранит план и прогноз на уровне месяца. Ежедневное распределение внутри месяца в PHERP-84 не реализуется, потому что такой источник отсутствует в текущей схеме.

## Дедупликация и приоритеты

Один денежный поток не должен занижать остаток двойным учетом. Для этого используются стабильные `cash_flow_key` и приоритет источников:

1. `fact`
2. `overdue`
3. `scheduled`
4. `approved`
5. `reserved`
6. `budget_plan`
7. `manual`

Если два элемента имеют одинаковый `cash_flow_key`, остается элемент с более высоким приоритетом. Это закрывает сценарий, когда активный резерв и платежный документ представляют одно и то же обязательство.

Для графиков оплат используется дополнительное правило: строки графика являются детализацией документа, поэтому агрегированный документ и резерв этого документа исключаются при сборе календаря, если активные строки графика уже попали в период.

## Маппинг в CashGapForecastItem

`PaymentCalendarItem::toCashGapForecastItem()` передает в PHERP-86:

- `date`;
- `direction`;
- bucket cash gap;
- `remaining_amount` как сумму прогноза;
- `probability`;
- `organization_id`;
- `project_id`;
- `counterparty_id`;
- `budget_article_id`;
- `responsibility_center_id`;
- `currency`;
- `source_type`;
- `source_id`;
- `original_date`;
- `cash_flow_key`.

Правила bucket:

| Calendar bucket | Inflow | Outflow |
| --- | --- | --- |
| `fact` | `actual_inflow` | `actual_outflow` |
| `scheduled` | `planned_inflow` | `scheduled_outflow` |
| `approved` | `planned_inflow` | `approved_outflow` |
| `reserved` | не используется как входящий поток | `reserved_outflow` |
| `overdue` | `overdue_inflow` | `overdue_outflow` |
| `budget_plan` | `planned_inflow` | `approved_outflow` |
| `manual` | `manual_adjustment` | `manual_adjustment` |

Ограничение: в текущем `CashGapForecastItem` нет отдельного bucket для плановых исходящих бюджетных строк. Поэтому `budget_plan` с направлением `outflow` временно маппится в `approved_outflow`. Это нужно пересмотреть, если PHERP-86 будет расширять таксономию bucket-ов.

## Границы 1С и бухгалтерского учета

1С в этом слое используется только как источник сверки и внешних идентификаторов. Поля `one_c_base_id` и `integration_profile_id`, если они есть в связанных сущностях, не превращают календарь в бухгалтерский учет.

PHERP-84 не реализует:

- бухгалтерские проводки;
- налоговые регистры;
- регламентированную отчетность;
- payroll;
- банковскую выписку как самостоятельный источник факта, если она не нормализована в `PaymentTransaction`;
- бухгалтерские операции 1С.

Эти границы согласованы с уже существующими ERP-roadmap документами о запрете дублирования бухгалтерии.

## Права доступа

PHERP-84 добавляет backend-сервис сбора источников и не добавляет новый пользовательский route или действие. Поэтому новые permissions не вводятся.

Если в PHERP-23 появится отдельный endpoint платежного календаря, его нужно привязать к существующим правам платежного контура или явно добавить новый permission с русскими названиями в `config/RoleDefinitions` и `lang/ru/permissions.php`.

## Ограничения текущей реализации

- Таблица ручных календарных корректировок отсутствует, поэтому `manual` определен в модели, но не собирается сервисом.
- План БДДС доступен только помесячно.
- Источники договорных графиков вне `PaymentSchedule` не реализованы, если они не нормализованы в платежный документ или график оплат.
- Факт берется только из `PaymentTransaction` со статусом `completed`.
- Резервы берутся только из `BudgetLimitReservation` со статусом `reserved`.
- Сервис не запускает миграции и не требует новых таблиц.
