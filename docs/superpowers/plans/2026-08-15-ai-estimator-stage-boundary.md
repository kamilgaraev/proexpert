# План реализации границы этапов AI-сметчика

**Цель:** устранить 500 и потерю частичного состояния session 72/document 174, вынести вопросы из document processing в Questions AI и ввести согласованные document/session cost guards без показа технической стоимости пользователю.

**Ограничения:** без субагентов, платных AI-вызовов, frontend build, локальных миграций/DB-команд, изменений CI/workflows/secrets/infrastructure и ручных production writes.

## 1. RED: граница документа и частичный read

- Добавить production-shaped unit/feature regression для legacy payload с `choices=[]` и `choices>8`.
- Зафиксировать отсутствие вопросов в document detail/readiness/summary.
- Добавить malformed observation/claim isolation regression.
- Запустить только новые/непосредственно связанные тесты и подтвердить ожидаемый RED.

## 2. Backend: удалить document question path

- Удалить `ClarificationQuestionProjector` и все его document/readiness зависимости.
- Удалить `ai_questions`, `document_arbitration.questions`, `ai_question_count` и question-driven semantic/readiness state из document runtime и DTO.
- Сохранять conflicts/unknowns/limitations/evidence как document facts.
- Сделать detail projection item-tolerant при строгих scope/source fences.

## 3. Backend: канонические Questions AI

- Перевести catalog/source на `ProjectModelRepository::currentUnderstanding()` после объединения комплекта.
- Добавить bounded projector исключительно в Questions namespace: 0..8 AI-вариантов + системные «Другое» и «Оставить нерешённым».
- Сохранить optimistic snapshot/source-version/fact validation при ответе.
- Обновить readiness/snapshot так, чтобы документы не зависели от вопросов, а Questions AI зависел от current merged understanding.

## 4. Backend: stop/reconcile/progress

- Добавить regression session72-shaped 2 completed + 1 stopped after wire + 19 superseded.
- После stop вызывать aggregate reconciliation; разрешать уже начатому unit опубликовать результат без downstream dispatch.
- Разделить execution progress и usefulness в list/detail/snapshot; terminal stop не оставляет session `processing/5%`.
- Прогнать штатный PostgreSQL contract harness для stop/reconcile/read без skip.

## 5. Backend: cost hierarchy

- Добавить RED boundary tests default/env/confirmed ceiling и суммарного session usage нескольких документов/downstream roles.
- Ввести config keys/defaults и `.env.example` из спецификации.
- Хранить подтверждение session ceiling в существующем persisted `analysis_payload`; изменение схемы и миграция не требуются.
- Проверять document и session ceiling атомарно до каждого physical wire; unknown pricing fail-closed.
- Добавить идемпотентное подтверждение session/document ceiling и resumable user-action state, отдельно от месячной quota.
- Убрать public `cost_journal`, сохранив внутренние usage/journal readers и observability.

## 6. Admin

- Синхронизировать DTO/normalizers: удалить document questions/cost journal.
- Удалить рублёвую строку и цены из confirmation/pause copy; оставить честные status/execution/usefulness.
- Добавить MSW one-shot 500 → cached detail preserved → final 200 regression.
- Обновить stop → refresh production-shaped fixtures/tests.
- Запустить целевые Vitest/MSW, `tsc --noEmit`, ESLint/Prettier только для изменённых файлов; build не запускать.

## 7. Проверка и выпуск

- Один пропорциональный backend test set, PostgreSQL harness, PHPStan изменённого модуля.
- Одно последовательное self-review полного backend/admin diff; исправить только подтверждённые замечания и повторить затронутые проверки.
- Обновить существующую статью YouTrack без дубликата.
- Создать русские Conventional Commits, push, backend/admin PR, дождаться CI, merge и штатного deploy.
- Read-only canary session 72/document 174 и GlitchTip/log evidence; цель завершить только после подтверждённого production результата.
