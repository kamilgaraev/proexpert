# AI-сметчик МОСТ v3: граница доверия AI и сервера

## Статус и цель

Эта спецификация уточняет `ai-estimator-multi-agent-v3.md` после production-инцидента проекта 52, сессии 69, документа 171. Она заменяет контрактный принцип «AI обязан сериализовать внутренний DTO» на безопасную двухступенчатую границу: AI передаёт ограниченные содержательные намерения, сервер проецирует их в канонические сущности и отдельно применяет строгие safety-, calculation- и publication-gates.

Цель изменения — сохранять доказанный строительный смысл при локальных форматных ошибках AI, не ослабляя tenant/source/version fences, provenance, защиту от дублей, точные расчёты, нормы, цены, права и выпуск.

## Production root cause

Последний прогон успешно выполнил шесть независимых observer-вызовов для страниц 1 и 2. Два arbiter-вызова вернули HTTP 200 и содержательно полезные `sheet-analysis:v3` результаты, но весь результат каждой страницы был уничтожен после provider transport:

- `schema_version` пришёл строкой `"3"`;
- AI повторил `canonical_claim`, отличающийся от серверного побайтово;
- `question.code` был в uppercase;
- `reason_code` содержал естественный Unicode;
- `source_locator` описывал реальный page/source/processing context, но не совпадал с внутренней формой DTO.

`RoleVisionResponseCanonicalizer` ремонтировал только несколько частных вариантов. Затем `ArbitrationDecision::fromProviderIntent()` считал AI-копии серверных полей authoritative, а `RunDocumentArbitration` применял all-or-nothing `array_map`. Любое несовпадение становилось `arbitration_contract_invalid`; повторная содержательная ошибка попадала в document breaker и останавливала остальные 20 страниц.

Корневая причина — смешение трёх разных обязанностей в одном валидаторе:

1. безопасность transport;
2. интерпретация содержательного AI-намерения;
3. валидация канонического серверного результата.

## Текущий data flow

`DocumentUnitExecutionContext`
→ `ProductionDocumentUnitProcessor`
→ три `RunIndependentObservers`
→ `TimewebVisionProvider::analysisPayload()`
→ `RoleVisionResponseCanonicalizer`
→ `VisionAnalysisData::fromProviderArray()`
→ `RunDocumentArbitration`
→ `ArbitrationDecision::fromProviderIntent()`
→ `ProjectModelEvidenceWriter`
→ `RunGeometryExpert`
→ `RunProjectSynthesis`
→ `RunEstimateComposer`
→ deterministic norm/quantity/pricing gates
→ `RunEstimateAudit`
→ оператор
→ publication gates.

Проблема находится между bounded JSON decoding и каноническими writers: transport уже ограничен, но содержательный payload повторно проверяется как полный внутренний DTO.

## Новая trust boundary

Новый flow:

`bounded JSON transport`
→ `tolerant role ingestion`
→ `list<accepted intent> + list<typed quarantine>`
→ `server canonical projection`
→ `strict scope/provenance/safety validation`
→ `canonical persistence`
→ downstream AI review roles
→ `deterministic calculation/norm/pricing/publication validation`.

### 1. Tolerant bounded ingestion

Transport остаётся fail-closed, если:

- тело отсутствует, не JSON или не object;
- превышены bytes/depth/item count/role item count;
- обнаружена попытка изменить роль, scope, system rules или authoritative source context;
- payload невозможно безопасно отделить от prompt-injection-инструкций;
- provider/model/physical attempt не соответствуют закреплённому запуску.

После успешного transport каждый item разбирается независимо. Ошибка формы, неизвестное необязательное поле, служебный регистр или неавторитетная копия server-owned поля не уничтожает соседние items.

### 2. Server canonical projection

Проектор получает только allowlisted intent refs и authoritative execution context. Он создаёт canonical claim, question identity/code, source locator, scope и lineage. Присланные AI копии server-owned полей могут быть сохранены только как bounded audit metadata; они не участвуют в принятии решения и их расхождение не делает корректный intent невалидным.

### 3. Strict safety/calculation/publication validation

После проекции строго проверяются:

- tenant/project/session/document/page/source_version/processing lineage;
- принадлежность claim/evidence refs текущему allowlist;
- отсутствие stale/cross-scope/cross-lineage ссылок;
- уникальность physical object accounting и canonical facts;
- idempotency, exact replay, locks и version fences;
- BigDecimal quantities, rounding, units, norm candidates, resources and prices;
- ABAC, operator confirmation и publication readiness.

## Ownership полей

### AI-owned

- наблюдаемый строительный смысл и значение;
- статус намерения `accepted | candidate | unresolved` в пределах разрешённой роли;
- ссылки на исходный allowlisted claim;
- supporting claim/evidence refs из переданного набора;
- естественная Unicode-причина;
- предмет вопроса, влияние, рекомендация и варианты;
- геометрическая интерпретация, операнды и формула как намерение;
- межлистовая связь как набор allowlisted fact refs;
- предложение состава работ;
- аудиторское замечание и предлагаемое исправление.

### Server-owned

- organization/project/session/document/page/processing unit IDs;
- source version, current/stale marker и attempt lineage;
- role/model/prompt contract identity и physical attempt ID;
- canonical claim payload и canonical fact ID;
- question ID и machine code;
- exact source locator envelope и coordinate space;
- audit/quarantine identity, timestamps и reason code taxonomy;
- item/package/estimate/publication IDs;
- idempotency keys, locks, version fences и ABAC actor context.

### Deterministic-derived

- canonical claim из allowlisted source claim;
- locator из authoritative page/evidence/source metadata;
- question code из stable source identity и нормализованного subject;
- typed quarantine reason из parser/safety result;
- deduplication fingerprint физического объекта;
- decimal quantity из проверенных операндов и формулы;
- units, rounding, norm/resources/prices/totals;
- readiness: `ready | partial | questions | system_failure`;
- publication eligibility.

## Контракты восьми AI-ролей

### Наблюдатели 1–3

AI возвращает bounded observations: локальный reference, fact type/value/unit, evidence selection, confidence как подсказку и естественные warnings. Сервер задаёт роль, source scope, locator envelope и canonical observation identity. Один невалидный факт quarantined; валидные evidence/observations сохраняются. Unknown evidence остаётся fail-closed для конкретного item.

### Арбитр 4

Минимальный decision intent:

```json
{
  "claim_ref": "observer_literal:fact:1",
  "status": "unresolved",
  "supporting_claim_refs": ["observer_literal:fact:1"],
  "evidence_refs": ["dimension-1"],
  "reason": "На фасаде нет размерной цепочки, достаточной для расчёта площади.",
  "question": {
    "subject": "Размеры фасада",
    "impact": "Без размеров нельзя подтвердить объём фасадных работ.",
    "recommendation": "Уточнить ширину и высоту фасада.",
    "choices": []
  }
}
```

Для совместимости ingestion принимает прежний `claim_id`, но канонический persisted контракт использует server projection. `canonical_claim`, `question.code` и `question.source_locator`, если присланы, игнорируются как authority. Unicode reason/question text разрешён и bounded.

### Геометр 5

AI выбирает allowlisted fact/evidence refs, описывает измерения, формулу, неопределённость и вопрос. Сервер добавляет sheet/page/source locator, проверяет применимость refs и выполняет арифметику. AI не задаёт authoritative quantity.

### Инженер модели 6

AI предлагает связи между current allowlisted fact refs и вопросы по конфликтам. Сервер создаёт link/conflict/question IDs и locators, запрещает повторный учёт одного физического факта, stale source и cross-scope. Один плохой link/question quarantined независимо.

### Составитель 7

AI предлагает intent состава работ, ссылаясь на canonical facts/geometry/work candidates. Сервер создаёт item identities, нормализует единицы, устраняет дубли и рассчитывает quantities/norms/resources/prices. Provider-supplied IDs, quantities, rates и totals не authoritative.

### Аудитор 8

AI возвращает тип замечания, severity, allowlisted source refs, естественные reason/impact/recommendation и correction intent. Сервер создаёт finding ID/locator, проверяет refs и применяет correction только через composer + deterministic gates. Один плохой finding quarantined независимо.

## Quarantine и состояния

Каждый quarantine item содержит server-generated identity, role, item index, typed reason, safe field path и bounded audit metadata без raw prompt/document/provider internals.

- `ready`: transport пригоден, обязательных вопросов и material quarantine нет.
- `partial`: часть items сохранена, часть quarantined; обработка документа продолжается.
- `questions`: сохранены факты и есть содержательные вопросы оператору.
- `system_failure`: transport непригоден либо нарушена safety boundary; содержательные ошибки отдельных items сюда не попадают.

Пустой, но пригодный содержательный ответ становится `partial/questions` по семантике роли, если сохранённые данные позволяют продолжить. Полностью malformed/oversized/prompt-injected output — `system_failure`.

## Breaker semantics

Breaker учитывает только повторяющийся transport/safety failure с одинаковой безопасной identity внутри одного `document_id + source_version + lineage`. Quarantine отдельных intents, Unicode, uppercase service copy, AI canonical copy mismatch и содержательная неопределённость breaker не увеличивают.

Другой document/source/lineage имеет независимый breaker scope. Уже сохранённые page results не удаляются. После двух содержательных ошибок следующие страницы продолжают обработку; partial success входит в document outcome.

## Prompt-injection и утечки

System prompt явно отделяет документ как недоверенные данные. Документ не может изменить роль, allowlist, source scope, tool/runtime policy или формат server-owned полей. Ingestion отклоняет payload, который пытается объявить иной scope/role/system override. Логи и API не содержат raw prompts, secrets, provider internals или пользовательские документы; наружу выходят только safe reason taxonomy и человекочитаемое состояние.

## Admin

Существующая пятистадийная модель сохраняется; обязательные отдельные вкладки геометрии и модели не возвращаются. Admin показывает агрегированные состояния `Готово`, `Частично обработано`, `Нужны ответы`, `Системная ошибка`, количество сохранённых фактов/вопросов/quarantine и доступные действия оператора. Partial не скрывает сохранённые факты и не маскируется как system failure.

## Тестовая стратегия

### RED fixtures

- два production-shaped arbiter payload для страниц 1 и 2 документа 171;
- observer fixtures с полезными facts и локально невалидным item;
- uppercase `question.code` сохраняет вопрос через server-generated code;
- Unicode reason принимается;
- locator строится сервером;
- неверный AI `canonical_claim` не authoritative;
- unknown evidence, cross-scope и stale source fail-closed;
- один invalid intent quarantined без потери остальных;
- две содержательные ошибки не останавливают остальные 20 страниц;
- malformed, oversized и prompt-injected payload блокируются.

### Backend/PostgreSQL

- unit tests ingestion/projection/strict validator и downstream ролей;
- contract tests persisted payload/admin resource;
- PostgreSQL tests exact replay, concurrent claim/write, source-version fence и isolation по document/source/lineage;
- существующие deterministic quantity/norm/pricing/publication tests остаются зелёными.

### Admin

- TypeScript contract для четырёх состояний;
- Vitest/MSW для partial facts/questions/system failure;
- `tsc --noEmit`, targeted ESLint/Prettier; frontend build не запускается.

## Rollout и выпуск

1. Реализовать RED→GREEN backend без миграций и новой инфраструктуры.
2. Изменить admin только при доказанном contract gap.
3. Выполнить один пропорциональный набор unit/contract/PostgreSQL/static checks.
4. Провести ровно одно независимое read-only ревью после зелёных проверок.
5. Исправить подтверждённые findings и повторить только затронутые проверки.
6. Обновить workflow-документацию.
7. Отдельные backend/admin commits, push, PR, merge и существующий стандартный deploy.
8. После deploy: только `/ready`, `release.json`, protected endpoints без JWT, read-only logs и GlitchTip.

Документ 171 не перезапускается. Новые сметы и платные Vision-вызовы не инициируются.

## Не входит в изменение

Новые таблицы, migrations, workflows, CI, secrets, environments, servers, feature flags, MLOps, fallback models, storage contours и отдельный финансовый учёт AI-вызовов не добавляются. Исторические миграции не изменяются. Несвязанные рефакторы запрещены.
