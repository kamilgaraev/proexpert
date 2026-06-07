# PHERP-75: 1C Exchange Incidents And Runbook

## Goal

Add a production-ready operational reaction layer for 1C exchange failures: incident rules, safe admin-facing texts, owners, response deadlines, runbook data contract, backend monitoring payload, and admin UI.

## Backend Contract

- Extend the existing `GET /api/v1/admin/one-c-exchange/monitoring` response.
- Keep `problem_operations` backward-compatible and enrich each problem operation with an `incident`.
- Add top-level `incidents`, `notification_summary`, and `runbook`.
- Use only safe fields: operation id/key, status, scope, direction, safe error code/message, retry counters, owner, deadline, and supported action descriptors.
- Do not expose raw payload, stack trace, token, secret, exception text, SQL text, or constraint diagnostics.
- Use `one_c_exchange.view` for read access and existing `one_c_exchange.retry` / `one_c_exchange.dead_letter.manage` permissions for mutation actions.

## Runbook Scenarios

- 1C unavailable / `transport_error` / `timeout`.
- `dead_letter` after attempts are exhausted.
- `requires_mapping`.
- stale `processing`.
- overdue retry.
- `business_validation` / `rejected`.
- delivery disabled / `transport_unconfigured`.

## Admin UI

- Add a dense operational block: "Инциденты и инструкция".
- Show active incidents first: severity, owner, deadline, operation identifier, next action, and supported actions.
- Show runbook scenarios with signals, ProHelper checks, handoff to 1C specialist, retry guidance, manual review trigger, and escalation trigger.
- Preserve loading, error, empty, and populated states.

## Verification

- Backend unit tests for rule resolver and runbook mapper.
- Feature coverage for monitoring contract.
- Admin service/type/component/page Vitest coverage.
- `php -l`, targeted `phpstan analyse`, `npx tsc --noEmit`, targeted Vitest, UTF-8/mojibake scan, and `git diff --check`.
