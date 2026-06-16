# PHERP-112: Wizard конвертации сделки в проект и договор

## Статус и цель

Документ фиксирует техническую спецификацию для реализации PHERP-113. В рамках PHERP-112 production flow не реализуется: не добавляются миграции, API, сервисы, UI-компоненты и фоновые задачи.

Цель PHERP-113 - замкнуть цепочку `CRM / тендер / КП -> сделка -> проект -> договор` через управляемый wizard в админке, который до запуска показывает наследуемые данные, недостающие обязательные поля, создаваемые сущности и итоговые переходы.

## Что изучено в текущем коде

### CRM

- Роуты: `prohelper/app/BusinessModules/Features/Crm/routes.php`.
- Контроллер: `prohelper/app/BusinessModules/Features/Crm/Http/Controllers/CrmController.php`.
- Сервисы: `CrmRegistryService`, `CrmWorkflowService`, `CrmTimelineService`.
- Модели: `CrmDeal`, `CrmCompany`, `CrmContact`, `CrmLead`, `CrmActivity`.
- Текущий endpoint `POST /api/v1/admin/crm/deals/{id}/links` только привязывает существующие `project_id` и `contract_id` к сделке. Он не создает проект и договор.

Ключевые связи:

- `CrmDeal` связан с компанией, основным контактом, лидом, ответственным, проектом, договором, воронкой, стадией, источником и активностями.
- `CrmCompany` хранит `linked_organization_id` и `linked_contractor_id`, поэтому wizard должен сначала использовать уже связанную организацию/контрагента, а не создавать дубль.
- Суммы CRM скрываются правом `crm.amounts.view`; в ресурсах есть `amount_visible`.

### Tenders

- Роуты: `prohelper/app/BusinessModules/Features/Tenders/routes.php`.
- Контроллер: `TenderController`.
- Сервисы: `TenderRegistryService`, `TenderWorkflowService`, `TenderTimelineService`.
- Модель: `Tender`.

Ключевые связи и поля:

- `Tender` хранит `customer_company_id`, `customer_contact_id`, `crm_deal_id`, `commercial_proposal_id`, `project_id`, `contract_id`.
- Для наследования доступны `title`, `description`, `customer_name`, `customer_inn`, `customer_kpp`, `customer_ogrn`, `initial_max_price`, `expected_bid_amount`, `final_bid_amount`, `winner_amount`, `currency`, дедлайны, требования, файлы, риски.
- Суммы тендеров скрываются правом `tenders.amounts.view`.
- В текущем `TenderRegistryService` проверяются ссылки на CRM-сделку, проект и договор, но связь с КП требует отдельной org-scoped проверки в PHERP-113.

### CommercialProposals

- Роуты: `prohelper/app/BusinessModules/Features/CommercialProposals/routes.php`.
- Контроллер: `CommercialProposalController`.
- Сервис: `CommercialProposalService`.
- Модели: `CommercialProposal`, `CommercialProposalVersion`, `CommercialProposalLineItem`, `CommercialProposalSection`, `CommercialProposalFile`, timeline/export/approval models.

Ключевые связи и поля:

- `CommercialProposal` хранит `crm_deal_id`, `tender_id`, `project_id`, `contract_id`, `current_version_id`, `accepted_version_id`.
- Для наследования доступны customer fields, `total_amount`, `currency`, `valid_until`, файлы, `accepted_version`, `sections_snapshot`, `source_links_snapshot`, `terms_snapshot`, `totals_snapshot`.
- Суммы КП скрываются правом `commercial_proposals.amounts.view`.
- `CommercialProposalService` валидирует `crm_deal_id`, но ссылки `tender_id`, `project_id`, `contract_id` в текущем коде требуют дополнительной org-scoped проверки в новом wizard-сервисе.

### Projects

- Роуты: `prohelper/routes/api/v1/admin/projects.php`.
- Контроллер: `ProjectController`.
- Request: `prohelper/app/Http/Requests/Api/V1/Admin/Project/StoreProjectRequest.php`.
- Сервис: `ProjectService`.
- Модель: `Project`.

Обязательные поля создания проекта:

- `name`
- `status` со значениями `draft`, `active`, `completed`, `paused`, `cancelled`

Полезные поля для предзаполнения:

- `customer`, `customer_organization`, `customer_representative`
- `budget_amount`
- `start_date`, `end_date`
- `contract_number`, `contract_date`
- `additional_info`
- `cost_category_id`, если выбран пользователем

### Contracts

- Роуты: `prohelper/routes/api/v1/admin/contracts.php` и project-based маршруты.
- Контроллер: `ContractController`.
- Request: `prohelper/app/Http/Requests/Api/V1/Admin/Contract/StoreContractRequest.php`.
- Сервисы: `ContractService`, `ContractSideMutationService`.
- Модель: `Contract`.

Обязательные поля создания договора:

- `contract_side_type`
- `number`
- `date`
- `status`
- `project_id` или `project_ids`
- `base_amount`, если `is_fixed_amount=true`

Важные правила:

- `contract_side_type` определяет, нужен ли подрядчик или поставщик.
- `is_multi_project=true` требует `project_ids`.
- Для wizard основной сценарий PHERP-113 должен создавать один проект и один договор, поэтому `is_multi_project=false` по умолчанию.
- Если часть данных договора неизвестна, wizard должен создать блокер до конвертации, а не пытаться создать неполный договор.

### Budgeting и BudgetEstimates

- Budgeting routes: `prohelper/app/BusinessModules/Features/Budgeting/routes.php`.
- Budgeting models: `BudgetVersion`, `BudgetLine`, `BudgetAmount`, `BudgetPeriod`, `BudgetArticle`, `ResponsibilityCenter`.
- BudgetEstimates project routes: `prohelper/app/BusinessModules/Features/BudgetEstimates/routes-project.php`.
- Estimate model: `Estimate`.
- Estimate coverage service: `EstimateCoverageService`.

Вывод для wizard:

- PHERP-113 не должен напрямую создавать строки бюджета из UI.
- Wizard может подготовить `budget_seed` на основе КП/тендера/сделки: сумма, валюта, источник, возможные позиции КП, будущий `project_id`, будущий `contract_id`.
- Фактическое создание сметы или бюджетных строк должно быть отдельным действием после конвертации либо отдельным scope внутри PHERP-113, если это будет явно включено.

## Целевой wizard

### Входные точки в админке

Wizard должен открываться из:

- карточки сделки CRM;
- списка/карточки тендера, если тендер связан со сделкой или пользователь выбрал сделку на первом шаге;
- карточки КП, если КП связано со сделкой или пользователь выбрал сделку на первом шаге.

Если источник не содержит связанной сделки, конвертация блокируется до выбора существующей сделки CRM. PHERP-113 не должен автоматически создавать сделку из КП или тендера в этом wizard, потому что целевой flow именно `сделка -> проект -> договор`.

### Шаг 1. Выбор источника

Поддерживаемые типы источника:

- `crm_deal`
- `commercial_proposal`
- `tender`

Пользователь выбирает основной источник. Дополнительно wizard может принять связанные идентификаторы, если пользователь вручную уточняет граф источников:

- `crm_deal_id`
- `commercial_proposal_id`
- `tender_id`

Правило разрешения графа:

1. Основной источник загружается по `source.type` и `source.id`.
2. Если источник связан со сделкой, `crm_deal_id` становится канонической сделкой.
3. Если источник связан с КП или тендером, они добавляются в preview как связанные источники.
4. Если есть несколько кандидатов КП или тендера, backend возвращает список `source_candidates`, а UI просит пользователя выбрать один вариант.
5. Convert запрещен, пока `resolved.crm_deal_id` пустой.

### Шаг 2. Preview наследуемых данных

Preview должен быть рассчитан backend-сервисом. UI не должен самостоятельно выводить бизнес-решения о том, какие поля наследуются.

Наследуемые данные:

- Клиент: CRM-компания, основной контакт, ИНН/КПП/ОГРН, телефон, email, адреса.
- Сделка: название, статус, стадия, сумма, валюта, ожидаемая дата закрытия, ответственный.
- Тендер: номер, название, заказчик, суммы, сроки, результат, требования, файлы, риски.
- КП: номер, название, статус, принятая версия, сумма, валюта, условия, позиции, файлы.
- Будущие связи: какой проект и договор будут созданы или переиспользованы.

Приоритет суммы:

1. Принятая версия КП: `accepted_version.totals.total_amount` или `CommercialProposal.total_amount`.
2. Тендер: `winner_amount`, затем `final_bid_amount`, затем `expected_bid_amount`, затем `initial_max_price`.
3. Сделка: `CrmDeal.amount`.
4. Ручной ввод пользователя.

Если у пользователя нет права на просмотр сумм соответствующего источника, preview не возвращает сумму из этого источника. UI показывает нейтральный текст: "Сумма недоступна для просмотра. Укажите сумму договора вручную."

### Шаг 3. Обязательные недостающие поля

Backend возвращает единый список `missing_required_fields`. Каждый элемент должен содержать:

- `step` - шаг wizard;
- `field` - машинное имя поля для формы;
- `label` - человекочитаемое название поля;
- `message` - бизнес-понятное объяснение;
- `blocking` - влияет ли отсутствие поля на запуск конвертации;
- `source` - откуда поле ожидалось: `project`, `contract`, `counterparty`, `source`, `documents`, `budget_seed`.

Минимальный набор блокирующих проверок:

- выбрана и доступна CRM-сделка;
- сделка принадлежит текущей организации;
- у сделки нет уже созданной полной пары проект + договор, если пользователь не выбрал режим переиспользования;
- заполнены `project.name` и `project.status`;
- заполнены `contract.contract_side_type`, `contract.number`, `contract.date`, `contract.status`;
- выбран или подтвержден контрагент/поставщик, если это требует `contract_side_type`;
- заполнена `contract.base_amount`, если договор фиксированной суммы;
- пользователь имеет права на создание проекта и договора;
- связанные тендер/КП принадлежат той же организации;
- не обнаружен конфликт уже существующих ссылок `project_id` или `contract_id` между сделкой, КП и тендером.

Примеры пользовательских сообщений:

- "Выберите сделку CRM, с которой нужно связать проект и договор."
- "Укажите номер договора."
- "Выберите контрагента для договора."
- "У сделки уже есть связанный проект и договор. Откройте существующие объекты или выберите другой источник."
- "Сумма договора не заполнена. Укажите сумму вручную."

Запрещенные тексты для UI и API message:

- `payload`, `dto`, `exception`, `sql`, `constraint`, `legacy`, `fallback`;
- сырые сообщения исключений;
- названия таблиц и внутренних классов.

### Шаг 4. Создание проекта

Wizard предлагает проект:

```json
{
  "mode": "create",
  "id": null,
  "fields": {
    "name": "Строительство склада ABC",
    "status": "draft",
    "customer": "ООО ABC",
    "customer_organization": "ООО ABC",
    "customer_representative": "Иванов Иван",
    "budget_amount": "12000000.00",
    "start_date": null,
    "end_date": null,
    "contract_number": null,
    "contract_date": null,
    "additional_info": {
      "source": {
        "crm_deal_id": "uuid",
        "tender_id": "uuid",
        "commercial_proposal_id": "uuid"
      }
    }
  }
}
```

Правила:

- `mode=create` создает новый проект.
- `mode=reuse` используется, если сделка/КП/тендер уже указывает на существующий проект.
- Если найден существующий проект в одном из источников, backend должен предложить переиспользование и не создавать дубль без явного решения пользователя.
- Проект создается через существующий доменный сервис или совместимый слой, чтобы сохранились события, аудит, участники и project context.

### Шаг 5. Создание договора

Wizard предлагает договор:

```json
{
  "mode": "create",
  "id": null,
  "fields": {
    "contract_side_type": "customer_to_general_contractor",
    "contract_category": "work",
    "number": "Д-2026-001",
    "date": "2026-06-16",
    "subject": "Выполнение работ по проекту Строительство склада ABC",
    "status": "draft",
    "is_fixed_amount": true,
    "base_amount": "12000000.00",
    "total_amount": "12000000.00",
    "currency": "RUB",
    "start_date": null,
    "end_date": null,
    "notes": null
  }
}
```

Правила:

- `project_id` не передается с UI при создании нового проекта; backend подставляет ID созданного проекта внутри транзакции.
- Если проект переиспользуется, `project_id` должен соответствовать выбранному проекту.
- Если договор уже существует в одном из источников, backend предлагает `mode=reuse`.
- Если есть проект без договора, wizard может создать только недостающий договор, но должен явно показать, что проект будет переиспользован.
- Если есть договор без проекта или договор относится к другому проекту/организации, convert блокируется до ручного решения.

### Шаг 6. Связи с контрагентом, документами и будущим бюджетом

Контрагент:

- Сначала используется `CrmCompany.linked_contractor_id`.
- Затем ищется существующий контрагент текущей организации по ИНН.
- Если найден один надежный кандидат, preview предлагает `reuse`.
- Если найдено несколько кандидатов или данные неполные, wizard требует ручного выбора.
- Если контрагент не найден, PHERP-113 должен явно определить режим: либо создать контрагента, либо заблокировать конвертацию. Для первой реализации рекомендуется блокировать и требовать выбор существующего контрагента, чтобы не плодить дубли.

Документы:

- Для тендера использовать `TenderFile`.
- Для КП использовать `CommercialProposalFile` и экспорт принятой версии, если он уже есть.
- PHERP-113 не должен копировать файлы в S3 внутри основной транзакции.
- Рекомендуемый вариант первой реализации: сохранить ссылки на исходные документы в metadata/timeline и показать их в результате как "связанные документы".
- Если позже потребуется физическое копирование документов в проект/договор, это должно быть отдельной post-commit задачей с повторяемым статусом.

Будущий бюджет:

- Preview возвращает `budget_seed`, но convert не создает `BudgetLine` напрямую.
- `budget_seed` должен включать источник суммы, валюту, позиции КП в агрегированном виде, будущие `project_id`/`contract_id` после конвертации и признак `can_create_estimate_later`.
- Если PHERP-113 расширят до создания сметы, использовать `EstimateCoverageService::createFromContract()` и `attachFullCoverage()`, а не прямую запись `Estimate.contract_id` из UI.

### Шаг 7. Финальный результат

После успешной конвертации UI показывает:

- созданный или переиспользованный проект;
- созданный или переиспользованный договор;
- обновленные связи сделки, тендера и КП;
- предупреждения, если были необязательные post-commit действия;
- кнопки перехода в созданные объекты.

Backend должен вернуть готовые route targets, чтобы UI не строил пути по догадкам.

## API-контракт PHERP-113

### Общие правила

Добавить маршруты в `prohelper/app/BusinessModules/Features/Crm/routes.php`:

- `POST /api/v1/admin/crm/conversion-wizard/preview`
- `POST /api/v1/admin/crm/conversion-wizard/validate`
- `POST /api/v1/admin/crm/conversion-wizard/convert`

Имена маршрутов:

- `admin.crm.conversion_wizard.preview`
- `admin.crm.conversion_wizard.validate`
- `admin.crm.conversion_wizard.convert`

Response wrapper:

- все успешные ответы через `AdminResponse::success($data, $message, $status, $meta)`;
- ошибки через `AdminResponse::error($message, $status, $errors, $extra)`;
- пользовательские тексты через `trans_message(...)`.

Минимальные права:

- preview: `crm.deals.view`;
- если связан тендер: `tenders.view`;
- если связано КП: `commercial_proposals.view`;
- суммы CRM: `crm.amounts.view`;
- суммы тендера: `tenders.amounts.view`;
- суммы КП: `commercial_proposals.amounts.view`;
- validate/convert: отдельное новое право `crm.deals.convert_project_contract`;
- создание проекта: `admin.projects.edit`;
- создание договора: `admin.contracts.edit` или project-context право `contracts.create`, если convert идет из project-based context;
- обновление связей сделки: `crm.deals.link`;
- обновление связей тендера: `tenders.convert.project`;
- обновление связей КП: новое право `commercial_proposals.convert.project_contract` либо существующее `commercial_proposals.update`, если команда примет такое решение.

Новое право `crm.deals.convert_project_contract` нужно добавить в role definitions и `lang/ru/permissions.php` с русским названием. Для него обязателен контрактный тест, чтобы UI не получил технический ключ.

### POST preview

Endpoint:

`POST /api/v1/admin/crm/conversion-wizard/preview`

Назначение:

- собрать граф источников;
- предложить проект, договор, контрагента и budget seed;
- вернуть blockers, warnings и missing fields;
- не менять данные.

Request:

```json
{
  "source": {
    "type": "commercial_proposal",
    "id": "8f76d6f0-7c12-45b0-9e8b-7c5c1d5a1d90"
  },
  "related_source_ids": {
    "crm_deal_id": "a7832d4e-1c18-4147-8f20-12c87d6f5401",
    "tender_id": "77e942bb-4c92-41b0-9446-1f4e7dd44121"
  },
  "options": {
    "include_documents": true,
    "include_budget_seed": true,
    "amount_source": "auto"
  }
}
```

Response 200:

```json
{
  "success": true,
  "message": null,
  "data": {
    "preview_hash": "sha256:...",
    "expires_at": "2026-06-16T12:30:00+03:00",
    "can_convert": false,
    "source_graph": {
      "primary": {
        "type": "commercial_proposal",
        "id": "8f76d6f0-7c12-45b0-9e8b-7c5c1d5a1d90",
        "title": "КП-2026-004",
        "status": "accepted",
        "status_label": "Принято клиентом"
      },
      "crm_deal": {
        "id": "a7832d4e-1c18-4147-8f20-12c87d6f5401",
        "title": "Строительство склада ABC",
        "status": "won",
        "stage_label": "Сделка выиграна"
      },
      "tender": {
        "id": "77e942bb-4c92-41b0-9446-1f4e7dd44121",
        "title": "Тендер на строительство склада",
        "status": "won"
      },
      "commercial_proposal": {
        "id": "8f76d6f0-7c12-45b0-9e8b-7c5c1d5a1d90",
        "number": "КП-2026-004",
        "status": "accepted"
      }
    },
    "inherited": {
      "customer": {
        "crm_company_id": "uuid",
        "name": "ООО ABC",
        "inn": "7700000000",
        "primary_contact": {
          "id": "uuid",
          "name": "Иванов Иван",
          "phone": "+79990000000",
          "email": "ivanov@example.com"
        }
      },
      "amount": {
        "visible": true,
        "source": "commercial_proposal",
        "value": "12000000.00",
        "currency": "RUB"
      },
      "dates": {
        "expected_close_at": "2026-06-30",
        "tender_submission_deadline_at": "2026-05-20",
        "proposal_valid_until": "2026-07-01"
      },
      "documents": [
        {
          "source_type": "commercial_proposal",
          "source_file_id": "uuid",
          "name": "КП-2026-004.pdf",
          "category": "proposal"
        }
      ]
    },
    "proposed": {
      "project": {
        "mode": "create",
        "id": null,
        "fields": {
          "name": "Строительство склада ABC",
          "status": "draft",
          "customer": "ООО ABC",
          "customer_organization": "ООО ABC",
          "customer_representative": "Иванов Иван",
          "budget_amount": "12000000.00"
        }
      },
      "contract": {
        "mode": "create",
        "id": null,
        "fields": {
          "contract_side_type": "customer_to_general_contractor",
          "contract_category": "work",
          "number": null,
          "date": "2026-06-16",
          "subject": "Выполнение работ по проекту Строительство склада ABC",
          "status": "draft",
          "is_fixed_amount": true,
          "base_amount": "12000000.00",
          "total_amount": "12000000.00"
        }
      },
      "counterparty": {
        "mode": "select",
        "crm_company_id": "uuid",
        "contractor_id": null,
        "candidates": []
      },
      "documents": {
        "mode": "link_references",
        "items": []
      },
      "budget_seed": {
        "enabled": true,
        "source": "commercial_proposal",
        "amount": "12000000.00",
        "currency": "RUB",
        "line_items_count": 12,
        "can_create_estimate_later": true
      }
    },
    "existing_links": {
      "project": null,
      "contract": null
    },
    "missing_required_fields": [
      {
        "step": "contract",
        "field": "contract.number",
        "label": "Номер договора",
        "message": "Укажите номер договора.",
        "blocking": true,
        "source": "contract"
      },
      {
        "step": "counterparty",
        "field": "counterparty.contractor_id",
        "label": "Контрагент договора",
        "message": "Выберите контрагента для договора.",
        "blocking": true,
        "source": "counterparty"
      }
    ],
    "warnings": [
      {
        "code": "documents_link_only",
        "message": "Документы будут связаны с результатом конвертации без копирования файлов."
      }
    ],
    "blockers": [],
    "permissions": {
      "can_view_amounts": true,
      "can_create_project": true,
      "can_create_contract": true,
      "can_link_sources": true
    }
  }
}
```

### POST validate

Endpoint:

`POST /api/v1/admin/crm/conversion-wizard/validate`

Назначение:

- проверить заполненный wizard перед запуском;
- не менять данные;
- вернуть field errors и blockers в том же формате, который UI может показать рядом с полями.

Request:

```json
{
  "preview_hash": "sha256:...",
  "source": {
    "type": "crm_deal",
    "id": "a7832d4e-1c18-4147-8f20-12c87d6f5401"
  },
  "related_source_ids": {
    "commercial_proposal_id": "8f76d6f0-7c12-45b0-9e8b-7c5c1d5a1d90",
    "tender_id": "77e942bb-4c92-41b0-9446-1f4e7dd44121"
  },
  "project": {
    "mode": "create",
    "id": null,
    "fields": {
      "name": "Строительство склада ABC",
      "status": "draft",
      "customer": "ООО ABC",
      "customer_organization": "ООО ABC",
      "customer_representative": "Иванов Иван",
      "budget_amount": "12000000.00"
    }
  },
  "contract": {
    "mode": "create",
    "id": null,
    "fields": {
      "contract_side_type": "customer_to_general_contractor",
      "contract_category": "work",
      "number": "Д-2026-001",
      "date": "2026-06-16",
      "subject": "Выполнение работ по проекту Строительство склада ABC",
      "status": "draft",
      "is_fixed_amount": true,
      "base_amount": "12000000.00",
      "total_amount": "12000000.00"
    }
  },
  "counterparty": {
    "mode": "reuse",
    "contractor_id": 123,
    "supplier_id": null
  },
  "documents": {
    "mode": "link_references",
    "source_file_ids": ["uuid"]
  },
  "budget_seed": {
    "enabled": true
  }
}
```

Response 200:

```json
{
  "success": true,
  "message": null,
  "data": {
    "valid": true,
    "preview_hash": "sha256:...",
    "missing_required_fields": [],
    "field_errors": {},
    "warnings": [],
    "blockers": []
  }
}
```

Response 422:

```json
{
  "success": false,
  "message": "Проверьте данные перед созданием проекта и договора.",
  "data": null,
  "error": "Проверьте данные перед созданием проекта и договора.",
  "errors": {
    "contract.number": ["Укажите номер договора."],
    "counterparty.contractor_id": ["Выберите контрагента для договора."]
  },
  "missing_required_fields": [
    {
      "step": "contract",
      "field": "contract.number",
      "label": "Номер договора",
      "message": "Укажите номер договора.",
      "blocking": true,
      "source": "contract"
    }
  ]
}
```

### POST convert

Endpoint:

`POST /api/v1/admin/crm/conversion-wizard/convert`

Назначение:

- атомарно создать или переиспользовать проект и договор;
- обновить ссылки сделки, тендера и КП;
- записать audit/timeline;
- вернуть итоговый результат.

Idempotency:

- UI должен отправлять `idempotency_key` в body.
- Дополнительно backend может поддержать HTTP header `Idempotency-Key`, но body обязателен для admin service.
- Ключ генерируется один раз при открытии wizard и сохраняется до успешного результата или закрытия wizard.

Request:

```json
{
  "idempotency_key": "c87c8c17-8db2-4c15-8a4e-5a6edca348fa",
  "preview_hash": "sha256:...",
  "source": {
    "type": "crm_deal",
    "id": "a7832d4e-1c18-4147-8f20-12c87d6f5401"
  },
  "related_source_ids": {
    "commercial_proposal_id": "8f76d6f0-7c12-45b0-9e8b-7c5c1d5a1d90",
    "tender_id": "77e942bb-4c92-41b0-9446-1f4e7dd44121"
  },
  "project": {
    "mode": "create",
    "id": null,
    "fields": {
      "name": "Строительство склада ABC",
      "status": "draft",
      "customer": "ООО ABC",
      "customer_organization": "ООО ABC",
      "customer_representative": "Иванов Иван",
      "budget_amount": "12000000.00",
      "start_date": null,
      "end_date": null
    }
  },
  "contract": {
    "mode": "create",
    "id": null,
    "fields": {
      "contract_side_type": "customer_to_general_contractor",
      "contract_category": "work",
      "number": "Д-2026-001",
      "date": "2026-06-16",
      "subject": "Выполнение работ по проекту Строительство склада ABC",
      "status": "draft",
      "is_fixed_amount": true,
      "base_amount": "12000000.00",
      "total_amount": "12000000.00",
      "start_date": null,
      "end_date": null
    }
  },
  "counterparty": {
    "mode": "reuse",
    "contractor_id": 123,
    "supplier_id": null
  },
  "documents": {
    "mode": "link_references",
    "source_file_ids": ["uuid"]
  },
  "budget_seed": {
    "enabled": true
  }
}
```

Response 201:

```json
{
  "success": true,
  "message": "Проект и договор созданы.",
  "data": {
    "conversion_id": "uuid",
    "status": "completed",
    "idempotent_replay": false,
    "source_graph": {
      "crm_deal_id": "a7832d4e-1c18-4147-8f20-12c87d6f5401",
      "commercial_proposal_id": "8f76d6f0-7c12-45b0-9e8b-7c5c1d5a1d90",
      "tender_id": "77e942bb-4c92-41b0-9446-1f4e7dd44121"
    },
    "project": {
      "mode": "created",
      "id": 1001,
      "name": "Строительство склада ABC",
      "status": "draft",
      "route": {
        "type": "admin_project",
        "path": "/projects/1001",
        "label": "Открыть проект"
      }
    },
    "contract": {
      "mode": "created",
      "id": 245,
      "number": "Д-2026-001",
      "status": "draft",
      "route": {
        "type": "admin_project_contract",
        "path": "/projects/1001/contracts/245",
        "label": "Открыть договор"
      }
    },
    "links": {
      "crm_deal": {
        "id": "a7832d4e-1c18-4147-8f20-12c87d6f5401",
        "project_id": 1001,
        "contract_id": 245,
        "route": {
          "type": "admin_crm_deal",
          "path": "/crm?deal=a7832d4e-1c18-4147-8f20-12c87d6f5401",
          "label": "Открыть сделку"
        }
      },
      "tender": {
        "id": "77e942bb-4c92-41b0-9446-1f4e7dd44121",
        "project_id": 1001,
        "contract_id": 245,
        "route": {
          "type": "admin_tender",
          "path": "/tenders?selected=77e942bb-4c92-41b0-9446-1f4e7dd44121",
          "label": "Открыть тендер"
        }
      },
      "commercial_proposal": {
        "id": "8f76d6f0-7c12-45b0-9e8b-7c5c1d5a1d90",
        "project_id": 1001,
        "contract_id": 245,
        "route": {
          "type": "admin_commercial_proposal",
          "path": "/commercial-proposals?selected=8f76d6f0-7c12-45b0-9e8b-7c5c1d5a1d90",
          "label": "Открыть КП"
        }
      }
    },
    "documents": {
      "mode": "linked_references",
      "linked_count": 1,
      "items": [
        {
          "source_type": "commercial_proposal",
          "source_file_id": "uuid",
          "name": "КП-2026-004.pdf"
        }
      ]
    },
    "budget_seed": {
      "status": "prepared",
      "source": "commercial_proposal",
      "amount": "12000000.00",
      "currency": "RUB",
      "next_action": {
        "type": "create_estimate_from_contract",
        "label": "Подготовить смету по договору",
        "enabled": true
      }
    },
    "warnings": [],
    "next_actions": [
      {
        "type": "open_project",
        "label": "Открыть проект",
        "path": "/projects/1001"
      },
      {
        "type": "open_contract",
        "label": "Открыть договор",
        "path": "/projects/1001/contracts/245"
      }
    ]
  }
}
```

Response 200 для повторного idempotency key:

```json
{
  "success": true,
  "message": "Проект и договор уже созданы по этому запросу.",
  "data": {
    "conversion_id": "uuid",
    "status": "completed",
    "idempotent_replay": true,
    "project": {
      "mode": "created",
      "id": 1001
    },
    "contract": {
      "mode": "created",
      "id": 245
    }
  }
}
```

### Ошибки

403:

```json
{
  "success": false,
  "message": "Недостаточно прав для создания проекта и договора.",
  "data": null,
  "error": "Недостаточно прав для создания проекта и договора.",
  "errors": {
    "permissions": ["Обратитесь к администратору организации."]
  }
}
```

404:

```json
{
  "success": false,
  "message": "Источник конвертации не найден или недоступен.",
  "data": null,
  "error": "Источник конвертации не найден или недоступен."
}
```

409:

```json
{
  "success": false,
  "message": "У сделки уже есть связанный проект и договор.",
  "data": null,
  "error": "У сделки уже есть связанный проект и договор.",
  "errors": {
    "source": ["Откройте существующие объекты или выберите другой источник."]
  },
  "existing_links": {
    "project_id": 1001,
    "contract_id": 245
  }
}
```

422:

```json
{
  "success": false,
  "message": "Проверьте данные перед созданием проекта и договора.",
  "data": null,
  "error": "Проверьте данные перед созданием проекта и договора.",
  "errors": {
    "contract.date": ["Укажите дату договора."]
  }
}
```

500:

- Возвращать только общий бизнес-текст: "Не удалось создать проект и договор. Попробуйте повторить позже или обратитесь к администратору."
- Технические детали писать только в логи.

## Backend-требования PHERP-113

### Сервисный слой

Рекомендуемые новые классы:

- `DealConversionWizardController`
- `DealConversionPreviewRequest`
- `DealConversionValidateRequest`
- `DealConversionConvertRequest`
- `DealConversionWizardService`
- DTO для `SourceGraph`, `ConversionPreview`, `ProjectDraft`, `ContractDraft`, `ConversionResult`
- Resource/mapper для ответа wizard

Контроллер должен быть тонким: авторизация, request validation, вызов сервиса, `AdminResponse`.

### Транзакционность

Convert должен выполняться в одной DB-транзакции:

1. Найти или создать idempotency operation.
2. Заблокировать operation row.
3. Заблокировать каноническую сделку через `lockForUpdate`.
4. Заблокировать связанные КП/тендер, если они участвуют в конвертации.
5. Повторно выполнить backend validation.
6. Создать или переиспользовать проект.
7. Создать или переиспользовать договор.
8. Обновить `project_id` и `contract_id` у сделки, тендера и КП.
9. Записать обязательный audit/timeline внутри транзакции или подготовить outbox-события.
10. Сохранить result snapshot в idempotency operation.

Если любой обязательный шаг падает, не должно остаться частично созданной цепочки "проект без договора" или "договор без ссылок на источники".

Особое внимание: текущий `ContractSideMutationService` сам управляет транзакцией. Для PHERP-113 нужно либо сделать его корректно совместимым с внешней транзакцией, либо вынести внутренние операции договора в метод, который можно вызвать внутри общей transaction boundary.

### Idempotency key

Нужна постоянная таблица, например `crm_conversion_operations`.

Рекомендуемые поля:

- `id` UUID;
- `organization_id`;
- `idempotency_key`;
- `source_type`;
- `source_id`;
- `crm_deal_id`;
- `commercial_proposal_id`;
- `tender_id`;
- `payload_hash`;
- `preview_hash`;
- `status`: `processing`, `completed`, `failed`;
- `project_id`;
- `contract_id`;
- `result_snapshot` JSONB;
- `error_code`;
- `created_by_user_id`;
- `completed_at`;
- timestamps.

Ограничения:

- unique `(organization_id, idempotency_key)`;
- защита от дублей по канонической сделке для завершенных операций;
- при повторе с тем же ключом и тем же payload hash вернуть сохраненный результат;
- при повторе с тем же ключом, но другим payload hash вернуть 409 с текстом "Запрос уже использовался для другой конвертации. Обновите страницу wizard и повторите действие."

### Отсутствие дублей

Правила:

- Если сделка уже имеет `project_id` и `contract_id`, новый convert с другим idempotency key возвращает 409.
- Если сделка имеет только `project_id`, preview предлагает `project.mode=reuse` и создание недостающего договора.
- Если сделка имеет только `contract_id`, preview должен проверить договор и его проект. Если проект не совпадает с графом источников, convert блокируется.
- Если тендер/КП уже указывают на другой проект или договор, convert блокируется до ручного решения.
- Если есть `CrmCompany.linked_contractor_id`, он имеет приоритет перед созданием или поиском нового контрагента.
- Если найден контрагент по ИНН, backend возвращает кандидата в preview, но не выбирает автоматически при неоднозначности.

### Audit trail и timeline

Обязательные записи:

- technical/business log начала и завершения конвертации;
- audit event `crm.deal_conversion.completed`;
- CRM timeline event на сделке;
- Tender timeline event, если участвовал тендер;
- CommercialProposal timeline event, если участвовало КП;
- Project/Contract audit events через существующие сервисы.

В audit/timeline сохранять:

- тип и ID источников;
- ID созданного/переиспользованного проекта;
- ID созданного/переиспользованного договора;
- пользователь, организация, timestamp;
- режим документации и budget seed.

Не сохранять в audit:

- сырые request payload;
- stack trace;
- SQL/constraint diagnostics;
- секреты, токены, приватные ссылки S3.

### Проверки прав

Backend проверяет права на каждом endpoint, а не доверяет UI:

- доступ к текущей организации;
- доступ к CRM-сделке;
- доступ к связанному тендеру/КП;
- право видеть суммы до возврата сумм в preview;
- право создать проект;
- право создать договор;
- право обновить связи источников;
- project context роль, если используется существующий проект;
- доступность выбранного контрагента/поставщика для текущей организации.

Если суммы недоступны, backend не возвращает их даже в hidden/meta/debug полях.

### Rollback и partial failure

Базовая стратегия:

- обязательные DB-изменения выполняются в одной транзакции;
- при ошибке транзакция откатывается полностью;
- idempotency operation с ошибкой сохраняет безопасный статус и код, но не технический текст;
- пользователю возвращается бизнес-понятное сообщение.

Post-commit действия:

- копирование файлов, тяжелые экспорты, генерация сметы и фоновые пересчеты не должны выполняться внутри основной транзакции;
- если post-commit действие включено позже и падает, основной result может быть `completed_with_warnings`;
- для таких действий нужен повторяемый job/outbox и понятный warning в результате.

### Валидация данных

Convert не должен доверять `preview_hash`. Он обязан заново:

- загрузить источники;
- проверить организацию и права;
- проверить текущие ссылки `project_id`/`contract_id`;
- проверить обязательные поля проекта и договора;
- проверить контрагента;
- проверить, что payload hash соответствует idempotency operation.

`preview_hash` нужен только для UX-предупреждения о stale preview. Если preview устарел, validate/convert возвращает 409 или 422 с текстом "Данные источника изменились. Обновите preview перед созданием проекта и договора."

### Тесты backend

Минимум для PHERP-113:

- unit tests для source graph resolver;
- unit tests для наследования полей и приоритета суммы;
- feature tests для preview/validate/convert;
- tests на скрытие сумм без `crm.amounts.view`, `tenders.amounts.view`, `commercial_proposals.amounts.view`;
- tests на idempotency replay;
- tests на конфликт уже связанной сделки;
- tests на rollback при ошибке создания договора;
- tests на русские названия нового permission;
- `php -l` для новых PHP-файлов;
- targeted `phpstan analyse` по новым классам и измененным сервисам.

## Admin UI требования PHERP-113

### Файлы и слои

Рекомендуемые новые файлы:

- `prohelper_admin/src/types/dealConversion.ts`
- `prohelper_admin/src/services/dealConversionService.ts`
- `prohelper_admin/src/components/crm/DealConversionWizard.tsx`
- при необходимости: `DealConversionPreviewStep`, `DealConversionProjectStep`, `DealConversionContractStep`, `DealConversionResultStep`.

Изменения в существующих файлах:

- добавить endpoints в `ADMIN_ENDPOINTS.CRM` в `apiConstants.ts`;
- добавить action в CRM deal detail;
- добавить action в Tender detail, если есть связанная сделка или пользователь может выбрать сделку;
- добавить action в Commercial Proposal detail, если есть связанная сделка или пользователь может выбрать сделку.

Сервисный слой:

- использовать `api.ts`;
- использовать `unwrapResponseData`;
- нормализовать mixed payload явно, как в `commercialProposalService.ts`;
- компоненты не должны читать raw `response.data.success`.

### Wizard шаги

1. Источник.
2. Preview.
3. Недостающие данные.
4. Проект.
5. Договор.
6. Связи, документы, будущий бюджет.
7. Результат.

Stepper должен переходить к первому шагу с blocking error после validate.

### Loading state

- При открытии из конкретной сделки сразу показывать skeleton preview.
- При поиске источников показывать компактный loading списка.
- Во время validate блокировать кнопку "Создать проект и договор", но оставлять возможность вернуться на предыдущие шаги.
- Во время convert блокировать закрытие только если есть риск повторной отправки; idempotency key должен сохраняться в состоянии wizard.

### Error state

- API error показывать в верхнем блоке текущего шага.
- `errors[field]` показывать рядом с конкретным полем.
- `missing_required_fields` показывать отдельным списком "Что нужно заполнить".
- `blockers` показывать до запуска convert, а не после.
- Тексты должны быть бизнес-понятными: "Укажите номер договора", "Выберите контрагента", "Источник уже связан с проектом и договором".

### Empty state

Отдельные empty states:

- не найдено доступных сделок;
- выбранное КП не связано со сделкой;
- выбранный тендер не связан со сделкой;
- нет доступных КП/тендеров для выбранной сделки;
- нет кандидатов контрагента;
- нет документов для связи;
- budget seed недоступен, потому что нет суммы или нет прав на просмотр суммы.

### Preview UI

Показывать:

- карточку источника и связанные объекты;
- клиента и контакт;
- сумму с явным источником, если сумма доступна;
- проект, который будет создан или переиспользован;
- договор, который будет создан или переиспользован;
- контрагента и кандидатов;
- документы;
- future budget seed.

Не показывать:

- технические поля `payload_hash`, class names, SQL/table names;
- сырые JSON payload;
- stack trace.

### Result UI

После convert показать:

- статус "Проект и договор созданы" или "Использованы существующие проект и договор";
- ID/номер/название проекта и договора;
- переходы из `result.route` и `next_actions`;
- ссылки на сделку, тендер и КП;
- warnings по документам/budget seed, если они были.

### Тесты admin

Минимум для PHERP-113:

- tests нормализатора `dealConversionService`;
- tests отображения missing required fields;
- tests disabled submit во время convert;
- tests idempotency key сохраняется между validate и convert;
- tests result routes используются из API, а не собираются вручную;
- `npx tsc --noEmit`;
- targeted Vitest по сервису и wizard state.

Сборку `npm run build` для `prohelper_admin` не запускать без отдельного разрешения.

## Критерии готовности PHERP-113

- Preview не меняет данные.
- Validate не меняет данные.
- Convert атомарно создает или переиспользует проект и договор.
- Повтор convert с тем же idempotency key возвращает тот же результат.
- Повтор convert с другим ключом для уже конвертированной сделки не создает дубль.
- Все связанные источники получают одинаковые `project_id` и `contract_id`.
- Суммы не возвращаются без соответствующих прав.
- Все ошибки в API и UI бизнес-понятные и переведены через `trans_message(...)`.
- UI показывает Loading/Error/Empty/Preview/Result состояния.
- Есть backend и admin tests из разделов выше.

## Открытые решения перед PHERP-113

- Разрешать ли wizard создавать нового контрагента из CRM-компании или требовать выбор существующего. Рекомендация первой реализации: требовать выбор существующего.
- Делать ли физическое копирование документов в проект/договор. Рекомендация первой реализации: только link references.
- Включать ли создание сметы в PHERP-113. Рекомендация первой реализации: только `budget_seed` и action "Подготовить смету по договору".
- Финальное имя нового permission для КП: `commercial_proposals.convert.project_contract` или reuse `commercial_proposals.update`.
