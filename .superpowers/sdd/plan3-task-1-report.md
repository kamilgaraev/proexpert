# Plan 3 Task 1 — implementation report

Status: DONE

## Scope

- Added a closed, versioned benchmark manifest for `development`, `regression`, and private `acceptance` datasets.
- Added strict dataset/source enums, immutable case DTOs, manifest validation, hash verification, path containment, symlink/traversal rejection, resource bounds, and cross-dataset ID/locator/digest disjointness.
- Added a typed pipeline-adapter boundary, deterministic sequential runner with per-case timeout contract, safe failure codes, explicit unsupported policy, macro/micro aggregation, per-source/per-tag breakdowns, canonical report JSON, deterministic fingerprint, exact decimal cost accumulation, and unknown-cost accounting.
- Added all required metric names and explicit formulas/empty-set/zero-denominator/outlier semantics.
- Added a DB-less command with explicit adapter/pipeline/prompt/failure-policy versions, strict-zero default failure threshold, protected output root, no overwrite, and production/private-acceptance gates.
- Registered no synthetic production adapter. A real Plan 3 pipeline adapter must be registered by later tasks; tests inject their fixture adapter explicitly.

## Fixture provenance

All repository fixtures are synthetic, contain no client or production content, and declare `CC0-1.0` provenance `synthetic:most-plan3-task1`.

- `vector_pdf`: minimal synthetic PDF descriptor.
- `scanned_pdf`: minimal synthetic raster-PDF descriptor.
- `photo_plan`: minimal PPM image.
- `dimensioned_sketch`: synthetic SVG with a dimension label.
- `undimensioned_sketch`: synthetic SVG without scale.
- `dxf`: minimal ASCII DXF with a wall line and millimetre units.
- `dwg`: legally self-authored synthetic `AC1027` descriptor placeholder, explicitly tagged `unsupported_conversion`; no conversion result is fabricated.
- `acceptance`: manifest-only organization-scoped S3 locators. No credentials, presigned URLs, source data, or expected data are stored in Git.

Every local input and expected object has a manifest SHA-256 verified before execution. Expected objects use `benchmark-expected:v1`.

## TDD evidence

RED was observed before implementation:

- 10 benchmark contract tests failed because `BenchmarkManifest`, `MetricRegistry`, `BenchmarkRunner`, report, and command contracts did not exist.
- Command tests failed because `estimate-generation:benchmark` was not registered.

GREEN after implementation and hardening:

```text
php artisan test tests/Unit/EstimateGeneration/Benchmark
14 passed, 132 assertions

vendor/bin/phpunit --display-warnings tests/Feature/EstimateGeneration/Benchmark/EstimateGenerationBenchmarkCommandTest.php
5 passed, 13 assertions, no warnings
```

The command suite uses a minimal in-memory console container and never boots Laravel persistence or requests a database connection.

## Metric definitions

- `sheet_classification_accuracy`: exact class match.
- `room_iou`, `wall_iou`: set intersection divided by union; empty/empty is 1 only for a technically successful case.
- `opening_f1`: `2TP / (expected + predicted)`; empty/empty is 1 only for a technically successful case.
- `area_mape`, `quantity_mape`, `cost_mape`: mean absolute percentage error by expected item; expected zero/predicted zero is zero error, expected zero/nonzero prediction is capped overflow. Raw error and overflow count are reported; score is `1 - min(1, MAPE)`.
- `work_recall`: matched expected work IDs divided by expected work IDs.
- `normative_top1`, `normative_top3`: expected normative ID found in the first 1/3 returned candidates.
- `technical_success_rate`: technically successful cases divided by attempted cases.
- `evidenced_applicable_items`: applicable items with at least one evidence ID divided by applicable items.

All technical failures score zero across every metric and cannot be inflated by empty-set semantics. All report values are finite. MAPE raw errors are nonnegative and overflow is explicit.

## Static and safety gates

```text
vendor/bin/phpstan analyse app/BusinessModules/Addons/EstimateGeneration/Benchmark app/BusinessModules/Addons/EstimateGeneration/Console/Commands/RunEstimateGenerationBenchmarkCommand.php app/BusinessModules/Addons/EstimateGeneration/EstimateGenerationServiceProvider.php config/estimate-generation.php tests/Unit/EstimateGeneration/Benchmark tests/Feature/EstimateGeneration/Benchmark --memory-limit=1G
No errors

vendor/bin/pint --test ...
PASS, 32 files

git diff --check
clean

php artisan list --raw
estimate-generation:benchmark registered
```

PHP syntax checks passed for every changed PHP file. Recursive privacy checks cover manifest and report output. No migrations, DB commands, external services, package changes, ordinary estimates, or shared AI Assistant code were touched.

## Acceptance corpus

Acceptance execution against real S3 was intentionally not run locally. The command refuses it in production and requires both `RUN_ESTIMATE_GENERATION_ACCEPTANCE_BENCHMARK=1` and an organization-scoped private S3 manifest configured by `ESTIMATE_GENERATION_ACCEPTANCE_BENCHMARK_MANIFEST`. The implementation now includes a bounded, digest-verifying private corpus loader through the existing `FileService`; its gated success path is covered with an isolated fake private object store without credentials or network access.

## Corrective hardening

The review findings were addressed without compatibility layers:

- Production registration now uses `CurrentBaselineBenchmarkAdapter`, which reads only the input object and invokes the current PDF text extractor and rule-based drawing analysis provider. It never reads expected data or opens a database connection.
- Each case runs in a Symfony Process worker with a PHP memory limit, hard timeout, bounded stdout/stderr, closed JSON protocol, and process-tree termination on Windows and POSIX.
- Expected and predicted payloads share the closed `benchmark-expected:v1` schema; unknown fields, invalid nested values, duplicate IDs, foreign evidence references, and schema mismatches are rejected.
- Authorized unsupported cases are excluded from attempted metric and failure-rate denominators; unauthorized unsupported results remain technical failures.
- Digest ownership is global across input and expected roles and all datasets.
- Path traversal, file links, directory links, unknown enums, missing licenses, timeout behavior, acceptance prefix isolation, object hashes, and bounded S3 reads have separate executable tests.

The exact Symfony Process dependency used locally is `symfony/process v7.4.5`, MIT-licensed. Its official API for `start`, `checkTimeout`, `stop`, timeouts, and signals was checked through Context7 before implementation.

The PDF corpus contains two real one-page PDFs verified with Poppler and the executable fixture validator:

- Vector plan: A4 landscape, vector walls/openings/dimensions/text, no embedded image; SHA-256 `18b3ab3ddaa317b1f1f11c0dadd8aae266c5a3de169ee822b9340fb19a517dd4`.
- Scanned plan: A4 landscape, one 1800x1273 grayscale raster, no text layer; SHA-256 `c35739115f4f8347fe05007d97c4ff0c3e8ceb2bd581f7e01ee9d1ea25b797eb`.

Fresh corrective verification:

```text
vendor/bin/pint ...
56 files; 15 style issues fixed

php artisan test tests/Unit/EstimateGeneration/Benchmark
31 passed, 194 assertions

vendor/bin/phpunit --display-warnings tests/Feature/EstimateGeneration/Benchmark/EstimateGenerationBenchmarkCommandTest.php
6 passed, 17 assertions, no warnings

vendor/bin/phpstan analyse <affected production scope> --memory-limit=1G
No errors (42 files)

php artisan estimate-generation:benchmark --dataset=development --adapter=current-baseline ...
Exit 0; 3 attempted, 1 vector success, 2 typed unsupported technical failures, technical success rate 0.3333333333333333
```
