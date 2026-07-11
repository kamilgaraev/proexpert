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
