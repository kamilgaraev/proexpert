# Production-инцидент AI-сметчика МОСТ: project 52 / session 71 / document 173

Дата фиксации: 2026-08-14
Статус: точечное исправление explicit retry и повторный штатный выпуск
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

Canary намеренно не вызывает AI. После выпуска единственная ручная проверка выполняется пользователем на сохранённом document 173: открыть документ и один раз подтвердить появившееся действие «Повторить обработку документа», затем дождаться результата. Агент не запускает этот повтор и не выполняет платный smoke.

## 13. Дополнение инцидента: ручной повтор после неопределённого исхода provider-вызова

### 13.1 Актуальный production evidence

Read-only снимок от 14.08.2026 после завершения предыдущего релиза:

- document 173: `failed/completed`, progress 100, processed pages 0 из 22;
- session 71: `failed`, progress 100, state version 3;
- `processing_outcome.type=system_failure`, `retry_allowed=false`;
- terminal units текущего source version: 9 × `document_unit_pre_wire_failed`, 11 × `vision_provider_response_invalid`, 2 × `vision_wire_outcome_ambiguous`;
- pending/running units: 0; units с действующей арендой: 0;
- текущая processing lineage: `6a2fe0cd-49af-4fbc-855f-f989c9ce842e`.

Authoritative `ExplicitDocumentRetryEligibility` разрешает только известные repairable и breaker failure codes. Код `vision_wire_outcome_ambiguous` отсутствует в разрешённом наборе, поэтому две terminal units закрывают eligibility всего документа. `EstimateGenerationDocumentActionBuilder` закономерно не публикует `retry_document`, а админка, следующая action contract, не показывает кнопку. Это точная причина противоречия между рекомендацией ручного повтора и фактическим серверным контрактом.

### 13.2 Граница безопасности

`vision_wire_outcome_ambiguous` нельзя автоматически повторять: неизвестно, был ли provider-вызов физически принят и учтён. Допустим только новый, явно подтверждённый пользователем document-level retry. Он является новой логической и физической попыткой, а не повторной доставкой старой ambiguous attempt.

Старые physical attempts, usage, стоимость, failure history и audit history неизменны. Компенсации, удаление или переиспользование старого provider attempt запрещены. Exactly-once учёт остаётся scoped к каждой physical attempt.

### 13.3 Машина состояний ручного повтора

```text
terminal system_failure
  + все units текущей lineage terminal
  + нет pending/running/active lease
  + нет user_action_required
  + tenant/project/session/document/source/version совпадают
  + ABAC estimate_generation.review
  + explicit user confirmation
    -> accepted(new idempotency key)
    -> new processing_attempt_id
    -> queued/stored/processing outcome
    -> one after-commit dispatch

accepted + same idempotency key
    -> replay same new lineage, no second dispatch

processing + different idempotency key
    -> already_in_progress, no lineage, no dispatch

ambiguous physical attempt + automatic recovery
    -> terminal, no provider retry

pending|running|active lease|user_action_required|stale source/version|
cross-tenant|wrong session|missing ABAC|unknown failure
    -> fail-closed, action absent, no dispatch
```

### 13.4 Backend action и mutation contract

Детальный и списочный `AdminResponse` публикует действие только из единого `EstimateGenerationDocumentActionBuilder`:

```json
{
  "action": "retry_document",
  "label": "Повторить обработку документа",
  "method": "POST",
  "endpoint": "/api/v1/admin/projects/{project}/estimate-generation/sessions/{session}/documents/{document}/retry",
  "requires_confirmation": true,
  "state_version": 3,
  "source_version": "sha256:<current>",
  "retry_disposition": "explicit_system_failure_retry"
}
```

Запрос содержит action-provided `state_version`, `source_version` и UUID `idempotency_key`. Под PostgreSQL document/session row locks сервис повторно проверяет tenant, ABAC, source identity, session version, terminal eligibility и отсутствие активной lineage. Принятая операция создаёт новый UUID `processing_attempt_id`, добавляет audit/history, переводит документ и session в processing и регистрирует единственный dispatch после commit.

### 13.5 Admin UX contract

- Кнопка существует только при наличии typed `retry_document` action.
- Перед отправкой обязательна явная модальная проверка. Текст сообщает, что новый анализ может снова использовать лимит и повлечь стоимость.
- Неопределённый сетевой исход и повтор отправки используют тот же idempotency key.
- После accepted response UI принимает authoritative document/snapshot: показывает «Выполняется» и не накладывает старые terminal 100%/system failure на новую lineage.
- Terminal `system_failure` подписывается «Обработка завершена с ошибкой»; 100% в этом состоянии означает завершённость работы, а не успешную готовность.

### 13.6 TDD-матрица точечного исправления

| Инвариант | RED | GREEN |
|---|---|---|
| Production-shaped eligibility | 9 pre-wire + 11 response-invalid + 2 ambiguous не публикуют action | публикуется ровно один typed retry action |
| Только ambiguous | terminal документ только с ambiguous не допускает explicit retry | explicit user-confirmed document retry разрешён; автоматический recovery не меняется |
| Активная работа | pending, running или действующая lease | action отсутствует, mutation fail-closed |
| Пользовательское решение | хотя бы одна unit `user_action_required` | action отсутствует |
| Fences | stale source/version, cross-tenant, wrong session, missing ABAC | action отсутствует или mutation возвращает безопасный conflict |
| Новая lineage | accepted retry после ambiguous | новый attempt ID отличается от старого; старые physical attempts не используются |
| Replay | два запроса с одним key | одна lineage и один dispatch |
| Competing key | второй key во время processing | `already_in_progress`, без дубля |
| PostgreSQL race | два одновременных manual retry | одна accepted lineage и один post-commit dispatch |
| Usage/cost | история старой lineage до и после retry mutation | старые usage/cost строки неизменны; новая lineage учитывается отдельно downstream |
| Admin action | production-shaped AdminResponse action present/absent | кнопка и confirmation только при present |
| Admin submit | accepted response с processing document/snapshot | сразу «Выполняется», старое terminal состояние скрыто |
| Admin timeout | повтор после неопределённого ответа | сохранён тот же idempotency key |
| Terminal copy | failed + 100% + system failure | «Обработка завершена с ошибкой», без ложной готовности |

### 13.7 План исполнения и выпуска

- [x] Зафиксировать RED backend eligibility/presentation и PostgreSQL lineage/concurrency/usage contracts.
- [x] Зафиксировать RED admin MSW/interaction/copy contracts.
- [x] Внести минимальное изменение единого eligibility/action/retry пути без параллельного контура.
- [x] Выполнить целевые проверки backend/admin и UTF-8/diff gates.
- [x] Провести один последовательный self-review correctness/security/architecture/UX.
- [ ] Создать backend/admin PR, выполнить merge и стандартный deploy только изменённых проектов.
- [ ] Выполнить read-only canary `/ready`, release SHA, protected 401, Laravel/GlitchTip.
- [ ] Обновить существующие статьи YouTrack Knowledge Base текущим выпущенным поведением.

## 14. Дополнение инцидента: production-shaped повтор lineage `069e6374-9f47-4d10-9b56-a38000559921`

### 14.1 Неизменяемый production evidence и границы диагностики

Контролируемый ручной повтор завершился 14.08.2026 в 21:07:25 UTC без преждевременной финализации:

- 5 реально исполнявшихся units завершились системной ошибкой, 17 units остановлены breaker;
- новые физические HTTP 200 появились только для page 1 и двух ролей page 2;
- добавлено ровно 3 usage rows: 14 508 input tokens, 5 055 output tokens, 6,053130 RUB;
- всего для document 173 остаются неизменяемыми 25 usage rows: 154 987 input tokens, 80 704 output tokens, 86,293485 RUB;
- response payload трёх HTTP 200 сохранён durable и рассматривается как уже оплаченный результат;
- production-диагностика выполняется только read-only, без повторного dispatch и без AI/Vision-вызовов.

В тестовых fixtures разрешена только минимальная обезличенная структура: роли, типы facts, cardinality, schema/prompt versions и deterministic identities. Тексты документа, изображения, prompts, signed URLs и исходный response body не сохраняются.

### 14.2 Точные root causes

#### PDO: page 1 / unit 390 и page 17 / unit 406

Call path:

```text
durable HTTP 200
  -> observer result completed
  -> RunIndependentObservers::preserveObservation
  -> ProjectModelEvidenceWriter::write
  -> projectModelEntity(material)
  -> INSERT estimate_generation_project_model_entities
  -> PostgreSQL entity payload trigger
```

Exact isolated PostgreSQL RED воспроизвёл SQLSTATE `P0001` и typed invariant `estimate_generation.project_model_entity_payload_invalid` из `eg_project_model_entity_payload_guard()`. Причина не в enum CHECK: PHP сериализует `attributes['properties'] = []` как JSON array `[]`, а canonical material contract требует JSON object. Payload с корректными `kind`, `key`, `material_code` и `name` отклоняется строго на `jsonb_typeof(payload->'properties') = 'object'`. Fingerprint `aaf3eeab...` повторился на page 17 по тому же пути. Исправление PR #433 добавило material projection, но его fixture не пересекла canonical JSON-object boundary.

#### InvalidArgumentException: page 2 / unit 391

Два HTTP 200 успешно разобраны: construction observer сохранил непроецируемый `note`, risk observer вернул допустимый результат без claims и с четырьмя item-level quarantined observations. После этого `RunIndependentObservers::preserveObservation()` повторно передал одиночный observer result в `ArbitrationInputBuilder::claims()`. Builder трактует пустой список claims как ошибку всего arbitration input и бросает `InvalidArgumentException('arbitration_claims_missing')` до arbitration HTTP.

Это не прежняя гипотеза `BuildingModelSchema::key / Room key is invalid`: этого класса в актуальном пути нет, а production-shaped result уже прошёл item-level quarantine. Ошибка находится во внешней preservation-границе, которая ошибочно требует хотя бы один проецируемый claim от каждого валидного observer result.

#### Physical claim: page 3 / unit 392 и page 18 / unit 407

Для обеих units новая lineage не создала physical rows. В каждой существует ровно одна старая completed physical row:

- unit 392: attempt `09e235ee-65ad-5971-b60d-fa0cf925514e`, request fingerprint `9d15d7db...`;
- unit 407: attempt `7bbe17f3-c5a9-5cae-ba2a-c195d2cec476`, request fingerprint `8632fa1f...`.

`ProductionDocumentUnitProcessor` строит исходный `AiOperationContext::attemptId` без `processing_attempt_id`. `TimewebVisionProvider::physicalContext()` выводит physical attempt ID из этого значения, model, wire ordinal, prompt contract и derivative hash — также без lineage. Одновременно request fingerprint содержит полный wire payload и physical attempt ID. При явном повторе deterministic physical ID совпадает со старой строкой, `insertOrIgnore` ничего не создаёт, а проверка старого и нового request fingerprints бросает `UsageInvariantViolation('Vision physical attempt collision.')`. Ошибка возникает до wire-start, поэтому новых physical rows и usage нет. Одинаковый safe diagnostic fingerprint `3dfcd0cc...` у двух units соответствует одной invariant-границе.

### 14.3 Почему PR #433/#434 были зелёными

- PostgreSQL fixture PR #433 проверял `room_area` и отфильтрованный `roof_geometry`, но не содержал production-shaped `material`, поэтому несовпадение enum/constraint не пересекалось.
- Тест writer вызывал projection напрямую и не проходил полный observer preservation flow с валидным пустым claims list и quarantined items.
- Replay-тесты проверяли один physical ID с одним fingerprint; не было старой terminal/completed строки одной lineage и явного повтора другой lineage с изменившимся wire payload.
- PR #434 сужал миграцию failure ledger и не менял parser, projection либо physical identity.

### 14.4 Целевая машина identity/replay

```text
logical immutable request
  = tenant + source/version + derivative + model + prompt + schema + canonical payload

physical execution
  = logical request + processing lineage + wire ordinal

same lineage + same logical request
  -> одна physical execution; concurrent workers видят busy/replay

completed response_received + exact immutable fence
  -> parser/persistence resume; provider calls +0; usage rows +0

cross-lineage + exact immutable completed response
  -> разрешён reuse того же durable response; provider calls +0; usage rows +0

cross-lineage + immutable fence mismatch
  -> новая lineage-scoped physical execution; один разрешённый HTTP

ambiguous
  -> никогда не является reusable success и не повторяется автоматически
```

`request_fingerprint` не зависит от lineage-scoped physical ID. Lineage хранится отдельно и участвует в physical execution identity всех document Vision-ролей: observer, arbitration и geometry expert. Конкурентный exact logical request сначала сериализуется unique identity/lock role-run, а внутри lineage физический claim сериализуется по primary physical attempt ID; поэтому provider получает не более одного владельца вызова.

### 14.5 Preservation и project-model projection

- Полный observer result остаётся durable в role run и доступен arbitration/оператору независимо от project-model projection.
- Пустой claims list является допустимым результатом observer-а и не запускает projection.
- Arbitrary observation (`note`, recommendation, unresolved question и другие неканонические типы) не преобразуется в room/material.
- Каждый claim проецируется только при доказанном соответствии canonical entity/fact type; непроецируемый optional item получает typed limitation и не отменяет остальные claims/evidence.
- Canonical entity ID включает scope и canonical type; replay с тем же смыслом идемпотентен, а тот же stable key с другим смыслом является typed semantic collision без перезаписи сохранённого результата.
- Нормы, цены, арифметика, source/version/evidence ownership остаются fail-closed.

### 14.6 Persistence и миграция

Нужна только новая forward-only retry-safe migration:

- канонически сериализовать object-valued entity attributes (`properties`) без ослабления deployed trigger и без изменения deployed migration;
- явно хранить processing lineage и logical immutable request fingerprint у новых physical attempts;
- сохранить существующую PostgreSQL uniqueness role-run для exact logical request и lineage-scoped primary identity physical attempt, не объявляя ambiguous success;
- исторические rows и 25 usage rows не изменять и не переоценивать.

Миграция не запускается в локальной БД. RED/GREEN выполняются только штатным isolated PostgreSQL contract harness.

### 14.7 API и admin contract

Backend resource остаётся единственным источником состояния:

- `ready/failed/breaker_stopped/pending/running/leased` отражают фактические counts;
- наличие сохранённых useful pages не скрывается terminal ошибкой другой page;
- terminal error не нормализуется в ready;
- retry action приходит только из backend action contract;
- новая typed причина получает русское человекочитаемое сообщение через `trans_message(...)` и не раскрывает SQL/message/payload.

Admin проверяется production-shaped MSW fixtures для partial success, system failure и наличия/отсутствия retry action.

### 14.8 RED/GREEN-матрица

| Инвариант | RED evidence | GREEN contract |
|---|---|---|
| Page 1 PDO | exact sanitized material claim в PostgreSQL даёт `P0001 / estimate_generation.project_model_entity_payload_invalid` | `properties` сериализуется JSON object; claim, entity, fact и evidence сохранены; replay идемпотентен |
| Page 2 empty claims | construction result + risk result с 0 claims/4 quarantined items бросает `arbitration_claims_missing` | оба role results сохранены, полезный output доступен, arbitration/provider повторно не вызывается |
| Cross-lineage collision | old/new lineage дают один physical ID и разные fingerprints | physical IDs разделены lineage; mismatch получает один новый call |
| Two-worker claim | два PostgreSQL workers одновременно claim exact request | максимум один owner/physical call |
| Same-lineage resume | `response_received` повторно доставлен | provider calls 0, usage rows +0, parsing/persistence завершены |
| Cross-lineage reuse | exact completed response и mismatch/ambiguous варианты | reuse только exact completed; mismatch вызывает call; ambiguous не reuse |
| Item quarantine | один invalid optional item среди валидных | invalid item получает typed limitation, остальные claims/evidence сохранены |
| Canonical identity | replay и одинаковый key с иным canonical meaning | replay no-op; semantic collision fail-fast без уничтожения старой записи |
| Breaker | три разных fingerprints и затем одинаковые terminal fingerprints | разные причины не суммируются; одинаковая причина открывает breaker по порогу |
| Finalization | sibling pending/running/leased | aggregate не terminal |
| Resource/admin | production-shaped partial/system/retry payloads | counts/pages/action отображаются без реконструкции |
| Cost journal | снимок usage до/после resume/race | resume/reprojection +0; новый HTTP ровно +1 |

### 14.9 Выпуск и canary

- целевые PHPUnit, PostgreSQL contracts без skip, `php -l`, Pint, Larastan, autoload и `git diff --check`;
- admin Vitest+MSW, `tsc --noEmit`, ESLint, Prettier и `git diff --check`, без frontend build;
- один последовательный self-review correctness/security/architecture/UX;
- отдельные PR backend/admin только при фактических изменениях соответствующего проекта;
- стандартный deploy без изменения infrastructure/workflows;
- read-only canary: `/ready`, exact release SHA, protected endpoint 401, новые Laravel/GlitchTip events и отсутствие нового dispatch/usage для document 173;
- единственный следующий ручной retry document 173 выполняет пользователь только после успешного canary.
