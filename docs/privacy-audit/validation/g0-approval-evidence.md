# МОСТ AI: вход G0 и пределы corpus acceptance

Версия corpus `most-ai-qa79-corpus/0.2-candidate`, 05.10.2026. [MOSTAI-79](https://prohelper.youtrack.cloud/issue/MOSTAI-79); автор QA, постоянный чат `01a105d0-7673-75b0-a2a7-d8bc9b28ca8c`. **Corpus candidate / independent review pending.** Авторская проверка документов не является независимой приёмкой своей задачи.

## Принятый вход

- Contract `most-ai-g0/0.2-candidate`, ownership `most-ai-g0-ownership/0.2-candidate`.
- Exact frozen input commit `e7a7bd7d19b4d3ce723e24b214187aea67cac6f4`, [PR #917](https://github.com/kamilgaraev/proexpert/pull/917).
- [Contracts](https://github.com/kamilgaraev/proexpert/blob/e7a7bd7d19b4d3ce723e24b214187aea67cac6f4/docs/architecture/most-ai-v1/g0-v1-contracts.md), [ownership](https://github.com/kamilgaraev/proexpert/blob/e7a7bd7d19b4d3ce723e24b214187aea67cac6f4/docs/architecture/most-ai-v1/g0-ownership.md) читаются из pinned Git object. Они не cherry-picked и не дублируются в diff QA-01.
- `CANDIDATE`/pending в frozen документе описывает состояние при публикации. Фактическое subsequent documentary acceptance авторитетно в [MOSTAI-13](https://prohelper.youtrack.cloud/issue/MOSTAI-13); оно не превращает source text в runtime proof.

| Actual native evidence | Reviewer / scope | Exact input verdict |
| --- | --- | --- |
| MOSTAI-13 comment7-270, 2026-10-05T15:38:37Z | Codex GATE, чат `01a105d0-85af-79b3-b0d7-9b0aee716873`; identity/wire/guard/profile/ownership | approved documentary GATE на e7a7bd7… /0.2 |
| MOSTAI-13 comment7-271, 2026-10-05T15:39:05Z | Codex PRIV, чат `01a105d0-7c30-7090-b0b7-1d06333357f9`; closed projections/refs/readiness/coverage | approved documentary PRIV на том же exact SHA/version |
| MOSTAI-13 comment7-272, 2026-10-05T15:39:53Z | Codex DOC, чат `01a105d0-9998-7653-b9fd-77ffb5c67c7c`; artifacts/media/source provenance/coverage | approved documentary DOC на том же exact SHA/version |
| MOSTAI-13 comment7-273, 2026-10-05T15:40:05Z | Независимый Codex QA; author LEAD отдельный; guard/schemas/partial/PG/Required/V2 | approved documentary QA на том же exact SHA/version |
| MOSTAI-13 comment7-274, 05.10.2026 | LEAD; проверены все четыре записи напрямую | Documentary G0 accepted/frozen; Test/Ready Yes; не Done до разрешённого merge |
| MOSTAI-79 comment7-275, 05.10.2026 | LEAD; actual assignment в текущем пакете02 | Develop/Ready No; accepted artifact разблокировал79/17 без fake Done13 |

История human decisions: message `01a10c87-1b2f-7240-b644-86c9312c9c49` в исходном coordinator напрямую прочитан QA; обновить YouTrack и продолжить bounded автономную работу до Gateway. Protocol этой ревизии задавал пакет02=13/79/17, затем49/38/50, independent local PG53, Gateway coding после PG. Routine branch/implementation/checks/commit/push/reviewable PR были разрешены; тогда merge/deploy новых пакетов оставались отдельным решением пользователя. Policy message01a10a61 не юридическое/customer/vendor/host residual approval; прежнее merge/deploy разрешение относилось к пакету01.

Последующая прямая команда пользователя `01a10cb5-1c57-70c3-ab08-bd2be5303c58` проверена через read_thread: три задачи → три независимых reviewers → штатный deploy → следующие три, полный stop при необходимом человеческом решении. Она заменяет прежнее ручное решение каждого обычного Required пакета. Root запускает единую group correctness/security/product из3 read-only reviewers на frozen current heads; после3PASS и required checks Lead выполняет штатный squash/CI deploy, smoke и cleanup. Автор QA не sole approver своего corpus и не запускает дублирующую review group. Private pilot/G3, human residual/G4, новый неподтверждённый scope/legal/vendor/risk/production rollout остаются отдельными решениями.

Heartbeat Root и общий `MOST_AI_MONITOR_STATE.json` контролируют globalStatus/epoch: перед новым write/commit/push/PR/spawn/release только `RUNNING` разрешает действие; missing/unreadable/stop state блокирует. QA сохранил ARMED ACK epoch1 вне репозитория; общий state не менял. При stop кооперативно остановить собственные helpers/subagents, сохранить работу и STOPPED ACK current epoch только после фактической остановки. Таймер/агент не снимает latch; чужая SQL работа не останавливается. Эти процедурные изменения не меняют frozen G0 contract SHA и не считаются runtime proof.

## Воспроизводимый source mapping

Fresh QA-01 base после fetch `b579f338bb2886ea40e40c3a1059c1b3df125871` (main, 05.10.2026). Ветка `task/mostai-qa-01`, owner QA. Точные corpus commit/PR/check outputs записываются в native79 после commit, чтобы не создавать self-referential HEAD в файле. Scope заранее зарегистрирован в79: только шесть этих Markdown и новый [v1-product-fixtures.json](v1-product-fixtures.json). Shared files/lease отсутствуют; основной checkout и LEAD worktree не менялись.

| Source snapshot | SHA-256 / canonical mapping |
| --- | --- |
| Privacy audit `MOST_AI_PRIVACY_AUDIT.md` | `bcfe118e8a5c01e635a193172a8669a42e2201efb819146f9b0efdd5eed5da10`; PR01–15 = matrix §13, detailed source inventory/historical limits preserved |
| Assistant audit `most-assistant-analysis.html` | `b391f9d43d55180562f1c0c385eaa4d38455fe7fbbba9ab937d7bf69d71b7597`; AS01–10 = `report-data.findings[]`1–10 |
| Pinned Foundation/inventory | [Foundation](https://github.com/kamilgaraev/proexpert/blob/e7a7bd7d19b4d3ce723e24b214187aea67cac6f4/docs/architecture/most-ai-v1/foundation-current-state.md), [inventory](https://github.com/kamilgaraev/proexpert/blob/e7a7bd7d19b4d3ce723e24b214187aea67cac6f4/docs/architecture/most-ai-v1/caller-inventory.md); original source verdict не revalidated runtime здесь |
| UX01–06 | Human05.10.2026 + updated native79/protocol; observable families в [mapping](v1-threat-corpus.md) и JSON; не numbered findings старого audit |

## Что готовит QA-01

| Artifact | Достаточность candidate / будущий verdict |
| --- | --- |
| [v1-threat-corpus.md](v1-threat-corpus.md) | PR/AS/UX coverage, negative/allowed controls, заранее metric protocol |
| [v1-dispatch-races.md](v1-dispatch-races.md) | Детерминированные interleavings, first payload всех channels, unknown outcome/late writer/retry/effect distinction |
| [v1-vault-recovery.md](v1-vault-recovery.md) | AEAD/tamper/tenant tokens/nonce/restore/key custody; никакой криптореализации |
| [v1-capability-disable.md](v1-capability-disable.md) | T1–T7, отдельный context-bearing OCR, семь T4 roles, BIM все entrypoints, egress/keys/storage negatives |
| [v2-deferred-matrix.md](v2-deferred-matrix.md) | Deferred scope отдельно; V1 compensation не ослаблена |
| [v1-product-fixtures.json](v1-product-fixtures.json) | Synthetic input sequences и semantic/factual oracles, distractors, local stub profile, per-case quality/evidence assertions |

Все TH/DR/VA/CP/product runtime cases `not_run`, metric observed values `null/not_measured`. JSON — corpus format, не G0 wire DTO и не trusted factory output. Photo descriptor/transcript/facts — synthetic local-stub input, не прошедший настоящий OCR/redaction image. Candidate validation проверяет синтаксис/refs/oracles/арифметику и полноту mapping, не безопасность модели/transport/Vault.

## Независимая приёмка и release gates

После commit/push Lead назначает reviewer, отличный от автора QA. Record: corpus exact commit/version, input G0/profile/schema versions, scope/files, reviewer role/chat, UTC, evidence links, findings и actual verdict. Material change G0 требует Lead и нового acceptance; corpus thresholds/fixtures после review тоже freeze/versioned. Здесь запись собственного независимого approval не создаётся; Ready No/Review pending до actual verdict. Self-check не обновляет private runtime PASS.

Corpus deliverable acceptance = спецификация достаточно покрывает threats и product assertions, expected results не подменяют будущие observed. Local PG53 после49/38/50 проверяет orchestration/public-synthetic stub only, не actual-model quality/ctxsize/privacy. MOSTAI-80/81/82 иG1 повторно принимают actual-model/history/refs/OCR/embeddings/RAG/response/wire/effects; G2 legal/vendor/runtime/customer applicability, G3 authorized pilot иG4 named human residual/release не закрыты. V2 optionality не освобождает включённый V1 path от mandatory proofs.

Migration/config/DB/production/CI/provider/effects здесь отсутствуют. После разрешённого merge/deploy уборка QA task branch/worktree по AGENTS с проверкой current tips/PR head/integration/active owners; до merge сохраняются одна чистая ветка и один worktree для review. Source audits и принятые G0 документы не меняются.

## Round1 PQA-01 и candidate0.2

Root product review `e8820ffb9a3235e0e3c075b975ed2106c346913a92ed1a7f8594494c4856c953` / native79 comment7-299 выявило P2 на старый corpus HEAD `30876284cb02a0aadbb7de0ca1e9c535c65be097`: noncritical natural_answer/followup_narrative допускали field dump при верных фактах и polish4/5/macro59/60. Candidate0.2 переносит смысловые product invariants в critical gates всех применимых PG cases/variants; любой failed/missing mandatory assertion блокирует capability независимо от polish. 4/5 иmacro90% сохранены только для noncritical presentation polish. Exact wording не требуется, релевантные таблицы и естественные scoped partial/refusal ответы допустимы.

В JSON semantic_gate_counterexamples сохранены12 hand-labelled synthetic примеров для отдельного generic gate probe: field/ref dump с верными фактами, template substitute, irrelevant estimate, unwarranted corpus refusal и missing mandatory evidence требуют FAIL; concise/paraphrased natural, partial, legitimate privacy refusal и relevant table допускают PASS gate при выполненных остальных assertions. Probe сравнивает frozen старый corpus и новый, включая legacy4/5/macro59/60 counterexample. Это проверка логики corpus на заранее размеченных synthetic observations, не semantic classifier/actual-model benchmark и не независимый approval автора. Execution status примеров not_run относится к будущему model/semantic run; offline gate receipts отдельно в79. Новые exact SHA/receipts в79; Root переиспользует свою group для нового frozen bundle. Старые round1 verdict исторические, не carryover PASS. Runtime cases NOT RUN/observed metrics NOT MEASURED и frozen G0 E7 неизменны.
