# AI Estimator Training Ingestion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convert uploaded эталонные сметы and project files into vetted `EstimateGenerationLearningExample` records that the AI-сметчик can use as examples.

**Architecture:** Filament upload stores files in organization-scoped S3 paths. The ingestion service creates an import session for the reference estimate, reuses the existing estimate import detector/preview pipeline, normalizes rows, applies quality gates, stores parsed training rows and records accepted rows into learning examples. A queue job runs processing safely outside the request.

**Tech Stack:** Laravel services, existing BudgetEstimates import pipeline, S3 storage, queue jobs, `EstimateGenerationLearningRecorder`.

---

### Task 1: Row Normalization and Quality Gate

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/Services/Training/TrainingEstimateRowNormalizer.php`
- Test: `tests/Unit/EstimateGeneration/TrainingEstimateRowNormalizerTest.php`

- [ ] **Step 1: Normalize estimate rows**

Accept row arrays from `EstimateImportDTO::toArray()`. Skip sections, footers and resource children. Normalize work name, unit, quantity, norm code, section path and row number.

- [ ] **Step 2: Apply quality flags**

Return flags:
- `missing_work_name`
- `missing_norm_code`
- `unit_unverified`
- `valid_training_row`

- [ ] **Step 3: Test pure behavior**

Run the unit test with PHPUnit bootstrap only.

### Task 2: Dataset Processing Service

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/Services/Training/EstimateGenerationTrainingDatasetService.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Jobs/ProcessEstimateGenerationTrainingDatasetJob.php`

- [ ] **Step 1: Store uploaded files**

Persist files in S3 under `org-{id}/estimate-generation/training-datasets/{dataset_uuid}/`.

- [ ] **Step 2: Parse reference estimate**

Create an `ImportSession`, run existing detection/structure/preview pipeline and store parsed rows in `estimate_generation_training_examples`.

- [ ] **Step 3: Record learning examples**

For valid rows with a known norm code, create `EstimateGenerationLearningExample` records with `source_type=superadmin_training_dataset` and source refs to uploaded documents/drawings.

- [ ] **Step 4: Queue processing**

Add a job using `redis_estimate_generation` and `estimate-generation` queue with overlap protection by dataset id.

### Task 3: Verification

**Commands:**
- `php -l` on new PHP files
- `vendor\bin\phpunit.bat --bootstrap vendor\autoload.php tests\Unit\EstimateGeneration\TrainingEstimateRowNormalizerTest.php`
- `vendor\bin\phpstan.bat analyse app\BusinessModules\Addons\EstimateGeneration tests\Unit\EstimateGeneration --memory-limit=1G`

Do not run migrations locally.
