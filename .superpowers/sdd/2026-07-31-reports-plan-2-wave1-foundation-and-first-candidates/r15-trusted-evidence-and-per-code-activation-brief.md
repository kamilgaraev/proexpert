# R15: trusted evidence ingestion и per-code activation

## Цель

Опубликовать только `procurement_cycle` после успешного R15 runtime/conformance/PostgreSQL gate на одном commit SHA. До завершения этой цепочки активный management catalog остаётся `blocked`, а release request отсутствует.

## Недостающая доверенная цепочка

1. CI формирует канонический `ReportPublicationCheckEvidence` для каждого обязательного check: `binding_contract`, `source_contract`, `formula_contract`, `drill_down_contract`, `rbac_contract`, `export_csv_contract`, `export_xlsx_contract`, `export_pdf_contract`, `postgresql_contract`.
2. Каждый evidence содержит report code, exact `GITHUB_SHA`, workflow/run/job identity, started/completed timestamps, command digest, result, hashes candidate definition, binding components, fixture, schema и renderer contract. Evidence подписывается GitHub artifact attestation либо отдельным ключом evidence issuer, не ключом release signer.
3. `ProjectReportPublicationReleaseRequestRegistry` не принимает массив строк `verifiedChecks`. Он загружает evidence только из доверенного artifact bundle текущего run, проверяет schema, подпись/attestation, exact SHA, report code, уникальность, полный набор checks и пересчитывает component hashes.
4. Release discovery зависит от общего publication gate и R15 PostgreSQL/conformance gate. Signing job получает только проверенный evidence bundle того же SHA и не может подменить его fixture-данными.
5. Protected admission job скачивает signed release bundle только по имени `report-publication-release-${GITHUB_SHA}`, повторно проверяет signature, provenance, proof/artifact pairing и eligibility, затем вызывает per-code promotion.

## Per-code authority и activation

- Ввести `ReportPublicationAdmissionService::admitOne()` для одной definition. Он атомарно вызывает `EloquentReportPublicationRegistry::promote()`, создаёт feature row в режиме `disabled` и append-only outbox event.
- Runtime catalog должен использовать одну authority. Целевой вариант: `AdmittedReportDefinitionRegistry` читает published YAML definition, но возвращает её только при совпадении active DB publication по `code`, `definition_hash`, versions, proof hash и enabled feature mode.
- Изменение feature `disabled -> canary -> on` выполняется отдельным operator action. До `on` definition не попадает в общий каталог; canary доступен только allowlist tenants.
- Новый single-code manifest promotion service изменяет только `procurement_cycle`, сверяет candidate/official manifest hashes и пишет append-only activation ledger. Bulk `ReportCatalogActivationTransactionService` для этого потока запрещён.
- Любое расхождение DB publication, feature state и manifest приводит к fail-closed отсутствию definition/binding в runtime.

## Обязательные проверки

- Unknown, missing, duplicate, stale, future, wrong-SHA и failed check evidence отклоняются.
- Evidence с `Tests\\*` component, sentinel hash или неподтверждённым action/job отклоняется.
- Release signer не может сам создать passed evidence; admission job не имеет signing secret.
- Повтор того же bundle идемпотентен, другой proof для active code конфликтует.
- Нельзя включить feature до promotion; нельзя активировать catalog при несовпадении definition/binding/proof.
- Architecture test доказывает невозможность расхождения DB authority и runtime catalog.
- Integration test проходит signed bundle -> promotion -> disabled -> canary -> on для одного R15 и доказывает, что остальные 27 кодов не изменились.

## Workflow boundary

R15 runtime-коммит может добавить provider/formula/snapshot/drill/export binding factory и усилить зависимость release discovery от R15 PostgreSQL gate. Он не создаёт release request, не регистрирует фиктивные checks, не меняет readiness на `READY` и не активирует catalog. Эти действия разрешены только после реализации описанного ingestion/activation блока и зелёного CI на exact SHA.
