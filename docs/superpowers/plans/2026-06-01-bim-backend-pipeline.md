# BIM Backend Pipeline Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a backend BIM preparation pipeline where an IFC derivative is marked ready only after the generated viewer file is structurally valid and contains renderable geometry.

**Architecture:** Keep ThatOpen Fragments as the free commercial-use derivative format for stage 1, but wrap it in our own server-side pipeline contract. The Node converter emits structured result metrics, PHP parses them into a typed result object, and `DesignModelViewerPreparationService` stores geometry diagnostics only after validating that the fragment contains geometry and a finite bounding box.

**Tech Stack:** Laravel 11, Symfony Process, S3/FileService, ThatOpen Fragments 3.4, FlatBuffers, pako, Vitest not required for this backend-only change.

---

### Task 1: Pipeline Result Contract

**Files:**
- Create: `app/BusinessModules/Features/DesignManagement/Support/DesignViewerConversionResult.php`
- Modify: `app/BusinessModules/Features/DesignManagement/Services/Contracts/DesignIfcToFragmentsConverterContract.php`
- Test: `tests/Unit/DesignManagement/DesignViewerConversionResultTest.php`

- [x] **Step 1: Write failing tests**

Create tests proving that a result with `local_id_count`, `sample_count`, `representation_count`, `bbox`, and converter diagnostics is accepted, while zero-geometry and invalid bounding boxes are rejected.

- [x] **Step 2: Run tests to verify RED**

Run: `php artisan test tests\Unit\DesignManagement\DesignViewerConversionResultTest.php`

Expected: fail because `DesignViewerConversionResult` does not exist.

- [x] **Step 3: Implement result object**

Add a strict typed support class with `fromPayload(array $payload)`, `assertRenderableGeometry()`, and `metadata()` methods. The metadata must be storage-safe, array-only, and use stable keys under `geometry`.

- [x] **Step 4: Run tests to verify GREEN**

Run: `php artisan test tests\Unit\DesignManagement\DesignViewerConversionResultTest.php`

Expected: pass.

### Task 2: Preparation Service Gate

**Files:**
- Modify: `app/BusinessModules/Features/DesignManagement/Services/DesignModelViewerPreparationService.php`
- Modify: `tests/Unit/DesignManagement/PrepareDesignModelViewerJobTest.php`

- [x] **Step 1: Write failing tests**

Extend the ready-path test so the mocked converter returns geometry metadata and the saved derivative contains it. Add a failure-path test where the converter returns no renderable geometry and the derivative becomes `failed`.

- [x] **Step 2: Run tests to verify RED**

Run: `php artisan test --filter=PrepareDesignModelViewerJobTest`

Expected: fail because the service still expects the converter to return `void`.

- [x] **Step 3: Implement service gate**

Change the converter contract to return `DesignViewerConversionResult`. After conversion and before upload, call `assertRenderableGeometry()`. Store `metadata()` together with source and derivative sizes via `DesignViewerConverter::preparedMetadata(...)`.

- [x] **Step 4: Run tests to verify GREEN**

Run: `php artisan test --filter=PrepareDesignModelViewerJobTest`

Expected: pass.

### Task 3: Node Fragment Inspector

**Files:**
- Modify: `resources/js/design-management/convert-ifc-to-frag.mjs`
- Modify: `app/BusinessModules/Features/DesignManagement/Services/DesignIfcToFragmentsConverter.php`
- Test: `tests/Unit/DesignManagement/DesignViewerConversionResultTest.php`

- [x] **Step 1: Write failing parser/metadata tests**

Add test coverage for accepting structured converter result payloads with fragment metrics and rejecting missing result payloads in the PHP result layer.

- [x] **Step 2: Run tests to verify RED**

Run: `php artisan test tests\Unit\DesignManagement\DesignViewerConversionResultTest.php`

Expected: fail until the converter result parser is wired.

- [x] **Step 3: Implement JS inspection**

After `IfcImporter.process(...)`, inflate the `.frag` payload with `pako`, read the ThatOpen `Model` flatbuffer, count local IDs, categories, samples, representations, shells, and union representation bounding boxes. Emit a final JSON line `{ "event": "result", "metrics": ... }`. Throw when no renderable geometry is found.

- [x] **Step 4: Implement PHP process parsing**

Make `DesignIfcToFragmentsConverter` capture `result` events from stdout, create a `DesignViewerConversionResult`, and throw a clear runtime error when the process exits successfully but no valid result was emitted.

- [x] **Step 5: Verify JS syntax**

Run: `node --check resources/js/design-management/convert-ifc-to-frag.mjs`

Expected: pass.

### Task 4: Verification And Push

**Files:**
- All changed backend and converter files.

- [x] **Step 1: PHP syntax**

Run `php -l` on every changed PHP file.

- [x] **Step 2: Focused tests**

Run:
- `php artisan test tests\Unit\DesignManagement\DesignViewerConversionResultTest.php`
- `php artisan test --filter=PrepareDesignModelViewerJobTest`
- `php artisan test tests\Feature\DesignManagement\DesignManagementApiTest.php --filter=viewer`

- [x] **Step 3: Static analysis**

Run `vendor\bin\phpstan analyse` on the touched module/test files with `--memory-limit=1G`.

- [x] **Step 4: Git hygiene**

Run `git diff --check`, review `git diff`, commit in Russian Conventional Commit format, push `main`, then check GitHub Actions deployment status.
