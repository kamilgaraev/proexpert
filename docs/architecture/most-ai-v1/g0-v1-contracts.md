# МОСТ AI V1: кандидат контрактов G0

Результат [MOSTAI-13 / LEAD-04](https://prohelper.youtrack.cloud/issue/MOSTAI-13), пакет 02, 05.10.2026. Версия `most-ai-g0/0.2-candidate`. **Status: CANDIDATE; G0 OPEN; supporting PRIV/GATE/DOC/QA exact-HEAD reviews pending.** Это целевой контракт, не описание реализованного runtime и не разрешение private AI, merge или deploy пакета 02.

## Основание и границы решения

Base: `c7bbe8460fb01808d2e504d6364b6431dccd1138`; ветка `task/mostai-g0-batch-02`. Входы: [source Foundation](foundation-current-state.md), [caller inventory](caller-inventory.md), [product policy](purpose-policy.md), включая `most-ai-v1-purpose-policy/0.2-product-approved-20261005`. Foundation принят в своём техническом scope; его исторические ограничения запуска следующего пакета читаются вместе с новым разрешением, а не переписываются задним числом.

В [исходном чате](codex://threads/01a1054e-e1f6-7c31-bef1-c4aff7ab6cf0) непосредственно проверены human messages `01a10a61-9682-7230-8002-996f27db78f6` (policy, юриста пока нет, Timeweb), `01a10a6f-8dcb-7e90-9500-e299686c2c83` (пакет 02) и `01a10c87-1b2f-7240-b644-86c9312c9c49` (самостоятельный агент в рамках прав пользователя, контекст и поиск до Gateway). Команды разрешают bounded работу по готовности, не согласуют этот контракт, residual risks или реальную передачу данных. MOSTAI-79/17 разблокируются фактическим независимым принятием exact G0 artifact внутри пакета; фиктивный Done MOSTAI-13 не нужен. До принятия выполняется только MOSTAI-13.

Изменяются только этот файл и [ownership](g0-ownership.md). Product/config/routes/providers/manifests/locks/CI/DB/production read-only. Пересверка уже принятого source inventory и успешных deployment checks не повторяется. Имена новых API, типов и файлов ниже — **PLANNED**, не существующая регистрация. PR #909 не дублируется.

## Самостоятельность помощника и порядок product acceptance

LLM ведёт смысловой plan, тему/task context, выбирает разрешённый tool и следующий поиск, связывает evidence и формулирует естественный ответ. Backend обеспечивает fresh ACL/tenant/privacy, проверяет authoritative evidence/claims и исполняет canonical операции. Валидную narrative нельзя заменять field dump, списком всех refs или server template. При неподтверждённом claim модель получает безопасный reason/evidence для исправления; scope-aware validation не принимает решения о смысле за модель. Значимые effects требуют canonical preview/confirm и idempotency независимо от свободного планирования.

После пакета 02: [MOSTAI-49](https://prohelper.youtrack.cloud/issue/MOSTAI-49) context/history, [MOSTAI-38](https://prohelper.youtrack.cloud/issue/MOSTAI-38) evidence search, [MOSTAI-50](https://prohelper.youtrack.cloud/issue/MOSTAI-50) agent loop используют единые shapes ниже; consumers не изобретают свои DTO. Контекст/поиск возможны параллельно, loop после готового контекста. Затем независимый [MOSTAI-53 / PG](https://prohelper.youtrack.cloud/issue/MOSTAI-53): public/synthetic fixtures, local provider stub, provider/private calls и реальные effects off. Сценарии: бетон/м³/цена, выбранное фото и два уточнения, смена темы, selected entity, partial scope и отказ доступа. Минимальный page/entity/filter context Required; расширенный MOSTAI-52 остаётся Recommended.

Gateway coding MOSTAI-27/31/33/34 начинается после PG и собственных входов. Local PG PASS проверяет orchestration/контекст/claims под stub, не actual-model support/quality и не runtime privacy PASS. Полные actual-model, history/refs/OCR/embeddings/RAG/response/wire/effects повторно принимаются MOSTAI-80/81/82 и [G1 / MOSTAI-14](https://prohelper.youtrack.cloud/issue/MOSTAI-14). G2/G3/G4 и human pilot/release сохраняются. Эта последовательность не запускает четвёртую задачу в пакете 02.

## Доверительные границы V1

| Участник | Получает | Может делать | Запрещено |
| --- | --- | --- | --- |
| App/workers/tools | Private user input и локальные результаты | Вызывать только Processor; отображать разрешённую локальную rehydration | Вызывать Gateway/provider SDK, назначать safe marker, хранить provider credentials |
| Trusted Processor | Private actor/org/project IDs, исходники, ACL, consent, policy, локальные versions | Минимизировать, tokenise, создавать закрытые safe types, final wire bytes; сериализовать revoke/dispatch | Raw fallback; разрешать unknown; считать клиентский context доказательством прав |
| Minimal Vault | Private mapping, ключ и recovery metadata | Tenant-scoped mapping/resolve только для Processor под fresh ACL | Доступ Gateway/provider/model; глобальный поиск токена; payload в logs |
| Gateway | Authenticated Processor identity, opaque scope/attempt/artifact refs, safe exact bytes и версии | Проверять trusted origin/integrity/digest/profile, fresh guard; единственная provider transport write | Принимать App/client/model identity; менять body; cached approval; detokenise |
| Provider | Только разрешённые wire bytes и необходимые transport headers | Ответить выбранному endpoint/profile | Получать private principal IDs, Vault keys/mapping, private source refs |

Private principal IDs остаются только на private стороне Processor/Vault. Provider reply, tool arguments и safe text остаются недоверенными: инструкция из документа не получает системные полномочия. Gateway не получает private actor/organization/project IDs ни в body, ни в headers, URL, trace/log tags или idempotency keys. Opaque refs генерируются случайно, не кодируют ID, имя, путь, tenant или hash исходной записи; их наличие не даёт права resolve.

В V1 достаточно authenticated integrity-protected Processor-only channel с проверкой выделенной service identity и отказом при недоступной проверке. Выбор механизма и deployment proof принадлежат GATE/infra. Подпись каждого envelope/artifact не обязательна; plain network allowlist или присланный `processor_id` не заменяют аутентификацию. Shared trusted host/kernel остаётся explicit residual risk.

## Закрытое создание SafeRepresentation

`PrivateProjection` — минимальная server-created выборка под tenant/project/entity/field ACL. Actor берётся из серверной аутентификации; существование источника и актуальная source generation проверяются локально. Предоставленные клиентом scope/IDs и предыдущий tool result повторно авторизуются. Если auth, source ACL, consent, policy, trusted creator или version dependency недоступны, результат `blocked`; отсутствие ошибки не считается allow.

`SafeRepresentation` нельзя построить из arbitrary string/object, произвольного JSON, `is_safe=true`, deserialization клиента или публичного конструктора. Его создает только trusted Processor factory после Required stages. Закрытый тип и доверенное происхождение должны сохраняться через восстановление: persistent object заново проверяется, а не становится safe по имени класса. Gateway принимает только trusted channel и registry-backed immutable representation, не самодекларированный DTO.

Stages: allowlisted field projection; category/purpose/owner-ban filtering; suppression/tokenization структурированного known PII независимо от NER; RU/EN free-text/category detection; local OCR/redaction для поддерживаемых media; проверка контекстной reidentification и минимизации; approved model/endpoint transform; окончательная сериализация; leak/category scan финальных bytes и всех вспомогательных каналов; immutable seal. Unknown, неполный scanner coverage, неготовый artifact или сомнительная очистка блокируют отправку.

Policy применяется к user/system/developer prompts, history/summary/memory, tool names/schemas/descriptions/examples/results, response schemas, JSON keys, filenames, URLs, page/unit metadata, auxiliary text, embeddings inputs и media. Известные PII-поля удаляются либо заменяются random tenant-scoped tokens даже при отрицательном NER. Payroll/medical/passport/payment data остаются локальными по policy; token не создаёт allowance категории. Структурированный ключ с ФИО не безопаснее значения с ФИО.

Это псевдонимизация, не анонимизация/legal basis. Остаточный контекст, цены и договорные факты проходят purpose/minimization/vendor/organization проверки. Public Gateway endpoint/model profile является техническим профилем; он не означает согласие конкретного customer или разрешение любых частных данных.

## Версионируемые поля и API shapes

Обозначения ниже задают семантику закрытых типов, а не TypeScript/PHP implementation. `OpaqueRef` — CSPRNG ref с минимум 128 бит случайности; server registry дополнительно привязывает его к tenant, purpose и generation. `Version` — точная неизменяемая версия, не `latest`; `Digest` — lowercase SHA-256 hex exact bytes. Неизвестная версия/schema, лишнее поле и неверный variant отклоняются; обновление incompatible fields требует новой major schema и согласования G0.

Ниже concrete bounded schema local public/synthetic пути пакета 03/PG. Это offline спецификация, не регистрация новых callable tools. `search`/`read_selected` — logical variants адаптера существующего разрешённого чтения; 15 proposed comparison/legal tools сюда не входят. Private production factory остаётся fail-closed, пока Required privacy dependencies/конкретный caller schema не реализованы и не приняты. Остальные callers T1–T7 требуют собственной versioned projection; эта минимальная schema не даёт универсального allowance.

```typescript
declare const processorSeal: unique symbol;
type Sealed<T> = Readonly<T> & { readonly [processorSeal]: true };
type OpaqueRef = string;
type Version = string;
type Digest = string;
type SafeText = Sealed<{ utf8Text: string }>;
type ImmutableBytes = Sealed<{ bytes: readonly number[] }>;
type PurposeCode = "assistant_chat";
type Unit = "m3" | "m2" | "kg" | "t" | "item" | "none";
type PeriodV1 = { from: string; through: string };
type ClaimScopeV1 = {
  kind: "selected_unit" | "selected_entity" | "search_subset" | "whole_corpus";
  scopeRef: OpaqueRef;
  sourceGenerationRef: OpaqueRef;
  unitRefs: OpaqueRef[];
};
type CoverageV1 = {
  status: "complete" | "partial" | "unknown";
  claimScope: ClaimScopeV1;
  inspectedUnits: number;
  totalUnits: number | null;
  omittedUnitRefs: OpaqueRef[];
};
type PrivateSourceVersionV1 = {
  privateSourceId: number;
  revision: Version;
  generation: Version;
  aclEpoch: Version;
  deletionState: "present" | "deleted";
  freshness: "fresh" | "stale" | "unknown";
};
type PrivateProjectionV1 = {
  schemaVersion: "private-projection/1";
  privateContext: { actorId: number; organizationId: number; projectId: number | null };
  purpose: PurposeCode;
  policyVersion: Version;
  sourceSet: PrivateSourceVersionV1[];
  fields: {
    rawQuestion: string;
    rawSelectedText: string | null;
    privateSelectedUnitId: number | null;
    privateSelectedEntityId: number | null;
    sourceClass: "public" | "synthetic" | "private" | "unknown";
  };
};
type SafeProvenanceV1 = {
  schemaVersion: "safe-provenance/1";
  evidenceRef: OpaqueRef;
  sourceKind: "photo" | "transcript" | "entity" | "public_text";
  sourceGenerationRef: OpaqueRef;
  unitRef: OpaqueRef;
  pageRef: OpaqueRef | null;
  fragmentRef: OpaqueRef;
  fragmentVersion: Version;
  contentDigest: Digest;
  validationVersion: Version;
};
type EvidenceFactV1 =
  | { kind: "text"; value: SafeText; provenance: SafeProvenanceV1 }
  | { kind: "quantity"; decimal: string; unit: Unit; period: PeriodV1 | null; provenance: SafeProvenanceV1 }
  | { kind: "price"; decimal: string; currency: "RUB"; perUnit: Unit; period: PeriodV1 | null; provenance: SafeProvenanceV1 };
type SafeToolArgumentsV1 = Sealed<{
  schemaVersion: "safe-tool-arguments/1";
  toolKind: "search" | "read_selected";
  query: SafeText | null;
  selectedRefs: OpaqueRef[];
  claimScope: ClaimScopeV1;
  limit: number;
}>;
type ToolResultV1 = Sealed<{
  schemaVersion: "safe-tool-result/1";
  requestRef: OpaqueRef;
  profileRef: OpaqueRef;
  profileVersion: Version;
  toolKind: "search" | "read_selected";
  status: "verified" | "partial" | "no_data" | "error" | "blocked";
  reason: "none" | "scope_limited" | "no_evidence" | "dependency_unavailable" | "access_denied" | "privacy_not_ready" | "source_stale";
  facts: EvidenceFactV1[];
  coverage: CoverageV1;
  nextSafeRefs: OpaqueRef[];
  resultGenerationRef: OpaqueRef;
}>;
type ReadySafeArtifactV1 = Sealed<{
  schemaVersion: "safe-artifact/1";
  artifactRef: OpaqueRef;
  sourceGenerationRef: OpaqueRef;
  artifactGenerationRef: OpaqueRef;
  mediaType: "image/png" | "image/jpeg";
  byteLength: number;
  digest: Digest;
  transformVersion: Version;
  verificationVersion: Version;
  readiness: "ready";
}>;
type SafeTranscriptV1 = Sealed<{
  schemaVersion: "safe-transcript/1";
  transcriptRef: OpaqueRef;
  transcriptVersion: Version;
  text: SafeText;
  textDigest: Digest;
  imageArtifactRef: OpaqueRef;
  imageArtifactGenerationRef: OpaqueRef;
  provenance: SafeProvenanceV1;
  transformVersion: Version;
  verificationVersion: Version;
  readiness: "ready";
}>;
type SelectedMediaV1 = Sealed<{
  schemaVersion: "safe-selected-media/1";
  image: ReadySafeArtifactV1;
  imageProvenance: SafeProvenanceV1;
  transcript: SafeTranscriptV1 | null;
}>;
type SafeMessageV1 = Sealed<{
  schemaVersion: "safe-message/1";
  role: "user" | "assistant" | "tool";
  text: SafeText;
  evidenceRefs: OpaqueRef[];
  taskRef: OpaqueRef;
  generationRef: OpaqueRef;
}>;
type SafeChunkV1 = Sealed<{
  schemaVersion: "safe-chunk/1";
  text: SafeText;
  provenance: SafeProvenanceV1;
  indexGenerationRef: OpaqueRef;
}>;
type TokenBudgetV1 = {
  profileRef: OpaqueRef;
  profileVersion: Version;
  tokenizerVersion: Version;
  inputTokens: number;
  historyTokens: number;
  toolSchemaTokens: number;
  mediaTokens: number;
  responseReserveTokens: number;
  toolResultReserveTokens: number;
  contextCapacityTokens: number;
};
type TaskFrameV1 = Sealed<{
  schemaVersion: "safe-task-frame/1";
  taskRef: OpaqueRef;
  scopeRef: OpaqueRef;
  topic: SafeText;
  userGoal: SafeText;
  selectedMedia: SelectedMediaV1 | null;
  selectedEntityRef: OpaqueRef | null;
  pageContext: {
    pageKind: "none" | "project" | "document" | "entity";
    pageRef: OpaqueRef | null;
    entityRef: OpaqueRef | null;
    filters: { status: "any" | "open" | "completed"; period: PeriodV1 | null; query: SafeText | null };
  };
  safeRefs: OpaqueRef[];
  sourceGenerationRef: OpaqueRef;
  privacyReadiness: "ready";
  aclFreshness: "fresh";
  sourceFreshness: "fresh";
  evidenceCoverage: CoverageV1;
  history: SafeMessageV1[];
  safeSummary: SafeText | null;
  profileRef: OpaqueRef;
  profileVersion: Version;
  tokenBudget: TokenBudgetV1;
}>;
type SafeContentV1 =
  | { kind: "text"; text: SafeText }
  | { kind: "task_frame"; frame: TaskFrameV1 }
  | { kind: "media"; selected: SelectedMediaV1 }
  | { kind: "tool"; arguments: SafeToolArgumentsV1; result: ToolResultV1 }
  | { kind: "history"; messages: SafeMessageV1[]; historyGenerationRef: OpaqueRef }
  | { kind: "rag"; chunks: SafeChunkV1[]; indexGenerationRef: OpaqueRef }
  | { kind: "embedding"; inputs: SafeText[]; embeddingProfileVersion: Version };
type SafeRepresentationV1 = Sealed<{
  schemaVersion: "safe-representation/1";
  representationRef: OpaqueRef;
  scopeRef: OpaqueRef;
  purpose: PurposeCode;
  policyVersion: Version;
  projectionVersion: Version;
  sanitizerVersion: Version;
  sourceGenerationRef: OpaqueRef;
  content: SafeContentV1;
}>;
type PayloadChannelV1 = {
  bytes: ImmutableBytes;
  byteLength: number;
  digest: Digest;
};
type DispatchEnvelopeV1 = Sealed<{
  schemaVersion: "dispatch-envelope/1";
  attemptRef: OpaqueRef;
  scopeRef: OpaqueRef;
  representationRef: OpaqueRef;
  purpose: PurposeCode;
  policyVersion: Version;
  sourceGenerationRef: OpaqueRef;
  profileRef: OpaqueRef;
  profileVersion: Version;
  wireSchemaVersion: Version;
  requestTarget: PayloadChannelV1;
  payloadHeaders: PayloadChannelV1;
  body: PayloadChannelV1;
  allPayloadDigest: Digest;
  artifactRefs: OpaqueRef[];
}>;
```

Все shapes закрыты: additional properties rejected на каждом уровне, отсутствующее required field/неверный enum/тип/связь даёт typed refusal. `Sealed<T>` — nominal in-memory brand trusted factory; не JSON flag, не возможность клиенту создать brand. Readonly/brand не доказательство provenance при persistence: восстановление заново проходит trusted verification. `SafeText` закрывается только после scan; string alias scalar не разрешает raw assignment.

| Scalar/collection | Bounds local V1 schema, не предел контекста реальной модели |
| --- | --- |
| OpaqueRef / Version / Digest | `ref_` + 32 lowercase hex, 128 random bits; Version 1–128 ASCII `[A-Za-z0-9._/-]`; Digest ровно 64 lowercase hex |
| Private IDs / epochs | Positive integer ≤ 2^53−1, либо explicit null где разрешён; revisions/generations/epochs — nonempty Version |
| Text | `utf8Text`, question, selected text и summary ≤ 8000 UTF-8 bytes; limit определяется также token budget; пустой topic/goal не ready |
| Source/evidence/history/chunks/refs | sourceSet 1–64; facts/chunks ≤ 64; history ≤ 40; refs/unitRefs/omitted refs ≤ 64; tool limit 1–64; без silent truncation |
| Decimal / period | Decimal canonical `-?(0|[1-9][0-9]{0,17})(\.[0-9]{1,6})?`, finite, без exponent/NaN; price nonnegative; ISO `YYYY-MM-DD`, real date/from ≤ through |
| Tokens/counts/byteLength | Integers 0–2^31−1; capacity и nonempty artifacts > 0; counts согласованы со scope; bytes elements 0–255 |

`projectId=null` разрешён только organization-scoped caller; project-required caller блокируется. `sourceSet` содержит полную dependency set выбранного frame/result, а не весь private corpus. Deleted/stale/unknown source или неизвестный ACL epoch блокирует ready. Field `sourceClass` назначает trusted source adapter, не клиент; synthetic marker без trusted fixture provenance не открывает production factory.

ToolResult `verified` означает проверенные facts и complete coverage текущего claim scope, не глобальную полноту другого scope. `partial` имеет partial/unknown coverage, но facts с валидным provenance и честные ограничения; `no_data` имеет пустые facts и не означает отсутствия данных за пределами inspected scope. `error`/`blocked` имеют пустые facts/nextSafeRefs и не создают allowance. Profile ref/version совпадает с frame и pinned composition. `nextSafeRefs` выдаёт Processor после fresh scope validation, модель не может добавить разрешённый ref. Quantity/price/text facts и claim scope — единственные допустимые варианты; неизвестная unit/currency/category/version blocked, не arbitrary JSON extension.

Processor связывает image artifact/generation, image provenance unit/page/fragment/version и transcript image ref/generation/provenance в одну проверенную source dependency set. Frame/coverage/arguments/result scopes и все evidence/artifact generations проверяются по этой registry dependency set; foreign ref/version mismatch blocked. Transcript принадлежит именно выбранному sanitized image/source fragment; смена фото или source revision инвалидирует связь. Digest текста вычисляется после redaction, не по raw OCR. При отсутствующем transcript ответ может опираться только на реально доступные и разрешённые facts изображения; extraction quality не заявляется документом. Model-generated correlation и произвольный ref не принимаются.

`Sealed`/Readonly в примере обозначает закрытую factory boundary, но shallow Readonly само по себе не enforcement. Materialized вложенные objects/arrays/bytes глубоко immutable: defensive owned copy/freeze либо эквивалент, mutation исходного буфера после seal не меняет sealed content. Десериализация и mutable shared references не сохраняют seal автоматически; изменение вложенного значения требует нового trusted representation и digest.

Числа text ≤ 8000/history ≤ 40 и остальные collection bounds — защитные пределы **этой local synthetic schema**, не universal truncation actual-model истории. Для actual frame допустима согласованная versioned schema с другими field bounds в пределах доказанного profile token budget. Задача расширения контекста не подменяется фиксированным урезанием сообщений; превышение bound явно обрабатывается новым schema/summary/scoped search, без silent truncation и потери task anchor.

Task frame хранит тему, выбор фото/расшифровки, entity и минимальные filters при двух уточнениях; явная смена темы обновляет frame и не подмешивает прошлые scopes. LLM предлагает semantic summary старых ходов только из разрешённого safe context; Processor заново проверяет privacy/refs/source/versions и закрывает typed summary. Summary не authoritative evidence: facts/units перепроверяются по source refs. Backend не придумывает тему, смысл или ответ за LLM; detokenized/raw history не summary input без raw ingress. Token accounting всех сериализованных prompts/schema/history/media привязан к pinned фактическому profile и tokenizer; резерв ответа/tool result входит до dispatch. Вход не помещается — сначала verified safe summary/минимизация, затем scoped search/уточнение; не silently drop выбранное фото/тему. Неизвестный реальный context capacity/accounting блокирует соответствующий provider mode.

## Общий pinned ProviderCapabilityProfile

Offline schema потребляется ASSIST/KNOW/PG, затем Processor/Gateway без локального изобретения capabilities. Она не создаёт runtime registry и не утверждает actual configuration. Mode gate проверяется для конкретной функции, а не выводится из совместимого API URL.

```typescript
type CapabilityEvidenceV1 = {
  evidenceRef: OpaqueRef;
  kind: "documented" | "measured" | "synthetic";
  revision: Version;
  observedAtUtc: string;
  scope: "public_synthetic_local_stub" | "actual_model";
};
type FeatureSupportV1 = {
  status: "supported" | "disabled" | "unknown";
  evidenceRefs: OpaqueRef[];
};
type ProviderCapabilityProfileV1 = Sealed<{
  schemaVersion: "provider-capability-profile/1";
  profileRef: OpaqueRef;
  profileVersion: Version;
  qualificationScope: "public_synthetic_local_stub" | "actual_model";
  status: "enabled" | "disabled" | "unknown";
  providerCode: string;
  modelCode: string;
  endpointProfile: {
    version: Version;
    endpoint: string;
    transport: "local_stub" | "https";
  };
  wireProfile: {
    version: Version;
    mode: "local_stub" | "responses" | "chat_completions" | "embeddings";
    serializerVersion: Version;
    accountingVersion: Version;
    maxPayloadBytes: number;
  };
  modes: {
    text: FeatureSupportV1;
    tools: FeatureSupportV1;
    vision: FeatureSupportV1;
    structuredOutput: FeatureSupportV1;
    embeddings: FeatureSupportV1;
  };
  contextCapacity: { status: "known"; tokens: number } | { status: "unknown"; tokens: null };
  accounting: {
    status: "known" | "unknown";
    tokenizerVersion: Version | null;
    maxInputTokens: number | null;
    maxOutputTokens: number | null;
    responseReserveTokens: number;
    toolResultReserveTokens: number;
    historyBudgetTokens: number;
  };
  evidence: CapabilityEvidenceV1[];
}>;
```

Profile имеет те же закрытые additional-properties правила. provider/model codes 1–128 allowlisted ASCII `[A-Za-z0-9._/-]`, endpoint ≤ 2048 UTF-8 bytes, без credentials/customer IDs; это trusted metadata, не user URL. Evidence ≤ 32, feature evidenceRefs ≤ 32, ссылки существуют в evidence данного pinned profile; ISO UTC timestamp с `Z`. Tokens/bytes integer bounds выше; known capacity/input/output > 0, unknown имеет null и не получает фиктивные числа. Local stub endpoint — только `local://synthetic-provider`, не network route; codes/profile явно synthetic. Его capacity/accounting берутся из именованной fixture, не называются размером контекста фактической модели.

Для required mode status `supported` требует соответствующего documented/measured evidence для actual-model scope либо synthetic evidence только для local-stub scope. Unknown/disabled блокирует **конкретную** feature. Actual-model status/profile/transport/accounting неизвестны до проверки; public docs сами не доказывают deployed route, runtime support или privacy approval. Capability qualification не заменяет vendor/consent gates. Local stub нельзя передать Gateway как actual-model enabled profile.

Budget inequality: `inputTokens + historyTokens + toolSchemaTokens + mediaTokens ≤ maxInputTokens`; эта сумма + response reserve + tool-result reserve ≤ known context capacity; response reserve ≤ maxOutputTokens; history tokens ≤ history budget. Каждая категория считается один раз по pinned serializer/accounting/tokenizer; tools schemas в input, новые tool results в reserve до следующего compose. Профиль фиксирует ограничения actual выбранной модели, не общеизвестное предполагаемое ctxsize. Mixed profile versions/неизвестная media accounting/невалидные bounds blocked; если профиль не поддерживает нужное фото/structured output, это честный feature отказ. Все stage49/38/50/53 используют один exact profile ref/version в TaskFrame/ToolResult composition.

## Privacy readiness отдельно от evidence coverage

Privacy readiness, ACL/consent/purpose/policy и source freshness — независимые Required guards. Unknown/stale любого dependency **используемого** frame/result/artifact всегда blocked; evidence coverage не отменяет их. `CoverageV1.status=unknown` означает неизвестный охват корпуса, не неизвестную source version и не privacy allowance. Если нужный fragment freshness неизвестен, selected answer тоже blocked.

Partial corpus не блокирует свежий, разрешённый selected photo/entity/fragment и честный limited answer. Для claim выбранного scope нужны valid facts/provenance этого scope; модель формулирует ограничение охвата и может продолжить разрешённый поиск. Для whole-corpus claim coverage соответствующего scope/generation должна быть `complete`, totalUnits известен, inspectedUnits=totalUnits и omitted refs пусты. Search top-K/partial result не доказывает общий итог/отсутствие данных везде. Counts не authoritative quantities: сервер валидирует quantities/units/currency/period по evidence, narrative остаётся у LLM.

| Planned private API | Результат / граница |
| --- | --- |
| `Processor.prepare(AuthenticatedPrivateContext, PurposeCode, PrivateInputRef)` | `ready(SafeRepresentationV1)` либо typed `blocked`; вызов App не получает Gateway envelope |
| `Processor.compose(SafeRepresentationV1[], ProviderCapabilityProfileV1)` | Immutable DispatchEnvelopeV1 после всех offline transforms/final scan; один pinned profile; private source/version registry сохраняется Processor |
| `Gateway.dispatch(DispatchEnvelopeV1)` | Только authenticated Processor; fresh serialized check на каждый attempt; ответ сначала возвращается Processor |
| `Processor.revoke(PrivateScope, RevocationChange)` | Durable revoke/version update в том же serialization domain; `committed` только после соблюдения write ordering |
| `Processor.resolve(OpaqueRef, AuthenticatedPrivateContext)` | Только локально, fresh ACL/tenant/purpose/source generation; blocked/stale не раскрывает existence соседнего tenant |

Все результаты имеют schema version и конечный status: `ready`, `blocked`, `stale`, `unsupported`, `unknown_outcome`, `completed`. `blocked` содержит allowlisted reason code и opaque correlation ref, без prompt/body/source path/PII. `manual_review` — отсутствие automatic allowance, а не обход Required stages. Локальный private UI может показывать уточнение под правами; Gateway/provider его не видит.

## Exact bytes и immutable artifacts

Processor выполняет только pure offline adapter/model formatting, encoding, JSON escaping, base64/media embedding и safe headers/query construction **до** окончательной проверки. Network SDK/credentials/first payload write принадлежат только Gateway. Envelope фиксирует exact bytes/length/SHA-256 каждого payload-bearing канала: request target (method/path/query), payload headers в окончательном порядке/encoding и body. `allPayloadDigest` считается по `uint64be(length(target)) || target || uint64be(length(headers)) || headers || uint64be(length(body)) || body`, без дополнительных separator/newline. Empty channel имеет length 0 и SHA-256 пустых bytes. Artifact bytes либо уже входят в body, либо привязаны exact digest immutable safe delivery profile. Byte length считается по bytes, не символам.

Pinned profile связывает endpoint, method/path/query, model, wire schema, header order/casing/encoding и transport encoding. User-supplied host/model/path запрещены. Nonpayload auth/routing/framing headers отдельно allowlisted Gateway; credentials не относятся к содержимому клиента и не входят в публичный digest. Если custom header/path/query содержит safe text/ref/metadata, это payload channel и он обязательно входит в envelope/scan/guard; называть его техническим header недостаточно.

Gateway повторно проверяет каждый channel length/digest, aggregate digest и pinned profile; пишет ровно эти buffers. Re-encoding JSON, вставка defaults, SDK body/header/query mutation, compression/body rewriting после seal и подмена attachment запрещены. Transport framing/TLS может оборачивать buffers, не менять payload bytes. Неизбежная transform возвращается Processor для нового seal/scan. Credentials добавляются Gateway как отдельные allowlisted secret headers, не в payload/logs. Digest одного body не доказывает safety URI/headers.

Артефакт становится `ready` только после local transform, проверки всех visible/hidden/auxiliary данных и атомарной публикации неизменяемой safe generation. Private originals и safe artifacts имеют отдельные access permissions; ссылка на оригинал, raw signed URL или общий mutable object key не safe ref. Gateway получает internal artifact ref и проверенные immutable bytes через trusted route; provider-facing URL разрешается только отдельным approved safe delivery profile без redirect на private storage. Если такой profile не доказан, URL delivery выключена.

Минимальная каноническая fixture для проверки документа (synthetic, не provider request): UTF-8, без BOM/newline, ровно 56 bytes. Пробелы и порядок keys здесь часть fixture. Универсальная JSON canonicalization не требуется: источник истины — сохранённый финальный buffer.

```json
{"input":"synthetic-safe","model":"profile-bound-model"}
```

SHA-256: `6d89cb6723f858106868c16b90062f6bfbd24d3c4f6140df97c179b91d7fdb2e`. Изменение одного byte, newline, model, UTF-8 encoding, metadata или artifact generation требует нового digest и проверки. Fixtures не получают статус safe для production.

Offline fixture всех payload channels; значения JSON декодируются в UTF-8 buffers, затем применяется length-prefixed aggregate rule выше. Request target 31 bytes, payload headers 27 bytes, body 56 bytes; aggregate с тремя uint64 lengths — 138 bytes. Это проверяет path/query/header coverage до body, не provider request или runtime PASS.

```json
{"requestTarget":"POST /v1/synthetic?scope=opaque","payloadHeaders":"X-Safe-Context: synthetic\r\n","body":"{\"input\":\"synthetic-safe\",\"model\":\"profile-bound-model\"}"}
```

`allPayloadDigest`: `64ecca0da3e6e2e888e5e4188dfa7f9b11446e08fa3ccdec32b9ed38ba6ebab4`. Изменение query/header/body либо порядка/encoding headers требует нового aggregate digest; auth nonpayload headers остаются отдельным allowlisted секретным каналом Gateway.

## Fresh guard: revoke и первая transport write

TTL, JWT expiry, cached approval и ранее успешный prepare не являются dispatch guard. На **каждом** attempt/retry/loop Gateway синхронно обращается к trusted Processor serialization domain; Processor разрешает opaque attempt ref в private dependencies и проверяет свежие ACL/consent/purpose/policy/source generation/artifact readiness/profile. Clock/timeout и unavailable dependency дают отказ. Локальная длительная очередь хранит refs/version intent, не готовое повторно используемое разрешение.

Точка линеаризации dispatch — первая разрешённая запись **любого safe payload** в provider transport: request path/query, payload-bearing header или body, смотря что реально пишется первым. Проверка предшествует этой записи и вместе с ней входит в одну взаимно исключающую критическую секцию относительно durable revoke соответствующего scope/dependency set. Socket connect/TLS handshake без payload не считается dispatch. Если request target/headers пишутся раньше body, последующий body guard не исправляет утечку. Отдельный admission commit до фактической записи не доказывает инвариант.

| Порядок | Обязательный результат |
| --- | --- |
| Revoke durable committed прежде первой payload write | Ноль outbound payload bytes во всех path/query/header/body каналах; attempt blocked |
| Dispatch first write committed прежде revoke | Уже отправленные bytes не отзываются; revoke блокирует следующий attempt/retry/loop и новую source/artifact выдачу |
| Revoke во время compose/очереди/загрузки artifact | Fresh check/serialized write отклоняет stale intent |
| Source edit/delete, ACL или policy epoch изменены | Generation/epoch mismatch; повторная подготовка, без raw/cache fallback |

Для remote Gateway authenticated request/response само по себе недостаточно. Transport writer обязан участвовать в том же ordering: удерживать взаимное исключение до первой write либо доказанного abort, а не получить offline reusable permit. Revoke не подтверждается как committed, пока возможна отложенная write ранее разрешённого writer. Конкретный cross-process механизм поставляет GATE; если невозможно доказать это ordering, данный transport выключен. V1 не требует отдельного глобального Admission Authority или nonce ledger.

Перед write сохраняется durable attempt state `prepared`; после установленной first write — `dispatch_committed`; результат — `completed`/`failed_after_dispatch`. Потеря ответа/timeout/crash между ними означает `unknown_outcome`, а не `not_sent`. Recovery сначала доказывает quiescence старого writer и определяет dispatch boundary; до этого повтор, loop, новый permit и признание revoke applied блокируются. Revoke request остаётся pending с честным статусом, если порядок не установлен; TTL не разрешает старому writer продолжить после revoke. Writer после disconnect/fence loss не имеет права поздней отправки; это отдельный acceptance race test.

Каждый redirect, SDK automatic retry, provider fallback, model-candidate retry и durable job continuation считается новым attempt и проходит новый guard. Внутренние неподконтрольные SDK redirects/retries отключаются; если отключение нельзя доказать — transport не допускается. HTTP 408/429/5xx и ambiguous timeout не разрешают самостоятельный resend. Gateway не кеширует allowance между attempts.

`attemptRef` — локальная корреляция и state machine, не глобальный dispatch nonce anti-replay ledger. Возможные дубликаты физической передачи при unknown outcome не маскируются словом exactly-once. Effect idempotency для mutation/billing/reservation имеет отдельный server-side effect key/state и актуальный preview/confirm; она предотвращает повтор эффекта, но не предотвращает повтор outbound и не заменяет fresh guard. AEAD nonce Vault — третья, независимая сущность.

## Канонические content и source lifecycle

| Content | Target контракт |
| --- | --- |
| Text/schema/tool | Closed safe text/JSON, allowlisted keys/fields; descriptions/examples/system prompts сканируются; tools/result/model args недоверенные; новый loop проходит Processor |
| Media/OCR/PDF | Local RU/EN OCR и pixel redaction, faces/signatures/QR/barcodes/hidden metadata охвачены выбранным verifier; original PDF/неподдерживаемый hidden layer blocked; local rasterization сама по себе не redaction |
| Tool refs/citations | Model видит opaque refs; Processor связывает tenant/purpose/entity/source generation; локальный fresh resolve под ACL; guessed/cross-tenant/stale refs denied; model не выбирает raw source IDs |
| History/summary/memory | Private и safe версии раздельны; detokenized display никогда не записывается обратно в safe history; regeneration после policy/source revoke; summary снова проходит pipeline |
| RAG | Safe chunks/citations и generation manifest с completeness; local ACL/FK/mapping private; retrieval свежих разрешённых generations; raw/legacy/mixed/stale index не fallback |
| Embeddings | Document chunks и query очищены до provider; exact input bytes/profile/dimensions version связаны с generation; vector/metadata tenant-isolated; удаление/revoke блокирует retrieval и следующие embedding attempts |
| Derived artifacts | Parent dependency set/transform/policy versions; readiness всех используемых units, atomic safe generation publish; stale/unknown privacy/ACL/source blocks; corpus partial допускает свежий limited output, но не whole-corpus claim |

`sourceGenerationRef` не является одной timestamp меткой. Processor связывает его с полной source dependency set и текущими ACL/consent/policy epochs. Partial/no-data/unknown facts явно отображаются, не превращаются в verified. Authoritative numbers/units/currency/period/coverage считает сервер; model summary не заменяет completeness.

## T1–T7, отключённые возможности и egress

Coverage — полный сохранённый [inventory](caller-inventory.md), не только assistant chat. Этот документ не повторяет current source verdict и не заявляет, что routes уже перехвачены.

| Surface | Target / до доказательства |
| --- | --- |
| T1 Responses / T2 Chat Completions | Все messages/history/tools/schemas/context через Processor; каждый model/profile attempt свежий; actual route/account unknown blocked |
| T3 embeddings | Safe document/query input, profile/generation gate; каждый backoff/retry и дополнительный profile check guard; raw index недопустим |
| T4 estimate roles | Все семь inventory roles/correction/review paths; org/project/session IDs заменяются private-bound opaque refs; business facts минимальны |
| T5 vision | Final sanitized raster/crops + safe auxiliary text/page/unit metadata; unsafe image/base64 blocked |
| T6 assistant document OCR | Внешний OCR raw pages выключен; local OCR/redaction до safe media dispatch; continuation/job retry guarded |
| T7 PDF fallback | Original PDF/filename/hidden data blocked; explicit usage guard — только usage guard, не privacy proof |
| T7 отдельный context-bearing unit OCR | Сохранён как отдельный root, purpose `estimate_unit_ocr`; наличие operation context не safe marker; local safe unit generation и fresh guard |
| Dynamic/custom bindings/wrappers/cron | Неизвестные registration order/actual profile/SDK wrapper/runtime attribution не объявляются safe; blocked до evidence G2/G3/G4 |

Deny egress применяется к App/workers/ingest/OCR/tools/jobs/RAG/analyzers, включая альтернативные SDK, shell/network paths, redirects, storage callbacks и скрытые fallback routes. Provider credentials доступны только Gateway. Processor/Vault и private originals имеют разрешённые внутренние связи; public knowledge retrieval не получает raw private query. Network/credential proof и key rotation/cutover принадлежат следующим Required задачам, не реализованы G0.

Неизвестные/uncovered capabilities выключены server-side end-to-end. Для incomplete BIM нужны независимые negative proofs всех readers/jobs/tools/RAG collectors/API entrypoints; отсутствие кнопки недостаточно. [DOC-07 / MOSTAI-60](https://prohelper.youtrack.cloud/issue/MOSTAI-60) остаётся Recommended **лишь при доказанном отключении** неполного BIM до release. Сейчас disable proof отсутствует; включение блокировано.

Все 15 proposed tools и 18 capability gaps сохраняют исходные names/purpose/mapping в Foundation/inventory; они Recommended и не становятся callable из-за этой общей safe shape. Контракт существующих безопасных readings/projections остаётся Required. Нельзя включить legal/comparison/BIM расширение под видом PRIV-01.

## Minimal Vault V1 и восстановление

V1 candidate задаёт AES-256-GCM: 32-byte key, 12-byte nonce, 16-byte authentication tag, encrypted durable tenant-scoped mapping. AAD — однозначно сериализованные schema, opaque tenant scope, mapping ref, policy/generation и key version; порядок/encoding фиксируется версией Vault schema. Неуспешная tag verification блокирует resolve до любого plaintext output. Алгоритм и vectors проверяются PRIV/QA при согласовании кандидата; самописная криптография не допускается.

Nonce уникален для **каждого** encryption под одним key, включая retry: durable per-key 96-bit monotonic allocator расходует значение до encryption; failed write его не возвращает. Переполнение/недоступный allocator блокируют запись. После rollback/restore состояния allocator новые encryptions требуют новой key version с новым независимым key; старые ключи остаются только для необходимого decrypt/recovery. Пока нельзя доказать отсутствие nonce reuse, Vault write blocked. Это локальная AEAD nonce allocation, не global dispatch nonce ledger и не enterprise KMS.

Storage не раскрывает plaintext mappings в index/log/cache/queue/backup. Tokens CSPRNG, tenant-scoped, без детерминированного hash PII; collision handling не выдаёт чужой mapping. Resolve проверяет private authenticated actor, tenant, scope, purpose, ACL, expiry/revoke и generation каждый раз. Нет API Gateway для resolve. Удаление mapping либо утрата ключа не дают raw fallback.

Ключ изолирован от БД ciphertext и provider keys; доступ только нужному Processor/Vault identity, rotation versioned. Encrypted backup mappings и защищённый recovery key должны быть восстанавливаемы совместно по утверждённой процедуре с access control и integrity check. Минимальное isolated synthetic restore proof проверяет readable mapping, tenant isolation, revoked state, tamper detection и nonce uniqueness после restore; отсутствие recovery key/proof блокирует токены/private pilot. RPO/RTO и retention не придумываются здесь: нужны решения ответственных для выбранного scope.

Общий Vault key и shared trusted host/kernel — возможные V1 residual risks, не approved решения. Нужны именованный human risk owner, scope/version/date, consequences и mitigation evidence для [G4 / MOSTAI-16](https://prohelper.youtrack.cloud/issue/MOSTAI-16). Product policy approval, поддержка QA и merge документа их не принимают.

## V1 Required / V2 и acceptance evidence

V1 Required: trusted identity/channel, closed safe creation, tenant isolation, immutable exact bytes/artifacts, final leak scan, serialized fresh guard, minimal AEAD Vault/recovery, safe history/tools/RAG/embeddings/media, deny egress/gateway-only keys и honest blocked scope. Operational logs/APM/cache/queues/replays по default не содержат payload; только allowlisted codes/counters/refs. Recoverability не оправдывает сохранение raw wire/private data в debug logs.

V2: отдельная Admission Authority, global dispatch nonce ledger, mandatory envelope/artifact signatures, advanced per-tenant DEK/KEK KMS, full DR/chaos. Они не prerequisites G0/G1/G2, если соответствующий scope не включён и независимый QA не выявил фактический bypass. V1 AEAD nonce uniqueness и mutation effect idempotency сохраняются Required.

Нужные executable evidence после утверждения: forged DTO/untrusted caller denied; known PII при NER miss suppressed; private identifiers отсутствуют во всех wire channels; digest/length/artifact/nested-content tamper denied; per-tenant tokens/resolve denied; source delete/ACL/policy/consent races; revoke before first payload write (query/path/header/body) = zero payload bytes; delayed writer/unknown outcome/retry/redirect/loop blocked; partial scope при fresh privacy разрешён только для limited claim; unknown ACL/privacy/source всегда blocked; photo/transcript/source correlation; private history reentry; stale/mixed RAG/profile; unknown capabilities/accounting и media hidden data unsupported blocked; Vault tamper/restore/nonce uniqueness; deny egress/keys и incomplete BIM end-to-end disable. Это planned tests, а не проведённые runtime proofs.

Подписи ролей PRIV/GATE/DOC/QA на exact candidate commit, критерии и оставшиеся human/release gates записываются в [ownership ledger](g0-ownership.md). Сейчас все pending. Юрист/account-specific vendor/organization consent/runtime unknown остаются за [G2 / MOSTAI-15](https://prohelper.youtrack.cloud/issue/MOSTAI-15), [G3 / MOSTAI-84](https://prohelper.youtrack.cloud/issue/MOSTAI-84), G4. G0 закрывается только по фактическому native acceptance и stakeholder approvals, не по этой фразе.
