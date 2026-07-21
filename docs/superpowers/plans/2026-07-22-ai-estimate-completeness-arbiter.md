# AI Estimate Completeness Arbiter Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prevent an AI estimate with partial confirmed work from being presented or applied as a full object budget, while adding a safe AI arbiter that can request one evidence-bounded targeted rebuild.

**Architecture:** Existing deterministic readiness gates remain authoritative for quantities, norms, resources and prices. New pure projections store a completeness boundary and a commercial-budget boundary in the draft; a separate read-only arbiter emits only validated references to existing scope and package keys. The session snapshot and the ordinary-estimate metadata transport those projections without changing ordinary-estimate actions.

**Tech Stack:** PHP 8.2, Laravel 11, PHPUnit, React, TypeScript, Vitest, MUI.

## Global Constraints

- Use `declare(strict_types=1);` in every new PHP file and PSR-12 in backend code.
- Use `trans_message('file.key')` for every new PHP user-facing message; UI copy is human-readable Russian.
- Do not modify contracts, contract documents, contract services, or their tests.
- Do not alter a chosen normative work item's resource composition; no arbiter output may change a work item, resource, quantity, norm or price.
- Roofing stays evidence-bounded: confirmed covering does not imply rafters, insulation, membranes, lathing or gutters.
- Do not run migrations, database commands, dev servers, or frontend builds.
- Preserve existing dirty worktree changes and stage only task files. Every commit uses Russian Conventional Commit with `[lk]`.
- First release uses `shadow`; it records and displays the verdict but does not change `can_apply`. A later configuration-only delivery introduces `enforce`.

## File Structure

| File | Responsibility |
| --- | --- |
| `app/BusinessModules/Addons/EstimateGeneration/Services/Quality/EstimateCompletenessProfile.php` | Deterministically projects requested, covered, excluded and unresolved scope. |
| `app/BusinessModules/Addons/EstimateGeneration/Services/Quality/EstimateBudgetScope.php` | Separates direct costs from uncalculated overhead/profit and determines the allowed budget claim. |
| `app/BusinessModules/Addons/EstimateGeneration/Services/Quality/DraftReadinessProjector.php` | Stores deterministic projections in the draft without weakening readiness gates. |
| `app/BusinessModules/Addons/EstimateGeneration/Quality/Arbiter/*.php` | Immutable verdict, strict validator, bounded AI adapter, audit and remediation coordinator. |
| `app/BusinessModules/Addons/EstimateGeneration/Application/Sessions/*.php` | Publishes the three projections through the session API. |
| `app/BusinessModules/Addons/EstimateGeneration/Application/Apply/LaravelGeneratedEstimateWriter.php` | Persists the boundary at the sole normal-estimate creation point. |
| `prohelper_admin/src/features/estimate-generation` | Typed presentation of coverage, exclusions, money state and arbiter result. |
| `prohelper_admin/src/pages/Estimates` | Persistent notice in the created ordinary estimate. |

---

### Task 1: Deterministic completeness profile

**Files:**

- Create: `app/BusinessModules/Addons/EstimateGeneration/Services/Quality/EstimateCompletenessProfile.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Services/Quality/DraftReadinessProjector.php`
- Test: `tests/Unit/EstimateGeneration/Quality/EstimateCompletenessProfileTest.php`
- Test: `tests/Unit/EstimateGeneration/Quality/DraftReadinessInspectorTest.php`

**Interfaces:**

- Consumes `ResidentialWorkCompositionCatalog::requirements(array $draft): array` and the same work-item metadata recognized by `DraftResidentialCompositionInspector`.
- Produces `EstimateCompletenessProfile::project(array $draft): array` containing `status`, `scopes`, `covered`, `unresolved`, and `excluded`.
- `DraftReadinessProjector::project()` stores this value at `$draft['completeness']`; `DraftReadinessInspector` remains the owner of `required_scope_unresolved`.

- [ ] **Step 1: Write failing profile tests**

```php
public function testItMarksHeatingDistributionAsUnresolvedWhenOnlyTheHeatSourceIsPresent(): void
{
    $profile = (new EstimateCompletenessProfile)->project($this->residentialDraft([
        'heating' => ['heating.unit'],
    ]));

    self::assertSame('confirmed_scope_only', $profile['status']);
    self::assertSame(['heating.pipe', 'heating.radiators'], $profile['unresolved'][0]['missing_items']);
}

public function testItAcceptsOnlyAnExplicitExclusionWithEvidenceReference(): void
{
    $profile = (new EstimateCompletenessProfile)->project($this->residentialDraft([], [
        'heating' => ['reason' => 'user_decision', 'evidence_refs' => ['input:heating-excluded']],
    ]));

    self::assertSame('excluded', $profile['scopes']['heating']['state']);
}

public function testItDoesNotInventPitchedRoofLayersWhenOnlyCoveringIsConfirmed(): void
{
    $profile = (new EstimateCompletenessProfile)->project(
        $this->residentialDraft(['roof' => ['roof.covering']], roofType: 'pitched'),
    );

    self::assertSame('covered', $profile['scopes']['roof']['state']);
}
```

- [ ] **Step 2: Run the focused tests and verify they fail**

Run: `php artisan test tests/Unit/EstimateGeneration/Quality/EstimateCompletenessProfileTest.php`

Expected: FAIL because `EstimateCompletenessProfile` does not exist.

- [ ] **Step 3: Implement the smallest pure profile**

```php
final readonly class EstimateCompletenessProfile
{
    public function __construct(private ResidentialWorkCompositionCatalog $catalog = new ResidentialWorkCompositionCatalog) {}

    public function project(array $draft): array
    {
        $coverage = $this->coveredWorkKeys($draft);
        $exclusions = $this->explicitExclusions($draft);

        foreach ($this->catalog->requirements($draft) as $packageKey => $requiredItems) {
            $scopes[$packageKey] = $this->scope($packageKey, $requiredItems, $coverage, $exclusions);
        }

        return $this->summary($scopes ?? []);
    }
}
```

The private `scope()` accepts an exclusion only when reason is `user_decision` or `document` and `evidence_refs` is a non-empty list of non-empty strings. It uses only `composition_work_key`, `material_scenario_work_key`, `quantity_key` and the existing formula fallback. It never reads resources or creates work items.

- [ ] **Step 4: Store the projection without changing readiness**

```php
$draft['completeness'] = $this->completeness->project($draft);
$draft['quality_summary'] = [
    ...($draft['quality_summary'] ?? []),
    'completeness_status' => $draft['completeness']['status'],
];
```

Inject `EstimateCompletenessProfile` into `DraftReadinessProjector`; do not add a blocking code because this release runs in shadow mode.

- [ ] **Step 5: Run verification**

Run: `php artisan test tests/Unit/EstimateGeneration/Quality/EstimateCompletenessProfileTest.php tests/Unit/EstimateGeneration/Quality/DraftReadinessInspectorTest.php`

Expected: PASS.

Run: `vendor/bin/phpstan analyse app/BusinessModules/Addons/EstimateGeneration/Services/Quality/EstimateCompletenessProfile.php app/BusinessModules/Addons/EstimateGeneration/Services/Quality/DraftReadinessProjector.php`

Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add app/BusinessModules/Addons/EstimateGeneration/Services/Quality/EstimateCompletenessProfile.php app/BusinessModules/Addons/EstimateGeneration/Services/Quality/DraftReadinessProjector.php tests/Unit/EstimateGeneration/Quality/EstimateCompletenessProfileTest.php
git commit -m "feat[lk]: определяется комплектность AI-сметы"
```

### Task 2: Budget boundary and normal-estimate traceability

**Files:**

- Create: `app/BusinessModules/Addons/EstimateGeneration/Services/Quality/EstimateBudgetScope.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Services/Quality/DraftReadinessProjector.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Apply/LaravelGeneratedEstimateWriter.php`
- Test: `tests/Unit/EstimateGeneration/Quality/EstimateBudgetScopeTest.php`
- Test: `tests/Unit/EstimateGeneration/Application/Apply/LaravelGeneratedEstimateWriterTest.php`

**Interfaces:**

- Consumes `draft['completeness']`, final direct total and calculation evidence for overhead/profit.
- Produces `EstimateBudgetScope::project(array $draft, float $directCosts): array` with `direct_costs`, `overhead`, `profit`, `commercial_budget`, `claim`.
- The writer adds `metadata['ai_scope']` but preserves every existing numeric estimate total.

- [ ] **Step 1: Write failing money-state tests**

```php
public function testItDoesNotTurnAbsentOverheadAndProfitRulesIntoZeroAmounts(): void
{
    $scope = (new EstimateBudgetScope)->project(
        ['completeness' => ['status' => 'confirmed_scope_only']],
        3154397.72,
    );

    self::assertSame('not_calculated', $scope['overhead']['status']);
    self::assertNull($scope['overhead']['amount']);
    self::assertSame('confirmed_scope_only', $scope['claim']);
}

public function testWriterPersistsScopeMetadataWithoutChangingDirectCostTotal(): void
{
    $attributes = $this->writer->capturedAttributesFor($this->session, $this->command, $this->draftWithScope, 1000.0);

    self::assertSame(1000.0, $attributes['total_direct_costs']);
    self::assertSame('confirmed_scope_only', $attributes['metadata']['ai_scope']['completeness']['status']);
}
```

- [ ] **Step 2: Run focused tests and verify failure**

Run: `php artisan test tests/Unit/EstimateGeneration/Quality/EstimateBudgetScopeTest.php tests/Unit/EstimateGeneration/Application/Apply/LaravelGeneratedEstimateWriterTest.php`

Expected: FAIL because budget scope and scope metadata are absent.

- [ ] **Step 3: Implement truthful values**

```php
$draft['budget_scope'] = $this->budgetScope->project(
    $draft,
    $this->draftTotal->forDraft($draft),
);

'metadata' => [
    'is_ai_generated' => true,
    'generation_session_id' => $session->getKey(),
    'ai_scope' => [
        'completeness' => $draft['completeness'] ?? [],
        'budget_scope' => $draft['budget_scope'] ?? [],
        'arbiter_review' => $draft['arbiter_review'] ?? [],
    ],
],
```

`commercial_budget.amount` exists only when direct costs, overhead and profit each have status `calculated`; zero-valued model fields are not treated as calculation evidence.

- [ ] **Step 4: Run verification**

Run: `php artisan test tests/Unit/EstimateGeneration/Quality/EstimateBudgetScopeTest.php tests/Unit/EstimateGeneration/Application/Apply/LaravelGeneratedEstimateWriterTest.php`

Expected: PASS.

Run: `vendor/bin/phpstan analyse app/BusinessModules/Addons/EstimateGeneration/Services/Quality/EstimateBudgetScope.php app/BusinessModules/Addons/EstimateGeneration/Application/Apply/LaravelGeneratedEstimateWriter.php`

Expected: no errors.

- [ ] **Step 5: Commit**

```bash
git add app/BusinessModules/Addons/EstimateGeneration/Services/Quality/EstimateBudgetScope.php app/BusinessModules/Addons/EstimateGeneration/Services/Quality/DraftReadinessProjector.php app/BusinessModules/Addons/EstimateGeneration/Application/Apply/LaravelGeneratedEstimateWriter.php tests/Unit/EstimateGeneration/Quality/EstimateBudgetScopeTest.php tests/Unit/EstimateGeneration/Application/Apply/LaravelGeneratedEstimateWriterTest.php
git commit -m "feat[lk]: отделяется бюджет подтвержденного объема"
```

### Task 3: Strict shadow AI arbiter

**Files:**

- Create: `app/BusinessModules/Addons/EstimateGeneration/Quality/Arbiter/ArbiterVerdict.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Quality/Arbiter/EstimateCompletenessArbiter.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Quality/Arbiter/ArbiterVerdictValidator.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Quality/Arbiter/ShadowArbiterCoordinator.php`
- Modify: `config/estimate-generation.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/EstimateGenerationServiceProvider.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Services/Quality/DraftReadinessProjector.php`
- Test: `tests/Unit/EstimateGeneration/Quality/Arbiter/ArbiterVerdictValidatorTest.php`
- Test: `tests/Unit/EstimateGeneration/Quality/Arbiter/ShadowArbiterCoordinatorTest.php`

**Interfaces:**

- `EstimateCompletenessArbiter::review(array $context): ArbiterVerdict` is the only model boundary.
- `ArbiterVerdictValidator::validate(array $raw, array $completeness): ArbiterVerdict` accepts enum outcomes, known scope keys, existing package keys and known evidence references only.
- `ShadowArbiterCoordinator::review(array $draft): array` returns sanitized `arbiter_review` and never changes `local_estimates`.

- [ ] **Step 1: Write failing boundary tests**

```php
public function testValidatorRejectsUnknownPackageAndFallsBackToHumanReview(): void
{
    $verdict = $this->validator->validate([
        'outcome' => 'targeted_rebuild',
        'findings' => [[
            'scope_key' => 'heating', 'package_keys' => ['invented-package'],
            'evidence_refs' => ['evidence:1'], 'action' => 'rebuild', 'reason' => 'x',
        ]],
    ], $this->completeness);

    self::assertSame('human_review', $verdict->outcome);
    self::assertSame('invalid_reference', $verdict->findings[0]['reason_code']);
}

public function testShadowCoordinatorPreservesEveryExistingWorkItem(): void
{
    $reviewed = $this->coordinator->review($this->draft);

    self::assertSame($this->draft['local_estimates'], $reviewed['local_estimates']);
    self::assertSame('shadow', $reviewed['arbiter_review']['mode']);
}
```

- [ ] **Step 2: Run focused tests and verify failure**

Run: `php artisan test tests/Unit/EstimateGeneration/Quality/Arbiter/ArbiterVerdictValidatorTest.php tests/Unit/EstimateGeneration/Quality/Arbiter/ShadowArbiterCoordinatorTest.php`

Expected: FAIL because arbiter types do not exist.

- [ ] **Step 3: Implement schema-first types and safe configuration**

```php
interface EstimateCompletenessArbiter
{
    public function review(array $context): ArbiterVerdict;
}

return [
    'completeness_arbiter' => [
        'mode' => env('ESTIMATE_COMPLETENESS_ARBITER_MODE', 'shadow'),
        'model' => env('ESTIMATE_COMPLETENESS_ARBITER_MODEL', ''),
        'prompt_version' => 'completeness-arbiter:v1',
        'max_input_tokens' => 24000,
        'max_output_tokens' => 2000,
    ],
];
```

The adapter receives only sanitized structured context: description hash, scope records, evidence IDs, package keys, direct cost and budget state. It records model identifier, prompt version, input hash and measured tokens through existing AI usage/audit services. An unconfigured model, timeout, invalid JSON, token-limit breach or unknown reference gives `human_review` and never retries.

- [ ] **Step 4: Persist shadow result only after deterministic projections**

```php
$draft = $this->readinessProjector->project($draft);
$draft['arbiter_review'] = $this->arbiterCoordinator->review($draft)['arbiter_review'];
```

Do not merge arbiter findings into `problem_flags`, `quality_summary.status`, readiness blockers or `can_apply` in shadow mode.

- [ ] **Step 5: Run verification**

Run: `php artisan test tests/Unit/EstimateGeneration/Quality/Arbiter/ArbiterVerdictValidatorTest.php tests/Unit/EstimateGeneration/Quality/Arbiter/ShadowArbiterCoordinatorTest.php tests/Unit/EstimateGeneration/Observability/AiUsagePrivacyContractTest.php`

Expected: PASS.

Run: `vendor/bin/phpstan analyse app/BusinessModules/Addons/EstimateGeneration/Quality/Arbiter app/BusinessModules/Addons/EstimateGeneration/Services/Quality/DraftReadinessProjector.php`

Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add config/estimate-generation.php app/BusinessModules/Addons/EstimateGeneration/Quality/Arbiter app/BusinessModules/Addons/EstimateGeneration/EstimateGenerationServiceProvider.php app/BusinessModules/Addons/EstimateGeneration/Services/Quality/DraftReadinessProjector.php tests/Unit/EstimateGeneration/Quality/Arbiter
git commit -m "feat[lk]: добавлен теневой арбитр AI-сметы"
```

### Task 4: One-cycle targeted remediation protocol

**Files:**

- Create: `app/BusinessModules/Addons/EstimateGeneration/Quality/Arbiter/ArbiterRemediationCoordinator.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Quality/Arbiter/ArbiterReviewCycle.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Services/EstimateGenerationAuditService.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Pipeline/PublishValidatedDraft.php`
- Test: `tests/Unit/EstimateGeneration/Quality/Arbiter/ArbiterRemediationCoordinatorTest.php`
- Test: `tests/Unit/EstimateGeneration/Pipeline/PublishValidatedDraftTest.php`

**Interfaces:**

- Consumes a valid `targeted_rebuild` verdict, `draft['input_version']` and existing package keys.
- Produces `arbiter_review.cycle` containing `input_hash`, `attempted`, `target_package_keys` and terminal outcome.
- Calls `TargetedPackageRebuilder::rebuild(array $draft, array $packageKeys): array` only for packages present in `local_estimates` and only once per input hash.

- [ ] **Step 1: Write failing idempotency and evidence tests**

```php
public function testItRebuildsValidatedTargetsOnceForInputHash(): void
{
    $first = $this->coordinator->remediate($this->draft, $this->targetedVerdict);
    $second = $this->coordinator->remediate($first, $this->targetedVerdict);

    self::assertSame(['heating'], $this->rebuilder->receivedPackageKeys());
    self::assertSame(1, $this->rebuilder->calls());
    self::assertSame('human_review', $second['arbiter_review']['outcome']);
}

public function testItRoutesMissingEvidenceToHumanReviewWithoutRebuild(): void
{
    $result = $this->coordinator->remediate($this->draft, $this->verdictWithoutEvidence);

    self::assertSame(0, $this->rebuilder->calls());
    self::assertSame('human_review', $result['arbiter_review']['outcome']);
}
```

- [ ] **Step 2: Run the focused test and verify failure**

Run: `php artisan test tests/Unit/EstimateGeneration/Quality/Arbiter/ArbiterRemediationCoordinatorTest.php`

Expected: FAIL because remediation types do not exist.

- [ ] **Step 3: Implement the one-cycle state machine**

```php
if ($verdict->outcome !== 'targeted_rebuild' || ! $this->hasKnownEvidence($verdict, $draft)) {
    return $this->humanReview($draft, 'evidence_required');
}

$hash = hash('sha256', json_encode($this->inputVersion($draft), JSON_THROW_ON_ERROR));
if (($draft['arbiter_review']['cycle']['input_hash'] ?? null) === $hash) {
    return $this->humanReview($draft, 'cycle_exhausted');
}

$rebuilt = $this->rebuilder->rebuild($draft, $verdict->packageKeys());
return $this->repeatArbitration($rebuilt, $hash);
```

The rebuilder adapter is the existing package-generation revision path, never the whole-draft generation entry point. `PublishValidatedDraft` invokes it before finalized-draft recording; exceptions leave the preceding valid checkpoint unchanged and emit sanitized audit data.

- [ ] **Step 4: Run verification**

Run: `php artisan test tests/Unit/EstimateGeneration/Quality/Arbiter/ArbiterRemediationCoordinatorTest.php tests/Unit/EstimateGeneration/Pipeline/PublishValidatedDraftTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/BusinessModules/Addons/EstimateGeneration/Quality/Arbiter app/BusinessModules/Addons/EstimateGeneration/Services/EstimateGenerationAuditService.php app/BusinessModules/Addons/EstimateGeneration/Pipeline/PublishValidatedDraft.php tests/Unit/EstimateGeneration/Quality/Arbiter tests/Unit/EstimateGeneration/Pipeline/PublishValidatedDraftTest.php
git commit -m "feat[lk]: ограничена доработка пакетов AI-сметы"
```

### Task 5: Snapshot contract and transparent AI summary

**Files:**

- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Sessions/SessionSnapshotData.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Sessions/BuildSessionSnapshot.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Sessions/BuildSessionOperationalSnapshot.php`
- Modify: `prohelper_admin/src/features/estimate-generation/api/estimateGenerationContracts.ts`
- Modify: `prohelper_admin/src/features/estimate-generation/api/estimateGenerationApi.ts`
- Modify: `prohelper_admin/src/features/estimate-generation/steps/SummaryStep.tsx`
- Create: `prohelper_admin/src/features/estimate-generation/shared/CompletenessBoundaryPanel.tsx`
- Modify: `prohelper_admin/src/features/estimate-generation/shared/ReadinessPanel.tsx`
- Test: `tests/Unit/EstimateGeneration/Workflow/BuildSessionSnapshotTest.php`
- Test: `tests/Unit/EstimateGeneration/Workflow/SessionOperationalSnapshotDataTest.php`
- Test: `prohelper_admin/src/features/estimate-generation/shared/CompletenessBoundaryPanel.test.tsx`

**Interfaces:**

- Snapshot fields are `completeness`, `budget_scope`, `arbiter_review`, each defaulting to `[]` for old sessions.
- Client maps them to `SessionSnapshot.completeness`, `.budgetScope`, `.arbiterReview`.
- The panel reads typed data only; it does not calculate coverage or money.

- [ ] **Step 1: Write failing backend and frontend contract tests**

```php
public function testSnapshotExposesScopeBoundaryWithoutChangingCanApplyInShadowMode(): void
{
    $snapshot = $this->builder->handle($this->sessionWithShadowReview(), ['estimate_generation.apply']);

    self::assertSame('confirmed_scope_only', $snapshot->toArray()['completeness']['status']);
    self::assertTrue($snapshot->toArray()['can_apply']);
}
```

```tsx
it('labels a partial result as confirmed scope rather than full budget', () => {
  render(<CompletenessBoundaryPanel completeness={partial} budgetScope={budget} arbiterReview={shadow} />);

  expect(screen.getByText('Смета подтвержденного объема')).toBeVisible();
  expect(screen.getByText('НР и сметная прибыль не рассчитаны')).toBeVisible();
});
```

- [ ] **Step 2: Run focused files and verify failure**

Run: `php artisan test tests/Unit/EstimateGeneration/Workflow/BuildSessionSnapshotTest.php tests/Unit/EstimateGeneration/Workflow/SessionOperationalSnapshotDataTest.php`

Expected: FAIL because snapshot fields are absent.

Run: `npx vitest run src/features/estimate-generation/shared/CompletenessBoundaryPanel.test.tsx`

Expected: FAIL because the component does not exist.

- [ ] **Step 3: Add transport-only fields and UI**

```php
public array $completeness = [],
public array $budgetScope = [],
public array $arbiterReview = [],

'completeness' => $this->completeness,
'budget_scope' => $this->budgetScope,
'arbiter_review' => $this->arbiterReview,
```

`BuildSessionOperationalSnapshot` selects only these JSON subobjects from `draft_payload`; it must not load raw prompts or document bodies. The panel lists included sections, exclusions with their grounds, unresolved sections, direct costs and each uncalculated budget part. It uses no technical keys in visible copy.

- [ ] **Step 4: Run verification**

Run: `php artisan test tests/Unit/EstimateGeneration/Workflow/BuildSessionSnapshotTest.php tests/Unit/EstimateGeneration/Workflow/SessionOperationalSnapshotDataTest.php`

Expected: PASS.

Run: `npx vitest run src/features/estimate-generation/steps/SummaryStep.test.tsx src/features/estimate-generation/shared/CompletenessBoundaryPanel.test.tsx`

Expected: PASS.

Run: `npx tsc --noEmit`

Expected: no TypeScript errors.

Run: `npx eslint src/features/estimate-generation/api/estimateGenerationContracts.ts src/features/estimate-generation/api/estimateGenerationApi.ts src/features/estimate-generation/steps/SummaryStep.tsx src/features/estimate-generation/shared/CompletenessBoundaryPanel.tsx src/features/estimate-generation/shared/ReadinessPanel.tsx`

Expected: no lint errors.

- [ ] **Step 5: Commit**

```bash
git add app/BusinessModules/Addons/EstimateGeneration/Application/Sessions prohelper_admin/src/features/estimate-generation tests/Unit/EstimateGeneration/Workflow
git commit -m "feat[lk]: показана граница комплектности AI-сметы"
```

### Task 6: Ordinary-estimate notice and enforcement readiness

**Files:**

- Modify: `prohelper_admin/src/types/estimate.ts`
- Modify: `prohelper_admin/src/pages/Estimates/EstimateDetailPage.tsx`
- Create: `prohelper_admin/src/pages/Estimates/components/AiScopeBoundaryAlert.tsx`
- Test: `prohelper_admin/src/pages/Estimates/components/AiScopeBoundaryAlert.test.tsx`
- Test: `tests/Architecture/EstimateGenerationOrdinaryEstimateBoundaryTest.php`

**Interfaces:**

- Consumes only existing normal-estimate `metadata.ai_scope`, written by `LaravelGeneratedEstimateWriter`.
- Displays a persistent notice for `confirmed_scope_only` or `review_required`; ordinary estimates without this metadata render no notice.

- [ ] **Step 1: Write failing safety and UI tests**

```php
public function testGeneratedOrdinaryEstimateStoresScopeMetadataButNoArbiterMutationPath(): void
{
    $writer = file_get_contents(app_path('BusinessModules/Addons/EstimateGeneration/Application/Apply/LaravelGeneratedEstimateWriter.php'));

    self::assertStringContainsString("'ai_scope' => [", $writer);
    self::assertStringNotContainsString('updateOrCreate(', $writer);
}
```

```tsx
it('does not call a confirmed-scope estimate a full budget', () => {
  render(<AiScopeBoundaryAlert aiScope={partialScope} />);

  expect(screen.getByText('Смета подтвержденного объема')).toBeVisible();
  expect(screen.queryByText('Полный бюджет объекта')).not.toBeInTheDocument();
});
```

- [ ] **Step 2: Run focused files and verify failure**

Run: `php artisan test tests/Architecture/EstimateGenerationOrdinaryEstimateBoundaryTest.php`

Expected: FAIL until the new metadata contract is asserted.

Run: `npx vitest run src/pages/Estimates/components/AiScopeBoundaryAlert.test.tsx`

Expected: FAIL because the alert component does not exist.

- [ ] **Step 3: Render persisted notice without changing estimate actions**

```tsx
if (!aiScope || aiScope.completeness?.status === 'full_confirmed_scope') return null;

return (
  <Alert severity={aiScope.completeness?.status === 'review_required' ? 'warning' : 'info'}>
    <AlertTitle>Смета подтвержденного объема</AlertTitle>
    В итог включены только подтвержденные работы. НР и сметная прибыль отображаются отдельно.
  </Alert>
);
```

Do not add estimate-modifying buttons, alter totals, or touch `EstimateResource` and contract-coverage code.

- [ ] **Step 4: Run final targeted verification**

Run: `php artisan test tests/Architecture/EstimateGenerationOrdinaryEstimateBoundaryTest.php tests/Unit/EstimateGeneration/Quality/EstimateCompletenessProfileTest.php tests/Unit/EstimateGeneration/Quality/EstimateBudgetScopeTest.php tests/Unit/EstimateGeneration/Quality/Arbiter/ArbiterVerdictValidatorTest.php`

Expected: PASS.

Run: `npx vitest run src/pages/Estimates/components/AiScopeBoundaryAlert.test.tsx src/features/estimate-generation/steps/SummaryStep.test.tsx`

Expected: PASS.

Run: `npx tsc --noEmit`

Expected: no TypeScript errors.

- [ ] **Step 5: Commit**

```bash
git add prohelper_admin/src/types/estimate.ts prohelper_admin/src/pages/Estimates/EstimateDetailPage.tsx prohelper_admin/src/pages/Estimates/components/AiScopeBoundaryAlert.tsx prohelper_admin/src/pages/Estimates/components/AiScopeBoundaryAlert.test.tsx tests/Architecture/EstimateGenerationOrdinaryEstimateBoundaryTest.php
git commit -m "feat[lk]: отмечается неполная AI-смета"
```

## Self-review

**Spec coverage:** Task 1 covers requested scope, subnodes, explicit exclusions and evidence-bounded roofing. Task 2 separates direct costs from uncalculated НР/СП and stores the boundary in ordinary estimates. Task 3 makes the separate model/prompt and token-bounded AI arbiter active in shadow mode and validates every reference. Task 4 establishes the single targeted rebuild cycle and direct human review for missing evidence. Task 5 makes scope and money state transparent in the AI flow. Task 6 carries the same warning to the ordinary estimate without entering the contract subsystem.

**Placeholder scan:** Every task names exact files, interfaces, test code, commands and expected results. No task delegates an unspecified implementation or verification decision.

**Type consistency:** The names `completeness`, `budget_scope` and `arbiter_review` are written into the draft, transported unchanged by the PHP snapshot, then mapped to `completeness`, `budgetScope` and `arbiterReview` in TypeScript. Remediation accepts only `ArbiterVerdict` package keys already validated against `completeness`.

## Execution choice

The user explicitly requested autonomous work without further questions. Execute inline using `superpowers:executing-plans`, reviewing and committing each independently testable task.
