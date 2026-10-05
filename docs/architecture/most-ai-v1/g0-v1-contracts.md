# МОСТ AI V1: кандидат контрактов G0

Результат [MOSTAI-13 / LEAD-04](https://prohelper.youtrack.cloud/issue/MOSTAI-13), пакет 02, 05.10.2026. Версия `most-ai-g0/0.1-candidate`. **Status: CANDIDATE; G0 OPEN; stakeholder approvals pending.** Это целевой контракт, не описание реализованного runtime и не разрешение private AI, merge или deploy пакета 02.

## Основание и границы решения

Base: `c7bbe8460fb01808d2e504d6364b6431dccd1138`; ветка `task/mostai-g0-batch-02`. Входы: [source Foundation](foundation-current-state.md), [caller inventory](caller-inventory.md), [product policy](purpose-policy.md), включая `most-ai-v1-purpose-policy/0.2-product-approved-20261005`. Foundation принят в своём техническом scope; его исторические ограничения запуска следующего пакета читаются вместе с новым разрешением, а не переписываются задним числом.

В [исходном чате](codex://threads/01a1054e-e1f6-7c31-bef1-c4aff7ab6cf0) непосредственно проверены human messages `01a10a61-9682-7230-8002-996f27db78f6` (policy, юриста пока нет, Timeweb) и `01a10a6f-8dcb-7e90-9500-e299686c2c83` («Пусть доведут до деплоя и стартуют следующие 3 задачи по готовности»). Последняя команда разрешает начало выбранного пакета MOSTAI-13/79/17 по зависимостям; она не согласует этот контракт, residual risks или реальную передачу данных. MOSTAI-79/17 не разблокируются публикацией кандидата.

Изменяются только этот файл и [ownership](g0-ownership.md). Product/config/routes/providers/manifests/locks/CI/DB/production read-only. Пересверка уже принятого source inventory и успешных deployment checks не повторяется. Имена новых API, типов и файлов ниже — **PLANNED**, не существующая регистрация.

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

```typescript
type PrivateProjectionV1 = {
  schemaVersion: "private-projection/1";
  privateContext: { actorId: number; organizationId: number; projectId: number | null };
  purpose: PurposeCode;
  policyVersion: Version;
  sourceSet: PrivateSourceVersion[];
  fields: ApprovedPrivateFields;
};

type SafeRepresentationV1 = ClosedProcessorCreatedType<{
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

type SafeContentV1 =
  | { kind: "text"; text: ClosedSafeText }
  | { kind: "media"; artifact: ReadySafeArtifactV1 }
  | { kind: "tool"; toolSchemaVersion: Version; arguments: ClosedSafeJson; result: ClosedSafeJson }
  | { kind: "history"; messages: ClosedSafeMessage[]; historyGenerationRef: OpaqueRef }
  | { kind: "rag"; chunks: ClosedSafeChunk[]; indexGenerationRef: OpaqueRef }
  | { kind: "embedding"; inputs: ClosedSafeText[]; embeddingProfileVersion: Version };

type ReadySafeArtifactV1 = ClosedProcessorCreatedType<{
  schemaVersion: "safe-artifact/1";
  artifactRef: OpaqueRef;
  sourceGenerationRef: OpaqueRef;
  artifactGenerationRef: OpaqueRef;
  mediaType: ApprovedMediaType;
  byteLength: number;
  digest: Digest;
  transformVersion: Version;
  verificationVersion: Version;
  readiness: "ready";
}>;

type DispatchEnvelopeV1 = ClosedProcessorCreatedType<{
  schemaVersion: "dispatch-envelope/1";
  attemptRef: OpaqueRef;
  scopeRef: OpaqueRef;
  representationRef: OpaqueRef;
  purpose: PurposeCode;
  policyVersion: Version;
  sourceGenerationRef: OpaqueRef;
  endpointProfileVersion: Version;
  modelProfileVersion: Version;
  wireSchemaVersion: Version;
  bodyByteLength: number;
  bodyDigest: Digest;
  body: ImmutableBytes;
  artifactRefs: OpaqueRef[];
}>;
```

`ApprovedPrivateFields`, `PrivateSourceVersion`, `ClosedSafeJson` и `PurposeCode` — конечные серверные allowlists выбранного caller, не универсальный map/any. Private source versions включают идентификатор источника, revision/generation, ACL epoch, deletion state и completeness; Gateway получает только opaque aggregate generation ref. Processor registry связывает всю dependency set, а не один удачно проверенный chunk. `projectId=null` допустим только для явно organization-scoped purpose; project-required purpose с null блокируется.

| Planned private API | Результат / граница |
| --- | --- |
| `Processor.prepare(AuthenticatedPrivateContext, PurposeCode, PrivateInputRef)` | `ready(SafeRepresentationV1)` либо typed `blocked`; вызов App не получает Gateway envelope |
| `Processor.compose(SafeRepresentationV1[], ApprovedProfileVersions)` | Immutable DispatchEnvelopeV1 после всех transforms/final scan; private source/version registry сохраняется Processor |
| `Gateway.dispatch(DispatchEnvelopeV1)` | Только authenticated Processor; fresh serialized check на каждый attempt; ответ сначала возвращается Processor |
| `Processor.revoke(PrivateScope, RevocationChange)` | Durable revoke/version update в том же serialization domain; `committed` только после соблюдения write ordering |
| `Processor.resolve(OpaqueRef, AuthenticatedPrivateContext)` | Только локально, fresh ACL/tenant/purpose/source generation; blocked/stale не раскрывает existence соседнего tenant |

Все результаты имеют schema version и конечный status: `ready`, `blocked`, `stale`, `unsupported`, `unknown_outcome`, `completed`. `blocked` содержит allowlisted reason code и opaque correlation ref, без prompt/body/source path/PII. `manual_review` — отсутствие automatic allowance, а не обход Required stages. Локальный private UI может показывать уточнение под правами; Gateway/provider его не видит.

## Exact bytes и immutable artifacts

Processor выполняет adapter/model formatting, encoding, JSON escaping, base64/media embedding и safe headers/query construction **до** окончательной проверки. Body digest считается по финальному `ImmutableBytes`, byte length — по bytes, не символам. Профиль связывает endpoint, model, method, path, wire schema, разрешённые headers/query и transport encoding; user-supplied host/model/path не принимаются.

Gateway повторно проверяет length/digest и разрешённый profile; пишет ровно этот buffer. Re-encoding JSON, вставка defaults, SDK body mutation, compression/body rewriting после seal и подмена attachment запрещены. Transport framing/TLS может оборачивать buffer, но не менять entity bytes. Неизбежная transform возвращается Processor для нового seal/scan. Credentials добавляются Gateway как отдельные allowlisted secret headers, не в safe body/logs. Вспомогательные URI/headers/model names также проверяются; digest тела не доказывает их безопасность.

Артефакт становится `ready` только после local transform, проверки всех visible/hidden/auxiliary данных и атомарной публикации неизменяемой safe generation. Private originals и safe artifacts имеют отдельные access permissions; ссылка на оригинал, raw signed URL или общий mutable object key не safe ref. Gateway получает internal artifact ref и проверенные immutable bytes через trusted route; provider-facing URL разрешается только отдельным approved safe delivery profile без redirect на private storage. Если такой profile не доказан, URL delivery выключена.

Минимальная каноническая fixture для проверки документа (synthetic, не provider request): UTF-8, без BOM/newline, ровно 56 bytes. Пробелы и порядок keys здесь часть fixture. Универсальная JSON canonicalization не требуется: источник истины — сохранённый финальный buffer.

```json
{"input":"synthetic-safe","model":"profile-bound-model"}
```

SHA-256: `6d89cb6723f858106868c16b90062f6bfbd24d3c4f6140df97c179b91d7fdb2e`. Изменение одного byte, newline, model, UTF-8 encoding, metadata или artifact generation требует нового digest и проверки. Fixtures не получают статус safe для production.

## Fresh guard: revoke и первая transport write

TTL, JWT expiry, cached approval и ранее успешный prepare не являются dispatch guard. На **каждом** attempt/retry/loop Gateway синхронно обращается к trusted Processor serialization domain; Processor разрешает opaque attempt ref в private dependencies и проверяет свежие ACL/consent/purpose/policy/source generation/artifact readiness/profile. Clock/timeout и unavailable dependency дают отказ. Локальная длительная очередь хранит refs/version intent, не готовое повторно используемое разрешение.

Точка линеаризации dispatch — первая разрешённая запись содержащих body bytes в provider transport. Проверка и эта запись входят в одну взаимно исключающую критическую секцию относительно durable revoke соответствующего scope/dependency set. Socket connect/TLS handshake без payload не считается dispatch. Отдельный admission commit до фактической записи не доказывает инвариант.

| Порядок | Обязательный результат |
| --- | --- |
| Revoke durable committed прежде первой write | Ноль outbound payload bytes; attempt blocked |
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
| Derived artifacts | Parent dependency set/transform/policy versions; readiness всех units, atomic safe generation publish; edit/delete/partial/unknown блокирует dependent output |

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

Нужные executable evidence после утверждения: forged DTO/untrusted caller denied; known PII при NER miss suppressed; private identifiers отсутствуют во всех wire channels; digest/length/artifact tamper denied; per-tenant tokens/resolve denied; source delete/ACL/policy/consent races; revoke before first write = zero bytes; delayed writer/unknown outcome/retry/redirect/loop blocked; private history reentry; stale/mixed RAG/profile; media hidden data unsupported blocked; Vault tamper/restore/nonce uniqueness; deny egress/keys и incomplete BIM end-to-end disable. Это planned tests, а не проведённые runtime proofs.

Подписи ролей PRIV/GATE/DOC/QA на exact candidate commit, критерии и оставшиеся human/release gates записываются в [ownership ledger](g0-ownership.md). Сейчас все pending. Юрист/account-specific vendor/organization consent/runtime unknown остаются за [G2 / MOSTAI-15](https://prohelper.youtrack.cloud/issue/MOSTAI-15), [G3 / MOSTAI-84](https://prohelper.youtrack.cloud/issue/MOSTAI-84), G4. G0 закрывается только по фактическому native acceptance и stakeholder approvals, не по этой фразе.
