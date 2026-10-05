# МОСТ AI V1: revoke и dispatch races

Версия `most-ai-qa79-corpus/0.2-candidate`; [MOSTAI-79](https://prohelper.youtrack.cloud/issue/MOSTAI-79). **SPECIFIED / NOT RUN.** Вход G0 `e7a7bd7d19b4d3ce723e24b214187aea67cac6f4`, [контракт:394](https://github.com/kamilgaraev/proexpert/blob/e7a7bd7d19b4d3ce723e24b214187aea67cac6f4/docs/architecture/most-ai-v1/g0-v1-contracts.md#L394). Это будущий harness contract, не созданный Gateway и не измеренная concurrency proof.

## Детерминированный harness

Использовать управляемые barriers, два/несколько writers и durable revoke/source store doubles в local test; sleep/TTL не доказательство ordering. Порядок основан на сохранённой sequence в общем serialization domain, не сравнении wall clocks разных процессов. Capture отмечает request-target/query/payload-header/body/artifact channel, attempt/scope/source/profile versions и событие первого payload write. Auth/framing отдельно allowlisted; контент нельзя переназвать transport metadata.

События: `prepare`, `guard_enter`, `dependencies_checked`, `before_first_payload`, `first_payload_written`, `dispatch_committed`, `abort_proven`, `writer_quiescent`, `revoke_requested`, `revoke_committed`, `unknown_outcome`, `recovery_finished`. Durable prepared сохраняется перед write; потери после первого write не обозначаются `not_sent`. В capture нет private principal IDs. Connect/TLS без application payload не dispatch.

Параметры каждого relevant case: first channel target/query/header/body; empty body profile; source edit/delete/ACL/policy/consent revoke; process disconnect/fence loss/crash; same/cross-process; each T1–T7 route из [manifest](v1-capability-disable.md). Все параметры listed до run; непроверенный включённый writer блокирует route. Большое число body-only runs не покрывает request line/header.

| ID | Контролируемый interleaving | Oracle | Требуемое evidence |
| --- | --- | --- | --- |
| DR-01 | Barrier перед guard; revoke durable commit; resume writer | Blocked;0 payload bytes всех channels | Revoke sequence < first allowed write; capture count0 |
| DR-02 | Prepare/compose завершены; barrier перед request-target/header write; commit revoke; resume | Cached prepare не allowance;0 payload bytes | Digest/profile + guard/refusal event; raw headers не ушли |
| DR-03 | Guard получил checked dependencies; writer paused до first payload, concurrent revoke | Один serialization order: revoke wins→0 bytes; write wins→committed dispatch, revoke blocks next attempts | Mutual exclusion/fencing trace; отсутствие offline permit gap |
| DR-04 | First target/header payload уже written; abort до body; затем revoke | `dispatch_committed`/`failed_after_dispatch`, не `not_sent`; уже written bytes не отменяются; следующие attempts blocked | First-channel bytes и durable outcome; empty-body тоже dispatch |
| DR-05 | Source/ACL/policy/consent epoch меняется при compose/queue/artifact loading | Mismatch fresh dependencies→blocked/reprepare; no raw/cache fallback | Exact dependency set/epoch и0 write для stale intent |
| DR-06 | Guard/registry/serialization domain unavailable; clock/timeout/fence invalid | Refusal,0 bytes; expiry/cached JWT не permit | Dependency outage injection, writer terminated/fenced |
| DR-07 | Crash между prepared и established write/commit либо lost acknowledgment | `unknown_outcome`; no resend/loop/new permit/revoke-applied до quiescence и boundary determination | Durable journal до/после; old writer не может поздно писать |
| DR-08 | Recovery заявляет quiescence, старый writer reconnect/late callback | Late payload denied; recovery proof не просто elapsed TTL | Writer token/fence identity и negative delayed-write capture |
| DR-09 | 408/429/5xx/redirect/model fallback/automatic SDK retry | Каждый новый attempt проходит новый fresh guard; uncontrolled SDK auto retry/redirect disabled либо route blocked | Attempt sequence/count; revoke между attempts даёт0 next payload |
| DR-10 | Long queued job/durable continuation/tool loop после revoke | Queue хранит refs/intent, не reusable allowance; next attempt blocked | Job/loop source refs, generation, new guard event |
| DR-11 | Crash after canonical effect; double/concurrent confirm; network outcome ambiguous | Один effect по independent effect key; это не exactly-once outbound; fresh preview/confirm и guard Required | Local effect journal/receipt, не provider text; реальные effects здесь off |
| DR-12 | После scan/seal mutate query/header/body/artifact/order/encoding/deep nested object | Before write length/digest/profile/generation mismatch либо immutable copy unchanged | Per-channel/allPayload digest и captured actual buffers |

Разрешённый контроль: свежий trusted intent под активными dependencies допускает одну запись только в isolated security capture sink после guard; следующий attempt снова проверяется. **PG до Gateway всегда оставляет provider/effects off**, поэтому такой transport control не объявляется выполненным product stub run.

Синтетические каналы G0: target31 bytes, headers27, body56; aggregate138 с `uint64be(length)||bytes` для каждого. Фиксированный digest из G0 проверяет framing/coverage, но не race ordering. Любая transform после seal требует нового Processor scan/representation. Проверка `allPayloadDigest` и0 bytes не взаимозаменяемы: digest может совпасть у неразрешённого request.

## Release oracle

Mandatory observed violation count0: revoke committed-before-first-payload →0 payload; no delayed writer after proven abort/fence loss; no uncontrolled resend; no fake completed/not_sent/ exactly-once; new attempts after revoke blocked. Неопределённость outcome удерживает pending и блокирует освобождение до proof. Прямой admission commit без записи не линеаризация dispatch.

Journal/capture/hardware/SUT/G0/profile versions и все interleavings должны быть повторяемы; минимум по одному run на каждую обязательную schedule cell, stress repeats добавляются отдельно и не заменяют controlled schedule. Runtime verdict принадлежит независимой приёмке80/81/82/G1, не автору harness. [Общий metric protocol](v1-threat-corpus.md) и [V2 distinction](v2-deferred-matrix.md).
