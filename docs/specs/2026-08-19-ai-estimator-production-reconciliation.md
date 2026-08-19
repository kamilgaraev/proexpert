# Production reconciliation результатов AI‑сметчика

## Цель

Оплаченный и уже сохранённый ответ AI должен публиковаться повторно без нового provider call, если tenant/project/session/document/page/source-version и evidence границы совпадают. Локальная ошибка одного warning, дубли описаний одного визуального объекта или повторная проекция той же entity не должны уничтожать полезный результат страницы. Остановка документа должна синхронно и идемпотентно завершать текущую lineage честным частичным результатом.

## Production evidence

Инцидент ограничен project 52, session 77, document 179, source PDF `ar (1).pdf`, 22 страницы. На момент диагностики документ имел `processing_control_status=cancelled`, десять completed units и двенадцать superseded units, активных pending/running units не было, но document оставался `processing/quality_check`.

- page 5 / page id 1120 / unit 526: completed output, 26 vision elements, visual inventory сантехники, кухонной мойки и условной мебели; document facts и project model не отражали весь полезный semantic result;
- page 9 / page id 1124 / unit 530: три observers и arbiter completed, durable role payloads и physical responses сохранены, публикация откатилась на `project_model_entity_exact_identity_collision`;
- page 11 / page id 1126 / unit 532: HTTP 200 и durable response сохранены; один `scale_notation` с `meters_per_unit=0.02` противоречил warning `scale_missing`, поэтому весь observer result завершился `observer_contract_invalid`;
- page 13 / page id 1128 / unit 534: остановленная после wire страница получила `document_processing_stopped`; это cancellation outcome, а не ошибка распознавания.

Стоимость исходного запуска — 240.48495000 ₽ и 47 AI‑вызовов. Любая проверка и repair выполняются offline или на изолированном PostgreSQL harness; production AI endpoints не вызываются.

## Канонический поток

```text
durable physical response
  -> strict envelope/source/model/usage validation
  -> local field sanitation for isolatable contradictions
  -> observer claims + evidence
  -> canonical semantic/object reducer
  -> atomic publication
       -> immutable evidence union
       -> canonical entities + facts
       -> accepted document facts
       -> visual inventory scope
  -> project understanding
       -> Questions AI only for requires_confirmation
       -> no estimate candidates for contextual/excluded objects
  -> document/session terminal reconciliation
```

## Инварианты validation и replay

1. Fail‑closed сохраняется для organization/project/session/document/page, source version, model identity, prompt contract, evidence ownership, dangling evidence, security и usage identity.
2. Provider warning — не security boundary. Если `scale_candidates` непуст, `scale_missing` удаляется и помещается в bounded quarantine как `scale_missing_warning_mismatch`; остальные evidence, elements, facts и routing сохраняются.
3. Если scale candidates отсутствуют, `scale_missing` добавляется. Материальный конфликт scales по‑прежнему детерминированно добавляет/удаляет `scale_conflict`.
4. Replay использует только durable response текущего exact physical attempt/request fingerprint. `response_received` и `completed` никогда не создают второй wire call и не повторяют usage/cost/quota journal.
5. Transient HTTP 500 остаётся историей physical attempt. После успешного scoped retry итог страницы определяется опубликованным успешным output и не наследует ошибку первого вызова.

## Канонический объект, evidence union и downstream scope

Три понятия независимы:

- evidence union — все уникальные supporting claim IDs, observer roles, evidence refs и locators;
- canonical object — один физический/семантический объект с детерминированной identity;
- downstream scope — `requires_confirmation`, `contextual_only` или `excluded_by_document_note`.

### Semantic object identity

- разделители `.`, `_`, `-`, `:` и известные room aliases нормализуются;
- rooms и dimensions идентифицируются entity + fact type + typed value + unit;
- visual inventory идентифицируется room/location + object family + object type, а не полным текстом observer;
- три описания одной мойки/санитарного объекта объединяются, lineage всех observers сохраняется;
- разные комнаты и разные физические объекты не объединяются только из-за одинаковой подписи;
- ordinal/instance из source entity key является частью identity физического объекта: три observer-варианта `toilet_1` объединяются, но `toilet_1` и `toilet_2` остаются разными объектами.

### Entity persistence

- accepted canonical entity имеет fact‑independent immutable attributes;
- измерение, площадь, подпись и другие observation values хранятся в Fact/DocumentFact, а не меняют immutable entity payload;
- несколько facts одной room/entity объединяются до `saveSourceModel` и не могут породить exact identity collision;
- канонический identity проверяет legacy-варианты разделителей только в точном organization/project/session/source-version scope и переиспользует единственный существующий ключ; несколько совпавших aliases завершаются fail-closed;
- legacy fact-dependent dimension совместим только при точном совпадении measurement kind (если он сохранён), fact value и unit; это отдельно проверяется для elevation, level и dimension chain;
- публикация одной unit атомарна: evidence, entities, facts, document facts и page output либо сохраняются вместе, либо не изменяются.

### Scope policy

- furniture и иное условное окружение с `excluded_by_document_note` не создаёт project model assertion, estimate candidate, вопрос или работу;
- `contextual_only` доступно в document presentation как контекст, но не становится estimate candidate;
- `sanitary_fixture`, `kitchen_fixture` и `unknown_fixture` создают candidate fact с `requires_confirmation` и попадают в `Questions AI` после project understanding;
- accepted arbiter status не превращает visual inventory в подтверждённую поставку;
- arbiter majority влияет на evidence/confidence, но не повышает downstream scope.

## Rooms, dimensions и геометрия

- evidence‑backed room names/areas и explicit numeric dimension chains публикуются как canonical document/project facts;
- axes, openings, walls, texts и normalized polygons сохраняются как source geometry/evidence, но сами по себе не создают подтверждённые объёмы;
- scale notation разрешает интерпретацию видимых размеров, но не подтверждает площади/объёмы без явного значения или детерминированного geometry calculator;
- строковые неоднозначные цепочки и отметки остаются context/candidate facts и не превращаются в quantity takeoff;
- UI/DTO показывает русскую подпись, источник и назначение, не machine keys.

## Stop/finalization

Остановка сериализуется по document/source version и:

1. запрещает новые pre‑wire dispatch;
2. supersede‑ит pending и pre‑wire running units с cancellation metadata;
3. уже начатый wire может сохранить bounded durable response и usage ровно один раз, но не запускает следующую роль/unit;
4. stop и поздняя публикация последнего post-wire unit атомарно снимают `units_finalized_source_version`, `units_reconciled_source_version` и reconcile lease/token, после чего outcome вычисляется повторно;
5. сохраняет completed outputs и считает `ready`, `cancelled`, `needs_user_action`, `system_failed` раздельно;
6. `document_processing_stopped` и operator‑superseded units считаются cancelled, а не AI/system failures;
7. документ получает terminal `needs_review`, `processing_stage=completed`, progress 100 и state `partial` при наличии полезных страниц;
8. session snapshot синхронно отражает частичный terminal document и доступные действия; повторный stop возвращает тот же outcome без новых audit/dispatch.

## Presentation contract

Document DTO для visual inventory содержит bounded поля: canonical id, русскую label/category/scope, source page/evidence, lineage count/roles. Назначения отображаются как «Требует подтверждения», «Контекст чертежа», «Исключено примечанием документа». Интерактивное решение включить/исключить сантехнику и мойку находится только на этапе «Вопросы AI».

## RED fixtures и проверки

- page 9 fixture воспроизводит повторные arbiter decisions и entity collision; после fix публикуется атомарно, replay — 0 provider calls;
- page 11 fixture содержит HTTP 200 response с одним scale candidate и `scale_missing`; после fix warning quarantined, 16 elements и 10 evidence сохраняются;
- page 5 fixture содержит rooms, dimensions и три observer descriptions сантехники/мойки/мебели; дубли объединены, conditional furniture не попадает в estimate model, fixtures доходят до Questions AI;
- stop fixture: 10 completed + 12 superseded, включая post‑wire stopped unit; outcome terminal partial, active/dispatch count 0;
- cost fixture: provider spy 0 calls, usage/cost/quota counters неизменны на replay;
- full PDF gate использует существующий `FullPdfAiEstimatorPostgresE2ETest`/штатный PostgreSQL harness и deterministic recorded providers; локальный admin build запрещён.

## Release и canary

После targeted, модульных, PostgreSQL, offline PDF, php-l, Pint и Larastan проверок backend выпускается штатным PR/deploy. Admin выпускается только если меняется его контракт/код. Canary read-only: exact release SHA, `/ready`, protected endpoint `401`, логи/GlitchTip и отсутствие новых ошибок. Production AI smoke, retry/resume, миграции, cache clear и ручные записи запрещены.

## Адверсариальные инварианты маршрутизации

- Самоклассификация literal observer является входным сигналом, но не может понизить серверный минимум анализа.
- `material_risk=high`, `information_density=high`, противоречие между типом страницы и риском/плотностью, содержательные geometry elements (`room`, `wall`, `opening`, `dimension`, `axis`, `engineering_element`) либо факты геометрии/инженерии повышают маршрут до `dense_ambiguous`.
- Повышенный маршрут означает три независимых observer и arbiter. Уже выполненный literal observer переиспользуется; лимиты provider calls и backpressure не расширяются сверх существующего dense route.
- Только согласованная `title`/`divider`/`empty`/`cover` страница с low risk, low density и без содержательных geometry/engineering фактов остаётся `simple_context` с одним literal observer.
- Причина server-owned повышения публикуется в routing reasons и воспроизводится одинаково при durable replay.

## Адверсариальные инварианты visual identity и merge

- Известные RU/EN room aliases канонизируются bounded-словарём до identity: `kitchen`/`кухня`, `bathroom`/`санузел`/`ванная`/`туалет` и распространённые сокращения чертежей.
- Известные object aliases канонизируются до одного object type. Неизвестные room/object токены не угадываются: они детерминированно нормализуются с сохранением room scope.
- Разделители `.`, `_`, `-`, `:` эквивалентны. Room, object type и bounded suffix ordinal/instance входят в identity. Чисто числовой ordinal канонизируется как целое (`1 == 01 == 001`), но `1 != 2` и отсутствие ordinal не равно `1`; нечисловые instance tokens не угадываются и не схлопываются.
- Общий `sink` разрешается только с учётом room/context: bathroom/sanitary aliases дают `washbasin`, kitchen aliases дают `kitchen_sink`, а неизвестный контекст остаётся нейтральным `unknown` с подтверждением. RU/EN aliases не меняют пользовательские русские label/category.
- Projector сначала собирает валидированные candidates по canonical identity, затем сворачивает каждую группу. Ни key, ни label, ни quantity, ни primary claim/evidence не наследуются от первого элемента.
- Reduce коммутативен, ассоциативен и детерминирован: все перестановки observers, claims, arbitration decisions и evidence дают canonical-equivalent byte payload.
- Canonical key выводится из identity, label — из bounded русской таблицы object types. Для unknown используется детерминированная безопасная подпись без технического entity key.
- Quantity извлекается только из явной счётной семантики (`2 мойки`, `унитаз — 1 шт.`, `3 окна`). Размеры, площади, диаметры, отметки, оси, модели, артикулы и марки (`60 см`, `600 мм`, `Ø50`, `Модель 60`, `арт. 123`, `11 100 мм`, `22.10 м²`) не становятся количеством. Разные явные counts, неясность либо смесь явного count и наблюдения без количества дают `quantity=null`, `quantity_uncertain=true` и требуют подтверждения; float-эвристика запрещена.
- Scope сворачивается по самому ограничительному значению. Для presentation primary arbitration выбирается в порядке `accepted` → `candidate` → `conditional` → `unresolved`, поэтому minority не представляет принятую группу. Supporting claim IDs, evidence refs и locators объединяются, дедуплицируются и сортируются; primary claim выбирается детерминированно внутри лучшего статуса, primary evidence — минимальным canonical locator независимо от входного порядка.
- Повторные arbiter decisions одного `claim_id` редуцируются коммутативно и ассоциативно по всему multiset. Конфликт статусов не повышается до `accepted`; `ambiguous`/`conditional`/`rejected` сохраняются честно, а primary decision, limitation и lineage выбираются детерминированно. Свежая arbitration publication, historical payload и durable replay дают canonical-equivalent результат при любой перестановке решений.
- Furniture с conditional note остаётся excluded downstream. Санитарные приборы и кухонная мойка остаются `requires_confirmation` и доступны только в «Вопросах AI».

## Политика безопасных top-level extensions

- Обязательные поля, schema version, типы, evidence/provenance, model/usage и tenant/project/session/document/page/source boundaries остаются fail-closed.
- Неизвестные top-level поля допускаются только под bounded диагностическими именами `diagnostic_*` и `extension_*`; их значения локально игнорируются и фиксируются как audit quarantine без включения в расчётные данные.
- Любое неизвестное поле, содержащее зарезервированные security/scope/source/model/usage семантические токены, включая snake_case и camelCase (`diagnostic_project_id`, `organizationId`, `sourceVersion`, `authorization`), а также вложенная попытка передать такой ключ завершается fail-closed.
- Extension policy ограничивает количество полей, длину имени, суммарные JSON bytes и глубину. Превышение любого лимита fail-closed и не уничтожает ранее сохранённый canonical response.
- Malformed известное поле проходит существующую typed quarantine либо fail-closed политику по его риску; extension policy не маскирует ошибки известных полей.
- Provider response с безопасным extension сохраняет полезные evidence/elements/facts. Durable replay использует сохранённый ответ, не вызывает provider и не меняет usage/cost/quota/draft hash.

## Новые доказательные RED/GREEN контрпримеры

- contradictory title: `simple_context + high density + high risk` обязан дать dense route, 3 observers и arbiter; genuine title без содержательных geometry/engineering facts остаётся 1-call route;
- RU/EN room aliases объединяются, но two rooms и numeric/zero-padded/alphabetic instances остаются отдельными identities;
- все перестановки трёх observers, их claims/evidence и arbitration decisions дают одинаковый canonical inventory; quantity conflict/omission не становится точным числом;
- minority evidence сохраняется в lineage, не повышает scope и не становится primary при наличии accepted/candidate support;
- `diagnostic_note` локально quarantined без потери sections/facts/elements; reserved/malicious extension fail-closed;
- PostgreSQL replay подтверждает 0 provider calls и неизменность usage/cost/quota; full PDF gate подтверждает 22/22 terminal outputs и предметные инварианты страниц 5 и 11.
