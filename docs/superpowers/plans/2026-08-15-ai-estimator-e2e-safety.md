# AI Estimator End-to-End Safety Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Доказать и выпустить согласованный backend/admin сценарий AI-сметчика МОСТ без потери полезных ответов и неконтролируемых повторных расходов.

**Architecture:** Чистый observer/arbiter publication payload публикуется атомарно store-слоем под claim/source/version fence. Stop и cost ceiling являются scoped lineage state и проверяются перед dispatch, claim и provider wire. Admin отображает один согласованный snapshot и сохраняет stale detail при refetch-ошибке.

**Tech Stack:** PHP 8.2+, Laravel 11, PostgreSQL 16 contract harness, PHPUnit 11, React 18, TypeScript, TanStack Query 4, Vitest 2, MSW 2.

**Global constraints:** Без платных AI-вызовов, production writes, ручных миграций, новых сервисов/очередей/секретов/workflows, frontend build и повторного запуска документа 173. Исторические миграции неизменны.

## Block A — Atomic result publication

- [x] Добавить PostgreSQL RED-тест точного пути `ProcessDocumentUnit -> ProductionDocumentUnitProcessor -> RunIndependentObservers -> publish`, воспроизводящий self-lineage `unit_claim_lost`.
- [x] Ввести типизированный publication payload с наблюдениями, арбитражем, вопросами и page projection; исключить authoritative writes из observer runtime.
- [x] Под одной транзакцией `EloquentDocumentProcessingUnitStore` проверить claim/tenant/project/document/source/retry ownership и сохранить evidence, projections и terminal unit state.
- [x] Обеспечить идемпотентный replay собственного publication identity и fail-closed для stale/cross-tenant/concurrent writer.
- [x] Доказать recovery после `response_received` с нулём дополнительных provider wire calls.
- [x] Классифицировать `document_unit_pre_wire_failed` и `vision_provider_response_invalid`; сохранять валидные элементы частично невалидного ответа.
- [x] Удалить конкурирующие runtime validators/stores и согласовать terminal/partial агрегатор.
- [x] Запустить DB-free unit tests, PostgreSQL concurrency/replay tests, PHPStan по изменённым PHP-файлам; провести одно read-only review и исправить подтверждённые замечания.
- [ ] Создать отдельный backend-коммит блока A.

Основные файлы: `ProcessDocumentUnit.php`, `ProductionDocumentUnitProcessor.php`, `RunIndependentObservers.php`, `ProjectModelEvidenceWriter.php`, `EloquentDocumentProcessingUnitStore.php`, `EloquentDocumentUnitAggregateReconciler.php`, `DocumentProcessingOutcomeResolver.php`, PostgreSQL tests/support.

## Block B — Stop and financial safety

- [ ] Добавить forward-only migration для scoped stop/pause/cost confirmation contract и обновить защищённый test inventory.
- [ ] Реализовать тонкий controller/FormRequest, service action и стандартизированный API response с ABAC и идемпотентностью.
- [ ] Проверять stop/paused state перед dispatch, claim и provider wire; корректно завершать pending/pre-wire units и разрешать публикацию уже wire-started unit без downstream dispatch.
- [ ] Расширить scoped systemic breaker: два одинаковых fingerprint или доказанный каскад останавливают только текущую lineage.
- [ ] Добавить configurable per-attempt/document cost ceiling с приоритетом `.env`, безопасным documented default и атомарной проверкой journal до wire.
- [ ] Подтвердить/release пользовательскую месячную quota reservation на продуктовой границе, сохранив provider cost в журнале.
- [ ] Покрыть PostgreSQL race-сценарии stop/dispatch, stop/claim, stop/after-wire, repeated stop, retry и month/quota boundaries.
- [ ] Выполнить целевые проверки, одно read-only review и отдельный backend-коммит блока B.

## Block C — Honest admin UX

- [ ] Расширить typed backend/admin contract: execution progress, usefulness, lineage state и finance journal.
- [ ] Добавить действие «Остановить обработку» с подтверждением сохранения готовых результатов.
- [ ] Отображать terminal/total отдельно от полезности и стоимости; исключить 0% при terminal units.
- [ ] Разделить list/detail/analysis/mutation errors; сохранить cached detail при background refetch error по контракту TanStack Query 4.
- [ ] Поддержать cancelled/paused/partial/system_failure без противоречивых badges и ограничить вопросы/выпуск до готовности.
- [ ] Добавить production-shaped MSW/Vitest fixtures, включая snapshot документа 173.
- [ ] Запустить targeted Vitest/MSW, `tsc --noEmit`, ESLint/Prettier изменённых файлов; провести одно read-only review.
- [ ] Создать согласованные admin и при необходимости backend-коммиты блока C.

Основные файлы: `estimateGenerationContracts.ts`, `estimateGenerationDocumentNormalizers.ts`, `estimateGenerationApi.ts`, `useEstimateGenerationPolling.ts`, `DocumentList.tsx`, `DocumentDetailsPanel.tsx`, `DocumentsStep.tsx`, MSW fixtures/tests.

## End-to-end release gate

- [ ] Прогнать все 22 страницы реального `ar (1).pdf` через штатный renderer, canonical routing, записанные обезличенные observer/arbiter doubles и реальный PostgreSQL без внешнего provider.
- [ ] Для каждой страницы выдать production-shaped ответы её маршрута: 1 observer для простой, 3 observers + arbiter для содержательной; зафиксировать per-page и total logical/physical spy counts.
- [ ] Доказать полный happy-path: 22/22 terminal units, 0 `unit_claim_lost`, 0 преждевременно terminal, 0 потерянных полезных outputs и replay/recovery без нового physical wire.
- [ ] После 22/22 пройти downstream до предметных вопросов из реальных расхождений и ненулевого sourced draft с серверными нормами, ценами и арифметикой.
- [ ] Отдельно доказать partial-invalid isolation, partial system failure, stop и ceiling, не смешивая injected failures с полным happy-path.
- [ ] Передать admin production-shaped snapshot полного 22-страничного результата и проверить согласованные execution/usefulness/cost counters.
- [ ] Прогнать bounded synthetic 50+ pages/multi-document suite на backpressure, память, очередь, stop и стоимость.
- [ ] Выполнить последовательный self-review, минимальные итоговые проверки и обновить существующие статьи YouTrack без дубликатов.
- [ ] Создать backend/admin PR, дождаться CI, merge и штатного deploy одним согласованным релизом.
- [ ] После deploy read-only проверить `/ready`, release SHA, 401 без JWT, GlitchTip и production logs без AI-запуска.
- [ ] Подготовить mapping defect → root cause → fix → regression и закрыть активную цель только после полного gate.
