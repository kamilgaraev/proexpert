# Каталог событий обмена ProHelper и 1С

Дата: 2026-06-07
Задача: PHERP-60
Статус документа: аналитика для проверки

## Контекст

Документ фиксирует целевой каталог событий для обмена ProHelper и 1С с учетом уже принятых границ:

- ProHelper - construction ERP и операционный источник истины по проектам, платежным заявкам, закупкам, складу, MDM, workforce и управленческой аналитике.
- 1С - бухгалтерский и налоговый источник истины по проводкам, регламентированному учету, бухгалтерским документам, юридически значимому payroll и отчетности.
- ProHelper не строит полноценный бухучет, налоговый учет, регламентированную отчетность и юридически значимый payroll без отдельного ADR.

Связанные документы:

- `docs/specs/erp-roadmap/erp-domain-inventory.md`
- `docs/specs/erp-roadmap/source-of-truth-matrix.md`
- `docs/specs/erp-roadmap/adr-prohelper-1c-accounting-boundaries.md`

## Текущий задел

В коде уже есть базовый ручной модуль `OneCBasicExchange`:

- scopes: `counterparties`, `employees`, `projects`, `materials`, `cost_categories`, `acts`, `payment_documents`, `advance_transactions`, `procurement_documents`;
- сущности: токены, маппинги, запуски обмена;
- статусы запуска: `pending`, `completed`, `failed`, `requires_mapping`;
- API: статус, токены, маппинги, ручной импорт, ручной экспорт, история запусков;
- ограничение MVP: ручной обмен, без очередей, расписаний, dead-letter, автоматического retry и полноценной двусторонней обработки конфликтов.

Целевой каталог ниже не описывает уже готовую реализацию. Он задает архитектурную рамку для следующего этапа exchange/orchestration слоя.

## Классы событий

| Класс | Значение | Пример |
| --- | --- | --- |
| Операционное событие | Событие родилось в ProHelper и отражает ход строительного процесса | утверждена платежная заявка, принят материал, закрыт payroll-source период |
| Учетное событие | Событие родилось в 1С и отражает бухгалтерскую приемку, проведение или отказ | документ принят в 1С, документ проведен, пакет расчета отклонен |
| Справочное событие | Изменение или сопоставление мастер-данных | назначен внешний код контрагента, обновлены реквизиты |
| Сверочное событие | Событие для контроля расхождений и актуальности | найдено расхождение по сумме, просрочен обмен по scope |

## Конверт события

Каждое событие обмена должно иметь единый конверт. Поля ниже целевые и могут лечь в журнал exchange attempts или в отдельную таблицу сообщений.

| Поле | Назначение |
| --- | --- |
| `event_type` | Стабильное имя события |
| `direction` | `prohelper_to_1c`, `1c_to_prohelper`, `prohelper_to_bi`, `1c_to_bi` |
| `organization_id` | Организация, в границах которой выполняется обмен |
| `exchange_scope` | Один из поддерживаемых контуров обмена |
| `local_entity_type`, `local_entity_id` | Тип и идентификатор сущности ProHelper |
| `external_id`, `mapping_id` | Идентификатор в 1С и ссылка на маппинг, если уже есть |
| `occurred_at` | Время бизнес-события |
| `created_at` | Время постановки в обмен |
| `idempotency_key` | Детерминированный ключ защиты от дублей |
| `correlation_id` | Сквозная связь запуска, документа и попыток обмена |
| `business_status` | Операционный статус в ProHelper |
| `sync_status` | Статус обмена |
| `accounting_status` | Статус 1С, если 1С уже ответила |
| `data_summary` | Безопасная сводка состава данных без секретов и полных вложений |
| `required_fields` | Минимальный набор полей для отправки |
| `expected_response` | Что ProHelper ожидает получить от 1С |
| `safe_error_code`, `safe_error_message` | Код и понятное описание ошибки без секретов |

## Целевые статусы журнала

Текущие статусы `pending`, `completed`, `failed`, `requires_mapping` подходят для MVP, но для production-sized обмена их нужно расширить:

| Статус | Значение |
| --- | --- |
| `queued` | Сообщение поставлено в очередь |
| `sent` | Сообщение отправлено в 1С |
| `accepted` | 1С приняла сообщение к обработке или зарегистрировала объект |
| `posted` | 1С провела документ, если для scope применимо проведение |
| `rejected` | 1С вернула бизнес-отказ |
| `failed` | Техническая ошибка без успешной доставки |
| `retrying` | Сообщение ожидает повторной попытки |
| `requires_mapping` | Нужен маппинг справочника или объекта |
| `manually_resolved` | Пользователь или поддержка закрыли конфликт вручную |
| `dead_letter` | Автоматические попытки исчерпаны, нужна разборка |

## ProHelper -> 1С

| Событие | Триггер | Класс | Источник | Получатель | Сводка данных | Обязательные поля | Идемпотентность | Ожидаемый ответ | Целевой SLA | Ошибки | Журнал |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `prohelper.project.approved_for_accounting_export` | Проект создан или изменен в части учетных разрезов | Справочное | Проекты | 1С | Код/название проекта, организация, заказчик, договорная привязка, статус | organization, project id, name, accounting code or request | `org:project:local_id:version` | внешний код проекта или отказ | до 15 минут | нет маппинга организации, конфликт кода | queued/sent/accepted/rejected |
| `prohelper.counterparty.mapping_requested` | Контрагент используется в договоре, платеже или закупке и требует сопоставления | Справочное | MDM/контрагенты | 1С | ИНН, КПП, название, тип, качество MDM, реквизиты | organization, contractor id, INN or normalized name | `org:counterparty:local_id:quality_version` | внешний id или список кандидатов | до 15 минут | дубли, неполные реквизиты | requires_mapping/accepted |
| `prohelper.contract.approved_for_accounting` | Договор переведен в активный учетный контур | Операционное | Договоры | 1С | Номер, дата, стороны, проект, сумма, валюта, предмет, версия | contract id, organization, counterparty mapping, number, date | `org:contract:local_id:version` | принятие карточки или отказ | до 30 минут | нет маппинга контрагента, сумма/НДС невалидны | sent/accepted/rejected |
| `prohelper.act.approved_for_accounting` | Акт утвержден в ProHelper и готов к учетной передаче | Операционное | Акты | 1С | Договор, период, номер, дата, сумма, строки работ/услуг | act id, contract mapping, amount, date, period | `org:act:local_id:status_version` | принятие или учетный отказ | до 30 минут | договор не найден в 1С, расхождение суммы | sent/accepted/rejected/posted |
| `prohelper.payment_request.approved` | Платежный документ утвержден к оплате | Операционное | Payments | 1С | направление, плательщик, получатель, сумма, назначение, срок | payment document id, amount, currency, payer/payee, bank requisites | `org:payment_document:local_id:approved_at` | принятие платежного документа или отказ | до 15 минут | реквизиты неполные, получатель не сопоставлен | sent/accepted/rejected |
| `prohelper.payment_document.exported_to_client_bank` | Сформирован файл 1C Client-Bank по платежным документам | Операционное | Payments export | 1С/банк через файл | номера платежей, суммы, реквизиты, назначение | selected payment ids, bank accounts, amount, date | `org:client_bank_export:hash(document_ids)` | подтверждение формирования файла в ProHelper | сразу для ручного сценария | документ не готов к экспорту, нет реквизитов | completed/failed |
| `prohelper.payment_transaction.imported_from_bank_for_accounting` | Банковский факт импортирован и сопоставлен с платежным документом | Сверочное | Bank statement import | 1С | банковский номер, дата, сумма, документ, контрагент | transaction id, payment document id, amount, bank reference | `org:bank_transaction:bank_reference:date:amount` | учетное отражение или подтверждение дубля | до 30 минут | дубль, не найден документ, расхождение суммы | accepted/rejected/requires_mapping |
| `prohelper.procurement_order.approved` | Заказ поставщику подтвержден и готов к учетной передаче | Операционное | Procurement | 1С | поставщик, проект, строки материалов/услуг, суммы, НДС | purchase order id, supplier mapping, lines, amount | `org:purchase_order:local_id:version` | принятие заказа/счета или отказ | до 30 минут | поставщик/номенклатура не сопоставлены | sent/accepted/rejected |
| `prohelper.purchase_receipt.posted_operationally` | Приход/приемка материалов отражена в складе ProHelper | Операционное | Warehouse/Procurement | 1С | склад, поставщик, заказ, материалы, количества, цена | receipt id, warehouse mapping, material mappings, quantities | `org:purchase_receipt:local_id:posted_at` | учетный документ прихода или отказ | до 30 минут | нет маппинга номенклатуры или склада | sent/accepted/rejected/posted |
| `prohelper.warehouse_movement.approved` | Утверждено движение склада: списание, перемещение, возврат, корректировка | Операционное | Warehouse | 1С | тип движения, склад, проект, материал, количество, основание | movement id, type, warehouse, material, quantity, date | `org:warehouse_movement:local_id:movement_date` | учетный статус документа движения | до 30 минут | отрицательный остаток в 1С, нет склада | sent/accepted/rejected/posted |
| `prohelper.inventory_act.approved` | Инвентаризационный акт утвержден | Операционное | Warehouse | 1С | склад, расхождения, корректировки, ответственные | inventory act id, warehouse, lines, approved_at | `org:inventory_act:local_id:approved_at` | принятие инвентаризации или отказ | до 1 часа | расхождение партионного учета, нет номенклатуры | sent/accepted/rejected/posted |
| `prohelper.material.updated_for_mapping` | MDM-карточка материала очищена и готова к сопоставлению | Справочное | MDM/materials | 1С | название, единица, артикул, категория, качество | material id, normalized name, unit mapping | `org:material:local_id:quality_version` | внешний код или список дублей | до 1 часа | дубль, неполная единица измерения | requires_mapping/accepted |
| `prohelper.cost_category.mapping_changed` | Изменена или утверждена статья затрат | Справочное | MDM/cost categories | 1С | категория, родитель, назначение, активность | category id, name, parent, status | `org:cost_category:local_id:version` | внешний код статьи | до 1 часа | конфликт структуры статей | accepted/rejected |
| `prohelper.employee.mapping_requested` | Сотрудник участвует в payroll-source или документе и требует внешней привязки | Справочное | Workforce | 1С/HR payroll | ФИО, табельный номер, статус занятости, подразделение | employee id, personnel number, employment status | `org:employee:local_id:personnel_number` | внешний payroll/accounting ref | до 1 часа | дубль сотрудника, нет табельного номера | requires_mapping/accepted |
| `prohelper.payroll_export_package.sent` | Закрытый payroll-source период выгружен | Операционное | Workforce | 1С/HR payroll | период, source hash, строки начислений, валидационные итоги | period id, package id, locked source hash, rows count | `org:payroll_package:package_id:source_hash` | принят, отклонен, требуется исправление | до 1 рабочего дня | source hash устарел, блокирующие ошибки | sent/accepted/rejected |

## 1С -> ProHelper

| Событие | Триггер | Класс | Источник | Получатель | Сводка данных | Обязательные поля | Идемпотентность | Ожидаемое действие ProHelper | Целевой SLA | Ошибки | Журнал |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `1c.project.accounting_code_assigned` | 1С создала или нашла учетную карточку проекта | Справочное | 1С | ProHelper | внешний код, организация, дата принятия | external id, local mapping candidate | `org:1c_project:external_id:version` | сохранить маппинг, не менять операционный проект без правила владения | до 15 минут | неоднозначный проект | accepted/requires_mapping |
| `1c.counterparty.legal_requisites_updated` | В 1С изменились юридические реквизиты контрагента | Справочное | 1С | ProHelper MDM | ИНН, КПП, название, адрес, банковские реквизиты | external id, INN, updated_at | `org:1c_counterparty:external_id:updated_at` | создать change request или обновить поля, которыми владеет 1С | до 1 часа | конфликт с локальным MDM | accepted/rejected/requires_mapping |
| `1c.contract.accounting_card_accepted` | 1С приняла карточку договора | Учетное | 1С | ProHelper | внешний id договора, учетный статус, замечания | local contract mapping, external id, status | `org:1c_contract:external_id:status_version` | обновить sync/accounting status договора | до 15 минут | локальный договор не найден | accepted/requires_mapping |
| `1c.act.accounting_posted` | Акт проведен или отклонен в 1С | Учетное | 1С | ProHelper | внешний id, статус проведения, причина отказа | external id, local act mapping, status | `org:1c_act:external_id:posted_version` | записать accounting status, не менять статус подписания без отдельного события ЭДО | до 15 минут | акт не найден, сумма не совпала | posted/rejected |
| `1c.payment_document.accepted` | Платежный документ принят или отклонен 1С | Учетное | 1С | ProHelper Payments | внешний id, статус, причина отказа | external id, payment document mapping, status | `org:1c_payment_document:external_id:status_version` | обновить accounting status и показать пользователю понятную причину | до 15 минут | документ уже изменен | accepted/rejected |
| `1c.payment_fact.accounting_posted` | 1С отразила факт платежа в учете | Учетное | 1С | ProHelper Payments/BI | внешний id проводки/документа, дата, сумма | external id, amount, date, payment mapping | `org:1c_payment_fact:external_id:posted_at` | обновить accounting posted status, не заменять банковский факт | до 30 минут | расхождение суммы или даты | posted/rejected |
| `1c.procurement_document.accounting_posted` | Документ закупки принят или проведен в 1С | Учетное | 1С | ProHelper Procurement | внешний id, закупочный документ, статус | external id, local procurement mapping | `org:1c_procurement:external_id:posted_version` | обновить учетный статус, оставить операционный статус закупки отдельно | до 30 минут | нет локального заказа | posted/rejected |
| `1c.warehouse_document.accounting_posted` | Складской документ проведен в 1С | Учетное | 1С | ProHelper Warehouse | внешний id, тип движения, статус | external id, movement mapping, status | `org:1c_warehouse_document:external_id:posted_version` | записать accounting status движения | до 30 минут | конфликт количества | posted/rejected |
| `1c.material.accounting_attributes_updated` | Изменились учетные атрибуты номенклатуры | Справочное | 1С | ProHelper MDM | внешний код, единица, учетная группа, НДС | external id, material mapping, attributes | `org:1c_material:external_id:updated_at` | обновить только 1С-owned поля или создать change request | до 1 часа | конфликт MDM качества | accepted/requires_mapping |
| `1c.cost_category.accounting_mapping_updated` | Изменена структура учетных статей | Справочное | 1С | ProHelper MDM/Reports | внешний код, родитель, активность | external id, category mapping | `org:1c_cost_category:external_id:updated_at` | обновить маппинг и пометить отчеты для пересверки | до 1 часа | конфликт иерархии | accepted/rejected |
| `1c.employee.payroll_ref_assigned` | Назначен внешний payroll/accounting ref сотрудника | Справочное | 1С/HR payroll | ProHelper Workforce | внешний id, табельный номер, дата актуальности | external id, employee mapping | `org:1c_employee:external_id:updated_at` | сохранить внешний ref, не менять кадровый статус без HR-события | до 1 часа | дубль табельного номера | accepted/requires_mapping |
| `1c.payroll_export_package.accepted` | 1С/HR payroll приняла или отклонила payroll-source пакет | Учетное | 1С/HR payroll | ProHelper Workforce | package id, source hash, статус, причина | package external id, source hash, status | `org:1c_payroll_package:external_id:status_version` | отметить package accepted/rejected, сохранить причину | до 1 рабочего дня | source hash не совпал | accepted/rejected |

## Идемпотентность и маппинги

Правила:

- внешний `external_id` не должен пересоздаваться при повторной отправке одного и того же документа;
- `idempotency_key` строится от организации, типа сущности, локального id и версии бизнес-состояния;
- для документов с номером и датой дополнительно хранится контрольный набор: номер, дата, сумма, контрагент;
- повторная отправка с тем же ключом должна возвращать уже известный результат или безопасно обновлять попытку;
- если 1С возвращает существующий объект с отличающимися критичными полями, событие уходит в `requires_mapping` или `rejected`, а не перезаписывает данные автоматически;
- маппинг `organization_id + scope + external_id` должен оставаться уникальным;
- смена маппинга после принятого учетного документа требует ручного решения и следа аудита.

## Необязательные для первого этапа события

Эти события полезны для BI и поддержки, но могут быть вынесены во второй этап после базового exchange journal:

- `prohelper.exchange_scope.freshness_warning`
- `prohelper.exchange_mapping.coverage_low`
- `prohelper.exchange_reconciliation.mismatch_detected`
- `1c.exchange_schema.version_changed`
- `1c.exchange_scope.disabled_by_credentials`

## Открытые вопросы

- Какие конфигурации 1С поддерживаются в первом production-контуре: Бухгалтерия, УНФ, ERP, ЗУП или кастомные базы.
- Нужен ли один exchange endpoint на организацию или отдельная привязка к нескольким базам 1С внутри холдинга.
- Какие поля реквизитов 1С может обновлять в MDM без approval, а какие всегда идут через change request.
- Какой формат станет основным: HTTP API, файл, внешняя обработка 1С или комбинированный режим.
- Должен ли ProHelper принимать статус `posted` напрямую от 1С или только через сверочный endpoint с подписью/контрольной суммой.
