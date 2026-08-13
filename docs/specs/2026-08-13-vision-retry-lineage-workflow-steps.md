# Vision retry lineage and workflow steps

## Incident evidence

The controlled retry for document `170` created a new document processing attempt and reset the units, but no new Vision physical-attempt rows were inserted. Two units replayed the old terminal HTTP 400 rows and the document breaker stopped the remaining twenty units. The diagnostic response inspector therefore had no new response stream to inspect.

## Contracts

- A processing attempt identifier is part of every primary and targeted sheet-analysis operation identity.
- Lease retries and concurrent delivery inside one processing attempt keep the same identity.
- A new explicit document retry keeps the pinned source/model contract but receives a different operation and physical-attempt identity.
- The session snapshot exposes canonical `workflow_steps` entries with `id`, `available`, and `recommended`.
- With zero usable document results and a recoverable system failure, `documents` is the only recommended recovery step; geometry and downstream steps are unavailable.
- Partial success keeps geometry review available when at least one usable result exists, but keeps downstream steps unavailable while document recovery is required.
- A fully recovered document set recalculates geometry and downstream availability and recommends geometry review at the input-review checkpoint.

## Verification

- Unit regressions for primary/targeted identities and session step policy.
- Isolated PostgreSQL contract proving unit metadata reaches the execution context unchanged.
- Admin Vitest/MSW regressions for zero usable system failure, partial success, and normal success.
- PHP syntax, Pint, targeted Larastan, TypeScript, ESLint, and Prettier gates.

## Release boundary

This release does not change the Luna request payload and must not trigger a production Vision call. A later user-authorized retry is required to capture the provider's exact HTTP 400 parameter safely.
