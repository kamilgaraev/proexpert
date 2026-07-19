<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantityData;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantitySource;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatorScopeInferenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\NormativeWorkItemPlannerService;
use App\BusinessModules\Addons\EstimateGeneration\Services\ProjectDocumentNormativeReferenceExtractor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NormativeWorkItemPlannerResidentialScenarioTest extends TestCase
{
    #[Test]
    public function approved_scenario_exposes_direct_takeoff_definitions_without_mandatory_review(): void
    {
        $analysis = [
            'object' => ['object_type' => 'house'],
            'document_context' => ['canonical_building_quantities' => [
                $this->scenarioQuantity('openings.windows', 'm2', '23.136000')->toArray(),
                $this->scenarioQuantity('openings.doors', 'm2', '9.000000')->toArray(),
            ]],
        ];
        $estimate = $this->estimate('openings', 'openings');

        $items = $this->planner()->build($estimate, $estimate['sections'][0], $analysis);
        $byKey = array_column($items, null, 'quantity_formula');

        self::assertSame('23.136', $byKey['openings.windows']['quantity']);
        self::assertSame('9', $byKey['openings.doors']['quantity']);
        self::assertSame('residential_preliminary_scenario', $byKey['openings.windows']['metadata']['quantity_source']);
        self::assertSame(0.55, $byKey['openings.windows']['confidence']);
        self::assertContains('preliminary_quantity_scenario', $byKey['openings.windows']['validation_flags']);
        self::assertNotContains('quantity_review_required', $byKey['openings.windows']['validation_flags']);
    }

    #[Test]
    public function sanitary_scenario_is_allowed_only_with_whitelisted_catalog_identity(): void
    {
        $analysis = [
            'object' => ['object_type' => 'house'],
            'document_context' => ['canonical_building_quantities' => [
                $this->scenarioQuantity('sanitary.points', 'pcs', '2.000000')->toArray(),
            ]],
        ];
        $estimate = $this->estimate('plumbing', 'plumbing');

        $items = $this->planner()->build($estimate, $estimate['sections'][0], $analysis);

        self::assertContains('sanitary.points', array_column($items, 'quantity_formula'));
    }

    #[Test]
    public function residential_electrical_scenario_does_not_create_cable_trays(): void
    {
        $analysis = [
            'object' => ['object_type' => 'house'],
            'document_context' => ['canonical_building_quantities' => [
                $this->scenarioQuantity('electrical.main_cable', 'm', '77.120000')->toArray(),
                $this->scenarioQuantity('electrical.power_lines', 'm', '154.240000')->toArray(),
                $this->scenarioQuantity('electrical.grounding', 'm', '42.576000')->toArray(),
            ]],
        ];
        $estimate = $this->estimate('electrical', 'electrical');

        $items = $this->planner()->build($estimate, $estimate['sections'][0], $analysis);

        self::assertNotContains('electrical.trays', array_column($items, 'quantity_formula'));
        self::assertContains('electrical.main_cable', array_column($items, 'quantity_formula'));
        self::assertContains('electrical.power_lines', array_column($items, 'quantity_formula'));
        self::assertContains('electrical.grounding', array_column($items, 'quantity_formula'));
    }

    #[Test]
    public function unknown_roof_type_uses_only_a_generic_roof_definition(): void
    {
        $analysis = [
            'object' => ['object_type' => 'house'],
            'document_context' => ['canonical_building_quantities' => [
                $this->scenarioQuantity('roof.area', 'm2', '113.300000')->toArray(),
            ]],
        ];
        $estimate = $this->estimate('roof', 'roof');

        $items = $this->planner()->build($estimate, $estimate['sections'][0], $analysis);

        self::assertSame(['roof.area'], array_column($items, 'quantity_formula'));
        self::assertStringNotContainsString('скатн', mb_strtolower((string) $items[0]['name']));
        self::assertStringNotContainsString('стропил', mb_strtolower((string) $items[0]['name']));
    }

    #[Test]
    public function flat_roof_type_uses_only_flat_roof_quantities(): void
    {
        $analysis = [
            'object' => ['object_type' => 'house', 'roof_type' => 'flat'],
            'document_context' => ['canonical_building_quantities' => [
                $this->scenarioQuantity('roof.flat_area', 'm2', '113.300000')->toArray(),
            ]],
        ];
        $estimate = $this->estimate('roof', 'roof');

        $items = $this->planner()->build($estimate, $estimate['sections'][0], $analysis);

        self::assertNotSame([], $items);
        self::assertSame(['roof.flat_area'], array_values(array_unique(array_column($items, 'quantity_formula'))));
    }

    #[Test]
    public function pitched_roof_type_uses_pitched_roof_quantities(): void
    {
        $analysis = [
            'object' => ['object_type' => 'house', 'roof_type' => 'pitched'],
            'document_context' => ['canonical_building_quantities' => [
                $this->scenarioQuantity('roof.area', 'm2', '152.955000')->toArray(),
            ]],
        ];
        $estimate = $this->estimate('roof', 'roof');

        $items = $this->planner()->build($estimate, $estimate['sections'][0], $analysis);

        self::assertNotSame([], $items);
        self::assertSame(['roof.area'], array_values(array_unique(array_column($items, 'quantity_formula'))));
        self::assertStringContainsString('кровл', mb_strtolower((string) $items[0]['name']));
    }

    private function scenarioQuantity(string $key, string $unit, string $amount): QuantityData
    {
        return new QuantityData(
            key: $key,
            unit: $unit,
            amount: $amount,
            formulaKey: 'residential_preliminary.'.$key,
            formulaVersion: '1.0.0',
            formulaInputs: ['scenario' => [
                'id' => 'residential_preliminary_scenario:v1',
                'version' => '1.0.0',
                'confidence' => 0.55,
                'warnings' => ['preliminary_quantity_scenario'],
            ]],
            source: QuantitySource::Estimated,
            evidenceIds: ['room:1'],
            modelVersion: 'building-model:v1',
            assumptions: ['residential_preliminary_scenario:v1'],
        );
    }

    private function estimate(string $key, string $scope): array
    {
        return [
            'key' => $key,
            'title' => $key,
            'scope_type' => $scope,
            'source_refs' => [],
            'sections' => [[
                'key' => $key.'-section',
                'title' => $key,
                'construction_part' => $scope,
                'source_refs' => [],
            ]],
        ];
    }

    private function planner(): NormativeWorkItemPlannerService
    {
        return new NormativeWorkItemPlannerService(
            new ProjectDocumentNormativeReferenceExtractor,
            new EstimatorScopeInferenceService,
        );
    }
}
