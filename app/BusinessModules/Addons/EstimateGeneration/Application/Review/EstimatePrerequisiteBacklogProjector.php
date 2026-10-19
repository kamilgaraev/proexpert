<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Review;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Planning\CompletenessFinding;
use App\BusinessModules\Addons\EstimateGeneration\Planning\PrerequisiteInputDefinition;
use Closure;

final readonly class EstimatePrerequisiteBacklogProjector
{
    private Closure $translate;

    public function __construct(?Closure $translate = null)
    {
        $this->translate = $translate ?? static fn (string $key): string => trans_message($key);
    }

    public function project(CompletenessFinding $finding, array $projection): array
    {
        $provenance = [
            'source_version' => $projection['source_version'] ?? null,
            'accepted_input_fingerprint' => $projection['input_fingerprint'] ?? null,
            'catalog_version' => $projection['catalog_version'] ?? null,
            'catalog_hash' => $projection['catalog_hash'] ?? null,
            'rule_catalog_version' => $projection['rule_catalog_version'] ?? null,
            'rule_catalog_hash' => $projection['rule_catalog_hash'] ?? null,
            'rule_id' => $finding->ruleId,
            'rule_version' => $finding->ruleVersion,
            'rule_hash' => $finding->ruleHash,
            'finding_key' => $finding->stableKey,
        ];
        $inputs = [];
        $limitations = [];
        $formulas = in_array($finding->status, ['unknown', 'proven_missing'], true)
            && ($finding->applicability['status'] ?? null) === 'applicable'
            ? ($finding->workPackage?->quantityFormulas ?? []) : [];
        foreach ($formulas as $formula) {
            if (! is_array($formula) || ! array_key_exists('unresolved_inputs', $formula)) {
                continue;
            }
            if (! $this->identifier($formula['id'] ?? null) || ! is_array($formula['unresolved_inputs'])
                || ! array_is_list($formula['unresolved_inputs'])
                || ! in_array($formula['unit'] ?? null, ['m', 'm2', 'm3', 'item', 'degree', 'ratio'], true)
                || array_key_exists('resolved_value', $formula)) {
                $limitations['prerequisite_input_contract_invalid'] = true;

                continue;
            }
            foreach ($formula['unresolved_inputs'] as $input) {
                if (! $this->identifier($input)) {
                    $limitations['prerequisite_input_contract_invalid'] = true;

                    continue;
                }
                $inputs[$input]['formulas'][$formula['id']] = true;
                $inputs[$input]['units'][$formula['unit']] = true;
            }
        }
        ksort($inputs, SORT_STRING);
        $total = count($inputs);
        if ($total > 100) {
            $limitations['prerequisite_question_budget_reached'] = true;
        }
        $questions = [];
        foreach (array_slice($inputs, 0, 100, true) as $input => $dependencies) {
            $formulaIds = array_keys($dependencies['formulas']);
            $quantityUnits = array_keys($dependencies['units']);
            sort($formulaIds, SORT_STRING);
            sort($quantityUnits, SORT_STRING);
            $labelKey = 'estimate_generation.prerequisites.parameters.'.$input;
            $label = ($this->translate)($labelKey);
            if ($label === '' || $label === $labelKey) {
                $label = ($this->translate)('estimate_generation.prerequisites.unknown_parameter');
            }
            $contract = count($quantityUnits) === 1 ? PrerequisiteInputDefinition::contract($input, $quantityUnits[0]) : null;
            $inputScope = $finding->workPackage?->provenance['input_scope'] ?? [];
            $target = $contract !== null && ($inputScope['status'] ?? null) === 'single_entity'
                ? ($inputScope['target'] ?? null) : null;
            if (! is_array($target) || ! is_string($target['entity_id'] ?? null)
                || preg_match('/\A[a-z][a-z0-9._:-]{1,191}\z/D', $target['entity_id']) !== 1
                || ! is_string($target['source_version'] ?? null)
                || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $target['source_version']) !== 1) {
                $target = null;
            } else {
                $target = ['entity_id' => $target['entity_id'], 'source_version' => $target['source_version']];
            }
            $questions[] = [
                'key' => 'prerequisite:'.hash('sha256', CanonicalPipelineJson::encode([
                    $finding->ruleId, $finding->ruleVersion, $finding->ruleHash, $input,
                    ...($target === null ? [] : [$target]),
                ])),
                'input_fact_type' => $input,
                'label' => $label,
                'scope' => $target === null ? 'rule' : 'entity',
                'answer_action' => 'review_sources',
                ...($target === null ? [] : ['target' => $target, 'input_contract' => $contract]),
                'formula_ids' => $formulaIds,
                'quantity_units' => $quantityUnits,
            ];
        }
        $group = ['contract_version' => 'estimate-prerequisites:v1', 'provenance' => $provenance,
            'questions' => $questions, 'total' => $total, 'partial' => $limitations !== [],
            'limitations' => array_keys($limitations)];

        return [...$group, 'version' => 'sha256:'.hash('sha256', CanonicalPipelineJson::encode($group))];
    }

    private function identifier(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-z][a-z0-9_.:-]{0,159}\z/D', $value) === 1;
    }
}
