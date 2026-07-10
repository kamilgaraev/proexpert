# Site Request And Procurement Consistency Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Закрыть приватность черновиков, поддержать ручные материалы в обеспечении и убрать расхождение цепочек заказа за счет серверного источника истины.

**Architecture:** Actor-aware scopes и доменные guards защищают заявки на backend во всех read/write сценариях. `SiteRequestFulfillmentService` разделяет общую валидность материальной заявки и складскую пригодность, а admin рендерит явные capabilities. Детальная карточка заказа получает полную `procurement_chain` в одном snapshot и не вычисляет этапы на клиенте.

**Tech Stack:** Laravel 11/PHP 8.2, AdminResponse, PostgreSQL contracts, React/Vite/TypeScript, Material UI, Vitest/MSW, Larastan/PHPStan.

---

### Task 1: Backend privacy boundary for drafts

**Files:**
- Modify: `app/BusinessModules/Features/SiteRequests/Models/SiteRequest.php`
- Modify: `app/BusinessModules/Features/SiteRequests/Models/SiteRequestGroup.php`
- Modify: `app/BusinessModules/Features/SiteRequests/Services/SiteRequestService.php`
- Modify: `app/BusinessModules/Features/SiteRequests/Http/Controllers/SiteRequestController.php`
- Modify: `app/BusinessModules/Features/SiteRequests/Http/Controllers/Mobile/SiteRequestController.php`
- Modify: `app/BusinessModules/Features/SiteRequests/Http/Controllers/SiteRequestDashboardController.php`
- Test: `tests/Feature/Api/V1/Admin/SiteRequestDraftVisibilityTest.php`
- Test: `tests/Feature/Api/V1/Mobile/SiteRequestsMobileTest.php`

- [ ] **Step 1: Write failing visibility tests**

Create two users in one organization and assert: own draft is present, foreign draft is absent, foreign pending is present, paginator total is correct, and foreign draft `show/update/delete/submit` returns `404` without mutation.

```php
$response = $this->actingAs($viewer)->getJson('/api/v1/admin/site-requests');
$response->assertOk()->assertJsonMissing(['id' => $foreignDraft->id]);
$this->actingAs($viewer)
    ->getJson('/api/v1/admin/site-requests/'.$foreignDraft->id)
    ->assertNotFound();
```

- [ ] **Step 2: Verify RED without violating environment constraints**

Run the focused feature test only when the configured test connection is explicitly safe and isolated. In the current Codex environment, do not open a local DB connection; otherwise record the test as written but not executed and use syntax/static checks.

- [ ] **Step 3: Add mandatory actor-aware scopes and service signatures**

Implement `visibleTo(int $actorUserId)` on requests and groups and make actor ID required in `find`, `findGroup`, `paginate`, statistics and overdue methods.

```php
return $query->where(static function (Builder $query) use ($actorUserId): void {
    $query->where('status', '!=', SiteRequestStatusEnum::DRAFT->value)
        ->orWhere('user_id', $actorUserId);
});
```

- [ ] **Step 4: Pass the actor through all admin/mobile read paths**

Every controller obtains the authenticated integer user ID and calls the actor-aware service. A foreign draft resolves as not found before any action-specific authorization.

- [ ] **Step 5: Add mutation guards**

`update`, `delete`, `submit`, `updateGroup` and `submitGroup` reject non-owned drafts. `delete` rejects non-draft records with a translated `422` domain error.

- [ ] **Step 6: Cover statistics, overdue and cache isolation**

Add tests proving foreign drafts do not affect aggregates and include the actor ID in cache keys.

- [ ] **Step 7: Run static verification**

Run `php -l` for every changed PHP file and `vendor/bin/phpstan analyse app/BusinessModules/Features/SiteRequests --memory-limit=1G --no-progress`.

### Task 2: Draft publication channels and admin capabilities

**Files:**
- Modify: `app/BusinessModules/Features/SiteRequests/Services/SiteRequestNotificationService.php`
- Modify: `app/BusinessModules/Features/SiteRequests/Services/SiteRequestCalendarService.php`
- Modify: `app/BusinessModules/Features/SiteRequests/Listeners/CreateCalendarEventOnSiteRequest.php`
- Modify: `app/BusinessModules/Features/SiteRequests/Http/Resources/SiteRequestResource.php`
- Modify: `app/BusinessModules/Features/SiteRequests/Http/Resources/SiteRequestGroupResource.php`
- Modify: `lang/ru/site_requests.php`
- Modify: `../prohelper_admin/src/types/siteRequest.ts`
- Modify: `../prohelper_admin/src/components/siteRequests/SiteRequestsTable.tsx`
- Modify: `../prohelper_admin/src/pages/SiteRequests/SiteRequestDetailPage.tsx`
- Modify: `../prohelper_admin/src/pages/SiteRequests/SiteRequestGroupPage.tsx`
- Test: `tests/Feature/Api/V1/Admin/SiteRequestDraftVisibilityTest.php`
- Test: `../prohelper_admin/src/pages/SiteRequests/SiteRequestsPages.test.tsx`

- [ ] **Step 1: Write failing publication and capability tests**

Assert draft creation does not notify other users or create a shared calendar event; submission publishes once. Assert resources expose actor-specific `can_be_edited`, `can_be_deleted`, `can_submit` and group equivalents.

- [ ] **Step 2: Verify RED under the same isolated-test rule**

Do not run backend feature tests against a local/production database. Run frontend tests normally.

- [ ] **Step 3: Move publication from create to submit**

Keep draft creation private. Reuse existing events/listeners only after the status transition to pending, preserving idempotency and avoiding duplicate calendar events/notifications.

- [ ] **Step 4: Return backward-compatible capabilities**

Add fields without removing existing keys. Capabilities combine current actor ownership, status and existing permission outcome; no role slug checks.

- [ ] **Step 5: Make admin actions capability-driven**

Tables, detail and group pages hide or disable unavailable actions from backend capabilities and tolerate missing fields during rollout.

- [ ] **Step 6: Verify frontend behavior**

Run focused Vitest and `npx tsc --noEmit`; do not run `npm run build`.

### Task 3: Manual material fulfillment backend contract

**Files:**
- Modify: `app/BusinessModules/Features/SiteRequests/Services/SiteRequestFulfillmentService.php`
- Modify: `app/BusinessModules/Features/SiteRequests/Http/Controllers/SiteRequestFulfillmentController.php`
- Modify: `app/BusinessModules/Features/Procurement/Services/PurchaseRequestService.php`
- Modify: `lang/ru/site_requests.php`
- Test: `tests/Feature/Api/V1/Admin/SiteRequestFulfillmentControllerTest.php`
- Test: `tests/Feature/Procurement/ProcurementChainServiceTest.php`

- [ ] **Step 1: Write failing manual-material tests**

Cover GET options returning `200` with purchase-only capabilities, POST purchase creating one request/line with `material_id=null`, warehouse/mixed returning `422` without writes, retry idempotency, catalog regression and tenant isolation.

```php
$this->actingAs($user)
    ->getJson('/api/v1/admin/site-requests/'.$manualRequest->id.'/fulfillment-options')
    ->assertOk()
    ->assertJsonPath('data.material_source', 'manual')
    ->assertJsonPath('data.can_use_warehouse', false)
    ->assertJsonPath('data.can_use_purchase', true);
```

- [ ] **Step 2: Verify RED under the isolated-test rule**

Do not run database-backed tests unless the environment has an explicitly approved isolated test connection.

- [ ] **Step 3: Split common and source-specific validation**

Common validation requires approved material request, positive quantity and either catalog ID or non-empty manual name. Warehouse/mixed validation additionally requires `material_id`.

- [ ] **Step 4: Return explicit capabilities**

Return `material_source`, `warehouse_lookup_supported`, `warehouse_unavailable_reason`, source booleans, recommendation and empty warehouses for manual material.

- [ ] **Step 5: Preserve purchase data and idempotency**

Catalog requests propagate `material_id`; manual requests retain the name, quantity and unit with `material_id=null`. Repeated decisions return the existing result.

- [ ] **Step 6: Run PHP syntax and static analysis**

Run `php -l` for changed files and focused Larastan/PHPStan.

### Task 4: Fulfillment dialog UX and toast ownership

**Files:**
- Modify: `../prohelper_admin/src/services/siteRequestService.ts`
- Modify: `../prohelper_admin/src/types/siteRequest.ts`
- Modify: `../prohelper_admin/src/components/siteRequests/FulfillmentDecisionDialog.tsx`
- Test: `../prohelper_admin/src/services/siteRequestService.test.ts`
- Create: `../prohelper_admin/src/components/siteRequests/FulfillmentDecisionDialog.test.tsx`

- [ ] **Step 1: Write failing component/service tests**

Test manual purchase-only rendering, material summary, warehouse warning, inline load error with retry, disabled save before selection, and exactly one global toast per failed/successful request.

- [ ] **Step 2: Run Vitest and confirm expected failures**

Run `npx vitest run src/components/siteRequests/FulfillmentDecisionDialog.test.tsx src/services/siteRequestService.test.ts`.

- [ ] **Step 3: Type and normalize the additive contract**

Normalize absent capability fields defensively so older responses still render catalog behavior.

- [ ] **Step 4: Implement populated manual and explicit error states**

Render inline `Alert` for purchase-only manual material. On load failure render message and retry button instead of empty `DialogContent`.

- [ ] **Step 5: Remove local duplicate toast calls and stale responses**

Use request sequencing/cancellation for option loading and let the API layer own standard toast messages.

- [ ] **Step 6: Verify GREEN and TypeScript**

Run focused Vitest and `npx tsc --noEmit`.

### Task 5: Full procurement chain in purchase-order detail

**Files:**
- Modify: `app/BusinessModules/Features/Procurement/Http/Controllers/PurchaseOrderController.php`
- Create or modify: `app/BusinessModules/Features/Procurement/Http/Resources/PurchaseOrderDetailResource.php`
- Modify: `app/BusinessModules/Features/Procurement/Http/Resources/PurchaseOrderResource.php`
- Modify: `app/BusinessModules/Features/Procurement/Services/ProcurementChainService.php`
- Test: `tests/Feature/Api/V1/Admin/ProcurementSupplierFlowCoreExperienceControllerTest.php`
- Test: `tests/Feature/Procurement/ProcurementChainServiceTest.php`

- [ ] **Step 1: Write failing detail contract and partial-delivery tests**

Assert show returns full `procurement_chain`, list stays compact, and `partially_delivered` produces the correct canonical current stage/blocker.

- [ ] **Step 2: Verify RED under the isolated-test rule**

Keep database-backed tests unexecuted unless the connection is explicitly approved and isolated.

- [ ] **Step 3: Build a detail-only resource snapshot**

Resolve order and full chain in one controller/resource response. Do not call a second chain endpoint from the detail client and do not add full stages to list resources.

- [ ] **Step 4: Keep compatibility adapters**

Preserve current `workflow_summary` and `procurement_chain_summary` keys and derive their meaning from the canonical projection where practical.

- [ ] **Step 5: Verify syntax and static analysis**

Run PHP syntax checks and focused Procurement Larastan/PHPStan.

### Task 6: Server-driven purchase-order workflow UI

**Files:**
- Modify: `../prohelper_admin/src/types/procurement.ts`
- Modify: `../prohelper_admin/src/services/procurementApiService.ts`
- Modify: `../prohelper_admin/src/services/procurementApiService.test.ts`
- Modify: `../prohelper_admin/src/components/procurement/chain/ProcurementChainPanel.tsx`
- Modify: `../prohelper_admin/src/components/procurement/chain/ProcurementChainPanel.test.tsx`
- Modify: `../prohelper_admin/src/components/procurement/purchaseOrders/PurchaseOrderDetail.tsx`
- Create: `../prohelper_admin/src/components/procurement/purchaseOrders/PurchaseOrderDetail.test.tsx`

- [ ] **Step 1: Write failing normalization and detail tests**

Mock an order with `status=partially_delivered` and authoritative server stages. Assert the page renders server labels/status/blocker and makes no second chain request or local conflicting inference.

- [ ] **Step 2: Run focused Vitest and confirm RED**

Run the three listed test files with `npx vitest run`.

- [ ] **Step 3: Normalize full detail chain**

Type `procurement_chain` as detail-only optional data and normalize nullable arrays/optional fields at the service boundary.

- [ ] **Step 4: Remove the local business-stage calculator**

Delete `buildTimelineSteps` and use the server chain renderer. Keep order facts such as dates in a clearly named status/info area, not a competing workflow.

- [ ] **Step 5: Handle unavailable chain explicitly**

Show an error/empty state when chain is unavailable; do not silently infer a fallback stage from `PurchaseOrder.status`.

- [ ] **Step 6: Verify GREEN and TypeScript**

Run focused Vitest and `npx tsc --noEmit`.

### Task 7: Cross-review, workflow documentation and final verification

**Files:**
- Modify: `docs/superpowers/specs/2026-07-10-site-request-and-procurement-consistency-design.md` only if implementation decisions changed
- Modify or create: relevant workflow documentation discovered in the ProHelper documentation workspace

- [ ] **Step 1: Run spec-compliance review per completed task**

Each reviewer checks the task requirements line by line and reports missing or extra behavior before code-quality review starts.

- [ ] **Step 2: Run code-quality review per completed task and final diff review**

Resolve every critical/important finding and re-review fixes.

- [ ] **Step 3: Verify all changed PHP files**

Run `php -l` and focused PHPStan/Larastan. Do not claim backend feature tests passed if DB constraints prevented execution.

- [ ] **Step 4: Verify admin**

Run all touched Vitest files and `npx tsc --noEmit`; do not run admin build.

- [ ] **Step 5: Perform browser smoke if an existing URL is available**

Open the target pages without starting a forbidden dev server; inspect screen, console and network. Otherwise record browser verification as unavailable.

- [ ] **Step 6: Sync workflow documentation**

Document personal draft visibility, publication on submit, manual purchase-only fulfillment and the canonical order chain in the existing ProHelper workflow section.

- [ ] **Step 7: Re-read this plan and report evidence**

Confirm every requirement has a code path and verification result, list any environment-limited checks, and only then mark the goal complete.
