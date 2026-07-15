# Task 4B — verified YooKassa webhooks and entitlement activation

## Status

DONE

## Implemented

- Added public `POST /api/v1/webhooks/yookassa` without JWT or organization middleware.
- Added proxy-aware source resolution with the official YooKassa IPv4/IPv6 CIDRs and an explicit trusted-proxy CIDR configuration boundary.
- Added strict notification schema validation and safe payload minimization.
- Extended the YooKassa gateway with authoritative payment fields and `GET /refunds/{id}` using the existing Basic authentication, timeout, and bounded retry policy.
- Added durable webhook fingerprints, authoritative processing results, refund records, cumulative refunded amount, and source-order entitlement ownership.
- Added transactional locking of payment, order, tenant account, and affected package rows.
- Implemented verified success activation, exact package/full-suite contour replacement, safe trial conversion, fixed billing anchor, and consent-plus-provider saved-method renewal gating.
- Implemented waiting/canceled precedence, duplicate and stale handling, partial/full refund policy, and old-order refund isolation.
- Added initiating-user-only notification-center records with the single `in_app` channel and translated user-facing strings.
- Changed the original commercial account migration default for `auto_renew_enabled` to `false`.

## TDD evidence

RED was observed before production code for:

- source IP and trusted proxy resolution;
- authoritative payment/refund DTOs and `getRefund()`;
- webhook/refund/source-order schema;
- payload validation;
- activation, mismatch, cancellation, refund, idempotency, no-op, and retryable provider failure processing;
- the public controller HTTP behavior;
- stale canceled precedence;
- order/payment amount divergence and composite refund ownership.

Fresh final focused and Task 4A regression run:

- 51 tests, 180 assertions, 0 failures, exit code 0.
- New webhook coverage: 37 tests, 129 assertions.
- Task 4A checkout regressions: 14 tests, 51 assertions.

## Quality gates

- PHPStan/Larastan: 15 touched production files, `1G`, `[OK] No errors`.
- Pint `--test`: 27 focused files passed. The legacy `routes/api.php` file was not bulk-reformatted; its three-line route change was syntax-checked separately.
- `php -l`: all 28 changed PHP files passed during implementation; final critical-file syntax check passed.
- `git diff --check`: passed.
- No migrations, DB artisan commands, real YooKassa requests, or dev server were run.

## Self-review

- Confirmed notification data contains no Basic credentials, authorization details, card data, or unfiltered provider/webhook payloads.
- Confirmed full refunds end only rows with the refunded `source_order_id`; partial refunds do not revoke access.
- Confirmed fresh stale canceled events cannot downgrade succeeded local payment/order state.
- Confirmed authoritative amount and currency match both the local payment and local order.
- Confirmed refund payment/order ownership has a composite database constraint.

## Concerns

None. Deployment still requires running the new migration through the normal release process and configuring `YOOKASSA_TRUSTED_PROXY_CIDRS` to match the production reverse-proxy topology.

## Review fixes

The follow-up review findings were addressed in a separate TDD wave:

- A verified webhook arriving before checkout stores `provider_payment_id` now resolves the pending order from whitelisted authoritative metadata, locks the order/payment/account path, validates tenant/provider/ID/test/amount/currency, atomically binds the provider ID, and continues processing. An event that cannot yet bind remains retryable and leaves no durable event marker.
- Checkout now locks and fills provider fields only while the local provider ID is null. A webhook-processed `succeeded` payment is not downgraded by the later checkout response; a different provider ID is rejected as a conflict.
- Refund totals are monotonic. Only the first crossing of the full amount revokes source-owned access and emits the full-refund notification. A late partial after full is recorded as `stale_refund` without decreasing the cumulative total, changing entitlement timestamps, or notifying again.
- Notification payload validation enforces the exact payment/refund event and object-status pairs.
- Fingerprint race recovery now recognizes only the exact webhook fingerprint unique constraint and rethrows unrelated unique failures.
- Payment safe snapshots and authoritative metadata retain only `order_id` and `organization_id`.
- The composite package `source_order_id` foreign key now uses a restrict/no-action deletion policy so audit ownership cannot null the mandatory tenant column.
- Added coverage for the provider-ID race, late checkout overwrite race, late partial after full, old-order refund versus newer source order, immutable trial ledger, exact unique-race handling, and the table-driven authoritative mismatch matrix.

Review-fix verification:

- Covering and checkout regression suites: 68 tests, 251 assertions, 0 failures, exit code 0.
- PHPStan/Larastan for all changed production files: `[OK] No errors` with a 1 GB memory limit.
- Pint, PHP syntax checks, and diff checks passed.
