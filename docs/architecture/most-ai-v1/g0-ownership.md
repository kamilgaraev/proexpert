# МОСТ AI V1: ownership и согласования G0

Версия `most-ai-g0-ownership/0.1-candidate`, 05.10.2026. [MOSTAI-13 / LEAD-04](https://prohelper.youtrack.cloud/issue/MOSTAI-13); контракт [g0-v1-contracts.md](g0-v1-contracts.md). **CANDIDATE; G0 OPEN; Ready for Merge No.** Таблица описывает planned ownership. Она не назначает новых сотрудников, не подтверждает approvals и не выдаёт shared-file leases.

## Пакет и границы владельцев

Base пакета `c7bbe8460fb01808d2e504d6364b6431dccd1138`, ветка `task/mostai-g0-batch-02`. LEAD работает только с двумя файлами `docs/architecture/most-ai-v1/g0-v1-contracts.md` и `docs/architecture/most-ai-v1/g0-ownership.md`. Точный HEAD/PR/worktree location и результаты проверок сохраняются в существующей YouTrack-задаче/чате; пользовательские filesystem paths в commits не включаются.

| Роль | Ответственность за контракт | Состояние / граница |
| --- | --- | --- |
| LEAD | Candidate/version, интеграция boundary/API, exact scopes, конфликты/leases, общий proof ledger | MOSTAI-13 Develop; публикация candidate не stakeholder approval |
| PRIV | Trusted projections/closed SafeRepresentation, policy/minimization/tokens, Vault/resolve/recovery | Candidate review pending; PRIV-01 после фактического G0 |
| GATE | Processor identity/channel, final wire transport, serialized first-write/revoke, profiles, retries, egress/keys | Boundary approval pending; product implementation не запущена пакетом 02 |
| DOC | Source/artifact generations/completeness, local OCR/redaction, safe media refs, hidden metadata/unsupported formats | Boundary approval pending; не разрешён общий DOC rollout |
| QA | Независимый threat corpus и negative/race evidence; разделение Required/Recommended/V2 | Supporting candidate review pending; QA-01 после G0, не самоприёмка LEAD |
| Product owner | Уже согласованная purpose policy 0.2 | Не является approval G0/residual risks/customer consent |
| Legal/account/vendor/infra owners | Account applicability, residency/retention/legal basis, runtime/cutover, human residual decision | Evidence/конкретное назначение pending; G2/G3/G4 сохраняются |

Новый поток/issue не стартует от ссылки на этот файл. Native зависимости MOSTAI-13 от MOSTAI-11/12 выполнены в technical Foundation scope; переход MOSTAI-79/17 требует действительного G0. Merge/deploy пакета 02 и private activation отдельного разрешения не имеют.

## Planned PRIV-01: точные новые пути

[MOSTAI-17 / PRIV-01](https://prohelper.youtrack.cloud/issue/MOSTAI-17) поставляет только typed private projections и закрытый SafeRepresentation по согласованному G0. Ниже новые файлы **PLANNED**, не созданные и не публичные service registrations. Ownership PRIV охватывает `app/Services/Privacy/**` и `tests/Unit/Privacy/**` только в этом bounded scope; расширение списка согласуется с LEAD до edits.

| Planned file | Назначение / владелец |
| --- | --- |
| `app/Services/Privacy/Contracts/PrivateProjection.php` | Private authenticated tenant/project/source context; PRIV |
| `app/Services/Privacy/Contracts/PrivateSourceVersion.php` | Полная dependency/version/completeness shape; PRIV с DOC contract review |
| `app/Services/Privacy/Contracts/SafeRepresentation.php` | Закрытый readonly safe type, без публичного arbitrary constructor; PRIV |
| `app/Services/Privacy/Contracts/SafeContent.php` | Конечные safe variants и metadata, никакого `any`/общего `is_safe`; PRIV |
| `app/Services/Privacy/Contracts/PrivacyDecision.php` | Typed ready/blocked/stale/unsupported, безопасные reason codes; PRIV |
| `app/Services/Privacy/PrivateProjectionFactory.php` | Trusted source/actor/ACL checks; PRIV |
| `app/Services/Privacy/SafeRepresentationFactory.php` | Trusted closed creation/version dependencies; PRIV |
| `tests/Unit/Privacy/PrivateProjectionTest.php` | Missing auth/tenant/project/field/source ACL, stale generation; PRIV |
| `tests/Unit/Privacy/SafeRepresentationTest.php` | Forged markers/constructor/deserialization, unknown variants/version, immutable content; PRIV |
| `tests/Unit/Privacy/PrivacyDecisionTest.php` | Fail-closed unavailable dependency и безопасные errors; PRIV |

Concrete fields/projection allowlists связываются с approved caller purposes; универсальный arbitrary map не заменяет contract. PRIV-01 не делает provider requests, Gateway rollout, Vault implementation, OCR, source reindex, DB schema или enterprise KMS. Если complete trusted factory зависит от ещё неготового Required stage, возвращает blocked, а не fake safe success. Тестовый double проверяет fail-closed contract; его наличие не доказывает production enforcement.

Public manifests/routing, autoload/DI/provider binding, existing product callsites и safe storage integration остаются read-only до exact shared-file lease. Новое имя класса не означает, что App уже вызывает только Processor. Не изменять `tests/TestCase.php`, `tests/CreatesApplication.php`, `tests/Pest.php`, `phpunit.xml`, `bootstrap/**`, DB/schema/runtime ради unit test; использовать изолированную проверку без Laravel/DB bootstrap. При необходимом shared change сначала нужен lease, не workaround чужой конфигурацией.

## Planned QA-01: независимый corpus

[MOSTAI-79 / QA-01](https://prohelper.youtrack.cloud/issue/MOSTAI-79) владеет `docs/privacy-audit/validation/**` после G0. Corpus — synthetic, без customer data, production logs и live AI. QA самостоятельно определяет cases/expected outcomes; LEAD/PRIV не пишет verdict за QA.

| Planned file | Scope |
| --- | --- |
| `docs/privacy-audit/validation/v1-threat-corpus.md` | Trust/forgery/tenant isolation/known PII/keys/schemas/history/tool/RAG/media |
| `docs/privacy-audit/validation/v1-dispatch-races.md` | Zero outbound before revoke, first-write ordering, crash/timeout/late writer, retry/redirect/loop |
| `docs/privacy-audit/validation/v1-vault-recovery.md` | Minimal AEAD/tamper/tenant resolve/nonce restore scenarios |
| `docs/privacy-audit/validation/v1-capability-disable.md` | T1–T7 roots включая context-bearing unit OCR; unknown wrappers/order/profile; BIM disable |
| `docs/privacy-audit/validation/v2-deferred-matrix.md` | Authority/global nonce/signatures/advanced KMS/full DR отдельно от V1 prerequisites |
| `docs/privacy-audit/validation/g0-approval-evidence.md` | Exact HEAD/version/scope/role/evidence/verdict, без вымышленных подписей |

Thematic executable tests остаются у исполнителей соответствующих модулей, независимо принимаются QA. Planned `tests/Unit/Privacy/SafeRepresentationTest.php` — PRIV; planned `tests/Unit/Privacy/GatewayDispatchGuardTest.php` — будущий GATE и отдельный scoped grant; planned `tests/Unit/Privacy/SafeArtifactGenerationTest.php` — будущий DOC и отдельный scoped grant; planned `tests/Unit/Privacy/VaultRecoveryTest.php` — будущий PRIV/Vault scope. Совпадение общей test directory не даёт двум потокам права менять один файл. Эти будущие пути зарезервированы описательно, actual lease нет; QA-01 не создаёт shared bootstrap/DB/runtime changes.

## Shared-file lease: только после явного назначения

Ни одному shared file этого пакета actual lease не выдан. Матрица candidate не является разрешением менять runtime. Перед shared edit LEAD фиксирует **отдельную** строку для каждого exact path, owner и актуального base. Wildcards/group labels вместо exact path недопустимы.

```json
{
  "schemaVersion": "shared-file-lease/1",
  "status": "not-issued",
  "exactPath": null,
  "baseSha": null,
  "ownerIssue": null,
  "ownerRole": null,
  "ownerChat": null,
  "expiresAtUtc": null,
  "applicableInputVersions": [],
  "permittedChange": null,
  "approverEvidence": null
}
```

Это placeholder, не валидный active lease. Active lease требует repo-relative `exactPath`, полный 40-hex base SHA, именованный owner issue/role/chat, expiry UTC, exact G0/policy/schema input versions, bounded permitted change и actual LEAD evidence. Перед edit проверяются отсутствие чужой активной работы и совпадение base/content; при изменившемся base/истёкшем lease/сменившихся inputs работа останавливается до renewal. После исполнения — exact diff/head, проверки и release lease в журнале.

Lease нужен для конкретных manifests/locks, route/config, DI/service providers, shared test bootstrap, DB schemas/migrations, deploy/network/CI. Он не заменяет отдельное human authorization для CI/deploy/production/DB. Задача MOSTAI-13 не выдаёт такие leases и не меняет shared files.

## Approval ledger и native acceptance

| Native MOSTAI-13 criterion | Candidate evidence | Actual approval |
| --- | --- | --- |
| App/workers only Processor; Gateway only authenticated integrity-protected identity | Trust table и closed creation/API shapes | PRIV/GATE pending |
| Fresh ACL/consent/purpose/policy/source generation serialized with revoke/first write; zero outbound/next attempts; TTL not guard | Ordering table, unknown outcome/quiescence contract, independent planned races | GATE/PRIV/QA pending |
| Typed SafeRepresentation; exact bytes/digest/versions/tenant tokens/immutable artifacts | Closed variants, final buffer fixture, source lifecycle | PRIV/GATE/DOC/QA pending |
| Minimal Vault encryption/recovery, deny egress, disabled capabilities, проверяемые residual risks | Minimal AEAD/nonce/recovery, all T roots/BIM blocked, residual ledger | PRIV/GATE/DOC/QA pending; human G4 residual decision отдельно |
| Separate Authority/nonce/signatures/advanced KMS/DR preserved V2 | V1/V2 table и ownership границы | PRIV/GATE/DOC/QA pending |

Approval record должен содержать exact candidate commit и contract version, роль и реального reviewer/уполномоченного исполнителя, scope, дату UTC, allowlisted evidence link, `approved`/`changes_requested`/`pending`, ограничения и фактическое основание. Agent supporting review учитывается только в своей роли; оно не становится human residual/legal/customer approval. Если HEAD/schema materially изменились, approval перепроверяется на новый scope. Согласования отсутствующих stakeholders не выводятся из silence или предыдущего Foundation QA.

До этих approvals: MOSTAI-13 Develop, Ready for Merge No, G0 OPEN; supporting review допускается без объявления QA-01 начатой. Candidate committed/pushed/draft PR — документальный результат, не Done. Дальнейшее решение о native criterion/зависимостях принимает LEAD по фактическим evidence; criteria не переписываются ради старта QA-01/PRIV-01.

## Проверки и сохранение работы

Для двух документов: UTF-8 без BOM, закрытые fences, relative links, JSON examples, synthetic exact-byte digest/length, запрет локальных пользовательских путей/секретов, exact allowlisted diff и `git diff --check`. PHP/Laravel bootstrap/tests/DB/network AI/builds не нужны и не запускаются; executable guard/Vault/egress proofs остаются будущей работой.

Пока merge не разрешён/не выполнен, сохраняются одна task-ветка и один чистый worktree пакета 02, owner LEAD: нужны для supporting review и исправлений. Чужие checkout/ветки не переключаются/не очищаются. После разрешённого merge/deploy уборка выполняется по AGENTS с проверкой integration/current tip/active owners; этот файл не даёт разрешения merge/deploy.
