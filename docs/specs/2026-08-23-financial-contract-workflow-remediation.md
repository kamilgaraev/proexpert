# Исправление финансового контура МОСТ

Дата: 2026-08-23  
Статус: реализация, собственная проверка и единственное независимое ревью завершены; ожидается поставка  
Ветка backend: `feat/financial-workflow-remediation-20260823`

## Цель и границы

Единый production-ready workflow: договор → утверждённая версия сметы → согласованные изменения → акт → счёт → платёж/возврат → каноническая задолженность.

Документ является единственным источником истины по FIN-001…FIN-024. Пункт получает статус «Исправлено» только после красного регрессионного теста, реализации, зелёной проверки и фиксации конкретного evidence.

## Обязательные доменные инварианты

1. Каждое чтение и изменение финансового документа ограничено organization, project, contract и стороной договора на сервере.
2. Утверждённая или использованная версия сметы неизменяема; новая редакция создаёт полный независимый snapshot.
3. Договор, допработа, акт и счёт закрепляют точную версию/snapshot и не пересчитывают историю по текущим данным.
4. Суммы, НДС и остатки рассчитываются сервером безопасной decimal-моделью.
5. Один объём нельзя дважды актировать, одну сумму нельзя дважды выставить или зачесть повторным платёжным событием.
6. Отмена, аннулирование и возврат создают прослеживаемую обратную операцию и не переписывают финансовую историю.
7. Повтор запроса после таймаута идемпотентен, а конкурентные переходы защищены блокировкой/условным обновлением и DB constraint.
8. Задолженность вычисляется одной формулой из канонического ledger и одинаково отображается во всех интерфейсах.

## Реестр FIN

| FIN-ID | Severity | Класс | Воспроизведение / нарушенный инвариант | Решение | Статус | Evidence / остаточный риск |
|---|---|---|---|---|---|---|
| FIN-001 | Critical | Confirmed defect | Пользователь только с `agreements.view` мог изменить/удалить допсоглашение. | Раздельные route authorization permissions и server-side scope. | Исправлено | `AgreementRouteAuthorizationTest` GREEN 2/17; `routes/api/v1/admin/project-based.php`, `AgreementController`. Остаточный риск: нет. |
| FIN-002 | High | Confirmed defect | Применённое допсоглашение изменялось/удалялось неатомарно, а отрицательное изменение могло опустить сумму ниже принятых обязательств. | `FOR UPDATE`, запрет destructive mutation, идемпотентное применение, terminal-state guard и commitment floor по актам/счетам/net payments. | Исправлено | Targeted GREEN: повторное применение, terminal contract и negative agreement below invoice commitment; `SupplementaryAgreementService`, migration `205000`. |
| FIN-003 | Critical | Confirmed defect | Утверждённая смета изменялась обычными endpoints на месте. | DB mutation guards и отдельный `EstimateRevisionService` для новой draft-редакции. | Исправлено | `EstimateVersioningWorkflowTest` GREEN 19/133; migration `200000`. |
| FIN-004 | Critical | Confirmed defect | Акт читал цену/НДС из текущей строки сметы и не закреплял историческое основание. | `estimate_version_id`, immutable `financial_basis`, server totals и честный legacy backfill без ложной версии. | Исправлено | `PerformanceActFinancialBasisTest` GREEN 2/20; `ActReportsPreviewTest` GREEN 28/125; migrations `203000`, `LegacyPerformanceActBasisBackfillService`. |
| FIN-005 | High | Confirmed defect | Snapshot терял ресурсы/региональные параметры/метаданные/связи с договором, restore переиспользовал identity строк. | Snapshot schema v2, полный header context и deep clone section/item/resource/contract links с новыми identity и scope validation. | Исправлено | `EstimateSnapshotBuilderTest` GREEN 1/9; restore GREEN 1/40; `EstimateSnapshotBuilder`, `EstimateVersionRestoreService`. |
| FIN-006 | High | Confirmed defect | Параллельные approve могли создать несколько current/approved версий. | Row lock, status CAS, partial unique DB indexes и атомарное переключение current. | Исправлено | Конкурентные сценарии входят в `EstimateVersioningWorkflowTest` 19/133; `EstimateStatusWorkflowService`. |
| FIN-007 | Medium | Confirmed defect | Compare не показывал НДС, ресурсы и часть totals. | Stable-key recursive diff всех финансовых полей и totals. | Исправлено | `EstimateVersionComparisonServiceTest`; общий unit-прогон 28/168. |
| FIN-008 | Medium | Confirmed defect | Admin скрывал просмотр версий за правом создания. | Отдельные guards view/create/compare/restore/approve и явные статусы/current. | Исправлено | Admin `tsc --noEmit`, Vitest 25/25, ESLint 0 errors, Prettier pass; `EstimateVersionsPage`, `App`. |
| FIN-009 | Medium | Confirmed defect | Список версий отдавал все тяжёлые snapshots без пагинации. | Metadata paginator без snapshot; snapshot только detail; TablePagination в admin. | Исправлено | Backend 19/133 и admin service Vitest GREEN. |
| FIN-010 | Medium | Confirmed defect | Retry snapshot/create после timeout порождал новую версию. | Обязательный idempotency key, DB unique и повторное использование ключа UI до успеха. | Исправлено | Backend 19/133, admin Vitest; `EstimateVersioningService`, `estimateVersionService`. |
| FIN-011 | Critical | Confirmed defect | Generic invoice доверял клиентским суммам/НДС и draft act. | Канонизация только из approved immutable act snapshot, проверка tenant/project и server amount/VAT. | Исправлено | `PaymentDocumentEstimateLifecycleTest` GREEN 12/46; `PaymentDocumentService::canonicalizeActInvoiceData`. |
| FIN-012 | High | Confirmed defect | Один акт мог быть выставлен повторно или сверх остатка. | Partial allocation, `origin_key`, idempotency key, remaining-to-invoice под lock и DB unique. | Исправлено | Invoice partial/retry/concurrency сценарии в `PaymentDocumentEstimateLifecycleTest`; admin timeout-retry test GREEN. |
| FIN-013 | Critical | Confirmed defect | Повтор/конкурентное внешнее платёжное событие зачитывалось дважды, если менялся request idempotency key. | Отдельная identity `(organization_id, bank_transaction_id)`, полный fingerprint, advisory/row locks, partial unique index и атомарная запись transaction+document. | Исправлено | Bank-event retry/fingerprint GREEN 2/4; migration `201000` содержит preflight reconciliation gate; production duplicate count пока недоступен из-за SSH banner timeout. |
| FIN-014 | High | Confirmed defect | Частичный возврат ошибочно завершал весь original payment и не восстанавливал item/budget projections. | Отдельный reversal ledger, доступный остаток возврата, повторяемый idempotency key, пересчёт estimate-item projection и повторное открытие budget reservation. | Исправлено | Refund projection GREEN; budget reservation refund GREEN 1/8; admin retry Vitest GREEN; `PaymentTransactionService`, `PaymentBudgetLimitService`, `RefundPaymentDialog`. |
| FIN-015 | High | Confirmed defect | Акт допускал draft → approved. | Единый draft → submitted → approved/rejected state machine под lock. | Исправлено | `ActReportsPreviewTest` 28/125; `ActReportWorkflowService`. |
| FIN-016 | High | Confirmed architectural inconsistency | Ручная строка акта не имела утверждённого основания. | Каждая строка получает version item snapshot либо approved variation/manual immutable basis; лимит резервируется атомарно. | Исправлено | `PerformanceActFinancialBasisTest` 2/20 и act preview suite; `ManualActLineBasisService`, `ActingQuantityReservationService`. |
| FIN-017 | High | Confirmed defect | Акт создавался после закрытия/расторжения договора. | Terminal contract guard во всех create/submit/approve путях акта. | Исправлено | Terminal-state regressions в `ActReportsPreviewTest`; `ActReportWorkflowService`, `ActingActWizardService`. |
| FIN-018 | High | Confirmed defect | Лимит договора учитывал неэффективные документы и допускал превышение. | Effective ledger only, BigDecimal contract/budget limits и запрет final overrun. | Исправлено | `PaymentContractLimitValidationTest` 1/2, budget lifecycle 5/16, decimal unit tests 7/31. |
| FIN-019 | High | Confirmed defect | Customer finance выбирал `amount`, но суммировал отсутствующий `paid_amount`, а decimal значения сериализовались в float. | Каноническая customer finance projection с полным ledger payload и decimal-строками до границы отображения. | Исправлено | `CustomerContractsVisibilityTest` targeted GREEN 1/9; customer Vitest 3/3 и `tsc`; `CustomerPortalService`, customer typed contracts. |
| FIN-020 | High | Confirmed architectural inconsistency | Dashboard, договор и отчёты считали задолженность разными формулами и разными numeric-контрактами. | Общий `FinancialBalanceQuery`/DTO: invoiced − paid + refunded; cancelled исключаются; API отдаёт decimal-строки. | Исправлено | Dashboard/customer/holding projections используют один DTO; act summary и customer finance regressions GREEN; admin/customer `tsc` GREEN. |
| FIN-021 | Medium | Confirmed defect | Payment list объявлял pagination поверх `limit()->get()`. | Реальный paginator и полные meta/links. | Исправлено | `PaymentDocumentPaginationTest` GREEN 1/4; admin registry tests GREEN. |
| FIN-022 | Medium | Confirmed defect | `advance_changes` ссылался на удалённую таблицу `invoices`. | Tenant-scoped ссылка на `payment_documents` и атомарная advance adjustment history. | Исправлено | `SupplementaryAgreementIdempotencyTest` 6/52; requests/service/migration `205000`. |
| FIN-023 | High | Coverage gap | Не было безопасного annul акта после выставления счёта, включая legacy morph invoice и release budget reservation. | Annulment создаёт immutable reversal, блокирует оплаченный legacy/current invoice, канонически отменяет неоплаченные allocations и освобождает бюджет. | Исправлено | `PerformanceActFinancialBasisTest` targeted GREEN 1/19; admin `actWorkflowService` Vitest GREEN; migration `204000`. |
| FIN-024 | Medium | Needs production evidence | Фактические production-объёмы/SLO, существующие bank-event duplicates и планы тяжёлых запросов статически не доказаны. | Пагинация/chunked backfill и fail-fast migration preflight реализованы; метрики проверяются read-only до/после deploy. | Needs production evidence | Read-only SSH 2026-08-24: `Connection timed out during banner exchange`; повтор обязателен перед merge/deploy и post-deploy. |

## Порядок реализации по зависимостям

1. Схема и совместимость: immutable version references, stable snapshot schema, source allocations, idempotency identities, reversal ledger.
2. Версионирование смет: deep clone, mutation guard, transitions/current, compare, pagination, retries.
3. Договоры и допработы: права, immutable applied records, terminal-state guards, approved basis.
4. Акты: pinned version, quantity allocations, state machine, annul/correction.
5. Счета/платежи/возвраты: canonical amounts, partial allocation, idempotency, reversals.
6. Задолженность: единая projection/query и одинаковые API outputs.
7. Admin/customer/mobile contracts и UX состояния.
8. Миграции/backfill, документация workflow, независимое ревью, PR/CI/deploy/post-deploy.

## Обязательные сквозные регрессии

- Два пользователя одновременно актируют один остаток.
- Повтор create/approve/invoice/payment после таймаута.
- Частичный акт → частичный счёт → несколько оплат.
- Допработа согласована после первого акта.
- Аннулирование акта после создания счёта.
- Возврат после частичного закрытия задолженности.
- Изменение НДС/цены между версиями без изменения истории.
- Закрытие/расторжение договора с незавершёнными обязательствами.
- Cross-organization/project/contract/customer document rejection.
- Большая смета, пагинация и отсутствие загрузки snapshot в списке.
- Медленная сеть и version conflict в интерфейсах.

## Версионирование смет: критерий готовности

Вердикт: **работает**. Backend и admin подтверждают полное независимое клонирование, неизменяемость утверждённых/использованных версий, атомарный current, конкурентное согласование, уникальную нумерацию, идемпотентный retry, полный compare, пагинацию и закрепление актов за конкретной версией. Mobile не имеет отдельного экрана истории версий и не изменялся; его approve endpoint теперь вызывает тот же `EstimateStatusWorkflowService`, поэтому второго статусного механизма нет.

| Операция | Ожидаемое поведение | Фактический backend | Фактический UI | Результат | FIN-ID |
|---|---|---|---|---|---|
| Изменить утверждённую смету | Новая draft-редакция, старая неизменна | `EstimateRevisionService`, DB guards | «Создать редакцию», historical view read-only | Работает | FIN-003 |
| Клонировать snapshot | Полная независимая копия | schema v2: sections/items/resources/VAT/discounts/metadata | Версия открывается отдельно | Работает | FIN-005 |
| Утвердить две версии конкурентно | Один current approved | row lock + partial unique indexes | Конфликт показывается без silent overwrite | Работает | FIN-006 |
| Повторить create после timeout | Вернуть исходную версию | unique idempotency key | Ключ сохраняется до успешного ответа | Работает | FIN-010 |
| Сравнить версии | Добавленные/удалённые/изменённые строки, ресурсы, НДС и totals | stable-key recursive diff | Таблица сравнения и empty/error/loading states | Работает | FIN-007/008 |
| Получить большой список | Пагинация без тяжёлых snapshots | metadata paginator | TablePagination | Работает | FIN-009 |
| Создать акт по версии | Закрепить version/item financial basis | `estimate_version_id` + immutable line basis | Основание и суммы read-only | Работает | FIN-004 |
| Выпустить новую версию после акта | Не менять акт/счёт/долг | История считается из pinned basis/ledger | Старые документы не пересчитываются | Работает | FIN-004/020 |

## Собственная verification-сводка

- Backend DB tests запускались только штатным `tests/Runtime/run-postgres-tests.ps1` на изолированном PostgreSQL 16; ручные миграции, локальный tinker и продуктовые DB-команды не запускались.
- Версионирование: 19 tests / 133 assertions; акты preview: 28 / 125; act basis: 2 / 20; платежный estimate lifecycle: 12 / 46; bulk: 4 / 27; refunds/transactions: 5 / 31; budget limits: 5 / 16; agreements: 6 / 52; tenant customer projection: 2 / 24; payment pagination: 1 / 4.
- Backend unit/architecture объединённый прогон: 28 / 168; accepted-production hash contract: 5 / 33; `php -l` 96 файлов; Pint 96 файлов; PHPStan изменённого `app`-слоя — 0 ошибок.
- После единственного независимого ревью дополнительно GREEN: project IDOR, full snapshot header/contract links, rejected/idempotent estimate approval, external bank identity/fingerprint, cumulative VAT final allocation, legacy paid-invoice annul, refund projections/budget reopen, terminal/negative supplementary agreement и cumulative variation limit.
- Backend после закрытия ревью: `php -l` GREEN; Pint 102 files; PHPStan изменённого `app`-слоя — 0 ошибок.
- Admin после закрытия ревью: 8 Vitest files / 25 tests, `tsc --noEmit`, ESLint 0 errors (8 warnings), Prettier pass.
- Customer после закрытия ревью: 2 targeted Vitest files / 3 tests, `tsc --noEmit`; проект не содержит ESLint/Prettier dependency/config, поэтому его штатная lint-проверка равна TypeScript check.
- Mobile: product diff отсутствует; backend mobile approval делегирует каноническому workflow; локальная Flutter SDK отсутствует, поэтому повторный Flutter test/analyze недоступен и отмечен как предел окружения, а не как зелёный runtime evidence.
- Следующие обязательные этапы: атомарные коммиты, PR/CI/deploy и read-only post-deploy verification.

## Независимое финальное ревью

Ровно одно разрешённое независимое ревью полного diff выполнено после исходной реализации. Все 13 замечаний закрыты собственноручно: полнота snapshot/restore, bank-event identity, legacy invoice annul, budget release/reopen, refund projections, terminal/commitment guards допсоглашений, variation allocation limit, project IDOR, cumulative VAT, idempotent approve, after-commit notification, decimal API contracts и rejected status. Повторное независимое ревью не запускалось.

## Матрица evidence

Для каждого блока сюда добавляются:

- команда и результат RED;
- изменённые файлы;
- команда и результат GREEN;
- статический анализ/типизация;
- остаточный риск и rollout/backfill решение;
- PR, merge SHA, CI/deploy run и post-deploy evidence.

## Журнал решений

- 2026-08-23: выбран совместимый переход к immutable snapshots и allocation ledger без второго параллельного финансового механизма; существующие endpoints сохраняются только как адаптеры к единому service workflow.
- 2026-08-23: FIN-024 оставлен `Needs production evidence` до read-only проверки фактических данных и производительности.
- 2026-08-24: единственное независимое ревью завершено; все подтверждённые замечания закрыты targeted regressions, production duplicate/SLO evidence отложен только из-за недоступности SSH banner.
