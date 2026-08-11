<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Planning;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Planning\CompletenessRuleCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Planning\ProjectCompletenessAnalyzer;
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
        ], [], []), []);

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
        $exclusion = $this->fact('completeness_exclusion', [
            'rule_id' => 'base_preparation',
            'decision_id' => 'decision:exclude-base',
            'actor' => '7',
            'reason' => 'Основание выполнено ранее',
        ], origin: 'user_assumption');
        $result = $this->analyzer()->analyze(new ProjectModelSnapshot([], [
            $this->fact('foundation_type', 'slab'),
            $this->fact('foundation_base_preparation', false),
            $exclusion,
        ], [], []), [], [new Decision(
            'decision:exclude-base', 10, 20, 30, self::SOURCE_VERSION, 'fact', 'fact:base-preparation',
            $exclusion->id, 'user', '7', 'Основание выполнено ранее', 1,
        )]);

        $base = $result->finding('base_preparation');
        self::assertSame('excluded', $base?->status);
        self::assertSame('decision:exclude-base', $base?->exclusionDecision['decision_id'] ?? null);
        self::assertSame('not_applicable', $result->finding('scaffolding')?->classification);
        self::assertSame('not_applicable', $result->finding('scaffolding')?->status);

        $unverified = $this->analyzer()->analyze(new ProjectModelSnapshot([], [
            $this->fact('foundation_type', 'slab'),
            $exclusion,
        ], [], []), []);
        self::assertSame('unknown', $unverified->finding('base_preparation')?->status);

        $withoutExclusion = $this->analyzer()->analyze(new ProjectModelSnapshot([], [
            $this->fact('foundation_type', 'slab'),
            $this->fact('foundation_base_preparation', false),
        ], [], []), []);
        self::assertSame('proven_missing', $withoutExclusion->finding('base_preparation')?->status);

        $unknown = $this->analyzer()->analyze(new ProjectModelSnapshot([], [
            $this->fact('foundation_type', 'slab'),
            $this->fact('below_grade_structure', false),
        ], [], []), []);
        self::assertSame('unknown', $unknown->finding('base_preparation')?->status);
        self::assertSame('not_applicable', $unknown->finding('waterproofing')?->status);
    }

    public function test_work_packages_are_complete_deduplicated_and_never_guess_quantities(): void
    {
        $result = $this->analyzer()->analyze(new ProjectModelSnapshot([], [
            $this->fact('roof_type', 'pitched'),
            $this->fact('roof_area', '120.50', 'm2'),
        ], [], []), []);
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
        ], [], []), []);
        self::assertSame(['roof_area'], $result->finding('fasteners')?->workPackage?->quantityFormulas[0]['unresolved_inputs']);

        $data = require dirname(__DIR__, 4).'/config/estimate-generation-completeness-rules.php';
        $data['rules'][0]['work_package']['dependencies'] = [
            ['from' => 'work:a', 'to' => 'work:b'],
            ['from' => 'work:b', 'to' => 'work:a'],
        ];
        $data['rules'][0]['work_package']['works'] = [['id' => 'work:a'], ['id' => 'work:b']];
        $catalog = CompletenessRuleCatalog::fromArray($data);

        $this->expectException(InvalidArgumentException::class);
        (new TechnologyWorkPackageBuilder)->build($catalog->rules()[0], []);
    }

    public function test_global_budgets_fail_closed_without_losing_existing_facts(): void
    {
        $analyzer = new ProjectCompletenessAnalyzer(
            CompletenessRuleCatalog::fromArray(require dirname(__DIR__, 4).'/config/estimate-generation-completeness-rules.php'),
            new TechnologyWorkPackageBuilder,
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

    private function analyzer(): ProjectCompletenessAnalyzer
    {
        return new ProjectCompletenessAnalyzer(
            CompletenessRuleCatalog::fromArray(require dirname(__DIR__, 4).'/config/estimate-generation-completeness-rules.php'),
            new TechnologyWorkPackageBuilder,
            maxFindings: 50,
            maxPackages: 50,
            maxEvidence: 200,
        );
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
