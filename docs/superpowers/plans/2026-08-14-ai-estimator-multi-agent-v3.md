# Мультиагентный AI-сметчик МОСТ v3 — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` to implement this plan task-by-task in one task. `superpowers:subagent-driven-development` is permitted only for independent review of completed major blocks, never for primary implementation. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Заменить текущий ограничивающий контур AI-сметчика на канонический восьмиролевой AI-конвейер с тремя независимыми наблюдателями, арбитражем, внутренней геометрией и моделью проекта, составлением и аудитом сметы, предметными вопросами и совместной правкой оператором.

**Architecture:** Три независимых Vision-наблюдения сохраняются как immutable role runs и объединяются арбитром в существующий ProjectModelRepository/Evidence. Геометрический эксперт, инженер модели, составитель и аудитор используют один canonical snapshot; BigDecimal, normative gates, pricing, ABAC и proposal/Decision boundaries остаются детерминированными. Старый targeted/manual geometry/building-model пользовательский контур удаляется после cutover без fallback и параллельного legacy пути.

**Tech Stack:** PHP 8.2, Laravel 11, PostgreSQL 16, Horizon, S3/FileService, Timeweb AI Gateway, Brick Math/BigDecimal, React/Vite/TypeScript, Vitest/MSW, PHPUnit/Larastan/Pint.

## Global Constraints

- Реализация выполняется в одной свежей Codex-задаче с одной активной целью до полного завершения.
- Работать только в новых backend/admin worktrees от актуальных `origin/main`; остановленный `fix/vision-semantic-upgrade-backfill` не изменять и не удалять.
- Не cherry-pick незавершённый stopped diff; переносить только доказанно нужные идеи и fixtures после чтения diff.
- Не добавлять новую инфраструктуру: workflows, CI services, secrets, environments, hosts, Kubernetes, MLOps, scheduler, storage или отдельную бухгалтерию AI-вызовов.
- Не добавлять feature flags, fallback model, legacy fallback, compatibility shim или второй authoritative pipeline.
- Использовать существующие Horizon queues, PostgreSQL, FileService/S3, AI usage journal, quota service, ABAC, Evidence, ProjectModelRepository, Decisions/proposals, normative/pricing и draft persistence.
- Одна пользовательская генерация списывает одну квоту; внутренние вызовы только агрегируются в существующем журнале стоимости.
- `.env` authoritative для модели и лимитов; defaults в config обязаны быть валидируемыми.
- Пользовательские тексты только через `trans_message`; raw provider text, английские labels и внутренние enum keys в API/UI запрещены.
- Исторические migrations не изменять; schema cleanup только новой forward-only migration после отсутствия readers/writers.
- Не запускать local working-DB migrations, dev servers и frontend build.
- Не выполнять платные production AI smoke/retry. После deploy проверять только workflow success, `/ready`, `release.json` и read-only логи.
- После каждого крупного блока: минимально достаточные tests, один независимый review, исправление findings, стандартный PR/merge/deploy и продолжение в той же задаче.
- Коммиты на русском Conventional Commits; backend scope по принятому формату проекта, admin обязательно `[admin]`.

---

## Карта файлов и границ

### Backend — новые канонические элементы

- `app/BusinessModules/Addons/EstimateGeneration/Analysis/Role/AiAnalysisRole.php` — enum восьми batch-ролей.
- `app/BusinessModules/Addons/EstimateGeneration/Analysis/DTO/AiRoleRunInput.php` — immutable subject/snapshot/render/contract identity.
- `app/BusinessModules/Addons/EstimateGeneration/Analysis/DTO/AiRoleRunResult.php` — bounded output, provenance, usage/failure reference.
- `app/BusinessModules/Addons/EstimateGeneration/Analysis/AiRoleRunRepository.php` и `app/BusinessModules/Addons/EstimateGeneration/Analysis/EloquentAiRoleRunRepository.php` — exactly-once persisted role runs.
- `app/BusinessModules/Addons/EstimateGeneration/Analysis/RunAiAnalysisRole.php` — единый orchestration boundary поверх существующего Vision provider/physical attempts.
- `app/BusinessModules/Addons/EstimateGeneration/Analysis/Observers/` — три независимых prompt/input builders без доступа к соседним результатам.
- `app/BusinessModules/Addons/EstimateGeneration/Analysis/Arbitration/` — semantic matching, quorum evidence и arbitration provider contract.
- `app/BusinessModules/Addons/EstimateGeneration/Analysis/Geometry/` — AI geometry interpretation и deterministic BigDecimal calculator.
- `app/BusinessModules/Addons/EstimateGeneration/Analysis/Synthesis/` — запись единой модели через ProjectModelRepository.
- `app/BusinessModules/Addons/EstimateGeneration/Analysis/Composition/` — AI work-intent composer поверх CanonicalTechnologyWorkItemPlanner/PlanWorkItemsStage.
- `app/BusinessModules/Addons/EstimateGeneration/Analysis/Audit/` — независимый draft auditor и максимум два correction cycles.
- `app/BusinessModules/Addons/EstimateGeneration/Questions/` — предметные вопросы, рекомендации, choices и Decision application.
- `app/BusinessModules/Addons/EstimateGeneration/Http/Presentation/AnalysisBasisPayloadService.php` — read-only основания расчёта.
- новая migration `create_estimate_generation_ai_role_runs` и финальная cleanup migration.

### Backend — существующие точки интеграции

- `Application/Documents/ProductionDocumentUnitProcessor.php`
- `Application/Documents/Understanding/SheetAnalysisOperationJournal.php`
- `Vision/Providers/TimewebVisionProvider.php`
- `Application/Sessions/AdvanceEstimateGeneration.php`
- `BuildingModel/*` и canonical `ProjectModelRepository` реализации
- `Pipeline/Stages/PlanWorkItemsStage.php`
- `Services/EstimatePricingService.php`
- `Services/EstimateDraftPersistenceService.php`
- существующие review/change proposal/Decision services и routes
- `app/BusinessModules/Addons/EstimateGeneration/routes.php`

### Admin — целевой пользовательский процесс

- `src/features/estimate-generation/model/steps.ts`
- `components/EstimateGenerationStepper.tsx`
- `pages/EstimateGenerationWorkspacePage.tsx`
- `steps/DocumentsStep.tsx`
- новые `steps/QuestionsStep.tsx` и `questions/*`
- `steps/DraftStep.tsx`, `review/ReviewCockpit.tsx`
- новый `basis/AnalysisBasisDrawer.tsx`
- API contracts/normalizers/MSW handlers
- удалить обязательное подключение `GeometryReviewStep.tsx` и `BuildingModelStep.tsx`; оставить только переиспользуемые read-only визуальные компоненты, если они нужны drawer.

---

## Major Block A — канонический анализ документов и удаление старого semantic choke point

### Task 1: Изолировать работу, зафиксировать baseline и разобрать остановленный diff

**Files:**
- Copy into backend worktree: `docs/specs/ai-estimator-multi-agent-v3.md`
- Copy into backend worktree: `docs/superpowers/plans/2026-08-14-ai-estimator-multi-agent-v3.md`
- Read only: `C:/Users/kamilgaraev/Desktop/prohelper_full/prohelper/.worktrees/fix-vision-semantic-upgrade-backfill/**`
- Create: `docs/specs/ai-estimator-v3-removal-manifest.md`

**Interfaces:**
- Consumes: актуальные backend/admin `origin/main`, stopped worktree diff.
- Produces: точный keep/replace/delete manifest, которому следуют все последующие задачи.

- [ ] **Step 1: Создать активную цель**

Вызвать `create_goal` с objective: `Полностью реализовать, проверить и по крупным блокам штатно выпустить мультиагентный AI-сметчик МОСТ v3 по утверждённой спецификации, без fallback и лишней инфраструктуры.`

- [ ] **Step 2: Создать чистые worktrees**

Обновить refs безопасным `git fetch`, создать backend ветку `feat/ai-estimator-v3-multi-agent` и admin ветку `feat/ai-estimator-v3-workspace` от соответствующих `origin/main`. Не использовать текущие пользовательские checkouts.

- [ ] **Step 3: Проверить baseline без дорогих прогонов**

Выполнить `git status --short`, `git diff --check`, targeted architecture tests AI-сметчика и admin typecheck/test discovery. Не запускать full suite дольше пяти минут, DB migrations или build.

- [ ] **Step 4: Прочитать stopped diff и создать removal manifest**

Manifest обязан перечислить:

```text
KEEP: safe provider error capture, physical attempt state machine, breaker, retry lineage,
      accepted render/evidence fixtures, Russian presentation idea.
REIMPLEMENT: semantic version identity, provider-free reuse of accepted observation,
             concrete question presentation.
DELETE/DO NOT PORT: second authoritative semantic projection, generic review strings,
                    narrow pre-arbitration fact rejection, legacy fallback and duplicate routing.
```

Для каждого production class старого manual geometry/building-model/targeted path указать `keep internal`, `replace`, или `delete after cutover` и назвать его readers/routes/tests.

- [ ] **Step 5: Закоммитить документацию**

```powershell
git add docs/specs/ai-estimator-multi-agent-v3.md docs/superpowers/plans/2026-08-14-ai-estimator-multi-agent-v3.md docs/specs/ai-estimator-v3-removal-manifest.md
git commit -m "docs: утверждён план мультиагентного AI-сметчика"
```

### Task 2: Добавить минимальное хранилище AI-ролей

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/Analysis/Role/AiAnalysisRole.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Analysis/Role/AiRoleRunStatus.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Analysis/DTO/AiRoleRunInput.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Analysis/DTO/AiRoleRunResult.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Analysis/AiRoleRunRepository.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Analysis/EloquentAiRoleRunRepository.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Models/EstimateGenerationAiRoleRun.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/migrations/2026_08_14_000100_create_estimate_generation_ai_role_runs.php`
- Test: `tests/Unit/EstimateGeneration/Analysis/AiRoleRunContractTest.php`
- Test: `tests/Feature/EstimateGeneration/Analysis/AiRoleRunPostgresTest.php`

**Interfaces:**
- Produces: `claim(AiRoleRunInput, ownerUuid): AiRoleRunClaim`, `complete(runId, result)`, `fail(runId, typedFailure)`, `loadCurrent(subject, role)`.
- Identity: tenant/project/session/document/source version/page/role/model/prompt contract/input fingerprint.

- [ ] **Step 1: Написать RED unit и PostgreSQL tests**

Проверить восемь enum roles, exact replay, concurrent claim, stale pre-wire takeover, ambiguous post-wire, immutable completed result, tenant isolation, bounded JSON bytes и source-version separation.

- [ ] **Step 2: Запустить RED**

Запустить только два новых test-файла; ожидаем отсутствие классов/table contract.

- [ ] **Step 3: Реализовать одну таблицу и repository**

Таблица содержит `id`, scope IDs, `subject_type/id/version`, `role`, `status`, `model`, `prompt_contract_version`, `input_fingerprint`, `physical_attempt_id`, bounded `result_payload jsonb`, `failure_code`, owner/lease timestamps и обычные timestamps. Не создавать role-specific таблицы.

- [ ] **Step 4: Запустить GREEN, php-l и Pint по файлам задачи**

- [ ] **Step 5: Закоммитить**

```powershell
git commit -m "feat: добавлен единый журнал ролей AI-сметчика"
```

### Task 3: Реализовать три действительно независимых наблюдения

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/Analysis/Observers/ObserverProfile.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Analysis/Observers/ObserverInputBuilder.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Analysis/Observers/LiteralObserverPrompt.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Analysis/Observers/ConstructionObserverPrompt.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Analysis/Observers/RiskObserverPrompt.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Analysis/Observers/RunIndependentObservers.php`
- Modify: `Vision/Providers/TimewebVisionProvider.php`
- Modify: `Application/Documents/ProductionDocumentUnitProcessor.php`
- Test: `tests/Unit/EstimateGeneration/Analysis/IndependentObserversTest.php`
- Test: `tests/Unit/EstimateGeneration/Vision/TimewebVisionProviderTest.php`

**Interfaces:**
- Consumes: verified render/native/text/vector representations and pinned model config.
- Produces: three `AiRoleRunResult` values with free observations plus bounded normalized claims/evidence; no observer receives another observer output.

- [ ] **Step 1: Написать RED independence tests**

Доказать три разных prompt contract hashes, три isolated request contexts, отсутствие чужих outputs в payload, разные deterministic image compositions, один pinned model, independent physical attempts и сохранение полного наблюдения при неизвестном semantic type.

- [ ] **Step 2: Добавить production-shaped fixture страниц 4, 17, 18**

Fixture должен сохранять условный фундамент, таблицу проёмов, материалы/примечания и визуальные наблюдения без generic `needs clarification`.

- [ ] **Step 3: Реализовать orchestration через существующие physical attempts/usage journal**

Не создавать новую HTTP integration и не дублировать error inspector/breaker. Три jobs могут выполняться параллельно существующей очередью, но каждая role run остаётся idempotent.

- [ ] **Step 4: Запустить targeted GREEN и resource-budget tests**

Проверить page boundary, payload bytes, images, output tokens, retry и cost aggregation.

- [ ] **Step 5: Закоммитить**

```powershell
git commit -m "feat: добавлены три независимых анализа документов"
```

### Task 4: Реализовать арбитра с evidence, кворумом и мнением меньшинства

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/Analysis/Arbitration/ObservationClaim.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Analysis/Arbitration/ClaimSemanticMatcher.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Analysis/Arbitration/ArbitrationInputBuilder.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Analysis/Arbitration/ArbitrationDecision.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Analysis/Arbitration/RunDocumentArbitration.php`
- Modify: `Vision/ProjectSheetAnalysisValidator.php`
- Modify: `BuildingModel/ProjectModelEvidenceWriter.php`
- Test: `tests/Unit/EstimateGeneration/Analysis/DocumentArbitrationTest.php`
- Test: `tests/Feature/EstimateGeneration/Analysis/DocumentArbitrationPostgresTest.php`

**Interfaces:**
- Consumes: exactly three completed observer runs and original source locator registry.
- Produces: accepted/candidate/unresolved claims written once to canonical Evidence/ProjectModelRepository plus concrete questions.

- [ ] **Step 1: Написать RED scenarios**

Покрыть `3/3 + same evidence`, `2/3 + stronger minority evidence`, `1/3 unique valid note`, three-way conflict, semantically equal synonyms, identical unsupported guesses, stale source and cross-tenant locator.

- [ ] **Step 2: Реализовать semantic matching без строкового majority vote**

Арбитр обязан видеть original render/crop и проверять minority. Provider возвращает decision intent; сервер разрешает только allowlisted observer/evidence IDs и проверяет scope/source version.

- [ ] **Step 3: Ослабить только pre-arbitration semantic choke point**

`ProjectSheetAnalysisValidator` сохраняет JSON shape/bounds/source integrity, но не отклоняет неизвестное профессиональное наблюдение. Confirmed status разрешается только арбитражному output.

- [ ] **Step 4: Запустить GREEN и regression старых incident fixtures**

- [ ] **Step 5: Закоммитить**

```powershell
git commit -m "feat: добавлен доказательный арбитраж документов"
```

### Task 5: Заменить document readiness и вопросы на канонический результат арбитража

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/Questions/EstimateClarificationQuestion.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Questions/EstimateClarificationChoice.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Questions/ClarificationQuestionProjector.php`
- Modify: `Application/Sessions/BuildSessionSnapshot.php`
- Modify: `Application/Sessions/AdvanceEstimateGeneration.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Http/Resources/EstimateGenerationDocumentResource.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Http/Resources/EstimateGenerationDocumentDetailResource.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Services/Documents/DocumentUnderstandingSummaryBuilder.php`
- Add translations: `lang/ru/estimate_generation.php`
- Test: document/session resource tests and PostgreSQL replay test.

**Interfaces:**
- Produces: machine code, Russian translation params, subject/source, impact, recommendation, bounded choices, `other`, `leave_unresolved`.

- [ ] **Step 1: Написать RED на страницы 3–4 и 18–19**

Нет raw English/internal enums/generic duplicates; conditional foundation становится одним предметным вопросом; сведения страницы 4 отвечают за материалы страниц 18–19; визуализация помечается как corroboration, а не confirmed quantity.

- [ ] **Step 2: Реализовать projector и readiness**

Готовность зависит от завершения трёх observers + arbiter и наличия обязательных unresolved decisions, не от количества элементов/старого targeted status.

- [ ] **Step 3: Удалить старый authoritative targeted semantic path**

Удалить его вызовы из `ProductionDocumentUnitProcessor`, router/readiness и DI. Сохранить только общие provider/error/attempt primitives. Удалить production-классы и тесты, отмеченные `delete` в removal manifest.

- [ ] **Step 4: Запустить module regression, PostgreSQL exact replay, php-l/Pint/Larastan**

- [ ] **Step 5: Закоммитить**

```powershell
git commit -m "refactor: заменён старый семантический контур AI-сметчика"
```

### Task 6: Выпустить Major Block A

**Files:** backend diff Tasks 1–5.

- [ ] **Step 1: Провести одно независимое correctness/security/architecture review**

Субагенту разрешено только read-only review завершённого блока. Исправить все P0/P1 и подтверждённые P2; после исправлений повторить только затронутые tests.

- [ ] **Step 2: Финальный release gate блока**

DB-free targeted suite, isolated PostgreSQL suite без skip, php-l, Pint, targeted Larastan или честный bootstrap blocker, UTF-8 и `git diff --check`.

- [ ] **Step 3: Стандартный backend PR/merge/deploy**

Не менять workflows/secrets/infrastructure. Дождаться success, проверить `/ready`, `release.json` и read-only логи. Не запускать AI document smoke.

- [ ] **Step 4: Обновить ветку следующего блока от deployed main**

Закрыть Block A в goal progress, но не завершать цель.

---

## Major Block B — внутренние геометрия и модель проекта, новый UX без лишних вкладок

### Task 7: Реализовать AI-геометра поверх арбитражного snapshot

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/Analysis/Geometry/GeometryExpertInput.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Analysis/Geometry/GeometryExpertResult.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Analysis/Geometry/RunGeometryExpert.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Analysis/Geometry/DeterministicGeometryCalculator.php`
- Reuse/Modify: `Vision/Geometry/*`, `Quantities/*`, DerivedQuantity services.
- Test: `tests/Unit/EstimateGeneration/Analysis/GeometryExpertTest.php`
- Test: `tests/Feature/EstimateGeneration/Analysis/GeometryExpertPostgresTest.php`

**Interfaces:**
- Consumes: arbitrated facts/evidence and applicable original sheets.
- Produces: formula IDs, canonical decimal operands/results, evidence, conflicts and unresolved geometry questions.

- [ ] **Step 1: RED на планы/разрезы/кровлю/проёмы**

Проверить размерные цепочки, масштабы, межлистовые расхождения, partial openings, duplicate physical locators, decimal boundaries и non-geometry sheets skipped without AI call.

- [ ] **Step 2: Реализовать AI interpretation + deterministic BigDecimal arithmetic**

Модель не возвращает денежные значения и не выполняет итоговую арифметику. Все результаты имеют formula/version/source lineage.

- [ ] **Step 3: Запустить GREEN и PostgreSQL current/history concurrency tests**

- [ ] **Step 4: Закоммитить**

```powershell
git commit -m "feat: добавлена внутренняя AI-проверка геометрии"
```

### Task 8: Реализовать инженера единой модели проекта

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/Analysis/Synthesis/ProjectSynthesisInput.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Analysis/Synthesis/RunProjectSynthesis.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Analysis/Synthesis/ProjectSynthesisValidator.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Domain/ProjectModel/ProjectModelRepository.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Domain/ProjectModel/EloquentProjectModelRepository.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Understanding/ProjectUnderstandingCoordinator.php`
- Modify/Delete: legacy `BuildingModelRepository`, bridge/store paths per removal manifest.
- Test: `tests/Unit/EstimateGeneration/Analysis/ProjectSynthesisTest.php`
- Test: `tests/Feature/EstimateGeneration/Analysis/ProjectSynthesisPostgresTest.php`

**Interfaces:**
- Consumes: current arbitration + geometry role-run fingerprints for all included source versions.
- Produces: one atomic current project model projection, history and concrete cross-document questions.

- [ ] **Step 1: RED cross-document fixtures**

Материал кровли с общих данных + площадь с плана кровли + визуальное подтверждение; conditional foundation; repeated openings; replaced source version; conflicting floor identity; exact replay.

- [ ] **Step 2: Реализовать synthesis в одной authoritative repository boundary**

Не создавать новый model store. Input/output fingerprints включают all source versions, arbitration, geometry, Decisions и contract version.

- [ ] **Step 3: Удалить второй authoritative building-model path**

Перевести оставшихся readers на ProjectModelRepository и удалить bridge/store/controller code только после graph/route search, подтверждающего отсутствие production consumers.

- [ ] **Step 4: GREEN + PostgreSQL atomic projection tests**

- [ ] **Step 5: Закоммитить**

```powershell
git commit -m "refactor: унифицирована внутренняя модель проекта AI-сметчика"
```

### Task 9: Удалить обязательные geometry/building-model стадии API и readiness

**Files:**
- Delete/Modify: `Http/Controllers/EstimateGenerationGeometryController.php`
- Delete/Modify: `Http/Controllers/EstimateGenerationBuildingModelController.php`
- Delete/Modify: geometry confirmation Requests/Application services/outbox writers.
- Create: `Http/Presentation/AnalysisBasisPayloadService.php`
- Create: read-only source/basis endpoint Request/Controller.
- Modify: routes, mutation policy, session snapshot, permissions translations only if endpoint permission changes.
- Test: route/resource/ABAC tests.

**Interfaces:**
- Produces: read-only basis lookup by work item/question/quantity with page locator and internal geometry/model explanation.

- [ ] **Step 1: RED API tests**

Session no longer exposes mandatory confirm geometry/model actions; old mutation endpoints are absent; basis endpoint is tenant/ABAC scoped and bounded.

- [ ] **Step 2: Реализовать read-only basis API и удалить mutation gates**

- [ ] **Step 3: Удалить obsolete outbox/confirmation writers and bindings**

Не удалять shared geometry math/evidence code, используемый Task 7.

- [ ] **Step 4: GREEN + architecture no-reference tests**

- [ ] **Step 5: Закоммитить**

```powershell
git commit -m "refactor: геометрия и модель скрыты за основаниями расчёта"
```

### Task 10: Перестроить admin на пять пользовательских стадий

**Files:**
- Modify: `src/features/estimate-generation/model/steps.ts`
- Modify: `components/EstimateGenerationStepper.tsx`
- Modify: `pages/EstimateGenerationWorkspacePage.tsx`
- Modify: `steps/DocumentsStep.tsx`
- Create: `steps/QuestionsStep.tsx`
- Create: `questions/ClarificationQuestionCard.tsx`
- Create: `basis/AnalysisBasisDrawer.tsx`
- Remove mandatory imports/routes: `steps/GeometryReviewStep.tsx`, `steps/BuildingModelStep.tsx`
- Modify API contracts/normalizers/test fixtures/MSW.

**Interfaces:**
- Five steps: object, documents, questions, draft, release review.
- Basis drawer is optional and opened from source links; no manual geometry/model completion action.

- [ ] **Step 1: RED Vitest/MSW tests**

Проверить five-step navigation, current statuses, concrete Russian question/choices/recommendation, no English/raw enum, basis drawer, empty questions auto-advance, system failure and retry.

- [ ] **Step 2: Реализовать exhaustive typed UI**

Удалить misleading percentages as trust signal. Progress отображает завершённые внутренние stages/pages, а confidence — человекочитаемое evidence basis.

- [ ] **Step 3: Удалить старые mandatory components/tests/normalizers**

Read-only canvas/tree компоненты можно перенести в basis drawer; не оставлять unreachable code.

- [ ] **Step 4: Vitest/MSW, `npx tsc --noEmit`, changed-file ESLint/Prettier**

- [ ] **Step 5: Закоммитить**

```powershell
git commit -m "feat[admin]: упрощён процесс работы с AI-сметой"
```

### Task 11: Выпустить Major Block B

- [ ] **Step 1: Одно независимое review backend+admin блока**

- [ ] **Step 2: Исправить findings и выполнить минимальную регрессию**

- [ ] **Step 3: Стандартно выпустить backend, затем совместимый admin**

Дождаться обоих deploy success; проверить SHA/ready/read-only logs без AI smoke.

- [ ] **Step 4: Обновить следующие ветки от deployed main**

---

## Major Block C — составитель, аудитор и совместная работа оператора

### Task 12: Реализовать AI-составителя поверх существующего нормативного pipeline

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/Analysis/Composition/EstimateComposerInput.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Analysis/Composition/EstimateWorkIntent.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Analysis/Composition/RunEstimateComposer.php`
- Modify: `Pipeline/Stages/PlanWorkItemsStage.php`
- Reuse: `app/BusinessModules/Addons/EstimateGeneration/Planning/CanonicalTechnologyWorkItemPlanner.php`, normative hard gates, resource assembly, pricing and draft persistence.
- Test: `tests/Unit/EstimateGeneration/Analysis/EstimateComposerTest.php`
- Test: `tests/Feature/EstimateGeneration/Analysis/EstimateComposerPostgresTest.php`

**Interfaces:**
- Consumes: exact current ProjectModel/Decision/geometry snapshot.
- Produces: bounded work intents with source, technology package candidate, assumptions and exclusions; provider prices are ignored.

- [ ] **Step 1: RED end-to-end composition fixtures**

Фундамент, стены, кровля, фасады, проёмы, подготовка участка, доставка/подъём, леса, отходы и missing-document recommendations. Проверить отсутствие дублей и невозможность нулевой догадки при missing norm/price.

- [ ] **Step 2: Реализовать composer до existing hard gates**

- [ ] **Step 3: Проверить exact decimal quantities/prices and snapshot fence**

- [ ] **Step 4: GREEN + PostgreSQL persistence tests**

- [ ] **Step 5: Закоммитить**

```powershell
git commit -m "feat: добавлен AI-составитель черновика сметы"
```

### Task 13: Реализовать независимый аудит и максимум два correction cycles

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/Analysis/Audit/EstimateAuditInput.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Analysis/Audit/EstimateAuditFinding.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Analysis/Audit/RunEstimateAudit.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Analysis/Audit/ApplyComposerCorrectionCycle.php`
- Modify: workflow advancement/checkpoints.
- Test: `tests/Unit/EstimateGeneration/Analysis/EstimateAuditTest.php`
- Test: `tests/Feature/EstimateGeneration/Analysis/EstimateAuditPostgresTest.php`

**Interfaces:**
- Produces: accepted audit, typed findings, deterministic correction proposal; cycle counter `0..2` in role-run identity.

- [ ] **Step 1: RED omissions/duplicates/units/coverage tests**

- [ ] **Step 2: Реализовать независимый auditor prompt/context**

Auditor не получает hidden composer reasoning, но видит canonical model, draft, evidence and source navigation.

- [ ] **Step 3: Реализовать bounded correction loop**

После двух циклов оставшиеся material findings становятся операторскими review items; бесконечный dispatch невозможен.

- [ ] **Step 4: GREEN + concurrent replay tests**

- [ ] **Step 5: Закоммитить**

```powershell
git commit -m "feat: добавлен независимый аудит AI-сметы"
```

### Task 14: Завершить предметные вопросы и диалоговые изменения сметы

**Files:**
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Dialogue/InterpretEstimateCommand.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Dialogue/ApplyEstimateChangeProposal.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Dialogue/PreviewEstimateChange.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Dialogue/CancelEstimateChangeProposal.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Dialogue/EstimateProposalMutationExecutor.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Infrastructure/Dialogue/EstimateChangeProposalRepository.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Questions/AnswerEstimateClarification.php`
- Modify: Decision application/reanalysis path.
- Modify: safe DTO resources and translation registry.
- Test: DB-free dialogue tests and PostgreSQL apply/cancel/race tests.

**Interfaces:**
- Answer boundary: actor context, session, question key, choice/other/unresolved, idempotency key.
- Dialogue boundary: natural-language command → deterministic preview → explicit apply → exact snapshot reanalysis.

- [ ] **Step 1: RED question and dialogue scenarios**

Выбор рекомендованного варианта, другое значение, leave unresolved, stale question, cross-tenant, retry after timeout, source navigation, cost delta recalculation and undo history.

- [ ] **Step 2: Реализовать один Decision/proposal path**

Не создавать второй mechanism для вопросов. Answer и dialogue command должны приводить к существующей canonical mutation/reanalysis boundary.

- [ ] **Step 3: GREEN + real PostgreSQL concurrency tests**

- [ ] **Step 4: Закоммитить**

```powershell
git commit -m "feat: завершена совместная правка сметы с AI"
```

### Task 15: Завершить admin draft, audit и AI dialogue UX

**Files:**
- Modify: `steps/DraftStep.tsx`
- Modify: `review/ReviewCockpit.tsx`
- Create: `dialogue/EstimateCopilotPanel.tsx`
- Create: `dialogue/ProposalDiff.tsx`
- Modify: API contracts/normalizers/MSW handlers.
- Test: component/MSW tests.

- [ ] **Step 1: RED operator workflow tests**

Проверить вопрос → ответ → reanalysis; команда → preview diff → apply/cancel; audit findings; source navigation; history/undo; retry disposition; partial/unknown cost without zero fabrication.

- [ ] **Step 2: Реализовать UX без технических терминов**

- [ ] **Step 3: Vitest/MSW, TypeScript, ESLint, Prettier**

- [ ] **Step 4: Закоммитить**

```powershell
git commit -m "feat[admin]: добавлена совместная работа со сметой через AI"
```

### Task 16: Финально удалить ненужный legacy-код и schema

**Files:**
- Use: `docs/specs/ai-estimator-v3-removal-manifest.md`
- Delete all remaining files marked `delete after cutover`.
- Create: forward-only migration `2026_08_14_000900_remove_obsolete_estimate_generation_review_contours.php` only for tables/constraints with zero production readers/writers.
- Add architecture tests prohibiting removed routes/bindings/classes.

- [ ] **Step 1: Повторить graph/route/reference audit**

Каждый удаляемый class/table должен иметь ноль current production readers/writers. Явные кандидаты cutover: `estimate_generation_sheet_analysis_operations`, `estimate_generation_geometry_regeneration_outbox`, `estimate_generation_geometry_confirmations`, `estimate_generation_building_model_evidence` и `estimate_generation_building_models`. Shared evidence, usage, physical attempts, canonical `estimate_generation_project_model_*`, Decisions and packages не удалять.

- [ ] **Step 2: Удалить legacy code/config/routes/translations/tests**

Удалить unreachable admin geometry/model workflow code, targeted semantic authority, duplicate building model repository/bridge and obsolete confirmation/outbox code, подтверждённые manifest.

- [ ] **Step 3: Добавить forward-only schema cleanup**

Migration fail-fast проверяет ожидаемые definitions пяти перечисленных legacy tables перед drop и не изменяет historical migrations. Если call-graph Task 1 докажет, что конкретная таблица всё ещё является каноническим shared storage, её reader сначала переводится в Tasks 5/8/9; таблица не оставляется параллельным legacy-контуром. PostgreSQL migration contract запускается в isolated harness.

- [ ] **Step 4: Запустить architecture/no-reference/PostgreSQL cleanup tests**

- [ ] **Step 5: Закоммитить**

```powershell
git commit -m "refactor: удалена устаревшая инфраструктура AI-сметчика"
```

### Task 17: Финальный release gate и выпуск Major Block C

- [ ] **Step 1: Одно независимое итоговое review полного Block C и сквозного workflow**

- [ ] **Step 2: Исправить findings и повторить только затронутую регрессию**

- [ ] **Step 3: Выполнить финальные проверки**

Targeted backend suites всех восьми ролей, isolated PostgreSQL without skip, resource/API parity, admin Vitest/MSW, TypeScript, changed-file lint/format, php-l/Pint/targeted Larastan, UTF-8, `git diff --check`.

- [ ] **Step 4: Стандартные backend/admin PR, merge и deploy**

Дождаться success. Проверить deployed SHAs, `/ready`, `release.json`, protected endpoints and read-only logs. Не выполнять production AI smoke/retry.

### Task 18: Синхронизировать workflow-документацию после фактического выпуска

**Files/Systems:** YouTrack Knowledge Base through workflow-sync tools.

- [ ] **Step 1: Найти существующие статьи МОСТ об AI-сметчике**

Искать `AI-сметчик`, `смета`, `генерация сметы`, `проверка геометрии`, `модель здания` и читать plausible matches.

- [ ] **Step 2: Обновить или создать пакет статей**

Минимум: `Workflow AI-сметчика`, `Статусная модель AI-сметчика`, `UX сценарии admin AI-сметчика`, `Как работать с AI-сметчиком`. Описать пять пользовательских стадий, вопросы, совместную правку, failure/retry, историю и основания расчёта. Не описывать внутренние восемь ролей как действия пользователя.

- [ ] **Step 3: Проверить бизнес-читаемость и release truth**

- [ ] **Step 4: Пометить цель complete**

Вызвать `update_goal(status="complete")` только после успешных deploy и документации. Итог сообщить с PR/SHA/deploy links, тестами, удалённой инфраструктурой, остаточными рисками и инструкцией пользователю для первого ручного прогона.

---

## Self-review результата плана

- Spec coverage: восемь AI-ролей, независимость, minority evidence, geometry/model internalization, composer/auditor, dialogue, ABAC, cost, retry, cleanup, UX и phased deploy имеют отдельные tasks.
- Placeholder scan: план не содержит незаполненных шагов; оптимизация числа observer calls явно исключена из scope текущего выпуска.
- Type consistency: role-run identity используется всеми стадиями; arbitration пишет в canonical Evidence/ProjectModelRepository; geometry/synthesis/composer/auditor читают exact current snapshots; questions/dialogue используют одну Decision/proposal boundary.
- Infrastructure check: одна новая универсальная role-runs table; нет новой внешней инфраструктуры и нет fallback.
- Release check: три крупных deploy block, без платных smoke-тестов.
