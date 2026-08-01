# R15 release entrypoint composition contract

## Status

This contract describes the missing production composition for
`scripts/issue-report-publication-release.php`. The entrypoint is intentionally
fail-closed until every invariant below is implemented and covered by tests.

The items in this document are target requirements, not evidence that the
current workflow already satisfies them.

## Current state versus target

| Area | Current evidence | Target (not implemented) |
| --- | --- | --- |
| Same-run evidence | `procurement-cycle-r15-candidate-evidence` uploads an artifact, but `report-publication-release-artifact` does not depend on that job and does not download it. | Add an explicit same-run `needs` edge and download into the trusted root before issuing. |
| Trusted roots | Candidate builder writes `build/reports/r15-candidate-evidence`; request discovery reads `build/reports/publication-release-requests`. The issuer script only resolves the latter. | Use one explicit, non-symlink root containing all four resolver documents and pass it explicitly to the entrypoint. |
| Laravel bootstrap | The script requires Composer autoload only and then throws `report_publication_release_composition_not_wired`. | Bootstrap the exact checkout and resolve the registered runtime services through a typed composition factory. |
| Provider bindings | `ReportingCatalogServiceProvider` registers eligibility and publication services, but not `ProjectReportPublicationReleaseRequestRegistry` or its concrete release composition. | Add explicit production-only bindings/factory for the registry and all DB-backed procurement dependencies. |
| Official manifest | No read or hash verification occurs in the script. | Read only the tracked official YAML path and verify its authoritative SHA before admission. |
| Acceptance evidence | No valid production composition test exists; current tests cover individual contracts and negative paths. | Add the valid same-run acceptance plus every listed negative case and workflow static checks. |

## Trusted inputs

1. The job checks out the exact `GITHUB_SHA` and verifies that `git rev-parse
   HEAD` is equal to it.
2. The candidate-evidence artifact from the same workflow run is downloaded
   into a dedicated, non-symlink trusted root. The root must contain the four
   canonical files consumed by
   `ProcurementCycleReleaseCandidateResolver`:
   `r15-candidate-manifest.json`, `r15-conformance-evidence.json`,
   `r15-proof-template.json`, and `r15_release_request.json`.
3. The request argument is resolved inside that root and its basename must
   equal the request's `request_id`. The request is not copied or rebuilt by
   the release job.
4. The official manifest bytes are read only from
   `app/BusinessModules/Core/Reporting/resources/official-document-catalog.v1.yaml`.
   The composition verifies its SHA-256 before constructing the admission.
   No environment variable may replace this path or hash.

## Runtime composition

The script must bootstrap the Laravel application for the exact checkout and
resolve the existing bindings for the DB-backed procurement adapter, source
snapshot store, readiness probe, binding factory, report definition factory,
and conformance evidence repository. A dedicated typed factory should assemble:

`ProjectReportPublicationReleaseRequestRegistry` →
`ReportPublicationReleaseArtifactIssuer` →
`ReportPublicationReleaseBundleWriter`.

The factory must reject production composition when any required binding is
missing, when the application is not running in the protected release job, or
when the trusted root/official manifest fails the path and hash checks. It must
not construct test adapters, fixture stores, synthetic evidence, or an
alternate registry.

## Required workflow changes

The release-artifact job must download the exact-run candidate-evidence
artifact before issuing releases and pass its trusted root to the entrypoint.
Request discovery and artifact issuance must use the same request-path
contract; checking out source alone is insufficient because generated evidence
is not tracked in the repository.

## Acceptance matrix

The implementation is admissible only when the following tests pass without
database writes or migrations:

* valid same-run bundle resolves and issues an artifact;
* missing downloaded root is rejected;
* request outside the trusted root or a symlink is rejected;
* official manifest mutation or path substitution is rejected;
* wrong checkout SHA and replayed request are rejected;
* missing/altered conformance, proof, or candidate manifest is rejected;
* production composition rejects fixture/test adapters and missing Laravel
  bindings;
* the workflow statically proves download-before-issue and passes the trusted
  root explicitly.

Until the valid case and all negative cases are implemented, the existing
`report_publication_release_composition_not_wired` failure remains the correct
behaviour; removing it without the composition above would create an unsafe
release path.
