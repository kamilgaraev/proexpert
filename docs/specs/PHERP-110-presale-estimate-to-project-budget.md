# PHERP-110: Presale-смета и перенос в проектный бюджет

## Статус и цель

Документ фиксирует техническую спецификацию для будущей реализации PHERP-111. В рамках PHERP-110 production-код не меняется: не добавляются миграции, модели, API, сервисы, UI-компоненты и фоновые задачи.

Цель PHERP-111 - подготовить управляемый перенос presale-сметы из цепочки `CRM / тендер / КП -> сделка -> проект -> договор` в проектный бюджет. Перенос должен работать после уже реализованного wizard конвертации сделки в проект и договор, сохранять трассируемость источника, учитывать права на суммы и не создавать бюджетные строки без явного подтверждения пользователя.

## Что изучено в текущем коде

### CRM, тендеры, КП и конвертация в проект

- CRM routes: `prohelper/app/BusinessModules/Features/Crm/routes.php`.
- Wizard конвертации: `prohelper/app/BusinessModules/Features/Crm/Services/DealConversionWizardService.php`.
- Контроллер wizard: `prohelper/app/BusinessModules/Features/Crm/Http/Controllers/DealConversionWizardController.php`.
- Операции idempotency: `prohelper/app/BusinessModules/Features/Crm/Models/CrmConversionOperation.php`.
- CRM-модели: `CrmDeal`, `CrmCompany`, `CrmContact`, `CrmLead`, `CrmActivity`.
- Tenders: `prohelper/app/BusinessModules/Features/Tenders`.
- Commercial Proposals: `prohelper/app/BusinessModules/Features/CommercialProposals`.

PHERP-112/113 уже закрыли создание или переиспользование проекта и договора из сделки:

- `POST /api/v1/admin/crm/deals/{id}/conversion/preview`
- `POST /api/v1/admin/crm/deals/{id}/conversion/validate`
- `POST /api/v1/admin/crm/deals/{id}/conversion/convert`

`DealConversionWizardService` строит preview, валидирует `preview_hash`, использует `DB::transaction`, `CrmConversionOperation`, `idempotency_key`, `payload_hash`, блокировки и audit. При успешной конвертации он создает или переиспользует `Project` и `Contract`, обновляет связи у `CrmDeal`, `Tender`, `CommercialProposal`, пишет timeline/audit events.

В текущем preview уже есть поле `budget_seed`, но оно является заглушкой: содержит источник суммы, видимость суммы, флаг `accepted`, пустой массив `items` и `source_id`. Это правильная точка UX-интеграции, но не полноценная модель presale-сметы и не перенос строк бюджета.

### CRM data

`CrmDeal` хранит:

- `organization_id`, `company_id`, `primary_contact_id`, `lead_id`, `owner_user_id`;
- `project_id`, `contract_id`;
- pipeline/stage/source, title, status;
- `amount`, `currency`, probability, expected close/won/lost dates;
- `custom_fields`, created/updated user fields, soft delete.

Суммы CRM скрываются правом `crm.amounts.view`; ресурсы возвращают `amount_visible`. Для PHERP-111 это означает, что preview и convert не должны раскрывать сумму сделки пользователю без права на суммы.

### Tenders data

`Tender` хранит:

- `organization_id`, customer company/contact, owner;
- `crm_deal_id`, `commercial_proposal_id`, `project_id`, `contract_id`;
- number/external refs, title, description, status, priority, risk level;
- `initial_max_price`, `expected_bid_amount`, `final_bid_amount`, `winner_amount`, `currency`;
- deadlines, go/no-go fields, winner/lost/cancel metadata;
- requirements/evaluation criteria/metadata.

Суммы тендера скрываются правом `tenders.amounts.view`. В тендере есть risks и requirements, но нет детализированной presale-сметы по работам, материалам и марже.

### Commercial Proposals data

`commercial_proposals` уже содержит важную будущую связь `presale_estimate_id`:

- `current_version_id`, `accepted_version_id`;
- `crm_deal_id`, `tender_id`, `presale_estimate_id`, `project_id`, `contract_id`;
- customer fields;
- `subtotal_amount`, `discount_amount`, `vat_amount`, `total_amount`, `currency`;
- status, valid/sent/decision timestamps, metadata.

`commercial_proposal_versions` хранит `sections_snapshot`, `source_links_snapshot`, `terms_snapshot`, `totals_snapshot`, `content_hash`.

`commercial_proposal_sections` и `commercial_proposal_line_items` хранят коммерческие разделы и строки:

- title, description, unit, quantity;
- unit price, discount, VAT rate;
- subtotal and total amounts;
- sort order, metadata.

Эти строки подходят как коммерческий источник, но не заменяют presale-смету: в них нет нормальной структуры себестоимости, ресурсов, маржи, рисков, бюджетных категорий и правил переноса.

### Projects and contracts

`Project` хранит:

- `organization_id`, name, address, customer/designer fields;
- `budget_amount`, `cost_category_id`;
- dates, status, additional info;
- customer organization/representative, contract number/date.

`Contract` хранит:

- `organization_id`, `project_id`, contractor/supplier, side/category;
- number/date/subject, work type, payment terms;
- `base_amount`, `total_amount`;
- statuses, warranty/subcontract flags, planned/actual advance.

Проект и договор уже становятся downstream-сущностями после PHERP-112/113. PHERP-111 должен проверять, что выбранная presale-смета относится к той же организации и той же source chain, что проект/договор.

### Project estimates

Сметы исполнения находятся в классической модели `Estimate`:

- `estimates`: project/contract, number, name, type, status, version, totals, VAT/overhead/profit rates, metadata;
- `estimate_sections`: иерархические разделы, `stable_key`;
- `estimate_items`: работы/материалы/оборудование/труд/summary, quantity, price fields, cost breakdown, overhead/profit, actual procurement, `stable_key`, metadata;
- `estimate_item_resources`: material/labor/equipment/overhead/other resources;
- `estimate_versions`, `estimate_change_log`, `estimate_snapshots`.

Эта модель нужна для проектной сметы и покрытия договора. Presale-смета не должна напрямую подменять `Estimate`: на этапе продажи данные могут быть укрупненными, коммерчески скрытыми и не готовыми для производственной сметы. PHERP-111 может в будущем создавать проектную `Estimate` из presale-сметы отдельным действием, но базовый перенос PHERP-111 должен быть сфокусирован на бюджетных строках.

### Budgeting data

Бюджетирование находится в `prohelper/app/BusinessModules/Features/Budgeting`.

Основные сущности:

- `budget_periods`: период, даты, статус закрытия.
- `budget_scenarios`: сценарий бюджета.
- `responsibility_centers`: ЦФО, тип, владелец, approver, linked entity.
- `budget_articles`: статья бюджета, `budget_kind`, `flow_direction`, `is_leaf`, `is_active`, `cost_category_id`.
- `budget_article_mappings`: внешние соответствия, сейчас в основном 1C.
- `budget_versions`: версия бюджета, период, сценарий, тип бюджета, status, workflow timestamps, `workflow_history`.
- `budget_lines`: статья, ЦФО, проект, договор, контрагент, валюта, описание, metadata.
- `budget_amounts`: месячные plan/forecast суммы.

`BudgetLineService::replace()` и `writeNormalizedRows()` проверяют:

- версия бюджета должна быть `draft`;
- период должен быть доступен для операции `budget_lines`;
- статья должна быть active leaf и совместима с `budget_kind`;
- ЦФО должен быть active;
- project/contract/counterparty должны быть scoped текущей организацией;
- месяц должен попадать в период версии.

Сейчас `writeNormalizedRows()` умеет `replace_lines` и группировку строк, но фактически не сохраняет source metadata из normalized rows. Для PHERP-111 это нужно расширить, иначе аудит переноса presale-строк в бюджет будет неполным.

## Целевая модель presale-сметы

Presale-смету нужно выделить в отдельный домен, например `BusinessModules/Features/PresaleEstimates`, а не расширять только `CommercialProposalLineItem`. Причины:

- КП является документом продажи, а presale-смета - расчетной моделью себестоимости и цены.
- У presale-сметы должны быть версии, статусы, mapping в бюджет и аудит.
- Одна presale-смета может быть источником для нескольких КП-версий, а КП может содержать коммерчески агрегированные строки.
- В переносе в бюджет нужен стабильный источник строк, а не snapshot HTML/коммерческого документа.

### Estimate header

Рекомендуемая таблица `presale_estimates`:

- `id` uuid primary key.
- `organization_id`.
- `number`, `title`, `description`.
- `status`: `draft`, `on_review`, `approved`, `accepted`, `converted`, `archived`.
- `currency`.
- `vat_mode`: `excluded`, `included`, `mixed`, `none`.
- `current_version_id`, `approved_version_id`, `accepted_version_id`.
- Source links: `crm_deal_id`, `tender_id`, `commercial_proposal_id`.
- Downstream links: `project_id`, `contract_id`.
- Totals: `cost_amount`, `overhead_amount`, `risk_amount`, `margin_amount`, `discount_amount`, `vat_amount`, `sale_amount`, `total_with_vat`.
- `amounts_visibility`: policy/snapshot for amount visibility, not user-specific permission result.
- `created_by_user_id`, `updated_by_user_id`, approval timestamps/users.
- `metadata` jsonb.
- timestamps and soft delete.

Source links must be nullable but org-scoped. На уровне сервиса нужно проверять согласованность цепочки:

- если указан `commercial_proposal_id`, его `crm_deal_id`, `tender_id`, `project_id`, `contract_id` не должны конфликтовать с header;
- если указан `tender_id`, его `crm_deal_id`, `commercial_proposal_id`, `project_id`, `contract_id` должны быть совместимы;
- если указан `crm_deal_id`, downstream links должны совпадать с текущей конвертированной сделкой.

### Versions

Рекомендуемая таблица `presale_estimate_versions`:

- `id` uuid primary key.
- `organization_id`, `presale_estimate_id`.
- `version_number`.
- `status`: `draft`, `on_review`, `approved`, `accepted`, `replaced`, `archived`.
- `title`.
- `content_hash`.
- `sections_snapshot`, `lines_snapshot`, `totals_snapshot`, `mapping_snapshot`, `source_links_snapshot`.
- `submitted_at`, `approved_at`, `accepted_at`, `locked_at`, user ids.
- `created_by_user_id`.
- timestamps.

Версия, из которой делается перенос в бюджет, должна быть locked или accepted. Для MVP PHERP-111 допустимо разрешить approved/accepted; draft можно показывать в preview, но блокировать convert.

### Sections and work packages

Рекомендуемая таблица `presale_estimate_sections`:

- `id` uuid primary key.
- `organization_id`, `presale_estimate_id`, `presale_estimate_version_id`.
- `parent_id`.
- `code`, `title`, `description`.
- `work_package_type`: `design`, `construction`, `supply`, `installation`, `commissioning`, `management`, `other`.
- `sort_order`.
- section totals: cost, overhead, risk, margin, sale, VAT.
- `metadata`.

Sections являются группировкой для preview и ручного mapping. Они не должны напрямую становиться бюджетными статьями: бюджетная статья выбирается на уровне строки или агрегированной группы.

### Line items

Рекомендуемая таблица `presale_estimate_line_items`:

- `id` uuid primary key.
- `organization_id`, `presale_estimate_id`, `presale_estimate_version_id`, `presale_estimate_section_id`.
- `stable_key` uuid для трассировки между версиями.
- `source_type`, `source_id`, `source_line_id` для связи с КП/импортом/ручным вводом.
- `line_type`: `material`, `work`, `labor`, `equipment`, `subcontract`, `overhead`, `margin`, `risk`, `discount`, `summary`, `other`.
- `title`, `description`, `unit`, `quantity`.
- `unit_cost`, `cost_amount`.
- `overhead_rate`, `overhead_amount`.
- `risk_rate`, `risk_amount`.
- `margin_rate`, `margin_amount`.
- `unit_sale_price`, `sale_amount`.
- `discount_amount`.
- `vat_rate`, `vat_amount`, `total_with_vat`.
- `currency`.
- Suggested mapping: `cost_category_id`, `budget_article_id`, `responsibility_center_id`, `counterparty_id`, `target_month`.
- `mapping_status`: `auto`, `manual_required`, `excluded`, `blocked`.
- `is_hidden_amount`: коммерческая или permission-sensitive сумма.
- `metadata`.
- timestamps.

Для материалов, работ, субподряда, накладных, маржи и рисков нужно хранить раздельные суммы. Это позволит переносить в бюджет именно управленческую себестоимость, а не только итоговую цену КП.

### Связь с КП, тендером, CRM, проектом и договором

Минимальные связи для PHERP-111:

- `CommercialProposal.presale_estimate_id` уже есть и должен стать основным bridge для КП.
- `PresaleEstimate.commercial_proposal_id`, `tender_id`, `crm_deal_id` хранят upstream chain.
- `PresaleEstimate.project_id`, `contract_id` проставляются после конвертации сделки или при ручной привязке.
- Timeline events должны появляться на CRM deal, tender и commercial proposal, если они есть.
- В budget line metadata должны попадать `presale_estimate_id`, `presale_estimate_version_id`, `presale_section_id`, `presale_line_id`, `stable_key`, `source_chain`.

Для PHERP-111 не обязательно добавлять `presale_estimate_id` прямо в `crm_deals` и `tenders`, если связь надежно восстанавливается через `CommercialProposal` и header presale-сметы. Но если UI должен запускать wizard напрямую из тендера или сделки без КП, прямые nullable-ссылки в этих таблицах нужно добавить миграцией PHERP-111.

## Mapping presale-сметы в проектный бюджет

### Target of transfer

Основной target PHERP-111 - `budget_versions` / `budget_lines` / `budget_amounts`.

Проектная `Estimate` остается отдельным downstream-документом. Она может создаваться позднее из presale-сметы или бюджета, но в рамках базового переноса не должна создаваться автоматически, чтобы не смешивать presale и execution scopes.

### Автоматически переносимые строки

Автоматически можно переносить строки, если выполнены все условия:

- presale estimate version имеет status `approved` или `accepted`;
- источник, проект, договор и budget version принадлежат одной организации;
- target budget version находится в `draft`;
- бюджетный период открыт для `budget_lines`;
- строка не является `summary`;
- строка не исключена пользователем;
- сумма видима текущему пользователю;
- валюта строки поддержана target budget policy;
- строка имеет однозначный `budget_article_id` или он однозначно выводится через `cost_category_id`;
- строка имеет `responsibility_center_id` или он однозначно выводится из проекта/договора/default project center;
- target month попадает в период версии.

По умолчанию в расходный проектный бюджет переносятся:

- `material`
- `work`
- `labor`
- `equipment`
- `subcontract`
- `overhead`
- `risk`, если политика переноса включает резерв рисков

Строки `margin`, `discount`, `summary` по умолчанию не переносятся в расходный бюджет автоматически. Они могут использоваться для revenue/margin analytics, но должны требовать явной политики и подходящего `budget_kind`/`flow_direction`.

### Строки с ручным mapping

Manual mapping обязателен, если:

- отсутствует или неоднозначна бюджетная статья;
- отсутствует ЦФО;
- `cost_category_id` не связан с active leaf `budget_article`;
- строка является `other`, `summary`, `margin`, `discount`;
- есть конфликт валюты или нужен курс пересчета;
- VAT mode смешанный или ставка не определена;
- сумма скрыта правами;
- строка содержит коммерческий агрегат без разбивки на себестоимость;
- target month не выбран или выходит за период;
- строка уже переносилась ранее и пользователь не выбрал режим update/skip/duplicate.

### Выбор budget article и cost category

`budget_article_id` является обязательным target-полем для `BudgetLine`. `cost_category_id` должен быть только подсказкой.

Порядок автоподбора:

1. `presale_estimate_line_items.budget_article_id`, если active leaf и совместим с target `budget_kind`.
2. `budget_articles.cost_category_id = line.cost_category_id`, если найден ровно один active leaf.
3. Mapping rule по `line_type` и `work_package_type`.
4. Project-level `Project.cost_category_id` -> active leaf `budget_article`.
5. Organization default mapping for presale line type.

Если найдено несколько кандидатов, preview должен вернуть warning и требовать выбора. Если не найден ни один кандидат, строка блокирует convert, пока пользователь не выберет статью или не исключит строку.

### ЦФО, проект, договор, контрагент

`responsibility_center_id` подбирается так:

1. явный mapping строки;
2. ЦФО с `linked_entity_type = project` и `linked_entity_id = project_id`;
3. ЦФО договора, если такой тип используется;
4. default project responsibility center организации.

`project_id` и `contract_id` должны заполняться во всех создаваемых бюджетных строках. `counterparty_id` заполняется для subcontract/material supplier lines, если контрагент известен и принадлежит организации; иначе остается null с warning.

### НДС

Presale-смета должна хранить cost/sale/VAT отдельно.

Правило по умолчанию для PHERP-111:

- в расходный бюджет переносится сумма без НДС;
- НДС не смешивается с себестоимостью;
- если организация ведет НДС отдельной бюджетной статьей, preview предлагает отдельную VAT line;
- если `vat_mode = included`, сервис должен пересчитать net/VAT по ставке строки;
- если ставка отсутствует или строка `mixed`, convert блокируется до ручного подтверждения.

### Валюта

Бюджетные строки уже хранят `currency`, но reporting и лимиты могут ожидать управленческую валюту.

Для PHERP-111:

- RUB строки можно переносить без курса;
- non-RUB строки допускаются только если target budget policy поддерживает валюту или пользователь указал exchange rate/date;
- preview показывает source amount, target management amount, курс и дату курса;
- отсутствие курса для обязательной конвертации является blocker.

### Скидки, маржа, риски

Скидка:

- уменьшает коммерческую цену КП;
- не должна автоматически уменьшать внутреннюю себестоимость;
- может быть распределена по sale-side строкам только для revenue/margin analytics.

Маржа:

- по умолчанию не переносится в expense budget;
- может быть перенесена в `income`/margin article только при явной политике и наличии прав;
- должна быть скрыта от пользователей без доступа к коммерческим суммам.

Риски:

- могут переноситься как contingency/reserve line, если `include_risks = true`;
- если риск задан процентом без суммы или без категории, нужен manual mapping;
- risk metadata должна сохранять источник расчета.

Накладные:

- могут переноситься отдельной строкой `overhead`, если есть бюджетная статья;
- либо распределяться по базовым строкам, если выбрана политика allocation.

### Скрытые суммы и права доступа

Preview не должен раскрывать суммы, если у пользователя нет всех необходимых прав на источник:

- `commercial_proposals.amounts.view` для КП;
- `tenders.amounts.view` для тендера;
- `crm.amounts.view` для сделки;
- новое право `presale_estimates.amounts.view` для presale-сметы;
- бюджетные права на target budget.

Если суммы скрыты:

- API возвращает `amount_visible = false`, `amount = null`, агрегаты без numeric totals;
- строки можно показать без сумм и с причиной ограничения;
- validate возвращает blocker для convert;
- convert запрещен, потому что создание бюджетных сумм пользователем без доступа нарушает модель безопасности.

## API-контракт для PHERP-111

### Canonical endpoints

Рекомендуемый base path:

- `POST /api/v1/admin/presale-estimates/{estimateId}/budget-transfer/preview`
- `POST /api/v1/admin/presale-estimates/{estimateId}/budget-transfer/validate`
- `POST /api/v1/admin/presale-estimates/{estimateId}/budget-transfer/convert`

Дополнительные contextual shortcuts можно добавить позднее:

- `POST /api/v1/admin/commercial-proposals/{proposalId}/presale-estimate/budget-transfer/preview`
- `POST /api/v1/admin/tenders/{tenderId}/presale-estimate/budget-transfer/preview`
- `POST /api/v1/admin/crm/deals/{dealId}/presale-estimate/budget-transfer/preview`

Canonical endpoint должен оставаться единственной точкой фактического convert.

### Preview request

```json
{
  "presale_estimate_version_id": "uuid",
  "target": {
    "project_id": 123,
    "contract_id": 456,
    "budget_version_id": "uuid",
    "budget_kind": "bdr",
    "budget_period_id": "uuid",
    "scenario_id": "uuid",
    "create_budget_version": false
  },
  "options": {
    "mode": "append_lines",
    "group_by": ["section", "budget_article", "responsibility_center", "month"],
    "amount_basis": "cost_without_vat",
    "include_vat": false,
    "include_margin": false,
    "include_risks": true,
    "overhead_policy": "separate_line",
    "duplicate_policy": "skip_existing",
    "target_month": "2026-07"
  },
  "mapping_overrides": [
    {
      "source_line_id": "uuid",
      "include": true,
      "budget_article_id": "uuid",
      "responsibility_center_id": "uuid",
      "cost_category_id": 10,
      "counterparty_id": null,
      "target_month": "2026-07"
    }
  ],
  "preview_hash": null
}
```

`preview_hash` в preview request опционален. Если он передан и отличается от рассчитанного, response должен вернуть blocker `preview_changed`.

### Validate request

Validate использует тот же payload, что preview, но обязан выполнить полную проверку:

- source version status;
- org scope source/target;
- проект и договор совместимы с source chain;
- target budget version editable;
- период открыт для `budget_lines`;
- budget articles active leaf и совместимы с `budget_kind`;
- ЦФО active;
- права пользователя;
- дубли/повторный перенос;
- валюта, НДС, hidden amounts;
- готовность каждой строки к convert.

Validate не должен писать budget lines.

### Convert request

```json
{
  "idempotency_key": "uuid-or-client-generated-key",
  "preview_hash": "sha256",
  "presale_estimate_version_id": "uuid",
  "target": {
    "project_id": 123,
    "contract_id": 456,
    "budget_version_id": "uuid"
  },
  "options": {
    "mode": "append_lines",
    "amount_basis": "cost_without_vat",
    "include_vat": false,
    "include_margin": false,
    "include_risks": true,
    "duplicate_policy": "skip_existing"
  },
  "mapping_overrides": []
}
```

`idempotency_key` обязателен только для convert. Клиент должен генерировать его один раз на попытку переноса и переиспользовать при повторной отправке после сетевой ошибки.

### Response body

Preview/validate должны возвращать единый contract:

```json
{
  "presale_estimate": {
    "id": "uuid",
    "number": "PS-0001",
    "title": "Presale-смета",
    "status": "accepted",
    "amount_visible": true
  },
  "source_chain": {
    "crm_deal": { "id": "uuid", "title": "Сделка" },
    "tender": { "id": "uuid", "title": "Тендер" },
    "commercial_proposal": { "id": "uuid", "number": "KP-0001" }
  },
  "target": {
    "project": { "id": 123, "name": "Проект" },
    "contract": { "id": 456, "number": "D-001" },
    "budget_version": { "id": "uuid", "name": "БДР проекта", "status": "draft" }
  },
  "totals": {
    "currency": "RUB",
    "source_cost_amount": 1000000,
    "transfer_plan_amount": 1050000,
    "vat_amount": 0,
    "hidden_amounts_count": 0
  },
  "rows": [
    {
      "source_line_id": "uuid",
      "stable_key": "uuid",
      "section": { "id": "uuid", "title": "Раздел" },
      "line_type": "material",
      "title": "Материалы",
      "amount_visible": true,
      "source_amount": 100000,
      "transfer_amount": 100000,
      "currency": "RUB",
      "target_month": "2026-07",
      "mapping_status": "auto",
      "budget_article": { "id": "uuid", "code": "MAT", "name": "Материалы" },
      "responsibility_center": { "id": "uuid", "code": "PRJ", "name": "Проектный ЦФО" },
      "blockers": [],
      "warnings": []
    }
  ],
  "blockers": [],
  "warnings": [],
  "ready_to_convert": true,
  "preview_hash": "sha256"
}
```

Blocker/warning format:

```json
{
  "key": "budget_article_missing",
  "label": "Выберите статью бюджета",
  "severity": "blocking",
  "scope": "line",
  "source_line_id": "uuid",
  "field": "budget_article_id",
  "meta": {}
}
```

Пользовательские `label` должны приходить через `trans_message(...)`, без технических формулировок.

### Convert response

```json
{
  "operation_id": "uuid",
  "status": "converted",
  "already_converted": false,
  "presale_estimate_id": "uuid",
  "presale_estimate_version_id": "uuid",
  "target_budget_version": {
    "id": "uuid",
    "name": "БДР проекта",
    "status": "draft"
  },
  "summary": {
    "created_lines_count": 8,
    "updated_lines_count": 0,
    "skipped_lines_count": 2,
    "transfer_plan_amount": 1050000,
    "currency": "RUB"
  },
  "warnings": [],
  "next_actions": [
    {
      "key": "open_budget_version",
      "label": "Открыть бюджет",
      "route": "/budgeting?tab=lines&version_id=uuid"
    },
    {
      "key": "open_project",
      "label": "Открыть проект",
      "route": "/projects/123"
    }
  ]
}
```

При повторе того же `idempotency_key` с тем же `payload_hash` response должен вернуть исходный `result_snapshot` и `already_converted = true`. При повторе ключа с другим payload должен быть blocker/error `idempotency_payload_mismatch`.

### Idempotency and operations

Рекомендуемая таблица `presale_estimate_budget_transfer_operations`:

- `id` uuid primary key.
- `organization_id`.
- `idempotency_key`.
- `presale_estimate_id`, `presale_estimate_version_id`.
- `project_id`, `contract_id`, `budget_version_id`.
- `payload_hash`, `preview_hash`.
- `status`: `pending`, `completed`, `failed`.
- `result_snapshot` jsonb.
- `error_code`, `error_message`.
- `created_by_user_id`, `completed_at`.
- timestamps.

Constraints:

- unique `organization_id + idempotency_key`;
- index by `presale_estimate_id + presale_estimate_version_id`;
- optional partial unique completed operation by target if бизнес запрещает повторный перенос той же версии в тот же budget version.

### Transaction and rollback requirements

Convert должен быть all-or-nothing:

- открыть transaction;
- lock operation row by `idempotency_key`;
- lock presale estimate/version;
- lock target budget version;
- повторно пересчитать preview и проверить `preview_hash`;
- выполнить validate внутри transaction;
- записать budget lines/amounts;
- записать operation result;
- записать audit/timeline events;
- commit.

Если любая строка с `blocking` попадает в convert, вся операция должна откатиться. Частичный перенос недопустим для MVP.

`BudgetLineService::writeNormalizedRows()` нужно расширить или обернуть так, чтобы сохранялись `metadata` budget lines с source refs. Нельзя писать budget lines raw insert без тех же проверок editability, org scope, статей, ЦФО и периода.

## Admin UI-сценарий

### Точки запуска wizard

Основная точка:

- detail screen КП в `prohelper_admin/src/pages/CommercialProposals/CommercialProposalsPage.tsx`, вкладка overview/links/history после accepted КП и наличия `project_id`/`contract_id`.

Дополнительные точки:

- финальный шаг `DealConversionWizard` после создания проекта и договора: next action "Подготовить бюджет", если source chain содержит КП или presale-смету;
- tender detail после won/result и связанного проекта/договора;
- project detail или budgeting workspace: действие "Импорт из presale-сметы" с выбором source estimate;
- project budget lines screen: secondary action, если выбран draft budget version.

Не нужно добавлять перенос в старый conversion wizard как автоматический шаг. Создание проекта/договора и перенос бюджета должны быть отдельными действиями: это снижает риск случайного создания бюджетных строк и упрощает idempotency.

### Wizard steps

1. Источник
   - показать CRM deal, tender, КП, presale estimate;
   - выбрать версию presale-сметы;
   - показать статус версии и готовность к переносу;
   - если presale-сметы нет, показать empty state и future action "Создать presale-смету из КП".

2. Target
   - выбрать project/contract;
   - выбрать существующую draft budget version или создать draft-версию;
   - выбрать period/scenario/budget kind при создании;
   - проверить совместимость source chain.

3. Preview
   - показать totals и строки по section/work package;
   - суммы скрывать при отсутствии прав;
   - показать статусы `auto`, `manual_required`, `excluded`, `blocked`;
   - показать blockers/warnings агрегировано.

4. Mapping
   - таблица строк с группировкой по разделам;
   - поля: include/exclude, budget article, cost category, responsibility center, counterparty, target month, amount basis;
   - bulk actions: применить статью к разделу, применить ЦФО ко всем строкам, исключить margin/discount, включить risks;
   - подсказки по статьям из `cost_category_id` и `line_type`.

5. Validate and convert
   - validate перед активной кнопкой переноса;
   - кнопка convert активна только при `ready_to_convert = true`;
   - idempotency key создается на клиенте при открытии шага convert и не меняется при retry;
   - при `preview_changed` UI возвращает пользователя на preview.

6. Result
   - показать созданные/пропущенные строки;
   - дать переход в `/budgeting` на выбранную версию и вкладку строк;
   - дать переход в проект и договор;
   - показать audit id/operation id для поддержки.

### Loading/Error/Empty states

Loading:

- загрузка source chain;
- загрузка catalogs бюджетирования;
- расчет preview;
- validate;
- convert.

Error:

- сетевые ошибки показывать бизнес-текстом;
- ошибки прав показывать без раскрытия скрытых сумм;
- ошибки stale preview предлагать обновить preview.

Empty:

- нет presale-сметы: предложить создать ее из КП в отдельной будущей задаче;
- нет проекта/договора: предложить сначала выполнить conversion wizard;
- нет draft budget version: предложить создать draft или перейти в бюджетирование;
- нет бюджетных статей/ЦФО: показать, какие справочники нужно заполнить.

### Existing admin integration points

Существующие файлы для PHERP-111:

- `prohelper_admin/src/components/crm/DealConversionWizard.tsx` - добавить next action, не смешивая с convert проекта/договора.
- `prohelper_admin/src/pages/CommercialProposals/CommercialProposalsPage.tsx` - основная кнопка запуска.
- `prohelper_admin/src/pages/Tenders/TendersWorkspacePage.tsx` - secondary entry point.
- `prohelper_admin/src/pages/Budgeting/BudgetingPage.tsx` - target screen и, возможно, action "Импорт из presale".
- `prohelper_admin/src/services/budgetingService.ts` и будущий `presaleEstimateBudgetTransferService.ts`.
- `prohelper_admin/src/types/budgeting.ts`, будущие `presaleEstimate.ts`/`presaleBudgetTransfer.ts`.

## Права и безопасность

### Новые permissions

Рекомендуемые права:

- `presale_estimates.view` - видеть presale-сметы без сумм.
- `presale_estimates.amounts.view` - видеть суммы presale-сметы.
- `presale_estimates.create` - создавать presale-смету.
- `presale_estimates.edit` - редактировать draft.
- `presale_estimates.approve` - согласовывать/принимать версию.
- `presale_estimates.transfer.preview` - строить preview переноса.
- `presale_estimates.transfer.validate` - выполнять validate.
- `presale_estimates.transfer.convert` - создавать budget lines.
- `presale_estimates.transfer.mapping.edit` - менять ручной mapping.

Для convert дополнительно требуются существующие права:

- `budgeting.budgets.view`;
- `budgeting.budgets.edit`;
- при создании версии - `budgeting.budgets.create`;
- права на просмотр проекта/договора;
- source amount rights, если перенос требует суммы.

Все новые права нужно добавить в `config/RoleDefinitions/...` и `lang/ru/permissions.php` с русскими названиями. Для API доступных прав нужен тест, чтобы UI не получал технические ключи вместо русских подписей.

### Security rules

- Любой id в request проверяется по `organization_id`.
- Нельзя переносить source estimate в чужой проект, чужой договор или чужую budget version.
- Нельзя переносить в не-draft budget version.
- Нельзя переносить в закрытый период без разрешенного режима корректировок.
- Нельзя раскрывать суммы через blockers, warnings, totals или audit payload пользователю без amount rights.
- Mapping overrides не должны принимать технические поля, которые позволяют обойти проверку article/center/org scope.
- Hidden commercial margin и discount должны оставаться скрытыми для пользователей без коммерческих прав.

### Audit trail

Нужно фиксировать:

- operation row с payload/result snapshot;
- `LoggingService->audit()` для preview/validate/convert;
- business/timeline event на CRM deal, tender, commercial proposal;
- изменение budget version workflow history или отдельное budget audit event;
- metadata у budget lines с source refs.

Минимальный audit payload:

- actor user id;
- organization id;
- source presale estimate/version;
- source chain ids;
- target project/contract/budget version;
- totals без раскрытия скрытых сумм неавторизованным читателям;
- count created/updated/skipped rows;
- `idempotency_key`, `preview_hash`, `payload_hash`.

## Требования к PHERP-111

### Миграции

PHERP-111 должен добавить:

- `presale_estimates`;
- `presale_estimate_versions`;
- `presale_estimate_sections`;
- `presale_estimate_line_items`;
- `presale_estimate_budget_transfer_operations`;
- при необходимости nullable `presale_estimate_id` в `crm_deals` и `tenders`;
- индексы по `organization_id`, source links, project/contract, statuses;
- foreign keys с корректным delete behavior;
- jsonb snapshots/metadata;
- permissions в role definitions и русские переводы.

Миграции не запускать автоматически.

### Backend services

Нужны сервисы:

- `PresaleEstimateService`;
- `PresaleEstimateVersionService`;
- `PresaleEstimateMappingResolver`;
- `PresaleEstimateAmountPolicy`;
- `PresaleEstimateBudgetTransferPreviewService`;
- `PresaleEstimateBudgetTransferValidationService`;
- `PresaleEstimateBudgetTransferConvertService`;
- `PresaleEstimateBudgetTransferAuditService`.

Backend implementation rules:

- контроллеры тонкие, с try/catch и `AdminResponse`;
- пользовательские сообщения через `trans_message(...)`;
- без `response()->json()` напрямую;
- org-scoped проверки всех source/target ids;
- idempotency по образцу `CrmConversionOperation`;
- transaction на convert;
- reuse/extension `BudgetLineService`, но с сохранением metadata;
- отдельные response resources/DTO для preview rows, blockers, warnings и result.

### Tests

Backend:

- unit tests mapping resolver: article by direct id, cost category, line type, ambiguity;
- amount policy: VAT included/excluded, margin/risk/discount, hidden amounts;
- feature tests preview/validate/convert happy path;
- permissions tests: no amount leak without amount rights;
- org scope tests для source/target mismatch;
- idempotency tests: same key same payload, same key different payload;
- rollback test: one invalid line prevents all writes;
- duplicate transfer policy tests;
- budget period closed/reopened tests;
- permission translation test for new rights.

Admin:

- service normalization tests для preview/validate/convert response;
- MSW scenarios: no presale estimate, no project/contract, no draft budget, hidden amounts, manual mapping, successful convert;
- wizard tests for disabled convert button on blockers;
- routing test from CP detail and conversion wizard result.

### Admin UI

Нужно добавить:

- types `presaleEstimate.ts` and `presaleBudgetTransfer.ts`;
- service `presaleEstimateBudgetTransferService.ts`;
- endpoints in `apiConstants.ts`;
- wizard component;
- integration into CP detail, deal conversion result, tender detail and budgeting target screen;
- loading/error/empty/result states;
- permission gates and amount visibility gates.

### Smoke-check after deploy

Минимальный сценарий:

1. Создать или выбрать CRM deal -> tender -> accepted КП с presale-сметой.
2. Выполнить conversion wizard и получить project + contract.
3. Создать draft budget version для проекта.
4. Открыть wizard переноса из КП.
5. Получить preview, убедиться в source chain и target budget.
6. Оставить одну строку на auto mapping, одну замапить вручную, одну исключить.
7. Выполнить validate.
8. Выполнить convert.
9. Проверить строки в budget version: project_id, contract_id, article, ЦФО, суммы, metadata source refs.
10. Повторить convert с тем же `idempotency_key` и убедиться, что дубликаты не созданы.
11. Проверить пользователя без rights на суммы: totals скрыты, convert заблокирован.
12. Проверить timeline/audit events на КП/сделке/тендере и operation result.

## Открытые решения перед PHERP-111

- Нужна ли возможность создавать presale-смету прямо из строк КП в MVP PHERP-111 или это отдельная задача.
- Нужно ли в PHERP-111 добавлять прямые `presale_estimate_id` в `crm_deals` и `tenders`, или достаточно связи через КП/header.
- Политика non-RUB валют: блокировать без курса или хранить строки в source currency.
- Политика VAT для организаций: отдельная VAT budget article или перенос только net amounts.
- Нужно ли создавать project `Estimate` из presale-сметы в той же задаче или оставить отдельным flow после бюджета.
- Разрешать ли повторный перенос той же версии presale-сметы в разные budget versions.

## Резюме целевого решения

PHERP-111 должен добавить отдельную версионируемую presale-смету как расчетный источник между КП/тендером/CRM и проектным бюджетом. Перенос в бюджет должен быть отдельным idempotent wizard после создания проекта и договора, с preview/validate/convert API, ручным mapping проблемных строк, строгой проверкой прав на суммы, транзакционным convert и полной трассировкой source refs в budget line metadata.
