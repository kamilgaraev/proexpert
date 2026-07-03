# Модель БДР/БДДС, статей бюджета и ЦФО

Задача: PHERP-80, родительские задачи PHERP-21 и PHERP-3.

Дата проектирования: 2026-06-08.

Статус: архитектурная спецификация для проверки. Код, миграции и UI в рамках этой задачи не менялись.

## Цель

Спецификация задает доменную модель управленческого бюджетирования в МОСТ: БДР, БДДС, бюджетные статьи, ЦФО, budget period, scenario, version, plan, fact, forecast, limit и closed period.

Модель должна поддержать строительный ERP-контур без prohibited accounting duplication: МОСТ ведет управленческие бюджеты, лимиты, план-факт и согласования, а 1С остается системой бухгалтерского и налогового учета.

Основные источники контекста:

- `docs/specs/erp-roadmap/adr-prohelper-1c-accounting-boundaries.md`;
- `docs/specs/erp-roadmap/source-of-truth-matrix.md`;
- `docs/specs/erp-roadmap/prohibited-accounting-duplications.md`;
- `docs/specs/erp-roadmap/1c-document-mapping-rules.md`;
- `docs/specs/erp-roadmap/1c-exchange-queue-idempotency.md`;
- `docs/specs/erp-roadmap/1c-sync-journal-model.md`;
- текущие backend-модули платежей, договоров, актов, смет, закупок, склада и справочников.

## Границы МОСТ и 1С

| Контур | МОСТ | 1С | Правило |
| --- | --- | --- | --- |
| Управленческий бюджет | source of truth для БДР, БДДС, лимитов, версий, сценариев и согласований | получает только согласованные управленческие аналитики, если они нужны для сверки | МОСТ не создает бухгалтерские проводки |
| Бюджетные статьи | владелец управленческой иерархии статей | владелец счетов учета, налоговых регистров и бухгалтерских аналитик | связь хранится через mapping, а не через перезапись статей |
| ЦФО | владелец управленческой структуры ответственности | может иметь соответствие учетным подразделениям или cost centers | ЦФО не должен подменять бухгалтерское подразделение без mapping |
| БДР plan/fact/forecast | владелец управленческого план-факта доходов и расходов | владелец регламентного признания доходов и расходов | fact БДР в МОСТ является управленческим, не бухгалтерским |
| БДДС plan/fact/forecast | владелец платежного календаря, заявок, scheduled payments и управленческого cash flow | владелец бухгалтерского отражения банковских операций | fact БДДС берется из подтвержденных платежных транзакций, не из проводок |
| Закрытие периода | управленческий closed period и корректировки бюджета | бухгалтерское закрытие периода | закрытие в МОСТ не равно закрытию месяца в бухгалтерии |
| Документы 1С | формирует пакеты обмена по утвержденным бюджетам и корректировкам | принимает, отклоняет, проводит учетные документы в рамках своей конфигурации | статусы обмена отделены от статусов бюджета |

Запрещено строить в МОСТ план счетов, регистры НДС, налоговую отчетность, бухгалтерское закрытие и официальный payroll. Любая такая попытка считается prohibited accounting duplication.

## Доменные сущности

| Сущность | Назначение | Ключевые поля | Связи |
| --- | --- | --- | --- |
| `BudgetPeriod` | Период бюджетирования: месяц, квартал, год или проектный диапазон | `id`, `organization_id`, `code`, `name`, `period_type`, `starts_at`, `ends_at`, `status` | версии бюджета, закрытия, корректировки |
| `BudgetScenario` | Сценарий бюджета | `id`, `organization_id`, `code`, `name`, `scenario_type`, `is_default`, `is_active` | версии бюджета |
| `BudgetVersion` | Версия БДР, БДДС или консолидированного бюджета | `id`, `organization_id`, `budget_period_id`, `scenario_id`, `budget_kind`, `version_number`, `status`, `activated_at` | строки бюджета, согласования, лимиты, корректировки |
| `BudgetArticle` | Управленческая статья бюджета | `id`, `organization_id`, `parent_id`, `code`, `name`, `budget_kind`, `flow_direction`, `is_leaf`, `is_active` | строки бюджета, факты, mapping с 1С и `cost_categories` |
| `BudgetArticleMapping` | Соответствие статьи внешним аналитикам | `id`, `budget_article_id`, `system`, `one_c_base_id`, `integration_profile_id`, `external_code`, `external_name`, `mapping_status` | статьи, журнал обмена |
| `ResponsibilityCenter` | Центр финансовой ответственности, ЦФО | `id`, `organization_id`, `parent_id`, `center_type`, `code`, `name`, `owner_user_id`, `approver_user_id`, `is_active` | строки бюджета, лимиты, права доступа |
| `BudgetLine` | Плановая строка бюджета | `id`, `budget_version_id`, `budget_article_id`, `responsibility_center_id`, `project_id`, `contract_id`, `counterparty_id`, `currency`, `description` | суммы по периодам, лимиты, факты |
| `BudgetAmount` | Денежное значение строки | `id`, `budget_line_id`, `month`, `plan_amount`, `forecast_amount`, `currency` | план-факт, лимиты |
| `BudgetFactSource` | Правило источника факта | `id`, `budget_kind`, `source_type`, `source_statuses`, `amount_field`, `date_field`, `mapping_rule` | фактические записи |
| `BudgetFact` | Нормализованный управленческий факт | `id`, `organization_id`, `budget_period_id`, `budget_article_id`, `responsibility_center_id`, `project_id`, `source_type`, `source_id`, `source_date`, `amount`, `currency` | drill-down в исходный документ |
| `BudgetLimit` | Ограничение расхода или платежа | `id`, `budget_version_id`, `budget_article_id`, `responsibility_center_id`, `project_id`, `limit_type`, `threshold_amount`, `enforcement_mode` | проверки лимитов, решения по override |
| `BudgetAdjustment` | Корректировка утвержденного бюджета | `id`, `budget_version_id`, `reason`, `status`, `requested_by`, `approved_by`, `applied_at` | строки корректировки, новая версия или delta |
| `BudgetPeriodClosure` | Управленческое закрытие периода | `id`, `budget_period_id`, `closed_by`, `closed_at`, `closure_status`, `reopen_reason` | запрет правок, корректировки |
| `BudgetImportBatch` | Импорт начального бюджета или корректировок | `id`, `organization_id`, `source_format`, `status`, `uploaded_by`, `preview_summary`, `error_summary` | строки импорта, версии бюджета |

## Иерархия статей

Каталог статей должен быть единым для управленческой аналитики, но каждая статья явно указывает область применения:

- `bdr`: доходы и расходы по начислению;
- `bdds`: поступления и выбытия денежных средств;
- `both`: статья используется в обоих отчетах, но с разными правилами факта;
- `technical`: служебная группировка для шаблонов и импорта, без ручного планирования.

Рекомендуемая верхняя структура:

| Верхний раздел | Бюджет | Направление | Примеры |
| --- | --- | --- | --- |
| Выручка по договорам | БДР | income | акты заказчика, выполненные работы, прочие доходы проекта |
| Себестоимость работ | БДР | expense | материалы, субподряд, техника, зарплатные начисления как управленческий источник |
| Коммерческие и управленческие расходы | БДР | expense | офис, продажи, управленческие затраты |
| Поступления | БДДС | inflow | оплаты заказчиков, возвраты авансов, прочие поступления |
| Выплаты | БДДС | outflow | поставщики, субподрядчики, зарплатные выплаты, налоги как платежи |
| Перемещения и корректировки | БДДС | neutral | внутренние переводы, сверочные строки, не влияющие на БДР |

Правила иерархии:

- планирование и mapping разрешены только на листовых статьях;
- родительские статьи считаются агрегатами и не должны принимать fact напрямую;
- статья может ссылаться на существующую `cost_category`, позицию сметы или тип закупки, но не должна зависеть от них как от единственного справочника;
- связь с 1С хранится в `BudgetArticleMapping` по `one_c_base_id`, `integration_profile_id`, организации и внешнему коду;
- удаление статьи с историей запрещено, вместо этого используется деактивация;
- переименование статьи не должно менять исторические версии бюджета.

## Модель ЦФО

ЦФО должен быть отдельной сущностью, а не неявной проекцией проекта, организации или категории затрат. До утверждения этой модели нельзя строить жесткую бюджетную аналитику на скрытых полях вроде `project.cost_category_id`.

Рекомендуемые типы ЦФО:

| Тип | Назначение | Привязки |
| --- | --- | --- |
| `holding` | уровень холдинга или группы компаний | несколько организаций |
| `organization` | юридическое или управленческое лицо | `organizations.id` |
| `project` | строительный проект или объект | `projects.id` |
| `department` | функциональное подразделение | будущий HR/оргструктурный контур |
| `warehouse` | склад или материально ответственная зона | `organization_warehouses.id` |
| `contract` | крупный договор как отдельный центр ответственности | `contracts.id` |
| `functional_area` | закупки, производство, финансы, административный блок | справочник функциональных областей |

У каждого ЦФО должен быть владелец, согласующий, период активности, родительский ЦФО и набор разрешенных бюджетных статей. Это позволит проверять лимиты на уровне проекта, склада, подразделения и организации без смешения управленческой ответственности с бухгалтерской аналитикой 1С.

## Версии и статусы

Статусы `BudgetVersion`:

| Статус | Значение | Доступные действия |
| --- | --- | --- |
| `draft` | черновик версии | редактирование строк, импорт, удаление черновика |
| `on_approval` | отправлено на согласование | просмотр, согласование, отклонение |
| `approved` | согласовано, но не активно | активация, архивирование, экспорт в 1С при необходимости |
| `active` | действующая версия для plan/fact/forecast и limit checks | просмотр, создание корректировки, замена новой версией |
| `replaced` | заменена другой активной версией | только просмотр и аудит |
| `archived` | архивная версия | только просмотр и экспорт |
| `cancelled` | отмененный черновик или согласование | только аудит |

Ключевые правила:

- для комбинации `organization_id + budget_period_id + scenario_id + budget_kind` может быть только одна активная version;
- после перехода в `approved` или `active` строки версии блокируются;
- изменения утвержденного бюджета выполняются через `BudgetAdjustment`;
- активация новой версии переводит предыдущую активную версию в `replaced`;
- закрытый budget period запрещает прямые изменения plan и forecast.

Статусы `BudgetPeriod`:

- `open`: план и прогноз можно менять в черновиках;
- `soft_closed`: fact за период зафиксирован, но допускается короткое окно сверки;
- `closed`: closed period, прямые изменения запрещены;
- `reopened_for_adjustment`: период открыт только для утвержденной корректировки;
- `archived`: период доступен только для просмотра.

## Plan, fact и forecast

### БДР

`plan` БДР берется из активной версии бюджета.

`fact` БДР должен собираться по управленческому начислению, а не по движению денег. Основные кандидаты источников:

- подписанные или утвержденные `ContractPerformanceAct`;
- строки `PerformanceActLine`;
- подтвержденные `CompletedWork`;
- оприходования и списания материалов из `WarehouseMovement`, если они отражают управленческий расход;
- утвержденные подотчетные расходы `AdvanceAccountTransaction`;
- закупочные приемки `PurchaseReceipt`, если они определены как первичный факт затрат.

`forecast` БДР строится из остатка по договорам, открытых работ, утвержденных закупочных обязательств, незакрытых сметных объемов и ожидаемых корректировок.

### БДДС

`plan` БДДС берется из строк активной версии и платежного календаря.

`fact` БДДС должен собираться из завершенных денежных операций:

- `PaymentTransaction` со статусом завершения;
- импортированные банковские выписки после сверки;
- подтвержденные входящие и исходящие платежи;
- взаимозачеты только как отдельный тип, чтобы не смешивать cash flow с неденежным закрытием расчетов.

`payment_documents.paid_amount` и `remaining_amount` не должны быть единственным ledger для БДДС. Эти поля допустимы как денормализованное состояние документа и источник сверки.

`forecast` БДДС строится из:

- approved и scheduled `PaymentDocument`;
- `PaymentSchedule`;
- подтвержденных `PurchaseOrder`;
- договорных графиков оплат;
- открытых заявок на платеж;
- forecast-to-complete по проекту.

## Drill-down факта

Каждая строка plan/fact/forecast должна позволять перейти к источнику:

- `source_type`;
- `source_id`;
- `source_document_number`;
- `source_date`;
- `organization_id`;
- `project_id`;
- `contract_id`;
- `counterparty_id`;
- `purchase_order_id`;
- `warehouse_movement_id`;
- `act_id`;
- `payment_document_id`;
- `payment_transaction_id`;
- `budget_article_id`;
- `responsibility_center_id`;
- `fact_source`;
- `one_c_sync_status`;
- `accounting_status`.

`accounting_status` не должен управлять бюджетным статусом. Он нужен только для отображения состояния обмена и сверки с 1С.

## Лимиты

Лимит проверяется до того, как операция создает управленческое обязательство или денежный расход.

Точки проверки:

- отправка и согласование заявки на закупку;
- подтверждение заказа поставщику;
- создание или согласование платежной заявки;
- постановка платежа в график;
- регистрация фактического платежа;
- корректировка утвержденной версии бюджета;
- утверждение акта, если он превышает плановую статью БДР.

Типы лимитов:

| Тип | Что ограничивает |
| --- | --- |
| `budget_line` | конкретную строку бюджета |
| `article` | статью в рамках периода, ЦФО или проекта |
| `responsibility_center` | общий бюджет ЦФО |
| `project` | бюджет проекта |
| `contract` | сумму обязательств по договору |
| `payment_calendar` | cash limit на дату или период |

Режимы контроля:

- `inform`: показать предупреждение и сохранить аудит;
- `soft_block`: требовать комментарий и расширенное согласование;
- `hard_block`: запретить действие без корректировки бюджета;
- `override`: разрешить действие пользователю с отдельным правом и обязательным аудитом.

Расчет использования лимита должен учитывать не только fact, но и commitments: утвержденные платежные документы, заказы, договорные обязательства и forecast.

## Миграционная модель

Ниже описана целевая модель будущих миграций. В рамках PHERP-80 миграции не создавались и не запускались.

| Таблица | Назначение | Основные поля и ограничения |
| --- | --- | --- |
| `budget_periods` | периоды бюджетирования | `uuid`, `organization_id`, `code`, `name`, `period_type`, `starts_at`, `ends_at`, `status`, уникальность кода в организации |
| `budget_scenarios` | сценарии | `uuid`, `organization_id`, `code`, `name`, `scenario_type`, `is_default`, `is_active`, уникальность кода |
| `budget_versions` | версии бюджета | `uuid`, `organization_id`, `budget_period_id`, `scenario_id`, `budget_kind`, `version_number`, `status`, `activated_at`, `created_by`, частичная уникальность активной версии |
| `budget_articles` | иерархия статей | `uuid`, `organization_id`, `parent_id`, `code`, `name`, `budget_kind`, `flow_direction`, `is_leaf`, `is_active`, индексы по parent и kind |
| `budget_article_mappings` | mapping с внешними аналитиками | `budget_article_id`, `system`, `one_c_base_id`, `integration_profile_id`, `external_code`, `mapping_payload jsonb`, уникальность по базе, профилю и внешнему коду |
| `responsibility_centers` | ЦФО | `uuid`, `organization_id`, `parent_id`, `center_type`, `code`, `name`, `owner_user_id`, `approver_user_id`, `linked_entity_type`, `linked_entity_id`, `is_active` |
| `budget_lines` | строки версии | `uuid`, `budget_version_id`, `budget_article_id`, `responsibility_center_id`, `project_id`, `contract_id`, `counterparty_id`, `currency`, `description` |
| `budget_amounts` | суммы по месяцам | `budget_line_id`, `month`, `plan_amount numeric(18,2)`, `forecast_amount numeric(18,2)`, `currency`, уникальность строки и месяца |
| `budget_fact_sources` | правила извлечения факта | `budget_kind`, `source_type`, `source_statuses jsonb`, `amount_rule jsonb`, `date_rule jsonb`, `is_active` |
| `budget_facts` | нормализованный fact | `uuid`, `organization_id`, `budget_period_id`, `budget_article_id`, `responsibility_center_id`, `source_type`, `source_id`, `source_date`, `amount`, `currency`, уникальность source |
| `budget_limits` | лимиты | `budget_version_id`, `scope_type`, `scope_id`, `budget_article_id`, `threshold_amount`, `enforcement_mode`, `is_active` |
| `budget_limit_checks` | результат проверки | `operation_type`, `operation_id`, `limit_id`, `requested_amount`, `used_amount`, `available_amount`, `decision` |
| `budget_limit_decisions` | ручные решения | `limit_check_id`, `decision`, `reason`, `decided_by`, `decided_at` |
| `budget_adjustments` | корректировки | `budget_version_id`, `reason`, `status`, `requested_by`, `approved_by`, `applied_at` |
| `budget_adjustment_lines` | строки корректировок | `budget_adjustment_id`, `budget_line_id`, `month`, `delta_amount`, `new_amount` |
| `budget_period_closures` | закрытие периода | `budget_period_id`, `closure_status`, `closed_by`, `closed_at`, `reopen_reason`, `metadata jsonb` |
| `budget_import_batches` | импорт | `organization_id`, `budget_version_id`, `source_format`, `status`, `uploaded_by`, `preview_summary jsonb`, `error_summary jsonb` |
| `budget_import_rows` | строки импорта | `budget_import_batch_id`, `row_number`, `raw_payload jsonb`, `normalized_payload jsonb`, `validation_status`, `validation_errors jsonb` |

Рекомендации к типам и индексам:

- использовать UUID как публичный идентификатор новых бюджетных сущностей;
- сохранять FK на существующие таблицы в их текущем формате, чтобы не ломать существующие модели;
- денежные поля хранить как `numeric(18,2)` или более точную шкалу, если потребуется мультивалютность;
- `jsonb` использовать для импортных payload, mapping и диагностических сводок, но не для основных аналитических измерений;
- добавить индексы по `organization_id`, `budget_period_id`, `scenario_id`, `budget_article_id`, `responsibility_center_id`, `project_id`, `source_type/source_id`;
- предотвращать циклы в иерархии статей и ЦФО на уровне сервиса и проверок перед сохранением;
- не хранить бухгалтерские проводки, счета учета и налоговые регистры как часть бюджетного домена.

Что нельзя перенести автоматически без бизнес-решения:

- начальные остатки БДДС;
- исторические факты до даты надежных источников;
- модель ЦФО и владельцев ответственности;
- mapping статей с 1С по каждой базе и integration profile;
- правила признания БДР по актам, выполненным работам, складу и закупочным приемкам;
- правила распределения мультипроектных договоров и платежей;
- управленческая трактовка НДС и налоговых платежей;
- мультивалютные курсы и даты переоценки;
- правила корректировок для уже закрытых периодов.

## Риски и открытые вопросы

- Нужно утвердить, кто владеет каталогом статей: финансы холдинга, организация или проектная команда.
- Нужно выбрать первичный источник material fact: `WarehouseMovement`, `PurchaseReceipt`, `CompletedWork` или комбинация с приоритетами.
- Нужно определить, как распределять платежи и акты между проектами при мультипроектных договорах.
- Нужно решить, как ЦФО соотносится с организациями, проектами, складами и будущей оргструктурой.
- Нужно согласовать mapping с 1С отдельно по каждой базе, конфигурации и integration profile.
- Нужно определить дату начала исторической загрузки и формат начальных остатков.
- Нужно описать правила валют и НДС в управленческом бюджете, не превращая их в бухгалтерский учет.
- Нужно рассчитать производительность для больших бюджетов: тысячи строк, несколько сценариев, помесячный plan/fact/forecast, drill-down по источникам.
- Нужно не показывать в UI технические ключи статусов, permissions и ошибок. Все пользовательские тексты должны быть бизнес-понятными.
