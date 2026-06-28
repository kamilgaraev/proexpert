# AI Estimator Superadmin Training UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a backend Filament superadmin screen where an operator uploads an эталонный комплект and starts AI-сметчик learning ingestion.

**Architecture:** A Filament resource manages `EstimateGenerationTrainingDataset`. Create page accepts organization, optional project, reference estimate file and supporting documents/drawings. List/view pages show processing status, counts, quality flags and created learning examples. Actions queue processing or retry failed datasets.

**Tech Stack:** Filament Resource, Filament Forms/Tables/Infolists, Laravel translations and system-admin permissions.

---

### Task 1: Filament Resource

**Files:**
- Create: `app/Filament/Resources/EstimateGenerationTrainingDatasetResource.php`
- Create: `app/Filament/Resources/EstimateGenerationTrainingDatasetResource/Pages/ListEstimateGenerationTrainingDatasets.php`
- Create: `app/Filament/Resources/EstimateGenerationTrainingDatasetResource/Pages/CreateEstimateGenerationTrainingDataset.php`
- Create: `app/Filament/Resources/EstimateGenerationTrainingDatasetResource/Pages/ViewEstimateGenerationTrainingDataset.php`

- [ ] **Step 1: Add form**

Fields:
- organization
- project
- title
- source system
- region/period notes
- reference estimate file
- supporting documents/drawings/scans
- source quality score
- notes

- [ ] **Step 2: Add table**

Show organization, title, source system, status, examples count, learning examples count, quality score, updated date.

- [ ] **Step 3: Add actions**

Actions:
- process queued job
- retry failed dataset
- delete only when permission allows

### Task 2: Translations and Navigation

**Files:**
- Modify: `app/Filament/Support/NavigationGroups.php`
- Modify: `lang/ru/filament_navigation.php`
- Modify: `lang/ru/estimate_generation.php`

- [ ] **Step 1: Add navigation group**

Add group for AI-сметчик in superadmin panel.

- [ ] **Step 2: Add readable UI labels**

All visible labels must be Russian business text, not internal technical keys.

### Task 3: Documentation

**Files:**
- Create: `docs/ai-estimator/training-dataset-superadmin.md`

- [ ] **Step 1: Document operator workflow**

Explain what to upload, accepted formats, what is recorded into learning memory, and what remains manual.
