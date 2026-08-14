# AI-сметчик МОСТ: адаптивный устойчивый контур обработки документов

## Статус

Утверждённая спецификация архитектурной замены page/document-контура v3. Она уточняет `ai-estimator-multi-agent-v3.md` и `2026-08-14-ai-estimator-v3-trust-boundary.md`. Новый контур является единственным authoritative runtime; fallback, compatibility path и двойная модель результата запрещены.

## Цель и масштаб

Контур обязан устойчиво обрабатывать комплекты из 3–4 взаимосвязанных документов и 200+ страниц, сохраняя каждый оплаченный успешный provider response, частичные результаты и прогресс. Один плохой item не уничтожает страницу, одна плохая страница не уничтожает документ, а завершённый unit не считается успешным результатом без корректного page outcome.

Production-shaped reference: сессия 70, документ 172, 22 страницы. Девять observers на страницах 1–3 и три arbiter-вызова завершились HTTP 200. Страницы 1 и 3 были потеряны из-за локальной contract projection, страница 2 — из-за старого 60-секундного `DocumentRepresentation` wall-clock лимита после успешного arbiter, затем breaker остановил страницы 4–22. Admin получил HTTP 200/304, но runtime normalization уничтожила экран.

## Граница доверия

AI владеет естественным языком, наблюдениями, строительным смыслом, предложениями фактов/связей, уверенностью, конфликтами, вопросами, semantic regions и объяснениями.

Сервер владеет organization/project/session/document/page/source/version scope, canonical IDs, trusted locators, budgets, idempotency, exact decimals, нормы, цены, ABAC, cost journal и publication.

Canonical flow:

`bounded HTTP body` → `durable paid response` → `bounded JSON envelope` → `tolerant item ingestion` → `server projection` → `canonical page corpus` → `geometry/project model/composer/auditor` → `deterministic quantity/norm/price/publication gates`.

Malformed top-level JSON, oversize/depth/count violation и cross-scope override fail closed. Unknown evidence, stale source, invalid region и malformed item quarantined независимо. AI-копии server-owned полей не являются authority.

## Адаптивная глубина анализа

### Первый observer

`observer_literal` всегда получает original full-page render достаточного качества и доступный trusted native/text/vector context. Это полноценный observer, а не router-call. Он одновременно:

- сохраняет найденные факты и контекст;
- классифицирует тип страницы;
- предлагает bounded semantic regions в нормализованных координатах;
- оценивает плотность, читаемость, неоднозначность и глубину анализа;
- возвращает routing intent с естественными причинами.

Его результат durable сохраняется до запуска следующих стадий. Он никогда не может выбросить страницу. `unknown`, low confidence/readability, ambiguous type и любое сомнение эскалируют анализ.

### Маршруты

1. `simple_context`: один observer. Титул, разделитель, почти пустой лист или однозначная обложка становятся `ready_context`. Пример: «Архитектурные решения (АР)».
2. `structured_textual`: обычно два независимых observers. Пояснения, ведомости, спецификации, экспликации, таблицы, условные обозначения и индексы листов считаются полезными. Arbiter запускается только при disagreement, risk, unresolved refs или material quarantine.
3. `dense_ambiguous`: три независимых observers и arbiter. Сюда входят планы, фасады, разрезы, узлы, инженерные схемы, плотные комбинированные листы, плохие сканы и мелкие размеры.

Дополнительные observers не получают смысловой результат первого. Они видят original/canonical full page, trusted context и разрешённые crops. Arbiter видит оригинал, crops и все независимые результаты.

Deterministic signals могут только повысить глубину. Междокументная ссылка или обнаруженный позднее недостаток контекста создаёт idempotent escalation уже сохранённой страницы без повторения первого observer.

Routing decision, причины, trigger, selected regions и фактическое число physical calls сохраняются в bounded audit/cost evidence.

## Semantic region flow

Regions создаются только для `dense_ambiguous` или deterministic escalation:

1. first observer предлагает до `max_regions_per_page` смысловых прямоугольников;
2. сервер проверяет finite normalized coordinates, min/max area, overlap, bounds, count и aggregate pixels/bytes;
3. сервер рендерит zoom crops из canonical high-quality page source через существующий GD/Intervention runtime;
4. crops получают stable server IDs и trusted source locators;
5. дополнительные observers получают full page плюс разрешённые crops одним multimodal request;
6. допускается не более одного bounded углубления и общего `max_provider_calls_per_page`;
7. macro facts сохраняются, даже если microtext в одном crop не читается.

Фиксированная безусловная сетка запрещена. Invalid region quarantined, соседние regions продолжают работу.

## Durable paid-result preservation

Physical attempt сохраняет bounded raw HTTP body немедленно после успешного terminal HTTP response и до envelope/parser/projection. Parsed provider envelope и local projection state сохраняются отдельно от business result.

Recovery state machine:

- `pre_wire` — provider ещё не вызван;
- `wire_started` — исход двусмыслен до terminal response;
- `response_received` — raw body durable, повторный provider call запрещён;
- `envelope_parsed` — bounded provider envelope durable;
- `projected` — canonical role result durable;
- `completed` — usage/cost записаны ровно один раз и role/page state опубликован.

После `response_received` timeout, crash или deterministic parser/projection issue восстанавливается локально. Contract/parser issue не является платным retry. Usage journal identity равна physical attempt identity; повторная запись является idempotent replay, а не новой стоимостью.

## Budgets, concurrency и backpressure

Transport timeout применяется только к одному physical HTTP call. Page workflow budget учитывает adaptive route и не валидируется как representation-building timeout после terminal response.

Технические budgets задаются config/env с безопасными defaults:

- page/document/session count limits;
- JSON depth/items/bytes/tokens;
- regions per page, crop pixels/bytes и total crop budget;
- provider calls per page/document/session;
- in-flight units per document и per session;
- bounded dispatch window и recovery batch;
- representation memory/bytes/object limits;
- page workflow soft deadline и job hard timeout.

Dispatcher выбирает только свободное окно, не ставит сотни заведомо busy jobs. Processing units остаются независимыми и параллельными между страницами/документами. Ни один worker не держит весь комплект в памяти; downstream aggregation читает bounded chunks/current projections.

## Breaker

Breaker учитывает только повторяющиеся transport/system/safety failures с одинаковой safe identity внутри document/source/lineage/route. Item quarantine, partial result, question, local parser/projection issue и unreadable crop breaker не увеличивают. Сохранённые page results не переписываются.

## Outcomes и downstream corpus

Page outcomes:

- `queued`, `processing`;
- `ready_calculation` — есть допустимые facts для расчёта;
- `ready_context` — полезный контекст без прямых quantities;
- `partial_review` — полезный corpus сохранён, есть quarantine/conflict/question;
- `system_failure` — непригодный transport/system/safety result.

Downstream AI-роли получают accepted, candidate, conflict, question и useful context с trusted evidence. Candidate/conflict/question не попадают молча в денежный расчёт, но не исчезают. Inter-document reconciliation работает по current source/version и может эскалировать страницу.

## Admin contract

Snapshot, document list, document detail и analysis error нормализуются независимо. Optional/new field не уничтожает экран. Последнее полезное состояние сохраняется при refresh/304/network failure.

UI показывает page/document/session progress, routing depth, context/calculation/partial/system outcomes, breaker stops, сохранённые facts/questions/regions и aggregate cost. `100%` означает завершение обработки, но не успех; failed/partial отображаются отдельно. Вкладки «Проверка геометрии» и «Модель здания» не возвращаются.

## Удаляемый runtime

Удаляются старые all-or-nothing validators/canonicalizers, repair regexes, `DocumentRepresentation` wall-clock assumption для полного AI workflow, breaker rules для local contract issues, unbounded dispatch, compatibility DTO/normalizers и dead targeted/legacy paths. Исторические migrations не меняются; schema cleanup — только forward-only migration после доказанного отсутствия readers/writers.

## TDD acceptance

- production-shaped arbiter pages 1/3: HTTP 200 decisions сохраняются, invalid item quarantined;
- page 2: successful arbiter принимается после пересечения старого threshold;
- title: ровно 1 provider call, `ready_context`;
- specification/table: второй observer, arbiter только при disagreement/risk;
- dense drawing: 3 independent observers + arbiter + bounded crops;
- unknown/low-confidence: mandatory escalation;
- invalid item/region/evidence: quarantine без потери соседних items;
- HTTP 200 recovery и routing replay: zero repeat provider calls/cost;
- cross-document reference: escalation с reuse первого result;
- 200 mixed pages, 3–4 documents: bounded concurrency/memory/calls/progress и заметно меньше 800 baseline calls;
- PostgreSQL: persistence, concurrent claim, recovery, idempotency, source/version fences и exactly-one cost record без skip;
- admin: production-shaped snapshot/list/detail через MSW без blank screen.

## Цена

Пользовательская цена остаётся одной понятной ценой генерации. Фактические internal calls журналируются для последующей калибровки. Новая тарификация по страницам и отдельная финансовая инфраструктура не создаются.

## Выпуск

Backend и admin проходят targeted tests/static checks, отдельный последовательный correctness/security/architecture/UX review, PR/CI/merge и стандартный deploy. После deploy выполняются `/ready`, exact release SHA, protected endpoint 401, read-only logs и GlitchTip. Production AI smoke и повтор сессии 70/документа 172 запрещены.
