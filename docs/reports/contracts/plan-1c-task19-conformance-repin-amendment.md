# Plan 1c Task 19 — forward-only conformance fixture repin

## Причина

На exact base `5ef0980feeda97b2544aba3e0abbf37a21032730` канонический Task 5
передаёт в offline promotion script существующий путь
`tests/Fixtures/Reporting/Conformance/report-conformance-evidence.valid.json`.
До Task 19 этот Task 3 schema fixture описывал изолированный synthetic code
`quality_report`, которого нет в management manifest. Единственный nominal
candidate в каноническом manifest имеет code `project_portfolio_health`.

Строгая проверка promotion step 4 не допускает alias, fallback или ослабление
identity. Поэтому fixture закрепляется вперёд на фактическую candidate identity.

## Закреплённая identity

- code: `project_portfolio_health`;
- definition hash: SHA-256 canonical candidate definition, загруженного из
  `tests/Fixtures/Reporting/Publication/candidate.valid.yaml`;
- fixture hash: hash независимого deterministic `ReportConformanceFixture`,
  созданного `ReportConformanceFixtureBuilder`, а не значение из evidence;
- component class hashes: реальные классы keyed candidate binding, которые
  использует concrete `StrictReportDefinitionCandidateValidator`;
- contract/formula/source-schema versions: точные версии candidate wrapper;
- digest: SHA-256 полного canonical evidence payload без поля `digest`.

## Граница изменения

Схема Task 3, DTO evidence и production repository не изменяются. Изменяется
только tracked schema fixture:

- `tests/Fixtures/Reporting/Conformance/report-conformance-evidence.valid.json`.

Task 5 script повторно валидирует fixture через Task 3 Draft 2020-12 schema,
пересчитывает digest и запускает concrete Task 4 validator с независимым
fixture registry. Caller-authored fixture hash, даже с пересчитанным digest,
отклоняется.

## Review round 2: trusted semantic fingerprints

В Task 1 management manifest schema forward-only добавлен обязательный закрытый
блок `semantic_fingerprints` с независимыми SHA-256 для `formula` и `source`.
Версии не входят в fingerprint payload и не используются как замена
семантической идентичности.

- formula fingerprint строится из totals hash и упорядоченной identity всех
  conformance component classes;
- source fingerprint строится из source hash, filters и grain;
- candidate fingerprints повторно вычисляются из typed passed conformance
  evidence до сравнения с current manifest;
- изменение fingerprint требует строго большей соответствующей версии;
- bump версии при неизменном fingerprint отклоняется.

Production-loaded schema-valid regressions отдельно покрывают formula/source
change-without-bump, bump-without-change и isolated source-schema drift.
Изменение filters по-прежнему требует одновременно source-schema и public
contract version bump.

## Review round 3: publication commit point

После verified new ledger и удаления backup публикация считается зафиксированной.
Неудачная очистка sidecar journal не отменяет output, lock или ledger: journal
остаётся canonical cleanup-only tombstone. Следующий read/append под тем же
`flock` валидирует new final и удаляет этот journal, не создавая повторное событие
и не сокращая историю ledger.
