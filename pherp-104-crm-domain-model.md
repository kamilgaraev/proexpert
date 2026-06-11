# PHERP-104: архитектура CRM-домена

Обновлено: 2026-06-11.

Документ фиксирует целевую архитектуру CRM-домена для ProHelper: компании, контакты, лиды, сделки, активности, документы и связи с существующими строительными сущностями. Спецификация основана на текущем коде `prohelper` и `prohelper_admin` и нужна как вход для последующей реализации backend/API/admin UI.

## Цель

Добавить CRM-контур, который помогает вести входящие обращения, контрагентов, контактных лиц, продажи и переход от продажи к строительному проекту, договору или подрядчику.

CRM не должна подменять существующие операционные домены:

- `organizations` остаются tenant-организациями платформы и участниками холдингов.
- `contractors` остаются контрагентами строительного и договорного контура.
- `projects` остаются строительными проектами.
- `contracts` остаются юридическими/финансовыми договорами с актами, платежами, спецификациями и event sourcing.
- `files` и `FileService` остаются базовым механизмом хранения файлов в S3.

## Текущее состояние

### Backend

- `prohelper/app/Models/Organization.php` и `prohelper/database/migrations/2025_01_01_000010_create_organizations_table.php`: tenant-организация с `name`, `legal_name`, `tax_number`, `registration_number`, `phone`, `email`, `address`, `city`, `country`, `is_active`, soft delete; позже добавлены verification, holding, S3, capabilities и RBAC-поля.
- `prohelper/app/Models/Contractor.php` и `prohelper/database/migrations/2025_01_01_000030_create_contractors_table.php`: строительный контрагент в рамках `organization_id` с `name`, `contact_person`, `phone`, `email`, `legal_address`, `inn`, `kpp`, `bank_details`, `notes`, `contractor_type`, `source_organization_id`, sync-полями и связью `contracts()`.
- `prohelper/app/Models/Project.php`: строительный проект с `organization_id`, `name`, `address`, `customer`, `designer`, `budget_amount`, `site_area_m2`, `status`, датами, `contract_number`, участниками через `project_organization`, ответственными через `project_user`, файлами через polymorphic `files()`, связями `contracts()` и `estimates()`.
- `prohelper/app/Models/Contract.php`: договор с `organization_id`, `project_id`, `contractor_id`, `supplier_id`, `contract_side_type`, `number`, `date`, `subject`, суммами, статусом, авансами, удержаниями, multi-project allocations, актами, платежами, спецификациями и state events.
- `prohelper/app/Models/User.php`: пользователь с `current_organization_id`, membership через `organization_user`, назначениями на проекты через `project_user`, role assignments и `AuthorizationService`.
- `prohelper/app/Models/File.php` и `prohelper/database/migrations/2025_05_03_173322_create_files_table.php`: общий файловый слой с `organization_id`, polymorphic `fileable`, `user_id`, `name`, `original_name`, `path`, `mime_type`, `size`, `disk`, `type`, `category`, `additional_info`.
- `prohelper/routes/api.php`: admin API находится под `/api/v1/admin`, middleware `admin.response`, `auth:api_admin`, `auth.jwt:api_admin`, `organization.context`, `authorize:admin.access`, `interface:admin`.
- `prohelper/routes/api/v1/admin/contractors.php`, `contracts.php`, `projects.php`, `users.php`: текущие операционные endpoints уже используют отдельные route-файлы.

### Admin Frontend

- Отдельного CRM-модуля и route `/crm` в текущем `prohelper_admin/src` не найдено.
- Есть готовые операционные паттерны:
  - `prohelper_admin/src/pages/Contractors/ContractorsPage.tsx`: реестр подрядчиков, карточки/таблица, диалог создания/редактирования, подтверждение удаления, статистика.
  - `prohelper_admin/src/services/contractorService.ts` и `prohelper_admin/src/types/contractor.ts`: typed service с `normalizePaginatedResponse` и `unwrapResponseData`.
  - `prohelper_admin/src/pages/Projects/ProjectsPage.tsx`, `ProjectDetailsPage.tsx`, `ProjectContractsPage.tsx`: реестры, detail views, project-scoped routes.
  - `prohelper_admin/src/services/contractService.ts`, `projectService.ts`, `responseUtils.ts`: нормализация `AdminResponse`, пагинации, `summary`, `meta`, nested `data`.
- Для CRM UI нужно повторять эти паттерны, но не встраивать CRM в contractor/project/contract pages как скрытую вкладку.

## Доменная граница

CRM-сущности должны иметь собственные таблицы и сервисы. Связь с существующими сущностями должна быть явной и nullable:

- CRM company может быть связана с `organizations.id`, если компания уже является tenant/участником платформы.
- CRM company может быть связана с `contractors.id`, если компания стала строительным контрагентом.
- CRM deal может быть связана с `projects.id`, если продажа конвертирована в строительный проект.
- CRM deal может быть связана с `contracts.id`, если сделка дошла до договора.

Нельзя использовать `organizations` как универсальную CRM-компанию: эта таблица несет subscription, holding, S3, RBAC и workspace-смысл. Нельзя использовать `contractors` как универсальные CRM-компании: текущая модель завязана на договоры, подрядчиков, самоподряд и синхронизацию приглашенных организаций.

## Целевая модель данных

Новые CRM-таблицы используют `uuid` primary key. Внешние ключи на существующие таблицы (`organizations`, `users`, `projects`, `contracts`, `contractors`, `files`) остаются bigint, потому что эти таблицы уже так устроены.

### `crm_companies`

Назначение: CRM-компания или физлицо-контрагент в рамках tenant-организации.

Ключевые поля:

- `id uuid primary key`
- `organization_id bigint not null references organizations(id)`
- `owner_user_id bigint null references users(id)`
- `linked_organization_id bigint null references organizations(id)`
- `linked_contractor_id bigint null references contractors(id)`
- `name text not null`
- `legal_name text null`
- `company_type text not null`: `legal_entity`, `individual`, `sole_proprietor`
- `roles jsonb not null default []`: `customer`, `contractor`, `supplier`, `partner`, `lead`, `other`
- `status text not null`: `new`, `active`, `inactive`, `blacklisted`, `archived`
- `inn text null`, `kpp text null`, `ogrn text null`
- `phone text null`, `email text null`, `website text null`
- `legal_address text null`, `actual_address text null`
- `source text null`: `manual`, `landing_form`, `import`, `marketplace`, `referral`, `project`, `contract`
- `tags jsonb not null default []`
- `custom_fields jsonb not null default {}`
- `notes text null`
- `last_activity_at timestamptz null`
- `created_by_user_id bigint null references users(id)`
- `updated_by_user_id bigint null references users(id)`
- `created_at`, `updated_at`, `deleted_at`

Ограничения и индексы:

- `index (organization_id, status)`
- `index (organization_id, owner_user_id)`
- `index (organization_id, linked_contractor_id)`
- `unique (organization_id, inn)` where `inn is not null and deleted_at is null`
- полнотекстовый или trigram index по `name`, `legal_name`, `inn`, `email`, `phone`

### `crm_contacts`

Назначение: контактное лицо компании или самостоятельный контакт.

Ключевые поля:

- `id uuid primary key`
- `organization_id bigint not null references organizations(id)`
- `company_id uuid null references crm_companies(id)`
- `owner_user_id bigint null references users(id)`
- `full_name text not null`
- `position text null`
- `phone text null`
- `email text null`
- `messengers jsonb not null default {}`
- `is_primary boolean not null default false`
- `status text not null`: `active`, `inactive`, `archived`
- `source text null`
- `personal_data_consent_at timestamptz null`
- `notes text null`
- `last_activity_at timestamptz null`
- `created_by_user_id`, `updated_by_user_id`
- timestamps, soft delete

Ограничения:

- FK `company_id` должен принадлежать той же `organization_id`.
- Только один primary-contact на компанию через partial unique index: `(organization_id, company_id)` where `is_primary = true and deleted_at is null`.

### `crm_leads`

Назначение: входящее обращение или потенциальная продажа до квалификации.

Ключевые поля:

- `id uuid primary key`
- `organization_id bigint not null`
- `company_id uuid null`
- `contact_id uuid null`
- `owner_user_id bigint null`
- `title text not null`
- `source text not null`: `manual`, `website`, `phone`, `email`, `referral`, `marketplace`, `import`
- `status text not null`: `new`, `in_progress`, `qualified`, `disqualified`, `converted`
- `priority text not null default 'normal'`
- `estimated_amount numeric(18,2) null`
- `expected_start_date date null`
- `need_description text null`
- `utm jsonb not null default {}`
- `lost_reason text null`
- `converted_deal_id uuid null references crm_deals(id)`
- timestamps, soft delete

### `crm_deals`

Назначение: продажа/возможность с pipeline-стадиями.

Ключевые поля:

- `id uuid primary key`
- `organization_id bigint not null`
- `company_id uuid not null`
- `primary_contact_id uuid null`
- `lead_id uuid null`
- `owner_user_id bigint null`
- `project_id bigint null references projects(id)`
- `contract_id bigint null references contracts(id)`
- `title text not null`
- `pipeline_code text not null default 'default'`
- `stage_code text not null`
- `status text not null`: `open`, `won`, `lost`, `archived`
- `amount numeric(18,2) null`
- `currency text not null default 'RUB'`
- `probability smallint null`
- `expected_close_at date null`
- `won_at timestamptz null`
- `lost_at timestamptz null`
- `lost_reason text null`
- `next_activity_at timestamptz null`
- `created_by_user_id`, `updated_by_user_id`
- timestamps, soft delete

Ограничения:

- `company_id`, `primary_contact_id`, `lead_id` должны быть в той же `organization_id`.
- `project_id` и `contract_id` должны быть доступны текущей tenant-организации через существующие project/contract access rules.
- `probability` в диапазоне 0..100.

### `crm_activities`

Назначение: звонки, встречи, письма, задачи, заметки и история касаний.

Ключевые поля:

- `id uuid primary key`
- `organization_id bigint not null`
- `owner_user_id bigint null`
- `company_id uuid null`
- `contact_id uuid null`
- `lead_id uuid null`
- `deal_id uuid null`
- `type text not null`: `call`, `email`, `meeting`, `task`, `note`, `message`
- `direction text null`: `incoming`, `outgoing`
- `status text not null`: `planned`, `done`, `cancelled`, `overdue`
- `subject text not null`
- `body text null`
- `due_at timestamptz null`
- `completed_at timestamptz null`
- `result text null`
- `created_by_user_id`, `updated_by_user_id`
- timestamps, soft delete

Правило: активность должна быть связана хотя бы с одной CRM-сущностью (`company_id`, `contact_id`, `lead_id`, `deal_id`).

### CRM-документы

Файлы не нужно хранить в отдельном хранилище. CRM-сущности получают `morphMany(File::class, 'fileable')`, а upload идет через `App\Services\Storage\FileService` в S3-пути:

```text
org-{organization_id}/crm/{entity}/{entity_id}/...
```

Для бизнес-классификации использовать `files.type = document` и `files.category`: `brief`, `proposal`, `commercial_offer`, `contract_draft`, `requisites`, `correspondence`, `other`.

### `crm_timeline_events`

Назначение: неизменяемая история важных изменений без хранения технических exception/payload.

Ключевые поля:

- `id uuid primary key`
- `organization_id bigint not null`
- `actor_user_id bigint null`
- `entity_type text not null`
- `entity_id uuid not null`
- `event_type text not null`: `created`, `updated`, `status_changed`, `stage_changed`, `converted`, `linked`, `unlinked`, `activity_completed`, `file_uploaded`
- `summary text not null`
- `metadata jsonb not null default {}`
- `created_at timestamptz not null`

`metadata` не должна содержать токены, raw request body, SQL, stack trace или внутренние exception-тексты.

## Backend-архитектура

Рекомендуемый модуль: `prohelper/app/BusinessModules/Features/Crm`.

Структура:

- `CrmModule.php`, `CrmServiceProvider.php`, `routes.php`
- `Models/CrmCompany.php`, `CrmContact.php`, `CrmLead.php`, `CrmDeal.php`, `CrmActivity.php`, `CrmTimelineEvent.php`
- `Http/Controllers/Admin/*Controller.php`
- `Http/Requests/Admin/*Request.php`
- `Http/Resources/Admin/*Resource.php`
- `Services/CrmCompanyService.php`, `CrmContactService.php`, `CrmLeadService.php`, `CrmDealService.php`, `CrmActivityService.php`, `CrmConversionService.php`, `CrmAccessService.php`
- `DTOs/*Data.php`
- `Enums/*Enum.php`

Контроллеры должны быть тонкими: validation, permission check, вызов service, `AdminResponse`. Бизнес-правила, конвертации и org-scoped lookups должны жить в services.

## API endpoints

Все пути идут от `/api/v1/admin/crm` и наследуют admin middleware. Дополнительные route-level permissions обязательны.

| Метод | Путь | Назначение | Право |
|---|---|---|---|
| `GET` | `/companies` | Реестр компаний | `crm.companies.view` |
| `POST` | `/companies` | Создать компанию | `crm.companies.create` |
| `GET` | `/companies/{company}` | Карточка компании | `crm.companies.view` |
| `PATCH` | `/companies/{company}` | Обновить компанию | `crm.companies.edit` |
| `DELETE` | `/companies/{company}` | Архивировать компанию | `crm.companies.delete` |
| `GET` | `/contacts` | Реестр контактов | `crm.contacts.view` |
| `POST` | `/contacts` | Создать контакт | `crm.contacts.create` |
| `GET` | `/contacts/{contact}` | Карточка контакта | `crm.contacts.view` |
| `PATCH` | `/contacts/{contact}` | Обновить контакт | `crm.contacts.edit` |
| `GET` | `/leads` | Реестр лидов | `crm.leads.view` |
| `POST` | `/leads` | Создать лид | `crm.leads.create` |
| `POST` | `/leads/{lead}/qualify` | Квалифицировать лид | `crm.leads.qualify` |
| `POST` | `/leads/{lead}/convert` | Конвертировать лид в сделку | `crm.leads.convert` |
| `GET` | `/deals` | Реестр/канбан сделок | `crm.deals.view` |
| `POST` | `/deals` | Создать сделку | `crm.deals.create` |
| `PATCH` | `/deals/{deal}` | Обновить сделку | `crm.deals.edit` |
| `POST` | `/deals/{deal}/stage` | Сменить стадию | `crm.deals.edit` |
| `POST` | `/deals/{deal}/win` | Закрыть успешно | `crm.deals.close` |
| `POST` | `/deals/{deal}/lose` | Закрыть без сделки | `crm.deals.close` |
| `POST` | `/deals/{deal}/link-project` | Связать с проектом | `crm.deals.link_operational` |
| `POST` | `/deals/{deal}/link-contract` | Связать с договором | `crm.deals.link_operational` |
| `GET` | `/activities` | Лента/задачи | `crm.activities.view` |
| `POST` | `/activities` | Создать активность | `crm.activities.create` |
| `POST` | `/activities/{activity}/complete` | Завершить активность | `crm.activities.edit` |
| `POST` | `/{entity}/{id}/files` | Загрузить файл к CRM-сущности | `crm.documents.upload` |
| `GET` | `/{entity}/{id}/timeline` | История CRM-сущности | `crm.timeline.view` |
| `GET` | `/summary` | Dashboard summary | `crm.analytics.view` |

### Формат списков

Списки возвращают `AdminResponse` с пагинацией и summary:

```json
{
  "success": true,
  "data": [
    {
      "id": "8c43f4a9-1ef3-45b3-8fc4-3a3da45f70a5",
      "name": "Строй Инвест",
      "status": "active",
      "roles": ["customer"],
      "inn": "1655000000",
      "primary_contact": {
        "id": "62fbf36a-2c18-4ee1-96f5-d4d10aef8a21",
        "full_name": "Иван Петров",
        "phone": "+7 900 000-00-00",
        "email": "i.petrov@example.ru"
      },
      "owner": {
        "id": 17,
        "name": "Анна Соколова"
      },
      "deals_open_count": 2,
      "last_activity_at": "2026-06-10T14:20:00+03:00",
      "created_at": "2026-06-01T10:00:00+03:00"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 1,
    "last_page": 1
  },
  "summary": {
    "total": 1,
    "active": 1,
    "without_owner": 0,
    "without_activity": 0
  }
}
```

Admin frontend должен нормализовать эти ответы через `normalizePaginatedResponse`, как уже делают `contractorService` и `contractService`.

### Формат detail

Detail response должен возвращать связанную рабочую картину, а не только строку таблицы:

```json
{
  "success": true,
  "data": {
    "id": "8c43f4a9-1ef3-45b3-8fc4-3a3da45f70a5",
    "name": "Строй Инвест",
    "legal_name": "ООО Строй Инвест",
    "status": "active",
    "roles": ["customer"],
    "owner": { "id": 17, "name": "Анна Соколова" },
    "contacts": [],
    "open_deals": [],
    "linked_entities": {
      "organization": null,
      "contractor": { "id": 45, "name": "Строй Инвест" },
      "projects_count": 1,
      "contracts_count": 2
    },
    "available_actions": [
      { "action": "edit", "label": "Редактировать", "enabled": true },
      { "action": "create_deal", "label": "Создать сделку", "enabled": true }
    ]
  }
}
```

`available_actions` формирует backend на основе прав и статуса, чтобы frontend не дублировал authorization/business rules.

### Ошибки

Ошибки возвращаются через `AdminResponse::error` с `trans_message(...)`.

Пользовательские сообщения не должны содержать внутренние слова вроде `payload`, `dto`, `exception`, `sql`, `constraint`, `fallback`, `legacy`. Для validation errors использовать понятные поля: "Компания", "Контакт", "Сделка", "Ответственный".

## RBAC

Новые права добавить в `config/RoleDefinitions/*/*.json` только через JSON-определения ролей и перевести в `lang/ru/permissions.php`.

Минимальный набор:

- `crm.access`
- `crm.companies.view`
- `crm.companies.create`
- `crm.companies.edit`
- `crm.companies.delete`
- `crm.contacts.view`
- `crm.contacts.create`
- `crm.contacts.edit`
- `crm.contacts.delete`
- `crm.leads.view`
- `crm.leads.create`
- `crm.leads.edit`
- `crm.leads.qualify`
- `crm.leads.convert`
- `crm.deals.view`
- `crm.deals.create`
- `crm.deals.edit`
- `crm.deals.close`
- `crm.deals.link_operational`
- `crm.activities.view`
- `crm.activities.create`
- `crm.activities.edit`
- `crm.documents.view`
- `crm.documents.upload`
- `crm.documents.delete`
- `crm.timeline.view`
- `crm.analytics.view`

Для `crm.deals.link_operational` дополнительно проверять доступ к целевому проекту/договору через существующие project/contract access services.

## Frontend-архитектура

Рекомендуемый маршрут в admin:

- `/crm` - CRM dashboard.
- `/crm/companies` - реестр компаний.
- `/crm/companies/:id` - карточка компании.
- `/crm/contacts` - реестр контактов.
- `/crm/leads` - лиды.
- `/crm/deals` - pipeline/реестр сделок.
- `/crm/activities` - задачи и коммуникации.

Файлы:

- `prohelper_admin/src/types/crm.ts`
- `prohelper_admin/src/services/crmService.ts`
- `prohelper_admin/src/pages/Crm/*`
- `prohelper_admin/src/pages/Crm/components/*`
- тесты рядом с service/page/components

UI должен повторять текущий admin style:

- `PageContainer`, `PageHeader`, `ActionCard`.
- Summary strip: активные компании, лиды без ответственного, открытые сделки, просроченные активности.
- Реестры с search/filter/sort/page и устойчивыми loading/error/empty states.
- Detail screen с вкладками: обзор, контакты, сделки, активности, документы, история.
- Диалоги для создания/редактирования и подтверждения архивирования.
- Для pipeline сделок допустим канбан, но рядом должен быть табличный режим для плотной работы.

CRM UI не должен показывать технические labels: slug-и статусов, permission keys, enum keys, internal IDs без бизнес-контекста.

## Интеграции и конвертации

### CRM company -> contractor

Кнопка "Создать подрядчика" доступна, если:

- у пользователя есть `crm.companies.edit` и право на создание подрядчика;
- CRM company еще не связана с `linked_contractor_id`;
- роль компании включает `contractor` или пользователь явно подтверждает использование как подрядчика.

Сервис `CrmConversionService` создает `Contractor` с маппингом:

- `Contractor.organization_id` = текущая tenant organization.
- `name` = `crm_companies.name`.
- `contact_person` = primary contact `full_name`.
- `phone`, `email`, `legal_address`, `inn`, `kpp`, `bank_details`, `notes`.
- После создания записывает `crm_companies.linked_contractor_id`.

### CRM deal -> project

Конвертация сделки в проект должна быть отдельным действием. Минимальный маппинг:

- `Project.organization_id` = текущая tenant organization.
- `Project.name` = `crm_deals.title`.
- `Project.customer` или будущий structured customer reference = `crm_companies.name`.
- `Project.customer_representative` = primary contact.
- `Project.budget_amount` = `crm_deals.amount`.
- `Project.additional_info.crm_deal_id` = deal id до появления явного FK.

Целевой вариант: добавить nullable `crm_deal_id`/`crm_company_id` в `projects`, но только после проверки всех project resources и customer portal contracts.

### CRM deal -> contract

CRM не создает договор напрямую без существующих contract-management правил. Разрешены два сценария:

- link existing contract через `contract_id`;
- prefill формы договора в admin UI из CRM deal/company, затем договор создается обычным `contractService`/backend contract flow.

## Нефункциональные требования

- Все выборки scope-ить по `organization_id`.
- Все FK из request проверять организационно, а не raw `exists:table,id`.
- Для списков использовать eager loading и агрегаты, чтобы не получить N+1 по contacts/deals/activities.
- Поиск должен работать по `name`, `legal_name`, `inn`, `phone`, `email`, contact `full_name`.
- Для длинных импортов и дедупликации предусмотреть фоновые задачи, статусы и retry; не делать тяжелую обработку в HTTP request.
- Любые файлы хранить только через S3 и `FileService`.
- История событий не должна раскрывать технические ошибки, SQL, exception и raw payload.
- Русские пользовательские тексты в PHP возвращать через `trans_message(...)`.

## Этапы реализации

### Этап 1: Companies + Contacts

- Миграции, модели, services, requests, resources.
- Admin endpoints `/crm/companies`, `/crm/contacts`.
- Permissions и переводы.
- Admin UI: реестр компаний, карточка компании, контакты, базовые активности.
- Тесты backend feature/unit и frontend service/page tests.

### Этап 2: Leads + Activities

- Лиды, квалификация, conversion lead -> deal.
- Activity list, overdue summary, complete/cancel actions.
- Dashboard summary.

### Этап 3: Deals

- Pipeline/stages, won/lost workflow.
- Deal detail, связка с company/contact/activity.
- Link project/contract.
- Backend-generated `available_actions`.

### Этап 4: Operational Links

- Conversion company -> contractor.
- Prefill project/contract creation.
- Documents/timeline hardening.
- Дедупликация по ИНН/email/phone.

### Этап 5: Import/Analytics

- CSV/XLSX import через queued job.
- Duplicate review workspace.
- CRM analytics: conversion, aging, overdue activities, owner workload.

## Приемочные уточнения PHERP-104

Этот раздел фиксирует архитектурные решения, без которых PHERP-105 может уйти в неполный CRUD. PHERP-104 не создает миграции, контроллеры и admin pages; задача закрывается только как спецификация домена, контрактов, прав и UX-сценариев.

### Source / Channel

Источник должен быть отдельной управляемой сущностью, а не свободной строкой в company, lead или deal.

Рекомендуемая таблица `crm_sources`:

- `id uuid primary key`
- `organization_id bigint null`: `null` для системных источников, значение организации для tenant-specific источников.
- `code text not null`: `manual`, `website_form`, `holding_site`, `import`, `tender_platform`, `partner`, `referral`, `email`, `phone`, `marketplace`, `existing_customer`, `other`.
- `label text not null`: человекочитаемое название для UI.
- `channel_type text not null`: веб-форма, импорт, тендерная площадка, партнер, входящее письмо, звонок, ручной ввод.
- `is_active boolean not null default true`
- `settings jsonb not null default {}`: настройки маппинга формы, UTM, площадки или импорта без секретов.
- `created_by_user_id`, `updated_by_user_id`, timestamps.

CRM-сущности хранят `source_id` и дополнительно `source_ref_type/source_ref_id`, если запись возникла из `ContactForm`, `HoldingSiteLead`, import row или будущего tender source. Исходные значения из файла импорта сохраняются в import row, а в основных карточках используется нормализованный источник.

### Pipeline / Stage

Pipeline и stage должны быть отдельными сущностями, даже если PHERP-105 начнет с системного набора стадий.

Рекомендуемые таблицы:

- `crm_pipelines`: `id`, `organization_id`, `code`, `label`, `entity_type`, `is_default`, `is_active`, timestamps.
- `crm_pipeline_stages`: `id`, `pipeline_id`, `code`, `label`, `category`, `sort_order`, `probability_percent`, `required_fields jsonb`, `required_links jsonb`, `is_terminal`, timestamps.

Базовые stages для deals:

| Stage | Категория | Смысл |
|---|---|---|
| `new` | open | Сделка создана после квалификации лида или вручную. |
| `qualified` | open | Подтверждены company/contact, потребность и ответственный. |
| `tender_or_request` | open | Ведется тендер, формальный запрос или подготовка к участию. |
| `proposal_preparation` | open | Готовится КП и при необходимости presale-смета. |
| `proposal_sent` | open | КП отправлено, ожидается реакция клиента. |
| `negotiation` | open | Идет согласование условий. |
| `contract_preparation` | open | Подготовка к проекту и договору. |
| `won` | won | Сделка выиграна. |
| `lost` | lost | Сделка проиграна. |

Stage transition выполняется только через backend service: он проверяет права, принадлежность tenant, обязательные поля, обязательные связи с КП/presale/tender и пишет событие в историю.

### Lifecycle

Целевой поток:

1. Входящее обращение, импорт или ручной ввод создает `CrmLead` с source/channel и сырыми исходными данными.
2. Нормализация ищет company/contact candidates по ИНН, КПП, ОГРН, email, phone, domain и имени.
3. Менеджер связывает или создает company/contact, назначает owner и переводит lead в `qualified`.
4. Qualified lead конвертируется в `CrmDeal`, а lead получает `status = converted`, `converted_deal_id`, `converted_at`.
5. Deal проходит stages: qualified -> tender/request -> КП/presale -> negotiation -> contract preparation.
6. `lost` требует причину отказа и сохраняет историю.
7. `won` разрешает conversion to project только при правах, заполненных обязательных данных и отсутствии повторной конвертации.
8. Project/contract создаются или связываются в своих ERP-контурах; CRM хранит presale trace и ссылки.

Archive скрывает запись из активных реестров, но не удаляет историю. Merged company/contact открывается read-only и показывает master record.

### Связи с tender, КП, presale, project и contract

PHERP-104 фиксирует только границы и связи, не реализует PHERP-106-110.

- Tender должен ссылаться на `deal_id`, `company_id`, `primary_contact_id`, owner, deadlines, go/no-go и status участия.
- Sales КП должно быть отдельным от закупочных supplier proposals и ссылаться на `deal_id`, optional `tender_id`, optional `presale_estimate_id`, version, approval state и send history.
- Presale estimate должно ссылаться на `deal_id`, optional `tender_id`, optional `commercial_proposal_id`, assumptions, risks, margin и future project budget mapping.
- Project хранит ссылку на source CRM deal/company только после won или подтвержденного раннего старта.
- Contract связан с deal после подготовки договора, но договорные суммы, акты, платежи и юридический документооборот остаются в contract/1С контуре.

### Deduplication / Merge

Дедупликация обязательна для companies и contacts.

Company matching:

- exact: `organization_id + inn + kpp`, ОГРН, подтвержденный 1С reference;
- strong: ИНН без КПП, совпадение domain и близкого legal/display name;
- soft: совпадение phone/email/domain/name с низкой уверенностью.

Contact matching:

- exact: email или normalized phone в рамках организации;
- strong: email/phone + company;
- soft: full name + company/domain.

Merge должен:

- требовать `crm.merge.apply`;
- показывать before/after значения и affected links;
- переносить contacts, leads, deals, activities, files, tenders, КП, presale estimates, projects and contracts links;
- сохранять master record, reason, actor, timestamp и audit trail;
- помечать duplicate как `merged` без физического удаления.

### Import preview / confirm

PHERP-105 должен иметь import flow для CSV/XLSX с предпросмотром и подтверждением.

Минимальные endpoints:

| Метод | Путь | Назначение | Право |
|---|---|---|---|
| `POST` | `/api/v1/admin/crm/imports/preview` | Загрузить файл и получить preview | `crm.import.preview` |
| `GET` | `/api/v1/admin/crm/imports/{batch}` | Получить состояние batch | `crm.import.preview` |
| `GET` | `/api/v1/admin/crm/imports/{batch}/rows` | Получить строки preview с ошибками и кандидатами дублей | `crm.import.preview` |
| `POST` | `/api/v1/admin/crm/imports/{batch}/confirm` | Применить выбранные решения | `crm.import.confirm` |
| `POST` | `/api/v1/admin/crm/imports/{batch}/cancel` | Отменить batch | `crm.import.confirm` |

Preview response через `AdminResponse` должен возвращать batch status, total/accepted/warning/blocked counters, mapping, duplicate candidates, validation errors и row decisions. Confirm для больших файлов может вернуть `status = processing`, `progress_percent` и polling URL.

### API contract completeness

Для каждой основной сущности нужны:

- list с search/filter/sort/pagination/meta/summary;
- detail с linked_entities, permissions, problem_flags, risk_flags и timeline summary;
- create/update/archive/restore;
- validation errors через `AdminResponse::error(..., 422, $errors)`;
- search по name, ИНН, КПП, ОГРН, email, phone, domain;
- безопасная обработка archived/merged states.

Компоненты admin UI не должны читать `response.data.data` напрямую. Нормализация выполняется в service layer и типизируется в `crm.ts`.

### Problem flags and commercial visibility

Рекомендуемые `problem_flags`:

- `missing_owner`
- `missing_company`
- `missing_primary_contact`
- `duplicate_candidate`
- `stale_activity`
- `no_next_action`
- `overdue_activity`
- `proposal_missing`
- `presale_estimate_missing`
- `one_c_reference_conflict`
- `project_conversion_blocked`

Коммерческие суммы показываются только при `crm.amounts.view`. Без права UI показывает masked state и не получает лишние суммы в list payload, если это возможно без ломки контрактов.

### RBAC additions

Минимальный набор прав из основного раздела нужно расширить:

- `crm.view`
- `crm.import.preview`
- `crm.import.confirm`
- `crm.merge.view`
- `crm.merge.apply`
- `crm.amounts.view`
- `crm.project_conversion.create`
- `crm.settings.manage`
- `crm.audit.view`

Все права добавляются в JSON role definitions и переводятся в `lang/ru/permissions.php`. Для PHERP-105 обязателен тест, что API прав возвращает русские названия, а technical keys остаются машинными идентификаторами.

### Admin UX completeness

CRM admin должен включать:

- dashboard: new/qualified leads, open deals, weighted amount, won amount, overdue activities, duplicate candidates, source conversion, pipeline forecast;
- registries: companies, contacts, leads, deals with search/filter/chips/pagination;
- detail cards: overview, contacts, leads, deals, activities, files, projects/contracts, audit;
- leads inbox: raw source data, duplicate candidates, qualification panel, convert wizard, disqualify reason;
- deals workspace: table and pipeline/kanban, stage validation errors, links to tender, КП, presale, project, contract;
- activity timeline: planned/overdue/completed states, business activities and audit events visually separated;
- import flow: upload, mapping, preview, duplicate decisions, confirm, result screen.

UI не должен быть лендингом и не должен показывать slug-и статусов, permission keys, internal IDs и технические ошибки без бизнес-контекста.

### Границы ProHelper / 1С

ProHelper в CRM является операционным source of truth для presale, коммуникаций, pipeline, дедупликации, связей и управленческих коммерческих оценок.

1С остается source of truth для бухгалтерских и налоговых данных: проводки, регистры, НДС, регламентированная отчетность, юридически значимый payroll, бухгалтерское закрытие периода, официальные первичные документы и подтвержденные юридические реквизиты.

CRM не перезаписывает подтвержденные 1С реквизиты автоматически. Конфликт реквизитов фиксируется flag `one_c_reference_conflict` и требует ручной проверки.

### Gaps and next steps

- PHERP-105: реализовать core CRM backend/admin UI, import preview/confirm, dedupe queue, merge, permission translations and tests.
- PHERP-106: спроектировать tender entity, status workflow, go/no-go, deadlines, requirements, competitors, files and risks.
- PHERP-107: реализовать tender UI, deadlines, reminders, documents, filters and links to CRM deal/activity timeline.
- PHERP-108: спроектировать sales КП versions, templates, approval, send history, client response and audit, отдельно от procurement proposals.
- PHERP-109: реализовать lifecycle sales КП: versioning, PDF/export, approval, sending, client marking, attachments and history.
- PHERP-110: спроектировать presale estimate, work sections, resources, materials, subcontract, overheads, margin, risks, versions, assumptions and project budget mapping.

## Критерии готовности PHERP-104

PHERP-104 считается закрытой, если текущий документ используется как вход в PHERP-105 и не требует повторного проектирования:

- зафиксированы Company/Account, Contact, Lead, Deal/Opportunity, Activity/Interaction, Source/Channel and Pipeline stage;
- описаны связи с tender, sales КП, presale estimate, project and contract;
- описан lifecycle lead -> qualified lead -> deal -> tender/КП/presale -> won/lost -> project;
- описаны dedupe, merge and audit trail для companies/contacts;
- описаны API contracts через AdminResponse, включая list/detail/create/update/archive/search/filter/pagination/import preview/confirm/validation errors;
- описаны permissions/RBAC, включая import, merge/dedup, commercial amounts and project conversion;
- описан admin UX dashboard, registries, cards, pipeline/kanban, timeline, import and links;
- явно зафиксированы границы ProHelper/1С;
- зафиксированы gaps PHERP-105-110.

## Проверка реализации

Backend:

- `php -l` по новым PHP-файлам.
- Targeted `phpstan analyse` по CRM module.
- Feature tests для list/detail/create/update/delete/conversion endpoints.
- Unit tests для org-scoped validation, stage transitions, conversion mapping.
- Тест на `PermissionTranslator`/контракт прав, чтобы UI получал русские названия.

Frontend:

- `npx tsc --noEmit` в `prohelper_admin`.
- Vitest для `crmService`, нормализации пагинации, реестров, карточек и dialogs.
- MSW для API-сценариев с loading/error/empty/populated states.
- Browser/gstack smoke при доступном admin URL: открыть CRM route, проверить console/network, основные состояния и адаптивность.

Ограничения проекта сохраняются: не запускать миграции без явной команды пользователя, не запускать `npm run build` для `prohelper_admin`, не запускать dev server без необходимости.

## Открытые решения

- Утвердить термин в UI: "CRM", "Клиенты", "Компании" или "Контрагенты". Рекомендация: раздел назвать "CRM", базовую сущность в UI - "Компании", а "Контрагенты" оставить для текущего contractor domain.
- Решить, нужен ли отдельный customer portal view для CRM-клиентов. В этой спецификации CRM ограничена admin-интерфейсом.
- Решить, добавлять ли прямые FK `crm_company_id`/`crm_deal_id` в `projects` на первом этапе или начать с `additional_info` и перейти к FK отдельной миграцией.
- Утвердить pipeline stages по умолчанию: например `new`, `qualified`, `proposal`, `negotiation`, `contract_review`, `won`, `lost`.
