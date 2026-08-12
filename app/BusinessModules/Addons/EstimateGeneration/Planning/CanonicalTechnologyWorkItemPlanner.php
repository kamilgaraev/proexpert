<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Planning;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantityData;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantitySource;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

final readonly class CanonicalTechnologyWorkItemPlanner
{
    private const MAX_PACKAGES = 200;

    private const MAX_ROWS = 500;

    /** @return list<array<string, mixed>> */
    public function planPackages(array $packages): array
    {
        if (! array_is_list($packages) || count($packages) > self::MAX_PACKAGES) {
            return [];
        }

        $items = [];
        $seen = [];
        foreach ($packages as $package) {
            if (! is_array($package)
                || ! in_array($package['status'] ?? null, ['unknown', 'proven_missing'], true)
                || ! is_array($package['works'] ?? null) || count($package['works']) > 40
                || ! is_array($package['quantities'] ?? null) || count($package['quantities']) > 20
                || ! is_array($package['norm_intents'] ?? null) || count($package['norm_intents']) > 20
                || ! is_array($package['evidence_fact_ids'] ?? null) || count($package['evidence_fact_ids']) > 256) {
                continue;
            }
            $packageId = $this->string($package['id'] ?? null);
            $quantities = $this->index($package['quantities'], 'formula_id');
            $intents = $this->index($package['norm_intents'], 'id');
            if ($packageId === null || $quantities === [] || $intents === []) {
                continue;
            }
            foreach ($package['works'] as $work) {
                if (! is_array($work)) {
                    continue;
                }
                $workId = $this->string($work['id'] ?? null);
                $label = $this->string($work['label'] ?? $work['intent'] ?? null);
                $quantity = $this->mapped($quantities, $work['quantity_formula_id'] ?? null);
                $intent = $this->mapped($intents, $work['norm_intent_id'] ?? null);
                $quantityKey = $this->string($quantity['key'] ?? null);
                $unit = $this->string($quantity['unit'] ?? null);
                if ($workId === null || $label === null || $quantityKey === null || $unit === null || $intent === []) {
                    continue;
                }
                $key = 'stage5:'.hash('sha256', $packageId."\0".$workId);
                if (isset($seen[$key]) || count($items) >= self::MAX_ROWS) {
                    continue;
                }
                $seen[$key] = true;
                $candidateRefs = array_values(array_filter(array_map(
                    fn (mixed $value): ?string => $this->string($value),
                    is_array($intent['candidate_refs'] ?? null)
                        ? array_slice($intent['candidate_refs'], 0, 5)
                        : [$intent['stable_intent'] ?? null],
                )));
                $items[] = [
                    'key' => $key,
                    'name' => $label,
                    'item_type' => 'priced_work',
                    'category' => 'technology',
                    'normative_search_text' => trim($label.' '.implode(' ', $candidateRefs)),
                    'unit' => $unit,
                    'source_refs' => array_map(
                        static fn (string $factId): array => ['fact_id' => $factId],
                        array_values(array_filter($package['evidence_fact_ids'], 'is_string')),
                    ),
                    'metadata' => [
                        'quantity_key' => $quantityKey,
                        'technology_package_id' => $packageId,
                        'technology_formula_id' => $quantity['formula_id'] ?? null,
                        'technology_decision_key' => $package['technology_decision_key'] ?? null,
                        'technology_decision' => $package['technology_decision'] ?? null,
                        'completeness_decision' => $package['completeness_decision'] ?? null,
                        'completeness_finding' => [
                            'key' => $package['finding_key'] ?? null,
                            'version' => $package['finding_version'] ?? null,
                            'status' => $package['status'],
                        ],
                        'normative_intent' => [
                            'id' => $intent['id'] ?? null,
                            'candidate_refs' => $candidateRefs,
                        ],
                        'technology_materials' => $package['materials'] ?? [],
                        'technology_machinery' => $package['machinery'] ?? [],
                        'technology_dependencies' => $package['dependencies'] ?? [],
                        'technology_variants' => $package['variants'] ?? [],
                        'technology_assumptions' => $package['assumptions'] ?? [],
                        'technology_risks' => $package['risks'] ?? [],
                        'dependency_keys' => array_values(array_unique(array_filter([
                            $package['technology_decision_key'] ?? null,
                            $quantityKey,
                            ...(is_array($work['depends_on'] ?? null) ? $work['depends_on'] : []),
                        ], 'is_string'))),
                    ],
                ];
            }
        }

        return $items;
    }

    /** @return array{rows:list<array<string,mixed>>,quantities:array<string,QuantityData>,blockers:list<string>,assumptions:list<string>,risks:list<string>} */
    public function simulateOption(
        TechnologySystemOption $option,
        ProjectModelSnapshot $snapshot,
        array $projection,
        string $decisionKey,
    ): array {
        $system = $option->system;
        $quantities = [];
        $quantityDescriptors = [];
        $blockers = [];
        foreach ($system->quantityFormulas as $formula) {
            if (! is_array($formula)) {
                $blockers[] = 'canonical_formula_invalid';

                continue;
            }
            $resolved = $this->formula($formula, $snapshot, $projection);
            if ($resolved['quantity'] === null) {
                $blockers[] = $resolved['blocker'];

                continue;
            }
            $quantity = $resolved['quantity'];
            $quantities[$quantity->key] = $quantity;
            $quantityDescriptors[] = [
                'key' => $quantity->key,
                'formula_id' => $formula['id'],
                'unit' => $quantity->unit,
            ];
        }
        if ($blockers !== []) {
            return [
                'rows' => [],
                'quantities' => [],
                'blockers' => array_values(array_unique($blockers)),
                'assumptions' => $this->labels($system->assumptions, 'assumption'),
                'risks' => $this->labels($system->risks, 'risk'),
            ];
        }
        $package = [
            'id' => $system->id,
            'finding_key' => 'technology-selection:'.$decisionKey,
            'finding_version' => 1,
            'status' => 'proven_missing',
            'works' => $system->works,
            'materials' => $system->materials,
            'machinery' => $system->machinery,
            'norm_intents' => $system->normIntents,
            'quantities' => $quantityDescriptors,
            'dependencies' => [],
            'variants' => array_values(array_filter(array_map(
                static fn (array $work): mixed => $work['variant'] ?? null,
                array_filter($system->works, 'is_array'),
            ))),
            'evidence_fact_ids' => array_values(array_unique(array_merge(...array_map(
                static fn (QuantityData $quantity): array => $quantity->evidenceIds,
                array_values($quantities),
            )))),
            'technology_decision_key' => $decisionKey,
            'assumptions' => $system->assumptions,
            'risks' => $system->risks,
        ];
        $rows = $this->planPackages([$package]);
        if (count($rows) !== count($system->works)) {
            return [
                'rows' => [],
                'quantities' => [],
                'blockers' => ['canonical_formula_or_norm_mapping_missing'],
                'assumptions' => $this->labels($system->assumptions, 'assumption'),
                'risks' => $this->labels($system->risks, 'risk'),
            ];
        }

        return [
            'rows' => $rows,
            'quantities' => $quantities,
            'blockers' => [],
            'assumptions' => $this->labels($system->assumptions, 'assumption'),
            'risks' => $this->labels($system->risks, 'risk'),
        ];
    }

    /** @return array{quantity:?QuantityData,blocker:string} */
    private function formula(array $formula, ProjectModelSnapshot $snapshot, array $projection): array
    {
        $id = $this->string($formula['id'] ?? null);
        $expression = $this->string($formula['expression'] ?? null);
        $unit = $this->string($formula['result_unit'] ?? $formula['unit'] ?? null);
        $operands = is_array($formula['operands'] ?? null) ? $formula['operands'] : [];
        if ($id === null || $expression === null || $unit === null || $operands === [] || count($operands) > 20) {
            return ['quantity' => null, 'blocker' => 'canonical_formula_invalid'];
        }
        $values = [];
        $evidence = [];
        $inputs = [];
        foreach ($operands as $operand) {
            $name = is_array($operand) ? $this->string($operand['name'] ?? null) : null;
            if ($name === null) {
                return ['quantity' => null, 'blocker' => 'canonical_formula_operand_invalid'];
            }
            $projected = $projection['quantities'][$name] ?? null;
            if ($projected instanceof QuantityData) {
                $values[$name] = BigDecimal::of($projected->amount);
                $evidence = [...$evidence, ...$projected->evidenceIds];
                $inputs[$name] = $projected->toArray();

                continue;
            }
            $facts = array_values(array_filter(
                $snapshot->facts,
                static fn (Fact $fact): bool => $fact->type === $name && $fact->status === 'confirmed'
                    && (is_string($fact->value) || is_int($fact->value)),
            ));
            if (count($facts) !== 1) {
                return ['quantity' => null, 'blocker' => count($facts) === 0
                    ? 'canonical_formula_operand_missing'
                    : 'canonical_formula_operand_ambiguous'];
            }
            try {
                $values[$name] = BigDecimal::of($facts[0]->value);
            } catch (MathException) {
                return ['quantity' => null, 'blocker' => 'canonical_formula_operand_invalid'];
            }
            $evidence = [...$evidence, ...$facts[0]->evidenceIds];
            $inputs[$name] = ['fact_id' => $facts[0]->id, 'value' => (string) $facts[0]->value, 'unit' => $facts[0]->unit];
        }
        try {
            if (preg_match('/^([a-z][a-z0-9_]*)$/D', $expression, $match) === 1 && isset($values[$match[1]])) {
                $value = $values[$match[1]];
            } elseif (preg_match('/^([a-z][a-z0-9_]*) ([*\/]) ([a-z][a-z0-9_]*)$/D', $expression, $match) === 1
                && isset($values[$match[1]], $values[$match[3]])) {
                $value = $match[2] === '*'
                    ? $values[$match[1]]->multipliedBy($values[$match[3]])
                    : $values[$match[1]]->dividedBy($values[$match[3]], 12, RoundingMode::HALF_UP);
            } else {
                return ['quantity' => null, 'blocker' => 'canonical_formula_unsupported'];
            }
        } catch (MathException) {
            return ['quantity' => null, 'blocker' => 'canonical_formula_invalid'];
        }
        if ($value->isLessThanOrEqualTo(0)) {
            return ['quantity' => null, 'blocker' => 'canonical_formula_invalid'];
        }
        $key = 'quantity:technology_system:'.hash('sha256', $id."\0".$expression);

        return ['quantity' => new QuantityData(
            key: $key,
            unit: $unit,
            amount: (string) $value->toScale(4, RoundingMode::HALF_UP),
            formulaKey: $expression,
            formulaVersion: $id,
            formulaInputs: $inputs,
            source: QuantitySource::Evidenced,
            evidenceIds: array_values(array_unique($evidence)),
            modelVersion: (string) ($projection['context']['project_model_token'] ?? 'simulation'),
        ), 'blocker' => ''];
    }

    /** @return array<string,array<string,mixed>> */
    private function index(array $items, string $key): array
    {
        $result = [];
        foreach ($items as $item) {
            $id = is_array($item) ? $this->string($item[$key] ?? null) : null;
            if ($id !== null && ! isset($result[$id])) {
                $result[$id] = $item;
            }
        }

        return $result;
    }

    /** @param array<string,array<string,mixed>> $items @return array<string,mixed> */
    private function mapped(array $items, mixed $requested): array
    {
        $key = $this->string($requested);
        if ($key !== null) {
            return $items[$key] ?? [];
        }

        return count($items) === 1 ? array_values($items)[0] : [];
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' && strlen($value) <= 512 ? $value : null;
    }

    /** @return list<string> */
    private function labels(array $values, string $kind): array
    {
        return array_values(array_map(static function (string $value) use ($kind): string {
            try {
                if (function_exists('app') && app()->bound('translator') && function_exists('trans_message')) {
                    return trans_message('estimate_generation.planning.technology.'.$kind.'.'.$value);
                }
            } catch (\Throwable) {
            }

            return $value;
        }, array_slice(array_filter($values, 'is_string'), 0, 20)));
    }
}
