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

## Production rollout order

1. Run expand migration `2026_07_12_001000`: nullable columns, nullable `tsvector`, and empty semantic table only.
2. Repeatedly run `estimate-generation:normative-retrieval-backfill --cursor=<next_cursor> --batch=1000`; persist the returned cursor externally until `complete=true`. Batches are bounded, ordered and idempotent; invalid dates are ignored through PostgreSQL `pg_input_is_valid`.
3. Run `estimate-generation:normative-retrieval-rollout deploy`: under an advisory lock it creates concurrent lookup/GIN indexes outside a transaction, adds constraints as `NOT VALID`, validates them, and writes the `enabled` marker. Historical `001100/001200` migrations are intentional no-ops so ordinary migration queues cannot fail mid-rollout.
5. Enable pinned normative retrieval only after the PostgreSQL opt-in contract passes against the migrated staging schema.

Rollback must run in reverse order. Concurrent index removal cannot be wrapped in a transaction; dropping the expand migration removes retrieval metadata and semantic scores and therefore requires a backup/rebuild plan.

The second corrective pass also introduced an upstream deterministic context pin in `PlanWorkItems` output, a shared bounded reranker model-set resolver, full-pool hard gating before top-N, and explicit combined/lexical/semantic versions in the stage output.

Third corrective deployment requirement: set `ESTIMATE_GENERATION_NORM_APPROVED_DATASET_VERSION` to the exact parsed `fsnb_2022` version approved for generation. Session creation verifies this exact identity server-side. Old clients receive the server policy pin and an immutable server-clock business date; an explicit conflicting client version or an unavailable policy returns HTTP 422 before a session is created.

Backfill progress is persisted in `estimate_normative_retrieval_rollouts` under schema version `normative-retrieval-v1`. The command resumes the stored cursor/target and marks completion atomically; runtime retrieval and index/validation migrations fail closed until the matching completion marker exists.

The third corrective pass moved pin authority fully server-side through `NormativeDatasetPinPolicy`, split retrieval into independently bounded lexical and semantic CTE branches with canonical deduplication, and made the rollout marker a runtime/deployment readiness fence. Final DB-less verification: **52 tests, 144 assertions, 0 failures**; PHPStan/Larastan: **104 paths, 0 errors**. The expanded PostgreSQL contract remains authored but unrun locally.

The fourth corrective pass installs a future-write trigger in the expand migration, refreshes the durable high-water target on every backfill batch, and moves concurrent indexes/constraint validation to the explicit idempotent rollout command. Runtime retrieval requires the final `enabled` marker.

Fourth corrective verification: **52 tests, 144 assertions, 0 failures**; PHPStan/Larastan: **92 paths, 0 errors**. PostgreSQL rollout/contract remains unrun locally.

Verification deliverables add a real bootstrapped Laravel application/provider test (without `RefreshDatabase`) that resolves the production reranker/workflow bindings while replacing only physical wire, usage store, provider and retrieval fixture. It proves one usage record per wire attempt and `unavailable` with no selected retrieval fallback after provider failure. A DB-less pin/hash/non-empty workflow test proves pin changes the canonical stage identity and a compatible candidate reaches `retrieval_only`.

Final verification inventory: **54 DB-less tests, 155 assertions, 0 failures**. The two new deliverable tests pass independently with PHPStan reporting **0 errors**.

Fifth corrective pass: PostgreSQL contract fixtures now use unique version prefixes and no explicit transaction around concurrent DDL; deploy asserts transaction level zero and cleanup is explicit. Durable rollout state separates backfill status from deploy phase/status, retains failed phase, and replays idempotent phase work under an advisory lock until `enabled`. The opt-in contract remains unrun locally.

Second corrective verification:

- DB-less normative, container model-set, attempt-aware usage and pipeline set: **50 tests, 142 assertions, 0 failures**.
- PHPStan/Larastan expanded corrective scope: **89 paths, 0 errors**.
- Pint, `php -l` and `git diff --check`: passed for all changed/new PHP files.
- PostgreSQL expand/backfill/concurrent-index/validate contract remains opt-in and was not executed locally.
