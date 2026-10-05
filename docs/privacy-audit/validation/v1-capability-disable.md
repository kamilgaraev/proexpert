# МОСТ AI V1: обязательные поверхности и отключение возможностей

Версия `most-ai-qa79-corpus/0.2-candidate`; **SPECIFIED / NOT RUN**. [MOSTAI-79](https://prohelper.youtrack.cloud/issue/MOSTAI-79). Pinned [caller inventory](https://github.com/kamilgaraev/proexpert/blob/e7a7bd7d19b4d3ce723e24b214187aea67cac6f4/docs/architecture/most-ai-v1/caller-inventory.md) сохраняет исходные paths и uncertain runtime attribution. Таблица ниже не утверждает, что SDK уже перенесены, routes перехвачены или BIM выключен.

Перед квалификацией каждый route получает exact caller/registration/container/SUT/profile/version manifest и state `enabled_qualified`, `disabled_proven` либо `unknown_blocked`. Это evidence states будущего release, не текущая конфигурация. `disabled_proven` требует вызываемых entrypoint negative tests, а не пустой интерфейс/отсутствие кнопки. Неизвестные wrapper/order/account/DNS/runtime scopes не N/A. Scope classification делает owner+независимый reviewer до run.

| ID | Surface и параметризация | Required negative / positive oracle |
| --- | --- | --- |
| CP-01 | T1 Responses: assistant/tool loop/history/images; KnowledgeAssistant; ProjectPulse; spreadsheet mapper/voice transcript/classification | Direct App/workers/Processor SDK/HTTP denied; provider keys отсутствуют; accepted safe path только через trusted Processor+Gateway; each candidate/loop fresh guard |
| CP-02 | T2 Chat Completions: selected alternate binding/custom base URI | Та же identity/egress/final-channel policy; configured alternate не privacy bypass; silent T1→T2 fallback не считать существующим |
| CP-03 | T3 embeddings: document index/query, stored-profile query, backoff/recovery/backfill/reindex CLI | Document/query до embeddings safe; profile/dimensions/generation совпадают; каждый retry/profile attempt fresh; raw/mixed/stale index недоступен |
| CP-04 | T4 семь estimate roles, correction/review/workflow attempts | Org/project/session/evidence IDs не wire; все role payload/system/schema stages safe; usage/cost guard не privacy approval |
| CP-05 | T5 Vision raster/PDF page/crop/auxiliary text/filename/page/unit metadata | Полная privacy verification выбранных units; unsupported hidden/low confidence blocked; raw base64 не allowance |
| CP-06 | T6 document OCR quote/confirm/job/retry | Local OCR/redaction перед safe generation; внешние raw pages blocked; budget confirmation не privacy consent |
| CP-07 | T7 raw-PDF fallback **и отдельный context-bearing unit OCR** purpose `estimate_unit_ocr` | Original PDF/filename/hidden data blocked; operationContext не safe marker. Без context source path historically throws before HTTP; это не proof отправленного whole PDF. Unit OCR требует своего safe projection/profile/guard |
| CP-08 | Dynamic bindings/alternate SDK/HTTP/shell/cron/Horizon/worker/scheduler/queued continuation | Registration order/runtime owner unknown→blocked; прямой egress и keys недоступны каждому identity, включая retries/redirects/storage callbacks |
| CP-09 | Incomplete BIM readers/jobs/indexers/tools/RAG collectors/API/admin/client/export | All enabled entrypoints refuse incomplete generation; partial не `verified`. До complete proof фича выключена server-side end-to-end |
| CP-10 | DNS/rebinding/IPv6/redirect/host network/custom proxy/shell и operational sinks | Network policy отрицательно проверена для каждой identity/address family/route; secrets/payload не logs/APM/failed_jobs; no hidden route |
| CP-11 | Gateway/raw storage/Vault/private DB; Processor/Internet/provider key | Gateway denied raw/mappings/key/resolve; Processor offline only local runtimes/serializers, no AI Internet/credentials; safe storage separate access |
| CP-12 | 15 proposed document/comparison/legal tools +18 gaps, newly installed unknown format/feature/profile | Наличие в roadmap не registration/implementation; finite allowlist отказ. Recommended/BIM не gate V1 только при доказанном отключении всех связанных входов |

CP-04 roles: `LLMNormativeCandidateReranker::rerank`, `AttemptAwareCrossDocumentFactArbitrator::arbitrate`, `AttemptAwareWorkCompositionLlmClient::chat`, `TimewebProjectSynthesisModel::synthesize`, `TimewebEstimateAuditModel::audit`, `TimewebEstimateComposerModel::compose/correct`, `AttemptAwareCompletenessArbiter::review`. Дополнительный corrected/durable retry — новый attempt. Каждый role должен иметь отдельную строку manifest/capture; один T4 smoke не покрывает семь roles.

## BIM disable oracle

Synthetic generation G1complete и G2incomplete: отсутствует unit, failed extraction/index job, wrong material/unit, stale/source delete, incomplete manifest, tenant ACL revoked. Для каждого reader/API/tool/collector/job/recovery/backfill/client route вызвать G2 напрямую и после cache/restart; refused/blocked,0 provider attempts,0 indexed/retrieved unchecked units, no `verified`/complete claim. G1control допускается только если feature реально включена и полностью квалифицирована; disabled feature честно refuses и G1. UI hidden не evidence network/server disable.

DOC-07/MOSTAI-60 остаётся Recommended при доказанном disable; иначе discovered bypass блока V1 эскалируется Lead. Новые и ранее неучтённые bindings добавляются к manifest до release и получают case, вместо молчаливого exclusion. Полный список15 proposed names и18 gaps хранится в pinned Foundation/inventory; данный corpus не регистрирует их и не создаёт duplicate product fix PR #909.

## Evidence и пределы

Для каждого CP сохранять owner route/identity/container config version, controlled invocation, captured network/secret/storage denial, positive approved-path trace либо deliberate disabled status, соответствующие TH/DR/VA cases, independent verdict. Network proof требует будущего изолированного runtime, а не source grep. Сегодня все CP `not_run`; actual inventory/config/private traffic не переизмерялись.

Local PG fixtures не обращаются к этим transport sinks: stub/public/synthetic, эффекты off. Acceptance enabled modes/profile/token accounting проверяется отдельно; неизвестная required vision/tools/embedding capability блокирует этот mode. [Threat metric protocol](v1-threat-corpus.md), [race oracle](v1-dispatch-races.md), [V2](v2-deferred-matrix.md).
