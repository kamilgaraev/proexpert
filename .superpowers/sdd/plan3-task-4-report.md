# Plan 3 / Task 4 — implementation report

## Status

DONE_WITH_CONCERNS. Vector PDF, DXF and real DWG paths are implemented without placeholder geometry. The production image contract is reproducible and pins all selected runtimes. Docker build was intentionally not executed by project policy; two pre-existing Laravel `Tests\\TestCase` regressions cannot start because the repository SQLite migration uses PostgreSQL-only `BTRIM`.

## Changed files

- `Dockerfile.prod`
- `docker/geometry/requirements.lock`
- `docker/geometry/THIRD_PARTY_NOTICES.md`
- `app/BusinessModules/Addons/EstimateGeneration/bin/pdf_geometry_extract.py`
- `app/BusinessModules/Addons/EstimateGeneration/bin/cad_geometry_extract.py`
- `app/BusinessModules/Addons/EstimateGeneration/Vision/Contracts/CadGeometryProvider.php`
- `app/BusinessModules/Addons/EstimateGeneration/Vision/DTO/VectorGeometryData.php`
- `app/BusinessModules/Addons/EstimateGeneration/Vision/Exceptions/GeometryExtractionException.php`
- `app/BusinessModules/Addons/EstimateGeneration/Vision/Geometry/CadConversionRuntime.php`
- `app/BusinessModules/Addons/EstimateGeneration/Vision/Geometry/DwgDxfGeometryProvider.php`
- `app/BusinessModules/Addons/EstimateGeneration/Vision/Geometry/PdfVectorGeometryProvider.php`
- `tests/Fixtures/EstimateGeneration/Vision/{README.md,simple-house.dxf,simple-house.dwg}`
- `tests/Unit/EstimateGeneration/Vision/{CadRuntimeContractTest,CadProductionRuntimeContractTest,DwgDxfGeometryProviderTest,PdfVectorGeometryProviderTest}.php`

## Architecture and security decisions

- PDF parsing uses only `pypdfium2==5.8.0`; the prior PyMuPDF worker was replaced while retaining its legacy JSON shape through an adapter mode.
- DXF parsing uses `ezdxf==1.4.4`; DWG invokes the separate GPL `dwgread 0.13.4 -O JSON` process and never links LibreDWG into PHP/Python.
- LibreDWG source archive is version- and SHA-256-pinned in a dedicated Docker build stage. Python direct and transitive dependencies are exactly pinned; notices record licenses and sources.
- Providers/runtime use unique `0700` workspaces, validate organization prefix, extension, magic, symlink, byte/output limits, timeout/idle timeout, bounded stderr and delete complete workspaces in `finally`.
- Original SHA-256 is computed from the copied original source. Contract carries runtime provenance, explicit unit status, page/layout identity, rotation/page box, handles, transform/source lineage, unsupported entity counts and blocking warnings.
- Closed top-level/entity validation rejects unknown fields, unsupported versions/provenance and duplicate handles. Raster-only PDF and empty/corrupt DWG are typed failures, not successful empty geometry.
- Real DWG fixture is generated from the original synthetic DXF by official LibreDWG 0.13.4 `dxf2dwg`; provenance is recorded and no third-party drawing is copied.

## TDD evidence

RED (before implementation):

```text
vendor/bin/phpunit tests/Unit/EstimateGeneration/Vision/CadRuntimeContractTest.php tests/Unit/EstimateGeneration/Vision/CadProductionRuntimeContractTest.php
Result: 3 tests failed because CadConversionRuntime and production pins did not exist.

vendor/bin/phpunit tests/Unit/EstimateGeneration/Vision/PdfVectorGeometryProviderTest.php tests/Unit/EstimateGeneration/Vision/DwgDxfGeometryProviderTest.php
Result: PDF tests failed because PdfVectorGeometryProvider did not exist.
```

GREEN/final fresh verification:

```text
LIBREDWG_DWGREAD_BINARY=<official-0.13.4>/dwgread.exe vendor/bin/phpunit \
  tests/Unit/EstimateGeneration/Vision/CadRuntimeContractTest.php \
  tests/Unit/EstimateGeneration/Vision/PdfVectorGeometryProviderTest.php \
  tests/Unit/EstimateGeneration/Vision/DwgDxfGeometryProviderTest.php \
  tests/Unit/EstimateGeneration/Vision/CadProductionRuntimeContractTest.php
OK (6 tests, 22 assertions), 0 failures, 0 skipped.

vendor/bin/phpstan analyse --no-progress --memory-limit=1G <six changed PHP production files>
[OK] No errors.

vendor/bin/pint --test app/BusinessModules/Addons/EstimateGeneration/Vision <four focused tests>
PASS, 29 files.

python -m py_compile app/BusinessModules/Addons/EstimateGeneration/bin/cad_geometry_extract.py app/BusinessModules/Addons/EstimateGeneration/bin/pdf_geometry_extract.py
Exit 0.

php -l <six changed PHP production files>
All six: No syntax errors detected.

git diff --check
Exit 0; only Git CRLF normalization warning for Dockerfile.prod.
```

## Regression/environment concern

`PdfGeometryWorkerScriptTest` and `PdfGeometryExtractorTest` were requested as relevant legacy regression checks, but their Laravel base class starts `migrate:fresh`. The current repository migration `2026_07_10_000001_add_cell_id_to_warehouse_storage_records.php` calls PostgreSQL `BTRIM` on SQLite and fails before either test method executes (`SQLSTATE[HY000]: no such function: BTRIM`). No DB command will be retried. This is unrelated to Task 4 code but prevents a clean full Vision regression result locally.

The production Dockerfile is statically contracted but not built, because the task explicitly forbids Docker builds. First deployment should validate the new LibreDWG compilation stage in CI before release.

## Commit

Implementation commit: `e4575e31`.

## Corrective cycle after independent review

### Finding → fix evidence

- Critical: PDF paths were bounding-box placeholders. Replaced by raw PDFium `FPDFPath_*` extraction of MOVE/LINE/cubic Bezier operators, exact source segment indices, close flags, points after composed object/Form/page transforms, style and non-authoritative bbox. `PdfVectorGeometryProviderTest` asserts exact operators and transformed coordinates; `LegacyPdfGeometryAdapterTest` asserts real legacy line geometry/style/metrics.
- Critical: page boxes/rotation/mixed classification were incomplete. MediaBox/CropBox and the exact normalization matrix are emitted; displayed dimensions account for rotation; raster and vector counters independently produce `vector`, `raster`, `mixed` or `empty`. A real two-page vector+image fixture verifies offset CropBox, 90° transform and mixed classification.
- Critical: CAD entities/transforms were incomplete. DXF LINE, ARC, CIRCLE, LWPOLYLINE/POLYLINE, INSERT, TEXT/MTEXT and DIMENSION now have geometry or fail closed. Nested INSERTs are recursively expanded with bounded depth, source lineage, block ownership and transform graph. DWG mandatory entities are mapped only when complete; incomplete POLYLINE/INSERT/DIMENSION is a typed failure rather than an empty entity. Focused tests verify every DXF mandatory type and nested transformed coordinates.
- Critical: unknown/partial CAD returned success. Unknown entity types, LibreDWG warning/error/skipped/unsupported diagnostics and incomplete mandatory entity payloads now return typed failures. The closed DTO also rejects completeness warnings from an untrusted worker.
- Critical: LibreDWG provenance was hardcoded. The worker invokes the actual binary with `--version`, requires exact `0.13.4`, validates JSON `created_by`, and rejects mismatch. The required real-DWG gate fails explicitly when `LIBREDWG_DWGREAD_BINARY` is absent; it never skips silently.
- Critical: process output/resource isolation was post-hoc. `GeometryProcessRunner` bounds stdout/stderr while receiving chunks and clears Symfony buffers immediately. Production Linux uses `geometry-sandbox.sh`: bubblewrap user/mount/network/PID isolation, read-only root, only one writable ephemeral workspace, `ulimit` for VM/CPU/file/open-file resources, hard timeout, bounded output files and typed timeout/file-limit exits. LibreDWG JSON/version output is streamed to bounded workspace files rather than `capture_output` memory.
- Critical: PDF lacked private S3 entrypoint. `PdfVectorGeometryProvider::extract()` now requires `org-{id}/`, `.pdf`, `FileService`, `BoundedStorageReader`, unique ephemeral lifecycle and unconditional cleanup. CAD provider has equivalent focused org-scoping/bounded-read coverage.
- Important: schema was only partly closed. `VectorGeometryData` validates all top-level collections, required/allowed nested keys, path segments/styles, runtime allowlist, units, finite numbers, ordered bounds, global handle uniqueness, text/point/item/depth limits and blocking warning codes. Negative tests cover unknown nested fields, NaN, reversed bounds, duplicates, unit inconsistency, runtime mismatch and excessive nesting.
- Important: DXF blocks lacked ownership/lineage. Blocks now include handles/owners/member handles; recursively expanded children retain the insert chain and block name.
- Important: PDF limits were per page. Limits are document-global for pages, objects, path segments and text characters; exceeding any limit fails closed rather than truncating.
- Important: legacy PDF adapter lost geometry. It now reconstructs real line/curve elements, bboxes, styles, text bounds, metrics, density, signals and optional previews from the same strict contract. The isolated regression test avoids the repository-wide SQLite migration bootstrap and proves the `geometry_v1` consumer shape.
- Important: required security matrix was absent. Focused tests now cover malformed signature/JSON, input/output limits, timeout, cleanup, S3 traversal, filesystem symlink/junction traversal, closed schema, NaN, invalid bounds, duplicates, unknown entities, explicit unknown units, required entity types, nested transforms and rotated mixed multipage PDF.
- Important: supply-chain/runtime contract was weak. Both Alpine/PHP bases are digest-pinned; `requirements.lock` is generated with hashes and installed using `--require-hashes`; LibreDWG archive SHA-256 is checked. Exact GPL Corresponding Source is copied into the final image at `/usr/share/source/libredwg-0.13.4.tar.xz`, not merely linked externally.
- Minor: Python security-boundary scripts were compressed. Both were split into typed parsing/validation/serialization helpers and formatted with Black 25.1.0.

### Corrective RED evidence

```text
VectorGeometryDataContractTest: 7/7 negative cases initially failed (no exception).
PDF exact-segment/legacy tests: missing `segments`, placeholder corner geometry and null legacy geometry failed.
CAD unknown/nested tests: unknown entity returned a contract and nested INSERT raised `cad_parse_failed`.
Production contract: failed on missing base digests/sandbox/hash install/GPL source delivery.
S3 PDF test: failed with unknown `fileService` constructor argument before entrypoint implementation.
Timeout/output tests were written before `GeometryProcessRunner`; initial runtime accumulated Process output.
```

### Corrective final verification

```text
LIBREDWG_DWGREAD_BINARY=<official LibreDWG 0.13.4>/dwgread.exe vendor/bin/phpunit \
  CadRuntimeContractTest.php CadProductionRuntimeContractTest.php \
  DwgDxfGeometryProviderTest.php PdfVectorGeometryProviderTest.php \
  LegacyPdfGeometryAdapterTest.php VectorGeometryDataContractTest.php
Result: 31 tests, 97 assertions, 0 failed, 0 skipped (final run).

PHPStan/Larastan on all changed production PHP: [OK] No errors.
Pint focused production/tests: PASS.
php -l on every changed production PHP: no syntax errors.
python -m py_compile on both workers: exit 0.
python -m black --check on both workers: unchanged/pass.
sh -n docker/geometry/geometry-sandbox.sh: exit 0.
git diff --check: exit 0 (Git reports only the existing Dockerfile CRLF normalization warning).
```

Docker build and database-backed Laravel tests were not run because the task expressly forbids Docker builds and DB/migration commands. The compatibility regression is therefore provided as a pure PHPUnit/process test that exercises the real Python adapter without Laravel database bootstrap.

Corrective implementation commit: `8afd261c`.

## Second corrective cycle after follow-up review

### Remaining finding → final fix evidence

- Important: repeated/nested block members lost stable identity. DXF expansion now pairs every virtual entity with the originating block definition member, uses the original member handle (or deterministic block/member index only when the source has none), and combines it with the full insert-instance chain. Repeating the same nested two-LINE block twice yields four unique instance handles, two stable `source_member_handle` values and distinct transformed coordinates; lineage is also carried by block TEXT/MTEXT and DIMENSION records.
- Important: nested schema types/invariants were permissive. `VectorGeometryData` now enforces strict primitive types for every collection, typed layer/block/page/scale/warning fields, exact coordinate pairs and boxes, affine/4x4 transforms, RGBA/style values, allowed entity types and type-specific geometry: LINE exactly two points, polylines at least two plus boolean closed, ARC/CIRCLE center/radius/angles, INSERT block+4x4 transform and PATH non-empty typed segments. All coordinate-bearing fields, including positions, definition points, transforms, transform lineage, bboxes and segment points, contribute to the global limit.
- Important: LibreDWG completeness diagnostics were generic. Bounded stderr is parsed into structured `unsupported`, `skipped` and `unknown` counts; JSON object/entity totals are reconciled against represented records. Blocking failures expose only bounded numeric `safeContext` through `GeometryExtractionException`, with no path/document/command content. Tests prove both count parsing and context propagation.
- Important: Linux could fall back unsandboxed. `GeometryProcessRunner` now fails closed on every Linux host when `GEOMETRY_SANDBOX_BINARY` is missing or non-executable; unsandboxed bounded execution remains explicitly non-Linux-only for local compatibility.
- Important: resource limits were hardcoded. Validated `GeometryResourceLimits` is bound from `estimate-generation.vision.geometry_runtime` config and passed through PDF/CAD runtimes into sandbox VM/CPU/file/open-file arguments. Zero, negative, undersized and excessive values are rejected before process start.
- Important: preview output escaped workspace. Legacy preview now requires `--workspace`, resolves both paths, rejects direct traversal and symlink/junction escapes, and writes only under the ephemeral workspace using the original sanitized `{filename}_page_{n}.png` convention.
- Important: ordinary PDF geometry contract changed. Legacy adapter again reports top-level provider `pymupdf`, retains old `pymupdf_unavailable` and generic error identifiers, page role/signals/geometry metrics/preview naming, while actual `pypdfium2` runtime provenance is isolated in metadata.
- Important: production security test was string-only. `CadProductionRuntimeContractTest` now executes `tests/Runtime/geometry-sandbox-runtime.sh` through WSL/POSIX. The harness downloads the pinned Ubuntu bubblewrap package, verifies its SHA-256, runs a real namespace smoke test, then proves workspace write success, outside-write denial, output-symlink safety, bounded stdout/stderr/child files, CPU, VM, open-file and wall-clock limits, plus invalid/colliding argument rejection. It is an executable boundary test, not a Docker build or string assertion.
- Minor: PDF inner copy is checked and returns `pdf_source_copy_failed`; PDF and CAD provider workspace creation is checked and returns typed workspace failures. Provider tests force invalid workspace roots to verify both paths.
- Minor: Python sandbox output redirection no longer follows attacker-planted final-path symlinks. It captures into unpredictable `mktemp` files and atomically renames them over final names after the child exits; the executable harness verifies an outside victim remains unchanged.

### Second corrective RED evidence

```text
Nested repeated INSERT test: failed with cad_runtime_contract_invalid from duplicate virtual LINE handles.
VectorGeometryDataContractTest: 6 added type/invariant cases all failed to throw.
GeometryResourceLimits/CadProduction tests: 2 errors + 2 failures before limits class/config/runtime harness existed.
LegacyPdfGeometryAdapterTest: 3/3 failed for provider identifier, preview filename and missing-runtime error identifier.
```

### Second corrective final verification

```text
LIBREDWG_DWGREAD_BINARY=<official LibreDWG 0.13.4>/dwgread.exe vendor/bin/phpunit \
  CadRuntimeContractTest.php CadProductionRuntimeContractTest.php \
  DwgDxfGeometryProviderTest.php PdfVectorGeometryProviderTest.php \
  LegacyPdfGeometryAdapterTest.php VectorGeometryDataContractTest.php \
  GeometryResourceLimitsTest.php
Result: 46 tests, 138 assertions, 0 failed, 0 skipped.

Executable sandbox gate within the matrix:
- real bubblewrap 0.6.1 namespace smoke: PASS;
- outside-write and symlink victim protection: PASS;
- stdout/stderr/child-file, CPU, VM, NOFILE and wall limits: PASS.

PHPStan/Larastan changed production PHP: [OK] No errors.
Pint focused production/tests: PASS, 36 files.
php -l all changed PHP/config: no syntax errors.
python -m py_compile: exit 0.
python -m black --check: both workers unchanged/pass.
sh -n sandbox and executable harness: exit 0.
git diff --check: exit 0; only existing Dockerfile CRLF normalization warning.
```

No Docker build, database command or migration was executed.

Second corrective implementation commit: `aa10cedd`.

## Third corrective cycle after final review

### Finding → fix evidence

- Closed geometry schema now rejects numeric strings in bounds, invalid optional reference fields and malformed `source_indices`; aggregate scalar and coordinate budgets include nested block members, lineage, segment indices, page boxes/transforms, scale candidates and warning context. The coordinate limit was renamed to reflect component-count semantics.
- DXF traversal aggregates unsupported entities across layouts and recursively expanded blocks, while DWG JSON traversal reports the same bounded numeric `decoder_counts` and reconciliation shape before a typed blocking failure.
- `CadConversionRuntime` accepts worker error context only through a closed numeric whitelist. Unknown keys, strings, document fragments, negative values and excessive counters are rejected as `cad_runtime_error_context_invalid`.
- Runtime isolation is fail-closed on unsupported production platforms. Unsandboxed non-Linux execution is permitted only in the `testing` environment or in `local` with explicit `GEOMETRY_ALLOW_UNISOLATED_LOCAL=1`; Linux sandbox errors now consistently use `*_runtime_sandbox_unavailable`.
- The bubblewrap network bootstrap is separated into `bootstrap-geometry-sandbox-runtime.sh`; the security assertion is offline and fails with an explicit missing-prerequisite message.
- A PHP integration test injects only the platform family, enters the real `GeometryProcessRunner::runSandboxed()` branch, crosses a Windows-to-WSL test adapter, runs the production `geometry-sandbox.sh` with bubblewrap, and verifies argument transfer plus stdout, stderr and exit-code 17 mapping.

### Third corrective verification

```text
LIBREDWG_DWGREAD_BINARY=C:\Users\kamilgaraev\AppData\Local\Temp\libredwg-bin\dwgread.exe vendor/bin/phpunit \
  CadRuntimeContractTest.php CadProductionRuntimeContractTest.php \
  DwgDxfGeometryProviderTest.php PdfVectorGeometryProviderTest.php \
  LegacyPdfGeometryAdapterTest.php VectorGeometryDataContractTest.php \
  GeometryResourceLimitsTest.php
Result: 53 tests, 157 assertions, 0 failed, 0 skipped.

PHPStan/Larastan focused production PHP: [OK] No errors.
Python Black and py_compile: pass.
Shell syntax for production sandbox, offline harness and bootstrap: pass.
```

No Docker build, database command or migration was executed.
