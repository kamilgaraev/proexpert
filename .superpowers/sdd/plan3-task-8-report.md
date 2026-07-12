# Plan 3 / Task 8 — implementation report

## Scope

- Added immutable typed normative work-intent, decision-context, candidate, rejected-candidate, candidate-set and rerank-result DTOs.
- Added tenant/project/dataset-scoped bounded retrieval boundary with deterministic ordering and explicit nullable semantic scoring.
- Added closed hard gates for unit, dimension, material, technology, structure, section, object type, dataset status/version, region and applicability date.
- Replaced fallback reranking with a strict attempt-aware LLM contract and recoverable typed failures.
- Deleted `RuleBasedNormativeCandidateReranker`, its container selection, configuration toggles and test expectations.
- Existing estimate matching now exposes `retrieval_only` metadata and does not present retrieval ordering as an AI result.
- Authored an opt-in PostgreSQL index/query contract; it was not executed locally.

## TDD evidence

### RED

- `NormativeHardGateTest`: 12 failures because typed DTOs and hard-gate service did not exist.
- `NormativeCandidateRerankerTest`: failure because the runtime constructor still required rule-based fallback; typed exceptions did not exist.
- `NormativeRetrievalServiceTest`: failure because the retrieval source/service did not exist.

### GREEN

Command:

```text
php artisan test tests/Unit/EstimateGeneration/Normatives tests/Unit/EstimateGeneration/NormativeCandidateRerankerTest.php
```

Result: **24 tests, 41 assertions, 0 failures**.

Covered contracts include independent and combined hard gates, unknown required data, deterministic bounded ordering, tenant/dataset arguments, unavailable semantic score, timeout, missing usage, malformed closed schema, unknown/duplicate/missing candidate IDs, unknown explanation/field, invalid confidence, bounded untrusted prompt data, and zero-candidate no-network behavior.

## Static verification

- PHPStan/Larastan on 17 changed runtime/test paths: **0 errors**.
- Pint on all changed/new PHP files: **21 files passed**.
- `php -l` on all changed/new PHP files: **no syntax errors**.
- `git diff --check`: **passed**.
- Runtime search for `RuleBasedNormativeCandidateReranker`, `rule_based`, and `llm_enabled`: **no matches**.

## PostgreSQL opt-in inventory

- `tests/Integration/EstimateGeneration/Normatives/PostgresNormativeRetrievalContractTest.php`
- `PostgresNormativeCandidateSource::QUERY_CONTRACT`
- `2026_07_12_001000_add_normative_retrieval_contract.php`

The contract is guarded by `RUN_POSTGRES_NORMATIVE_CONTRACT=1` and was intentionally not run. It requires validation against the production-sized PostgreSQL schema/index rollout before enabling the new retrieval source.

## Concerns

- The PostgreSQL migration and opt-in contract were not run locally; they require the dedicated migrated PostgreSQL contract environment.
- Ordinary estimates remain on their existing explicitly labelled `retrieval_only` path; the AI generation stage uses the strict pinned workflow.

## Corrective review pass

The review's two Critical, three Important and one Minor findings were addressed:

- The production `MatchNormativesStage` now executes the typed retrieval workflow and handles `retrieval_only`, `reranked`, `review_required` and `unavailable`. It requires a pinned `regional_context.normative_dataset_version`; a missing pin blocks review and never calls legacy latest-dataset selection. Exact selected norm IDs are applied through the pinned dataset version.
- The global normative catalog query now follows the real `estimate_norms.collection_id → estimate_norm_collections.dataset_version_id → estimate_dataset_versions.id` chain. Tenant identity remains in the pipeline decision/usage fence instead of fictional catalog columns.
- A rollout migration adds typed compatibility/applicability fields, safe backfill, validity constraint, generated Russian `tsvector`/GIN index, and a version/query-hash scoped semantic score table with bounded score constraint and lookup index. The migration was authored but not run.
- The PostgreSQL opt-in test now inserts real FK fixtures, executes the source query for a pinned version, verifies global-catalog tenant neutrality, enforces the limit, and runs `EXPLAIN (FORMAT JSON)`. It was not run locally.
- Evidence references and explanation codes must be unique lists; evidence must belong to the canonical work/context/candidate allow-list; selection must equal the first ordered candidate.
- Source evidence DTO values are bounded to 32 unique references of at most 128 bytes. Serialized prompts are capped at 16 KiB before the wire call.
- Combined scoring is versioned as `normative-combined-v1`, normalizes lexical/semantic scales, treats absent semantic score explicitly as zero contribution, and uses candidate ID as canonical tie-break. Retrieval requests a bounded pool up to 128 and owns final top-N.
- Attempt identity now includes candidate-set hash, prompt/schema/model versions and dataset versions while retaining one usage record per physical wire attempt.

Corrective verification:

- DB-less normative/workflow/usage/pipeline set: **42 tests, 96 assertions, 0 failures**.
- PHPStan/Larastan corrective scope: **29 paths, 0 errors**.
- Pint: **25 changed/new PHP files passed**.
- `php -l` and `git diff --check`: **passed**.
