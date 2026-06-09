# PHERP-98. Доменная модель WIP и forecast-to-complete

## 1. Назначение

Документ фиксирует управленческую модель WIP/FTC для строительной ERP ProHelper. После него PHERP-99 должен быть реализуем без дополнительных уточнений бизнес-логики: backend service, хранение forecast versions, ручные корректировки, audit trail, API, UI и контрактные тесты.

Модель относится к управленческому учету проекта. Она не заменяет 1С, банк, ЭДО, налоговый учет, бухгалтерские проводки, регламентированную отчетность, официальный складской учет и юридически значимый payroll.

## 2. Границы и решения модели

### 2.1. Что считает ProHelper

ProHelper является source of truth для:

- управленческого бюджета, сценариев и forecast versions;
- структуры проекта, этапов, графика, задач и связей со сметой;
- операционного прогресса работ;
- управленческой оценки WIP, FTC, EAC и forecast margin;
- ручных управленческих корректировок, причин, владельцев и истории согласования;
- flags, freshness, source coverage, drill-down и объяснимости расчета.

### 2.2. Что только сверяется

1С, банк и ЭДО используются как подтверждающие источники:

- 1С подтверждает отражение договоров, актов, платежных документов и складских операций в бухгалтерском/налоговом контуре;
- банк подтверждает фактическое движение денег;
- ЭДО подтверждает юридически значимый статус подписания актов и документов;
- payroll/зарплатный контур подтверждает юридически значимые начисления, но ProHelper использует только управленческую стоимость труда.

ProHelper не дублирует:

- бухгалтерские проводки и план счетов;
- НДС, налоговые регистры и регламентированную отчетность;
- бухгалтерское закрытие периода;
- юридически значимый payroll;
- официальный складской учет партий и себестоимости.

### 2.3. Единицы измерения

- Денежные значения считаются без НДС, если в строке не указано обратное.
- Основная валюта строки сохраняется в исходной валюте. Для портфельных итогов используется management currency организации с курсом на дату признания управленческого события.
- Проценты хранятся в диапазоне `0..100`, вычисления допускают внутреннюю точность до 4 знаков после запятой, UI показывает согласованное округление.
- Все показатели считаются на `as_of_date`. Для версий прогноза фиксируется `snapshot_at`, чтобы повторное открытие версии не меняло расчет задним числом.

## 3. Текущие доменные источники

| Контур | Сущности ProHelper | Роль в WIP/FTC |
| --- | --- | --- |
| Проект | `Project` | Корень расчета, организация, статус, даты, базовый бюджет, внешний код для сверки |
| Управленческий бюджет | `BudgetVersion`, `BudgetLine`, `BudgetAmount` | Плановая база BAC, forecast budget, сценарии, закрытые периоды |
| Смета | `Estimate`, `EstimateSection`, `EstimateItem`, `EstimateVersion` | Плановые объемы, стоимость работ, детализация по позициям |
| График | `ProjectSchedule`, `ScheduleTask`, `TaskDependency`, `TaskResource`, `TaskMilestone` | Плановые даты, baseline, planned value, операционный прогресс задач |
| Выполнение | `CompletedWork`, `CompletedWorkMaterial`, `ConstructionJournalEntry`, `JournalWorkVolume` | Первичный управленческий факт выполненных объемов |
| Акты | `ContractPerformanceAct`, `PerformanceActLine`, `PerformanceActCompletedWork` | Принятый заказчиком объем, закрытие WIP, revenue recognition в управленческой модели |
| Договоры | `Contract`, `ContractProjectAllocation`, `ContractCurrentState`, `ContractAllocationHistory` | Контрактная выручка, распределение по проектам, изменения суммы договора |
| Платежи | `PaymentDocument`, `PaymentTransaction`, `PaymentSchedule` | Денежное подтверждение и платежные обязательства; cash не является accrual cost |
| Склад | `WarehouseMovement`, `WarehouseProjectAllocation`, `ProjectMaterialDelivery`, `WarehouseBalance` | Управленческий расход материалов на проект и обеспеченность остатка |
| Труд | `TimeEntry`, `ProductionLaborWorkOrder`, `ProductionLaborOutputEntry`, `ProductionLaborTimesheet`, `ProductionLaborPayrollAccrual` | Управленческие трудозатраты и принятый производственный выпуск |
| Периоды | `BudgetPeriodClosureService`, настройки бюджетных периодов | Блокировки закрытых периодов и правила корректировок |

## 4. Общие правила качества данных

### 4.1. Freshness SLA

| Источник | SLA для активного проекта | Устаревание |
| --- | --- | --- |
| Бюджет, forecast version, план-факт | до 4 рабочих часов после изменения | старше 4 рабочих часов: `attention`; старше 1 рабочего дня: `stale_budget` |
| График и задачи | до 1 рабочего часа после изменения | старше 1 рабочего дня: `stale_schedule` |
| Прогресс работ | не реже одного раза в 3 рабочих дня | старше 3 рабочих дней: `stale_progress`; старше 7 рабочих дней: high severity |
| Акты ProHelper | до 1 рабочего часа после согласования | старше 1 рабочего дня: `stale_acts` |
| ЭДО/1С подтверждение актов | до 4 рабочих часов после внешнего события | старше 24 часов: critical для сверки, но не блокирует управленческий расчет |
| Платежные документы ProHelper | до 1 рабочего часа после изменения | старше 1 рабочего дня: `stale_payment_documents` |
| Банк | текущий операционный статус, сверка до 1 банковского дня | старше 1 банковского дня: `bank_match_pending` или `stale_bank_confirmation` |
| Складские движения | до 1 рабочего часа после проведения управленческого движения | старше 1 рабочего дня: `stale_warehouse_movements` |
| Трудозатраты и производственный выпуск | до 1 рабочего часа после утверждения | старше 3 рабочих дней: `stale_labor_output` |
| Итог WIP/FTC | до 1 рабочего часа после изменения любого входа | старше 1 рабочего часа: `stale_forecast_calculation` |

### 4.2. Допустимые расхождения

| Тип сверки | Допустимое расхождение | Поведение |
| --- | --- | --- |
| Денежная сумма | `max(1 RUB, 0.1% суммы строки)` или эквивалент в валюте | В пределах допуска: `reconciled_with_tolerance`; выше допуска: `reconciliation_mismatch` |
| Количество/объем | `max(0.0001 единицы, 0.1% объема строки)` | Выше допуска: `quantity_mismatch` |
| Дата банка | до 1 банковского дня между ProHelper и банковской датой | Выше допуска: `bank_date_mismatch` |
| Период закрытого управленческого учета | расхождение не допускается | Требуется новая forecast version или корректировка в открытом периоде |
| Валюта | расхождение не допускается | `currency_mismatch`, строка не попадает в достоверный итог |
| Внешний идентификатор документа | расхождение не допускается | `duplicate_external_reference` или `missing_external_reference` |

### 4.3. Пустые, частичные и устаревшие данные

- Пустой источник не трактуется как ноль, если источник должен существовать по контракту расчета. Значение получает `quality_status = unavailable` или `partial`.
- Если нет утвержденной плановой базы, WIP/FTC не показывает достоверные CTC, ETC, FTC, EAC и margin; UI обязан показать причину `missing_baseline`.
- Если есть прогресс без сметной/бюджетной базы, прогресс можно показать в натуральных единицах, но денежный EV/WIP не считается.
- Если есть платежи без accrual source, они попадают в cash/reconciliation drill-down, но не увеличивают actual cost.
- Если есть акт без связанного объема работ, акт участвует в управленческой выручке с флагом `act_without_work_volume`; для percent complete он учитывается только в act-based слое.
- Устаревший источник используется в расчете только с freshness flag и снижением quality status. Для critical freshness расчет остается видимым, но не может быть утвержден как active forecast version.

## 5. Формулы

### 5.1. Baseline at completion, BAC

**Смысл:** утвержденная управленческая стоимость планового объема проекта на дату расчета.

**Формула:**

```text
BAC = sum(approved_baseline_cost_lines.amount_without_vat)
```

Если утвержденный budget baseline отсутствует:

```text
BAC = sum(approved_estimate_items.current_total_amount)
```

Если смета не утверждена, допускается временный расчет от активного графика:

```text
BAC = sum(active_schedule_tasks.estimated_cost)
```

Такой расчет получает `risk_flags = ["baseline_from_schedule_only"]` и не может быть утвержден без управленческой плановой базы.

**Входные данные:** active/approved `BudgetVersion`, `BudgetLine`, `BudgetAmount`, approved `Estimate`, `EstimateItem`, active `ProjectSchedule`, `ScheduleTask`.

**Source of truth:** ProHelper budget version. Смета и график являются fallback-источниками для предварительной оценки.

**Сверка:** 1С может подтверждать отражение договоров и документов, но BAC остается управленческим бюджетом ProHelper.

**Freshness SLA:** до 4 рабочих часов после изменения бюджета или сметы.

**Допустимые расхождения:** для сверки с внешними документами сумма в пределах `max(1 RUB, 0.1%)`; между бюджетом и сметой расхождение допускается только как управленческий variance, не как ошибка данных.

**Поведение:** без BAC денежные WIP/EV/CTC/ETC/FTC/EAC недостоверны; UI показывает progress coverage и блокирует approve forecast.

### 5.2. Percent complete

**Смысл:** доля фактически выполненного и подтвержденного управленческими источниками объема проекта.

**Базовая формула:**

```text
percent_complete = sum(scope_weight_i * percent_complete_i) / sum(scope_weight_i)
```

где `scope_weight_i` выбирается по приоритету:

1. утвержденная управленческая стоимость строки бюджета или сметы;
2. `ScheduleTask.estimated_cost`;
3. плановый объем `EstimateItem.quantity_total` или `ScheduleTask.quantity`;
4. равный вес только для низкодостоверного operational view.

Для позиции:

```text
quantity_percent_i = completed_quantity_i / planned_quantity_i * 100
```

Значение ограничивается диапазоном `0..100`, кроме отдельного флага `completed_volume_exceeds_baseline`, когда факт выше плана.

**Приоритет источников percent complete:**

1. Актовый прогресс: approved/signed `ContractPerformanceAct` и `PerformanceActLine` по связанным `CompletedWork`.
2. Объемный прогресс: confirmed/approved `CompletedWork`, approved `ConstructionJournalEntry` и `JournalWorkVolume`.
3. Производственный выпуск: accepted `ProductionLaborOutputEntry` и accepted lines производственных нарядов.
4. График: `ScheduleTask.completed_quantity`, затем `ScheduleTask.progress_percent` активного графика.
5. Ручная оценка: approved manual progress adjustment с причиной, владельцем и сроком действия.
6. Time-phased planned progress: только как fallback для отсутствующего факта, с `risk_flags = ["planned_progress_used_as_fallback"]`.

**Входные данные:** `ContractPerformanceAct`, `PerformanceActLine`, `CompletedWork`, `JournalWorkVolume`, `ProductionLaborOutputEntry`, `ScheduleTask`, `EstimateItem`, manual progress adjustments.

**Source of truth:** ProHelper operational progress. ЭДО подтверждает юридический статус актов, но не является источником процента выполнения до загрузки в ProHelper.

**Сверка:** 1С/ЭДО сверяются по актам и статусам подписания; банк не участвует в percent complete.

**Freshness SLA:** обновление прогресса не реже одного раза в 3 рабочих дня для активного проекта.

**Допустимые расхождения:** объемы в пределах `max(0.0001 единицы, 0.1%)`; актовый объем не должен превышать confirmed completed volume без флага и причины.

**Поведение:** если источники конфликтуют, выбирается источник с более высоким приоритетом, а расхождение отражается в `problem_flags`. Если нет ни одного фактического источника, процент недоступен; time-phased fallback не может быть использован для утвержденного прогноза.

### 5.3. Earned value, EV

**Смысл:** управленческая стоимость выполненного объема в ценах утвержденного baseline.

**Формула по детализации:**

```text
EV = sum(baseline_cost_i * percent_complete_i / 100)
```

**Агрегированная fallback-формула:**

```text
EV = BAC * percent_complete / 100
```

**Входные данные:** BAC, percent complete по строкам, budget/estimate/schedule weights.

**Source of truth:** ProHelper budget baseline плюс ProHelper progress sources.

**Сверка:** 1С не является источником EV; акты из ЭДО/1С только подтверждают актовый progress layer.

**Freshness SLA:** до 1 рабочего часа после изменения прогресса или baseline.

**Допустимые расхождения:** EV должен объясняться суммой строк. Отклонение агрегата от детализации выше `max(1 RUB, 0.1%)` запрещает утверждение версии.

**Поведение:** без BAC или percent complete EV недоступен. При partial coverage EV считается только по покрытой части и получает `source_coverage.progress < 100`.

### 5.4. Planned value, PV

**Смысл:** управленческая стоимость объема, который должен быть выполнен по baseline-графику на `as_of_date`.

**Формула:**

```text
PV = sum(baseline_cost_i * planned_fraction_i(as_of_date))
```

`planned_fraction_i`:

- `0`, если `as_of_date` раньше baseline/planned start;
- `1`, если `as_of_date` позже baseline/planned finish;
- линейная доля между датами, если нет более детальной кривой распределения;
- по milestone/payment-weighted curve, если для этапа утверждена такая кривая.

**Входные данные:** `ProjectSchedule`, `ScheduleTask`, baseline dates, planned dates, task weights, milestones.

**Source of truth:** ProHelper active baseline schedule.

**Сверка:** внешняя сверка не требуется; 1С/банк/ЭДО не определяют плановую кривую выполнения.

**Freshness SLA:** до 1 рабочего часа после изменения графика; baseline должен быть синхронизирован со сметой.

**Допустимые расхождения:** `sum(PV_i)` должен совпадать с агрегатом в пределах `max(1 RUB, 0.1%)`.

**Поведение:** если нет baseline-графика, PV недоступен. Если есть только даты проекта, можно показать low-confidence PV с `risk_flags = ["project_dates_used_for_pv"]`, но нельзя использовать его для critical KPI без подтверждения.

### 5.5. Actual cost, AC

**Смысл:** управленческая фактическая стоимость уже потребленных ресурсов и принятых затрат проекта на `as_of_date`.

**Формула:**

```text
AC = supplier_accrual_cost
   + subcontractor_accepted_cost
   + warehouse_consumption_cost
   + labor_management_cost
   + approved_expense_cost
   + approved_manual_cost_adjustments
```

Не включается:

- входящий платеж от заказчика;
- исходящий банковский платеж без первичного accrual source;
- аванс без принятого расхода;
- НДС как налоговый компонент;
- юридически значимый payroll из 1С/ЗУП как бухгалтерская зарплата.

**Входные данные:** approved outgoing `PaymentDocument` с затратной природой, contractor/supplier acts, `WarehouseMovement` типов списания/производственного использования, approved `TimeEntry`, accepted labor output, approved management payroll accrual, approved expense reports, manual adjustments.

**Source of truth:** ProHelper management accrual and operational consumption records.

**Сверка:** банк подтверждает оплату, 1С подтверждает бухгалтерское отражение, складской внешний контур подтверждает официальный учет остатков; они не заменяют управленческий AC.

**Freshness SLA:** до 1 рабочего часа после изменения управленческого документа; банк сверяется до 1 банковского дня.

**Допустимые расхождения:** сумма управленческого документа против внешнего подтверждения в пределах `max(1 RUB, 0.1%)`; платеж без accrual source не закрывает cost mismatch.

**Поведение:** cash-only строки выводятся в drill-down с `risk_flags = ["cash_only_source"]` и не попадают в AC. Устаревшие или неподтвержденные расходы могут входить в preliminary AC, но блокируют approve forecast при high/critical severity.

### 5.6. Work in progress, WIP

**Смысл:** управленческая стоимость выполненного объема, который еще не закрыт актами или не подтвержден внешним статусом.

WIP разделяется на два слоя, чтобы не смешивать операционный факт и юридическое подтверждение:

```text
performed_not_acted_wip = max(EV - approved_customer_act_value_for_same_scope, 0)
```

```text
approved_not_externally_confirmed_wip = approved_act_value
                                      - externally_confirmed_act_value
```

```text
total_management_wip = performed_not_acted_wip
                     + approved_not_externally_confirmed_wip
```

`approved_customer_act_value_for_same_scope` берется только по работам, связанным с EV scope. Нельзя вычитать акт другого договора, этапа или проекта.

**Входные данные:** EV by scope, approved/signed acts, act lines, act-completed-work pivot, ЭДО/1С confirmation status.

**Source of truth:** ProHelper for management WIP; ЭДО и 1С только подтверждают внешний статус акта.

**Сверка:** ЭДО сверяет подписание; 1С сверяет бухгалтерское отражение акта; банк не закрывает WIP.

**Freshness SLA:** до 1 рабочего часа после изменения прогресса или акта; внешнее подтверждение до 4 рабочих часов после события.

**Допустимые расхождения:** сумма акта против связанных строк в пределах `max(1 RUB, 0.1%)`; актовый объем против completed work в пределах quantity tolerance.

**Поведение:** если акт не связан с объемом, WIP считается по агрегированной сумме с `problem_flags = ["act_without_work_volume"]`. Если EV меньше актов по scope, `performed_not_acted_wip = 0`, а строка получает `risk_flags = ["act_value_exceeds_earned_value"]`.

### 5.7. Cost-to-complete, CTC

**Смысл:** baseline-стоимость оставшегося объема без учета текущей эффективности и ручного прогноза.

**Формула:**

```text
CTC = max(BAC - EV, 0)
```

По детализации:

```text
CTC = sum(max(baseline_cost_i - earned_value_i, 0))
```

**Входные данные:** BAC, EV.

**Source of truth:** ProHelper baseline и progress.

**Сверка:** внешние системы не являются источником CTC.

**Freshness SLA:** до 1 рабочего часа после изменения BAC или EV.

**Допустимые расхождения:** агрегат против детализации в пределах `max(1 RUB, 0.1%)`.

**Поведение:** если EV выше BAC, CTC равен 0 и выставляется `risk_flags = ["earned_value_exceeds_baseline"]`. CTC не заменяет forecast; это механический остаток baseline.

### 5.8. Estimate-to-complete, ETC

**Смысл:** расчетная ожидаемая стоимость оставшейся части проекта до ручных управленческих корректировок forecast version.

**Приоритет формулы:**

1. Bottom-up ETC по оставшимся строкам:

```text
ETC = sum(remaining_quantity_i * current_expected_unit_cost_i)
```

2. Performance-based ETC, если CPI статистически применим:

```text
CPI = EV / AC
ETC = max(BAC - EV, 0) / CPI
```

3. Baseline fallback:

```text
ETC = CTC
```

CPI применим только если `AC > 0`, `EV > 0`, progress coverage достаточен, а percent complete не ниже 10%. Иначе CPI не должен ухудшать расчет.

**Входные данные:** remaining quantities, текущие expected unit costs, procurement quotes, approved supplier rates, labor rates, BAC, EV, AC, CTC.

**Source of truth:** ProHelper operational cost model и budget/estimate lines.

**Сверка:** внешние счета, банк, 1С и ЭДО подтверждают факты и цены, но не определяют управленческий ETC.

**Freshness SLA:** до 1 рабочего часа после изменения стоимости ресурсов, прогресса, бюджета или факта.

**Допустимые расхождения:** расчет по строкам против агрегата в пределах `max(1 RUB, 0.1%)`; CPI-based и bottom-up различие выше 5% получает `risk_flags = ["etc_method_variance"]`.

**Поведение:** если нет current unit costs, используется baseline unit cost с `risk_flags = ["baseline_cost_used_for_etc"]`. Если нет прогресса, ETC не считается достоверным и показывается как preliminary.

### 5.9. Forecast-to-complete, FTC

**Смысл:** управляемый версионный прогноз стоимости завершения проекта, который может включать bottom-up ETC, рисковые резервы, утвержденные изменения и ручные управленческие корректировки.

**Формула версии:**

```text
FTC = system_etc
    + approved_cost_adjustments
    + approved_risk_reserve
    + approved_scope_change_cost
    - excluded_or_cancelled_remaining_scope
```

Для активной версии:

```text
active_FTC = sum(active_forecast_version.lines.remaining_cost_forecast)
           + sum(active_forecast_version.adjustments)
```

Если активной forecast version нет:

```text
preliminary_FTC = ETC
```

с `problem_flags = ["forecast_version_missing"]`.

**Входные данные:** ETC, forecast version lines, manual adjustments, risk reserve, change orders, excluded scope, assumptions.

**Source of truth:** ProHelper forecast version.

**Сверка:** внешние источники подтверждают отдельные факты и документы; FTC является управленческим прогнозом ProHelper.

**Freshness SLA:** active forecast должен пересчитываться до 1 рабочего часа после изменения входных данных; регулярный reforecast не реже cadence из раздела 7.4.

**Допустимые расхождения:** версия хранит snapshot входов, поэтому пересчет должен воспроизводить значение в пределах `max(1 RUB, 0.1%)`. Отклонение текущих источников от snapshot отражается как variance к версии, а не переписывает утвержденный forecast.

**Поведение:** ручные корректировки без причины, владельца, периода действия и approval не включаются в active FTC. Устаревшая версия остается доступной, но получает `risk_flags = ["forecast_version_stale"]`.

### 5.10. Estimate at completion, EAC

**Смысл:** ожидаемая итоговая стоимость проекта к завершению.

**Основная формула:**

```text
EAC = AC + FTC
```

Если active forecast version отсутствует:

```text
preliminary_EAC = AC + ETC
```

Legacy EVM fallback допустим только для сравнения:

```text
EAC_evm = BAC / CPI
```

`EAC_evm` не должен заменять versioned EAC, если есть active forecast version.

**Входные данные:** AC, FTC, ETC, CPI для сравнения.

**Source of truth:** ProHelper actual cost и ProHelper active forecast version.

**Сверка:** 1С и банк подтверждают факты AC; EAC как прогноз остается управленческим.

**Freshness SLA:** до 1 рабочего часа после изменения AC или FTC.

**Допустимые расхождения:** `EAC - (AC + FTC)` не допускается выше округления `max(1 RUB, 0.1%)`.

**Поведение:** если AC preliminary или FTC preliminary, EAC получает такой же quality status и не может быть утвержден как окончательный forecast.

### 5.11. Forecast revenue at completion

**Смысл:** ожидаемая управленческая выручка проекта к завершению.

**Формула:**

```text
forecast_revenue_at_completion = approved_contract_allocated_revenue
                                + approved_revenue_change_orders
                                + approved_revenue_forecast_adjustments
                                + scenario_allowed_pending_claims
                                - cancelled_or_excluded_revenue_scope
```

Pending claims включаются только если scenario явно разрешает их учитывать и у строки есть вероятность, сумма, владелец и срок решения.

**Входные данные:** `Contract.total_amount`, `ContractProjectAllocation`, active specifications/additional agreements, approved acts for actual revenue, change orders, claims, manual revenue adjustments.

**Source of truth:** ProHelper contracts and forecast version.

**Сверка:** ЭДО и 1С подтверждают договоры/акты; банк подтверждает cash collection, но не forecast revenue.

**Freshness SLA:** до 4 рабочих часов после изменения договора или аллокации; до 1 рабочего часа после изменения forecast version.

**Допустимые расхождения:** сумма договора/допсоглашения против внешнего подтверждения в пределах `max(1 RUB, 0.1%)`.

**Поведение:** если договор распределен на несколько проектов и нет active allocation, revenue forecast получает `problem_flags = ["missing_contract_allocation"]`.

### 5.12. Forecast gross margin и margin percent

**Смысл:** ожидаемая управленческая валовая маржа проекта при завершении.

**Формулы:**

```text
forecast_gross_margin = forecast_revenue_at_completion - EAC
```

```text
forecast_margin_percent = forecast_gross_margin / forecast_revenue_at_completion * 100
```

Если forecast revenue равен 0, margin percent не считается.

**Входные данные:** forecast revenue at completion, EAC.

**Source of truth:** ProHelper contracts, forecast version, actual cost.

**Сверка:** внешние системы подтверждают договоры, акты и платежи; margin остается управленческим KPI.

**Freshness SLA:** до 1 рабочего часа после изменения revenue forecast, AC или FTC.

**Допустимые расхождения:** агрегат против строк в пределах `max(1 RUB, 0.1%)`.

**Поведение:** отрицательная маржа допустима и не скрывается. UI показывает severity по порогам проекта/портфеля: warning при снижении ниже target margin, critical при отрицательной forecast margin или нарушении covenant/лимита.

## 6. Модель прогресса проекта

### 6.1. Уровни расчета

Расчет должен поддерживать уровни:

- project;
- contract allocation;
- stage или schedule task group;
- estimate section;
- estimate item;
- schedule task;
- period/month;
- source document line.

Минимальный уровень для PHERP-99: project, stage/task, estimate item при наличии связи, contract, period, source document.

### 6.2. Связь этапов, графика и объемов

`ProjectSchedule` задает baseline и planned curve. `ScheduleTask` связывает дату, объем, стоимость и progress. Если задача связана с `EstimateItem`, вес и плановый объем берутся из сметы. Если связи нет, строка считается operational-only и получает `risk_flags = ["schedule_task_without_estimate_item"]`.

`CompletedWork` является основным фактом выполненной работы. Если запись создана из утвержденного журнала, drill-down должен показывать `ConstructionJournalEntry` и `JournalWorkVolume`. Если выполненная работа привязана к `ScheduleTask`, она обновляет progress task layer, но не переписывает baseline без отдельной операции.

`ContractPerformanceAct` закрывает часть WIP только в той части, где акт связан с `CompletedWork` или `PerformanceActLine` имеет `estimate_item_id`/scope. Manual line акта без причины получает `problem_flags = ["manual_act_line_without_reason"]`.

### 6.3. Ручная оценка прогресса

Manual progress adjustment допускается только если:

- указан project/stage/task/scope;
- указано старое и новое значение;
- указана бизнес-причина;
- указан владелец;
- указан срок действия или событие пересмотра;
- указаны supporting documents при наличии;
- корректировка согласована пользователем с правом approve forecast/progress adjustment.

Manual progress не удаляет расхождение с фактическими источниками. Он хранится отдельной строкой и в расчете имеет source type `manual_progress_adjustment`.

### 6.4. Конфликты источников

| Конфликт | Flag | Severity | Поведение |
| --- | --- | --- | --- |
| Актовый объем больше confirmed completed volume | `act_exceeds_completed_volume` | high | Акт учитывается в revenue, progress получает предупреждение; approve forecast требует причины |
| CompletedWork больше baseline quantity | `completed_volume_exceeds_baseline` | high | Progress capped at 100 для baseline EV, перерасход показывается отдельной строкой |
| График не синхронизирован со сметой | `schedule_out_of_sync_with_estimate` | medium/high | PV и task weights снижают quality status |
| Ручной percent отличается от факта выше 5 п.п. | `manual_progress_conflicts_with_actuals` | medium/high | Ручное значение требует approval и срока действия |
| Нет активного baseline | `missing_baseline` | critical | Денежные формулы недоступны для approve |
| Прогресс устарел | `stale_progress` | medium/high | Расчет видим, но версия не утверждается при high severity |
| Акт без строки объема | `act_without_work_volume` | medium | Revenue считается, WIP closure требует drill-down |
| Cash есть, accrual source отсутствует | `cash_only_source` | medium | Cash не входит в AC |
| Внешнее подтверждение старше SLA | `stale_external_confirmation` | medium/critical | Управленческий расчет видим, сверка требует внимания |

## 7. Forecast workflow

### 7.1. Сущность forecast version

Forecast version должна хранить:

- `id`, `uuid`, `organization_id`, `project_id`;
- `scenario`: base, optimistic, conservative, claim_included или другой утвержденный сценарий;
- `status`: editing, submitted, approved, active, superseded, archived;
- `as_of_date`, `snapshot_at`, `period_from`, `period_to`;
- `baseline_version_id`, `budget_version_id`, `schedule_id`, `estimate_id`;
- `previous_forecast_version_id`;
- `created_by`, `submitted_by`, `approved_by`, `activated_by`;
- `summary`: AC, ETC, FTC, EAC, forecast revenue, forecast margin;
- `quality_status`, `problem_flags`, `risk_flags`;
- `source_coverage`, `freshness`, `assumptions`;
- immutable source snapshot hash.

Approved/active версия не редактируется. Любое изменение после approval создает новую version или отдельную approved adjustment в открытом периоде.

### 7.2. Создание версии

Создание версии:

1. Пользователь выбирает project, as_of_date, scenario.
2. Backend собирает source snapshot.
3. Backend рассчитывает system ETC, preliminary FTC, EAC, margin.
4. Backend создает version в status `editing`.
5. UI показывает source coverage, freshness, flags, расхождение к предыдущей версии и активному бюджету.

Создание запрещено, если пользователь не имеет права view project finances. Создание с critical `missing_baseline` допускается только для диагностического просмотра, но не для submit/approve.

### 7.3. Обновление версии

Версия в status `editing` может обновляться:

- автоматическим пересбором source snapshot;
- ручным обновлением forecast lines;
- добавлением assumptions;
- добавлением manual adjustments;
- исключением отмененного объема;
- привязкой change orders и risk reserves.

При каждом update пишется audit event с diff до/после.

### 7.4. Reforecast cadence

| Условие проекта | Cadence |
| --- | --- |
| Активный проект без high risk | не реже 1 раза в месяц |
| Проект в активной производственной фазе | не реже 1 раза в 2 недели |
| Critical path delay, CPI < 0.95, SPI < 0.95, margin ниже target | еженедельно |
| Изменение договора, крупная закупка, акт, перерасход > 5%, ручная корректировка | внеочередной reforecast в течение 1 рабочего дня |
| Закрытие управленческого периода | forecast snapshot до закрытия периода |

### 7.5. Ручные корректировки

Manual adjustment хранится отдельной строкой:

- scope: project, contract, stage, estimate item, period;
- type: cost, revenue, progress, risk_reserve, scope_change, exclusion;
- amount или percent/quantity;
- currency;
- reason;
- owner;
- valid_from, valid_until или review_event;
- supporting document links;
- approval status;
- created_by, approved_by;
- superseded_by при замене.

Корректировка без причины не участвует в active FTC/EAC. Истекшая корректировка исключается из active расчета и отображается в assumptions history.

### 7.6. Audit trail

Audit trail должен фиксировать:

- создание версии;
- пересбор snapshot;
- изменение forecast line;
- добавление, изменение, approval, rejection и expiry manual adjustment;
- submit, approve, activate, supersede, archive;
- изменение статуса периода, влияющее на блокировки;
- попытку операции, заблокированную правами или closed period.

Audit event хранит actor, timestamp, action, entity type/id, old value, new value, reason, source request id, IP/user agent при наличии, affected formulas.

### 7.7. Права доступа

Для PHERP-99 нужны permissions в JSON ролей и русские подписи в `lang/ru/permissions.php`:

- `budgeting.wip_forecast.view` — просмотр summary и строк без запрещенного drill-down;
- `budgeting.wip_forecast.view_sensitive_costs` — просмотр AC, labor cost, supplier cost;
- `budgeting.wip_forecast.create_version` — создание версии;
- `budgeting.wip_forecast.update_version` — редактирование версии в status `editing`;
- `budgeting.wip_forecast.submit_version` — отправка на согласование;
- `budgeting.wip_forecast.approve_version` — утверждение;
- `budgeting.wip_forecast.activate_version` — назначение active version;
- `budgeting.wip_forecast.manage_adjustments` — ручные корректировки;
- `budgeting.wip_forecast.export` — экспорт;
- `budgeting.wip_forecast.view_audit` — просмотр audit trail.

Если пользователь не имеет права на источник drill-down, API возвращает агрегат и `problem_flags = ["hidden_by_permissions"]` на строке детализации.

### 7.8. Закрытые бюджетные периоды

С учетом PHERP-90/91 и `BudgetPeriodClosureService`:

- закрытый или archived период запрещает изменение budget baseline, approved forecast lines и source attribution в этом периоде;
- soft closed период допускает только операции, явно разрешенные настройками reopen/adjustment workflow;
- корректировка закрытого периода не переписывает source document, а создается как отдельная management adjustment в открытом периоде или в reopened window;
- active forecast version, утвержденная до закрытия периода, сохраняет snapshot;
- новая forecast version может учитывать новые факты будущих или открытых периодов, но не меняет исторический approved snapshot;
- попытка изменить закрытый период возвращает business error с человекочитаемым сообщением и кодом `closed_period_locked`.

## 8. API/UI контракт для PHERP-99

### 8.1. Endpoints

Все ответы идут через `AdminResponse`.

```text
GET  /api/v1/admin/budgeting/wip-forecast
GET  /api/v1/admin/budgeting/wip-forecast/drill-down
GET  /api/v1/admin/budgeting/wip-forecast/versions
POST /api/v1/admin/budgeting/wip-forecast/versions
GET  /api/v1/admin/budgeting/wip-forecast/versions/{version}
PATCH /api/v1/admin/budgeting/wip-forecast/versions/{version}
POST /api/v1/admin/budgeting/wip-forecast/versions/{version}/submit
POST /api/v1/admin/budgeting/wip-forecast/versions/{version}/approve
POST /api/v1/admin/budgeting/wip-forecast/versions/{version}/activate
POST /api/v1/admin/budgeting/wip-forecast/versions/{version}/adjustments
GET  /api/v1/admin/budgeting/wip-forecast/versions/{version}/audit
```

### 8.2. Query filters

```json
{
  "organization_id": 10,
  "project_ids": [101, 102],
  "contract_ids": [501],
  "stage_ids": [701],
  "period_from": "2026-01",
  "period_to": "2026-06",
  "as_of_date": "2026-06-09",
  "scenario": "base",
  "forecast_version_id": "f6f64b6c-7b0f-47f3-b9df-59ad87f6c750",
  "budget_version_id": "0e5a9c8b-6e8c-4c49-829e-9f06d2f22c37",
  "group_by": ["project", "stage", "contract"],
  "currency": "RUB",
  "include_drill_down": false,
  "include_hidden_sources": false
}
```

### 8.3. Response shape

```json
{
  "summary": {
    "currency": "RUB",
    "bac": 120000000.0,
    "pv": 54000000.0,
    "ev": 50000000.0,
    "ac": 47000000.0,
    "wip": {
      "performed_not_acted": 8000000.0,
      "approved_not_externally_confirmed": 3000000.0,
      "total": 11000000.0
    },
    "ctc": 70000000.0,
    "etc": 73000000.0,
    "ftc": 76000000.0,
    "eac": 123000000.0,
    "forecast_revenue_at_completion": 145000000.0,
    "forecast_gross_margin": 22000000.0,
    "forecast_margin_percent": 15.17,
    "cpi": 1.0638,
    "spi": 0.9259,
    "quality_status": "attention"
  },
  "rows": [
    {
      "row_id": "project:101:stage:701",
      "project_id": 101,
      "project_name": "Строительство объекта",
      "contract_id": 501,
      "stage_id": 701,
      "estimate_item_id": null,
      "period": "2026-06",
      "currency": "RUB",
      "bac": 30000000.0,
      "pv": 15000000.0,
      "ev": 14000000.0,
      "ac": 15500000.0,
      "wip_total": 2500000.0,
      "ctc": 16000000.0,
      "etc": 17500000.0,
      "ftc": 18200000.0,
      "eac": 33700000.0,
      "forecast_revenue_at_completion": 39000000.0,
      "forecast_gross_margin": 5300000.0,
      "forecast_margin_percent": 13.59,
      "percent_complete": 46.67,
      "progress_source": "completed_work",
      "quality_status": "attention",
      "problem_flags": ["schedule_out_of_sync_with_estimate"],
      "risk_flags": ["etc_method_variance"],
      "drill_down": {
        "available": true,
        "key": {
          "project_id": 101,
          "stage_id": 701,
          "contract_id": 501,
          "period": "2026-06"
        }
      },
      "actions": {
        "can_open_sources": true,
        "can_add_adjustment": true,
        "can_recalculate": false,
        "disabled_reasons": []
      }
    }
  ],
  "formulas": {
    "percent_complete": "sum(scope_weight_i * percent_complete_i) / sum(scope_weight_i)",
    "ev": "sum(baseline_cost_i * percent_complete_i / 100)",
    "pv": "sum(baseline_cost_i * planned_fraction_i(as_of_date))",
    "ac": "supplier_accrual_cost + subcontractor_accepted_cost + warehouse_consumption_cost + labor_management_cost + approved_expense_cost + approved_manual_cost_adjustments",
    "wip_total": "max(EV - approved_customer_act_value_for_same_scope, 0) + approved_not_externally_confirmed_wip",
    "ctc": "max(BAC - EV, 0)",
    "etc": "bottom_up_ETC or max(BAC - EV, 0) / CPI or CTC",
    "ftc": "system_etc + approved_cost_adjustments + approved_risk_reserve + approved_scope_change_cost - excluded_or_cancelled_remaining_scope",
    "eac": "AC + FTC",
    "forecast_gross_margin": "forecast_revenue_at_completion - EAC",
    "forecast_margin_percent": "forecast_gross_margin / forecast_revenue_at_completion * 100"
  },
  "assumptions": [
    {
      "assumption_id": "a-1001",
      "scope": "stage",
      "scope_id": 701,
      "type": "risk_reserve",
      "amount": 700000.0,
      "currency": "RUB",
      "reason": "Ожидаемое удорожание материалов по этапу",
      "owner_user_id": 25,
      "valid_until": "2026-07-31",
      "status": "approved"
    }
  ],
  "source_coverage": {
    "baseline": {
      "status": "actual",
      "covered_amount": 120000000.0,
      "coverage_percent": 100.0,
      "sources": ["budget_version", "estimate"]
    },
    "progress": {
      "status": "attention",
      "coverage_percent": 82.4,
      "sources": ["completed_work", "contract_performance_act", "schedule_task"],
      "missing_sources": ["labor_output"]
    },
    "actual_cost": {
      "status": "partial",
      "coverage_percent": 76.0,
      "sources": ["payment_document", "warehouse_movement", "time_entry"]
    },
    "external_confirmation": {
      "status": "attention",
      "edo_coverage_percent": 91.0,
      "one_c_coverage_percent": 87.0,
      "bank_coverage_percent": 96.0
    }
  },
  "freshness": {
    "calculated_at": "2026-06-09T12:15:00+03:00",
    "baseline_updated_at": "2026-06-09T10:30:00+03:00",
    "progress_updated_at": "2026-06-07T18:00:00+03:00",
    "acts_updated_at": "2026-06-09T09:40:00+03:00",
    "actual_cost_updated_at": "2026-06-09T11:55:00+03:00",
    "external_confirmation_updated_at": "2026-06-08T16:00:00+03:00",
    "stale_sources": ["progress"],
    "sla": {
      "progress_max_age_working_days": 3,
      "calculation_max_age_minutes": 60
    }
  },
  "problem_flags": [
    {
      "code": "schedule_out_of_sync_with_estimate",
      "severity": "medium",
      "message": "График отличается от утвержденной сметы",
      "affected_rows": ["project:101:stage:701"]
    }
  ],
  "risk_flags": [
    {
      "code": "etc_method_variance",
      "severity": "medium",
      "message": "Bottom-up расчет остатка отличается от CPI-расчета больше чем на 5%",
      "affected_rows": ["project:101:stage:701"]
    }
  ],
  "drill_down": {
    "available": true,
    "levels": ["project", "contract", "stage", "estimate_item", "source_document"],
    "endpoint": "/api/v1/admin/budgeting/wip-forecast/drill-down"
  },
  "actions": {
    "can_create_version": true,
    "can_edit_version": true,
    "can_submit_version": true,
    "can_approve_version": false,
    "can_activate_version": false,
    "can_add_adjustment": true,
    "can_export": true,
    "disabled_reasons": {
      "can_approve_version": "Недостаточно прав для утверждения прогноза",
      "can_activate_version": "Версия еще не утверждена"
    }
  },
  "meta": {
    "source_of_truth": {
      "management": "ProHelper",
      "accounting": "1C external confirmation only",
      "bank": "bank confirmation only",
      "edo": "legal signing confirmation only"
    },
    "version": {
      "id": "f6f64b6c-7b0f-47f3-b9df-59ad87f6c750",
      "status": "editing",
      "scenario": "base",
      "as_of_date": "2026-06-09",
      "snapshot_at": "2026-06-09T12:15:00+03:00",
      "previous_version_id": "aab042f5-33db-42dc-8bd2-7fbcb84f4c10"
    },
    "period_lock": {
      "status": "open",
      "locked_periods": ["2026-04"],
      "blocked_operations": []
    },
    "permissions": {
      "hidden_sources_count": 0
    }
  }
}
```

### 8.4. Drill-down contract

Drill-down строка должна объяснять каждый показатель до source document line:

```json
{
  "source_type": "completed_work",
  "source_id": 3450,
  "source_line_id": null,
  "recognition_event": "confirmed_completed_work",
  "scope": {
    "project_id": 101,
    "contract_id": 501,
    "stage_id": 701,
    "estimate_item_id": 909
  },
  "period": "2026-06",
  "quantity": 125.5,
  "unit": "м3",
  "amount_without_vat": 1200000.0,
  "formula_components": {
    "baseline_cost": 2500000.0,
    "percent_complete": 48.0,
    "earned_value": 1200000.0
  },
  "confirmation": {
    "prohelper_status": "confirmed",
    "edo_status": "pending",
    "one_c_status": "not_confirmed",
    "bank_status": "not_applicable"
  },
  "freshness_status": "actual",
  "reconciliation_status": "pending_external_confirmation",
  "problem_flags": [],
  "risk_flags": ["edo_pending"],
  "permissions": {
    "can_open_source": true
  }
}
```

### 8.5. UI требования

Экран PHERP-99 должен показывать:

- summary cards: WIP, percent complete, EV, PV, AC, CTC, ETC, FTC, EAC, forecast margin;
- таблицу rows с группировкой project/stage/contract/estimate item/period;
- переключатель версии и сценария;
- сравнение с предыдущей forecast version и approved budget;
- панель formulas с раскрытием компонентов;
- панель assumptions и manual adjustments;
- source coverage/freshness panel;
- problem/risk flags с severity и переходом в drill-down;
- audit trail версии;
- actions по правам пользователя;
- состояния Loading, Error, Empty, Partial, Stale и Locked period.

UI не должен показывать пользователю технические термины внутренних ошибок. Тексты flags и disabled reasons должны переводиться через `trans_message(...)` на backend и приходить в UI человекочитаемыми.

## 9. Problem flags и risk flags для PHERP-99

### 9.1. Problem flags

| Code | Severity по умолчанию | Когда ставится |
| --- | --- | --- |
| `missing_baseline` | critical | Нет утвержденного baseline для денежного расчета |
| `missing_project` | critical | Источник не привязан к проекту |
| `missing_contract` | medium | Источник должен быть договорным, но договор отсутствует |
| `missing_contract_allocation` | high | Договор мультипроектный, но нет active allocation |
| `missing_stage` | medium | Нельзя разнести строку по этапу |
| `missing_estimate_item` | medium | Нет связи с позицией сметы при требуемой детализации |
| `schedule_out_of_sync_with_estimate` | medium | Активный график не соответствует смете |
| `progress_source_conflict` | medium | Источники progress расходятся выше допуска |
| `act_exceeds_completed_volume` | high | Акт закрывает больше, чем confirmed объем |
| `act_without_work_volume` | medium | Акт не связан с выполненными работами |
| `completed_volume_exceeds_baseline` | high | Факт выше baseline объема |
| `quantity_mismatch` | medium | Объемы расходятся выше допуска |
| `reconciliation_mismatch` | high | Внешняя сверка суммы выше допуска |
| `closed_period_locked` | critical | Операция меняет закрытый период |
| `forecast_version_missing` | medium | Нет active forecast version |
| `manual_adjustment_without_reason` | high | Корректировка без причины |
| `hidden_by_permissions` | medium | Источник скрыт правами пользователя |

### 9.2. Risk flags

| Code | Severity по умолчанию | Когда ставится |
| --- | --- | --- |
| `baseline_from_schedule_only` | high | BAC взят из графика из-за отсутствия бюджета/сметы |
| `planned_progress_used_as_fallback` | high | Percent complete рассчитан от плановой кривой |
| `project_dates_used_for_pv` | medium | PV рассчитан от дат проекта без baseline-графика |
| `cash_only_source` | medium | Есть платеж без accrual source |
| `accrual_without_payment` | low/medium | Есть начисление без оплаты |
| `payment_without_accrual` | medium | Есть оплата без управленческого расхода |
| `edo_pending` | medium | Ожидается ЭДО-подтверждение |
| `one_c_confirmation_pending` | medium | Ожидается подтверждение 1С |
| `bank_match_pending` | medium | Ожидается сверка банка |
| `stale_progress` | medium/high | Прогресс старше SLA |
| `stale_external_confirmation` | medium/critical | Внешнее подтверждение старше SLA |
| `forecast_version_stale` | high | Active forecast не пересматривался по cadence |
| `etc_method_variance` | medium | Bottom-up ETC и CPI ETC расходятся выше 5% |
| `baseline_cost_used_for_etc` | medium | Нет current expected unit cost |
| `manual_adjustment_active` | low | Есть активная ручная корректировка |
| `manual_adjustment_expiring` | medium | Корректировка скоро истекает |
| `source_disputed` | high | Источник оспорен или находится в dispute |
| `multi_currency_without_rate` | high | Нет курса для management currency |

## 10. Реализационные gaps для PHERP-99

### 10.1. Backend и хранение

Для PHERP-99 нужно добавить:

- таблицы forecast versions, forecast lines, forecast adjustments, forecast assumptions, forecast audit events;
- snapshot входных источников с hash и ссылками на source rows;
- нормализованный слой WIP/FTC attribution lines по аналогии с `ProjectMarginAttributionLine`;
- enum/DTO для formula components, source coverage, freshness, problem flags, risk flags;
- сервис расчета WIP/FTC, который не зависит от UI и возвращает стабильный DTO;
- idempotent recompute по project/as_of/version;
- cache invalidation по изменениям budget, estimate, schedule, completed work, acts, payment documents, warehouse movements, labor output, period closure;
- contract tests на форму `summary`, `rows`, `formulas`, `assumptions`, `source_coverage`, `freshness`, `problem_flags`, `risk_flags`, `drill_down`, `actions`, `meta`;
- permission contract tests с русскими подписями новых permissions.

### 10.2. Данные и интеграции

Нужно уточнить и реализовать:

- canonical mapping supplier/subcontractor cost в AC, чтобы не считать cash как cost;
- связь labor output, payroll accrual и management labor cost без дублирования юридического payroll;
- связь warehouse write-off/production usage с project/stage/estimate item;
- внешний status map для ЭДО/1С/банка в единый confirmation contract;
- правила включения pending claims/change orders в revenue forecast по scenario;
- currency rate source для management currency;
- правила current expected unit cost для ETC: procurement quotes, supplier rates, historical actuals, manual estimates.

### 10.3. UI и EPM dashboard

Для EPM dashboard/portfolio задач нужно:

- портфельный roll-up WIP, FTC, EAC, margin по organization/project manager/responsibility center;
- severity aggregation для problem/risk flags;
- drill-down из portfolio KPI в project forecast version;
- сравнение active forecast vs previous forecast vs approved budget;
- отдельный вид stale/partial/unavailable проектов;
- export с теми же формулами и source coverage, что API;
- role-aware скрытие чувствительных затрат без поломки агрегатов.

## 11. Acceptance criteria для PHERP-99

PHERP-99 считается реализованной корректно, если:

- любой итог WIP/FTC/EAC/margin объясняется до source document line или manual adjustment;
- пустые источники не превращаются в нулевые достоверные значения;
- cash-only платежи не попадают в AC;
- closed periods блокируют изменение утвержденных historical values;
- approved forecast version воспроизводима по snapshot;
- ручная корректировка без причины не включается в active forecast;
- API возвращает стабильный контракт с summary, rows, formulas, assumptions, source coverage, freshness, flags, drill-down, actions и meta;
- UI показывает пользователю понятные причины проблем, а не технические ключи;
- 1С/банк/ЭДО используются как подтверждение и сверка, а не как дублируемый бухгалтерский контур.
