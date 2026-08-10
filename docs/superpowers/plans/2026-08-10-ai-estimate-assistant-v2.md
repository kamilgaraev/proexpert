# AI-помощник сметчика МОСТ v2 — план реализации

> **Для агентного исполнителя:** ОБЯЗАТЕЛЬНЫЙ SKILL — `superpowers:executing-plans`. Выполнять план последовательно в основном контексте. `superpowers:subagent-driven-development`, любые субагенты, делегирование и параллельное агентное ревью запрещены пользователем.

**Цель:** превратить существующий AI-сметчик в надёжного AI-помощника, который глубоко изучает комплект строительной документации, предлагает технологически полный почти готовый черновик обычной сметы МОСТ и отдаёт оператору только существенные исключения и решения.

**Архитектура:** один версионированный документный pipeline формирует доказательные факты и связи между листами; AI отвечает за понимание, сопоставление, поиск пробелов и рекомендации, а обычный PHP-код — за контракты, формулы, нормы, цены и финансовые итоги. Первый этап полностью удаляет лишние параллельные контуры и оставляет одну рабочую цепочку до начала расширения функциональности.

**Технологии:** PHP 8.2, Laravel 11, PostgreSQL, Laravel queues, S3 через `FileService`, React/TypeScript/Vite, Vitest, MSW, существующие AI-провайдеры, существующий CAD runtime, ФСНБ и действующие источники региональных цен.

**Спецификация:** `docs/superpowers/specs/2026-08-10-ai-estimate-assistant-v2-design.md`.

## Глобальные ограничения

- Запрещено использовать субагентов для реализации, исследования, ревью, тестирования и подготовки evidence.
- Каждый этап выполняется одним основным агентом последовательно; после этапа он делает отдельный самостоятельный review-pass.
- Backend и `prohelper_admin` разрабатываются в отдельных feature-ветках от свежего `origin/main`; напрямую в `main` не работать.
- Не запускать миграции, tinker, DB-команды, dev-серверы и frontend build.
- Применённые миграции не переписывать и не удалять; удаление production-таблиц выполнять только новой forward-only миграцией.
- Контроллеры остаются тонкими; workflow, транзакции, ABAC и расчёты находятся в сервисах.
- Авторизация выполняется через `AuthorizationService` и контекст организации/проекта; роли и их названия не хардкодить.
- API использует стандартизированные response-классы; `response()->json()` напрямую не применять.
- Пользовательские PHP-тексты возвращать через `trans_message(...)`.
- Все новые PHP-файлы содержат `declare(strict_types=1);` и соответствуют PSR-12.
- Все файлы хранить в S3 через `FileService` в путях `org-{organization_id}/...`.
- Единица потребления — одна сессия одного комплекта документов до принятого черновика.
- 10 генераций в месяц входят в модуль; дополнительные генерации учитываются существующей системой коммерческих лимитов.
- Дозагрузки, AI-повторы, диалог, исправления и локальные пересчёты не списывают новую генерацию.
- Техническое завершение без пригодного черновика освобождает резерв генерации.
- Нельзя тарифицировать пользователю токены, страницы, внутренние AI-вызовы или этапы.
- Любая мутация черновика через AI-диалог требует предварительного просмотра и явного подтверждения.
- Итогом является обычная смета МОСТ, а не отдельный AI-тип документа.
- OCR не является общим знаменателем: PDF, изображения, CAD и XLSX сохраняют нативную структуру и визуальное представление.
- AI не рассчитывает финансовый итог свободным текстом; формулы, нормы, цены и суммы обрабатываются детерминированным кодом.
- После каждого логического этапа запускать только минимальные целевые проверки; не повторять успешно пройденные полные наборы без изменения покрываемого кода.
- Для PHP-логики выполнять целевой PHPStan изменённых файлов; для TypeScript-логики — `npx tsc --noEmit` и целевой Vitest.
- Изменения схемы проверять статически и тестами в разрешённом изолированном test/CI окружении; локально миграции не выполнять.

## Целевой runtime после очистки

```text
Session + quota
    ↓
Document ingestion
    ↓
Canonical format adapters
    ↓
Sheet classification and multimodal analysis
    ↓
Entity / Fact / Evidence / Conflict / Decision
    ↓
Completeness and technology recommendations
    ↓
Deterministic quantities / FSNB / pricing
    ↓
Ordinary MOST estimate draft
    ↓
Exception review + confirmed AI dialogue changes
    ↓
Evaluation corpus and cost metrics
```

## Карта этапов

| Этап | Результат | Блокирует |
|---:|---|---|
| 1 | Полностью удалена ненужная инфраструктура, остался один runtime | Все последующие этапы |
| 2 | Зафиксирована коммерческая сессия: 10 + доп. лимит | Запуск новых сессий |
| 3 | Все форматы дают нативное и визуальное представления | Анализ документов |
| 4 | AI связывает документы в узкую доказательную модель | Комплектность и расчёты |
| 5 | AI предлагает технологические системы и недостающие работы | Почти готовый черновик |
| 6 | Формируется обычная смета МОСТ с нормами и ценами | Пользовательский результат |
| 7 | Работает проверка по исключениям и AI-диалог | Масштабирование оператора |
| 8 | Работает контур обучения, benchmark и расчёт экономики | Решение о выпуске и цене |

---

## Этап 1. Полная очистка лишней инфраструктуры

Этап считается завершённым только после удаления runtime-кода, регистраций контейнера, расписаний, маршрутов, фоновых заданий, frontend-вызовов, тестов удалённого поведения и production-схемы через forward-only миграции. Оставлять «временно неиспользуемые» реализации запрещено.

### Task 1: Зафиксировать cleanup matrix и защищаемые продуктовые контракты

**Files:**
- Create: `docs/estimate-generation/ai-estimate-v2-cleanup-matrix.md`
- Create: `tests/Architecture/EstimateGenerationV2RuntimeBoundaryTest.php`
- Modify: `tests/Architecture/EstimateGenerationOrdinaryEstimateBoundaryTest.php`
- Read: `app/BusinessModules/Addons/EstimateGeneration/EstimateGenerationServiceProvider.php`
- Read: `routes/console.php`
- Read: `routes/api_v1.php`

**Interfaces:**
- Consumes: текущие container bindings, routes, schedules, jobs, migrations и frontend endpoints.
- Produces: таблицу `KEEP | REPLACE | DELETE` с конкретным владельцем каждого runtime-контура и архитектурный запрет на возвращение удалённых подсистем.

- [x] **Step 1: Создать исчерпывающую cleanup matrix**

В документе перечислить минимум следующие контуры и решение по каждому:

```text
KEEP    AiEstimateQuotaService и estimate_generation_ai_estimate_quota_reservations
KEEP    AiUsageStore, AiCostCalculator, AiPricingCatalog как измерение факта
DELETE  estimate_generation_ai_budget_reservations и SQL-функции eg_*_ai_budget
DELETE  ReconcileAiBudgetReservationsJob и AiAttemptBudgetAuthorizer
KEEP    безопасная запись технической ошибки и штатный retry очереди
DELETE  AdminFailureResolution* и изменяющий failure workflow
KEEP    проверенный evaluation corpus и версии AI-контрактов
DELETE  training lease recovery, online migration и преждевременный dataset workflow
KEEP    один DocumentUnitAdapter contract для определения единиц документа
RENAME  native Cad/Spreadsheet extractors, чтобы они не назывались вторыми adapters
KEEP    один PipelineRunner и один checkpoint store
DELETE  параллельные finalization outbox/delivery abstractions после замены уникальным publication marker
KEEP    версионированный audit решений пользователя
DELETE  event-sourcing-подобную correction chain после переноса undo в обычный журнал версий
```

- [x] **Step 2: Добавить RED architecture test запрещённых runtime-символов**

Тест должен сканировать production PHP-код, service provider и `routes/console.php` и падать, пока встречаются:

```php
$forbidden = [
    'AiAttemptBudgetAuthorizer',
    'AiBudgetGuard',
    'ReconcileAiBudgetReservationsJob',
    'AdminFailureResolutionCommand',
    'AdminFailureResolutionTransaction',
    'RecoverExpiredTrainingDatasetLeasesJob',
    'TrainingBenchmarkOnlineMigrationRuntime',
    'FinalizationOutbox',
    'FinalizationDeliveryStore',
];
```

Тест отдельно разрешает старые имена только в исторических миграциях и в cleanup migration.

- [x] **Step 3: Зафиксировать защищаемые публичные контракты**

Добавить assertions, что после очистки сохраняются:

```text
POST session/start
POST session/documents
GET  session/snapshot
GET  session/review
POST session/generate-draft
обычный Estimate writer
AiEstimateQuotaService
AiUsageStore
AuthorizationService checks
```

- [x] **Step 4: Запустить только архитектурные тесты**

Run:

```powershell
vendor\bin\phpunit tests\Architecture\EstimateGenerationV2RuntimeBoundaryTest.php tests\Architecture\EstimateGenerationOrdinaryEstimateBoundaryTest.php
```

Expected: новый boundary test падает на перечисленных legacy runtime-символах; существующая граница обычной сметы проходит.

- [x] **Step 5: Commit**

```text
test[backend]: зафиксирована граница очистки AI-сметчика
```

### Task 2: Удалить внутреннюю финансовую бухгалтерию AI-вызовов

**Files:**
- Delete: `app/BusinessModules/Addons/EstimateGeneration/Observability/AiAttemptBudgetAuthorizer.php`
- Delete: `app/BusinessModules/Addons/EstimateGeneration/Observability/AiBudgetGuard.php`
- Delete: `app/BusinessModules/Addons/EstimateGeneration/Jobs/ReconcileAiBudgetReservationsJob.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Observability/EloquentAiUsageStore.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Observability/AiUsageAttemptExecutor.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/EstimateGenerationServiceProvider.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/migrations/2026_08_10_000100_drop_internal_ai_budget_accounting.php`
- Delete: `tests/Unit/EstimateGeneration/Observability/AiBudgetReservationContractTest.php`
- Delete: `tests/Feature/EstimateGeneration/AiBudgetExactlyOncePostgresContractTest.php`
- Create: `tests/Unit/EstimateGeneration/Observability/AiUsageCostLedgerTest.php`

**Interfaces:**
- Consumes: `AiOperationContext`, provider usage data and `AiPricingCatalog`.
- Produces: `AiUsageStore::record(AiUsageData $usage): void` без reserve/claim/settle/reconcile.
- Preserves: пользовательскую квоту `AiEstimateQuotaService`; она не относится к удаляемому внутреннему бюджету.

- [x] **Step 1: Написать RED unit-тест простого журнала стоимости**

Проверить один физический provider result:

```php
$usage = AiUsageData::completed(
    operationId: 'vision:session-1:page-3:attempt-1',
    sessionId: 'session-1',
    operation: 'vision.sheet',
    model: 'configured-model',
    inputTokens: 1200,
    outputTokens: 300,
    costMinor: 184,
    durationMs: 8420,
);

$store->record($usage);

self::assertSame(184, $store->forSession('session-1')->totalCostMinor());
```

Повтор того же `operationId` не создаёт вторую запись, но не требует отдельной budget reservation.

- [x] **Step 2: Упростить исполнение AI-попытки**

`AiUsageAttemptExecutor` должен выполнять только:

```text
authorize organization/project action
invoke provider
calculate actual cost
persist one idempotent usage row
return typed provider result
```

Удалить reserve, wire claim, settle, release и reconciliation branches.

- [x] **Step 3: Удалить bindings и schedule**

Из `EstimateGenerationServiceProvider` удалить binding `AiAttemptBudgetAuthorizer`, schedule `ReconcileAiBudgetReservationsJob` и связанные настройки. Оставить provider safety caps как обычную конфигурацию максимального размера запроса, а не финансовый ledger.

- [x] **Step 4: Добавить forward-only cleanup migration**

Migration `up()` должна удалить SQL-функции `eg_reserve_ai_budget`, `eg_claim_ai_budget_wire`, `eg_mark_ai_budget_sent`, `eg_settle_ai_budget`, `eg_release_ai_budget`, `eg_mark_ai_budget_reconciliation`, `eg_reconcile_expired_ai_budgets`, затем таблицы `estimate_generation_ai_budget_reservations` и `estimate_generation_ai_operations`, если последняя больше не используется журналом фактического usage.

Migration `down()` должна бросать `RuntimeException` с техническим сообщением о необратимой cleanup migration; восстанавливать удалённую архитектуру запрещено.

- [x] **Step 5: Проверить отсутствие связи с пользовательской квотой**

Run:

```powershell
vendor\bin\phpunit tests\Feature\EstimateGeneration\AiEstimateQuotaTest.php tests\Unit\EstimateGeneration\Observability\AiUsageCostLedgerTest.php
vendor\bin\phpstan analyse app\BusinessModules\Addons\EstimateGeneration\Observability app\BusinessModules\Addons\EstimateGeneration\Jobs\ReconcileAiBudgetReservationsJob.php --memory-limit=1G
```

Expected: quota tests проходят; PHPStan command корректируется до существующих изменённых файлов после удаления job.

- [x] **Step 6: Commit**

```text
refactor[backend]: удалена внутренняя бухгалтерия AI-вызовов
```

### Task 3: Удалить изменяющий failure-management workflow

**Files:**
- Delete: `app/BusinessModules/Addons/EstimateGeneration/Operations/AdminFailureResolution*.php`
- Delete: `app/BusinessModules/Addons/EstimateGeneration/Operations/SystemAdminFailureResolutionAuthorizer.php`
- Delete: `app/BusinessModules/Addons/EstimateGeneration/Observability/FailureWorkflowAction.php`
- Delete: `app/BusinessModules/Addons/EstimateGeneration/Observability/FailureWorkflowFence.php`
- Delete: `app/BusinessModules/Addons/EstimateGeneration/Observability/FailureWorkflowHandler.php`
- Delete: `app/BusinessModules/Addons/EstimateGeneration/Observability/EloquentFailureWorkflowHandler.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Generation/HandleEstimateGenerationDraftFailure.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Documents/HandleDocumentProcessingFailure.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Pipeline/PipelineRunner.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/EstimateGenerationServiceProvider.php`
- Keep read-only: `app/BusinessModules/Addons/EstimateGeneration/Observability/FailureRecorder.php`
- Create: `tests/Unit/EstimateGeneration/Observability/SimpleFailureRecoveryPolicyTest.php`

**Interfaces:**
- Produces: `FailureRecorder::record(FailureContext $context, Throwable $error): FailureData`.
- Produces: `RetryEstimateGenerationStage::retry(string $sessionId, string $stage, ActorContext $actor): void` либо существующий эквивалент, без отдельного failure resolution registry.

- [x] **Step 1: Зафиксировать три допустимых исхода сбоя**

```text
retryable     → Laravel queue retry/backoff, checkpoint не теряется
needs_input   → сессия показывает понятное действие пользователю
terminal      → сессия завершена технически, quota release выполняется один раз
```

- [x] **Step 2: Написать RED-тест простой recovery policy**

Проверить, что retryable ошибка не меняет подтверждённые результаты; needs_input создаёт пользовательское исключение; terminal error освобождает пользовательскую quota только если пригодный черновик не создан.

- [x] **Step 3: Перевести callers на FailureRecorder и штатный retry**

`PipelineRunner`, document failure handler и draft failure handler больше не должны вызывать claim/fence/transition registry. Идемпотентность terminal handling обеспечивается уникальным terminal marker сессии и транзакцией quota service.

- [x] **Step 4: Удалить mutating admin endpoints и Filament actions**

Read-only экран истории сбоев можно оставить. Любые кнопки «resolve/reopen/claim» и связанные маршруты удалить. Ручной повтор выполняется тем же публичным application service, что и пользовательский retry, с ABAC-проверкой.

- [x] **Step 5: Запустить целевые failure tests и PHPStan**

```powershell
vendor\bin\phpunit tests\Unit\EstimateGeneration\Observability\SimpleFailureRecoveryPolicyTest.php tests\Feature\EstimateGeneration\Pipeline\PipelineFailureRecoveryTest.php
vendor\bin\phpstan analyse app\BusinessModules\Addons\EstimateGeneration\Application\Generation app\BusinessModules\Addons\EstimateGeneration\Application\Documents app\BusinessModules\Addons\EstimateGeneration\Pipeline --memory-limit=1G
```

- [x] **Step 6: Commit**

```text
refactor[backend]: упрощено восстановление AI-смет
```

### Task 4: Сократить преждевременную MLOps-инфраструктуру без потери обучения

**Files:**
- Delete: `app/BusinessModules/Addons/EstimateGeneration/Jobs/RecoverExpiredTrainingDatasetLeasesJob.php`
- Delete: `app/BusinessModules/Addons/EstimateGeneration/Jobs/ProcessEstimateGenerationTrainingDatasetJob.php`
- Delete: `app/BusinessModules/Addons/EstimateGeneration/Support/TrainingBenchmarkOnlineMigrationRuntime.php`
- Delete: `app/BusinessModules/Addons/EstimateGeneration/Services/Training/TrainingDatasetReviewStateMachine.php`
- Delete: `app/BusinessModules/Addons/EstimateGeneration/Services/Training/TrainingDatasetActionPolicy.php`
- Delete: `app/BusinessModules/Addons/EstimateGeneration/Operations/AdminTrainingDatasetAction*.php`
- Modify: `routes/console.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/EstimateGenerationServiceProvider.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Evaluation/EvaluationExample.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Evaluation/EvaluationCorpus.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Evaluation/EvaluationExampleTrust.php`
- Create: `tests/Unit/EstimateGeneration/Evaluation/EvaluationCorpusTest.php`

**Interfaces:**
- Produces: immutable evaluation example with `sourceVersion`, `expectedFacts`, `expectedDecisions`, `expectedQuantities`, `expectedEstimateRows`, `trustStatus`.
- `trustStatus`: `candidate | reviewed | rejected`; только `reviewed` участвует в release gate.

- [x] **Step 1: Написать RED-тест доверенного evaluation corpus**

Проверить, что пользовательское исправление создаёт `candidate`, а не автоматически `reviewed`; release comparison читает только reviewed examples; train/test split неизменяем по stable source hash.

- [x] **Step 2: Реализовать простой corpus API без leases**

Корпус должен поддерживать синхронные application operations `addCandidate`, `review`, `reject`, `listReviewed`. Тяжёлый benchmark запускается отдельной явной CLI-командой/CI job, а не постоянным lease processor.

- [x] **Step 3: Удалить scheduled recovery и online migration runtime**

Удалить schedule из `routes/console.php`, job middleware/rate-limit bindings и Filament actions, завязанные на lease state machine. Исторические таблицы удалить поздней forward-only migration только после переноса reviewed examples в каноническую схему evaluation corpus.

- [x] **Step 4: Сохранить полезные нормализаторы и corpus importer**

`TrainingEstimateRowNormalizer`, безопасный импорт обезличенных примеров и benchmark comparison не удалять, если cleanup matrix доказывает их прямое использование новым `EvaluationCorpus`. Переименовать из `Training` в `Evaluation`, чтобы runtime не обещал автоматическое обучение модели.

- [x] **Step 5: Проверить corpus contract**

```powershell
vendor\bin\phpunit tests\Unit\EstimateGeneration\Evaluation\EvaluationCorpusTest.php tests\Unit\EstimateGeneration\Training\TrainingDatasetTrustPolicyTest.php
vendor\bin\phpstan analyse app\BusinessModules\Addons\EstimateGeneration\Evaluation --memory-limit=1G
```

До удаления старого trust policy второй тест переносится на новый namespace и старый файл удаляется.

- [x] **Step 6: Commit**

```text
refactor[backend]: упрощен контур обучения AI-сметчика
```

### Task 5: Объединить форматные адаптеры и исключить второй источник истины

**Files:**
- Keep: `app/BusinessModules/Addons/EstimateGeneration/Application/Documents/DocumentUnitAdapter.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Documents/PdfDocumentAdapter.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Documents/ImageDocumentAdapter.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Documents/CadDocumentAdapter.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Documents/SpreadsheetDocumentAdapter.php`
- Rename: `app/BusinessModules/Addons/EstimateGeneration/Documents/Cad/CadDocumentAdapter.php` → `.../Documents/Cad/CadStructureExtractor.php`
- Rename: `app/BusinessModules/Addons/EstimateGeneration/Documents/Spreadsheet/SpreadsheetDocumentAdapter.php` → `.../Documents/Spreadsheet/SpreadsheetStructureExtractor.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Application/Documents/DocumentRepresentation.php`
- Create: `tests/Unit/EstimateGeneration/Documents/CanonicalDocumentAdapterContractTest.php`

**Interfaces:**

```php
interface DocumentUnitAdapter
{
    public function supports(StoredDocumentArtifact $artifact): bool;
    public function createUnits(StoredDocumentArtifact $artifact): array;
    public function representation(DocumentUnitData $unit): DocumentRepresentation;
}

final readonly class DocumentRepresentation
{
    public function __construct(
        public DocumentSourceVersion $source,
        public array $nativeStructure,
        public string $visualArtifactPath,
        public string $coordinateSpace,
        public array $capabilities,
    ) {}
}
```

- [x] **Step 1: Написать общую contract matrix PDF/image/CAD/XLSX**

Каждый адаптер обязан вернуть source version, native structure, visual artifact, coordinate space и capabilities. IFC остаётся явно неподдерживаемым до отдельного решения и не маскируется CAD adapter.

- [x] **Step 2: Разделить unit adapter и native extractor по именам и ответственности**

Unit adapter управляет единицами документа и каноническим representation. Native extractor только читает форматную структуру и не публикует второй document contract.

- [x] **Step 3: Удалить legacy callers старых adapter contracts**

`ArtifactDocumentUnitDetector`, `ProductionDocumentUnitProcessor` и service provider должны зависеть только от `DocumentUnitAdapter` и typed extractor interfaces.

- [x] **Step 4: Запустить contract tests всех форматов**

```powershell
vendor\bin\phpunit tests\Unit\EstimateGeneration\Documents\CanonicalDocumentAdapterContractTest.php tests\Unit\EstimateGeneration\Documents tests\Unit\EstimateGeneration\Ocr
vendor\bin\phpstan analyse app\BusinessModules\Addons\EstimateGeneration\Application\Documents app\BusinessModules\Addons\EstimateGeneration\Documents --memory-limit=1G
```

- [x] **Step 5: Commit**

```text
refactor[backend]: объединены адаптеры документов AI-смет
```

### Task 6: Свести pipeline к одному runner и одному способу финализации

**Files:**
- Keep: `PipelineRunner.php`, `PipelineStage.php`, `PipelineStageResult.php`, `EloquentPipelineCheckpointStore.php`, `S3PipelineArtifactStore.php`
- Delete after caller migration: `FinalizationOutbox.php`, `EloquentFinalizationOutbox.php`, `InMemoryFinalizationOutbox.php`
- Delete after caller migration: `FinalizationDeliveryStore.php`, `EloquentFinalizationDeliveryStore.php`, `InMemoryFinalizationDeliveryStore.php`
- Delete after caller migration: `FinalizationClaim.php`, `FinalizationDeliveryReceipt.php`, `DocumentManifestPublicationFence.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Pipeline/PublishDraftOnce.php`
- Create: `tests/Feature/EstimateGeneration/Pipeline/SinglePipelineRuntimeContractTest.php`

**Interfaces:**

```php
interface PublishDraftOnce
{
    public function publish(string $sessionId, string $pipelineVersion, string $artifactHash): DraftPublicationResult;
}
```

Publication выполняется в транзакции с unique key `(session_id, pipeline_version, artifact_hash)` и повторно возвращает существующий result.

- [x] **Step 1: Написать RED contract test повторной финализации**

Два последовательных и два конкурентных вызова с одним ключом создают ровно одну обычную смету; сбой до commit не оставляет publication marker; повтор после сбоя безопасен.

- [x] **Step 2: Реализовать PublishDraftOnce поверх обычного Estimate writer**

Не вводить новый outbox. Если существующий Estimate writer уже транзакционен и имеет natural unique key, использовать его; иначе добавить минимальный publication marker в forward-only migration.

- [x] **Step 3: Перевести DeliverFinalization и PublishValidatedDraft на один service**

После переноса удалить outbox/delivery interfaces, implementations, bindings и тесты их внутренней механики.

- [x] **Step 4: Упростить claims и leases**

Оставить lease только там, где один и тот же queue unit реально может исполняться конкурентно дольше visibility timeout. Claims, не защищающие доказанный race, удалить. Зафиксировать оставшиеся claims в cleanup matrix с конкретным race-test.

- [x] **Step 5: Запустить pipeline contract tests**

```powershell
vendor\bin\phpunit tests\Feature\EstimateGeneration\Pipeline\SinglePipelineRuntimeContractTest.php tests\Feature\EstimateGeneration\Pipeline\PipelineCheckpointPostgresContractTest.php tests\Unit\EstimateGeneration\Pipeline
vendor\bin\phpstan analyse app\BusinessModules\Addons\EstimateGeneration\Pipeline --memory-limit=1G
```

- [x] **Step 6: Commit**

```text
refactor[backend]: оставлен единый pipeline AI-смет
```

### Task 7: Заменить correction chain обычным журналом решений

**Files:**
- Delete after migration: `BuildingModel/ProjectModelCorrectionChainProjector.php`
- Delete after migration: `BuildingModel/ProjectModelCorrectionList.php`
- Delete after migration: `BuildingModel/ProjectModelCorrectionConflict.php`
- Create: `Domain/Decisions/EstimateDecision.php`
- Create: `Domain/Decisions/EstimateDecisionRepository.php`
- Create: `Domain/Decisions/ApplyEstimateDecision.php`
- Create: `Domain/Decisions/RevertEstimateDecision.php`
- Modify: `Http/Controllers/EstimateGenerationProjectModelCorrectionController.php`
- Modify: `Http/Presentation/ProjectModelCorrectionHistoryPresenter.php`
- Create: `tests/Feature/EstimateGeneration/DecisionJournalApiTest.php`

**Interfaces:**

```php
ApplyEstimateDecision::handle(
    sessionId: string,
    decisionKey: string,
    expectedVersion: int,
    before: array,
    after: array,
    reason: string,
    actor: ActorContext,
): EstimateDecision;
```

- [x] **Step 1: Написать RED-тест apply/revert/version conflict**

Проверить optimistic locking, idempotency key, неизменяемый audit, revert как новую запись и отсутствие полного replay correction chain для чтения текущего состояния.

- [x] **Step 2: Реализовать current projection + append-only audit**

Текущее значение хранится в канонической проекции. Журнал содержит before/after, actor, reason, source command и timestamp. Revert применяет обратное изменение через тот же service.

- [x] **Step 3: Сохранить совместимость HTTP-контракта**

Существующие клиенты получают прежние бизнес-поля истории, но backend больше не использует chain projector. Технические legacy-поля не возвращать в UI.

- [x] **Step 4: Удалить legacy chain и его bindings/tests**

Архитектурный тест должен запрещать `ProjectModelCorrectionChainProjector` в production runtime.

- [x] **Step 5: Запустить decision tests и PHPStan**

```powershell
vendor\bin\phpunit tests\Feature\EstimateGeneration\DecisionJournalApiTest.php tests\Feature\EstimateGeneration\ProjectModelCorrectionApiTest.php
vendor\bin\phpstan analyse app\BusinessModules\Addons\EstimateGeneration\Domain\Decisions app\BusinessModules\Addons\EstimateGeneration\Http --memory-limit=1G
```

- [x] **Step 6: Commit**

```text
refactor[backend]: упрощен журнал решений AI-сметы
```

### Task 8: Закрыть cleanup gate

**Files:**
- Modify: `docs/estimate-generation/ai-estimate-v2-cleanup-matrix.md`
- Modify: `tests/Architecture/EstimateGenerationV2RuntimeBoundaryTest.php`
- Create: `docs/estimate-generation/ai-estimate-v2-cleanup-evidence.md`

- [x] **Step 1: Повторно построить caller map**

Для каждого `DELETE` убедиться, что отсутствуют production callers, routes, schedules, bindings, frontend constants и изменяющие Filament actions.

- [x] **Step 2: Проверить runtime-запреты**

```powershell
vendor\bin\phpunit tests\Architecture\EstimateGenerationV2RuntimeBoundaryTest.php tests\Architecture\EstimateGenerationOrdinaryEstimateBoundaryTest.php
```

Expected: PASS; исторические миграции разрешены только explicit allowlist.

- [x] **Step 3: Проверить migration safety статически**

В evidence перечислить forward-only cleanup migrations, порядок deploy (`deploy code without callers` → `observe` → `run schema cleanup in controlled deploy`) и rollback boundary. Миграции локально не запускать.

- [x] **Step 4: Выполнить один самостоятельный review-pass**

Основной агент перечитывает staged diff как reviewer: ищет оставшиеся параллельные runtime, dangling bindings, schedule references, удалённые API imports и потерю пользовательской квоты. Результат записывает в evidence без субагента.

- [x] **Step 5: Commit**

```text
docs[backend]: подтверждена очистка AI-сметчика
```

**Gate:** к этапу 2 нельзя переходить, пока architecture test не проходит и cleanup matrix не имеет строк `DELETE` со статусом «осталось в runtime».

---

## Этап 2. Сессия, квота и ABAC

### Task 9: Зафиксировать одну генерацию на жизненный цикл комплекта документов

**Files:**
- Modify: `Services/Billing/AiEstimateQuotaService.php`
- Modify: `Application/Generation/RequestEstimateGeneration.php`
- Modify: `Application/Sessions/AdvanceEstimateGeneration.php`
- Modify: document upload/replacement service
- Modify: terminal failure handler
- Modify: `resources/lang/ru/ai_estimates.php`
- Test: `tests/Feature/EstimateGeneration/AiEstimateQuotaTest.php`

**Interfaces:**

```php
AiEstimateQuotaService::reserveSession(string $organizationId, string $sessionId): QuotaSnapshot;
AiEstimateQuotaService::releaseTechnicalFailure(string $organizationId, string $sessionId): QuotaSnapshot;
AiEstimateQuotaService::snapshot(string $organizationId): QuotaSnapshot;
```

`QuotaSnapshot`: `included=10`, `purchased`, `used`, `available`, `reservationStatus`.

- [x] Добавить RED-сценарии: 10 включённых генераций, purchased extras, повторный start, дозагрузка, AI-диалог, локальный rebuild, техническая ошибка без draft и ошибка после draft.
- [x] Реализовать единый session reservation key `(organization_id, session_id)`.
- [x] Удалить любые альтернативные счётчики `ai_estimates_month: 0` и вычислять snapshot только из quota reservations + commercial limits.
- [x] Проверить start/upload/rebuild permissions через `AuthorizationService`, без role slug.
- [x] Запустить `AiEstimateQuotaTest`, permission contract test и PHPStan изменённых файлов.
- [x] Commit: `fix[backend]: закреплена единица генерации AI-сметы`.

---

## Этап 3. Каноническое понимание всех форматов

### Task 10: Формировать нативное и визуальное представления PDF, изображений, CAD и XLSX

**Files:**
- Modify: `Application/Documents/PdfDocumentAdapter.php`
- Modify: `Application/Documents/ImageDocumentAdapter.php`
- Modify: `Application/Documents/CadDocumentAdapter.php`
- Modify: `Application/Documents/SpreadsheetDocumentAdapter.php`
- Create: `Application/Documents/DocumentRepresentationCapabilities.php`
- Create fixtures: `tests/Fixtures/EstimateGeneration/documents/v2/`.
- Test: `tests/Unit/EstimateGeneration/Documents/DocumentRepresentationMatrixTest.php`.

**Interfaces:**

```text
PDF   → text spans + vectors + page render + source coordinates
Image → original raster + OCR spans + image coordinates
CAD   → layers + blocks + polylines + dimensions + texts + sheet render
XLSX  → sheets + cells + formulas + merges + table render
```

- [x] Подготовить по одному минимальному и одному production-sized fixture каждого формата без персональных данных.
- [x] Написать RED matrix test capability contracts и source-coordinate roundtrip.
- [x] Реализовать representation builders с лимитами памяти, страниц, объектов и безопасным S3 workspace.
- [x] Не скрывать деградацию: отсутствующая native capability возвращает typed limitation и попадает в review exception.
- [x] Проверить, что замена одного файла инвалидирует только его units/evidence/dependants.
- [x] Запустить document contract tests и PHPStan; тяжёлые production fixtures вынести в CI gate, если локальный набор превышает пять минут.
- [x] Commit: `feat[backend]: унифицировано понимание проектных документов`.

### Task 11: Маршрутизировать мультимодальный анализ по роли листа

**Files:**
- Modify: `Application/Documents/Understanding/SheetRole.php`
- Modify: `SheetRoleClassifier.php`, `SheetAnalysisRouter.php`
- Create: `Vision/Contracts/PlanSheetAnalysis.php`, `SectionSheetAnalysis.php`, `FacadeSheetAnalysis.php`, `SpecificationSheetAnalysis.php`, `UnknownSheetAnalysis.php`
- Test: `tests/Unit/EstimateGeneration/Vision/SheetRoleAnalysisContractTest.php`

**Interfaces:** каждый найденный факт содержит `entityKey`, `factType`, `value`, `unit`, `evidenceRef`, `sourcePolygonOrNativeRef`, `confidence`, `contractVersion`.

- [ ] Написать RED-тесты плана, разреза, фасада, экспликации, спецификации и неизвестного листа.
- [ ] Ограничить каждый AI-вызов одной ролью и одним типизированным контрактом.
- [ ] Реализовать targeted recheck только для конфликтной сущности или пары листов.
- [ ] Записать reason и source set каждой повторной проверки в usage ledger.
- [ ] Отклонять неизвестные ключи, битые координаты и факты без evidence.
- [ ] Запустить contract tests, privacy tests, provider payload tests и PHPStan.
- [ ] Commit: `feat[backend]: добавлен ролевой анализ листов проекта`.

---

## Этап 4. Доказательная модель и междокументные связи

### Task 12: Свести существующую модель к Entity/Fact/Evidence/Conflict/Decision/DerivedQuantity

**Files:**
- Create: `Domain/ProjectModel/Entity.php`, `Fact.php`, `Evidence.php`, `Conflict.php`, `Decision.php`, `DerivedQuantity.php`.
- Modify: `BuildingModel/BuildingModelRepository.php`.
- Create: `migrations/2026_08_10_000300_consolidate_estimate_project_model_v2.php` as a forward-only migration.
- Create: `Domain/ProjectModel/ProjectModelRepository.php`.
- Test: `tests/Feature/EstimateGeneration/ProjectModel/ProjectModelV2PostgresContractTest.php`.

**Interfaces:** все IDs scoped by `organization_id`, `project_id`, `session_id`, `source_version`; evidence immutable; current fact projection versioned.

- [ ] Написать schema/contract tests для помещения, стены, проёма, размера, материала, оборудования и количества.
- [ ] Добавить явное `origin`: `document | ai_inference | user_assumption | ai_technology_recommendation | unresolved`.
- [ ] Реализовать conflict detection без автоматического сокрытия конфликтов источником с большим приоритетом.
- [ ] Мигрировать callers существующего `BuildingModel` на шесть канонических понятий; удалить DTO и typed lists без отдельной доменной ответственности.
- [ ] Проверить organization/project ABAC и отсутствие cross-tenant IDs.
- [ ] Запустить unit contract tests; PostgreSQL schema tests только в разрешённом test/CI окружении; PHPStan изменённого модуля.
- [ ] Commit: `refactor[backend]: упрощена доказательная модель AI-сметы`.

### Task 13: Связывать факты между листами и документами

**Files:**
- Create: `Application/Understanding/CrossDocumentFactLinker.php`
- Create: `Application/Understanding/TargetedConflictResolver.php`
- Create: `Application/Understanding/ProjectUnderstandingResult.php`
- Test: `tests/Unit/EstimateGeneration/Understanding/CrossDocumentFactLinkerTest.php`

- [ ] Написать сценарии: номер помещения ↔ экспликация; план ↔ разрез по осям; оборудование ↔ спецификация; материал фасада ↔ ведомость отделки.
- [ ] Реализовать deterministic candidate matching до AI arbitration: stable keys, номера, листы, оси, native IDs.
- [ ] Вызывать AI arbitration только для неоднозначных связей и сохранять evidence обеих сторон.
- [ ] Не переводить unresolved conflict в confirmed fact.
- [ ] Формировать exception с понятным вопросом и вариантами источников.
- [ ] Запустить linker/resolver tests и PHPStan.
- [ ] Commit: `feat[backend]: связаны факты проектной документации`.

---

## Этап 5. Комплектность и умные технологические рекомендации

### Task 14: Ввести каталог технологических систем

**Files:**
- Create: `Planning/TechnologySystem.php`
- Create: `Planning/TechnologySystemCatalog.php`
- Create: `Planning/TechnologySystemOption.php`
- Create: `Planning/TechnologyRecommendationService.php`
- Create config/data source for versioned technology systems.
- Test: `tests/Unit/EstimateGeneration/Planning/TechnologyRecommendationServiceTest.php`.

**Interfaces:**

```php
TechnologyRecommendationService::recommend(
    ProjectModel $model,
    UnresolvedDecision $decision,
    OrganizationPreferenceContext $preferences,
): TechnologyRecommendation;
```

Recommendation содержит recommended option, 2–3 alternatives, materials, work packages, FSNB candidates, quantity formulas, regional price availability, cost preview, risks, assumptions и explanation.

- [ ] Написать RED-сценарий неизвестного материала скатной кровли.
- [ ] Добавить варианты металлочерепицы, гибкой черепицы и фальцевой системы как полные technology systems, не одиночные материалы.
- [ ] Ранжировать по совокупности проектных фактов; история организации — только дополнительный фактор.
- [ ] Запретить автоматическое применение рекомендации; выбор создаёт `user_assumption` decision.
- [ ] Добавить option `other` и `leave_unresolved`.
- [ ] Запустить recommendation tests и PHPStan.
- [ ] Commit: `feat[backend]: добавлены технологические рекомендации AI-смет`.

### Task 15: Выявлять отсутствующие технологически необходимые работы

**Files:**
- Create: `Planning/ProjectCompletenessAnalyzer.php`
- Create: `Planning/CompletenessRule.php`
- Create: `Planning/CompletenessFinding.php`
- Create: `Planning/TechnologyWorkPackageBuilder.php`
- Test: `tests/Unit/EstimateGeneration/Planning/ProjectCompletenessAnalyzerTest.php`.

- [ ] Написать сценарии выравнивания участка, подготовки основания, гидроизоляции, лесов, крепежа, вывоза отходов, испытаний и восстановления благоустройства.
- [ ] Разделить `document_missing`, `technology_required`, `optional_recommendation` и `not_applicable`.
- [ ] Для каждого finding сохранять evidence, применённое правило/AI reasoning, влияние и возможность исключения с подтверждением.
- [ ] Строить work package с полным составом работ, материалов, механизмов и norm intents.
- [ ] Не создавать позицию без quantity formula или явного unresolved quantity.
- [ ] Запустить completeness tests и PHPStan.
- [ ] Commit: `feat[backend]: добавлена проверка комплектности AI-смет`.

---

## Этап 6. Детерминированный черновик обычной сметы МОСТ

### Task 16: Рассчитывать объёмы только из доказательных фактов и решений

**Files:**
- Modify: `Quantities/*` canonical calculators.
- Create: `Quantities/DerivedQuantityFactory.php`
- Create: `Quantities/QuantityReadiness.php`
- Test: `tests/Unit/EstimateGeneration/Quantities/DerivedQuantityFactoryTest.php`.

- [ ] Написать формулы площади пола, стен с вычетом проёмов, скатной кровли, земляных работ и технологического work package.
- [ ] Сохранять operand-level evidence, units и rounding policy.
- [ ] Запретить final persistence quantity без confirmed inputs или explicit user assumption.
- [ ] Возвращать unresolved inputs вместо оценочного числа.
- [ ] Проверить permutation stability и source-coordinate conversions.
- [ ] Запустить quantity trust tests и PHPStan.
- [ ] Commit: `feat[backend]: сформированы доказательные объемы AI-смет`.

### Task 17: Подобрать ФСНБ, региональные цены и записать обычную смету

**Files:**
- Modify: `Services/NormativeWorkItemPlannerService.php`.
- Modify: `Services/Normatives/NormativeCandidateSelectionService.php`.
- Modify: `Pricing/ResolveRegionalPrice.php`.
- Modify: `Services/EstimatePricingService.php`.
- Modify: `Application/Apply/GeneratedEstimateWriter.php`.
- Modify: `Application/Apply/LaravelGeneratedEstimateWriter.php`.
- Modify: `Services/EstimateDraftPersistenceService.php`.
- Create: `Application/Generation/BuildMostEstimateDraft.php`
- Test: `tests/Feature/EstimateGeneration/OrdinaryEstimateDraftWorkflowTest.php`.

- [ ] Написать end-to-end test: facts → technology system → quantities → FSNB → regional prices → ordinary Estimate rows.
- [ ] Сохранить source formula, norm identity, price source, technology decision и evidence links в metadata обычной строки.
- [ ] AI reranker не может обходить normative hard gates и compatibility rules.
- [ ] Missing norm/price формирует exception, а не фиктивную итоговую стоимость.
- [ ] Повторная публикация одного artifact hash не создаёт вторую смету.
- [ ] Запустить целевой feature test, normative safety tests и PHPStan; не запускать миграции.
- [ ] Commit: `feat[backend]: AI-черновик записывается в обычную смету МОСТ`.

---

## Этап 7. Проверка по исключениям и AI-диалог

Frontend-работа выполняется в отдельной ветке `prohelper_admin` от свежего `origin/main`. Backend и admin коммиты не смешиваются.

### Task 18: Создать очередь существенных исключений

**Backend Files:**
- Create: `Http/Resources/EstimateReviewExceptionResource.php`
- Create: `Application/Review/ListEstimateReviewExceptions.php`
- Modify: `Http/Controllers/EstimateGenerationReviewController.php`.
- Modify: `Http/Requests/ListEstimateGenerationReviewItemsRequest.php`.
- Modify: module routes registered by `EstimateGenerationServiceProvider.php`.

**Admin Files:**
- Modify: `src/features/estimate-generation/review/ReviewCockpit.tsx`
- Create: `src/features/estimate-generation/review/ExceptionQueue.tsx`
- Create: `src/features/estimate-generation/review/TechnologyRecommendationCard.tsx`
- Create: `src/features/estimate-generation/review/CostImpactSummary.tsx`
- Modify: `src/features/estimate-generation/test/handlers.ts`.
- Create: `src/features/estimate-generation/review/ExceptionQueue.test.tsx`.
- Create: `src/features/estimate-generation/review/TechnologyRecommendationCard.test.tsx`.
- Modify: `src/features/estimate-generation/api/estimateGenerationApi.test.ts`.

- [ ] Зафиксировать API filters: severity, floor, room, section, origin, cost impact, unresolved type.
- [ ] По умолчанию показывать только conflicts, missing required data, low confidence и technology recommendations.
- [ ] Сортировать сначала по блокирующему влиянию и стоимости, а не по порядку строк.
- [ ] Открывать точный лист/область/native reference одним действием.
- [ ] Незакрытые вопросы не запрещают экспорт, но формируют completeness summary.
- [ ] Запустить backend review API tests; в admin — целевой Vitest, ESLint/Prettier изменённых файлов и `npx tsc --noEmit`; build не запускать.
- [ ] Commits: `feat[backend]: добавлена очередь исключений AI-сметы` и `feat[admin]: добавлена проверка исключений AI-сметы`.

### Task 19: Добавить подтверждаемый AI-диалог управления черновиком

**Backend Files:**
- Create: `Application/Dialogue/InterpretEstimateCommand.php`
- Create: `Application/Dialogue/EstimateChangeProposal.php`
- Create: `Application/Dialogue/PreviewEstimateChange.php`
- Create: `Application/Dialogue/ApplyEstimateChangeProposal.php`
- Create FormRequests/controllers/resources for preview/apply/cancel.
- Test: `tests/Feature/EstimateGeneration/EstimateDialogueWorkflowTest.php`.

**Admin Files:**
- Create: `src/features/estimate-generation/dialogue/EstimateAssistantPanel.tsx`
- Create: `ChangeProposalPreview.tsx`
- Create: typed API/MSW handlers and Vitest scenarios.

**Interfaces:** proposal содержит immutable ID, interpreted intent, before/after, affected facts, decisions, work packages, estimate rows, cost delta, assumptions, expiry и source command.

- [ ] Написать RED-сценарии: объяснить рекомендацию; заменить кровельную систему; исправить площадь; отменить proposal; повторно применить один proposal; stale model version.
- [ ] Любая команда создаёт proposal и никогда не мутирует draft напрямую.
- [ ] Apply выполняет ABAC, optimistic version check и использует decision journal из Task 7.
- [ ] После apply пересчитываются только dependency keys proposal.
- [ ] UI всегда показывает «что изменится» и требует явной кнопки подтверждения.
- [ ] Запустить backend feature tests; admin Vitest/MSW, lint changed files и `npx tsc --noEmit`.
- [ ] Commits: `feat[backend]: добавлен подтверждаемый диалог AI-сметы` и `feat[admin]: добавлен диалог с AI-сметчиком`.

---

## Этап 8. Обучение, benchmark, экономика и выпуск

### Task 20: Собрать доверенный эталонный корпус всех форматов

**Files:**
- Create: `tests/Fixtures/EstimateGeneration/v2-corpus/manifest.json`
- Create: corpus builder/validator under `Benchmark` or new `Evaluation` namespace.
- Create: `tests/Unit/EstimateGeneration/Evaluation/EvaluationCorpusManifestTest.php`.

- [ ] Включить обезличенные PDF, scan/image, DXF/DWG и XLSX cases.
- [ ] Для каждого case зафиксировать expected sheet roles, entities, facts, conflicts, recommendations, user decisions, quantities, norms и key estimate rows.
- [ ] Разделить development и sealed holdout sets по source-family hash.
- [ ] Запретить попадание holdout examples в prompt examples/RAG.
- [ ] Проверять provenance completeness и отсутствие персональных/секретных данных.
- [ ] Запустить manifest validation и corpus privacy tests.
- [ ] Commit: `test[backend]: добавлен корпус качества AI-сметчика`.

### Task 21: Ввести release metrics качества, времени и стоимости

**Files:**
- Create: `Evaluation/EstimateAssistantScorecard.php`
- Create: `Evaluation/EstimateAssistantReleaseGate.php`
- Modify: existing production replay benchmark adapter.
- Test: `tests/Unit/EstimateGeneration/Evaluation/EstimateAssistantReleaseGateTest.php`.

**Metrics:** entity precision/recall, critical fact recall, conflict recall, unsupported fact rate, quantity error, norm acceptance, missing-work recall, operator corrections, time-to-draft, AI cost p50/p90/p99 и session failure rate.

- [ ] Написать RED gate: версия не проходит при ухудшении critical recall, unsupported facts, quantity error или cost ceiling.
- [ ] Не сводить качество к одному среднему score; критические safety metrics имеют отдельные hard gates.
- [ ] Сравнивать current candidate с действующей production baseline на одинаковом corpus manifest.
- [ ] Формировать человекочитаемый report с regression cases и cost distribution.
- [ ] Запустить release gate tests и production replay в разрешённом benchmark окружении.
- [ ] Commit: `feat[backend]: добавлен шлюз выпуска AI-сметчика`.

### Task 22: Проверить пользовательский сценарий и определить фактическую цену

**Files:**
- Create: `docs/estimate-generation/ai-estimate-v2-acceptance.md`
- Create: `docs/estimate-generation/ai-estimate-v2-economics.md`
- Modify price config только отдельным решением после измерений.

- [ ] Пройти сценарий небольшой компании: оператор загружает комплект, ждёт фоновой обработки, разрешает исключения, выбирает рекомендацию, использует диалог и получает обычную смету.
- [ ] Проверить ноутбук и узкий экран; полный построчный review не должен требоваться.
- [ ] Измерить время оператора и сметчика, долю исправленных строк, число вопросов и полноту результата.
- [ ] Рассчитать себестоимость p50/p90/p99 по типу и размеру проекта, включая диалог и повторные проверки.
- [ ] Сопоставить себестоимость с 10 включёнными генерациями и целевой ценой дополнительной генерации около 500 ₽.
- [ ] Не менять цену автоматически; оформить отдельное бизнес-решение с предлагаемой ценой и запасом на p90.
- [ ] Commit: `docs[backend]: зафиксирована приемка AI-сметчика`.

### Task 23: Выполнить финальный release gate без субагентов

**Files:**
- Modify cleanup/acceptance/economics evidence.
- No product changes unless fixing a validated finding.

- [ ] Проверить отсутствие незакоммиченных изменений и соответствие веток свежему `origin/main`.
- [ ] Выполнить один финальный самостоятельный review-pass backend diff и отдельно admin diff.
- [ ] Проверить architecture boundary: удалённая инфраструктура не зарегистрирована и не запланирована.
- [ ] Выполнить минимальный агрегированный набор новых unit/feature/architecture tests без дублирования уже доказанных контрактов.
- [ ] Выполнить PHPStan изменённых backend-модулей, admin `npx tsc --noEmit`, целевой Vitest и lint изменённых файлов; frontend build не запускать.
- [ ] Выполнить read-only production readiness audit только при необходимости фактических production данных; не изменять production и не запускать миграции.
- [ ] Зафиксировать незапущенные долгие/DB-dependent проверки как CI gates с точными командами и причиной.
- [ ] Подготовить отдельные PR backend/admin с русскими Conventional Commit messages и порядком deploy cleanup migrations.
- [ ] Commit: `docs[backend]: завершена проверка AI-сметчика v2`.

## Definition of Done

- Старый план и старая спецификация удалены; этот план является единственным источником исполнения AI-сметчика v2.
- Cleanup matrix не содержит незавершённых `DELETE` runtime-контуров.
- В production-коде нет внутренней финансовой бухгалтерии AI-попыток.
- Нет изменяющей административной failure-resolution платформы.
- Нет lease/online-migration MLOps runtime без доказанной эксплуатации.
- Нет дублирующихся document adapter contracts и параллельных источников истины.
- Есть один pipeline runner/checkpoint path и одна идемпотентная публикация обычной сметы.
- Пользовательская квота равна 10 + купленные генерации; одна сессия списывает максимум одну единицу.
- Поддерживаются PDF, images, DWG/DXF и XLSX через native + visual representations.
- AI извлекает и связывает доказательные факты, выявляет пробелы и предлагает технологические системы.
- Технологически необходимые работы включаются с объяснимым происхождением и возможностью подтверждённого исключения.
- Пользователь проверяет исключения, а не всю огромную смету.
- Все AI-команды на изменение требуют preview и явного подтверждения.
- Черновик записывается как обычная смета МОСТ с формулами, ФСНБ, ценами и provenance.
- Evaluation corpus, release gates и экономика доказаны на всех заявленных форматах.
- Ни один этап реализации или ревью не использовал субагентов.

## Порядок выполнения после сжатия контекста

После возобновления работы основной агент обязан:

1. Прочитать полностью этот план и спецификацию.
2. Проверить текущую ветку и `git status` обоих затрагиваемых репозиториев.
3. Найти первый незакрытый checkbox сверху вниз.
4. Выполнять только соответствующий Task до его тестов, самостоятельного review-pass и commit.
5. Не переходить через Gate этапа 1 при оставшейся legacy-инфраструктуре.
6. Не создавать субагентов даже для «быстрого» поиска или независимого ревью.
7. Не повторять уже успешные проверки, если покрываемый код после них не менялся.
8. Обновлять checkbox и evidence сразу после доказанного результата, а не по памяти в конце.
