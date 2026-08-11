<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Planning;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Planning\CompletenessRuleCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Planning\OrganizationPreferenceContext;
use App\BusinessModules\Addons\EstimateGeneration\Planning\ProjectCompletenessAnalyzer;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyRecommendationService;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologySystemCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyWorkPackageBuilder;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ProjectCompletenessAnalyzerTest extends TestCase
{
    private const SOURCE_VERSION = 'sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function test_all_mandatory_scenarios_are_classified_with_evidence_and_versioned_rules(): void
    {
        $result = $this->analyzer()->analyze(new ProjectModelSnapshot([], [
            $this->fact('site_work', true),
            $this->fact('foundation_type', 'slab'),
            $this->fact('below_grade_structure', true),
            $this->fact('work_height_m', '6', 'm'),
            $this->fact('roof_type', 'pitched'),
            $this->fact('demolition_or_waste_generation', true),
            $this->fact('engineering_system', 'water_supply'),
            $this->fact('external_site_disturbance', true),
        ], [], []), [$this->roofRecommendation()]);

        self::assertSame([
            'base_preparation', 'fasteners', 'landscaping_restoration', 'scaffolding',
            'site_leveling', 'system_testing', 'waste_removal', 'waterproofing',
        ], $this->ids($result->findings));
        $classifications = array_values(array_unique(array_column($result->toArray()['findings'], 'classification')));
        sort($classifications, SORT_STRING);
        self::assertSame(['document_missing', 'optional_recommendation', 'technology_required'], $classifications);
        foreach ($result->findings as $finding) {
            self::assertNotEmpty($finding->ruleVersion);
            self::assertNotEmpty($finding->ruleHash);
            self::assertNotEmpty($finding->evidenceFactIds);
            self::assertNotEmpty($finding->relatedEntityIds);
            self::assertNotEmpty($finding->impact);
            self::assertNotEmpty($finding->exclusionPolicy);
        }
    }

    public function test_unknown_proven_missing_not_applicable_and_explicit_decision_exclusion_are_distinct(): void
    {
        $projection = [
            'source_version' => self::SOURCE_VERSION,
            'input_fingerprint' => str_repeat('b', 64),
            'catalog_version' => '2026.08.11-v1',
            'catalog_hash' => str_repeat('c', 64),
            'rule_catalog_version' => '2026.08.11-v1',
            'rule_catalog_hash' => CompletenessRuleCatalog::fromArray(
                require dirname(__DIR__, 4).'/config/estimate-generation-completeness-rules.php',
            )->contentHash,
        ];
        $baseline = $this->analyzer()->analyze(new ProjectModelSnapshot([], [
            $this->fact('foundation_type', 'slab'),
            $this->fact('foundation_base_preparation', false),
        ], [], []), [], [], $projection)->finding('base_preparation');
        self::assertNotNull($baseline);
        $exclusion = $this->fact('completeness_exclusion', $baseline->exclusionValue(
            $projection,
            'decision:exclude-base',
            '7',
            'Основание выполнено ранее',
        ), origin: 'user_assumption');
        $result = $this->analyzer()->analyze(new ProjectModelSnapshot([], [
            $this->fact('foundation_type', 'slab'),
            $this->fact('foundation_base_preparation', false),
            $exclusion,
        ], [], []), [], [new Decision(
            'decision:exclude-base', 10, 20, 30, self::SOURCE_VERSION, 'fact', 'fact:base-preparation',
            $exclusion->id, 'user', '7', 'Основание выполнено ранее', 1,
        )], $projection);

        $base = $result->finding('base_preparation');
        self::assertSame('excluded', $base?->status);
        self::assertSame('decision:exclude-base', $base?->exclusionDecision['decision_id'] ?? null);
        self::assertSame('technology_conditional', $result->finding('scaffolding')?->classification);
        self::assertSame('unknown', $result->finding('scaffolding')?->status);

        $staleProjection = $projection;
        $staleProjection['input_fingerprint'] = str_repeat('d', 64);
        $stale = $this->analyzer()->analyze(new ProjectModelSnapshot([], [
            $this->fact('foundation_type', 'slab'),
            $this->fact('foundation_base_preparation', false),
            $exclusion,
        ], [], []), [], [new Decision(
            'decision:exclude-base', 10, 20, 30, self::SOURCE_VERSION, 'fact', 'fact:base-preparation',
            $exclusion->id, 'user', '7', 'Основание выполнено ранее', 1,
        )], $staleProjection);
        self::assertSame('excluded', $stale->finding('base_preparation')?->status);

        $updatedRules = require dirname(__DIR__, 4).'/config/estimate-generation-completeness-rules.php';
        $updatedRules['rules'][1]['version'] = '1.0.1';
        $updatedAnalyzer = new ProjectCompletenessAnalyzer(
            CompletenessRuleCatalog::fromArray($updatedRules), $this->builder(), 50, 50, 200,
        );
        $updated = $updatedAnalyzer->analyze(new ProjectModelSnapshot([], [
            $this->fact('foundation_type', 'slab'),
            $this->fact('foundation_base_preparation', false),
            $exclusion,
        ], [], []), [], [new Decision(
            'decision:exclude-base', 10, 20, 30, self::SOURCE_VERSION, 'fact', 'fact:base-preparation',
            $exclusion->id, 'user', '7', 'Основание выполнено ранее', 1,
        )], $projection);
        self::assertSame('proven_missing', $updated->finding('base_preparation')?->status);

        $unverified = $this->analyzer()->analyze(new ProjectModelSnapshot([], [
            $this->fact('foundation_type', 'slab'),
            $exclusion,
        ], [], []), [$this->roofRecommendation()]);
        self::assertSame('unknown', $unverified->finding('base_preparation')?->status);

        $withoutExclusion = $this->analyzer()->analyze(new ProjectModelSnapshot([], [
            $this->fact('foundation_type', 'slab'),
            $this->fact('foundation_base_preparation', false),
        ], [], []), [$this->roofRecommendation()]);
        self::assertSame('proven_missing', $withoutExclusion->finding('base_preparation')?->status);

        $unknown = $this->analyzer()->analyze(new ProjectModelSnapshot([], [
            $this->fact('foundation_type', 'slab'),
            $this->fact('below_grade_structure', false),
        ], [], []), [$this->roofRecommendation()]);
        self::assertSame('unknown', $unknown->finding('base_preparation')?->status);
        self::assertSame('not_applicable', $unknown->finding('waterproofing')?->status);
    }

    public function test_work_packages_are_complete_deduplicated_and_never_guess_quantities(): void
    {
        $result = $this->analyzer()->analyze(new ProjectModelSnapshot([], [
            $this->fact('roof_type', 'pitched'),
            $this->fact('roof_area', '120.50', 'm2'),
        ], [], []), [$this->roofRecommendation()]);
        $package = $result->finding('fasteners')?->workPackage;

        self::assertNotNull($package);
        self::assertNotEmpty($package->works);
        self::assertNotEmpty($package->materials);
        self::assertNotEmpty($package->machinery);
        self::assertNotEmpty($package->normIntents);
        self::assertNotEmpty($package->dependencies);
        self::assertNotEmpty($package->regionalPriceAvailability);
        self::assertFalse($package->regionalPriceAvailability['available']);
        self::assertNotEmpty($package->risks);
        self::assertNotEmpty($package->assumptions);
        self::assertNotEmpty($package->provenance);
        self::assertSame(count($package->works), count(array_unique(array_column($package->works, 'id'))));
        foreach ($package->quantityFormulas as $formula) {
            self::assertTrue(
                isset($formula['resolved_value'], $formula['operands']) || ! empty($formula['unresolved_inputs']),
                'Quantity must be proven by operands or explicitly unresolved.',
            );
            self::assertArrayNotHasKey('average', $formula);
        }
        self::assertSame('fact:'.hash('sha256', 'roof_area'.json_encode('120.50')), $package->quantityFormulas[0]['operands'][0]['fact_id']);
        self::assertSame('120.50', $package->quantityFormulas[0]['operands'][0]['value']);
    }

    public function test_missing_quantity_inputs_are_explicit_and_dependency_cycles_fail_closed(): void
    {
        $result = $this->analyzer()->analyze(new ProjectModelSnapshot([], [
            $this->fact('roof_type', 'pitched'),
        ], [], []), [$this->roofRecommendation()]);
        self::assertSame(['roof_area'], $result->finding('fasteners')?->workPackage?->quantityFormulas[0]['unresolved_inputs']);

        $data = require dirname(__DIR__, 4).'/config/estimate-generation-completeness-rules.php';
        $data['rules'][0]['work_package']['dependencies'] = [
            ['from' => 'work:a', 'to' => 'work:b'],
            ['from' => 'work:b', 'to' => 'work:a'],
        ];
        $data['rules'][0]['work_package']['works'] = [
            ['id' => 'work:a', 'name_key' => 'estimate_generation.planning.completeness.site_leveling.prepare'],
            ['id' => 'work:b', 'name_key' => 'estimate_generation.planning.completeness.site_leveling.execute'],
        ];
        $catalog = CompletenessRuleCatalog::fromArray($data);

        $this->expectException(InvalidArgumentException::class);
        $this->builder()->build($catalog->rules()[0], []);
    }

    public function test_global_budgets_fail_closed_without_losing_existing_facts(): void
    {
        $analyzer = new ProjectCompletenessAnalyzer(
            CompletenessRuleCatalog::fromArray(require dirname(__DIR__, 4).'/config/estimate-generation-completeness-rules.php'),
            $this->builder(),
            maxFindings: 2,
            maxPackages: 2,
            maxEvidence: 16,
        );
        $result = $analyzer->analyze(new ProjectModelSnapshot([], [
            $this->fact('foundation_type', 'slab'),
            $this->fact('below_grade_structure', true),
            $this->fact('roof_type', 'pitched'),
        ], [], []), []);

        self::assertCount(2, $result->findings);
        self::assertContains('completeness_finding_budget_reached', $result->limitations);
    }

    public function test_rule_catalog_rejects_unknown_entries_and_bounds_payloads(): void
    {
        $data = require dirname(__DIR__, 4).'/config/estimate-generation-completeness-rules.php';
        foreach ($data['rules'] as $rule) {
            self::assertLessThanOrEqual(40, count($rule['work_package']['works']));
            self::assertLessThanOrEqual(40, count($rule['work_package']['materials']));
            self::assertLessThanOrEqual(20, count($rule['work_package']['norm_intents']));
        }
        $data['rules'][0]['classification'] = 'unknown_classification';

        $this->expectException(InvalidArgumentException::class);
        CompletenessRuleCatalog::fromArray($data);
    }

    public function test_rule_hash_is_permutation_stable_for_dag_edges_and_tracks_meaningful_changes(): void
    {
        $data = require dirname(__DIR__, 4).'/config/estimate-generation-completeness-rules.php';
        $base = CompletenessRuleCatalog::fromArray($data);
        $permuted = $data;
        $permuted['rules'] = array_reverse($permuted['rules']);
        self::assertSame($base->contentHash, CompletenessRuleCatalog::fromArray($permuted)->contentHash);

        $changed = $data;
        $changed['rules'][3]['conditions'][0]['value'] = 4;
        self::assertNotSame($base->contentHash, CompletenessRuleCatalog::fromArray($changed)->contentHash);

        $dependencyOrder = $data;
        $dependencyOrder['rules'][0]['work_package']['works'][] = [
            'id' => 'work:site_leveling:finish',
            'name_key' => 'estimate_generation.planning.completeness.site_leveling.execute',
        ];
        $dependencyOrder['rules'][0]['work_package']['dependencies'][] = [
            'from' => 'work:site_leveling:execute', 'to' => 'work:site_leveling:finish',
        ];
        $orderedHash = CompletenessRuleCatalog::fromArray($dependencyOrder)->contentHash;
        $dependencyOrder['rules'][0]['work_package']['dependencies'] = array_reverse(
            $dependencyOrder['rules'][0]['work_package']['dependencies'],
        );
        self::assertSame($orderedHash, CompletenessRuleCatalog::fromArray($dependencyOrder)->contentHash);
    }

    public function test_rule_catalog_rejects_incomplete_or_mistyped_runtime_contracts(): void
    {
        $base = require dirname(__DIR__, 4).'/config/estimate-generation-completeness-rules.php';
        $invalid = [];
        foreach (['fact_type', 'operator', 'false_means_missing'] as $field) {
            $candidate = $base;
            unset($candidate['rules'][0]['satisfaction'][$field]);
            $invalid[] = $candidate;
        }
        $unknownSatisfaction = $base;
        $unknownSatisfaction['rules'][0]['satisfaction']['unexpected'] = true;
        $invalid[] = $unknownSatisfaction;
        $badTechnologyRequirement = $base;
        $badTechnologyRequirement['rules'][4]['technology_requirement']['allow_recommended_applicable'] = 'yes';
        $invalid[] = $badTechnologyRequirement;
        $unknownTechnologyRequirement = $base;
        $unknownTechnologyRequirement['rules'][4]['technology_requirement']['unexpected'] = true;
        $invalid[] = $unknownTechnologyRequirement;
        $badFactType = $base;
        $badFactType['rules'][0]['conditions'][0]['fact_type'] = 123;
        $invalid[] = $badFactType;
        $unknownUnit = $base;
        $unknownUnit['rules'][3]['conditions'][0]['unit'] = 'unknown';
        $invalid[] = $unknownUnit;
        $nullUnit = $base;
        $nullUnit['rules'][3]['conditions'][0]['unit'] = null;
        $invalid[] = $nullUnit;
        $wrongOperandShape = $base;
        $wrongOperandShape['rules'][3]['conditions'][0]['values'] = [3];
        $invalid[] = $wrongOperandShape;
        $wrongValuesType = $base;
        $wrongValuesType['rules'][0]['conditions'][0]['value'] = ['yes'];
        $invalid[] = $wrongValuesType;
        $unknownPackageField = $base;
        $unknownPackageField['rules'][0]['work_package']['regional_price_availability']['unexpected'] = true;
        $invalid[] = $unknownPackageField;
        $nonListRules = $base;
        $nonListRules['rules'] = ['rule' => $nonListRules['rules'][0]];
        $invalid[] = $nonListRules;
        $badApplicability = $base;
        $badApplicability['rules'][0]['applicability_fact_types'] = 'foundation_type';
        $invalid[] = $badApplicability;
        $badSeverity = $base;
        $badSeverity['rules'][0]['severity'] = 3;
        $invalid[] = $badSeverity;
        $unpairedVariants = $base;
        $unpairedVariants['rules'][0]['work_package']['variant_fact_type'] = 'foundation_type';
        $invalid[] = $unpairedVariants;
        $badCandidateReference = $base;
        $badCandidateReference['rules'][0]['work_package']['norm_intents'][0]['candidate_refs'] = [123];
        $invalid[] = $badCandidateReference;

        foreach ($invalid as $data) {
            try {
                CompletenessRuleCatalog::fromArray($data);
                self::fail('Incomplete or mistyped completeness catalog was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_typed_conditions_distinguish_flat_roof_low_height_null_zero_and_false(): void
    {
        $result = $this->analyzer()->analyze(new ProjectModelSnapshot([], [
            $this->fact('roof_type', 'flat'),
            $this->fact('work_height_m', 1, 'm'),
            $this->fact('foundation_type', 'slab'),
            $this->fact('foundation_base_preparation', null),
        ], [], []), []);

        self::assertSame('not_applicable', $result->finding('fasteners')?->status);
        self::assertSame('not_applicable', $result->finding('fasteners')?->applicability['status'] ?? null);
        self::assertNotEmpty($result->finding('fasteners')?->applicability['evidence_fact_ids'] ?? []);
        self::assertSame('not_applicable', $result->finding('scaffolding')?->status);
        self::assertSame('unknown', $result->finding('base_preparation')?->status);

        foreach ([0, '0'] as $value) {
            $satisfied = $this->analyzer()->analyze(new ProjectModelSnapshot([], [
                $this->fact('foundation_type', 'slab'),
                $this->fact('foundation_base_preparation', $value),
            ], [], []), []);
            self::assertSame('satisfied', $satisfied->finding('base_preparation')?->status);
        }
        $missing = $this->analyzer()->analyze(new ProjectModelSnapshot([], [
            $this->fact('foundation_type', 'slab'),
            $this->fact('foundation_base_preparation', false),
        ], [], []), []);
        self::assertSame('proven_missing', $missing->finding('base_preparation')?->status);
    }

    public function test_foundation_types_select_distinct_work_packages(): void
    {
        $ids = [];
        foreach (['slab', 'strip', 'pile', 'columnar'] as $type) {
            $result = $this->analyzer()->analyze(new ProjectModelSnapshot([], [
                $this->fact('foundation_type', $type),
            ], [], []), []);
            $ids[] = $result->finding('base_preparation')?->workPackage?->id;
        }

        self::assertSame([
            'package:base_preparation_slab',
            'package:base_preparation_strip',
            'package:base_preparation_pile',
            'package:base_preparation_columnar',
        ], $ids);
    }

    public function test_conditional_or_missing_recommendation_never_creates_required_fastener_package(): void
    {
        $snapshot = new ProjectModelSnapshot([], [$this->fact('roof_type', 'pitched')], [], []);
        $without = $this->analyzer()->analyze($snapshot, []);
        $conditional = $this->analyzer()->analyze($snapshot, [$this->roofRecommendation(includeSlope: false)]);

        self::assertSame('technology_conditional', $without->finding('fasteners')?->classification);
        self::assertSame('unresolved', $without->finding('fasteners')?->status);
        self::assertNull($without->finding('fasteners')?->workPackage);
        self::assertSame('unresolved', $conditional->finding('fasteners')?->status);
        self::assertNull($conditional->finding('fasteners')?->workPackage);
    }

    public function test_confirmed_selected_system_enables_technology_rule_without_a_recommendation(): void
    {
        $selected = $this->fact('roof_covering_system', [
            'kind' => 'catalog_system',
            'system_id' => 'pitched_roof.standing_seam',
            'decision_key' => 'roof_covering_system.1234567890abcdef12345678',
        ], origin: 'user_assumption');
        $result = $this->analyzer()->analyze(new ProjectModelSnapshot([], [
            $this->fact('roof_type', 'pitched'),
            $selected,
        ], [], []), []);

        self::assertSame('unknown', $result->finding('fasteners')?->status);
        self::assertNotNull($result->finding('fasteners')?->workPackage);
    }

    public function test_user_assumption_does_not_hide_a_missing_required_document(): void
    {
        $result = $this->analyzer()->analyze(new ProjectModelSnapshot([], [
            $this->fact('engineering_system', 'heating'),
            $this->fact('test_program', true, origin: 'user_assumption'),
        ], [], []), []);

        self::assertSame('document_missing', $result->finding('system_testing')?->classification);
        self::assertSame('unknown', $result->finding('system_testing')?->status);
    }

    private function analyzer(): ProjectCompletenessAnalyzer
    {
        return new ProjectCompletenessAnalyzer(
            CompletenessRuleCatalog::fromArray(require dirname(__DIR__, 4).'/config/estimate-generation-completeness-rules.php'),
            $this->builder(),
            maxFindings: 50,
            maxPackages: 50,
            maxEvidence: 200,
        );
    }

    private function roofRecommendation(bool $includeSlope = true): \App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyRecommendation
    {
        $facts = [$this->fact('roof_type', 'pitched')];
        if ($includeSlope) {
            $facts[] = $this->fact('roof_slope_degrees', '20', 'degree');
        }
        $target = new Fact(
            'fact:roof-target', 10, 20, 30, self::SOURCE_VERSION, 'entity:project',
            'roof_covering_system', null, null, 0, 'unresolved', 'unresolved', [],
        );

        return (new TechnologyRecommendationService(
            TechnologySystemCatalog::fromArray(require dirname(__DIR__, 4).'/config/estimate-generation-technology-systems.php'),
            static fn (string $key): string => $key,
        ))->recommend(new ProjectModelSnapshot([], $facts, [], []), $target, new OrganizationPreferenceContext(10, []));
    }

    private function builder(): TechnologyWorkPackageBuilder
    {
        return new TechnologyWorkPackageBuilder(static fn (string $key): string => 'Человекочитаемое название');
    }

    private function fact(string $type, mixed $value, ?string $unit = null, string $origin = 'document'): Fact
    {
        return new Fact(
            id: 'fact:'.hash('sha256', $type.json_encode($value)), organizationId: 10, projectId: 20, sessionId: 30,
            sourceVersion: self::SOURCE_VERSION, entityId: 'entity:project', type: $type, value: $value,
            unit: $unit, confidence: 1.0, origin: $origin, status: 'confirmed',
            evidenceIds: $origin === 'user_assumption' ? [] : ['evidence:'.$type],
        );
    }

    private function ids(array $findings): array
    {
        $ids = array_map(static fn ($finding): string => $finding->ruleId, $findings);
        sort($ids, SORT_STRING);

        return $ids;
    }
}
