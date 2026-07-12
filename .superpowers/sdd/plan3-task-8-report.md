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
- `PostgresNormativeCandidateSource::INDEX_CONTRACT`
- `PostgresNormativeCandidateSource::QUERY_CONTRACT`

The contract is guarded by `RUN_POSTGRES_NORMATIVE_CONTRACT=1` and was intentionally not run. It requires validation against the production-sized PostgreSQL schema/index rollout before enabling the new retrieval source.

## Concerns

- The PostgreSQL index is authored as a deployment contract only; no migration was added and no database command was run for it.
- Ordinary estimate ranking remains unchanged and is explicitly labelled `retrieval_only`; invoking strict LLM reranking requires the typed workflow stage and complete decision context.
