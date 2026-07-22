# Evidence-bound AI Estimate Completion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Подготовить AI-смету МОСТ к безопасному конечному состоянию: она содержит только доказанные позиции и ясно показывает невключённые части без возможности выдать результат за полный бюджет.

**Architecture:** Генератор перестаёт создавать платные позиции с искусственным количеством. Непокрытые объёмы и отсутствующие закреплённые нормативы превращаются в структурированные предупреждения состава пакета; вычисление готовности использует их как честную границу, а не как скрытую ручную очередь. Нормативный контекст закрепляет кандидатов по `work_item_key`, а интерфейс показывает пользователю названия компонентов, причину и нужный тип исходных данных.

**Tech Stack:** PHP 8.2, Laravel 11, PHPUnit; React, TypeScript, Vitest, MUI.

## Global Constraints

- Не создавать смету вручную, не менять сессию №58 и смету №414.
- Не добавлять объёмы, нормы, материалы или ресурсы без доказательства из входных данных.
- Не запускать миграции, локальные команды БД, frontend build или dev-server.
- Сохранять полный ресурсный состав нормы; нормы без полного ресурсно-ценового покрытия не допускаются.
- Сессия с `confirmed_scope_only` не должна быть применима как полный бюджет.
- Не трогать договоры и связанную с ними логику.
- Каждый коммит имеет русское Conventional-сообщение с областью `[lk]`.

---

## File Structure

- `app/BusinessModules/Addons/EstimateGeneration/Services/NormativeWorkItemPlannerService.php` — прекращает выпуск фиктивных плановых позиций без подтверждённого количества.
- `app/BusinessModules/Addons/EstimateGeneration/Planning/WorkPlanCompiler.php` — передаёт стабильный ключ плановой позиции в нормативное намерение.
- `app/BusinessModules/Addons/EstimateGeneration/Normatives/Services/NormativeContextPinData.php` — сохраняет отображение работы на допустимые закреплённые идентификаторы кандидатов.
- `app/BusinessModules/Addons/EstimateGeneration/Normatives/Services/EloquentNormativeContextPinSource.php` — строит это отображение только из кандидатов с полными ресурсами и ценами.
- `app/BusinessModules/Addons/EstimateGeneration/Normatives/Services/PinnedNormativeCandidateFactory.php` — берёт кандидаты только из отображения конкретной работы.
- `app/BusinessModules/Addons/EstimateGeneration/Pipeline/Stages/MatchNormativesStage.php` — переводит отсутствие закреплённой нормы в предупреждение границы, а не в неразрешимую строку очереди.
- `app/BusinessModules/Addons/EstimateGeneration/Services/Quality/EstimateCompletenessProfile.php` и `EstimateScopeMetadataProjector.php` — выдают в API структурированные пробелы комплектации.
- `src/types/estimateScope.ts`, `src/features/estimate-generation/api/estimateGenerationNormalizers.ts`, `src/components/estimates/EstimateScopeBoundaryPanel.tsx` — типизируют и отображают понятную пользователю границу.

### Task 1: Исключить искусственные количества из планировщика

**Files:**
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Services/NormativeWorkItemPlannerService.php:93-186`
- Modify: `tests/Unit/EstimateGeneration/NormativeWorkItemPlannerDensityTest.php`

**Interfaces:**
- Consumes: `quantityForDefinition(): array{source: string, source_refs: list<array>}`.
- Produces: `build(): list<array<string,mixed>>`, содержащий только позиции с подтверждённым или явно разрешённым сценарным количеством.

- [ ] **Step 1: Написать падающий регрессионный тест**

```php
public function test_planner_does_not_create_priced_item_from_empty_planner_fallback(): void
{
    $items = $this->pricedItems($this->planner()->build(
        $local = $this->localEstimate('ventilation', 'Вентиляция', 'engineering', 12),
        $local['sections'][0],
        ['document_context' => []],
    ));

    self::assertNotContains('planner_fallback', array_map(
        static fn (array $item): string => (string) ($item['metadata']['quantity_source'] ?? ''),
        $items,
    ));
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/EstimateGeneration/NormativeWorkItemPlannerDensityTest.php --filter=empty_planner_fallback`

Expected: FAIL, because a work item has `metadata.quantity_source = planner_fallback`.

- [ ] **Step 3: Write minimal implementation**

In `workItemFromDefinition()` replace the whole `isPlannerFallbackQuantity()` branch with:

```php
if ($this->isPlannerFallbackQuantity($quantity)) {
    return null;
}
```

Remove `shouldExposePlannerFallback()`. Keep `quantity_review` unchanged: it is valid only for a real source with explicit confirmation required.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/EstimateGeneration/NormativeWorkItemPlannerDensityTest.php`

Expected: PASS; no priced work has empty `source_refs` and `planner_fallback`.

- [ ] **Step 5: Commit**

```bash
git add app/BusinessModules/Addons/EstimateGeneration/Services/NormativeWorkItemPlannerService.php tests/Unit/EstimateGeneration/NormativeWorkItemPlannerDensityTest.php
git commit -m "fix[lk]: исключены фиктивные объёмы AI-сметы"
```

### Task 2: Закрепить нормативных кандидатов за плановой работой

**Files:**
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Planning/WorkPlanCompiler.php:190-260`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Normatives/Services/NormativeContextPinData.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Normatives/Services/EloquentNormativeContextPinSource.php:75-871`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Normatives/Services/PinnedNormativeCandidateFactory.php`
- Test: `tests/Unit/EstimateGeneration/Planning/WorkPlanCompilerTest.php`
- Test: `tests/Unit/EstimateGeneration/Normatives/PinnedNormativeCandidateFactoryTest.php`

**Interfaces:**
- Consumes: нормативное намерение с `work_item_key: string`.
- Produces: `NormativeContextPinData::toArray()['candidate_ids_by_work_item']` вида `array<string, list<string>>`.
- Invariant: каждый идентификатор в отображении входит в `catalog_candidates`; сопоставление никогда не расширяет этот набор.

- [ ] **Step 1: Write failing tests**

```php
self::assertSame('roof-norm-intent-1', $capturedIntents[0]['work_item_key']);

$selected = (new PinnedNormativeCandidateFactory)->forWorkItem(
    $candidates,
    ['key' => 'roof-norm-intent-1', 'name' => 'Монтаж покрытия', 'unit' => 'м2'],
    ['12'],
    null,
    ['roof-norm-intent-1' => ['roof-rate']],
);
self::assertSame(['roof-rate'], array_map(static fn ($item): string => $item->id, $selected));
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Unit/EstimateGeneration/Planning/WorkPlanCompilerTest.php tests/Unit/EstimateGeneration/Normatives/PinnedNormativeCandidateFactoryTest.php`

Expected: FAIL, because the intent has no `work_item_key` and the factory accepts only a global catalog.

- [ ] **Step 3: Add the key and validated pin contract**

In `WorkPlanCompiler::normativeIntents()` add:

```php
'work_item_key' => (string) ($item['key'] ?? ''),
```

Skip work with an empty key. In `NormativeContextPinData` add a final constructor argument and `toArray()` field:

```php
public array $candidateIdsByWorkItem = [],

'candidate_ids_by_work_item' => $this->candidateIdsByWorkItem,
```

Validate each map key with `/^[A-Za-z0-9:._-]{1,120}$/D`; each value is a list of no more than eight candidate ids; the full map has at most 128 links.

- [ ] **Step 4: Build mapping from final resource-complete candidates**

In `EloquentNormativeContextPinSource`, track `$rankedNormIdsByWorkItem[$workItemKey]` rather than index-only data. After `$admittedNormIds` is final, form `candidateIdsByWorkItem` by retaining only candidates admitted for that key. Include the map in the canonical hash and in `new NormativeContextPinData(...)`. Keep an empty list for a key without a complete candidate; do not reject the entire pin because another work is not covered.

- [ ] **Step 5: Select candidates from the work-specific map**

Extend the factory signature:

```php
public function forWorkItem(
    array $catalogCandidates,
    array $workItem,
    array $normativeSections = [],
    ?WorkIntentData $canonicalIntent = null,
    array $candidateIdsByWorkItem = [],
): array
```

Read `$allowedIds = $candidateIdsByWorkItem[(string) ($workItem['key'] ?? '')] ?? null`. When it is a list, build `$rankable` only from it. Use the old global behavior only for historical pins that do not have the map.

- [ ] **Step 6: Run tests and static analysis**

Run: `php artisan test tests/Unit/EstimateGeneration/Planning/WorkPlanCompilerTest.php tests/Unit/EstimateGeneration/Normatives/PinnedNormativeCandidateFactoryTest.php`

Expected: PASS.

Run: `vendor/bin/phpstan analyse app/BusinessModules/Addons/EstimateGeneration/Planning/WorkPlanCompiler.php app/BusinessModules/Addons/EstimateGeneration/Normatives/Services/NormativeContextPinData.php app/BusinessModules/Addons/EstimateGeneration/Normatives/Services/EloquentNormativeContextPinSource.php app/BusinessModules/Addons/EstimateGeneration/Normatives/Services/PinnedNormativeCandidateFactory.php`

Expected: exit code 0.

- [ ] **Step 7: Commit**

```bash
git add app/BusinessModules/Addons/EstimateGeneration/Planning/WorkPlanCompiler.php app/BusinessModules/Addons/EstimateGeneration/Normatives/Services/NormativeContextPinData.php app/BusinessModules/Addons/EstimateGeneration/Normatives/Services/EloquentNormativeContextPinSource.php app/BusinessModules/Addons/EstimateGeneration/Normatives/Services/PinnedNormativeCandidateFactory.php tests/Unit/EstimateGeneration/Planning/WorkPlanCompilerTest.php tests/Unit/EstimateGeneration/Normatives/PinnedNormativeCandidateFactoryTest.php
git commit -m "fix[lk]: закреплены нормы за работами AI-сметы"
```

### Task 3: Перевести отсутствие нормы в границу комплектации

**Files:**
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Pipeline/Stages/MatchNormativesStage.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Quantities/QuantityCoverageWarning.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Services/Quality/EstimateCompletenessProfile.php`
- Test: `tests/Unit/EstimateGeneration/Quality/EstimateCompletenessProfileTest.php`
- Test: `tests/Unit/EstimateGeneration/Quantities/QuantityCoverageWarningTest.php`

**Interfaces:**
- Produces: `coverage_warnings` entry `['quantity_key' => string, 'reason' => 'normative_candidate_missing', 'package_key' => string]`.
- Invariant: a work without a pinned candidate is removed from `work_items`, never contributes cost, and never becomes a manual review row.

- [ ] **Step 1: Write failing test**

```php
$draft['local_estimates'][0]['coverage_warnings'] = [[
    'quantity_key' => 'roof.covering',
    'reason' => 'normative_candidate_missing',
    'package_key' => 'roof',
]];
$result = (new EstimateCompletenessProfile)->project($draft);

self::assertSame('confirmed_scope_only', $result['status']);
self::assertSame('normative_candidate_missing', $result['scopes']['roof']['gaps'][0]['reason']);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/EstimateGeneration/Quality/EstimateCompletenessProfileTest.php --filter=normative_candidate_missing`

Expected: FAIL, because `gaps` and the reason are not in the current contract.

- [ ] **Step 3: Write minimal implementation**

Add `normative_candidate_missing` to `QuantityCoverageWarning::REASONS`. In `MatchNormativesStage`, when dataset and version are pinned but the work-specific candidate list is empty, append a de-duplicated warning to that local estimate and remove the work item. Keep missing dataset/version as `review_required`.

```php
$this->appendScopeGap(
    $data['local_estimates'][$localIndex],
    (string) ($workItem['quantity_formula'] ?? $workItem['key'] ?? ''),
    'normative_candidate_missing',
);
unset($data['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'][$itemIndex]);
```

Normalize each section using `array_values()`. In `EstimateCompletenessProfile`, add `gaps` to every scope: use matching valid warnings first; add one `{work_key, reason: 'document_takeoff_missing'}` for every absent required key that has no warning. A covered key must have a priced work item with calculated pricing status.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Unit/EstimateGeneration/Quality/EstimateCompletenessProfileTest.php tests/Unit/EstimateGeneration/Quantities/QuantityCoverageWarningTest.php`

Expected: PASS; a missing roof or ventilation component remains in the scope boundary, not in the priced queue.

- [ ] **Step 5: Commit**

```bash
git add app/BusinessModules/Addons/EstimateGeneration/Pipeline/Stages/MatchNormativesStage.php app/BusinessModules/Addons/EstimateGeneration/Quantities/QuantityCoverageWarning.php app/BusinessModules/Addons/EstimateGeneration/Services/Quality/EstimateCompletenessProfile.php tests/Unit/EstimateGeneration/Quality/EstimateCompletenessProfileTest.php tests/Unit/EstimateGeneration/Quantities/QuantityCoverageWarningTest.php
git commit -m "fix[lk]: выводятся границы непокрытых разделов"
```

### Task 4: Зафиксировать безопасный API-снимок

**Files:**
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Services/Quality/EstimateScopeMetadataProjector.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Services/Quality/DraftReadinessProjector.php`
- Test: `tests/Unit/EstimateGeneration/Quality/DraftReadinessProjectorTest.php`
- Test: `tests/Unit/EstimateGeneration/GeneratedEstimateScopeMetadataTest.php`

**Interfaces:**
- Produces: `scope_summary.completeness.scopes[*].gaps: list<{work_key:string,reason:string}>`.
- Invariant: `confirmed_scope_only` exposes direct costs only and cannot turn into a commercial budget.

- [ ] **Step 1: Write failing contract test**

```php
$metadata = (new EstimateScopeMetadataProjector)->project($draft, $draft['budget_scope']);
self::assertSame([
    ['work_key' => 'ventilation.air_exchange', 'reason' => 'ventilation_duct_takeoff_missing'],
], $metadata['completeness']['scopes'][0]['gaps']);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/EstimateGeneration/GeneratedEstimateScopeMetadataTest.php --filter=gaps`

Expected: FAIL, because the snapshot has only raw `missing_items`.

- [ ] **Step 3: Write minimal implementation**

Add `'gaps' => $this->gaps($scope['gaps'] ?? [])` to the returned scope. `gaps()` accepts at most 100 records, retains only valid `work_key` and `reason` strings through `isReference()`, removes duplicates, then sorts by work key and reason. In `DraftReadinessProjector`, preserve `not_calculated` overhead, profit, and commercial budget while completeness equals `confirmed_scope_only`.

- [ ] **Step 4: Run tests and static analysis**

Run: `php artisan test tests/Unit/EstimateGeneration/Quality/DraftReadinessProjectorTest.php tests/Unit/EstimateGeneration/GeneratedEstimateScopeMetadataTest.php`

Expected: PASS.

Run: `vendor/bin/phpstan analyse app/BusinessModules/Addons/EstimateGeneration/Services/Quality/EstimateScopeMetadataProjector.php app/BusinessModules/Addons/EstimateGeneration/Services/Quality/DraftReadinessProjector.php`

Expected: exit code 0.

- [ ] **Step 5: Commit**

```bash
git add app/BusinessModules/Addons/EstimateGeneration/Services/Quality/EstimateScopeMetadataProjector.php app/BusinessModules/Addons/EstimateGeneration/Services/Quality/DraftReadinessProjector.php tests/Unit/EstimateGeneration/Quality/DraftReadinessProjectorTest.php tests/Unit/EstimateGeneration/GeneratedEstimateScopeMetadataTest.php
git commit -m "feat[lk]: раскрыты исключения из стоимости AI-сметы"
```

### Task 5: Показать границу состава понятным языком в админке

**Files:**
- Modify: `src/types/estimateScope.ts`
- Modify: `src/features/estimate-generation/api/estimateGenerationNormalizers.ts`
- Modify: `src/features/estimate-generation/api/estimateGenerationNormalizers.test.ts`
- Modify: `src/components/estimates/EstimateScopeBoundaryPanel.tsx`
- Modify: `src/components/estimates/EstimateScopeBoundaryPanel.test.tsx`

**Interfaces:**
- Consumes: `EstimateScopeSection.gaps: Array<{work_key: string; reason: string}>`.
- Produces: «Не включено в стоимость» with business labels and source guidance, never technical keys.

- [ ] **Step 1: Write failing tests**

```ts
expect(snapshot.scopeSummary?.completeness.scopes[0]?.gaps).toEqual([
  { work_key: 'ventilation.air_exchange', reason: 'ventilation_duct_takeoff_missing' },
]);
```

```tsx
expect(screen.getByText('Не включено в стоимость')).toBeInTheDocument();
expect(screen.getByText('Вентиляция: воздухообмен')).toBeInTheDocument();
expect(screen.getByText('Нужен план вентиляции или ведомость трасс и оборудования.')).toBeInTheDocument();
expect(screen.queryByText('ventilation.air_exchange')).not.toBeInTheDocument();
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `npx vitest run src/features/estimate-generation/api/estimateGenerationNormalizers.test.ts src/components/estimates/EstimateScopeBoundaryPanel.test.tsx`

Expected: FAIL, because `gaps` is absent from the type and normalizer.

- [ ] **Step 3: Extend the type and strict normalizer**

```ts
export interface EstimateScopeGap {
  work_key: string;
  reason: string;
}
```

Add `gaps: EstimateScopeGap[]` to `EstimateScopeSection`. Require `item.gaps` as an array in `scopeSummary()` and normalize every record through `record()` and `string()`; invalid field values throw `EstimateGenerationContractError` with the exact field path.

- [ ] **Step 4: Add user-facing boundary copy**

Create local `WORK_LABELS` and `GAP_GUIDANCE` in `EstimateScopeBoundaryPanel.tsx`. Map ventilation, roof composition, and normative candidate reasons to Russian wording; all other known or unknown reasons render «Нужен план или ведомость объёмов по этому разделу.». For `confirmed_scope_only`, title is «Смета готова в подтверждённой границе» and copy is «Добавьте подтверждающие данные, чтобы подготовить полный бюджет.».

- [ ] **Step 5: Run test and typecheck**

Run: `npx vitest run src/features/estimate-generation/api/estimateGenerationNormalizers.test.ts src/components/estimates/EstimateScopeBoundaryPanel.test.tsx`

Expected: PASS.

Run: `npx tsc --noEmit`

Expected: exit code 0.

- [ ] **Step 6: Commit**

```bash
git add src/types/estimateScope.ts src/features/estimate-generation/api/estimateGenerationNormalizers.ts src/features/estimate-generation/api/estimateGenerationNormalizers.test.ts src/components/estimates/EstimateScopeBoundaryPanel.tsx src/components/estimates/EstimateScopeBoundaryPanel.test.tsx
git commit -m "feat[lk]: показана граница подтверждённой AI-сметы"
```

### Task 6: Сквозная проверка и безопасная пересборка сессии №59

- [ ] **Step 1: Run final targeted backend tests**

Run: `php artisan test tests/Unit/EstimateGeneration/NormativeWorkItemPlannerDensityTest.php tests/Unit/EstimateGeneration/Planning/WorkPlanCompilerTest.php tests/Unit/EstimateGeneration/Normatives/PinnedNormativeCandidateFactoryTest.php tests/Unit/EstimateGeneration/Quality/EstimateCompletenessProfileTest.php tests/Unit/EstimateGeneration/Quality/DraftReadinessProjectorTest.php tests/Unit/EstimateGeneration/GeneratedEstimateScopeMetadataTest.php`

Expected: PASS.

- [ ] **Step 2: Check patch boundaries**

Run: `git diff --check HEAD~5..HEAD && git status --short`

Expected: no `git diff --check` output; no unrelated files were staged or changed by this work.

- [ ] **Step 3: Verify the regenerated session through the admin UI after normal deployment**

Check only via UI: session №58 and estimate №414 remain unchanged; no ordinary estimate was created manually; ventilation has no line with quantity `1` without a source; roof covering is either priced by a pinned norm or shown in «Не включено в стоимость»; incomplete systems never receive a full-budget claim; application action remains unavailable.

## Self-review

- Task 1 excludes fabricated quantities; Task 2 makes candidate selection reproducible; Task 3 makes a missing candidate an explicit boundary; Task 4 keeps the API safe; Task 5 gives transparent UX; Task 6 verifies session №59 without applying it.
- The shared names are `work_item_key`, `candidate_ids_by_work_item`, `normative_candidate_missing`, `gaps`, and `confirmed_scope_only`.
- The plan does not contain migrations, database commands, frontend build, contract changes, or contract-module files.
