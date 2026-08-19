# AI-сметчик: устранение адверсариальных findings — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Устранить четыре подтверждённых дефекта identity, quantity, sink context и повторных arbiter decisions без изменения внешнего API и без новых AI-вызовов.

**Architecture:** Канонизация остаётся в `VisualObjectIdentity`, а conservative deterministic reduction — в `VisualInventoryProjector`. Изменения закрываются unit property/permutation regressions, затем существующими downstream, PostgreSQL replay и offline full-PDF gates.

**Tech Stack:** PHP 8.2+, Laravel 11, PHPUnit 11, Larastan/PHPStan, Pint, штатный PostgreSQL contract harness.

**Spec:** `docs/specs/2026-08-19-ai-estimator-production-reconciliation.md`

## Global Constraints

- Backend base: `origin/main` at `e5f91aa994dd6e7e0680dd0c37a8536cedb065c3` after fetch.
- Admin base: `origin/main` at `1a0567e23380f899a1d324d8e60c49022bff6c6d`; менять admin только при невозможности исправить контракт в backend.
- Без миграций, локальных artisan DB-команд, внешних AI/Vision-вызовов и frontend build.
- Сохранить tenant/project/session/document/page/source-version/evidence boundaries и русские пользовательские labels.
- Исполнение выполняется inline без субагентов по прямому указанию пользователя.

---

### Task 1: Canonical visual identity

**Files:**
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Analysis/Arbitration/VisualObjectIdentity.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/BuildingModel/ProjectModelEvidenceWriter.php`
- Test: `tests/Unit/EstimateGeneration/Analysis/VisualObjectIdentityTest.php`

**Interfaces:**
- Consumes: `identity(string $category, string $entityKey, string $label): string`.
- Produces: context-aware `objectType()` and canonical numeric ordinal identity.

- [ ] Добавить RED-таблицы `1/01/001`, `1/2`, absent/1, numeric/non-numeric, RU/EN bathroom/kitchen aliases, unknown sink context и разные room/object scopes.
- [ ] Запустить только новые тесты и зафиксировать ожидаемые failures текущей реализации.
- [ ] Канонизировать чисто числовой suffix как integer string; не менять нечисловые suffixes.
- [ ] Разрешать generic sink через canonical room/category context, unknown оставлять нейтральным.
- [ ] Запустить тесты identity до GREEN.

### Task 2: Explicit count semantics

**Files:**
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Documents/VisualInventoryProjector.php`
- Test: `tests/Unit/EstimateGeneration/Documents/VisualInventoryProjectorTest.php`

**Interfaces:**
- Consumes: observer claim `value.data`.
- Produces: `quantity:?int`, `quantity_uncertain:bool`, conservative downstream scope.

- [ ] Добавить RED cases для `60 см`, `600 мм`, `Ø50`, `Модель 60`, `арт. 123`, `11 100 мм`, `22.10 м²` и явных `2 мойки`, `унитаз — 1 шт.`, `3 окна`.
- [ ] Зафиксировать ложные текущие quantities и RED.
- [ ] Заменить свободное число на bounded explicit-count parser без float heuristic.
- [ ] Проверить conflict/omission как `null + uncertain` и GREEN.

### Task 3: Deterministic duplicate decision reduction

**Files:**
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Documents/VisualInventoryProjector.php`
- Test: `tests/Unit/EstimateGeneration/Documents/VisualInventoryProjectorTest.php`

**Interfaces:**
- Consumes: multiset `arbitration.decisions` grouped by `claim_id`.
- Produces: canonical decision, limitation and lineage independent of order/grouping.

- [ ] Добавить RED для всех permutations дубликатов accepted/conditional/rejected/ambiguous и canonical byte equality.
- [ ] Зафиксировать last-write-wins RED.
- [ ] Реализовать conservative commutative/associative reduction с deterministic tie-break и union lineage.
- [ ] Проверить fresh/historical-shaped inputs и GREEN.

### Task 4: Adjacent contracts and offline replay

**Files:**
- Test: `tests/Unit/EstimateGeneration/Understanding/VisualInventoryQuestionBuilderTest.php`
- Test: `tests/Unit/EstimateGeneration/Documents/ProductionRoleResultPublicationReplayTest.php`
- Test: `tests/Feature/EstimateGeneration/Pipeline/AtomicDocumentUnitPublicationPostgresTest.php`
- Test: `tests/Feature/EstimateGeneration/Pipeline/FullPdfAiEstimatorPostgresE2ETest.php`

**Interfaces:**
- Consumes: canonical visual inventory.
- Produces: stable questions, persisted/replayed output and unchanged provider/usage/cost/quota/draft state.

- [ ] Запустить минимальные identity/inventory/questions/replay unit suites.
- [ ] Запустить только затронутый PostgreSQL publication/replay contract через существующий harness.
- [ ] Запустить offline full-PDF gate для `ar (1).pdf` recordings и проверить 22/22 terminal, page 5 quantities/types, replay 0 calls и неизменность accounting/hash.

### Task 5: Quality, review and release

**Files:**
- Modify only confirmed P0–P2 findings from the sequential review.

**Interfaces:**
- Produces: verified backend release with no admin change unless contract evidence requires it.

- [ ] Выполнить `php -l` для изменённых PHP, targeted Pint и targeted Larastan/PHPStan.
- [ ] Провести последовательный correctness/security/architecture/UX review diff и исправить подтверждённые P0–P2.
- [ ] Повторить только затронутые checks и один final relevant gate.
- [ ] Сделать русский Conventional Commit, push, PR, штатный merge/deploy без CI/infrastructure changes.
- [ ] Выполнить read-only canary: exact release SHA, `/ready`, protected `401`, свежие production logs/GlitchTip; платный AI smoke не запускать.
