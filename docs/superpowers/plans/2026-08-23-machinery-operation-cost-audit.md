# Machinery Operation Cost Audit Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Устранить доказанные дефекты сквозного контура эксплуатации техники МОСТ и подтвердить конкурентность, однократность фактов, offline-порядок и себестоимость.

**Architecture:** Backend остаётся единственным источником статусного автомата, показаний и денежных расчётов. PostgreSQL constraints дополняют сервисные блокировки для race safety; mobile хранит исходную identity команды и не пропускает зависимый хвост очереди. Интеграция себестоимости использует существующий budgeting/journal extension point, если он подтверждён трассировкой.

**Tech Stack:** PHP 8.2, Laravel 11, PostgreSQL, React 18/Vite/TypeScript, Flutter/Riverpod/Isar/Dio.

**Spec:** `docs/specs/2026-08-23-machinery-operation-cost-audit.md`

## Global Constraints

- Не запускать миграции, DB artisan/tinker, dev-серверы и frontend builds.
- Не добавлять пакеты, брокеры, deployment/CI/CD и параллельный workflow.
- Каждый исполняемый фикс проходит TDD: красный тест, минимальный код, зелёный тест.
- Все PHP user-facing сообщения используют `trans_message(...)`.
- Controllers остаются HTTP-слоем; блокировки, транзакции и расчёты находятся в service.

---

### Task 1: Защитить жизненный цикл смены и показания

**Files:** service/model/resource/controller, новая migration, backend feature tests, translations.

**Interfaces:** start создаёт единственную `draft` смену; finish атомарно переводит её в `completed`; submit принимает только `completed`; actual delta вычисляется сервером.

- [ ] Добавить красные tests для ME-001..ME-003.
- [ ] Запустить только новые tests и подтвердить ожидаемые failures.
- [ ] Добавить DB constraints/columns и минимальные service guards под row locks.
- [ ] Обновить HTTP validation/resource без клиентского вычисления.
- [ ] Запустить целевой backend набор и минимальный PHPStan.

### Task 2: Защитить топливо и ТО

**Files:** fuel/maintenance models, service/controller/resource, migration, backend tests, translations, mobile actions/repository/models.

**Interfaces:** fuel требует shift identity и совпадающие asset/project/actor boundaries; maintenance не открывается при активной смене или втором блокирующем заказе.

- [ ] Добавить красные tests ME-004/ME-005.
- [ ] Подтвердить failures.
- [ ] Реализовать server guards и schema links/constraints.
- [ ] Синхронизировать mobile contract и UI blockers.
- [ ] Запустить backend tests, mobile targeted tests/analyze и статический анализ.

### Task 3: Сделать offline-порядок устойчивым между запусками

**Files:** `lib/core/sync/*`, machinery action/repository, targeted Flutter tests.

**Interfaces:** первый unresolved queue item fences every later item; 409 имеет явное conflict состояние; replay сохраняет исходный key.

- [ ] Добавить красный тест повторного запуска после conflict и future retry.
- [ ] Подтвердить, что текущий код отправляет более позднюю команду.
- [ ] Исправить queue traversal минимальным изменением.
- [ ] Добавить/уточнить machinery offline dependency test.
- [ ] Запустить targeted Flutter tests и analyze изменённых файлов.

### Task 4: Проверить контроль допуска и связи себестоимости

**Files:** определяются только после graph trace safety → machinery → journal → budgeting.

**Interfaces:** inspections append-only и участвуют в серверном допуске; approved shift exposes one immutable cost fact linked to project/schedule/journal.

- [ ] Выполнить trace_path и прочитать существующие extension points полностью.
- [ ] Зафиксировать ME-007/ME-008 как resolved-by-existing-contract либо добавить красные contract tests.
- [ ] Реализовать только подтверждённый минимальный extension без параллельного ledger.
- [ ] Запустить targeted tests и статический анализ.

### Task 5: Синхронизировать admin/mobile contract и workflow-документацию

**Files:** typed services/types/components only where backend shape/status changed; YouTrack Knowledge Base article.

**Interfaces:** единые status/action/error codes; loading/error/empty/offline/conflict/blocked видимы пользователю.

- [ ] Обновить типы и компоненты по фактическому backend response.
- [ ] Добавить/обновить Vitest/Flutter widget tests для новых состояний.
- [ ] Запустить tsc, targeted Vitest, ESLint и mobile checks.
- [ ] Найти и обновить существующую статью YouTrack либо создать корректный пакет после реализации.

### Task 6: Финальное независимое ревью и evidence gate

**Files:** spec/evidence only, если review не выявит defects.

- [ ] Перечитать diff как новый reviewer: race, idempotency, rollback/reversal, money, org/project, N+1, audit.
- [ ] Исправить подтверждённые review findings через отдельные TDD cycles.
- [ ] Выполнить свежий минимальный полный набор релевантных проверок один раз.
- [ ] Обновить каждый ME-пункт: решение, файлы, command/output, residual risk.
- [ ] Проверить git status всех пяти репозиториев и подготовить итог без commit/push/deploy.
