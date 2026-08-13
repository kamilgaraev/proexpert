# Recoverable Document Retry Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Восстановить безопасный повтор исправимого terminal-сбоя документа и корректную document-first навигацию AI-сметчика МОСТ.

**Architecture:** Laravel остаётся источником capability и рекомендуемого шага. Retry выполняет повторную проверку под блокировкой, создаёт новую lineage только для нового пользовательского key после terminal attempt и публикует job after commit; React использует typed snapshot/capability и state-scoped idempotency key.

**Tech Stack:** PHP 8.2/Laravel 11/PostgreSQL/PHPUnit; React/Vite/TypeScript/Vitest/MSW.

## Global Constraints

- Не запускать production retry документа 168 и платный AI.
- Не запускать миграции, локальные DB-команды, dev server или admin build.
- Все backend user messages через `trans_message`.
- Сохранить append-only history, tenant/ABAC/source/state fences и after-commit dispatch.

---

### Task 1: Backend eligibility и lifecycle

**Files:**
- Modify: `tests/Unit/EstimateGeneration/Documents/ExplicitDocumentRetryEligibilityTest.php`
- Modify: `tests/Feature/EstimateGeneration/ExplicitDocumentRetryHttpContractTest.php`
- Modify: `tests/Feature/EstimateGeneration/Pipeline/ExplicitDocumentRetryPostgresContractTest.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Documents/ExplicitDocumentRetryEligibility.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Documents/RetryEstimateGenerationDocument.php`

- [ ] Добавить failing tests: terminal previous attempt доступен для нового key; active attempt и unsafe failures закрыты; exact old key replay не dispatch; new key создаёт lineage, append history и переводит session/document в processing/queued.
- [ ] Запустить узкие PHPUnit тесты и зафиксировать ожидаемый RED.
- [ ] Убрать history-wide deny, заменить его проверкой current attempt status/source и сохранить повторную lock-проверку.
- [ ] Запустить DB-free/API тесты до GREEN.
- [ ] Расширить PostgreSQL contract на terminal lineage → new lineage, exact replay и concurrency; выполнить штатным wrapper с 0 skip.

### Task 2: Canonical snapshot

**Files:**
- Modify: `tests/Unit/EstimateGeneration/Workflow/BuildSessionSnapshotTest.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Sessions/SessionSnapshotData.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Sessions/BuildSessionSnapshot.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Sessions/BuildSessionOperationalSnapshot.php`

- [ ] Добавить failing snapshot test для `failed/resume processing_documents/document system failure/0 ready` с `recommended_step=documents` и отсутствием downstream capability.
- [ ] Запустить тест и подтвердить RED из-за отсутствующего поля/неверного шага.
- [ ] Добавить backward-compatible `recommended_step` в DTO и определить его из session/document recovery semantics.
- [ ] Запустить snapshot/readiness regressions до GREEN.

### Task 3: Admin navigation и retry identity

**Files:**
- Modify: `src/features/estimate-generation/api/estimateGenerationContracts.ts`
- Modify: `src/features/estimate-generation/api/estimateGenerationNormalizers.ts`
- Modify: `src/features/estimate-generation/model/steps.ts`
- Modify: `src/features/estimate-generation/model/steps.test.ts`
- Modify: `src/features/estimate-generation/pages/EstimateGenerationWorkspacePage.tsx`
- Modify: `src/features/estimate-generation/pages/EstimateGenerationWorkspacePage.test.tsx`
- Modify: `src/features/estimate-generation/documents/documentRetryIdempotency.ts`
- Modify: `src/features/estimate-generation/steps/DocumentsStep.tsx`
- Modify: `src/features/estimate-generation/steps/DocumentsStep.test.tsx`

- [ ] Добавить failing tests для production-shaped snapshot, server recommended step, 0/22 downstream block и remount/new-terminal key behavior.
- [ ] Запустить только новые/затронутые Vitest cases и подтвердить RED.
- [ ] Типизировать/нормализовать `recommended_step`, применить его при выборе active step и сохранить legacy document fallback.
- [ ] Включить capability state identity в storage key, очищать key только при однозначно завершённом/обновлённом server state.
- [ ] Запустить целевые Vitest/MSW до GREEN, сохранив input-review и valid summary regressions.

### Task 4: Verification, review и выпуск

**Files:** Все изменённые файлы двух репозиториев.

- [ ] Выполнить backend PHPUnit/PostgreSQL wrapper, `php -l`, Pint, минимальный PHPStan/Larastan, UTF-8 и `git diff --check`.
- [ ] Выполнить admin Vitest/MSW, `tsc --noEmit`, ESLint/Prettier изменённых файлов, UTF-8 и `git diff --check` без build.
- [ ] Передать один завершённый backend+admin diff независимому read-only reviewer; исправить Critical/Important и повторить только затронутые проверки.
- [ ] Создать русские Conventional Commits, push, PR, squash merge и дождаться штатных deploy workflows backend/admin.
- [ ] Провести read-only canary `/ready`, release JSON, unauthenticated 401, logs/GlitchTip и document 168 snapshot; не вызывать retry.
