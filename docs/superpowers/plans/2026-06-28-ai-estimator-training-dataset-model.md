# AI Estimator Training Dataset Model Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a durable backend data model for эталонные наборы AI-сметчика: смета из Гранд-Сметы, проектные документы, чертежи, строки эталона и связь с learning examples.

**Architecture:** Store benchmark/training cases separately from production project estimates. Each dataset owns uploaded files, parsed estimate rows, processing status, quality metrics and links to `estimate_generation_learning_examples`. This keeps user data, training data and generated estimates separated.

**Tech Stack:** Laravel 11, PostgreSQL JSONB, Eloquent models, Filament system-admin permissions.

---

### Task 1: Training Dataset Tables

**Files:**
- Create: `database/migrations/2026_06_28_000003_create_estimate_generation_training_dataset_tables.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Models/EstimateGenerationTrainingDataset.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Models/EstimateGenerationTrainingFile.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Models/EstimateGenerationTrainingExample.php`

- [ ] **Step 1: Add migration**

Create three tables:
- `estimate_generation_training_datasets`
- `estimate_generation_training_files`
- `estimate_generation_training_examples`

Use `jsonb` for source metadata, quality flags, source refs and stats. Add indexes for organization/status/source_system and dataset/status.

- [ ] **Step 2: Add models**

Models must use `declare(strict_types=1);`, casts for JSON/date/numeric fields, and relations:
- dataset belongs to organization/project/system admin
- dataset has many files/examples
- example belongs to dataset/file/learning example

- [ ] **Step 3: Verify**

Run PHP lint and PHPStan on the new models. Do not run migrations locally.

### Task 2: Permission Surface

**Files:**
- Modify: `app/Filament/Support/FilamentPermission.php`
- Modify: `config/RoleDefinitions/system_admin/qa_engineer.json`
- Modify: `config/RoleDefinitions/system_admin/security_auditor.json`
- Modify: `lang/ru/permissions.php`

- [ ] **Step 1: Add permissions**

Add:
- `system_admin.ai_estimator_training.view`
- `system_admin.ai_estimator_training.create`
- `system_admin.ai_estimator_training.process`
- `system_admin.ai_estimator_training.delete`

- [ ] **Step 2: Add role access**

Super admin already has `*`. QA can view/create/process. Security auditor can view only.

- [ ] **Step 3: Translate permission labels**

Add Russian labels in `lang/ru/permissions.php`.
