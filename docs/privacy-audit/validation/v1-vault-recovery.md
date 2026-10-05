# МОСТ AI V1: tokens, Vault и recovery

Версия `most-ai-qa79-corpus/0.1-candidate`; **SPECIFIED / NOT RUN**. [MOSTAI-79](https://prohelper.youtrack.cloud/issue/MOSTAI-79), вход [G0:448](https://github.com/kamilgaraev/proexpert/blob/e7a7bd7d19b4d3ce723e24b214187aea67cac6f4/docs/architecture/most-ai-v1/g0-v1-contracts.md#L448). Ни encrypt/decrypt, ни restore runtime здесь не запускались.

Все mappings, keys, names/canaries генерируются изолированно для теста; production key/backup/data не читаются. Fixed vectors не operational keys. Approved library vectors/key access/backup/nonce allocation проверяют PRIV и независимый QA позже; corpus не реализует криптографию. Trusted-host/common-key risks требуют человеческого решения G4, а residency/account applicability — evidence G2; они не принимаются этим файлом.

| ID | Fault / stimulus | Oracle | Evidence будущего run |
| --- | --- | --- | --- |
| VA-01 | Same synthetic entity doc/query; concurrent get-or-create; deterministic ID/hash token injection | Stable binding в разрешённом tenant/purpose, CSPRNG128+ ref; не ID/hash/PII; collision не выдаёт чужой mapping | Issuance/source recipe, unique scoped refs и race trace; statistical sample не proof CSPRNG |
| VA-02 | Forced collision/random source failure, aliased/ambiguous entity merge | Collision safely retries либо blocked; неизвестная identity не сливается автоматически | Generator stub и mapping invariant до/после |
| VA-03 | Other actor/tenant/purpose/project, expired/revoked/guessed ref | Fresh resolve denied, no existence leak, no Gateway resolve API | Principal/dependency matrix и0 plaintext bytes |
| VA-04 | Gateway/provider доступ к raw bucket, mappings, key, DB mount/credentials | Denied end-to-end; только immutable safe artifacts доступны Gateway | Network/storage/identity negative proof, not UI assertion |
| VA-05 | Tamper ciphertext/tag/nonce/AAD/schema/policy/generation/key version | AEAD authentication failure до plaintext output; no raw fallback | Byte mutation matrix, output capture0, safe error code |
| VA-06 | Wrong tenant/mapping AAD, swapping whole encrypted record | Authentication/scope failure; replay не переносит mapping tenant | Exact versioned unambiguous AAD serialization/vector |
| VA-07 | Nonce allocator down/overflow/concurrent encryption | Unique per-key96-bit allocation durable до encryption; failure blocked | Durable allocation journal; distinct nonce/key-version pairs |
| VA-08 | Encryption retry/failed write consumes nonce; crash после allocation до persistence | Consumed value не возвращается; every encrypt uses fresh nonce | Retry/crash trace, no repeated pair |
| VA-09 | Backup restore/rollback allocator while old key reused for new writes | Writes blocked до new independent key version; restored old key decrypt-only | New key identity/version, allocator monotonic trace; no nonce reuse |
| VA-10 | Recovery key missing/corrupt/access denied; ciphertext backup без key либо vice versa | Recovery failure честно; private tokens/pilot blocked; no regenerated guessed mappings | Isolated restoration receipt, dependency status |
| VA-11 | Restore revoked/deleted mappings/ACL epochs из older backup | Reconciliation prevents resurrection; fresh current authoritative revoke/ACL Required | Trusted latest revoke/generation evidence; unknown freshness blocked |
| VA-12 | Mapping/key/plaintext в index/log/cache/queue/scratch/backup export | Ни одного plaintext canary/key в неразрешённом sink | Full synthetic sink manifest/capture/cleanup receipt |
| VA-13 | Rotation race decrypt-old/encrypt-new, old key removed prematurely | Versioned readable recovery; unavailable key blocks, no raw fallback | Library/rotation protocol, old key decrypt-only, owner retention decision |
| VA-14 | Abort restore, partial durable data, corrupted integrity metadata | Atomic accepted restoration либо blocked; readable части не complete proof | Fault barriers, integrity manifest, isolation/ref resolution |
| VA-15 | Authorized synthetic backup + protected key isolated restore | Same permitted binding readable; tenant isolation/revoked state/tamper/nonce checks all pass | Named operator/reviewer, key-access procedure, versions/digests and independent verdict |

VA-15 не заменяет негативные cases и не является доказательством business RPO/RTO. До run ответственный фиксирует backup/key custody, retention/RPO/RTO для включённого scope, trusted latest state source и доступы. Unknown required recovery evidence блокирует private pilot. Восстанавливаемость не оправдывает raw mappings в debug logs.

Release threshold:0 observed unauthorized plaintext/mapping resolution,0 repeated key+nonce pair,0 acceptance after AEAD tamper и0 stale revoked mapping resurrection. Every mandatory branch/fault case Required для включённого Vault. [Общий protocol](v1-threat-corpus.md) задаёт FN/FP/blocked/latency denominators; recovery canaries synthetic only. Advanced per-tenant KMS/DEK/KEK и full DR/chaos отдельно в [V2 matrix](v2-deferred-matrix.md), без переноса AEAD nonce uniqueness или минимального восстановления из V1.
