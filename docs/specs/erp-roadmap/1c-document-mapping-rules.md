# Правила маппинга документов ProHelper и 1С

Дата: 2026-06-07
Задача: PHERP-70
Статус документа: аналитика для проверки

## Контекст

Документ фиксирует правила обмена документами ProHelper и 1С. Он опирается на разделение трех осей: операционный статус ProHelper, sync status exchange-слоя и учетный статус 1С.

Границы:

- ProHelper остается строительной ERP и операционным source of truth.
- 1С остается бухгалтерским и налоговым source of truth.
- Операционные статусы ProHelper не равны учетным статусам 1С.
- ProHelper не становится бухгалтерским ядром.
- Raw payload, stack trace, токены и секреты нельзя показывать пользователям.

## Общие правила

- Документ отправляется в 1С только после выполнения доменного условия готовности и обязательных mapping rules.
- После `sent` критичные поля блокируются от тихого редактирования. Изменения идут через новую версию, корректировку или сторно-событие.
- `accepted` означает, что 1С приняла документ или пакет, но не обязательно провела его.
- `posted` или `accounted` означает только подтверждение 1С.
- `rejected` является бизнес-отказом и не повторяется автоматически.
- `failed` является технической ошибкой и может повторяться с тем же idempotency key.
- `conflict` требует ручного решения.
- 1С не должна обновлять обратно операционные статусы ProHelper, если field ownership принадлежит ProHelper.

## Глобальная модель полей

| Группа полей | Владелец | Правило обратного обновления |
| --- | --- | --- |
| Операционный lifecycle | ProHelper | 1С не меняет |
| Бухгалтерская приемка и проведение | 1С | ProHelper хранит отдельно как accounting status |
| Номера и даты ProHelper | ProHelper | 1С может вернуть внешний номер, но не перезаписывает локальный |
| Внешний id, учетный код, accounting reference | 1С | сохраняется в mapping/status |
| Сумма, строки, контрагент, проект после отправки | владелец документа ProHelper до отправки, после отправки через версию/корректировку | не обновлять тихо из 1С |
| Банковский факт | банк или 1С как внешний факт | не равен локальному `paid` без сверки |
| Payroll legal result | 1С/ЗУП | ProHelper хранит только acceptance/rejection и source rows |

## Договоры

| Правило | Значение |
| --- | --- |
| Owner/source of truth | ProHelper по операционному договору; 1С по учетной карточке и бухгалтерскому отражению |
| Когда создается событие | Договор активирован или утвержден для учета; допсоглашение или изменение критичных полей после отправки создает новую версию |
| Обязательные поля | organization mapping, counterparty mapping, project mapping, номер, дата, предмет, категория, сумма/валюта, срок действия |
| Field-level ownership | статус договора, проект, предмет, workflow - ProHelper; external id, accounting status - 1С |
| Табличные части | распределение по проектам/сметам, график платежей, статьи затрат при необходимости |
| Статусы ProHelper | `draft`, `active`, `completed`, `on_hold`, `terminated` |
| Sync status | `draft`, `queued`, `sent`, `accepted`, `posted/accounted`, `rejected`, `failed`, `conflict` |
| Учетные статусы 1С | `not_created`, `accepted`, `posted`, `rejected`, `reversed` |
| После `sent` | номер, дата, стороны, сумма, проект и валюта меняются только через новую версию или корректировку |
| При `rejected/failed/conflict` | rejected - исправление бизнес-данных; failed - retry; conflict - ручная сверка external id |
| Нельзя обновлять из 1С | операционный статус, участники проекта, внутренний workflow, бюджетные связи |
| Не бухгалтерский факт | `active` или `completed` в ProHelper не означает проведение договора в 1С |

## Акты

| Правило | Значение |
| --- | --- |
| Owner/source of truth | ProHelper по выполнению работ и операционной приемке; 1С по учетному проведению |
| Когда создается событие | Акт утвержден или подписан в операционном контуре и готов к учету |
| Обязательные поля | contract mapping, counterparty mapping, project mapping, номер акта, дата, период, сумма, НДС при применимости |
| Field-level ownership | состав работ, период, сумма и approval - ProHelper; accounting posted status - 1С |
| Табличные части | строки работ/сметных позиций, суммы, НДС, аналитика проекта/статьи |
| Статусы ProHelper | `draft`, `approved`, `signed` для текущего `ContractPerformanceAct`; если есть расширенный актовый workflow, статусная ось остается операционной |
| Sync status | `queued`, `sent`, `accepted`, `posted`, `rejected`, `failed`, `requires_mapping`, `conflict` |
| Учетные статусы 1С | `accepted`, `posted`, `rejected`, `reversed` |
| После `sent` | сумма, период, договор и строки блокируются; исправление через корректировочный акт |
| При `rejected/failed/conflict` | бизнес-отказ возвращается владельцу акта; retry только для technical failure |
| Нельзя обновлять из 1С | факт выполнения работ, операционную подпись, связь с нарядами/сметой |
| Не бухгалтерский факт | `approved` и `signed` в ProHelper не равны бухгалтерскому `posted` |

## Платежные заявки

| Правило | Значение |
| --- | --- |
| Owner/source of truth | ProHelper по заявке, согласованию и планированию платежа |
| Когда создается событие | Заявка утверждена и должна попасть в учетный/платежный контур |
| Обязательные поля | organization, payer/payee mapping, договор/акт при наличии, сумма, валюта, назначение, due date, проект/статья |
| Field-level ownership | approval workflow, due date, приоритет, связь с проектом - ProHelper |
| Табличные части | распределение по статьям бюджета, объектам и основаниям |
| Статусы ProHelper | `draft`, `submitted`, `pending_approval`, `approved`, `scheduled`, `rejected`, `cancelled` |
| Sync status | `queued`, `sent`, `accepted`, `rejected`, `failed`, `requires_mapping`, `conflict` |
| Учетные статусы 1С | `not_created`, `accepted`, `rejected`; проведение зависит от типа документа 1С |
| После `sent` | сумма, получатель, основание и назначение меняются через новую версию или отмену |
| При `rejected/failed/conflict` | rejected - исправление реквизитов; failed - retry; conflict - сверка получателя/основания |
| Нельзя обновлять из 1С | маршрут согласования, внутренний приоритет, операционные комментарии |
| Не бухгалтерский факт | `approved` не значит, что платеж отражен в бухгалтерии или исполнен банком |

## Платежные документы

| Правило | Значение |
| --- | --- |
| Owner/source of truth | ProHelper по операционному платежному документу; банк по факту движения денег; 1С по учетному отражению |
| Когда создается событие | Документ утвержден/запланирован или готов к клиент-банку/1С |
| Обязательные поля | payer/payee, счет, БИК, сумма, дата, назначение, договор/акт/счет, проект, статья затрат |
| Field-level ownership | workflow и связь с заявкой - ProHelper; accounting status - 1С; bank execution - банк |
| Табличные части | распределение суммы по основаниям, проектам, статьям |
| Статусы ProHelper | `approved`, `scheduled`, `paid`, `partially_paid`, `cancelled` и другие из PaymentDocument |
| Sync status | `queued`, `sent`, `accepted`, `posted`, `rejected`, `failed`, `conflict` |
| Учетные статусы 1С | `accepted`, `posted`, `rejected`, `reversed` |
| После `sent` | сумма, получатель и реквизиты блокируются; исправление через отмену/корректировку |
| При `rejected/failed/conflict` | mapping/validation blockers решаются вручную, technical failures retry |
| Нельзя обновлять из 1С | локальный approval, внутренний статус согласования, связь с workflow |
| Не бухгалтерский факт | `paid` в ProHelper не равно бухгалтерскому проведению без банковского и/или 1С подтверждения |

## Банковские факты

| Правило | Значение |
| --- | --- |
| Owner/source of truth | Банк по факту списания/поступления; 1С по бухгалтерскому отражению; ProHelper по операционной сверке |
| Когда создается событие | Импорт client-bank, callback банка или подтверждение 1С о проведении платежа |
| Обязательные поля | дата операции, сумма, счет, контрагент, назначение, банковский id, направление |
| Field-level ownership | банковский id и факт движения - внешний источник; match status - ProHelper |
| Табличные части | сопоставление с несколькими платежными документами при частичной оплате |
| Статусы ProHelper | transaction `pending`, `processing`, `completed`, `failed`, `cancelled`, `refunded` |
| Sync status | `imported`, `matched`, `requires_review`, `accepted`, `posted`, `conflict` |
| Учетные статусы 1С | `posted`, `rejected`, `reversed` |
| После `sent/accepted` | банковский факт не редактируется вручную, только сторно/корректировка |
| При `rejected/failed/conflict` | ручная сверка суммы, даты, счета, назначения |
| Нельзя обновлять из 1С | операционный workflow заявки и внутреннюю причину платежа |
| Не бухгалтерский факт | локальная отметка transaction `completed` не равна accounting `posted` |

## Закупки

| Правило | Значение |
| --- | --- |
| Owner/source of truth | ProHelper по цепочке request -> supplier request/proposal -> purchase order; 1С по учетному поступлению/счету |
| Когда создается событие | Purchase order подтвержден, создан договор/счет, поступление принято |
| Обязательные поля | supplier mapping, project, warehouse/material mapping, order number/date, суммы, позиции, единицы |
| Field-level ownership | выбор поставщика, статусы снабжения, связи с заявками - ProHelper |
| Табличные части | позиции заказа, количество, цена, НДС, материалы, склад, проект |
| Статусы ProHelper | order `draft`, `sent`, `confirmed`, `in_delivery`, `partially_delivered`, `delivered`, `cancelled` |
| Sync status | `queued`, `sent`, `accepted`, `posted`, `rejected`, `failed`, `requires_mapping`, `conflict` |
| Учетные статусы 1С | заказ/поступление `accepted`, `posted`, `rejected` |
| После `sent` | supplier, позиции, цены и количества меняются через версию или корректировку |
| При `rejected/failed/conflict` | проверка supplier/material/warehouse mapping, item-level retry |
| Нельзя обновлять из 1С | операционный выбор поставщика, статусы снабжения, внутренний audit |
| Не бухгалтерский факт | `confirmed` или `delivered` не означает учетное поступление в 1С |

## Приход и складские движения

| Правило | Значение |
| --- | --- |
| Owner/source of truth | ProHelper по оперативному складу стройки; 1С по официальному складскому и стоимостному учету, если включен |
| Когда создается событие | Приход, списание, перемещение, возврат, корректировка или приемка на объект утверждены |
| Обязательные поля | warehouse mapping, material mapping, quantity, unit, date, movement type, project, source document |
| Field-level ownership | оперативные остатки, резервы, mobile scan - ProHelper; учетная партия/стоимость - 1С |
| Табличные части | строки материалов, партии/серии при наличии, количество, цена, склад, проект |
| Статусы ProHelper | movement immutable; delivery `requested`, `processing`, `reserved`, `in_transit`, `delivered`, `accepted`, `problem`, `cancelled` |
| Sync status | `queued`, `sent`, `accepted`, `posted`, `rejected`, `failed`, `conflict` |
| Учетные статусы 1С | складской документ `accepted`, `posted`, `rejected`, `reversed` |
| После `sent` | движение не редактируется, создается корректирующее движение |
| При `rejected/failed/conflict` | mapping материалов/склада, расхождения количества и партий в review |
| Нельзя обновлять из 1С | оперативный резерв, mobile scan events, материальную ответственность на площадке |
| Не бухгалтерский факт | оперативный `receipt`/`write_off` не равен официальному складскому проведению 1С |

## Инвентаризация

| Правило | Значение |
| --- | --- |
| Owner/source of truth | ProHelper по факту инвентаризации площадки; 1С по учетной корректировке |
| Когда создается событие | Inventory act завершен и утвержден |
| Обязательные поля | warehouse mapping, inventory date, commission, строки факта, расхождения, причины |
| Field-level ownership | фактический пересчет и комиссии - ProHelper; учетная корректировка - 1С |
| Табличные части | material, expected qty, actual qty, diff, unit, batch/serial при наличии |
| Статусы ProHelper | `draft`, `in_progress`, `completed`, `approved`, `cancelled` |
| Sync status | `queued`, `sent`, `accepted`, `posted`, `rejected`, `failed`, `conflict` |
| Учетные статусы 1С | корректировка `accepted`, `posted`, `rejected` |
| После `sent` | акт блокируется, новые расхождения оформляются новым актом |
| При `rejected/failed/conflict` | сверка складов, материалов, партий, дат |
| Нельзя обновлять из 1С | фактические результаты пересчета |
| Не бухгалтерский факт | `approved` в ProHelper не означает учетную корректировку 1С |

## Бюджетные корректировки

| Правило | Значение |
| --- | --- |
| Owner/source of truth | ProHelper по управленческому бюджету и сметам; 1С по учетной аналитике затрат |
| Когда создается событие | Корректировка утверждена и требует учетной аналитики или сверки |
| Обязательные поля | project mapping, cost category/budget article mapping, сумма, причина, период, версия бюджета |
| Field-level ownership | бюджетный workflow, версии сметы, управленческие лимиты - ProHelper |
| Табличные части | статьи бюджета, ЦФО, проектные разрезы, суммы |
| Статусы ProHelper | зависят от бюджетного workflow, но остаются операционными |
| Sync status | `queued`, `sent`, `accepted`, `rejected`, `failed`, `conflict` |
| Учетные статусы 1С | `accepted`, `posted` только если 1С ведет соответствующий документ |
| После `sent` | корректировка фиксируется версией, изменения через новую корректировку |
| При `rejected/failed/conflict` | проверка статей, ЦФО, проекта и периода |
| Нельзя обновлять из 1С | управленческие лимиты, версии сметы, причины изменения |
| Не бухгалтерский факт | утвержденный бюджет ProHelper не является бухгалтерской проводкой |

## Payroll export packages

| Правило | Значение |
| --- | --- |
| Owner/source of truth | ProHelper по source rows явки/выработки; 1С/ЗУП по юридической зарплате, налогам и взносам |
| Когда создается событие | Payroll period validated, locked, source hash зафиксирован, создан export package |
| Обязательные поля | payroll period, employee mapping/external payroll ref, source rows, work dates, hours, amount, project/work order references |
| Field-level ownership | явка, выработка, source rows - ProHelper; начисление зарплаты, НДФЛ, взносы, отчетность - 1С/ЗУП |
| Табличные части | строки payroll source по сотрудникам, датам, проектам, нарядам |
| Статусы ProHelper | `draft`, `validated`, `locked` для периода и статусы export package |
| Sync status | `queued`, `sent`, `accepted`, `rejected`, `failed`, `requires_mapping`, `conflict` |
| Учетные статусы 1С | package `accepted`, `rejected`, payroll result status при возврате |
| После `sent` | source rows не меняются; при изменении нужен новый package version |
| При `rejected/failed/conflict` | HR/payroll owner исправляет mapping или источник; retry только technical |
| Нельзя обновлять из 1С | табель, выработку, наряды, операционные назначения |
| Не бухгалтерский факт | сумма source row не является юридической зарплатой или налоговым расчетом |

## Общая обработка rejected, failed и conflict

| Состояние | Действие |
| --- | --- |
| `rejected` | Пользователь видит бизнес-причину и next action, автоматический retry запрещен |
| `failed` | Технический retry с тем же idempotency key, если payload актуален |
| `requires_mapping` | Открыть mapping registry, после решения requeue |
| `conflict` | Открыть conflict registry, выбрать ручное решение |
| `dead_letter` | Поддержка проверяет причину, после устранения выполняет requeue с audit |

## Открытые вопросы

- Какие документы в первом этапе должны получать `posted`, а какие только `accepted`.
- Какой формат 1С будет целевым для договоров, актов и warehouse movements: HTTP API, файл обмена или обработка 1С.
- Нужны ли корректировочные документы для всех типов или только для платежей, актов и склада.
- Как показывать бухгалтерские статусы в существующих карточках admin UI без смешения с операционными статусами.
