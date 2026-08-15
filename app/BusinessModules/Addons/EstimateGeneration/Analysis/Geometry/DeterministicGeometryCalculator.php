<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Geometry;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\DerivedQuantity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\DerivedQuantityIdentity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final class DeterministicGeometryCalculator
{
    public const FORMULA_VERSION = 'geometry-formulas:v2';

    public function calculate(GeometryExpertInput $input): GeometryExpertResult
    {
        $quantities = [];
        $conflicts = [];
        $limitations = [];
        $skippedSheets = [];
        $quarantinedIntents = [];
        foreach ($input->sheets as $sheet) {
            if (! is_array($sheet) || ! in_array($sheet['sheet_role'] ?? null, [
                'plan', 'section', 'facade', 'roof', 'explication', 'specification',
            ], true)) {
                $skippedSheets[] = is_array($sheet) && is_string($sheet['sheet_id'] ?? null)
                    ? $sheet['sheet_id'] : 'unknown';

                continue;
            }
            foreach ($this->interpretations($sheet) as $interpretationIndex => $interpretation) {
                try {
                    $interpretation = $this->projectIntentSources($sheet, $interpretation);
                    $interpretation = $this->projectIntentIdentity($input, $interpretation);
                } catch (InvalidArgumentException $exception) {
                    $quarantinedIntents[] = [
                        'sheet_id' => is_string($sheet['sheet_id'] ?? null) ? $sheet['sheet_id'] : 'unknown',
                        'index' => $interpretationIndex,
                        'reason' => $exception->getMessage(),
                    ];

                    continue;
                }
                $locators = array_column(is_array($interpretation['operands'] ?? null) ? $interpretation['operands'] : [], 'physical_locator');
                if (count($locators) !== count(array_unique($locators))) {
                    $conflicts[] = [
                        'code' => 'duplicate_physical_locator',
                        'quantity_id' => $interpretation['quantity_id'] ?? null,
                        'physical_locators' => array_values(array_unique($locators)),
                    ];
                    $limitations[] = [
                        'code' => $this->limitationCode('duplicate_geometry_source', $interpretation, $locators),
                        'type' => 'duplicate_geometry_source',
                        'quantity_id' => $interpretation['quantity_id'] ?? null,
                        'source_locator' => [
                            'page_number' => $sheet['page_number'] ?? null,
                            'physical_locators' => array_values(array_unique($locators)),
                        ],
                    ];

                    continue;
                }
                $partialOpening = $this->partialOpening($interpretation);
                if ($partialOpening !== null) {
                    $conflicts[] = [
                        'code' => 'partial_opening_geometry',
                        'quantity_id' => $interpretation['quantity_id'] ?? null,
                        'missing_operand' => $partialOpening,
                    ];
                    $limitations[] = [
                        'code' => $this->limitationCode('partial_opening_geometry', $interpretation, $locators),
                        'type' => 'partial_opening_geometry',
                        'quantity_id' => $interpretation['quantity_id'] ?? null,
                        'missing_operand' => $partialOpening,
                        'source_locator' => $this->sheetSourceLocator($sheet, [
                            'physical_locators' => array_values(array_unique($locators)),
                        ]),
                    ];

                    continue;
                }
                try {
                    $projectedQuantity = $this->quantity($interpretation);
                } catch (InvalidArgumentException $exception) {
                    $quarantinedIntents[] = [
                        'sheet_id' => is_string($sheet['sheet_id'] ?? null) ? $sheet['sheet_id'] : 'unknown',
                        'index' => $interpretationIndex,
                        'reason' => $exception->getMessage(),
                    ];

                    continue;
                }
                $quantities[] = [
                    'quantity' => [
                        ...$projectedQuantity,
                        'sheet_ids' => [(string) ($sheet['sheet_id'] ?? '')],
                        'source_version' => is_string($sheet['source_version'] ?? null)
                            ? $sheet['source_version'] : $input->sourceVersion,
                    ],
                    'page_number' => $sheet['page_number'] ?? null,
                    'source_locator' => $this->sheetSourceLocator($sheet),
                ];
            }
        }

        [$quantities, $crossSheetConflicts, $crossSheetLimitations] = $this->reconcile($quantities);
        $conflicts = [...$conflicts, ...$crossSheetConflicts];
        $limitations = [...$limitations, ...$crossSheetLimitations];

        return new GeometryExpertResult($quantities, $conflicts, $limitations, $skippedSheets, $quarantinedIntents);
    }

    /**
     * @param  list<array{result:GeometryExpertResult,document_id:int,page_id:int,page_number:int,source_version:string}>  $pages
     */
    public function reconcileResults(array $pages): GeometryExpertResult
    {
        $candidates = [];
        $conflicts = [];
        $limitations = [];
        $skipped = [];
        $quarantined = [];
        foreach ($pages as $page) {
            if (! ($page['result'] ?? null) instanceof GeometryExpertResult) {
                throw new InvalidArgumentException('geometry_reconciliation_page_invalid');
            }
            $result = $page['result'];
            $locator = [
                'document_id' => $page['document_id'],
                'page_id' => $page['page_id'],
                'page_number' => $page['page_number'],
                'source_version' => $page['source_version'],
            ];
            foreach ($result->quantities as $quantity) {
                $candidates[] = [
                    'quantity' => [...$quantity, 'source_version' => $page['source_version']],
                    'page_number' => $page['page_number'],
                    'source_locator' => $locator,
                ];
            }
            $conflicts = [...$conflicts, ...$result->conflicts];
            $limitations = [...$limitations, ...$result->limitations];
            $skipped = [...$skipped, ...$result->skippedSheets];
            $quarantined = [...$quarantined, ...$result->quarantinedIntents];
        }
        [$quantities, $crossConflicts, $crossLimitations] = $this->reconcile($candidates);

        return new GeometryExpertResult(
            $quantities,
            [...$conflicts, ...$crossConflicts],
            [...$limitations, ...$crossLimitations],
            array_values(array_unique($skipped)),
            $quarantined,
        );
    }

    /** @return list<DerivedQuantity> */
    public function domainQuantities(GeometryExpertInput $input, GeometryExpertResult $result): array
    {
        return array_map(function (array $quantity) use ($input): DerivedQuantity {
            $quantitySourceVersion = is_string($quantity['source_version'] ?? null)
                ? $quantity['source_version'] : $input->sourceVersion;
            if (preg_match('/^sha256:[a-f0-9]{64}$/D', $quantitySourceVersion) !== 1) {
                throw new InvalidArgumentException('geometry_quantity_source_invalid');
            }
            $operands = array_map(static function (array $operand): array {
                return [
                    ...$operand,
                    'fact_id' => (string) ($operand['fact_id'] ?? ''),
                    'projection_version' => (int) ($operand['projection_version'] ?? 0),
                    'status' => 'confirmed',
                    'current' => true,
                    'value' => (string) ($operand['value'] ?? ''),
                    'unit' => (string) ($operand['unit'] ?? ''),
                    'evidence_ids' => [(string) ($operand['evidence_id'] ?? '')],
                    'decision_id' => null,
                ];
            }, $quantity['operands']);
            $base = new DerivedQuantity(
                id: (string) $quantity['quantity_id'],
                organizationId: $input->organizationId,
                projectId: $input->projectId,
                sessionId: $input->sessionId,
                sourceVersion: $quantitySourceVersion,
                entityId: (string) $quantity['entity_id'],
                formula: $quantity['formula_id'].':'.$quantity['formula_version'],
                operands: $operands,
                value: (string) $quantity['value'],
                unit: (string) $quantity['unit'],
                roundingMode: (string) $quantity['rounding_mode'],
                roundingScale: (int) $quantity['rounding_scale'],
                evidenceIds: $quantity['evidence_ids'],
                status: 'confirmed',
                formulaIdentity: (string) $quantity['formula_id'],
                formulaVersion: (string) $quantity['formula_version'],
                roundingBoundary: $quantity['formula_id'] === 'sloped_roof_area'
                    ? 'irrational_operation_then_formula_result' : 'formula_result',
                unitCompatibility: 'exact',
                snapshotIdentity: [
                    'source_version' => $quantitySourceVersion,
                    'source_set_version' => $input->sourceVersion,
                    'sheet_ids' => array_values(array_filter(
                        is_array($quantity['sheet_ids'] ?? null) ? $quantity['sheet_ids'] : [],
                        static fn (mixed $sheetId): bool => is_string($sheetId) && $sheetId !== '',
                    )),
                    'sources' => array_values(array_filter(
                        is_array($quantity['sources'] ?? null) ? $quantity['sources'] : [],
                        static fn (mixed $source): bool => is_array($source) && ! array_is_list($source),
                    )),
                ],
                logicalId: (string) $quantity['quantity_id'],
            );
            $identity = DerivedQuantityIdentity::for($base);

            return new DerivedQuantity(
                id: 'quantityv:'.$identity,
                organizationId: $base->organizationId,
                projectId: $base->projectId,
                sessionId: $base->sessionId,
                sourceVersion: $base->sourceVersion,
                entityId: $base->entityId,
                formula: $base->formula,
                operands: $base->operands,
                value: $base->value,
                unit: $base->unit,
                roundingMode: $base->roundingMode,
                roundingScale: $base->roundingScale,
                evidenceIds: $base->evidenceIds,
                status: $base->status,
                formulaIdentity: $base->formulaIdentity,
                formulaVersion: $base->formulaVersion,
                roundingBoundary: $base->roundingBoundary,
                unitCompatibility: $base->unitCompatibility,
                snapshotIdentity: $base->snapshotIdentity,
                logicalId: $base->logicalId,
                exactIdentity: $identity,
            );
        }, $result->quantities);
    }

    /** @return list<array<string,mixed>> */
    private function interpretations(mixed $sheet): array
    {
        $items = is_array($sheet) ? ($sheet['interpretations'] ?? null) : null;
        if (! is_array($items) || ! array_is_list($items) || count($items) > 256) {
            throw new InvalidArgumentException('geometry_interpretations_invalid');
        }

        return $items;
    }

    /** @param array<string,mixed> $interpretation @return array<string,mixed> */
    private function quantity(array $interpretation): array
    {
        $formulaId = $interpretation['formula_id'] ?? null;
        $operands = $interpretation['operands'] ?? null;
        $scale = $interpretation['rounding_scale'] ?? null;
        if (! in_array($formulaId, ['floor_area', 'wall_net_area', 'sloped_roof_area'], true)
            || ! is_array($operands) || ! array_is_list($operands) || $operands === []
            || ! is_int($scale) || $scale < 0 || $scale > 12) {
            throw new InvalidArgumentException('geometry_formula_invalid');
        }
        $operands = $this->normalizedOperands($formulaId, $operands, $interpretation['output_unit'] ?? null);
        $values = [];
        $evidence = [];
        foreach ($operands as $operand) {
            if (! is_array($operand) || ! is_string($operand['value'] ?? null)
                || ! is_string($operand['evidence_id'] ?? null)) {
                throw new InvalidArgumentException('geometry_operand_invalid');
            }
            $values[(string) ($operand['name'] ?? '')][] = BigDecimal::of($operand['value']);
            $evidence[] = $operand['evidence_id'];
        }
        $value = $this->formula($formulaId, $values)->toScale($scale, RoundingMode::HalfUp);
        if ($value->isLessThanOrEqualTo(0)) {
            throw new InvalidArgumentException('geometry_result_invalid');
        }

        return [
            'quantity_id' => (string) ($interpretation['quantity_id'] ?? ''),
            'entity_id' => (string) ($interpretation['entity_id'] ?? ''),
            'formula_id' => $formulaId,
            'formula_version' => self::FORMULA_VERSION,
            'operands' => $operands,
            'value' => $value->isZero() ? '0' : (string) $value->strippedOfTrailingZeros(),
            'unit' => 'm2',
            'rounding_mode' => 'half_up',
            'rounding_scale' => $scale,
            'evidence_ids' => array_values(array_unique($evidence)),
        ];
    }

    /** @param array<string,mixed> $interpretation @return array<string,mixed> */
    private function projectIntentIdentity(
        GeometryExpertInput $input,
        array $interpretation,
    ): array {
        $quantityRef = $interpretation['quantity_ref'] ?? $interpretation['quantity_id'] ?? null;
        $entityRef = $interpretation['entity_ref'] ?? $interpretation['entity_id'] ?? null;
        if (! is_string($quantityRef) || trim($quantityRef) === '' || mb_strlen($quantityRef) > 200
            || ! is_string($entityRef) || trim($entityRef) === '' || mb_strlen($entityRef) > 200) {
            throw new InvalidArgumentException('geometry_intent_reference_invalid');
        }
        $scope = implode('|', [
            $input->organizationId,
            $input->projectId,
            $input->sessionId,
            $input->sourceVersion,
        ]);

        return [
            ...$interpretation,
            'quantity_id' => 'quantity:'.substr(hash('sha256', $scope.'|'.trim($quantityRef)), 0, 32),
            'entity_id' => 'entity:'.substr(hash('sha256', $scope.'|'.trim($entityRef)), 0, 32),
        ];
    }

    /** @param array<string,mixed> $sheet @param array<string,mixed> $interpretation @return array<string,mixed> */
    private function projectIntentSources(array $sheet, array $interpretation): array
    {
        $arbitration = $sheet['arbitration'] ?? null;
        if (! is_array($arbitration)) {
            return $interpretation;
        }
        $decisions = $arbitration['decisions'] ?? null;
        $operands = $interpretation['operands'] ?? null;
        if (! is_array($decisions) || ! array_is_list($decisions)
            || ! is_array($operands) || ! array_is_list($operands) || count($operands) > 64) {
            throw new InvalidArgumentException('geometry_source_projection_invalid');
        }
        $claims = [];
        foreach ($decisions as $decision) {
            $canonical = is_array($decision) ? ($decision['canonical_claim'] ?? null) : null;
            $claimId = is_array($canonical) ? ($canonical['source_claim_id'] ?? null) : null;
            if (! is_string($claimId) || ($decision['status'] ?? null) !== 'accepted') {
                continue;
            }
            $claims[$claimId] = [
                'canonical' => $canonical,
                'evidence' => is_array($decision['evidence_refs'] ?? null) ? $decision['evidence_refs'] : [],
            ];
        }
        $projectedOperands = [];
        foreach ($operands as $operand) {
            $claimRef = is_array($operand) ? ($operand['claim_ref'] ?? null) : null;
            $evidenceRef = is_array($operand) ? ($operand['evidence_ref'] ?? null) : null;
            $name = is_array($operand) ? ($operand['name'] ?? null) : null;
            if (! is_string($claimRef) || ! isset($claims[$claimRef])
                || ! is_string($evidenceRef) || ! in_array($evidenceRef, $claims[$claimRef]['evidence'], true)
                || ! is_string($name)) {
                throw new InvalidArgumentException('geometry_source_reference_not_allowlisted');
            }
            $canonical = $claims[$claimRef]['canonical'];
            $value = is_array($canonical['value'] ?? null) ? ($canonical['value']['data'] ?? null) : null;
            $unit = $canonical['unit'] ?? null;
            if ((! is_string($value) && ! is_int($value)) || ! is_string($unit)) {
                throw new InvalidArgumentException('geometry_source_value_invalid');
            }
            $projectedOperands[] = [
                'name' => $name,
                'value' => (string) $value,
                'unit' => $unit,
                'evidence_id' => $evidenceRef,
                'physical_locator' => implode(':', [
                    'source',
                    $sheet['document_id'] ?? 'unknown',
                    $sheet['page_id'] ?? 'unknown',
                    $sheet['processing_unit_id'] ?? 'unknown',
                    $sheet['source_version'] ?? 'unknown',
                    $evidenceRef,
                ]),
            ];
        }
        $interpretation['operands'] = $projectedOperands;
        $interpretation['output_unit'] = 'm2';
        $interpretation['rounding_scale'] = 6;

        return $interpretation;
    }

    /** @param array<string,list<BigDecimal>> $values */
    private function formula(string $formulaId, array $values): BigDecimal
    {
        return match ($formulaId) {
            'floor_area' => $this->single($values, 'length')->multipliedBy($this->single($values, 'width')),
            'wall_net_area' => $this->wallNetArea($values),
            'sloped_roof_area' => $this->slopedRoofArea($values),
            default => throw new InvalidArgumentException('geometry_formula_invalid'),
        };
    }

    /** @param array<string,list<BigDecimal>> $values */
    private function wallNetArea(array $values): BigDecimal
    {
        $widths = $values['opening_width'] ?? [];
        $heights = $values['opening_height'] ?? [];
        if (count($widths) !== count($heights)) {
            throw new InvalidArgumentException('geometry_opening_operands_invalid');
        }
        $area = $this->single($values, 'wall_length')->multipliedBy($this->single($values, 'wall_height'));
        foreach ($widths as $index => $width) {
            $area = $area->minus($width->multipliedBy($heights[$index]));
        }

        return $area;
    }

    /** @param array<string,mixed> $interpretation */
    private function partialOpening(array $interpretation): ?string
    {
        if (($interpretation['formula_id'] ?? null) !== 'wall_net_area'
            || ! is_array($interpretation['operands'] ?? null)) {
            return null;
        }
        $names = array_column($interpretation['operands'], 'name');
        $widthCount = count(array_filter($names, static fn (mixed $name): bool => $name === 'opening_width'));
        $heightCount = count(array_filter($names, static fn (mixed $name): bool => $name === 'opening_height'));

        return match (true) {
            $widthCount > $heightCount => 'opening_height',
            $heightCount > $widthCount => 'opening_width',
            default => null,
        };
    }

    /** @param array<string,list<BigDecimal>> $values */
    private function slopedRoofArea(array $values): BigDecimal
    {
        $rise = $this->single($values, 'slope_rise');
        $run = $this->single($values, 'slope_run');
        if ($run->isZero()) {
            throw new InvalidArgumentException('geometry_roof_run_invalid');
        }
        $slopeLength = $rise->power(2)->plus($run->power(2))->sqrt(12, RoundingMode::HalfUp);
        $area = $this->single($values, 'plan_area')
            ->multipliedBy($slopeLength->dividedBy($run, 12, RoundingMode::HalfUp));
        foreach ($values['roof_opening_area'] ?? [] as $openingArea) {
            $area = $area->minus($openingArea);
        }

        return $area;
    }

    /** @param array<string,list<BigDecimal>> $values */
    private function single(array $values, string $name): BigDecimal
    {
        $items = $values[$name] ?? [];
        if (count($items) !== 1) {
            throw new InvalidArgumentException('geometry_operand_invalid');
        }

        return $items[0];
    }

    /**
     * @param  list<array{quantity:array<string,mixed>,page_number:mixed}>  $candidates
     * @return array{list<array<string,mixed>>,list<array<string,mixed>>,list<array<string,mixed>>}
     */
    private function reconcile(array $candidates): array
    {
        $groups = [];
        foreach ($candidates as $candidate) {
            $groups[$candidate['quantity']['quantity_id']][] = $candidate;
        }
        $quantities = [];
        $conflicts = [];
        $limitations = [];
        foreach ($groups as $quantityId => $group) {
            usort($group, static fn (array $left, array $right): int => json_encode(
                $left['source_locator'] ?? [],
                JSON_THROW_ON_ERROR,
            ) <=> json_encode($right['source_locator'] ?? [], JSON_THROW_ON_ERROR));
            $values = array_values(array_unique(array_column(array_column($group, 'quantity'), 'value')));
            if (count($values) === 1) {
                $quantity = $group[0]['quantity'];
                foreach (array_slice($group, 1) as $candidate) {
                    if (($candidate['quantity']['source_version'] ?? null) === ($quantity['source_version'] ?? null)) {
                        $quantity['evidence_ids'] = array_values(array_unique([
                            ...$quantity['evidence_ids'], ...$candidate['quantity']['evidence_ids'],
                        ]));
                    }
                    $quantity['sheet_ids'] = array_values(array_unique([
                        ...$quantity['sheet_ids'], ...$candidate['quantity']['sheet_ids'],
                    ]));
                }
                $quantity['sources'] = array_values(array_filter(
                    array_map(static fn (array $candidate): mixed => $candidate['source_locator'] ?? null, $group),
                    static fn (mixed $source): bool => is_array($source) && ! array_is_list($source),
                ));
                $quantities[] = $quantity;

                continue;
            }
            $pages = array_values(array_unique(array_filter(
                array_column($group, 'page_number'),
                static fn (mixed $page): bool => is_int($page),
            )));
            sort($pages, SORT_NUMERIC);
            $sources = [];
            foreach ($group as $candidate) {
                if (is_array($candidate['source_locator'] ?? null)) {
                    $sources[json_encode($candidate['source_locator'], JSON_THROW_ON_ERROR)] = $candidate['source_locator'];
                }
            }
            ksort($sources, SORT_STRING);
            $conflicts[] = [
                'code' => 'cross_sheet_geometry_conflict',
                'quantity_id' => $quantityId,
                'values' => $values,
                'page_numbers' => $pages,
            ];
            $limitations[] = [
                'code' => 'cross_sheet_geometry_'.substr(hash('sha256', (string) $quantityId), 0, 16),
                'type' => 'cross_sheet_geometry_conflict',
                'quantity_id' => $quantityId,
                'values' => $values,
                'source_locator' => ['page_numbers' => $pages, 'sources' => array_values($sources)],
            ];
        }

        return [$quantities, $conflicts, $limitations];
    }

    /** @param list<array<string,mixed>> $operands @return list<array<string,mixed>> */
    private function normalizedOperands(string $formulaId, array $operands, mixed $outputUnit): array
    {
        $dimensions = match ($formulaId) {
            'floor_area' => ['length' => 'length', 'width' => 'length'],
            'wall_net_area' => [
                'wall_length' => 'length', 'wall_height' => 'length',
                'opening_width' => 'length', 'opening_height' => 'length',
            ],
            'sloped_roof_area' => [
                'plan_area' => 'area', 'slope_rise' => 'length',
                'slope_run' => 'length', 'roof_opening_area' => 'area',
            ],
            default => throw new InvalidArgumentException('geometry_formula_invalid'),
        };
        if (! is_string($outputUnit) || ! in_array(mb_strtolower(trim($outputUnit)), ['m2', 'm²'], true)) {
            throw new InvalidArgumentException('geometry_unit_incompatible');
        }

        return array_map(function (array $operand) use ($dimensions): array {
            $name = $operand['name'] ?? null;
            $unit = $operand['unit'] ?? null;
            if (! is_string($name) || ! isset($dimensions[$name]) || ! is_string($unit)) {
                throw new InvalidArgumentException('geometry_unit_incompatible');
            }
            $normalizedUnit = mb_strtolower(trim($unit));
            $factors = $dimensions[$name] === 'length'
                ? ['m' => '1', 'cm' => '0.01', 'mm' => '0.001']
                : ['m2' => '1', 'm²' => '1', 'cm2' => '0.0001', 'cm²' => '0.0001', 'mm2' => '0.000001', 'mm²' => '0.000001'];
            if (! isset($factors[$normalizedUnit])) {
                throw new InvalidArgumentException('geometry_unit_incompatible');
            }
            $value = BigDecimal::of((string) ($operand['value'] ?? ''))->multipliedBy($factors[$normalizedUnit]);

            return [
                ...$operand,
                'value' => $value->isZero() ? '0' : (string) $value->strippedOfTrailingZeros(),
                'unit' => $dimensions[$name] === 'length' ? 'm' : 'm2',
            ];
        }, $operands);
    }

    /** @param array<string,mixed> $interpretation @param list<mixed> $locators */
    private function limitationCode(string $prefix, array $interpretation, array $locators): string
    {
        return $prefix.'_'.substr(hash('sha256', json_encode([
            $interpretation['quantity_id'] ?? null,
            array_values(array_unique($locators)),
        ], JSON_THROW_ON_ERROR)), 0, 16);
    }

    /** @param array<string,mixed> $sheet @param array<string,mixed> $extra @return array<string,mixed> */
    private function sheetSourceLocator(array $sheet, array $extra = []): array
    {
        return array_filter([
            'document_id' => $sheet['document_id'] ?? null,
            'page_id' => $sheet['page_id'] ?? null,
            'page_number' => $sheet['page_number'] ?? null,
            'source_version' => $sheet['source_version'] ?? null,
            ...$extra,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }
}
