# AI-сметчик МОСТ v3 Trust Boundary Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Перенести authoritative сериализацию и scope/provenance ownership с AI на сервер, сохранив per-item partial success и строгие safety/calculation/publication gates.

**Architecture:** Bounded transport создаёт безопасный payload, role-specific ingestion независимо разбирает intents, server projection строит canonical fields из allowlisted context, а строгие validators проверяют уже серверную проекцию. Breaker реагирует только на transport/safety failures; downstream роли используют тот же принцип references-in/canonical-fields-out.

**Tech Stack:** PHP 8.2, Laravel 11, PostgreSQL, PHPUnit, Larastan; React/Vite/TypeScript/Vitest/MSW только при подтверждённом изменении admin-контракта.

## Global Constraints

- Не запускать миграции, tinker, локальные DB-команды, dev servers и frontend builds.
- Реальные PostgreSQL contract/concurrency tests запускать через существующий изолированный test contour.
- Не добавлять инфраструктуру, feature flags, fallback models или отдельные хранилища.
- Ровно один read-only reviewer после полной реализации и зелёных целевых тестов.
- После deploy не запускать документ 171, новые сметы или платные Vision-вызовы.

---

### Task 1: Зафиксировать call path и RED production fixtures

**Files:**
- Modify: `tests/Unit/EstimateGeneration/Analysis/DocumentArbitrationTest.php`
- Modify: `tests/Unit/EstimateGeneration/Vision/TimewebVisionProviderTest.php`
- Modify: `tests/Unit/EstimateGeneration/ProductionDocumentUnitProcessorTest.php`

**Interfaces:**
- Consumes: production-shaped observer/arbiter payloads и текущие `VisionDocumentInput`, `ObservationClaim`.
- Produces: исполняемые требования к tolerant ingestion, canonical projection, quarantine и breaker isolation.

- [ ] Добавить fixtures двух arbiter-ответов и observer payload, включая uppercase code, Unicode reason, AI locator/canonical copies.
- [ ] Добавить отдельные тесты unknown evidence, stale/cross-scope, mixed valid/invalid intents и malformed/oversized/injection.
- [ ] Запустить только новые тесты и подтвердить ожидаемые failures текущего all-or-nothing контракта.

### Task 2: Разделить ingestion, projection и strict validation арбитра

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/Analysis/Contracts/AiIntentIngestionResult.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Analysis/Contracts/AiIntentQuarantine.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Analysis/Arbitration/ArbitrationIntentIngestor.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Analysis/Arbitration/ArbitrationDecisionProjector.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Analysis/Arbitration/ArbitrationDecision.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Analysis/Arbitration/RunDocumentArbitration.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Analysis/Arbitration/ArbitrationInputBuilder.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Vision/RoleVisionResponseCanonicalizer.php`

**Interfaces:**
- Consumes: `list<array<string,mixed>>` provider intents, `list<ObservationClaim>`, authoritative `VisionDocumentInput`.
- Produces: `AiIntentIngestionResult<ArbitrationDecision>` с accepted decisions и typed quarantine; canonical question/locator/claim.

- [ ] Ingestor bounded-проверяет каждый item и не принимает provider server-owned copies как authority.
- [ ] Projector строит canonical claim, question code/locator и audit reason из allowlist/context.
- [ ] Strict decision validation сохраняет evidence/source safety и explicit-evidence rule.
- [ ] `RunDocumentArbitration` сохраняет валидные decisions, quarantine и partial state без all-or-nothing `array_map`.
- [ ] Удалить v2/v3 repair-костыли и canonical-claim repair из общего canonicalizer.
- [ ] Запустить RED-набор и существующие arbitration/observer tests до GREEN.

### Task 3: Применить boundary к geometry, synthesis, composer и auditor

**Files:**
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Analysis/Geometry/*`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Analysis/Synthesis/*`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Analysis/Composition/*`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Analysis/Audit/*`
- Modify: соответствующие `tests/Unit/EstimateGeneration/Analysis/*Test.php`

**Interfaces:**
- Consumes: AI-owned refs/text/formula/work/finding intents.
- Produces: server-owned identities/locators plus per-item quarantine; deterministic quantities/norms/prices остаются downstream.

- [ ] Для каждой роли сначала добавить failing test на provider server-owned copies и mixed valid/invalid list.
- [ ] Проецировать IDs/locators/codes сервером из current allowlist.
- [ ] Сохранять fail-closed unknown/stale/cross-scope refs и duplicate physical accounting.
- [ ] Подтвердить, что provider quantity/rate/total не пересекает deterministic gates.

### Task 4: Исправить breaker и document outcome

**Files:**
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Vision/Providers/TimewebVisionProvider.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Documents/ProductionDocumentUnitProcessor.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Documents/DocumentProcessingOutcomeResolver.php`
- Modify: соответствующие unit/PostgreSQL tests.

**Interfaces:**
- Consumes: typed failure class `content | transport | safety`, document/source/lineage scope.
- Produces: breaker counters только для transport/safety, partial document outcome с сохранёнными outputs.

- [ ] Добавить failing tests: два content failures не останавливают 20 страниц; repeated transport/safety может остановить только текущий scope.
- [ ] Исключить quarantined intent/content uncertainty из breaker identity.
- [ ] Сохранить ready pages и честно агрегировать ready/partial/questions/system failure.

### Task 5: Проверить admin contract и изменить только при gap

**Files:**
- Inspect/modify only if required: `prohelper_admin/src/**` AI estimator page/service/types/tests.

**Interfaces:**
- Consumes: backend resource status/counts/saved facts/questions.
- Produces: честное отображение ready/partial/questions/system failure без обязательных geometry/model tabs.

- [ ] Сопоставить backend resource с текущими TypeScript types и UI.
- [ ] При gap сначала добавить Vitest/MSW regression, затем минимальную реализацию.
- [ ] Запустить targeted Vitest, `tsc --noEmit`, ESLint/Prettier по изменённым файлам; build не запускать.

### Task 6: PostgreSQL, static checks и единственное ревью

**Files:**
- Modify: существующие PostgreSQL tests persistence/concurrency/version fences.

**Interfaces:**
- Consumes: завершённый backend/admin diff.
- Produces: доказательства exact replay, concurrency, source isolation и code quality.

- [ ] Запустить единый целевой PHPUnit/PostgreSQL набор.
- [ ] Выполнить `php -l`, Pint и Larastan по изменённому модулю.
- [ ] После зелёных проверок вызвать ровно одного read-only субагента без делегирования и production-действий.
- [ ] Лично проверить findings, исправить подтверждённые и повторить только затронутые проверки.

### Task 7: Документация, commit, PR, merge, deploy, canary

**Files:**
- Modify/create: ближайшая workflow-статья МОСТ в YouTrack Knowledge Base.

**Interfaces:**
- Consumes: проверенные изменения и единственное ревью.
- Produces: merged backend/admin SHAs, стандартный production release и read-only evidence.

- [ ] Обновить workflow-документацию по partial/questions/system failure и действиям оператора.
- [ ] Создать русские Conventional Commits с корректными scopes.
- [ ] Push, PR, дождаться checks, merge и выполнить только существующий deploy.
- [ ] Проверить `/ready`, `release.json`, protected endpoints без JWT, logs и GlitchTip read-only.
- [ ] Не инициировать AI/document processing smoke.
