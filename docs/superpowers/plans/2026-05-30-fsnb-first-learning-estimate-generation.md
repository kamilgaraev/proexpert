# FSNB-First Learning Estimate Generation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a production-grade FSNB-first estimate generation system that selects, prices, explains, learns from imported/user-corrected estimates, and blocks dangerous normative matches before they can distort a draft.

**Architecture:** Generation stays deterministic around FSNB data: extraction and LLMs can propose intent and ranking, but only backend gates decide whether a norm may price a line. The system adds a learning layer from imported estimates and user corrections, indexes these examples into the existing RAG stack, and uses them as evidence for candidate retrieval/reranking without bypassing unit, scope, price, and anomaly checks.

**Tech Stack:** Laravel 11, PHP 8.2, PostgreSQL/jsonb/pgvector, existing EstimateGeneration module, existing AIAssistant RAG services, existing BudgetEstimates import data, PHPUnit, Larastan/PHPStan, React/Vite admin UI where UI tasks are explicitly listed.

---

## Progress

- [x] Task 1: Safety Regression Harness
- [x] Task 2: Unit Compatibility And Conversion Model
- [x] Task 3: Work Intent Classifier And Scope Rules
- [x] Task 4: Safe Candidate Search Before Scoring
- [x] Task 5: Hard Gate Decision Service
- [x] Task 6: Resource Assembly And Pricing Cannot Use Unsafe Candidates
- [x] Task 7: Line-Level Outlier Guard
- [x] Task 8: OCR Area Fact Ranking
- [x] Task 9: Learning Examples Schema
- [x] Task 10: Extract Learning Pairs From Imported Estimates
- [x] Task 11: Record User Normative Corrections
- [x] Task 12: RAG Source For Learning Examples
- [x] Task 13: Learning Evidence Retrieval For Normative Matching
- [x] Task 14: Normative Candidate Reranker
- [ ] Task 15: Generation Flow And Progress UX Contract
- [ ] Task 16: Admin UI For Progress, Evidence, And Review
- [ ] Task 17: End-To-End Backend Quality Suite
- [ ] Task 18: Production Diagnostic Readiness

## Operating Rules

- Work directly in `prohelper` on `main`, as requested.
- Do not run migrations locally or on production. Create migration files and validate syntax only.
- Do not run `npm run build`, `npm run dev`, or `artisan serve`.
- Preserve existing dirty files in `app/BusinessModules/Features/AIAssistant`; inspect and merge with them instead of reverting.
- Commit after each task with Russian Conventional Commit format and `[lk]` scope.
- FSNB is the source of truth for generated estimate pricing. Market/free-text price heuristics can only be visible as reference metadata, never as the price source for a saved generated line.
- A norm with incompatible unit dimension cannot price a line, even if it has resources and high text score.
- A low-confidence norm may be shown and preselected for review only after hard gates pass. It must carry a review flag and explanation.
- Every new user-facing backend message goes through `trans_message('estimate_generation.*')`.

## Current Failure To Regress

Production session evidence from project `56`, latest investigated session `23`:

- Total draft: `346 256 188,53 ₽` for `151.76 м2`.
- `Прокладка кабельных линий`, `834.68 м` matched `08-01-025-03` with unit `шт`, total `144 941 419,82 ₽`.
- `Утепление кровли 200 мм`, `194.25 м2` matched trench excavation norm `01-01-063-01` with unit `км`, total `116 737 780,04 ₽`.
- `Разводка труб отопления`, `182.11 м` matched trench excavation norm `01-01-063-01` with unit `км`, total `40 536 356,71 ₽`.
- `60/61` priced lines were candidate-level matches; `39` lines had `unit_mismatch` and created almost the entire anomaly.

The implementation is successful only when these cases are impossible to price automatically.

## Product Architecture: Estimate Memory + FSNB RAG

This plan is not about trying to "teach one neural network estimates" once. The target product architecture is a learning normative system: ProHelper accumulates estimate experience from real imports and user corrections, then uses that evidence to improve FSNB selection while backend guardrails keep every generated draft safe.

### Core Learning Loop

Every useful estimate interaction becomes structured data:

```text
Imported estimates
+ user manual corrections
+ selected/rejected FSNB norms
+ actual quantities and units
+ region and price period
= Estimate Memory
```

For a new generated position, the system retrieves evidence from Estimate Memory and FSNB:

```text
"Утепление кровли 200 мм"
-> similar imported estimate lines
-> norms users actually selected for similar lines
-> user corrections where AI was wrong
-> FSNB norms by title, collection, table, unit, composition, resources
-> curated map for common private-house works
-> reranked candidate list
-> backend hard gates
```

The LLM/reranker helps choose among candidates. It is not allowed to invent a norm, skip unit checks, or turn an unsafe candidate into a priced line.

### Data Corpora

**FSNB Corpus**

- Full normative corpus: code, collection, table, section, name, unit, work composition, resources, price availability, dataset version.
- Used for authoritative pricing and resource decomposition.
- Searchable both lexically and by structured filters: collection, section prefix, unit dimension, scope type, resources.

**Imported Estimate Corpus**

- Real imported estimates from users: project/object context, estimate section, work name, unit, quantity, normative code, price, region, quarter, import source.
- Converted into learning examples only after normalization and quality checks.
- High-value examples: imported work lines with a clear FSNB norm, compatible unit, non-zero quantity, and sane price.

**Correction Corpus**

- The most valuable data: cases where the system selected/offered one norm and the user selected another.
- Stores both sides:
  - rejected candidate;
  - accepted candidate;
  - work context;
  - reason/feedback when provided.
- Used as positive and negative ranking evidence.

**Curated Map**

- Expert deterministic map for common private-house and warehouse works.
- Examples: `foundation.concreting`, `foundation.reinforcement`, `roof.insulation`, `engineering.heating_pipe_layout`, `engineering.cable_installation`.
- Gives safe first-pass scope and section constraints before any neural/rag ranking.

### Quality Gate Before Learning

No online fine-tune from raw imports. A bad uploaded estimate must not poison the system.

An imported or corrected example is allowed into active learning only when it has:

- normalized work name;
- known unit dimension;
- FSNB code or selected norm id;
- compatible work unit and norm unit, or explicit conversion metadata;
- non-empty object/section context;
- sane quantity and price range;
- organization/project provenance;
- source quality status.

Low-quality examples stay stored for audit but do not influence ranking until reviewed or normalized.

### Retrieval And Ranking Flow

For each generated work item:

1. `WorkIntentClassifier` determines scope/action/object/material/system.
2. `NormativeCandidateSearchService` retrieves FSNB candidates with structured filters.
3. `EstimateGenerationLearningEvidenceService` retrieves similar imported lines and corrections.
4. RAG source `estimate_generation_learning` provides semantic evidence when indexed chunks exist.
5. `RuleBasedNormativeCandidateReranker` ranks candidates deterministically by hard facts and evidence.
6. Optional `LLMNormativeCandidateReranker` receives only the short safe candidate list and may choose a candidate or `none`.
7. `NormativeMatchDecisionService` applies backend hard gates.
8. `ResourceAssemblyService` applies resources only if the decision allows pricing.
9. `EstimateGenerationQualityGateService` checks line, section, and draft-level anomalies.

### Confidence Semantics

- High confidence + hard gates passed: norm is selected and priced.
- Medium confidence + hard gates passed: norm is priced, but the line is marked as needing review.
- Low confidence: candidate is shown, but the line is not priced.
- Unit mismatch, scope mismatch, forbidden domain, missing resources, missing prices, or outlier risk: candidate cannot price the line.

### Development Stages

**Stage 1: RAG and Estimate Memory without fine-tune**

- Extract learning examples from imports and corrections.
- Add deterministic retrieval and RAG source.
- Use examples to boost/penalize candidates.
- This is the first production milestone because it improves quality without risky model training.

**Stage 2: Train or tune a reranker**

- Use curated examples to train a small ranking layer: "which norm among these candidates is best for this position".
- Keep backend hard gates unchanged.
- Evaluate on held-out imported/corrected estimates.

**Stage 3: Fine-tune an LLM only after enough clean data**

- Start only after at least thousands of quality examples exist.
- Fine-tune for structured reasoning and explanation, not for bypassing FSNB retrieval.
- The fine-tuned model remains advisory; FSNB data and backend validation stay authoritative.

### Product Moat

The valuable asset becomes the accumulated graph:

```text
work text -> construction context -> FSNB norm -> unit conversion -> quantity pattern -> resources -> price period -> region -> user correction
```

This is why the product should evolve into a learning normative system, not a one-off AI generator. More real estimates through ProHelper should directly improve future FSNB matching quality.

## File Map

### Backend, EstimateGeneration

- Modify: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Services/Normatives/NormativeUnitNormalizer.php`
  - Parse FSNB accounting units into dimension, base unit, multiplier, and compatibility reason.
- Create: `prohelper/app/BusinessModules/Addons/EstimateGeneration/DTOs/Normatives/NormativeUnitData.php`
  - Immutable parsed unit DTO.
- Create: `prohelper/app/BusinessModules/Addons/EstimateGeneration/DTOs/Normatives/WorkIntentData.php`
  - Immutable intent DTO for generated/imported work lines.
- Create: `prohelper/app/BusinessModules/Addons/EstimateGeneration/DTOs/Normatives/NormativeCandidateDecisionContextData.php`
  - Data object passed into hard gate and reranker services.
- Modify: `prohelper/app/BusinessModules/Addons/EstimateGeneration/DTOs/NormativeMatchDecisionData.php`
  - Keep current API and add normalized decision fields when needed.
- Create: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Services/Normatives/WorkIntentClassifier.php`
  - Classify each work item into scope, action, object, material, system, forbidden norm groups, and expected unit dimensions.
- Create: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Services/Normatives/NormativeScopeRuleCatalog.php`
  - Central curated rule catalog for common private-house scopes and FSNB collection/section constraints.
- Create: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Services/Normatives/NormativeCandidateSearchService.php`
  - Replace pre-score `OR token + orderBy(code)` with scope-aware candidate search.
- Modify: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Normatives/Services/EstimateNormativeMatcher.php`
  - Delegate candidate search, work intent, scoring, and optional reranking.
- Modify: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Services/Normatives/NormativeMatchDecisionService.php`
  - Enforce hard gates: unit, scope, resources, prices, forbidden domains, outlier risk.
- Modify: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Services/ResourceAssemblyService.php`
  - Apply resources only for `canUseForPricing=true`, and never multiply incompatible dimensions.
- Modify: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Services/EstimatePricingService.php`
  - Preserve zero price for non-priced candidates and expose price status cleanly.
- Modify: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Services/EstimateValidationService.php`
  - Normalize new flags and statuses into draft summary.
- Modify: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Services/Quality/EstimateGenerationQualityGateService.php`
  - Add line-level and section-level anomaly gates before final draft status is set.
- Modify: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Services/EstimateGenerationOrchestrator.php`
  - Add generation stages for intent classification, safe normative matching, and learning evidence.
- Modify: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Services/Ocr/ConstructionDocumentFactExtractor.php`
  - Improve area fact types and source confidence.
- Modify: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Services/Ocr/DocumentFactMerger.php`
  - Select project area by source rank, not just highest confidence.

### Backend, Learning And RAG

- Create: `prohelper/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_05_30_000001_create_estimate_generation_learning_examples_table.php`
- Create: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Models/EstimateGenerationLearningExample.php`
- Create: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Services/Learning/EstimateLearningExampleExtractor.php`
- Create: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Services/Learning/EstimateGenerationLearningRecorder.php`
- Create: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Services/Learning/EstimateGenerationLearningIndexer.php`
- Create: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Services/Learning/EstimateGenerationLearningEvidenceService.php`
- Modify: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Http/Controllers/EstimateGenerationController.php`
  - Record accepted/rejected user selections and feedback.
- Modify: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Services/Normatives/NormativeCandidateSelectionService.php`
  - Record selected norm as a learning example after successful draft update.
- Modify: `prohelper/app/BusinessModules/Features/BudgetEstimates/Services/Import/*`
  - Call extractor after successful estimate import.
- Create or modify: `prohelper/app/BusinessModules/Features/AIAssistant/Services/Rag/Sources/EstimateGenerationLearningRagSource.php`
  - Add a RAG source for curated examples, respecting current dirty RAG source additions.
- Modify: `prohelper/app/BusinessModules/Features/AIAssistant/Services/Rag/RagSourceRegistry.php`
  - Register the new source.

### Backend, Reranker

- Create: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Services/Normatives/Reranking/NormativeCandidateRerankerInterface.php`
- Create: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Services/Normatives/Reranking/RuleBasedNormativeCandidateReranker.php`
- Create: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Services/Normatives/Reranking/LLMNormativeCandidateReranker.php`
- Create: `prohelper/app/BusinessModules/Addons/EstimateGeneration/DTOs/Normatives/NormativeRerankResultData.php`
- Modify: `prohelper/app/BusinessModules/Addons/EstimateGeneration/EstimateGenerationServiceProvider.php`
  - Bind the reranker interface and learning services.
- Modify: `prohelper/config/estimate-generation.php`
  - Add safe, explicit config for normative search, learning, and reranking.

### Admin UI

- Modify: `prohelper_admin/src/pages/EstimateGeneration/EstimateGenerationPage.tsx`
- Modify: `prohelper_admin/src/services/estimateGeneration.ts`
- Modify: `prohelper_admin/src/types/estimateGeneration.ts`
- Create: `prohelper_admin/src/pages/EstimateGeneration/components/GenerationProgressDialog.tsx`
- Create: `prohelper_admin/src/pages/EstimateGeneration/components/NormativeEvidencePanel.tsx`
- Test: `prohelper_admin/src/pages/EstimateGeneration/__tests__/EstimateGenerationPage.test.tsx`

### Tests

- Create: `prohelper/tests/Unit/EstimateGeneration/FsnbFirstNormativeSafetyTest.php`
- Modify: `prohelper/tests/Unit/EstimateGeneration/NormativeUnitNormalizerTest.php`
- Modify: `prohelper/tests/Unit/EstimateGeneration/NormativeMatchDecisionServiceTest.php`
- Modify: `prohelper/tests/Unit/EstimateGeneration/EstimateNormativeMatcherTest.php`
- Create: `prohelper/tests/Unit/EstimateGeneration/WorkIntentClassifierTest.php`
- Create: `prohelper/tests/Unit/EstimateGeneration/EstimateGenerationLearningRecorderTest.php`
- Create: `prohelper/tests/Unit/EstimateGeneration/EstimateGenerationLearningEvidenceServiceTest.php`
- Modify: `prohelper/tests/Unit/EstimateGeneration/EstimateGenerationQualityGateServiceTest.php`
- Modify: `prohelper/tests/Unit/EstimateGeneration/Ocr/ConstructionDocumentFactExtractorTest.php`
- Create: `prohelper/tests/Feature/EstimateGeneration/EstimateGenerationLearningImportTest.php`
- Create: `prohelper/tests/Feature/EstimateGeneration/EstimateGenerationNormativeSelectionLearningTest.php`
- Create: `prohelper/tests/Unit/AIAssistant/Rag/EstimateGenerationLearningRagSourceTest.php`

---

## Task 1: Safety Regression Harness

**Files:**
- Create: `prohelper/tests/Unit/EstimateGeneration/FsnbFirstNormativeSafetyTest.php`
- Modify: `prohelper/tests/Unit/EstimateGeneration/NormativeMatchDecisionServiceTest.php`
- Modify: `prohelper/tests/Unit/EstimateGeneration/EstimateGenerationQualityGateServiceTest.php`

- [ ] **Step 1: Write failing tests for the 346M class of failures**

Create `FsnbFirstNormativeSafetyTest` with three cases:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeMatchDecisionService;
use PHPUnit\Framework\TestCase;

final class FsnbFirstNormativeSafetyTest extends TestCase
{
    public function test_cable_line_cannot_be_priced_by_piece_substation_norm(): void
    {
        $decision = (new NormativeMatchDecisionService())->decide($this->candidate([
            'code' => '08-01-025-03',
            'name' => 'Подстанция блочная',
            'unit' => 'шт',
            'confidence' => 0.95,
        ]), [
            'name' => 'Прокладка кабельных линий',
            'unit' => 'м',
            'quantity' => 834.68,
        ]);

        $this->assertFalse($decision->canUseForPricing);
        $this->assertContains('unit_mismatch', $decision->warnings);
    }

    public function test_roof_insulation_cannot_be_priced_by_trench_norm(): void
    {
        $decision = (new NormativeMatchDecisionService())->decide($this->candidate([
            'code' => '01-01-063-01',
            'name' => 'Разработка грунта в траншеях',
            'unit' => 'км',
            'confidence' => 0.95,
        ]), [
            'name' => 'Утепление кровли 200 мм',
            'unit' => 'м2',
            'quantity' => 194.25,
        ]);

        $this->assertFalse($decision->canUseForPricing);
        $this->assertContains('unit_mismatch', $decision->warnings);
    }

    public function test_heating_pipe_layout_cannot_be_priced_by_earthwork_norm(): void
    {
        $decision = (new NormativeMatchDecisionService())->decide($this->candidate([
            'code' => '01-01-063-01',
            'name' => 'Разработка грунта в траншеях',
            'unit' => 'км',
            'confidence' => 0.95,
        ]), [
            'name' => 'Разводка труб отопления',
            'unit' => 'м',
            'quantity' => 182.11,
            'work_intent' => ['scope' => 'engineering', 'system' => 'heating'],
        ]);

        $this->assertFalse($decision->canUseForPricing);
        $this->assertContains('scope_mismatch', $decision->warnings);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function candidate(array $overrides): array
    {
        return [
            ...$overrides,
            'resources' => [
                'materials' => [[
                    'price_source' => 'fsbc_base',
                    'quantity' => 1,
                    'unit_price' => 1000,
                ]],
                'machinery' => [],
                'labor' => [],
                'other' => [],
            ],
            'collection' => ['norm_type' => 'gesn'],
            'section' => ['code' => substr((string) $overrides['code'], 0, 5)],
        ];
    }
}
```

- [ ] **Step 2: Run the new test and verify it fails on current behavior**

Run:

```powershell
php artisan test tests/Unit/EstimateGeneration/FsnbFirstNormativeSafetyTest.php
```

Expected: at least one failure where current candidate decision still allows pricing for resource-rich but unsafe candidates.

- [ ] **Step 3: Add quality gate test for line anomaly**

Add a test in `EstimateGenerationQualityGateServiceTest` where one generated line exceeds `800000 ₽/м2` equivalent or one line contributes more than `35%` of total because of a candidate norm. Expected level: `blocked`, critical flag: `line_total_anomaly`.

- [ ] **Step 4: Commit the failing safety harness**

Run:

```powershell
git add tests/Unit/EstimateGeneration/FsnbFirstNormativeSafetyTest.php tests/Unit/EstimateGeneration/NormativeMatchDecisionServiceTest.php tests/Unit/EstimateGeneration/EstimateGenerationQualityGateServiceTest.php
git commit -m "test[lk]: зафиксированы опасные ошибки подбора ФСНБ"
```

---

## Task 2: Unit Compatibility And Conversion Model

**Files:**
- Create: `prohelper/app/BusinessModules/Addons/EstimateGeneration/DTOs/Normatives/NormativeUnitData.php`
- Modify: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Services/Normatives/NormativeUnitNormalizer.php`
- Modify: `prohelper/tests/Unit/EstimateGeneration/NormativeUnitNormalizerTest.php`

- [ ] **Step 1: Write unit parsing tests**

Add cases:

```php
public function test_parses_normative_units_with_dimensions_and_multipliers(): void
{
    $this->assertSame(['length', 'м', 1000.0], $this->unitTuple('км'));
    $this->assertSame(['length', 'м', 100.0], $this->unitTuple('100 м'));
    $this->assertSame(['area', 'м2', 100.0], $this->unitTuple('100 м2'));
    $this->assertSame(['volume', 'м3', 1000.0], $this->unitTuple('1000 м3'));
    $this->assertSame(['mass', 'кг', 1000.0], $this->unitTuple('т'));
    $this->assertSame(['piece', 'шт', 1.0], $this->unitTuple('шт'));
    $this->assertSame(['set', 'компл', 1.0], $this->unitTuple('компл'));
}

public function test_incompatible_dimensions_have_no_quantity_factor(): void
{
    $this->assertNull(NormativeUnitNormalizer::safeQuantityFactor('м2', 'км'));
    $this->assertNull(NormativeUnitNormalizer::safeQuantityFactor('м', 'шт'));
}

public function test_compatible_units_convert_to_norm_quantity(): void
{
    $this->assertSame(0.83468, NormativeUnitNormalizer::safeQuantityFactor('м', 'км'));
    $this->assertSame(1.9425, NormativeUnitNormalizer::safeQuantityFactor('м2', '100 м2'));
}

/**
 * @return array{string, string, float}
 */
private function unitTuple(string $unit): array
{
    $parsed = NormativeUnitNormalizer::parseDetailed($unit);

    return [$parsed->dimension, $parsed->baseUnit, $parsed->multiplier];
}
```

- [ ] **Step 2: Implement `NormativeUnitData`**

Create:

```php
<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\DTOs\Normatives;

final readonly class NormativeUnitData
{
    public function __construct(
        public string $raw,
        public string $normalized,
        public string $dimension,
        public string $baseUnit,
        public float $multiplier,
    ) {}

    public function isKnown(): bool
    {
        return $this->dimension !== '' && $this->baseUnit !== '';
    }

    public function compatibleWith(self $other): bool
    {
        return $this->isKnown()
            && $other->isKnown()
            && $this->dimension === $other->dimension
            && $this->baseUnit === $other->baseUnit;
    }
}
```

- [ ] **Step 3: Replace unsafe `quantityFactor` behavior**

In `NormativeUnitNormalizer`:

- Keep `parse()` and `quantityFactor()` for compatibility during the transition.
- Add `parseDetailed(string $unit): NormativeUnitData`.
- Add `safeQuantityFactor(string $workUnit, string $normUnit): ?float`.
- Make `quantityFactor()` delegate to `safeQuantityFactor()` and return `1.0` only for old callers; new code must use `safeQuantityFactor()`.
- Normalize Cyrillic units in real UTF-8: `м`, `м2`, `м3`, `км`, `100 м`, `100 м2`, `100 м3`, `1000 м3`, `шт`, `компл`, `т`, `кг`, `чел-ч`, `маш-ч`.

- [ ] **Step 4: Run unit tests**

Run:

```powershell
php artisan test tests/Unit/EstimateGeneration/NormativeUnitNormalizerTest.php
```

Expected: all `NormativeUnitNormalizerTest` tests pass.

- [ ] **Step 5: Commit**

```powershell
git add app/BusinessModules/Addons/EstimateGeneration/DTOs/Normatives/NormativeUnitData.php app/BusinessModules/Addons/EstimateGeneration/Services/Normatives/NormativeUnitNormalizer.php tests/Unit/EstimateGeneration/NormativeUnitNormalizerTest.php
git commit -m "fix[lk]: ужесточена совместимость единиц ФСНБ"
```

---

## Task 3: Work Intent Classifier And Scope Rules

**Files:**
- Create: `prohelper/app/BusinessModules/Addons/EstimateGeneration/DTOs/Normatives/WorkIntentData.php`
- Create: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Services/Normatives/WorkIntentClassifier.php`
- Create: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Services/Normatives/NormativeScopeRuleCatalog.php`
- Modify: `prohelper/config/estimate-generation.php`
- Create: `prohelper/tests/Unit/EstimateGeneration/WorkIntentClassifierTest.php`

- [ ] **Step 1: Write classifier tests for common generated private-house works**

Cover at least these inputs:

```php
/**
 * @dataProvider workIntentProvider
 */
public function test_classifies_private_house_work_intents(
    string $name,
    string $unit,
    string $expectedScope,
    string $expectedAction,
    string $expectedDimension,
    array $forbiddenCollections
): void {
    $intent = (new WorkIntentClassifier(new NormativeScopeRuleCatalog()))->classify([
        'name' => $name,
        'unit' => $unit,
    ], [
        'scope_type' => $expectedScope,
    ]);

    $this->assertSame($expectedScope, $intent->scope);
    $this->assertSame($expectedAction, $intent->action);
    $this->assertContains($expectedDimension, $intent->expectedDimensions);
    foreach ($forbiddenCollections as $collection) {
        $this->assertContains($collection, $intent->forbiddenNormTypes);
    }
}

public static function workIntentProvider(): array
{
    return [
        ['Утепление кровли 200 мм', 'м2', 'roof', 'insulation', 'area', ['gesn_earthwork']],
        ['Разводка труб отопления', 'м', 'engineering', 'pipe_layout', 'length', ['gesn_earthwork']],
        ['Прокладка кабельных линий', 'м', 'engineering', 'cable_installation', 'length', ['gesn_earthwork']],
        ['Опалубка ленточного фундамента', 'м2', 'foundation', 'formwork', 'area', []],
        ['Армирование фундаментной ленты', 'т', 'foundation', 'reinforcement', 'mass', []],
        ['Бетонирование фундаментной ленты B22.5', 'м3', 'foundation', 'concreting', 'volume', []],
    ];
}
```

- [ ] **Step 2: Implement `WorkIntentData`**

Fields:

```php
public function __construct(
    public string $scope,
    public string $action,
    public ?string $object,
    public ?string $material,
    public ?string $system,
    public array $expectedDimensions,
    public array $preferredNormTypes,
    public array $forbiddenNormTypes,
    public array $preferredSectionPrefixes,
    public array $forbiddenSectionPrefixes,
    public float $confidence,
    public array $signals,
) {}
```

- [ ] **Step 3: Implement curated catalog**

`NormativeScopeRuleCatalog` returns rules for:

- `foundation`: earthworks `01-*`, concrete `06-*`, reinforcement, formwork, waterproofing.
- `roof`: roof structures, insulation, vapor barrier, waterproofing; forbid `01-*` earthworks unless name explicitly says excavation.
- `engineering/electrical`: electrical and installation collections; forbid earthwork collections for indoor cable layout.
- `engineering/heating`: heating/plumbing installation collections; forbid earthworks unless line explicitly says external trench.
- `walls`, `slabs`, `facade`, `finishing`, `site`, `temporary`.

- [ ] **Step 4: Implement classifier**

Use deterministic Russian keyword rules before LLM/RAG. It must return a non-empty intent for every priced generated item. Low confidence is allowed, empty intent is not.

- [ ] **Step 5: Wire classifier into service container**

Bind `WorkIntentClassifier` and `NormativeScopeRuleCatalog` in `EstimateGenerationServiceProvider`.

- [ ] **Step 6: Run tests and static syntax checks**

```powershell
php artisan test tests/Unit/EstimateGeneration/WorkIntentClassifierTest.php
php -l app/BusinessModules/Addons/EstimateGeneration/Services/Normatives/WorkIntentClassifier.php
php -l app/BusinessModules/Addons/EstimateGeneration/Services/Normatives/NormativeScopeRuleCatalog.php
```

Expected: all tests pass, all syntax checks report no errors.

- [ ] **Step 7: Commit**

```powershell
git add config/estimate-generation.php app/BusinessModules/Addons/EstimateGeneration/DTOs/Normatives/WorkIntentData.php app/BusinessModules/Addons/EstimateGeneration/Services/Normatives/WorkIntentClassifier.php app/BusinessModules/Addons/EstimateGeneration/Services/Normatives/NormativeScopeRuleCatalog.php app/BusinessModules/Addons/EstimateGeneration/EstimateGenerationServiceProvider.php tests/Unit/EstimateGeneration/WorkIntentClassifierTest.php
git commit -m "feat[lk]: добавлена классификация работ для подбора ФСНБ"
```

---

## Task 4: Safe Candidate Search Before Scoring

**Files:**
- Create: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Services/Normatives/NormativeCandidateSearchService.php`
- Modify: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Normatives/Services/EstimateNormativeMatcher.php`
- Modify: `prohelper/tests/Unit/EstimateGeneration/EstimateNormativeMatcherTest.php`

- [ ] **Step 1: Write matcher tests for search ordering and scope filtering**

Add tests that prove:

- `01-*` earthwork norms are not top candidates for roof insulation.
- `08-*` piece-equipment norms are not top candidates for cable line measured in meters unless section/name matches installation of cable lines.
- Query is not limited by `orderBy('code')` before scoring.

Use factory/model setup already used in `EstimateNormativeMatcherTest`.

- [ ] **Step 2: Implement `NormativeCandidateSearchService`**

Public method:

```php
/**
 * @param array<string, mixed> $workItem
 * @param array<string, mixed> $context
 * @param array<int, string> $tokens
 * @return \Illuminate\Support\Collection<int, \App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateNorm>
 */
public function search(EstimateDatasetVersion $version, array $workItem, array $context, array $tokens, int $limit): Collection
```

Rules:

- Build `WorkIntentData` first.
- Apply preferred section prefixes as positive filters when confidence is high enough.
- Apply forbidden section prefixes as hard exclusions.
- Pull a larger lexical pool: `max($limit * 60, 300)`.
- Sort by lexical match, unit dimension compatibility, section prefix, collection type, and resources presence after fetching.
- Return at least `limit` candidates when safe candidates exist.

- [ ] **Step 3: Move old `candidateNorms()` logic out of matcher**

`EstimateNormativeMatcher::matchWorkItem()` should call `NormativeCandidateSearchService::search()` and pass the returned candidates to `scoreNorm()`.

- [ ] **Step 4: Run matcher tests**

```powershell
php artisan test tests/Unit/EstimateGeneration/EstimateNormativeMatcherTest.php
```

Expected: matcher tests pass and new tests fail before implementation, pass after implementation.

- [ ] **Step 5: Commit**

```powershell
git add app/BusinessModules/Addons/EstimateGeneration/Services/Normatives/NormativeCandidateSearchService.php app/BusinessModules/Addons/EstimateGeneration/Normatives/Services/EstimateNormativeMatcher.php tests/Unit/EstimateGeneration/EstimateNormativeMatcherTest.php
git commit -m "fix[lk]: поиск ФСНБ учитывает область работ до ранжирования"
```

---

## Task 5: Hard Gate Decision Service

**Files:**
- Create: `prohelper/app/BusinessModules/Addons/EstimateGeneration/DTOs/Normatives/NormativeCandidateDecisionContextData.php`
- Modify: `prohelper/app/BusinessModules/Addons/EstimateGeneration/DTOs/NormativeMatchDecisionData.php`
- Modify: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Services/Normatives/NormativeMatchDecisionService.php`
- Modify: `prohelper/tests/Unit/EstimateGeneration/NormativeMatchDecisionServiceTest.php`
- Modify: `prohelper/tests/Unit/EstimateGeneration/FsnbFirstNormativeSafetyTest.php`

- [ ] **Step 1: Expand decision tests**

Assertions:

- `unit_mismatch` means `canUseForPricing=false`.
- `scope_mismatch` means `canUseForPricing=false`.
- missing prices means `canUseForPricing=false`.
- compatible unit + compatible scope + resources + prices + confidence `>= 0.72` means `accepted`.
- compatible unit + compatible scope + resources + prices + confidence between `0.55` and `0.72` means `review_priced`, `canUseForPricing=true`, with `requires_normative_review`.
- compatible unit + compatible scope + resources + prices + confidence below `0.55` means `candidate`, `canUseForPricing=false`.

- [ ] **Step 2: Implement hard gate order**

Gate order inside `decide()`:

1. Parse units with `NormativeUnitNormalizer::parseDetailed()`.
2. Block unknown or incompatible dimensions.
3. Check intent scope against candidate collection/section prefixes.
4. Block forbidden norm types/section prefixes.
5. Block no resources.
6. Block no priced resources.
7. Mark low confidence candidate as non-pricing.
8. Mark middle confidence as pricing with review only if all hard gates passed.
9. Mark accepted only if all hard gates passed and confidence threshold passed.

- [ ] **Step 3: Keep status vocabulary explicit**

Allowed decision statuses:

- `accepted`
- `review_priced`
- `candidate`
- `rejected`

No other status may be produced by `NormativeMatchDecisionService`.

- [ ] **Step 4: Run decision and safety tests**

```powershell
php artisan test tests/Unit/EstimateGeneration/NormativeMatchDecisionServiceTest.php tests/Unit/EstimateGeneration/FsnbFirstNormativeSafetyTest.php
```

Expected: all tests pass.

- [ ] **Step 5: Commit**

```powershell
git add app/BusinessModules/Addons/EstimateGeneration/DTOs/Normatives/NormativeCandidateDecisionContextData.php app/BusinessModules/Addons/EstimateGeneration/DTOs/NormativeMatchDecisionData.php app/BusinessModules/Addons/EstimateGeneration/Services/Normatives/NormativeMatchDecisionService.php tests/Unit/EstimateGeneration/NormativeMatchDecisionServiceTest.php tests/Unit/EstimateGeneration/FsnbFirstNormativeSafetyTest.php
git commit -m "fix[lk]: запрещено применять опасные кандидаты ФСНБ"
```

---

## Task 6: Resource Assembly And Pricing Cannot Use Unsafe Candidates

**Files:**
- Modify: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Services/ResourceAssemblyService.php`
- Modify: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Services/EstimatePricingService.php`
- Modify: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Services/EstimateValidationService.php`
- Modify: `prohelper/tests/Unit/EstimateGeneration/EstimateGenerationNormativeOutputTest.php`
- Create: `prohelper/tests/Unit/EstimateGeneration/ResourceAssemblySafetyTest.php`

- [ ] **Step 1: Write resource assembly safety tests**

Test:

- unsafe candidate keeps `materials`, `labor`, `machinery`, `other_resources` empty.
- unsafe candidate keeps `total_cost=0`.
- `normative_match.status='candidate'`.
- `normative_candidates` still contains offered norms for UI review.
- `price_source=null`.

- [ ] **Step 2: Switch assembly to `safeQuantityFactor()`**

In `applyNormativeResources()`:

```php
$quantityFactor = NormativeUnitNormalizer::safeQuantityFactor(
    (string) ($workItem['unit'] ?? ''),
    (string) ($selected['unit'] ?? '')
);

if ($quantityFactor === null && !$selectedByUser) {
    return $this->applyCandidateOnlyMatch($workItem, $match, $decision ?? [
        'status' => 'candidate',
        'warnings' => ['unit_mismatch'],
    ]);
}

$normQuantity = max((float) ($workItem['quantity'] ?? 0), 0.0) * ($quantityFactor ?? 1.0);
```

Selected-by-user still requires compatible units unless the UI/API later supports explicit quantity conversion input. This protects saved estimates from accidental `м2 -> км` pricing.

- [ ] **Step 3: Normalize price statuses**

`EstimateValidationService` must expose:

- `normative_items.accepted`
- `normative_items.review_priced`
- `normative_items.candidate_only`
- `normative_items.not_found`
- `normative_items.unit_mismatch`
- `normative_items.scope_mismatch`

- [ ] **Step 4: Run tests**

```powershell
php artisan test tests/Unit/EstimateGeneration/ResourceAssemblySafetyTest.php tests/Unit/EstimateGeneration/EstimateGenerationNormativeOutputTest.php
```

Expected: all tests pass.

- [ ] **Step 5: Commit**

```powershell
git add app/BusinessModules/Addons/EstimateGeneration/Services/ResourceAssemblyService.php app/BusinessModules/Addons/EstimateGeneration/Services/EstimatePricingService.php app/BusinessModules/Addons/EstimateGeneration/Services/EstimateValidationService.php tests/Unit/EstimateGeneration/ResourceAssemblySafetyTest.php tests/Unit/EstimateGeneration/EstimateGenerationNormativeOutputTest.php
git commit -m "fix[lk]: ресурсы ФСНБ применяются только после строгой проверки"
```

---

## Task 7: Line-Level Outlier Guard

**Files:**
- Modify: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Services/Quality/EstimateGenerationQualityGateService.php`
- Modify: `prohelper/tests/Unit/EstimateGeneration/EstimateGenerationQualityGateServiceTest.php`

- [ ] **Step 1: Write tests for per-line and per-section anomalies**

Cases:

- one line with `total_cost=144941419.82` in a `151.76 м2` house blocks draft with `line_total_anomaly`.
- one section above `45%` of total is a warning when its line statuses are accepted and total-per-m2 is sane.
- one section above `45%` blocks when it contains `review_priced` or `candidate` norm decision.

- [ ] **Step 2: Implement line anomaly metrics**

Add metrics:

- `max_line_total`
- `max_line_share`
- `max_line_total_per_project_m2`
- `anomalous_line_keys`
- `anomalous_section_keys`

Rules:

- Block if a single generated line contributes more than `35%` of draft total and the norm decision is not `accepted`.
- Block if a line exceeds `800000 ₽/м2` of project area.
- Block if unit mismatch/scope mismatch exists on any priced line.

- [ ] **Step 3: Run quality tests**

```powershell
php artisan test tests/Unit/EstimateGeneration/EstimateGenerationQualityGateServiceTest.php
```

Expected: all tests pass.

- [ ] **Step 4: Commit**

```powershell
git add app/BusinessModules/Addons/EstimateGeneration/Services/Quality/EstimateGenerationQualityGateService.php tests/Unit/EstimateGeneration/EstimateGenerationQualityGateServiceTest.php
git commit -m "fix[lk]: добавлен контроль аномальных строк сметы"
```

---

## Task 8: OCR Area Fact Ranking

**Files:**
- Modify: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Services/Ocr/ConstructionDocumentFactExtractor.php`
- Modify: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Services/Ocr/DocumentFactMerger.php`
- Modify: `prohelper/tests/Unit/EstimateGeneration/Ocr/ConstructionDocumentFactExtractorTest.php`

- [ ] **Step 1: Write tests for total area versus room/zone area**

Use sample lines:

```text
Общая площадь дома 151.76 м2
Жилая площадь 80.21 м2
Терраса 22.15 м2
Площадь зоны 22.15 м2
```

Expected summary:

- `total_area_m2=151.76`.
- `zones` contains terrace/zone values.
- `conflicts` are recorded if two total-area sources disagree by more than `1 м2`.
- a zone/living/terrace area is never promoted to project total while a total-area fact exists.

- [ ] **Step 2: Add fact subtype and rank**

`ExtractedDocumentFact->normalizedPayload` for area facts must include:

- `area_role`: `total`, `living`, `terrace`, `room`, `zone`, `unknown`
- `source_rank`: lower is stronger, with `total=10`, `living=40`, `terrace=50`, `zone=60`, `unknown=70`

- [ ] **Step 3: Merge by source rank then confidence**

`DocumentFactMerger::bestNumber()` for total area must select:

1. area facts with `area_role=total`;
2. lowest `source_rank`;
3. highest confidence;
4. larger page-level text coverage when available.

- [ ] **Step 4: Run OCR unit tests**

```powershell
php artisan test tests/Unit/EstimateGeneration/Ocr/ConstructionDocumentFactExtractorTest.php
```

Expected: all tests pass.

- [ ] **Step 5: Commit**

```powershell
git add app/BusinessModules/Addons/EstimateGeneration/Services/Ocr/ConstructionDocumentFactExtractor.php app/BusinessModules/Addons/EstimateGeneration/Services/Ocr/DocumentFactMerger.php tests/Unit/EstimateGeneration/Ocr/ConstructionDocumentFactExtractorTest.php
git commit -m "fix[lk]: площадь объекта выбирается по надежности OCR-фактов"
```

---

## Task 9: Learning Examples Schema

**Files:**
- Create: `prohelper/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_05_30_000001_create_estimate_generation_learning_examples_table.php`
- Create: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Models/EstimateGenerationLearningExample.php`
- Modify: `prohelper/app/BusinessModules/Addons/EstimateGeneration/EstimateGenerationServiceProvider.php`
- Create: `prohelper/tests/Unit/EstimateGeneration/EstimateGenerationLearningExampleModelTest.php`

- [ ] **Step 1: Write model tests**

Assert casts:

- `context_payload` as array.
- `work_intent` as array.
- `source_refs` as array.
- `quality_flags` as array.
- `accepted_at` as datetime.
- `indexed_at` as datetime.

- [ ] **Step 2: Create migration**

Table fields:

- `id`
- `organization_id`
- `project_id nullable`
- `source_type` enum-like string: `imported_estimate`, `user_selection`, `user_rejection`, `feedback`, `generated_accepted`
- `source_entity_type nullable`
- `source_entity_id nullable`
- `estimate_id nullable`
- `estimate_item_id nullable`
- `generation_session_id nullable`
- `generation_package_item_id nullable`
- `work_name text`
- `work_unit string(50) nullable`
- `work_quantity decimal(18,6) nullable`
- `work_intent json nullable`
- `normative_dataset_version_id nullable`
- `estimate_norm_id nullable`
- `normative_code string(100)`
- `normative_name text nullable`
- `normative_unit string(50) nullable`
- `decision_status string(60)`
- `confidence decimal(5,4) nullable`
- `is_positive boolean`
- `source_quality_score decimal(5,4) nullable`
- `context_payload json nullable`
- `source_refs json nullable`
- `quality_flags json nullable`
- `indexed_at timestamp nullable`
- timestamps

Indexes:

- `organization_id, source_type, created_at`
- `organization_id, normative_code`
- `organization_id, is_positive, created_at`
- `estimate_norm_id`
- `generation_session_id`
- unique partial equivalent is not portable through schema builder, so add normal unique on `source_type, source_entity_type, source_entity_id, normative_code` only when all source identifiers are present in recorder logic.

- [ ] **Step 3: Implement model**

Model fillable and casts must match the migration. Add relations to organization, project, session, package item, and estimate norm.

- [ ] **Step 4: Run syntax and model tests**

```powershell
php -l app/BusinessModules/Addons/EstimateGeneration/migrations/2026_05_30_000001_create_estimate_generation_learning_examples_table.php
php -l app/BusinessModules/Addons/EstimateGeneration/Models/EstimateGenerationLearningExample.php
php artisan test tests/Unit/EstimateGeneration/EstimateGenerationLearningExampleModelTest.php
```

Expected: syntax checks pass, model tests pass.

- [ ] **Step 5: Commit**

```powershell
git add app/BusinessModules/Addons/EstimateGeneration/migrations/2026_05_30_000001_create_estimate_generation_learning_examples_table.php app/BusinessModules/Addons/EstimateGeneration/Models/EstimateGenerationLearningExample.php tests/Unit/EstimateGeneration/EstimateGenerationLearningExampleModelTest.php
git commit -m "feat[lk]: добавлена память примеров для генерации смет"
```

---

## Task 10: Extract Learning Pairs From Imported Estimates

**Files:**
- Create: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Services/Learning/EstimateLearningExampleExtractor.php`
- Create: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Services/Learning/EstimateGenerationLearningRecorder.php`
- Modify: `prohelper/app/BusinessModules/Features/BudgetEstimates/Services/Import/EstimateImportService.php` or the actual import completion service found during implementation.
- Modify: `prohelper/app/BusinessModules/Features/BudgetEstimates/Services/Import/Parsers/*` only if normative code/unit fields are not preserved in row data.
- Create: `prohelper/tests/Feature/EstimateGeneration/EstimateGenerationLearningImportTest.php`

- [ ] **Step 1: Write import learning test**

Test imports or constructs an estimate item with:

- work name: `Бетонирование фундаментной ленты B22.5`
- unit: `м3`
- quantity: `13.8`
- `normative_rate_code` or raw parsed FSNB code
- section context: `Фундамент`

Expected:

- one positive learning example is recorded.
- `work_intent.scope=foundation`.
- `work_intent.action=concreting`.
- `normative_code` is normalized.
- no example is recorded for manual material-only rows without a work norm.

- [ ] **Step 2: Implement extractor**

`EstimateLearningExampleExtractor` accepts imported estimate/sections/items and emits clean examples:

- reject empty names;
- reject rows without normative code;
- reject material-only resource rows when they are children of a work row;
- normalize unit through `NormativeUnitNormalizer`;
- classify intent through `WorkIntentClassifier`;
- include raw import adapter metadata in `context_payload`.

- [ ] **Step 3: Implement recorder**

`EstimateGenerationLearningRecorder` responsibilities:

- upsert by stable source identity and norm code;
- never overwrite a positive curated example with a lower-quality duplicate;
- mark examples from user selections higher quality than import-only examples;
- call no queue/network work directly.

- [ ] **Step 4: Hook into import success**

Find the single import path that persists final estimate items. Add recorder call after the estimate transaction is committed or after successful persistence returns. If import code has multiple adapters, hook at the common service layer, not each adapter.

- [ ] **Step 5: Run tests**

```powershell
php artisan test tests/Feature/EstimateGeneration/EstimateGenerationLearningImportTest.php
```

Expected: test passes.

- [ ] **Step 6: Commit**

```powershell
git add app/BusinessModules/Addons/EstimateGeneration/Services/Learning/EstimateLearningExampleExtractor.php app/BusinessModules/Addons/EstimateGeneration/Services/Learning/EstimateGenerationLearningRecorder.php app/BusinessModules/Features/BudgetEstimates/Services/Import tests/Feature/EstimateGeneration/EstimateGenerationLearningImportTest.php
git commit -m "feat[lk]: импортированные сметы обучают подбор ФСНБ"
```

---

## Task 11: Record User Normative Corrections

**Files:**
- Modify: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Services/Normatives/NormativeCandidateSelectionService.php`
- Modify: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Http/Controllers/EstimateGenerationController.php`
- Modify: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Http/Requests/EstimateGenerationFeedbackRequest.php`
- Create: `prohelper/tests/Feature/EstimateGeneration/EstimateGenerationNormativeSelectionLearningTest.php`

- [ ] **Step 1: Write selection learning test**

Scenario:

- session has draft with one line and offered candidates;
- user selects candidate norm;
- service updates draft;
- recorder creates positive example with `source_type=user_selection`.

Expected:

- example references `generation_session_id`.
- example references `generation_package_item_id` when package item exists.
- example includes previous offered candidates in `context_payload`.

- [ ] **Step 2: Write rejection feedback test**

Scenario:

- user sends feedback that selected norm is wrong.

Expected:

- recorder creates negative example with `source_type=user_rejection`.
- `is_positive=false`.
- context includes rejected `norm_id`/`normative_code`.

- [ ] **Step 3: Implement positive recording**

After successful `NormativeCandidateSelectionService::select()`, call recorder with:

- original work item before selection;
- selected norm after selection;
- user id;
- session id;
- package item id when available;
- decision status.

- [ ] **Step 4: Implement rejection recording**

Extend feedback validation to accept normative correction feedback with fields:

- `work_item_key`
- `norm_id`
- `normative_code`
- `reason`

Store feedback as now and record a negative learning example.

- [ ] **Step 5: Run tests**

```powershell
php artisan test tests/Feature/EstimateGeneration/EstimateGenerationNormativeSelectionLearningTest.php
```

Expected: all tests pass.

- [ ] **Step 6: Commit**

```powershell
git add app/BusinessModules/Addons/EstimateGeneration/Services/Normatives/NormativeCandidateSelectionService.php app/BusinessModules/Addons/EstimateGeneration/Http/Controllers/EstimateGenerationController.php app/BusinessModules/Addons/EstimateGeneration/Http/Requests/EstimateGenerationFeedbackRequest.php tests/Feature/EstimateGeneration/EstimateGenerationNormativeSelectionLearningTest.php
git commit -m "feat[lk]: правки пользователя улучшают подбор ФСНБ"
```

---

## Task 12: RAG Source For Learning Examples

**Files:**
- Create: `prohelper/app/BusinessModules/Features/AIAssistant/Services/Rag/Sources/EstimateGenerationLearningRagSource.php`
- Modify: `prohelper/app/BusinessModules/Features/AIAssistant/Services/Rag/RagSourceRegistry.php`
- Modify: `prohelper/app/BusinessModules/Features/AIAssistant/AIAssistantServiceProvider.php`
- Create: `prohelper/tests/Unit/AIAssistant/Rag/EstimateGenerationLearningRagSourceTest.php`

- [ ] **Step 1: Inspect dirty AIAssistant files before editing**

Run:

```powershell
git status --short
git diff -- app/BusinessModules/Features/AIAssistant/AIAssistantServiceProvider.php app/BusinessModules/Features/AIAssistant/Services/Rag/RagPromptContextBuilder.php
```

Expected: understand existing uncommitted RAG source additions and keep them intact.

- [ ] **Step 2: Write RAG source test**

Expected chunk content includes:

- work name;
- work unit and quantity;
- intent scope/action/system;
- selected FSNB code/name/unit;
- decision status;
- source type;
- positive/negative marker;
- compact context.

Expected metadata includes:

- `learning_example_id`
- `source_type`
- `normative_code`
- `work_intent`
- `is_positive`

- [ ] **Step 3: Implement source collector**

`sourceType()` returns `estimate_generation_learning`.

Collector returns only examples:

- belonging to organization;
- positive or explicit negative examples with enough context;
- not older than configured retention when retention is configured;
- not already marked unindexable by quality flags.

- [ ] **Step 4: Register source**

Register alongside existing RAG sources without removing dirty additions in the working tree.

- [ ] **Step 5: Run RAG tests**

```powershell
php artisan test tests/Unit/AIAssistant/Rag/EstimateGenerationLearningRagSourceTest.php tests/Unit/AIAssistant/Rag/RagSourceRegistryTest.php
```

Expected: all tests pass.

- [ ] **Step 6: Commit only relevant files plus compatible dirty registry changes**

```powershell
git add app/BusinessModules/Features/AIAssistant/Services/Rag/Sources/EstimateGenerationLearningRagSource.php app/BusinessModules/Features/AIAssistant/Services/Rag/RagSourceRegistry.php app/BusinessModules/Features/AIAssistant/AIAssistantServiceProvider.php tests/Unit/AIAssistant/Rag/EstimateGenerationLearningRagSourceTest.php tests/Unit/AIAssistant/Rag/RagSourceRegistryTest.php
git commit -m "feat[lk]: примеры смет добавлены в RAG-контур"
```

If the provider/registry already contains unrelated dirty additions, commit only after confirming `git diff --cached` contains this task plus required compatibility edits.

---

## Task 13: Learning Evidence Retrieval For Normative Matching

**Files:**
- Create: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Services/Learning/EstimateGenerationLearningEvidenceService.php`
- Modify: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Normatives/Services/EstimateNormativeMatcher.php`
- Create: `prohelper/tests/Unit/EstimateGeneration/EstimateGenerationLearningEvidenceServiceTest.php`
- Modify: `prohelper/tests/Unit/EstimateGeneration/EstimateNormativeMatcherTest.php`

- [ ] **Step 1: Write evidence retrieval tests**

Cases:

- positive examples for similar work boost same norm code.
- negative examples for same work and norm code penalize candidate.
- examples from same organization outrank global examples.
- evidence never bypasses unit/scope hard gates.

- [ ] **Step 2: Implement SQL lexical retrieval**

Before vector retrieval is available, use deterministic SQL:

- same organization;
- same normalized unit dimension;
- overlapping intent scope/action/system;
- trigram/ILIKE-like lexical work-name overlap;
- exact norm code match gets strongest score.

This is not a substitute for RAG; it gives matcher reliable evidence during normal generation even when embedding jobs lag.

- [ ] **Step 3: Add optional RAG retrieval path**

When `RagRetriever` is available and source type `estimate_generation_learning` is enabled:

- query by compact work item text;
- include top evidence chunks in candidate metadata;
- cap tokens and result count;
- do not call external APIs inside tight loops if embeddings are unavailable.

- [ ] **Step 4: Feed evidence into matcher scoring**

Candidate scoring receives:

- `learning_positive_count`
- `learning_negative_count`
- `learning_score`
- `learning_sources`

Positive evidence can boost ranking. Negative evidence can push candidate below pricing threshold. Neither can override hard gates.

- [ ] **Step 5: Run tests**

```powershell
php artisan test tests/Unit/EstimateGeneration/EstimateGenerationLearningEvidenceServiceTest.php tests/Unit/EstimateGeneration/EstimateNormativeMatcherTest.php
```

Expected: all tests pass.

- [ ] **Step 6: Commit**

```powershell
git add app/BusinessModules/Addons/EstimateGeneration/Services/Learning/EstimateGenerationLearningEvidenceService.php app/BusinessModules/Addons/EstimateGeneration/Normatives/Services/EstimateNormativeMatcher.php tests/Unit/EstimateGeneration/EstimateGenerationLearningEvidenceServiceTest.php tests/Unit/EstimateGeneration/EstimateNormativeMatcherTest.php
git commit -m "feat[lk]: подбор ФСНБ учитывает накопленные примеры"
```

---

## Task 14: Normative Candidate Reranker

**Files:**
- Create: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Services/Normatives/Reranking/NormativeCandidateRerankerInterface.php`
- Create: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Services/Normatives/Reranking/RuleBasedNormativeCandidateReranker.php`
- Create: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Services/Normatives/Reranking/LLMNormativeCandidateReranker.php`
- Create: `prohelper/app/BusinessModules/Addons/EstimateGeneration/DTOs/Normatives/NormativeRerankResultData.php`
- Modify: `prohelper/app/BusinessModules/Addons/EstimateGeneration/EstimateGenerationServiceProvider.php`
- Modify: `prohelper/config/estimate-generation.php`
- Create: `prohelper/tests/Unit/EstimateGeneration/NormativeCandidateRerankerTest.php`

- [ ] **Step 1: Write reranker contract tests**

Rules:

- reranker may select candidate key from provided list;
- reranker may return `none`;
- reranker cannot introduce a norm not in candidates;
- reranker cannot mark a hard-gated candidate as priceable;
- invalid LLM JSON returns rule-based result and records warning.

- [ ] **Step 2: Implement result DTO**

Fields:

- `selectedCandidateKey`
- `confidence`
- `reason`
- `evidenceKeys`
- `warnings`
- `provider`

- [ ] **Step 3: Implement rule-based reranker**

Rank by:

1. hard gate pass;
2. unit dimension;
3. scope compatibility;
4. learning score;
5. lexical score;
6. resources/prices presence.

- [ ] **Step 4: Implement LLM reranker behind config**

Use existing AIAssistant LLM provider interfaces. Prompt sends:

- work item name/unit/quantity;
- intent;
- source facts;
- top candidates with code/name/unit/section/composition;
- learning evidence summaries;
- strict instruction to answer only with candidate key or `none`.

The LLM response is advisory. The decision service remains authoritative.

- [ ] **Step 5: Bind default reranker**

Config:

```php
'normative_matching' => [
    'reranker' => [
        'provider' => env('ESTIMATE_GENERATION_NORM_RERANKER', 'rule_based'),
        'llm_enabled' => (bool) env('ESTIMATE_GENERATION_NORM_RERANKER_LLM_ENABLED', false),
        'max_candidates' => 8,
        'timeout_seconds' => 15,
    ],
],
```

Default is `rule_based`, which is a full deterministic implementation.

- [ ] **Step 6: Run reranker tests**

```powershell
php artisan test tests/Unit/EstimateGeneration/NormativeCandidateRerankerTest.php
```

Expected: all tests pass.

- [ ] **Step 7: Commit**

```powershell
git add config/estimate-generation.php app/BusinessModules/Addons/EstimateGeneration/Services/Normatives/Reranking app/BusinessModules/Addons/EstimateGeneration/DTOs/Normatives/NormativeRerankResultData.php app/BusinessModules/Addons/EstimateGeneration/EstimateGenerationServiceProvider.php tests/Unit/EstimateGeneration/NormativeCandidateRerankerTest.php
git commit -m "feat[lk]: добавлен безопасный reranker норм ФСНБ"
```

---

## Task 15: Generation Flow And Progress UX Contract

**Files:**
- Modify: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Services/EstimateGenerationOrchestrator.php`
- Modify: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Http/Controllers/EstimateGenerationController.php`
- Modify: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Http/Resources/EstimateGenerationSessionResource.php`
- Modify: `prohelper/lang/ru/estimate_generation.php`
- Modify: `prohelper/tests/Feature/EstimateGeneration/EstimateGenerationFlowTest.php`

- [ ] **Step 1: Write backend flow test for "documents ready then auto generate"**

Expected:

- session status does not stay `created` after document readiness when generation is requested;
- `generate()` queues job when ready;
- status endpoint returns stage labels and estimated user action;
- if documents still process, response says generation will start after processing, not that draft is empty.

- [ ] **Step 2: Add progress stage contract**

API status should return:

```php
'progress' => [
    'stage' => 'normative_matching',
    'percent' => 72,
    'title' => trans_message('estimate_generation.stage_normative_matching'),
    'description' => trans_message('estimate_generation.stage_normative_matching_description'),
    'can_close_page' => true,
]
```

Stages:

- `documents_processing`
- `object_analysis`
- `package_planning`
- `work_generation`
- `normative_matching`
- `resource_enrichment`
- `validation_and_normalization`
- `ready_for_review`
- `blocked`
- `failed`

- [ ] **Step 3: Ensure rebuild starts generation when needed**

If session has documents and no draft/packages, "rebuild draft" must call analyze/generate flow instead of leaving the user at `created 0%`.

- [ ] **Step 4: Run flow tests**

```powershell
php artisan test tests/Feature/EstimateGeneration/EstimateGenerationFlowTest.php
```

Expected: all tests pass.

- [ ] **Step 5: Commit**

```powershell
git add app/BusinessModules/Addons/EstimateGeneration/Services/EstimateGenerationOrchestrator.php app/BusinessModules/Addons/EstimateGeneration/Http/Controllers/EstimateGenerationController.php app/BusinessModules/Addons/EstimateGeneration/Http/Resources/EstimateGenerationSessionResource.php lang/ru/estimate_generation.php tests/Feature/EstimateGeneration/EstimateGenerationFlowTest.php
git commit -m "fix[lk]: генерация сметы показывает понятный прогресс"
```

---

## Task 16: Admin UI For Progress, Evidence, And Review

**Files:**
- Modify: `prohelper_admin/src/types/estimateGeneration.ts`
- Modify: `prohelper_admin/src/services/estimateGeneration.ts`
- Modify: `prohelper_admin/src/pages/EstimateGeneration/EstimateGenerationPage.tsx`
- Create: `prohelper_admin/src/pages/EstimateGeneration/components/GenerationProgressDialog.tsx`
- Create: `prohelper_admin/src/pages/EstimateGeneration/components/NormativeEvidencePanel.tsx`
- Create: `prohelper_admin/src/pages/EstimateGeneration/__tests__/EstimateGenerationPage.test.tsx`

- [ ] **Step 1: Write UI tests**

With Vitest/MSW:

- when status is processing, modal is visible in center with progress and "можно закрыть страницу";
- when generation is ready, modal closes and review panel shows selected norms;
- when status is `review_required`, "Применить в смету" is enabled if all priced lines have a selected norm, even when some have review flags;
- when a line has `candidate` without pricing, UI shows candidate selection and keeps save disabled for that specific line reason.

- [ ] **Step 2: Update types**

Add fields:

- `progress.stage`
- `progress.percent`
- `progress.title`
- `progress.description`
- `progress.can_close_page`
- `normative_match.decision.status`
- `normative_match.decision.warnings`
- `normative_match.learning_evidence`
- `quality_summary.normative_items.review_priced`
- `quality_summary.normative_items.candidate_only`

- [ ] **Step 3: Implement progress dialog**

Requirements:

- fixed center modal while status is active;
- progress bar with stable height;
- text says the user can close the page and return later;
- no technical terms in visible text;
- no nested cards;
- buttons/icons from existing icon library.

- [ ] **Step 4: Implement evidence panel**

For each line show:

- selected FSNB code/name/unit;
- status: selected, needs review, no safe norm;
- reasons in human language;
- learning evidence count when present.

- [ ] **Step 5: Run frontend checks**

Do not run build. Run:

```powershell
npx tsc --noEmit
npx vitest run src/pages/EstimateGeneration/__tests__/EstimateGenerationPage.test.tsx
```

Expected: TypeScript and Vitest pass.

- [ ] **Step 6: Commit**

```powershell
git add src/types/estimateGeneration.ts src/services/estimateGeneration.ts src/pages/EstimateGeneration/EstimateGenerationPage.tsx src/pages/EstimateGeneration/components/GenerationProgressDialog.tsx src/pages/EstimateGeneration/components/NormativeEvidencePanel.tsx src/pages/EstimateGeneration/__tests__/EstimateGenerationPage.test.tsx
git commit -m "feat[lk]: улучшен интерфейс проверки ФСНБ в генерации смет"
```

Run these commands from `prohelper_admin` for the UI task.

---

## Task 17: End-To-End Backend Quality Suite

**Files:**
- Modify tests listed above as needed.
- No product code changes unless a test exposes an implementation defect.

- [ ] **Step 1: Run targeted backend suite**

```powershell
php artisan test tests/Unit/EstimateGeneration tests/Feature/EstimateGeneration tests/Unit/AIAssistant/Rag/EstimateGenerationLearningRagSourceTest.php
```

Expected: all tests pass.

- [ ] **Step 2: Run static analysis on touched backend paths**

Use the project PHPStan command if present. If the project uses Larastan config, run:

```powershell
vendor/bin/phpstan analyse app/BusinessModules/Addons/EstimateGeneration app/BusinessModules/Features/AIAssistant/Services/Rag/Sources --memory-limit=1G
```

Expected: no new errors in touched paths.

- [ ] **Step 3: Run syntax check over new PHP files**

```powershell
Get-ChildItem app/BusinessModules/Addons/EstimateGeneration -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
```

Expected: each file reports no syntax errors.

- [ ] **Step 4: Commit verification fixes only if needed**

If verification required changes:

```powershell
git add app tests
git commit -m "fix[lk]: исправлены проверки генерации смет"
```

---

## Task 18: Production Diagnostic Readiness

**Files:**
- Create: `prohelper/docs/estimate-generation/fsnb-first-operational-checklist.md`
- Modify: `prohelper/app/BusinessModules/Addons/EstimateGeneration/Services/EstimateGenerationAuditService.php` if such service exists; otherwise create focused audit writer near existing audit event usage.
- Modify: `prohelper/tests/Feature/EstimateGeneration/EstimateGenerationAuditTest.php`

- [ ] **Step 1: Document production checks**

Checklist must include read-only commands only:

- latest sessions by status;
- count of candidate-only lines;
- count of unit/scope mismatch lines;
- top anomalous line totals;
- learning examples per organization;
- RAG index status for `estimate_generation_learning`.

- [ ] **Step 2: Add audit events for normative decisions**

For each generated package, record compact event:

- count accepted;
- count review priced;
- count candidate only;
- count not found;
- count unit mismatch;
- count scope mismatch;
- max line total.

- [ ] **Step 3: Test audit payload**

Feature test ensures generation stores audit event without sensitive prompt text or secrets.

- [ ] **Step 4: Run tests**

```powershell
php artisan test tests/Feature/EstimateGeneration/EstimateGenerationAuditTest.php
```

Expected: test passes.

- [ ] **Step 5: Commit**

```powershell
git add docs/estimate-generation/fsnb-first-operational-checklist.md app/BusinessModules/Addons/EstimateGeneration tests/Feature/EstimateGeneration/EstimateGenerationAuditTest.php
git commit -m "docs[lk]: добавлен контроль качества FSNB-first генерации"
```

---

## Final Verification

- [ ] **Step 1: Confirm no dangerous candidate can price**

Run:

```powershell
php artisan test tests/Unit/EstimateGeneration/FsnbFirstNormativeSafetyTest.php
```

Expected: all tests pass.

- [ ] **Step 2: Confirm full targeted backend suite**

```powershell
php artisan test tests/Unit/EstimateGeneration tests/Feature/EstimateGeneration
```

Expected: all tests pass.

- [ ] **Step 3: Confirm RAG tests**

```powershell
php artisan test tests/Unit/AIAssistant/Rag
```

Expected: all tests pass or only known unrelated tests fail with documented reason before push.

- [ ] **Step 4: Confirm frontend checks if admin UI was touched**

From `prohelper_admin`:

```powershell
npx tsc --noEmit
npx vitest run src/pages/EstimateGeneration/__tests__/EstimateGenerationPage.test.tsx
```

Expected: TypeScript and Vitest pass.

- [ ] **Step 5: Review staged diff before each commit and before push**

```powershell
git status --short
git diff --cached --stat
git diff --cached
```

Expected: only planned files are staged, unrelated dirty RAG files are not accidentally reverted or swept into an unrelated commit.

- [ ] **Step 6: Push main after all tasks and verification**

```powershell
git status --short
git push origin main
```

Expected: push succeeds.

## Acceptance Criteria

- The three production failure examples cannot be priced automatically.
- Compatible, plausible FSNB norms are still applied automatically.
- Low-confidence but safe norms can be priced with review status.
- Unsafe norms remain visible as candidates without affecting totals.
- Imported estimates and user corrections create learning examples.
- Learning examples are searchable by deterministic retrieval and indexed into RAG.
- Reranker improves ordering but cannot bypass backend hard gates.
- OCR area selection cannot promote terrace/zone/living area to project total when total area exists.
- Admin UI shows active generation progress in a central dialog and explains that the user can return later.
- "Apply to estimate" is blocked only for truly unpriced/unsafe lines, not merely because safe lines need review.
- All touched backend tests pass.
- Frontend TypeScript and targeted Vitest pass when UI is touched.
- Production diagnostic checklist exists and uses read-only checks.
