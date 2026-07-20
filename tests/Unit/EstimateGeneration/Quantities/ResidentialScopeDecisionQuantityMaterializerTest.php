<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\FloorData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\NormalizedBuildingModelData;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantityData;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantitySource;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\ResidentialScopeDecisionQuantityMaterializer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResidentialScopeDecisionQuantityMaterializerTest extends TestCase
{
    #[Test]
    public function preliminary_electric_boiler_decision_produces_traceable_count_and_bounded_power(): void
    {
        $quantities = (new ResidentialScopeDecisionQuantityMaterializer)->materialize([
            'heating_source' => $this->decision('electric_boiler'),
        ], $this->floorArea('180.000000'), $this->model());

        self::assertSame(['heating.unit', 'heating.power_kw'], array_keys($quantities));
        self::assertSame('1.000000', $quantities['heating.unit']->amount);
        self::assertSame('pcs', $quantities['heating.unit']->unit);
        self::assertSame('18.000000', $quantities['heating.power_kw']->amount);
        self::assertSame('kW', $quantities['heating.power_kw']->unit);
        self::assertSame('up_to_0_03_t', $quantities['heating.unit']->formulaInputs['equipment_mass_band']);
        self::assertSame('preliminary', $quantities['heating.unit']->formulaInputs['decision']['status']);
        self::assertSame([], $quantities['heating.unit']->formulaInputs['decision']['evidence_ids']);
        self::assertContains('residential_heat_load_kw_per_m2:0.10', $quantities['heating.power_kw']->assumptions);
        self::assertContains('901', $quantities['heating.power_kw']->evidenceIds);
        self::assertTrue(ResidentialScopeDecisionQuantityMaterializer::owns($quantities['heating.unit']));
    }

    #[Test]
    public function heating_power_is_rounded_and_bounded_by_versioned_policy(): void
    {
        $materializer = new ResidentialScopeDecisionQuantityMaterializer;

        $minimum = $materializer->materialize(
            ['heating_source' => $this->decision('electric_boiler')],
            $this->floorArea('25.000000'),
            $this->model(),
        );
        $maximum = $materializer->materialize(
            ['heating_source' => $this->decision('electric_boiler')],
            $this->floorArea('420.000000'),
            $this->model(),
        );

        self::assertSame('4.000000', $minimum['heating.power_kw']->amount);
        self::assertSame('30.000000', $maximum['heating.power_kw']->amount);
    }

    #[Test]
    public function preliminary_wastewater_decision_uses_five_meter_pp110_route_instead_of_pieces(): void
    {
        foreach (['central_sewer', 'septic'] as $option) {
            $quantities = (new ResidentialScopeDecisionQuantityMaterializer)->materialize([
                'wastewater_destination' => $this->decision($option),
            ], $this->floorArea('180.000000'), $this->model());

            self::assertArrayHasKey('sewerage.outlet_route', $quantities);
            self::assertSame('5.000000', $quantities['sewerage.outlet_route']->amount);
            self::assertSame('m', $quantities['sewerage.outlet_route']->unit);
            self::assertSame('110', $quantities['sewerage.outlet_route']->formulaInputs['pipe_diameter_mm']);
            self::assertContains(
                'preliminary_scope_decision:wastewater_destination='.$option,
                $quantities['sewerage.outlet_route']->assumptions,
            );
        }
    }

    #[Test]
    public function unsupported_or_non_preliminary_decisions_do_not_invent_quantities(): void
    {
        $quantities = (new ResidentialScopeDecisionQuantityMaterializer)->materialize([
            'heating_source' => $this->decision('gas_boiler'),
            'wastewater_destination' => [...$this->decision('central_sewer'), 'status' => 'needs_data'],
        ], $this->floorArea('180.000000'), $this->model());

        self::assertSame([], $quantities);
    }

    #[Test]
    public function preliminary_allowances_never_replace_document_backed_quantities(): void
    {
        $existing = new QuantityData(
            key: 'sewerage.outlet_route',
            unit: 'm',
            amount: '8.500000',
            formulaKey: 'document.takeoff.sewerage_outlet_route',
            formulaVersion: '1.0.0',
            formulaInputs: [],
            source: QuantitySource::Evidenced,
            evidenceIds: ['drawing:sewerage'],
            modelVersion: 'building-model:v1',
        );

        $quantities = (new ResidentialScopeDecisionQuantityMaterializer)->materialize([
            'wastewater_destination' => $this->decision('septic'),
        ], $this->floorArea('180.000000'), $this->model(), [
            'sewerage.outlet_route' => $existing,
        ]);

        self::assertArrayNotHasKey('sewerage.outlet_route', $quantities);
    }

    /** @return array<string, mixed> */
    private function decision(string $option): array
    {
        return [
            'option' => $option,
            'status' => 'preliminary',
            'evidence_ids' => [],
            'confidence' => 0.6,
        ];
    }

    private function floorArea(string $amount): QuantityData
    {
        return new QuantityData(
            key: 'floor_area',
            unit: 'm2',
            amount: $amount,
            formulaKey: 'document.facts.total_floor_area',
            formulaVersion: '1.0.0',
            formulaInputs: [],
            source: QuantitySource::Evidenced,
            evidenceIds: ['901'],
            modelVersion: 'building-model:v1',
        );
    }

    private function model(): NormalizedBuildingModelData
    {
        return new NormalizedBuildingModelData(
            unit: 'm',
            scaleStatus: 'confirmed',
            scaleMetersPerUnit: 1.0,
            floors: [new FloorData(
                key: 'floor-1',
                elevationM: 0.0,
                heightM: 3.0,
                rooms: [],
                walls: [],
                openings: [],
                engineeringElements: [],
                evidenceIds: [184],
                confidence: 0.9,
                geometryCertainty: 'confirmed',
            )],
            assumptions: [],
            modelVersion: 'building-model:v1',
        );
    }
}
