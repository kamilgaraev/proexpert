# Production release registry composition contract

Status: design gate. The empty registry is intentionally not treated as implemented.

## Required composition

Introduce a typed application port `ReportPublicationReleaseComposition::compose(ReportPublicationReleaseRequest $request, TrustedReleaseDirectory $directory, CommitSha $expectedCommitSha): ReportPublicationResolvedReleaseRequest`.

`ProjectReportPublicationReleaseRequestRegistry` must require this port in its constructor and delegate only after request code/schema validation. Missing composition is a fail-closed error; no synthetic object or callback fallback is allowed.

`CommitSha` is exactly 40 lower-case hexadecimal characters and is supplied by the trusted checkout workflow separately from the request. `TrustedReleaseDirectory` is resolved from `realpath(config('reports.publication_release.trusted_directory'))` and cannot be a symlink.

The CLI must ensure the request path is a direct child of this root, then use `ReportPublicationReleaseRequestFileLoader::load($requestPath, $root)`. Artifacts are derived only from the request's fixed `artifact_paths`; every artifact must also be a direct child of the same root. No arbitrary subdirectories or alternate names are accepted.

## Concrete production mapping

The adapter must use these existing owners and exact signatures:

- `CandidateReportDefinitionRegistry::candidate(string $code): CandidateReportDefinition` resolves the candidate from the authoritative candidate manifest. Candidate code and definition hash must match the request documents.
- `ReportDefinitionFactory::fromManifest(array $row): ReportDefinition` creates the domain definition; it is then wrapped in `CandidateReportDefinition` only when readiness is `candidate`.
- `ReportDefinitionBindingAssembler::register(ReportDefinitionBinding $binding): void` is the only binding registration mutation. `assemble(ReportDefinitionRegistry $registry): ReportDefinitionBindingMap` uses the published `ReportDefinitionRegistry`, not the candidate registry. The resulting map lookup must match code and definition hash.
- `FilesystemReportConformanceEvidenceRepository::get(string $code, Sha256Hash $definitionHash, Sha256Hash $fixtureHash): ReportDefinitionConformanceEvidence` is the evidence hydrate owner. Both hashes come from the candidate/evidence documents; returned code and hashes are rechecked.
- `ReportPublicationProof::fromArray(array $payload): ReportPublicationProof` is the only proof parser.
- `ReportPublicationAdmissionRequirements::requiredChecks(string $code): array` is the authoritative ordered `verifiedChecks` source. Caller-provided subsets or reordered lists are rejected.

Before returning an admission, the adapter compares candidate, binding, evidence, and proof definition hashes, all document digests, commit SHA, status, and required checks. It invokes `assertProductionSafe()` and uses a production eligibility gate; CI fixture components are forbidden.

## Replay and uniqueness

Composition requires `ReportPublicationReleaseReplayStore::reserveOrMatch(ReportPublicationReleaseIdempotencyKey $key): ReplayReservation`.

`ReportPublicationReleaseIdempotencyKey` contains request id, code, commit SHA, and proof SHA-256. The persistence boundary atomically reserves a missing key, returns `matched` for the same immutable digest, and returns `conflict` for another digest or commit. No promotion or partial write occurs before all documents are verified.

Replay tests belong to `tests/Unit/Reporting/Publication/ReportPublicationReleaseReplayStoreTest.php`; composition tests belong to `tests/Feature/Reporting/Publication/ReportPublicationReleaseCompositionTest.php`. They cover first reserve, same-key match, digest conflict, commit conflict, concurrent reservation, missing/tampered artifacts, wrong commit, symlink/traversal, unknown request, binding mismatch, failed evidence, and production-boundary rejection. Local tests use a deterministic in-memory contract double; no database command is implied.

Publication is not ready until this contract and the complete test matrix are implemented and passing.
