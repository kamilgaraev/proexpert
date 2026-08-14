# Production-инцидент AI-сметчика МОСТ: project 52 / session 71 / document 173

Дата фиксации: 2026-08-14
Статус: реализация и выпуск
Область: backend/API и админка МОСТ

## 1. Цель

Восстановить единый контракт анализа страниц и корректную конечную машину состояний так, чтобы оплаченный успешный ответ провайдера сохранялся и использовался, локальный дефект элемента не уничтожал весь ответ, а документ и сессия не становились готовыми до фактического завершения всех units текущего source version.

Решение не добавляет запасные модели, флаги, параллельный старый контур или ручные production-операции. Старый несовместимый контракт удаляется.

## 2. Ограничения выпуска

- Никаких новых AI/Vision-вызовов, повторной обработки document 173 или новых AI-смет.
- Production используется только read-only под `codex-ro`; production writes, ручные миграции, рестарты и очистка кешей запрещены.
- Изменения выпускаются только стандартными GitHub PR и существующим deploy.
- Реальные DB-инварианты проверяются на выделенном изолированном PostgreSQL contract environment.
- Frontend build локально не выполняется.
- Fixtures не содержат изображений, prompt-ов, signed URL, секретов и необязательного исходного текста.

## 3. Обезличенные production-факты

### 3.1 Исходный объект

- project: 52;
- session: 71;
- document: 173;
- исходный файл: PDF, 22 страницы;
- source version: SHA-256 identity текущего документа;
- provider действительно вернул полезные HTTP 200 ответы.

Страница 1 содержит пригодные наблюдения о фасаде, кровле, проёмах и контексте здания. Страница 2 корректно распознана как лёгкая титульная/контекстная. Страница 3 содержит индекс листов и межлистовые связи и использует `schema_version=4`.

### 3.2 Read-only snapshot на момент диагностики

Снимок является временной точкой, а не ожидаемым итоговым состоянием:

- session status/stage: `input_review_required`, progress 35;
- document status/stage/progress: `needs_review` / `completed` / 100;
- `processed_page_count=0` при 22 страницах;
- units: 18 failed, 1 running, 3 pending;
- page states: 18 failed, 1 processing, 3 queued;
- usage: 18 строк, 117881 input tokens, 62534 output tokens, 66.566475 RUB;
- physical attempts: 16 completed, 2 ambiguous, 1 wire-started;
- document facts для этого документа отсутствуют;
- завершённые literal observer runs сохранили `result_payload`, после чего unit упал в projection/persistence;
- единый PDO fingerprint наблюдался на нескольких страницах;
- GlitchTip issue 581 зафиксировал вторичный сбой persistence наблюдаемости, включая primary failure `document_unit_pre_wire_failed`.

Эти значения перечитываются только для canary-диагностики и никогда не используются как основание для production write.

## 4. Доказанные дефекты и корневые контракты

### 4.1 Routing contract split

Provider возвращает `analysis_routing.material_risk` как enum `low|medium|high`, тогда как parser/DTO ожидает boolean. Неверный тип переводит routing в fail-open dense/unknown и инициирует ненужные тяжёлые роли.

Целевой контракт: один enum `low|medium|high` в prompt, JSON schema, parser, DTO, routing policy, persistence и тестах. Boolean не приводится и отклоняется.

### 4.2 Валидный HTTP 200 уничтожается на контейнере evidence

Production-shaped page 3 содержит evidence как keyed object:

```json
{
  "ev_title": {"key": "ev_title", "locator": {}},
  "ev_schedule": {"key": "ev_schedule", "locator": {}}
}
```

Текущий parser принимает list или keyed object без внутреннего `key`, поэтому возвращает `invalid_evidence`. Целевой parser принимает только однозначный keyed-вариант, где внешний key строго равен внутреннему `key`, затем применяет обычную серверную canonicalization и scope validation. Несовпадение ключей остаётся fail-closed.

Project-sheet facts страницы 3 имеют полезные элементы, но отдельные facts не содержат обязательный `unit`. Они должны быть карантинированы поэлементно; валидные элементы ответа сохраняются. Tenant/source/version, evidence reference, нормы, цены и арифметика остаются fail-closed.

### 4.3 Durable observer result и projection/persistence

Role run переводится в `completed` до `preserveObservation()`. Следовательно, оплаченный ответ уже является durable и должен переиспользоваться без нового provider-вызова. Projection одного неверного item должна быть атомарной: либо сохраняется весь валидный batch, либо DB не меняется; исходный role result не теряется.

PostgreSQL RED воспроизвёл `estimate_generation.project_model_entity_payload_invalid`: произвольный observer claim про кровлю проецировался как `material` с неполным payload, который закономерно отклонял строгий entity guard. Исправление допускает в каноническую модель только claims, для которых application собирает полный разрешённый entity payload; остальные остаются в durable role result для арбитража и не ломают атомарную проекцию валидных элементов. Tenant/source/evidence constraints не ослабляются.

### 4.4 Failure observability

Persistence диагностики может упасть вслед за primary failure. Целевой контракт:

- primary throwable и его typed identity всегда сохраняются в control flow;
- failure recorder не заменяет и не маскирует primary throwable;
- secondary diagnostic содержит безопасный тип/identity собственной ошибки;
- payload, SQL text, credentials и исходный текст не логируются;
- повторная запись той же failure identity остаётся идемпотентной;
- одинаковый fingerprint в разных units создаёт разные scoped identities. Старый глобальный unique по fingerprint удаляется, а deterministic identity включает полный scope. Существующая identity того же scope переиспользуется без раздвоения истории.

### 4.5 Premature terminal transition

Обработчик исчерпания одной unit напрямую переводит документ в `needs_review/completed/100`, хотя другие units текущего source version остаются pending/running/leased. Затем сессия открывает review, но платная работа продолжается.

Целевой агрегатный барьер: документ текущего attempt/source version может получить terminal status только если нет units со status `pending|running` и нет активной lease. Unit failure обновляет только unit/page и инициирует reconcile. Только aggregate reconciler публикует terminal document/session state.

## 5. Каноническая машина состояний

### 5.1 Работа и результат — независимые оси

`work_state`:

- `processing`: существует pending/running/leased unit текущего source version;
- `complete`: все включённые units текущего source version terminal;
- `not_started`: units ещё не созданы.

`result_state` после `work_state=complete`:

- `ready`: все необходимые страницы сохранены и нет пользовательского решения;
- `partial`: хотя бы одна страница сохранена, но часть units завершилась системной ошибкой или требует проверки;
- `system_failure`: полезного результата нет, присутствует системная ошибка;
- `questions`: работа завершена и действительно требуется решение пользователя.

Пока `work_state=processing`, внешний state всегда `processing`, `is_ready=false`, 100% не показывается, Questions AI не открываются и следующий шаг не рекомендуется.

### 5.2 Допустимые переходы

```text
uploaded -> queued -> processing
processing -- unit terminal --> processing (пока есть blocking units)
processing -- all units terminal + all usable --> ready
processing -- all units terminal + usable subset --> needs_review/partial
processing -- all units terminal + no usable + system errors --> failed/system_failure
processing -- all units terminal + real questions --> needs_review/questions
terminal -- stale/redelivered unit --> terminal, no dispatch, no usage mutation
source replacement -> queued нового source version; старые units superseded
```

Прямой переход отдельной unit в terminal document state запрещён.

## 6. Backpressure, concurrency и exactly-once

- Claim unit разрешён только при совпадении organization/project/session/document/source version и актуального processing attempt.
- Реально terminal document не допускает нового dispatch.
- Одновременные claims сериализуются lease/token fencing.
- Завершённый role result переигрывается из role-run store; provider повторно не вызывается.
- Usage уникален по physical attempt и записывается ровно один раз для successful, failed и ambiguous outcomes.
- Stale completion не меняет unit/document/session и не добавляет usage.
- Aggregate finalization сериализуется document lock/claim token и повторяется идемпотентно.

## 7. Backend API contract

`EstimateGenerationDocumentResource.processing_outcome` является источником истины для админки и содержит:

- `type`: `processing|ready|system_failure|temporary_failure|user_action_required`;
- `state`: `processing|ready|partial|system_failure|questions`;
- `counts`: included/ready/processing/system-failed/action-required/excluded;
- `is_ready`: true только после aggregate completion и допустимого результата;
- `readiness`: разрешённые действия, вычисленные backend;
- безопасный `error.code/message` только для системной ошибки.

Legacy document status не может переопределить `processing_outcome`, если current units доказывают ongoing work. HTTP 200 и revalidated 304 должны нормализоваться в один typed resource без потери ранее сохранённых страниц.

## 8. Admin UX contract

UI показывает четыре честных состояния:

- «Обработка выполняется» — есть незавершённые units, продолжение недоступно;
- «Завершено с частичным результатом» — aggregate complete, сохранённые страницы видимы, часть результата недоступна;
- «Системная ошибка» — aggregate complete, пригодного результата нет;
- «Требуется решение» — aggregate complete и backend явно разрешил пользовательское действие.

Правила:

- сохранённые страницы никогда не скрываются из-за ошибки другой страницы;
- при processing не показываются 100% готовности, Questions AI и рекомендация следующего шага;
- normalizer принимает только реальный `AdminResponse` и не реконструирует readiness из legacy status;
- 200 и 304 сохраняют последний валидный resource; malformed success response становится диагностируемой сетевой ошибкой, но не стирает уже показанные данные.

## 9. TDD-матрица

| Риск | RED-регрессия | Ожидаемый GREEN |
|---|---|---|
| Routing enum | `material_risk=medium` production-shaped parse; boolean negative | enum проходит end-to-end, boolean отклоняется без coercion |
| Лёгкая страница | title/simple-context routing | только literal observer, без трёх тяжёлых ролей |
| Page 3 HTTP 200 | обезличенный schema v4 keyed-evidence fixture | response parse/canonicalization сохраняет полезные sections; bad facts quarantined |
| Evidence tampering | внешний key не равен внутреннему key | весь evidence container fail-closed |
| PDO projection | completed observer fixture + реальные repositories в isolated PostgreSQL | точный текущий PDO сначала RED; после fix atomic durable projection |
| Partial invalid claim | один безопасно изолируемый неверный item | item quarantined, валидный batch сохранён; DB atomicity сохранена |
| Observer reuse | completed role run и повторная доставка | provider calls не растут, stored result используется |
| Observability | failure store и observer последовательно бросают | исходный throwable rethrown; secondary identity безопасно доступна |
| Premature finalization | pending/running/leased sibling units | document/session остаются processing, progress <100, Questions закрыты |
| Terminal dispatch fence | real terminal aggregate + redelivery | claim/dispatch отсутствует, usage unchanged |
| Concurrency | два claims/finalizers в PostgreSQL | один владелец, один terminal publish |
| Exactly-once usage | duplicate physical completion/ambiguous delivery | одна usage row на attempt |
| Admin 200 | production-shaped AdminResponse для 4 состояний | корректные русские labels/actions/pages |
| Admin 304 | cached valid resource + 304 | UI сохраняет ресурс и состояние |
| Admin malformed | invalid success body после valid resource | явная ошибка загрузки без стирания сохранённых страниц |

## 10. Проверки

Backend:

- целевые PHPUnit для routing/parser/observer/finalization/observability;
- PostgreSQL contracts без skip для projection, concurrency, finalization и exactly-once;
- `php -l` изменённых PHP-файлов;
- Pint изменённых PHP-файлов;
- Larastan минимального затронутого модуля.

Admin:

- целевые Vitest/MSW;
- `tsc --noEmit`;
- ESLint и Prettier для изменённых файлов;
- локальный build запрещён.

Общее:

- UTF-8 проверка изменённых текстов/fixtures;
- `git diff --check` в обоих репозиториях;
- один последовательный self-review correctness/security/architecture/UX;
- после findings повторяются только затронутые проверки.

## 11. Критерии выпуска

- Все RED-регрессии доказаны до production-кода и проходят после исправления.
- Routing использует один enum без boolean-остатков.
- Page 3 fixture больше не уничтожается; invalid elements quarantined адресно.
- PostgreSQL reproduction показывает точную SQL-причину и подтверждает атомарный fix.
- Primary failure не маскируется failure-observability.
- Документ не terminal при blocking units и не dispatch-ит после real terminal.
- Usage остаётся exactly-once.
- Admin следует backend resource и честно отображает четыре состояния.
- Backend/admin PR merged, стандартный deploy успешен.
- Read-only canary подтверждает `/ready`, release SHA, 401 защищённых endpoints без JWT и отсутствие новых релизных Laravel/GlitchTip ошибок.

## 12. Остаточный риск и ручная проверка

Canary намеренно не вызывает AI. После выпуска единственная ручная проверка пользователя должна выполняться на новом тестовом документе: загрузить один небольшой PDF с лёгкой титульной страницей и одной содержательной страницей, дождаться фактического завершения и проверить честный прогресс, сохранённые страницы и доступность следующего шага только после готовности backend.
