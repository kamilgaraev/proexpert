# Task 3R report: truthful Wave 1 candidate state

## Status

Completed. G01, G04, G09 and G10 are now blocked by source readiness, all twelve candidates have a `null` provider, and `WaveOneCandidateBindingSet::implemented()` returns an empty array before admission.

## Commits

- `051f61770 fix[reports]: восстановлено состояние кандидатов Wave 1`

## Files changed

- `app/BusinessModules/Core/Reporting/Application/Catalog/WaveOneCandidateBindingSet.php`
- `app/BusinessModules/Core/Reporting/Domain/Enums/WaveOneCandidateBindingStatus.php`
- `app/BusinessModules/Core/Reporting/resources/candidates/wave-1-candidates.v1.schema.json`
- `app/BusinessModules/Core/Reporting/resources/candidates/wave-1-candidates.v1.yaml`
- `docs/reports/wave-1-source-contracts.md`
- `docs/superpowers/plans/2026-07-31-reports-plan-2-wave1-foundation-and-first-candidates.md`
- `tests/Unit/Reporting/Catalog/WaveOneCandidateBindingSetTest.php`
- `tests/Unit/Reporting/Catalog/WaveOneCandidateManifestTest.php`

## Checks

- `php -l app/BusinessModules/Core/Reporting/Domain/Enums/WaveOneCandidateBindingStatus.php` — no syntax errors.
- `php -l app/BusinessModules/Core/Reporting/Application/Catalog/WaveOneCandidateBindingSet.php` — no syntax errors.
- `php -l tests/Unit/Reporting/Catalog/WaveOneCandidateManifestTest.php` — no syntax errors.
- `php -l tests/Unit/Reporting/Catalog/WaveOneCandidateBindingSetTest.php` — no syntax errors.
- `vendor/bin/phpunit tests/Unit/Reporting/Catalog/WaveOneCandidateManifestTest.php tests/Unit/Reporting/Catalog/WaveOneCandidateBindingSetTest.php` — PASS: 27 tests, 58 assertions.
- `vendor/bin/phpstan analyse app/BusinessModules/Core/Reporting/Domain/Enums/WaveOneCandidateBindingStatus.php app/BusinessModules/Core/Reporting/Application/Catalog/WaveOneCandidateBindingSet.php --no-progress` — not accepted as a successful check: PHPStan reached the configured 128M memory limit while loading unrelated Reporting analysis; no retry or broader run was performed.
- `git diff --check` — PASS: no output.
- `rg -n "four implemented|four bindings|four total|only bindings with a provider|source_status: implemented|Task 3:|Task 4: Source adapters|Task 5: Conformance|Task 6:" docs/superpowers/plans/2026-07-31-reports-plan-2-wave1-foundation-and-first-candidates.md` — PASS: no obsolete Task 3/provider claims found.

## Risks and concerns

- No production snapshot/replay implementation exists yet; Task 3S must supply the immutable snapshot contract and admission evidence before any provider can be introduced.
- PHPStan needs a higher local memory budget or a narrower project bootstrap to complete; PHPUnit and syntax checks passed for the changed contract.

## Fix round 1/5

- Task 4 evidence is now dynamic: it creates fixtures only for candidates admitted in Task 3A, derives `total_seed_count` from the number of admitted families, and requires 500 cases plus cursor, scope, redaction and snapshot-replay evidence for each admitted family.
- The plan no longer makes G09, four fixtures, or 2,000 total cases a prerequisite for pre-admission Wave 1 state.
