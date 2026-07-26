# Канонический дизайн отчётности МОСТ

## Метаданные

- Дата: 2026-07-26.
- Статус: APPROVED.
- Решение: единый implementation contract для backend/API и админки МОСТ.
- Каталог: `management-catalog.v1`.
- Версия контракта: `1.0.0`.
- Граница backend: `App\BusinessModules\Core\Reporting`.
- Формат production manifest: `management-catalog.v1.yaml`.
- Official document registry: `official-document-catalog.v1.yaml`.
- Управленческих report codes: 28.
- Регламентированный document code: `official_material_usage_m29`.
- М-29 не входит в 28 управленческих codes.
- Язык пользовательских текстов: русский через `trans_message(...)`.
- Кодировка артефактов: UTF-8.
- Режим верификации: deterministic code, contract, integration и CI проверки.
- Live UI, browser auth и auth-smoke исключены пользователем.
- Dev-server, production credentials и production data не требуются.

## Вердикт

Текущий reporting surface не допускается к точечной доработке как единая основа.

Он содержит независимые формулы, расходящиеся доступы, неодинаковые API и UI состояния.

Он не доказывает происхождение результата, tenant isolation и корректность файла.

Целевой результат - один registry-driven модуль с доменными источниками истины.

Управленческий результат существует только как versioned, immutable и проверяемый snapshot.

Неполный источник выражается `null`, `unknown` или `partial` с coverage.

Неполный источник никогда не маскируется нулём, пустой строкой или синтетическим итогом.

Production не содержит совместимых старых route, calculator, page, normalizer или demo data.

## Доказательства current state

Статический анализ подтвердил обходы view/export/official permissions, конкурирующие формулы,
недостоверную official material form, неработающие endpoint-сценарии и synchronous exports.

UI содержит 15 маршрутизируемых screens с разными guard и model, независимые catalog/routes/cards,
client tables, local demo data «Активности прорабов» и два дублирующих act screens.

Это дефект общей границы ответственности, а не набор локальных багов: он нарушает correctness,
security, provenance и performance, поэтому требует полного cutover варианта C.

## Цели

- Сделать 28 отчётов единым набором управленческих data products.
- Дать каждой метрике одного канонического владельца формулы.
- Сохранить одну идентичность snapshot для экрана, строк, drill-down и экспорта.
- Строить organization/project/resource scope исключительно на сервере.
- Сделать права, sensitive redaction и source-object ABAC обязательными на каждой границе.
- Выполнять длительную materialization и формирование файла только асинхронно.
- Дать SQL cursor pagination, indexed filters и stable sort на production-sized данных.
- Версионировать формулу, schema, renderer, definition и provenance.
- Синхронизировать backend и админку одним manifest hash и schema lock.
- Удалить legacy runtime вместе с конечным release.

## Ограничения

- `Core/Reporting` не пересчитывает финансовые, EVM, складские, кадровые и договорные формулы.
- Формула принадлежит предметному bounded context и вызывается через typed query port.
- Generic SQL, RAG/AI aggregation и client-derived KPI не являются управленческими отчётами.
- HTTP controller не выполняет DB query, transaction, формулу, export или storage operation.
- Public S3 object, синхронный export и хранение download URL запрещены.
- Валюты и несовместимые натуральные единицы не складываются без versioned conversion source.
- Юридическая подпись не выводится из `sealed`; seal означает техническую воспроизводимость snapshot.
- Неготовая definition не получает production route, card, hidden page или API alias.
- Любой внешний caller старого контура блокирует выпуск до своего удаления или миграции.

## ADR: выбор архитектуры

| Вариант | Балл / 100 | Решение |
|---|---:|---|
| A: локально исправлять legacy-модуль | 42.2 | отклонён |
| B: facade с постепенной консолидацией | 77.0 | отклонён как конечная архитектура |
| C: bounded Reporting, canonical ports и atomic cutover | 93.2 | выбран |

Вариант A не устраняет две истины формул, прав и provenance.

Вариант B продлевает период двойной семантики и не является конечным состоянием.

Вариант C объединяет correctness, безопасность, performance, traceability и delivery contract.

До переключения новый контур создаётся и проверяется вне production routing.

После переключения исправления идут вперёд в новом контуре.

Откат возможен только целым release artifact в ограниченное операционное окно.

## Непереговорные инварианты

1. Одна метрика имеет одного domain owner и одну formula version.
2. Один report run связан с одним immutable `snapshot_id` и `source_hash`.
3. Page, cursor export и drill-down читают тот же snapshot, что и summary.
4. `ReportExecutionContext` обязателен для materialize, result, page, cursor и drill-down.
5. Scope snapshot содержит organization и не может расширять актуальный scope actor.
6. SQL чтение всегда фильтрует `organization_id` одновременно со snapshot predicate.
7. Client не задаёт organization, permissions, formula version, owner scope или snapshot ownership.
8. Production registry содержит только готовые definitions и ровно 28 management codes.
9. М-29 находится только в отдельном official registry.
10. Cursor является единственным механизмом выдачи строк.
11. Offset, page, `meta.total` и client sort/filter неполного набора запрещены.
12. Любая sort order завершается unique immutable `row_key`.
13. Exact `row_count` берётся только из sealed metadata готового run.
14. Unknown filter, sort, column, drill type и permission не игнорируются.
15. Ошибка foreign filter ID не раскрывает наличие чужой сущности.
16. Актуальные права проверяются заново на retry, cancel, export, download и subscription delivery.
17. Sensitive value скрывается до serialization rows, totals, drill-down и export.
18. Изменение смысла не происходит in-place: создаются новая version и новый snapshot.
19. Provenance не содержит персональные данные, секреты, raw query или private URL.
20. Денежная точность использует Decimal/minor units, округление только на presentation boundary.
21. Нулевой denominator даёт `null` и warning, не нулевой процент.
22. Удалённый legacy URI возвращает 404.

## Граница bounded-модуля

`Core/Reporting` владеет registry, contract versions, API orchestration и lifecycle runs.

Он владеет server-side filter normalization, idempotency, saved views и subscriptions.

Он владеет typed response envelopes, quality, provenance, drill-down tokens и export policy.

Он владеет private artifact publication, retention, telemetry и audit adapter.

Он вызывает canonical domain providers через `ReportDataProvider`, `ReportRowQuery` и drill-down port.

Он не хранит универсальные JSON rows как production row store.

Он не присваивает предметные sources, event streams или business formula ownership.

Он не использует request-bound authorization внутри job, command или subscription.

## Каталог и publication

`management-catalog.v1.yaml` - единственный источник identity, waves, permissions и readiness.

Backend registry, API schema, admin registry, locks и verification providers используют одни bytes.

`manifest_sha256` сравнивается byte-for-byte всеми consumers release.

Manifest validation выполняется fail-closed.

Duplicate code, unknown permission, отсутствующий provider или schema mismatch блокируют publication.

Каждая definition содержит code, title key, category, grain, filters, columns, sorts и formats.

Каждая definition содержит formula/schema/contract/renderer versions и permission policy.

Каждая definition содержит source, formula, delivery и publication readiness отдельно.

Definition попадает в production, только если все четыре readiness gates подтверждены.

Wave 1: R01, R04, R06, R09-R13, R19-R22.

Wave 2: R02, R03, R05, R07, R15, R16, R23-R25, R27.

Wave 3: R08, R14, R17, R18, R26, R28.

М-29 поставляется отдельно после доказанного seal contract.

## Общие правила метрик

- `monetary_basis`: `contracted`, `accepted_accrual`, `incurred_accrual`, `cash`, `forecast` или `not_monetary`.
- `tax_basis`: `vat_exclusive`, `vat_inclusive`, `not_applicable` или `unknown`.
- `recognition_policy` фиксирует дату, допустимые статусы и reversals.
- `sign_policy` фиксирует направление положительного значения, credit и reversal.
- `currency_source` обязателен; предполагаемая валюта не является источником.
- `unit_dimension` и versioned conversion rule обязательны для натуральной величины.
- `calendar` фиксирует timezone, рабочий календарь и cutoff.
- Detail-to-total сверяется внутри snapshot с tolerance 0 minor units.
- Между каноническими providers допустим 1 minor unit только при явном conversion contract.
- `HALF_UP` применяется только на presentation boundary.
- Деньги округляются до minor unit валюты, индексы - до 4 знаков, проценты - до 2 знаков.
- Golden/property fixtures покрывают positive, negative, reversal и conversion rounding.
- Итоговая доля равна сумме numerator, делённой на сумму denominator.
- Среднее строковых процентов, линейный PV и cash AC для EVM запрещены.
- Source states `partial`, `stale` и `unavailable` сохраняются в result, не заменяются empty.

## Backend application contract

Все новые PHP-файлы используют `declare(strict_types=1);` и PSR-12.

Admin controller принимает FormRequest, actor/context и вызывает один application handler.

Admin controller возвращает `AdminResponse`.

Controller не формирует большой payload вручную и не обращается к DB или FileService.

`ReportDefinitionRegistry` загружает management definitions.

`OfficialDocumentDefinitionRegistry` загружает official definitions.

`ReportDefinitionBindingAssembler` строит immutable code-to-provider map при boot.

Assembler проверяет uniqueness, readiness, filters, columns, sorts, formats и resolvable bindings.

`ReportAccessService` выполняет global, domain, sensitive, audit и source object checks.

`OrganizationReportScopeResolver` строит scope из server actor context.

`ReportFilterNormalizer` типизирует request и формирует canonical query.

`ReportRunCoordinator` владеет transition run и audit transition.

`ReportExportCoordinator` владеет export policy, publication и artifact lifecycle.

`ReportSubscriptionCoordinator` строит deterministic scheduled deliveries только для supported definitions.

`ImmutableAuditReportRecorder` передаёт evidence в Core ImmutableAudit, не создавая вторую audit table.

## Execution и owner ports

`ReportExecutionContext` содержит actor, scope, visibility, authorization context и correlation id.

`ReportScope` содержит organization, holding hierarchy, project/resource allowlists и timezone.

`ReportQuery` содержит definition, scope, filters, comparison, `as_of`, locale и canonical JSON.

`query_hash` строится после normalization и scoped reference resolution.

`ReportWindowSort` - отдельная immutable нормализованная allowlisted sort для одного rows/export window.

`ReportWindowSort` не меняет sealed business filters, scope, snapshot или `query_hash` run.

`ReportSnapshotRef` содержит kind, id, owner scope, versions, source hash, generated/stale timestamps и watermarks.

`ReportResult` содержит metadata, totals, freshness, quality, provenance, row schema и capabilities.

`ReportDataProvider::materialize()` возвращает immutable domain snapshot reference.

`ReportDataProvider::result()` формирует result только на переданном snapshot.

`ReportRowQuery::page()` читает keyset page на snapshot и scope.

`ReportRowQuery::cursor()` читает тот же snapshot chunk-by-chunk для export.

`ReportDrillDownProvider::drillDown()` повторно проверяет scope и source object permissions.

Provider никогда не получает HTTP Request.

Domain owner предоставляет typed snapshot/page/cursor port, а Reporting только адаптирует context.

Legacy array service допускается только как deterministic test oracle.

## Схема, projections и индексы

`report_runs` хранит org, requester, report code, versions, definition hash и canonical query hash.

`report_runs` хранит scope, filters, comparison, `as_of`, snapshot kind/id и source hash.

`report_runs` хранит result metadata, totals, freshness, quality, source refs и sealed row count.

`report_runs` хранит progress, attempts, safe error code и lifecycle timestamps.

Unique constraint: `(organization_id, idempotency_key_hash)`.

Partial active lock: `(organization_id, report_code, active_lock)` при non-null active lock.

Run indexes покрывают `(organization_id, report_code, created_at)`, requester/time, status/update и source/formula.

Ready run требует snapshot reference, source hash и `ready_at` constraint.

`report_exports` хранит run, requested actor, export hash, normalized `ReportWindowSort`, format, columns, locale и timezone.

`report_exports` хранит pinned artifact path, S3 version, ETag, SHA-256, size, row count и expiry.

Export indexes покрывают organization/idempotency, run/format/hash, actor/time, status/update и expiry/status.

`report_saved_views` хранит owner, organization, definition code, contract version и normalized query state.

`report_saved_views` хранит visibility, filters, comparison, sort, columns, status и soft delete.

One default view использует partial unique key по organization, owner и report code.

`report_subscriptions` хранит owner, saved view, schedule, timezone, policy, status и next run.

`report_subscription_deliveries` хранит scheduled time, run/export refs, attempt, status и safe error.

Due scheduler использует `FOR UPDATE SKIP LOCKED` и dispatch after commit.

Domain projections содержат typed columns и indexes для каждого allowed filter/sort.

Все row queries имеют `(organization_id, snapshot_id, ..., row_key)` predicate and index path.

## Data source contracts

Budgeting публикует portfolio, cash-gap, margin, plan-fact, WIP и management P&L projections.

Schedule публикует versioned baseline, status-date, dependency/float и constraint snapshots.

Payments и MultiOrganization публикуют immutable allocation, accepted, paid и due projections.

Warehouse movement amount всегда равен `quantity * price`.

Internal transfer pair не является organization consumption.

Nullable price создаёт amount `null` и quality warning; seal М-29 в таком случае невозможен.

Procurement, Warehouse, Workforce, Quality, Safety, Handover и customer domain публикуют typed,
versioned events/snapshots, перечисленные в их catalog definitions.

## Versions, provenance, quality и audit

Each definition/ready run хранит `contract_version`, `formula_version`, `source_schema_version`,
`renderer_version` и SHA-256 `definition_hash` normalized definition без translated labels.

`source_hash` строится из canonical query, snapshot ids/versions, row/totals hashes и formula version
с рекурсивной сортировкой JSON keys.

`ReportProvenance` содержит management source of truth, source refs, source hash и external confirmation role.

Source ref содержит source, snapshot kind/id, schema version, watermark, row count и hash без PII/secrets.

Quality schema: `status` (`complete|partial|invalid`), `coverage`, `warnings`, `unmatched_count`,
`reconciliation`, `unknown_metrics`, `excluded_sources`.

Coverage содержит `numerator`, `denominator`, `ratio`; zero denominator даёт `ratio=null`.

Warning содержит stable code, severity и affected metric/row count; reconciliation:
`matched|mismatch|not_applicable`.

`ImmutableAuditReportRecorder` делегирует в Core ImmutableAudit allowlist-redacted evidence.

Stable source event id: `reports:{subject_type}:{subject_ulid}:{event_code}:{transition_version}`.

Audit обеспечивает idempotency, hash-chain и redaction organization/actor/status/hash-prefix context.

Raw rows, full filters/search, rates, PII, URLs и exception messages не проходят redaction allowlist.

Coordinator согласованно фиксирует transition и mandatory audit record.

Run ready и official snapshot seal запрещены при unavailable audit writer.

## RBAC, ABAC и organization isolation

Admin routes требуют JWT/admin stack, `module.access:reports` и `reports.view`.

Export дополнительно требует `reports.export`.

Definition добавляет свой view/export permission и при необходимости sensitive/audit permission.

Organization берётся только из `current_organization_id` server attribute.

Client `organization_id` отсутствует либо точно совпадает с server value.

Holding provider получает hierarchy только от canonical resolver.

Parent organization не даёт автоматической видимости дочерних resources.

Каждый foreign filter ID резолвится scoped query в provider.

Foreign и nonexistent ID возвращают одинаковый `REPORT_FILTER_VALUE_NOT_FOUND`.

`AuthorizationService::canInContext()` принимает `AuthorizationDecisionContext` без `request()`.

Queue, CLI и subscription передают channel, correlation id и nullable transport metadata.

Job повторно загружает actor; revoked, blocked или deleted actor получает safe deny.

Download link, retry, cancel, shared view и subscription execution повторно авторизуются.

Permission slugs находятся только в `config/RoleDefinitions`; UI labels находятся в `lang/ru/permissions.php`.

## HTTP API v1

Admin prefix: `/api/v1/admin/reports`.

```text
GET    /catalog
POST   /{reportCode}/runs
GET    /runs/{runId}
GET    /runs/{runId}/rows
POST   /runs/{runId}/drill-down
POST   /runs/{runId}/retry
POST   /runs/{runId}/cancel
POST   /runs/{runId}/exports
GET    /exports/{exportId}
POST   /exports/{exportId}/retry
POST   /exports/{exportId}/cancel
POST   /exports/{exportId}/download-link
GET    /saved-views
POST   /saved-views
GET    /saved-views/{savedViewId}
PATCH  /saved-views/{savedViewId}
DELETE /saved-views/{savedViewId}
POST   /saved-views/{savedViewId}/set-default
GET    /subscriptions
POST   /subscriptions
PATCH  /subscriptions/{subscriptionId}
DELETE /subscriptions/{subscriptionId}
POST   /subscriptions/{subscriptionId}/pause
POST   /subscriptions/{subscriptionId}/resume
POST   /subscriptions/{subscriptionId}/run-now
```

Direct report route, direct download route и per-report compatibility route отсутствуют.

Unknown или non-published report code возвращает 404.

Run creation принимает filters, `as_of`, comparison и optional saved view only.

Run creation не принимает organization, user, permissions, formula version или source hash.

`Idempotency-Key` обязателен для run и export creation и имеет 8-128 ASCII characters.

Same key and same canonical body возвращает existing business object.

Same key and changed canonical body возвращает `REPORT_IDEMPOTENCY_CONFLICT` / 409.

New ready snapshot run возвращает 201 с Location.

New или reused queued/materializing run возвращает 202 с `poll_after_ms` и Retry-After.

Reused ready run возвращает 200.

Failed run требует explicit retry; expired run требует новый idempotency key.

Polling выполняется только через run/export status endpoint.

## Run, rows и drill-down contract

Run statuses: `queued`, `materializing`, `ready`, `failed`, `cancelled`, `expired`.

Export statuses: `queued`, `running`, `uploading`, `ready`, `failed`, `cancelled`, `expired`.

Run `stale` не существует: stale является freshness status готового result.

Freshness statuses: `fresh`, `stale`, `partial`, `unavailable`.

Run retry сохраняет immutable query, scope и formula version.

Run cancel возвращает 200 для queued/already cancelled и 202 для cooperative materializing cancel.

Terminal ready, failed и expired run не отменяются и возвращают 409.

Rows endpoint принимает только `cursor`, `limit`, `sort_by` и `sort_dir`.

Business filters принадлежат sealed canonical run query; их изменение создаёт новый run,
new query hash и new idempotency key.

Rows response содержит `rows`, totals, quality, freshness и meta `limit,next_cursor,has_more,sort`.

Rows response не содержит exact total или page number.

`row_count` публикуется в metadata `GET /runs/{id}` после seal.

Definition переводит client sort field в allowlisted typed SQL expression.

Raw SQL identifier, expression или direction не принимаются.

Cursor подписан, содержит sort values, source hash и run query hash и проверяется на каждом запросе.

Cursor также содержит run id и normalized `ReportWindowSort`; sort change начинает новое cursor window.

Drill-down token содержит run, row/metric, type, source hash и expiry.

Client не передаёт table, model, class, raw URL или trusted route target.

Resource link содержит `resource_type`, `resource_id`, `route_name`, params и availability only.

## Export, queue и S3

Export создаётся только из ready, non-expired и authorized run.

Export использует business filters только из sealed run contract.

Export creation принимает тот же normalized allowlisted `ReportWindowSort`, что и rows endpoint.

`export_hash` включает run id, source hash, run query hash, normalized sort, format, columns, locale и timezone.

Renderer вызывает `ReportRowQuery::cursor()` с export sort, pinned к run/snapshot/query hash.

Export columns проверяются definition allowlist и visibility profile.

Export format без permission отсутствует в UI и отклоняется backend.

Materialization job: `ShouldQueue`, `ShouldBeUnique`, queue `reports-materialization`.

Materialization tries: 3; timeout: 1800 seconds; backoff: 60, 300, 900 seconds.

Export job: `ShouldQueue`, `ShouldBeUnique`, queue `reports-export`.

Export tries: 5; timeout: 3600 seconds; backoff: 60, 300, 900, 1800 seconds.

Both jobs use `WithoutOverlapping`, organization rate limits and dispatch after commit.

Business run/export statuses хранятся отдельно от queue backend state.

Worker читает `ReportRowQuery::cursor()` chunks 2,000-5,000 rows.

Progress пишется не чаще чем раз в 5 seconds и только при росте минимум на 1 percent.

Worker проверяет `cancel_requested_at` между chunks.

CSV лимит: 2,000,000 rows; UTF-8 BOM, RFC 4180 и spreadsheet formula neutralization обязательны.

XLSX лимит: 1,000,000 rows; renderer не удерживает domain models в памяти.

PDF лимит: 5,000 detail rows и отдельный definition page budget.

Artifact max size: 500 MiB.

Max active exports per organization: 2.

Max active materializations per organization: 4.

S3 path: `org-{organization_id}/reports/{report_code}/{run_ulid}/{export_ulid}/{source_hash}.{ext}`.

Только `FileService` создаёт multipart, upload part, complete/abort, immutable publish, head и temporary URL.

Final publish применяет destination `If-None-Match:*` и не заменяет existing object.

Coordinator после publish проверяет path, version, size, checksum и MIME через `head`.

Export получает ready только после успешной проверки pinned object.

Download link создаётся после current authorization, живёт максимум 300 seconds и не хранится в DB.

Link всегда привязан к `artifact_path` и exact `s3_version_id`.

Retention удаляет exact S3 version, а не создаёт delete marker по key.

Ordinary ready artifacts и runs хранятся 30 days.

Failed/cancelled runs хранятся 90 days без raw exception message.

Subscription deliveries хранятся 90 days.

Official artifact activation требует отдельный legal retention policy code.

## Subscription lifecycle

Subscription разрешена только для ready definition с `supports_subscriptions=true`,
reproducible scheduled snapshot и saved view без `needs_migration`.

Initial и единственный channel v1: `in_app`; email и external recipients не поддерживаются.

Subscription хранит saved view, frequency, weekday/day-of-month, local time, timezone,
period policy, format, next run, consecutive failures и versioned query context.

Period policy вычисляется детерминированно в subscription timezone; DST ambiguity/skipped time покрыты tests.

Subscription states: `active`, `paused`, `disabled`, `deleted`.

Transitions: `active↔paused`, `active|paused→disabled`, `disabled→active` после полной reauthorization.

Deletion soft-deletes subscription и запрещает future delivery.

Delivery states: `scheduled`, `building_run`, `building_export`, `ready`, `notified`, `failed`, `expired`.

Retryable delivery возвращается в `scheduled` только в пределах configured attempt budget.

`run-now` создаёт delivery с unique `(subscription_id, scheduled_for)` и не меняет calendar next run.

Permission revoke, deleted actor или scope loss переводит subscription в `disabled` с reason `permission_revoked`.

## Stable error contract

Все ошибки возвращаются только стандартизированной response фабрикой.

Response содержит translated safe message, HTTP status, safe fields, code, correlation id и retryable.

`REPORT_NOT_FOUND` возвращает 404.

`REPORT_SCOPE_FORBIDDEN` возвращает 403.

`REPORT_FILTER_UNSUPPORTED`, `REPORT_FILTER_VALUE_NOT_FOUND`, `REPORT_FILTER_RANGE_INVALID` возвращают 422.

`REPORT_SORT_UNSUPPORTED` и `REPORT_CURSOR_INVALID` возвращают 422.

`REPORT_IDEMPOTENCY_CONFLICT` возвращает 409.

`REPORT_SNAPSHOT_NOT_READY`, `REPORT_EXPORT_NOT_READY` и official unsealed error возвращают 409.

`REPORT_SNAPSHOT_EXPIRED` и `REPORT_EXPORT_EXPIRED` возвращают 410.

`REPORT_EXPORT_LIMIT_EXCEEDED` возвращает 413.

`REPORT_RATE_LIMITED` возвращает 429.

`REPORT_SOURCE_UNAVAILABLE` и `REPORT_DEPENDENCY_FAILED` возвращают retryable 503.

`REPORT_INTERNAL_ERROR` возвращает 500 без exception message, SQL, source row или secret.

Structured log хранит exception class и safe error code, но не раскрывает message пользователю.

## Admin UI target contract

`/reports` является catalog published data products в семи предметных группах.

Группы: портфель, проекты, финансы, закупки/склад, команда, качество/безопасность, подрядчики/заказчики.

Workspace содержит saved views, recent reports, favourites и export center отдельно от групп каталога.

Cards, sidebar, search, categories, routes и tests строятся только из generated production registry.

Typed `ReportDefinition` содержит access, filters, columns, sorts, KPIs, insights, charts, drill-down и export.

Все UI identifier values входят в typed allowlist.

Strict parser отклоняет malformed API response вместо normalizing в synthetic state.

`ReportRoutePage` является единственным владельцем draft filters, selected rows, drawer и transient state.

Applied filters, cursor position, limit, sort, visible columns, group и saved view id находятся в URL.

URL codec versioned, whitelisted и возвращает `valid`, `invalid` или `incompatible`.

Invalid URL показывается как исправимый пользовательский state, без тихого изменения query.

Изменение applied filter сбрасывает cursor window к началу набора.

Back, refresh и deep link детерминированно восстанавливают query state.

React Query хранит server state only.

Смена definition id через React key очищает transient state прежней definition.

`startTransition` разрешён только для presentation и не создаёт вторую query truth.

## UI composition и data presentation

Каждый published route монтирует `ReportAccessBoundary` и `ReportRoutePage`.

Внутри присутствуют header, context bar, filter panel, active chips и state boundary.

KPI, totals, insight и chart dataset приходят сервером для полного applied filter scope.

`ReportDataTable` использует server-controlled cursor contract.

MUI X Data Grid работает с `paginationMode=server`, `sortingMode=server`, `filterMode=server`.

Backend cursor meta заменяет UI page/total model.

`row_count` показывается только из ready-run metadata, когда он доступен.

Drill-down получает row key и pinned snapshot pair, затем загружает server cursor pages lazily.

При смене snapshot UI отменяет requests, удаляет старые cache windows и не показывает прошлые data.

## UI filters, views и export centre

Filters schema-driven и controlled.

Панель содержит явные действия «Применить» и «Сбросить».

Active chips удаляют один filter и обновляют URL.

UI хранит table sort в URL как `ReportWindowSort`, нормализует его against definition allowlist
и передаёт одинаковое значение rows и export creation.

Reference field имеет typed source, scope, dependencies, cursor/search, AbortSignal и own states.

Ошибка reference отключает только зависимое control.

Saved views доступны на `/reports/views` и сохраняют canonical query state, owner и scope.

Saved views используют server cursor pagination.

Create/update/delete/share/default доступны только при пересечении permission, capability и scope.

Incompatible saved view имеет server status `needs_migration`.

Export centre отображает server export job, progress, safe error и download action.

UI poll-ит только authorized run/export status endpoints.

UI предлагает download only для `ready` export и не генерирует Blob из query rows.

Формат без permission или capability отсутствует в menu.

Temporary URL не хранится в client persistence.

## UI access, quality и accessibility

Report states: `initial`, `loading`, `ready`, `empty`, `error`, `partial`.

Access states: `checking`, `allowed`, `denied`, `accessError`.

Denied state не делает report API call.

403 очищает scoped cache и имеет приоритет над cached access decision.

Loading поверх last successful state обозначает background refresh.

Error даёт safe text и Retry, не разрушая последний успех.

Quality banner показывает snapshot timestamp, scope, freshness, coverage, source и limits.

Partial остаётся partial и не отображается empty.

Используются общие PageContainer, PageHeader, ActionCard, StatsCard, DataTable и DrawerShell.

Широкая table имеет controlled horizontal scroll или подтверждённую card projection.

Focus видим; drawer удерживает focus и возвращает его инициатору.

Table contract включает caption, aria-label, named actions, labelled inputs, `aria-live` и keyboard coverage.

### Responsive matrix и keyboard/a11y contract

| Breakpoint | Filters и KPI | Chart и table | Drawer / keyboard | QG-12 IDs |
|---|---|---|---|---|
| `xs` | filters one column; KPI one column | chart full width; table controlled horizontal scroll | drawer full screen; Escape closes and returns focus | `A11Y-XS-01..05` |
| `sm` | filters grouped; KPI two columns when space permits | chart full width; table scroll with stable actions | labelled drawer and visible focus | `A11Y-SM-01..05` |
| `md` | filters grouped; KPI adaptive grid | chart/table side-by-side only when content fits | h1 receives route-change focus | `A11Y-MD-01..05` |
| `lg` | filters multi-group; KPI adaptive grid | chart panels and full server table | focus trap and return to initiator | `A11Y-LG-01..05` |
| `xl` | filters multi-group; KPI maximum readable columns | chart/table retain semantic order | keyboard order remains DOM order | `A11Y-XL-01..05` |

Every chart has an accessible subject name, `role="img"`, aria-label and equivalent semantic table.

Route change focuses h1; Drawer closes on Escape, traps focus while open and returns focus to initiator.

Result count, loading, error, empty state and export progress use `aria-live` announcements.

QG-12 tests long labels, currencies and 0/1/10000+ rows on `xs`, `sm`, `md`, `lg`, `xl`.

The matrix specifies semantic responsive branches, not browser pixel geometry.

## Полный каталог 28/28

| Ref | Stable code | Управленческое решение и grain | Canonical source и core formula/unit | Filters и drill-down | Exact permission contract | Exact readiness / wave | Acceptance |
|---|---|---|---|---|---|---|---|
| R01 | `project_portfolio_health` | Выбрать проекты для вмешательства; project × currency × as_of | Portfolio/Budgeting snapshot; margin=revenue-cost, WIP/FTC/EAC/CTC; money/currency, %, days/count | holding/org/project/manager/ЦФО/status/currency/risk/period → №4/9/10/11, limits, approvals | view `budgeting.portfolio_dashboard.view`; export `budgeting.portfolio_dashboard.export`; source money drill-down ABAC | `partial/ready/not_implemented/draft`; W1 | G01/A01/U01; one-currency totals; stale critical source blocks publication |
| R02 | `holding_performance` | Сравнить вклад организаций; org × project × currency × period × basis | Holding snapshot + contract allocations; contracted, accepted accrual, cash separate; money/currency | holding/org/project/contractor/status/date/currency → allocation/contract/act/transaction | view `multi-organization.reports.kpi`; export `multi-organization.reports.export`; hierarchy scope | `aggregation_required/contract_required/not_implemented/blocked`; W2 | G02/A02/U02; org sums=holding; unknown currency outside total |
| R03 | `intercompany_contract_flows` | Контролировать внутренний/внешний поток; project × allocations × counterparty × currency × period | Holding allocation snapshot; internal+external+unclassified, share, spread; money/currency/tax basis | project/org/counterparty/work type/contract/currency/period → allocations/contracts/acts/transactions | view `multi-organization.reports.financial`; export `multi-organization.reports.export` | `aggregation_required/contract_required/not_implemented/blocked`; W2 | G03/A03/U03; buckets=total; spread is not economic margin |
| R04 | `portfolio_liquidity` | Определить финансирование; day × project/portfolio × currency × scenario | CashGap snapshot; closing=opening+eligible inflows-approved/reserved/overdue outflows; money/currency, days | project/ЦФО/counterparty/document/scenario/currency/horizon → flows/schedules/reserves | view `budgeting.cfo.view`; export `budgeting.cash_gap.export` | `aggregation_required/ready/not_implemented/draft`; W1 | G04/A04/U04; opening[d+1]=closing[d]; no double overdue |
| R05 | `project_evm_control` | Решить о recovery plan; project × baseline × status date × WBS/task × currency | `project_control_core.v1`; BAC/PV/EV/AC, SV/CV/SPI/CPI/EAC; money/currency, indices | WBS/task/contractor/ЦФО/currency/status date → task/act/cost evidence | view `reports.project_control.view`; export `reports.project_control.export`; sensitive `budgeting.wip_forecast.view_sensitive_costs` | `aggregation_required/contract_required/not_implemented/blocked`; W2 | G05/A05/U05; shared metric ref; zero denominator=null |
| R06 | `baseline_schedule_variance` | Выбрать критические задачи; task × baseline version × as_of | Schedule baseline/dependency/critical snapshot; date/duration/float variance; days/%/count | project/schedule/WBS/owner/contractor/critical/status → task/dependency/change/lookahead | view `schedule.view`; export `schedule.reports.export`; task ABAC | `aggregation_required/ready/not_implemented/draft`; W1 | G06/A06/U06; no baseline=null; completed task not overdue |
| R07 | `lookahead_readiness` | Снять ограничения до старта; constraint × task/window × project × as_of | Schedule constraints + versioned policy; readiness=ready/eligible; %, days/count | horizon/project/zone/WBS/type/owner/contractor/severity/status → constraint/task/RFI/procurement | view `schedule.view`; export `schedule.reports.export`; linked-source ABAC | `aggregation_required/policy_required/not_implemented/blocked`; W2 | G07/A07/U07; complete eligible denominator; expired waiver fails |
| R08 | `accepted_production_progress` | Найти непринятый объём; completed_work/act_line × unit × recognition day | Accepted work/act event; accepted-planned, reported-accepted, accepted×approved rate; units separated | project/work/act/contractor/unit/zone/period/status → work/act/quality evidence | view `reports.production_progress.view`; export `reports.production_progress.export`; sensitive `budgeting.wip_forecast.view_sensitive_costs` | `event_required/blocked_by_source/not_implemented/blocked`; W3 | G08/A08/U08; approved act evidence; accepted once; no unit fan-out |
| R09 | `project_margin` | Защитить проектную маржу; project × ЦФО/article × currency × period/as_of | ProjectMargin snapshot; plan/actual/forecast revenue/cost, margin and margin%; money/currency/tax basis | project/ЦФО/article/counterparty/contract/work type/currency/period → budget/act/payment/warehouse/time | view `budgeting.project_margin.view`; export `budgeting.project_margin.export` | `partial/ready/not_implemented/blocked`; W1 | G09/A09/U09; VAT/sign/allocation parity; no hardcoded currency |
| R10 | `budget_plan_fact` | Остановить перерасход; budget version/period × project × ЦФО/article × currency | PlanFact snapshot; available=plan-actual-committed; money/currency | version/scenario/period/project/ЦФО/article/direction/currency/risk/source → line/reservation/document/transaction | view `budgeting.plan_fact.view`; export `budgeting.plan_fact.export` | `ready/ready/not_implemented/draft`; W1 | G10/A10/U10; completed actual; committed excludes actual; closed period immutable |
| R11 | `wip_completion_forecast` | Зафиксировать EAC/резерв; forecast version × provider grain × currency | `project_control_core.v1` + WIP version; WIP/CTC/EAC; money/currency | project/WBS/ЦФО/version/status/owner/adjustment/currency → EV/AC/adjustment/audit | view `budgeting.wip_forecast.view`; export `budgeting.wip_forecast.export`; sensitive `budgeting.wip_forecast.view_sensitive_costs`; audit `budgeting.wip_forecast.view_audit` | `partial/contract_required/not_implemented/blocked`; W1 | G11/A11/U11; one active version; shared EVM codes not redefined |
| R12 | `contract_settlement_exposure` | Управлять обязательствами; allocation × direction × currency × as_of | Contract allocation + Payments snapshot; effective/accepted/cash/unpaid/aging; money/currency | entity/project/allocation/party/direction/instrument/status/due/currency/period → contract/act/document/transaction | view `contracts.management_report.view`; export `contracts.management_report.export`; `customer.finance.view`; payment/act ABAC | `aggregation_required/contract_required/not_implemented/blocked`; W1 | G12/A12/U12; allocation/act/transaction facts counted once |
| R13 | `management_pnl` | Управлять operating result; org × project/ЦФО/article × currency × period × scenario | Margin + PlanFact + Payroll; gross margin and operating result; money/scenario/currency | holding/org/project/ЦФО/article/scenario/currency/period → allocation and №9/10/21/22 facts | view `budgeting.management_pnl.view`; export `budgeting.management_pnl.export` | `aggregation_required/policy_required/not_implemented/blocked`; W1 | G13/A13/U13; direct labor once; allocations=100% |
| R14 | `change_claim_contingency` | Согласовать change/claim; immutable change version × project × allocation × currency | Change workflow + contingency ledger; exposure/aging/roll-forward; money/currency, days | project/contract/initiator/reason/owner/status/approval/currency/cohort/period → impact/claim/budget/schedule | view `change-management.view`; export `change-management.reports.export`; source ABAC | `event_required/contract_required/not_implemented/blocked`; W3 | G14/A14/U14; proposed≠approved; linked claim once |
| R15 | `procurement_cycle` | Эскалировать bottlenecks; request_line process instance | Procurement transitions; stage/total cycle, cohorts, SLA; duration/count/% | org/project/requester/buyer/category/supplier/amount/priority/stage/status/period → request line/audit timeline | view `procurement.dashboard.view`; export `procurement.reports.export`; audit `procurement.audit.view` | `aggregation_required/contract_required/not_implemented/blocked`; W2 | G15/A15/U15; one request line; monotonic timestamps |
| R16 | `supplier_award_competitiveness` | Оценить конкуренцию award; decision × proposal version × currency | Proposal comparison + decision snapshot; participation/premium/variance; money/currency/% | project/category/material/buyer/supplier/decision/method/currency/period/non-lowest → proposal/reason | view `procurement.supplier_proposals.view`; export `procurement.reports.export`; sensitive `procurement.proposal_decisions.view` | `aggregation_required/contract_required/not_implemented/blocked`; W2 | G16/A16/U16; comparable qty/unit/spec/VAT/freight/currency |
| R17 | `supply_reliability` | Эскалировать supplier; PO line × original promise version | PO lifecycle + receipt/return events; OTIF/delay; units and money/currency/tax basis | supplier/project/warehouse/material/buyer/priority/status/promised month/delay → PO/receipt/movement/task | view `procurement.purchase_orders.view`; export `procurement.reports.export`; receipt ABAC | `event_required/contract_required/not_implemented/blocked`; W3 | G17/A17/U17; reversals reset stability; cancellation semantics fixed |
| R18 | `inventory_risk` | Пополнить/перераспределить запас; material × warehouse/project × day | Warehouse balance/movement + demand/lead-time/policy; available/turnover/reorder; units/valuation currency | org/warehouse/project/material/category/ABC/XYZ/status/period → movement/reservation/PO/receipt/demand | view `warehouse.advanced.view`; export `warehouse.reports.export`; sensitive `warehouse.view_custody` | `event_required/contract_required/not_implemented/blocked`; W3 | G18/A18/U18; balance recurrence; zero average=null |
| R19 | `workforce_capacity` | Решить найм/переназначение; staff unit/position × project × day/month | Effective assignment/rate; vacancy/capacity/FTE; FTE/hours/money/currency | department/position/project/employment/rate type/currency/month → staff unit/assignment/employee/schedule | view `workforce.view`; export `workforce.reports.export`; source ABAC | `aggregation_required/ready/not_implemented/draft`; W1 | G19/A19/U19; overlapping assignments not doubled; currencies separate |
| R20 | `attendance_execution` | Контролировать смены; employee × project/site × workday/shift | Attendance close + schedule/absence; eligible/present/overtime/absence; hours/% | employee/project/site/day/shift/status/absence → correction/absence/schedule | view `workforce.view`; export `workforce.reports.export`; sensitive `workforce.audit.view` | `aggregation_required/ready/not_implemented/draft`; W1 | G20/A20/U20; approved absence not violation; zero denominator=null |
| R21 | `project_labor_cost` | Сравнить труд/стоимость; approved entry × employee × project/task × day | Time tracking + effective rate; hours×rate-at-date and variance; hours/money | project/employee/contractor/task/work type/billable/status/period → entry/task/accepted work | view `time_tracking.view`; export `time_tracking.reports.export`; sensitive `time_tracking.cost.view` | `ready/ready/not_implemented/draft`; W1 | G21/A21/U21; approved only; no overlap; cost absent without right |
| R22 | `payroll_readiness` | Решить payroll close; period × employee × project × source row/issue | Payroll calculation version; coverage/issues/blockers; hours/money | period/project/employee/issue/severity/status/source → source work/time/assignment/rate/audit | view `workforce.view`; export `workforce.reports.export`; audit `workforce.audit.view` | `ready/ready/not_implemented/draft`; W1 | G22/A22/U22; blockers forbid ready; approved period immutable |
| R23 | `quality_defect_flow` | Выбрать corrective zones; defect × transition × project/contractor/task | Defect transitions + backlog; opening+created-closed+reopened; count/days/% | project/contractor/task/severity/status/cohort/period → defect/photo/history/work/act | view `quality-control.defects.view`; export `quality-control.reports.export`; evidence ABAC | `aggregation_required/policy_required/not_implemented/blocked`; W2 | G23/A23/U23; mature cohort; reopen not inferred from current status |
| R24 | `safety_incident_actions` | Определить sites for action; incident/violation/action × project/site × day | Safety transitions + exposure; backlog/due/frequency; count/days/% | project/site/contractor/category/severity/status/owner/period → investigation/action evidence | view `safety-management.view`; export `safety-management.reports.export`; evidence redaction | `aggregation_required/policy_required/not_implemented/blocked`; W2 | G24/A24/U24; incomplete exposure=unknown; closure evidence required |
| R25 | `workforce_admission` | Допустить/отстранить worker; assignment/person × site × requirement × day | Safety requirement snapshot; admission/compliance/blockers; count/% | project/site/person/requirement/status/expiry → training/medical/PPE/waiver | view `safety-management.view`; export `safety-management.reports.export`; sensitive `safety-management.medical.view` | `aggregation_required/ready/not_implemented/draft`; W2 | G25/A25/U25; expired/missing blocks; one person once |
| R26 | `handover_readiness` | Решить readiness handover package; gate version × location/package × project | Checklist/gate + evidence events; complete mandatory/required; count/%/days | project/location/WBS/package/contractor/gate/owner/due → RFI/change/constraint/defect/document | view `reports.project_readiness.view`; export `reports.project_readiness.export`; source ABAC | `event_required/contract_required/not_implemented/blocked`; W3 | G26/A26/U26; hard blocker forbids ready; attempt/result separate |
| R27 | `contractor_scorecard` | Выбрать/продлить contractor; profile × category × cohort/project | Review snapshot + №6/17/23/24; component means and coverage; units by source | contractor/category/project/cohort/component → review/offer/objective evidence | view `contractor_marketplace.profile.view`; export `contractor_marketplace.reports.export`; reviewer PII redacted | `aggregation_required/policy_required/not_implemented/blocked`; W2 | G27/A27/U27; 0-5 bounds; no composite/ranking |
| R28 | `customer_sla` | Эскалировать communication; issue/request × event/comment × project/customer org | Customer events + SLA calendar; response/resolution/aging; duration/count/% | project/customer/type/priority/owner/status/cohort/period → timeline/comments/documents | view `customer.sla_report.view`; export `customer.sla_report.export`; issue/request ABAC | `event_required/contract_required/not_implemented/blocked`; W3 | G28/A28/U28; responder side; business-hours/DST |

## Official document: М-29

`official_material_usage_m29` не является management report code.

Его provider может seal snapshot только с opening balance и closing balance периода.

Snapshot включает receipts, actual consumption и approved normative consumption.

Normative consumption равен versioned norm, умноженной на accepted physical volume.

Snapshot включает versioned coefficients, основания и source refs движения, нормы, объёма и approval.

Economy/overrun равен normative minus actual.

Unclear warehouse price, currency, direction или missing source делает seal невозможным.

## Observability и performance

Structured events: `reports.run.*`, `reports.export.*`, `reports.subscription.*`.

Log context: correlation, organization, actor, code, run/export id, versions, hash prefix, status and duration.

Logs не содержат full filters, source rows, PII filename, S3 URL, SQL или exception message.

Metrics: run/export totals, duration, queue latency, page duration, rows, bytes, retries and freshness age.

Metrics: quality coverage, stuck jobs and subscription delivery outcomes.

Alert: stuck queued/materializing/running/uploading beyond timeout plus grace.

Alert: export failure rate exceeds 5 percent for 15 minutes with sufficient sample.

Alert: p95 queue latency breaches the release SLO.

Alert: a financial ready definition has stale or unavailable mandatory source.

Alert: S3 path/version/hash/size/MIME verification mismatch occurs.

Alert: subscription reaches three consecutive delivery failures.

Alert: ImmutableAudit persistence fails; run ready and official seal remain blocked.

Runbook owner acknowledges each alert, records correlation ids and either restores the canonical source or disables publication.

Interactive cursor limit: maximum 100.

Large fixture: minimum 10,000 source rows with cursor page 100.

Nightly p95 cursor page 100: maximum 750 ms.

Performance policy: cursor <=100, queries <=8 (composite <=12), N+1 slope <=1,
export fixture 50,000/chunk 500, reads <=4/chunk, peak growth <=128 MiB,
nightly export <=10 min; change требует новой policy version.

Performance runs use synthetic datasets and a fixed CI profile.

Query plan evidence uses `EXPLAIN (FORMAT JSON)` without `ANALYZE` and is attached to release SHA.

## Legacy cutover и deletion

До cutover все существующие reporting и time-tracking report actions получают granular view/export/cost checks.

Broken material scenarios отключаются до появления canonical provider.

Новые legacy report types, template types и direct exporters не создаются.

Foundation и all waves реализуются в isolated branch, CI и staging.

Production shadow execution старой формулы не выполняется.

Atomic release переключает backend, admin registry, routes, UI, consumer locks и schedules одновременно.

В release удаляются старые report routes, controllers, services и report actions.

В release удаляются time-tracking report/export URIs и их direct callers.

Legacy artifact не считается verified source; он quarantine-only до отдельной cleanup migration.

Deployed target не содержит кода, читающего legacy report runtime.

`LegacyReportsRuntimeRemovalTest` сканирует backend, routes, config, tests и consumers.

Test запрещает legacy imports, static calls, constructor types, container resolution, routes и aliases.

QG-14 проходит с `expected_matches=0` и пустым allowlist.

После ограниченного rollback window выполняется финальная irreversible cleanup phase.

Phase сначала фиксирует integrity и retention evidence для legacy artifacts и tables.

Phase затем физически удаляет legacy report files/templates tables, quarantined artifacts и migration-only readers.

После cleanup повторяются QG-14, route snapshot и repository-wide runtime scan.

Cleanup transition записывается в ImmutableAudit с retention/integrity evidence.

Cutover и DoD не завершены, пока cleanup phase не доказана.

## Quality gates: 14

1. **QG-01 Registry completeness.** Ровно 28 одинаковых codes без orphan/phantom definition; М-29 отдельно.
2. **QG-02 Golden formulas.** 56 happy/boundary fixtures, по два на каждый report.
3. **QG-03 Property invariants.** Не менее 500 deterministic seeds на family formulas.
4. **QG-04 Snapshot/provenance.** Immutable identity и equality detail/summary/export.
5. **QG-05 Tenant/RBAC/ABAC.** 28 action matrices, redaction и revoked download.
6. **QG-06 API schema/errors/routes.** Valid contract, 46 malformed page fixtures, stable translation.
7. **QG-07 SQL pagination/performance.** Cursor, sort, query budget, N+1, PostgreSQL plan и large fixtures.
8. **QG-08 Export workflow.** Idempotency, retry/cancel/uploading, version pin, retention и semantic equality.
9. **QG-09 Backend static quality.** Syntax, formatting и scoped static analysis without suppression.
10. **QG-10 Admin registry/contract.** 28 typed definitions, strict parser и MSW lock.
11. **QG-11 Admin state matrix.** Minimum 252 parametrized report-state cases плюс export states.
12. **QG-12 A11y/responsive-code.** Roles, names, keyboard, focus, live regions и breakpoint branches.
13. **QG-13 Admin static quality.** Typecheck, scoped lint и exact formatter.
14. **QG-14 Cutover absence.** Backend token/AST и admin TypeScript scan возвращают zero forbidden matches.

Каждый gate записывает command, count, schema hash и commit SHA в `traceability.json`.

Missing или skipped gate считается failed.

Backend required checks блокируют QG-01-QG-09 и backend часть QG-14.

Admin required checks блокируют QG-10-QG-13 и admin часть QG-14.

Performance evidence относится к release SHA и не старше 24 hours.

## Rollout

1. Зафиксировать base SHA, isolated branches/worktrees и multi-repo release ledger.
2. Включить required CI checks, schema locks, formatter prerequisite и branch protection.
3. Реализовать foundation: manifest, registry, persistence, access, queue, private S3 и ReportShell.
4. Подтвердить source conformance для currency, period, allocation, EVM, margin, P&L и events.
5. Реализовать Wave 1 и все typed owner snapshot/page/cursor ports.
6. Реализовать Wave 2 process/cohort marts и reconciliation.
7. Реализовать Wave 3 events и М-29 seal inputs.
8. Для каждой wave пройти source/formula ready, delivery verified, RBAC, export parity и frozen-clock freshness.
9. Зафиксировать backend test IDs до подключения corresponding admin registry definition.
10. Перед merge сравнить codes, permissions, hashes, routes, parsers, fixtures и test IDs.
11. Выпустить backend и admin в согласованной последовательности без несовместимого окна.
12. Переключить catalog/routes/UI на generated run-based v1 contract.
13. Удалить legacy runtime в том же release artifact.
14. Выполнить QG-14 и зафиксировать zero matches в traceability.
15. После rollback window выполнить irreversible cleanup, audit evidence и повторный QG-14.

## Definition of Done

1. Manifest и traceability содержат 28 codes, owners, versions, permissions, routes, renderers и test IDs.
2. Production registry содержит 28 готовых management definitions; М-29 отделён.
3. Каждая ready definition имеет source/formula ready, delivery verified и publication published.
4. Каждый Wave 1 code имеет owner materialize/page/cursor port и typed indexed storage.
5. Все UI consumers используют cursor contract, strict parser и manifest/schema hash lock.
6. Все current authorization checks покрывают view, export, official, sensitive, audit, drill-down и download.
7. Organization scope не зависит от client data и присутствует во всех snapshot SQL predicates.
8. Screen, cursor rows, drill-down и export имеют одинаковые snapshot/source/formula identity.
9. Unknown, null denominator, partial coverage и currency separation отражаются без выдуманного значения.
10. Exports queue-only, idempotent, private, version-pinned, bounded и reauthorized for download.
11. ImmutableAudit принимает idempotent redacted evidence без второй reporting audit table.
12. Warehouse доказывает `quantity * price`, movement semantics и valuation currency.
13. М-29 имеет sealed opening/period/closing snapshot с complete source refs.
14. Пройдены 56 golden fixtures и property suites с 500 seeds на formula family.
15. Пройдены 28 authorization matrices, 46 malformed page cases и минимум 252 admin state cases.
16. Query budgets выполнены каждым published report, N+1 slope находится в лимите.
17. Все 14 quality gates green, none skipped, performance evidence fresh.
18. Legacy runtime, routes, aliases, direct callers, demo data и duplicate UI отсутствуют.
19. Legacy report files/templates tables и quarantined artifacts физически удалены после rollback window.
20. Live UI, browser auth и auth-smoke не требуются для выполнения DoD данного решения.

## Риски и контролируемые решения

Риск: неверный canonical source закрепит ошибку во всех consumers.

Контроль: owner assignment, golden parity, source readiness и review formula version.

Риск: Wave 2/3 source events будут неполными к сроку release.

Контроль: definition не публикуется до source/formula/delivery/publication evidence.

Риск: large export исчерпает память, S3 time или queue capacity.

Контроль: cursor chunking, rate limits, timeout, retry, progress, budgets и pinned multipart publication.

Риск: permission revoke произойдёт между run и download.

Контроль: transport-neutral current reauthorization при каждой чувствительной операции.

Риск: stale или partial data будут приняты за complete result.

Контроль: explicit freshness/quality contract, blocking policy for formal reports и UI banner.

Риск: legacy caller останется незамеченным.

Контроль: repository-wide token/AST scan, route snapshot и zero-match QG-14.

Риск: backend/UI schema расходятся при coordinated delivery.

Контроль: manifest bytes, schema hash lock, generated registry и joint release gate.

## Автономное утверждение

Пользователь заранее разрешил автономное принятие решений в рамках отчётности МОСТ.

Этот документ не требует дополнительного согласования для выбора варианта C.

Любая реализация обязана соблюдать все инварианты, quality gates и DoD данного документа.

Изменение catalog identity, formula semantics, budgets, permission contract или release gate требует нового ADR.

## Resolution table: review round 1/5

| Finding | Исправление | Обновлённые строки | Статус |
|---|---|---|---|
| C-01 | Полная таблица R01-R28 теперь содержит управленческое решение, grain, source, formula/unit, filters/drill-down, exact permission slugs, four-axis readiness и G/A/U acceptance; registry machine-check сравнивает permission/readiness tokens с manifest. | 711-742; 139-166; 844 | addressed |
| I-01 | Из backend/API contract удалены customer controllers, `CustomerResponse`, customer prefix и customer catalog publication; `customer_sla` остаётся domain definition только admin registry. | 185-216; 336-365; 711-742 | addressed |
| I-02 | Rows принимает только cursor, limit и allowlisted sort; filters sealed в run, а `ReportWindowSort` одинаково нормализуется для rows/export и pinned к run, snapshot и query hash. | 217-246; 422-470; 635-670 | addressed |
| I-03 | Добавлен exact versions/provenance/quality/audit contract, hash recipe, source event id, hash chain, allowlist redaction и fail-closed ready/seal. | 304-335 | addressed |
| I-04 | Добавлен доказательный current-state verdict с permission bypass, contradictory formulas, official data, endpoint/export и UI evidence. | 40-50 | addressed |
| I-05 | Добавлены xs/sm/md/lg/xl responsive matrix, chart semantic equivalent, focus h1/Escape/return, live announcements и QG-12 IDs. | 665-710; 852-855 | addressed |
| I-06 | Добавлена final irreversible cleanup phase после rollback window, integrity/retention/audit evidence, physical legacy table/artifact removal и повтор QG-14; DoD зависит от неё. | 802-841; 869-908 | addressed |
| I-07 | Добавлены all required alerts, runbook ownership, synthetic fixed CI profile и safe `EXPLAIN (FORMAT JSON)` release evidence. | 760-801 | addressed |
| I-08 | Добавлен `HALF_UP`, money/index/percent precision и rounding coverage для reversal/conversion. | 167-184 | addressed |
| I-09 | Определены supported subscription capability, v1 `in_app`, deterministic timezone/DST policy, subscription/delivery states, retry/idempotency и permission-revoked disable. | 525-550 | addressed |

## Resolution table: re-review round 2/5

| Finding | Исправление | Обновлённые строки | Статус |
|---|---|---|---|
| I-02 | Введён `ReportWindowSort`: filters остаются sealed в run, rows/export принимают единый normalized allowlisted sort; cursor и export hash включают run id, source hash, query hash и sort; renderer получает этот sort, UI передаёт его в оба действия. | 217-246; 422-470; 635-670; 963-974 | addressed |
