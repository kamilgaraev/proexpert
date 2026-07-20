<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\NormalizedBuildingModelData;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class ResidentialScopeDecisionQuantityMaterializer
{
    public const VERSION = '1.0.0';

    public const SCENARIO_ID = 'residential_scope_decision_allowance:v1';

    private const HEAT_LOAD_KW_PER_M2 = '0.10';

    private const MINIMUM_HEATING_POWER_KW = '4';

    private const MAXIMUM_HEATING_POWER_KW = '30';

    private const SEWER_OUTLET_ROUTE_M = '5';

    /**
     * @param  array<string, array<string, mixed>>|list<array<string, mixed>>  $decisions
     * @return array<string, QuantityData>
     */
    public function materialize(
        array $decisions,
        ?QuantityData $floorArea,
        NormalizedBuildingModelData $model,
        array $existingQuantities = [],
    ): array {
        $indexed = $this->indexedPreliminaryDecisions($decisions);
        $quantities = [];

        if (($indexed['heating_source']['option'] ?? null) === 'electric_boiler' && $floorArea !== null) {
            $decision = $indexed['heating_source'];
            $power = $this->heatingPower($floorArea->amount);
            $evidenceIds = $this->evidenceIds($decision, $floorArea, $model);
            $commonInputs = $this->formulaInputs($decision, $floorArea, $model);

            if ($this->canMaterialize('heating.unit', $existingQuantities)) {
                $quantities['heating.unit'] = $this->quantity(
                    key: 'heating.unit',
                    unit: 'pcs',
                    amount: '1.000000',
                    formulaKey: 'residential.scope_decision.electric_boiler_count',
                    formulaInputs: [
                        ...$commonInputs,
                        'boiler_count' => '1',
                        'equipment_mass_band' => 'up_to_0_03_t',
                    ],
                    evidenceIds: $evidenceIds,
                    model: $model,
                    assumptions: [
                        'preliminary_scope_decision:heating_source=electric_boiler',
                        'one_electric_boiler_per_residential_house',
                        'electric_boiler_installation_analog_mass_band:up_to_0_03_t',
                    ],
                );
            }
            if ($this->canMaterialize('heating.power_kw', $existingQuantities)) {
                $quantities['heating.power_kw'] = $this->quantity(
                    key: 'heating.power_kw',
                    unit: 'kW',
                    amount: $power,
                    formulaKey: 'residential.scope_decision.heating_power',
                    formulaInputs: [
                        ...$commonInputs,
                        'heat_load_kw_per_m2' => self::HEAT_LOAD_KW_PER_M2,
                        'minimum_power_kw' => self::MINIMUM_HEATING_POWER_KW,
                        'maximum_power_kw' => self::MAXIMUM_HEATING_POWER_KW,
                        'rounding' => 'whole_kw_half_up',
                    ],
                    evidenceIds: $evidenceIds,
                    model: $model,
                    assumptions: [
                        'preliminary_scope_decision:heating_source=electric_boiler',
                        'residential_heat_load_kw_per_m2:'.self::HEAT_LOAD_KW_PER_M2,
                        'residential_heating_power_bounds_kw:'.self::MINIMUM_HEATING_POWER_KW.'-'.self::MAXIMUM_HEATING_POWER_KW,
                    ],
                );
            }
        }

        if (in_array($indexed['wastewater_destination']['option'] ?? null, ['central_sewer', 'septic'], true)
            && $this->canMaterialize('sewerage.outlet_route', $existingQuantities)) {
            $decision = $indexed['wastewater_destination'];
            $quantities['sewerage.outlet_route'] = $this->quantity(
                key: 'sewerage.outlet_route',
                unit: 'm',
                amount: '5.000000',
                formulaKey: 'residential.scope_decision.sewer_outlet_route_allowance',
                formulaInputs: [
                    ...$this->formulaInputs($decision, $floorArea, $model),
                    'route_allowance_m' => self::SEWER_OUTLET_ROUTE_M,
                    'pipe_material' => 'polypropylene',
                    'pipe_diameter_mm' => '110',
                ],
                evidenceIds: $this->evidenceIds($decision, $floorArea, $model),
                model: $model,
                assumptions: [
                    'preliminary_scope_decision:wastewater_destination='.$decision['option'],
                    'residential_sewer_outlet_route_allowance_m:'.self::SEWER_OUTLET_ROUTE_M,
                    'residential_sewer_outlet_pipe:polypropylene_110mm',
                ],
            );
        }

        return $quantities;
    }

    /** @param array<string, QuantityData> $existingQuantities */
    private function canMaterialize(string $key, array $existingQuantities): bool
    {
        $existing = $existingQuantities[$key] ?? null;

        return ! $existing instanceof QuantityData || self::owns($existing);
    }

    public static function owns(QuantityData $quantity): bool
    {
        return $quantity->formulaVersion === self::VERSION
            && in_array(self::SCENARIO_ID, $quantity->assumptions, true);
    }

    /** @param array<string, array<string, mixed>>|list<array<string, mixed>> $decisions @return array<string, array<string, mixed>> */
    private function indexedPreliminaryDecisions(array $decisions): array
    {
        $indexed = [];

        foreach ($decisions as $decisionKey => $decision) {
            $key = is_array($decision) && is_string($decision['key'] ?? null)
                ? $decision['key']
                : (is_string($decisionKey) ? $decisionKey : null);
            if (! is_array($decision)
                || ($decision['status'] ?? null) !== 'preliminary'
                || $key === null
                || isset($indexed[$key])) {
                continue;
            }

            $indexed[$key] = ['key' => $key, ...$decision];
        }

        return $indexed;
    }

    private function heatingPower(string $floorArea): string
    {
        $power = BigDecimal::of($floorArea)->multipliedBy(self::HEAT_LOAD_KW_PER_M2);
        $bounded = $power->isLessThan(BigDecimal::of(self::MINIMUM_HEATING_POWER_KW))
            ? BigDecimal::of(self::MINIMUM_HEATING_POWER_KW)
            : ($power->isGreaterThan(BigDecimal::of(self::MAXIMUM_HEATING_POWER_KW))
                ? BigDecimal::of(self::MAXIMUM_HEATING_POWER_KW)
                : $power);

        return (string) $bounded->toScale(0, RoundingMode::HalfUp)->toScale(6);
    }

    /** @param array<string, mixed> $decision @return array<string, mixed> */
    private function formulaInputs(
        array $decision,
        ?QuantityData $floorArea,
        NormalizedBuildingModelData $model,
    ): array {
        return [
            'scenario' => [
                'id' => self::SCENARIO_ID,
                'version' => self::VERSION,
                'confidence' => min(0.6, (float) ($decision['confidence'] ?? 0.6)),
                'warnings' => ['preliminary_scope_decision'],
            ],
            'decision' => [
                'key' => $decision['key'],
                'option' => $decision['option'] ?? null,
                'status' => 'preliminary',
                'evidence_ids' => $this->decisionEvidenceIds($decision),
            ],
            'floor_area' => $floorArea === null ? null : [
                'key' => $floorArea->key,
                'unit' => $floorArea->unit,
                'amount' => $floorArea->amount,
                'formula_key' => $floorArea->formulaKey,
                'formula_version' => $floorArea->formulaVersion,
                'model_version' => $floorArea->modelVersion,
            ],
            'building_model_version' => $model->modelVersion,
        ];
    }

    /** @param array<string, mixed> $decision @return list<string> */
    private function evidenceIds(
        array $decision,
        ?QuantityData $floorArea,
        NormalizedBuildingModelData $model,
    ): array {
        return array_values(array_unique([
            ...$this->decisionEvidenceIds($decision),
            ...($floorArea?->evidenceIds ?? []),
            ...array_map('strval', $model->evidenceIds),
        ]));
    }

    /** @param array<string, mixed> $decision @return list<string> */
    private function decisionEvidenceIds(array $decision): array
    {
        return array_values(array_unique(array_filter(
            array_map('strval', is_array($decision['evidence_ids'] ?? null) ? $decision['evidence_ids'] : []),
            static fn (string $id): bool => $id !== '',
        )));
    }

    /** @param array<string, mixed> $formulaInputs @param list<string> $evidenceIds @param list<string> $assumptions */
    private function quantity(
        string $key,
        string $unit,
        string $amount,
        string $formulaKey,
        array $formulaInputs,
        array $evidenceIds,
        NormalizedBuildingModelData $model,
        array $assumptions,
    ): QuantityData {
        return new QuantityData(
            key: $key,
            unit: $unit,
            amount: $amount,
            formulaKey: $formulaKey,
            formulaVersion: self::VERSION,
            formulaInputs: $formulaInputs,
            source: QuantitySource::Estimated,
            evidenceIds: $evidenceIds,
            modelVersion: $model->modelVersion,
            assumptions: [self::SCENARIO_ID, ...$assumptions],
            reviewBlockers: [],
        );
    }
}
