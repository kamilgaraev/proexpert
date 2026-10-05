# МОСТ AI: V2 scenarios вне минимального V1 gate

Версия `most-ai-qa79-corpus/0.1-candidate`; **SPECIFIED / NOT RUN**. [MOSTAI-79](https://prohelper.youtrack.cloud/issue/MOSTAI-79), [G0 V1/V2:462](https://github.com/kamilgaraev/proexpert/blob/e7a7bd7d19b4d3ce723e24b214187aea67cac6f4/docs/architecture/most-ai-v1/g0-v1-contracts.md#L462).

| ID | Deferred control / future fault case | V1 compensation, которую нельзя убрать |
| --- | --- | --- |
| V2-01 | Mandatory envelope/artifact signature; wrong signer/tamper/key rotation/replay | Authenticated integrity-protected Processor-only channel, immutable per-channel/aggregate digest, trusted registry/provenance; не client `processor_id`/network allowlist |
| V2-02 | Separate global Admission Authority; split-brain/outage/stale grants | Fresh local trusted serialization domain с transport writer/revoke ordering и honest unknown outcome; unavailable guard blocked |
| V2-03 | Global one-time dispatch nonce ledger; duplicate consume/fork/replay | Каждая физическая attempt/retry/loop fresh guard; attemptRef не exactly-once. AEAD Vault nonce uniqueness и canonical effect idempotency остаются V1 Required |
| V2-04 | Advanced per-tenant DEK/KEK KMS/HSM, complex rotation/crypto erasure | Minimal AEAD encryption/recoverable protected key, tenant isolation, AAD/tamper/nonce restore proofs и versioned basic rotation |
| V2-05 | Separate Gateway VM/hardware attestation/host partition drills | Выделенная trusted identity, gateway-only keys, tested deny egress/raw/Vault access; общий host/kernel residual принимает named human G4 |
| V2-06 | Full multi-region DR/chaos/RPO campaign; correlated disaster | Minimal isolated synthetic backup+key restore с revoked state/tamper/tenant/nonce proof; unavailable recovery blocks private pilot |
| V2-07 | Advanced global anti-replay and long-running rotation/revoke/retention drills | Controlled V1 crash/timeout/late writer/source delete/ACL/revoke races, безопасные queues/logs/storage lifecycle и key custody |

Статус каждого V2 case `deferred_not_run`; результат не required denominator V1 и не принимается за реализованный. Если V2 scope будет включён либо независимый review выявит фактический V1 bypass, Lead фиксирует finding, affected capability, недостающий V1 invariant и bounded компенсацию; соответствующая capability блокируется до proof. Сложность control сама по себе не причина defer. Отсутствие V2 signature/KMS/Authority само по себе не failed V1, но отсутствие identity/fresh guard/AEAD uniqueness/egress/minimal recovery — failed V1.

G2 legal/vendor/customer evidence, G3 разрешённый pilot, G4 named human residual/release и final actual-model/privacy/wire/effect acceptance не V2 conveniences и не отменяются документальным G0, PG stub PASS или merge corpus. [Ledger](g0-approval-evidence.md), [V1 corpus](v1-threat-corpus.md), [Vault](v1-vault-recovery.md).
