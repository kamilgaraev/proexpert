# Контракт production composition для публикации отчётов

Статус: design gate, production registry намеренно не считается реализованным.

## Причина отдельного контракта

`ProjectReportPublicationReleaseRequestRegistry` сейчас не имеет реализации. JSON-resolver проверяет каноничность и связи четырёх документов, но возвращает только массивы. `ReportPublicationReleaseAdmission` требует доменные объекты и реальные production binding-компоненты. Синтетическая сборка этих объектов или подстановка тестовых providers нарушает `assertProductionSafe` и делает admission недостоверным. Поэтому следующий блок обязан сначала реализовать composition ниже; пустой registry не расширяется обходом.

## Точные границы и интерфейсы

Добавить application-порт `ReportPublicationReleaseComposition` с методом `compose(ReportPublicationReleaseRequest $request, string $trustedReleaseDirectory): ReportPublicationResolvedReleaseRequest`.

`ProjectReportPublicationReleaseRequestRegistry` принимает этот порт через обязательный `__construct` и только делегирует ему после проверки request code/schema. Отсутствующий composition binding должен завершаться `report_publication_release_composition_unavailable`, а не fallback-объектом.

Production adapter `FilesystemReportPublicationReleaseComposition` получает только реальные зависимости:

- канонический resolver для request, candidate manifest, conformance evidence и proof template;
- `ReportDefinitionFactory` + `CandidateReportDefinitionRegistry` для построения candidate definition;
- `ReportDefinitionBindingAssembler`/`ReportDefinitionBindingMap` для binding с тем же code и definition hash;
- `FilesystemReportConformanceEvidenceRepository`-совместимый hydrate-парсер evidence;
- `ReportPublicationProof::fromArray`;
- production `ReportPublicationReleaseEligibilityGate`.

Adapter не принимает `callable`, `mixed`, тестовый factory или объекты из `Tests\\`; все concrete зависимости типизированы и обязательны.

## Trusted root и файловые инварианты

Trusted root задаётся конфигурацией `reports.publication_release.trusted_directory` и передаётся в composition явно. Он обязан:

1. существовать, быть directory и разрешаться через `realpath`;
2. не быть symlink;
3. содержать только request-файл с именем `{request_id}.json` и ровно три артефакта из `artifact_paths`;
4. не позволять `..`, symlink или path traversal после `realpath`;
5. читать canonical JSON (`CanonicalJson::encode(decoded) === bytes`);
6. сверять request `commit_sha` с candidate, conformance, proof, provenance и ожидаемым trusted checkout SHA;
7. сверять все SHA-256 связей и список обязательных checks с `ReportPublicationAdmissionRequirements`.

Любой missing, extra, malformed, wrong-commit или tampered документ даёт один fail-closed доменный `InvalidArgumentException`; частичный admission не возвращается.

## Mapping invariants

- candidate definition преобразуется через `ReportDefinitionFactory`; hash равен `candidate_definition_sha256` манифеста и proof.
- результат оборачивается в `CandidateReportDefinition` только при `publication_readiness=candidate`.
- binding разрешается production `ReportDefinitionBindingMap` по `(code, definitionHash)`; отсутствие или mismatch — отказ.
- evidence hydrate-ится существующим canonical parser, его code/hash/commit/status/assertion count обязаны совпадать с manifest и proof; `status` только `passed`.
- proof строится только `ReportPublicationProof::fromArray`; digest обязан совпадать с request `proof_sha256`.
- `verifiedChecks` равен authoritative per-code списку и сохраняет порядок; нельзя принимать подмножество.
- `assertProductionSafe()` вызывается до возврата resolved request.
- gate строится из production `ReportPublicationEligibilityService`, не из CI fixture.

## Replay semantics

Одинаковый `request_id` + `commit_sha` + proof digest считается тем же immutable release и может быть повторно проверен без изменения admission. Любая попытка повторно зарегистрировать тот же request с другим digest, использовать request после смены trusted checkout SHA, подменить документы или разрешить неизвестный request/code отклоняется до gate/issuer.

Adapter не выполняет запись, promotion или queue dispatch; replay/uniqueness хранится и проверяется на persistence boundary отдельным release registry.

## Dependency composition

Service provider обязан связать `ReportPublicationReleaseRequestRegistry -> FilesystemReportPublicationReleaseComposition -> (candidate resolver, factory, binding map, evidence repository, eligibility gate)`.

CLI `issue-report-publication-release.php` получает trusted root и request path из workflow; CLI не создаёт registry через `new` без зависимостей и не читает произвольные каталоги. Если конфигурация отсутствует, команда завершается до чтения артефактов.

## Test matrix (обязательный до реализации)

1. Valid canonical request: полный production composition возвращает admission и gate; hashes/checks совпадают.
2. Missing request/artifact: fail closed.
3. Wrong commit в candidate/conformance/proof/provenance: fail closed.
4. Tampered bytes при сохранённом JSON: canonical/hash mismatch.
5. Symlink и traversal: fail closed.
6. Unknown/unregistered request id и wrong code: fail closed.
7. Replay с тем же digest: deterministic re-resolution; replay с иным digest: reject.
8. Binding отсутствует или definition hash отличается: reject.
9. Evidence failed/missing check/test component: reject; production component обязателен.
10. Production composition boundary: test classes и CI fixture adapters cannot be injected.

До прохождения всей матрицы публикация отчёта не объявляется готовой.
## Уточнения после независимого ревью

### Trusted checkout SHA и layout

Ожидаемый SHA checkout является обязательным типизированным аргументом composition: compose(request, TrustedReleaseDirectory, CommitSha expectedCommitSha). CommitSha принимает только 40 символов lower-case hexadecimal. Значение нельзя получать из request, окружения или содержимого артефакта: workflow передаёт SHA проверенного checkout отдельно.

TrustedReleaseDirectory строится только из realpath(config('reports.publication_release.trusted_directory')). CLI проверяет, что request path является прямым child этого root, затем ReportPublicationReleaseRequestFileLoader::load(requestPath, root) проверяет extension, symlink, canonical JSON и имя {request_id}.json. Composition выводит артефакты только из фиксированного artifact_paths request и требует, чтобы каждый путь был прямым child того же root; каталог не должен содержать альтернативных имён или произвольных подкаталогов. Request-файл не считается артефактом.

### Точные lookup signatures и ownership

- CandidateReportDefinitionRegistry::candidate(string $code): CandidateReportDefinition — authoritative candidate lookup; adapter проверяет code и definition hash.
- ReportDefinitionBindingAssembler::assemble(ReportDefinitionRegistry $registry): ReportDefinitionBindingMap — единственный владелец production binding composition; map lookup проверяет definition hash.
- FilesystemReportConformanceEvidenceRepository::load(string $code, string $commitSha): ReportDefinitionConformanceEvidence — единственный владелец evidence hydrate.
- ReportPublicationProof::fromArray(array $payload): ReportPublicationProof — единственный parser proof.
- ReportPublicationAdmissionRequirements::requiredChecks(string $code): array — authoritative verifiedChecks; adapter не принимает caller-provided список и сохраняет порядок proof.ci.required_checks.

Перед возвратом admission adapter сравнивает candidate, binding, evidence и proof definition hashes.

### Replay port и атомарная уникальность

Replay требует обязательный порт ReportPublicationReleaseReplayStore::reserveOrMatch(ReportPublicationReleaseIdempotencyKey $key): ReplayReservation.

ReportPublicationReleaseIdempotencyKey — immutable value object из requestId, code, commitSha, proofSha256; canonical digest используется как уникальный ключ. reserveOrMatch атомарен: отсутствующий ключ резервируется; существующий с тем же digest возвращает matched; иной digest/commit возвращает conflict. Adapter не продвигает публикацию и не пишет частичные записи до полной верификации.

Owner replay-контракта — infrastructure release registry. Тесты-владельцы: tests/Unit/Reporting/Publication/ReportPublicationReleaseReplayStoreTest.php и tests/Feature/Reporting/Publication/ReportPublicationReleaseCompositionTest.php. Матрица: first reserve, same-key match, digest conflict, commit conflict и atomic concurrent reservation через deterministic in-memory contract double.
