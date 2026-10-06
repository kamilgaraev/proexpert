# Public core: source-контракты MOSTAI-34

Версия `public-core-authority/0.3-candidate` в разработке. Это source preparation пакета 04, не runtime activation и не выполнение полного MOSTAI-34/G1. Coordination input: `most-ai-public-core-coordination/0.2-candidate`, SHA-256 `b61940f7298d460ab23c5d6cbb7da6d7c88ff3a166fac0322ff09bc1ddba97be`.

## Закрытый registry

`RegisteredPublicFixtureRegistry::compiled()` создаёт конечный manifest без метода arbitrary registration. `resolve(fixtureId, fixtureVersion, inputId)` принимает только точную тройку. `input_id` равен `scenario_step`, `scenario_id` содержит fixture и input, версия сценария `public-core-scenario/1`. Row digest — SHA-256 canonical JSON строки до добавления `record_sha256`; question digest — SHA-256 точных UTF-8 bytes `display_text`. Canonical JSON: без escaping Unicode и slash, без пробелов/newline, в порядке ключей producer.

| Fixture / version | Inputs | Corpus и ограничения |
| --- | --- | --- |
| `material-search-v1` / `public-material/1` | `price-b25`, `quote-12m3`, `cement-price`, `no-results` | Material38: В25 7800.00 RUB/m3, В30 8250.50 RUB/m3; В15 не имеет подтверждённых currency/basis. Для 12 m3 В25 oracle 93600.00 RUB. |
| `public-photo-metadata-v1` / `public-photo-metadata/1` | `photo-explain`, `photo-followup-one`, `photo-followup-two` | Только явно учебная текстовая расшифровка. Нет pixels, OCR/redaction или actual vision proof. |

Полные строки, source generations, records и SHA-256 доступны через `manifest()`/`manifestDigest()`. Producer handoff экспортирует этот manifest вместе с проверенным Git HEAD; только Lead acceptance разрешает consumer binding. `concrete-quantity-v1` PRIV17 с 12.5 m3 и старый QA79 oracle 4800/57600 не входят в этот registry и не подменяют Material38. Manifest digest включает всю совокупность records/inputs; любое изменение делает старую session source binding недействительной.

## Real viewer и synthetic realm

`PublicCoreSessionAuthority::openOrResume(trustedViewerBinding, selection, publicSessionRef)` получает только registered selection: `fixture_id`, `fixture_version`, `input_id`, UUID `request_id`. Все лишние ключи, включая message/context/history/uploads/actions/real IDs, отклоняются. Session/request/conversation refs выпускаются сервером. UUID обеспечивает viewer+organization-bound idempotency; новая тройка с тем же UUID конфликтует.

Current viewer callback — доверенный backend adapter/TCB, не клиентский параметр HTTP. Он независимо проверяет серверное credential binding и возвращает ровно `authorized`, `viewerRef`, `organizationRef`, `authorizationRevision`, `policyRevision`. Отсутствующий, неисправный, неполный или отозванный adapter блокирует действие. На каждую операцию проверяются текущие права, policy revision, source generation, ownership и lifetime; expiry не заменяет current authorization.

Literal synthetic IDs 7/11/13 принадлежат только fixture realm Material38. Они никогда не используются для авторизации реального viewer или ERP resolve. Реальный viewer с таким же числовым ID допустим только после независимого backend proof. Ledger сохраняет keyed owner digest, а не credential или открытые viewer/organization IDs; публичный результат содержит только opaque refs.

## Receipt ledger и callback protocol

`PublicCoreReceiptStore` требует отдельный role-local directory и control key не короче 32 bytes. Это ключ внутренней integrity, не AI provider credential. Default store не доступен. State HMAC проверяется до чтения; запись использует fsync и atomic replacement. Все операции fenced отдельным стабильным `authority.lock`; JSON ledger заменяется, lock path не заменяется. Пределы: 1 MiB ledger, 128 sessions, 32 requests/session, 256 receipts; превышение не даёт acknowledgement.

`SessionAuthority::publisher(binding, requestRef, currentCoreBinding)` выдаёт request-bound store. Current core binding — доверенный adapter для существующих exact Context49 source/scope/profile/aliases/sources; его реализация и приемка не заменяются успехом unit double. Store API: `publish(event, data, expected)`; события только `lineage`, `stage`, `commit`, `abort`, `final_guard`. `begin/candidate/ack` не являются callback API.

Stage/commit ack выдаётся лишь после соответствующего сохранения в ledger. Receipt соответствует `assistant-context-receipt/1`; expected — `contextRef`, `payloadDigest`, SHA-256 exact canonical receipt. `final_guard` проверяет committed receipt, current owner/source/profile/snapshot/request/lifetime и возвращает exact `assistant-context-final-guard/1`. Lookup `authority(contextRef)` читает authoritative committed state, а не UI JSON. Abort удаляет matching receipt; replay/changed digest/profile/lineage или revoked request не получают commit/final guard.

Current backend readers и publisher являются Processor-only configuration, не endpoint для app/model. PHP-классы, каталог, HMAC и префикс namespace сами не доказывают это разделение. На данный момент producer/runtime adapters не подключены; все положительные local tests относятся к явно внедрённым test readers и private TEMP ledger.

Current core binding v0.2 требует ровно `scope`, `snapshotHash`, `profileFingerprint`, `registryDigest`, `aliases`, `sources`, `trustedModelProfile`. Последнее поле — независимо загруженный текущий полный 11-field trusted profile, не копия receipt/model payload. Store разрешает его через frozen `AssistantModelContextProfile::resolve`, проверяет исходный full-profile fingerprint и сравнивает receipt `modelProfile` с native `modelPayload()` (8 полей). Fingerprint и receipt digest не пересчитываются по сокращённому payload и не заменяются. Отсутствующий/изменённый trusted profile, forged payload/fingerprint и 11-field receipt вместо native payload блокируются. Старый six-field current binding v0.1 несовместим и не получает allowance; consumers должны принять новый exact producer handoff. Frozen Core не изменён.

## Условная source composition

Runtime default остаётся закрыт: `unavailable`, `runtime_not_activated`, `model_enabled=false`, `actual_model=null`, `private_ready=false`. Readiness получает только приватно внедрённые profile/proof/tokenizer readers. `local-source-test` qualification разрешает source-тесты с local-stub, но не меняет публичные actual readiness/model/capabilities. Live branch требует current actual qualification, profile16 fingerprint, registry digest и независимые references на backend-fence, identity, channel, egress, secret и activation evidence. Эти private TCB readers обязаны проверять доказательства; наличие строк/reference само по себе ничего не доказывает. Сейчас actual readers не подключены.

`coreProfile()` явно проецирует profile16 в trusted Core11 с `qualification=offline-synthetic`. Native payload8, Core11 fingerprint и Gateway16 fingerprint сохраняют разные значения. `count()` отдельно проверяет соответствие model/tokenizer identity4 и mapping evidence. Source byte counter в тестах никогда не выдаётся за actual tokenizer.

`PublicCoreProcessor::handle(command,payload,verifiedPeer)` принимает только `readiness`, `open_or_resume`, `execute_owned`, `lookup_owned`. `verifiedPeer` содержит kernel `pid/uid/gid` из trusted channel, а не body/header/environment role. Приватный peer reader должен независимо связать их с app identity. Protected payload передаёт `viewer_ticket_ref`; приватный backend reader преобразует его в server-owned viewer binding. Пользовательский HTTP DTO этого поля не принимает. Все прочие параметры остаются закрытым registered selection/owned reference. В role-local ledger сохраняется только exact `{viewerTicketRef}` с тем же opaque server-issued ticket; raw credentials, viewer IDs и client authorization flags не допускаются.

`executeOwned()` сохраняет claim до composition. Native composition factory должна вернуть ровно `loop`, `profileRef`, `refs`, `sourceState`, `runtime`; `loop` обязан быть genuine `AssistantLocalLoop`. Она получает server request/viewer/store/session/readiness и текущий session runtime; никаких фабрик из пользовательского ввода. Snapshot/projector/tools/semantic/native-authority остаются ответственностью already-leased93 ContextBindings. Их exact accepted construction API ещё требуется для подключения. Processor сохраняет manifest generation и отдельный randomized runtime corpus generation. Потерянный runtime после restart и существующий running claim блокируются; завершённый результат читается только после повторной current authorization.

`projectForDispatch(nativeInput6,nativePrivateBinding6,qualifiedProfile16)` проверяет native receipt через frozen `AssistantContextReceipt::consume`, current publisher/owner/source/profile и создаёт immutable `GatewayModelRequest`. Provider body canonical bytes, payload/receipt/projection digests и отдельный Gateway fingerprint сохраняются вместе с opaque attempt/admission/projection refs. Один request имеет максимум12 attempts. Legacy `dispatch(contextRef,writer)` allowance не даёт.

`withDispatchFence()` в текущем WIP проверяет exact persisted prepared attempt и checkpoint-ит consumed state до operation. `currentBinding()` читает уже удерживаемый state; `withGatewayFence()` не входит повторно в flock. Replay, restart без corpus, другой owner/profile/generation и uncertain send не разрешают отправку или новую попытку; ранее подготовленные попытки также блокируются после uncertain send. Синхронные тесты используют только accepted baseline27 `b521ca62895d03df77b0afcbfad30ff684fb6270` и simulated TCB.

**Оставшийся B04-UPLOAD-CRITICAL-LIFETIME-03:** actual backend и private ledger locks нельзя держать до полной model response. Они должны освобождаться после проверенного полного upload и cancellation ordering, до `WAITING_RESPONSE`; затем нужен fresh guard для следующей attempt/final output. Current whole-response baseline test seam не является принятым runtime adapter. Root/Lead control candidate `batch04-channel-control-contract-v02.json` SHA256 `88cdfe80e0f7df9bd1d7bf1c4d306a6e16eb98d98d4b3b621df6363778bce8e8` пока не tested producer acceptance. New27/93 hooks и concrete lock strategy ещё не потребляются; source34 и whole-chain source readiness не объявлены.

`processor/public-core/entry.php` позволяет private CLI bootstrap передать typed Processor и trusted serve function. Они вызывают command handler; body/query не выбирают bootstrap, роль или adapters. Прямой запуск остаётся unavailable readiness (HTTP503), без Laravel/bootstrap, ENV/provider key или network calls. Shared authenticated channel ещё не привязан: GATE владеет реализацией, consumption требует accepted exact tested handoff.

## Release prerequisites

До любого фактического dispatch нужны принятый exact producer HEAD, qualified model/profile/tokenizer, реальный current authorization source и доказанные отдельные workload identities, control-key/secret mounts, authenticated Processor→Gateway channel, ledger ownership и deny egress для app/workers. Gateway не получает real viewer binding/Vault/raw history. Source-код, chmod, filesystem lock и local HMAC tests не являются physical isolation/NTFS ACL/network proof; common host/kernel остаётся отдельной границей доверия.

Ни инфраструктура/compose/workflow/.env, ни старые factories/contracts/callsites не меняются этим source scope. Full MOSTAI-34, private G1/53, actual-model/OCR/RAG/wire/effects не Done. Runtime interop и разрешение модели требуют нового принятого handoff; source PASS не переносится как actual-model PASS.

## Проверка source scope

Bare PHPUnit без Laravel/DB bootstrap проверяет registry mappings/digests, immutable snapshots, current viewer/session ownership/idempotency/revoke/expiry, actual durable stage/commit/abort/final_guard, HMAC tamper/wrong key, lock fencing и zero writer invocation. Feature-named isolation suite остаётся локальным source test; она не выполняет deployment/production/egress проверки. Собственные tools/config/cache/fixtures находятся в task TEMP вне checkout. Target PHP 8.2, strict_types; source lease включает десять точных путей.
