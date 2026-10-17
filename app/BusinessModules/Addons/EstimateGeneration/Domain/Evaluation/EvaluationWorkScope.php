<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation;

use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Planning\EvaluationSectionMap;
use InvalidArgumentException;

final readonly class EvaluationWorkScope
{
    public function select(array $draft, array $sections, array $input): array
    {
        $keys = $input['selected_work_item_keys'] ?? null;
        $reason = $input['scope_reason'] ?? null;
        if (! is_array($keys) || ! array_is_list($keys) || $keys === [] || count($keys) > 128
            || count(array_filter($keys, 'is_string')) !== count($keys) || count(array_unique($keys)) !== count($keys)
            || ! is_string($reason) || mb_strlen(trim($reason)) < 10 || mb_strlen($reason) > 1000
            || ! is_string($input['artifact_hash'] ?? null) || ($draft['artifact_hash'] ?? null) !== $input['artifact_hash']
            || ! (new BuildMostEstimateDraft)->verifyArtifact($draft)) {
            throw new InvalidArgumentException('evaluation_work_scope_invalid');
        }
        $items = $this->items($draft);
        sort($keys, SORT_STRING);
        $bindings = [];
        foreach ($keys as $key) {
            $item = $items[$key] ?? null;
            if ($item === null || ! in_array(EvaluationSectionMap::forPackage($item['package_key']), $sections, true)) {
                throw new InvalidArgumentException('evaluation_work_scope_invalid');
            }
            $bindings[] = $item['binding'];
        }
        $excluded = $this->findings($draft, $input['excluded_audit_finding_ids'] ?? [], $keys, false, $items);
        $accepted = $this->findings($draft, $input['accepted_material_finding_ids'] ?? [], $keys, true, $items);

        $selection = ['selected_items' => $bindings, 'reason' => trim($reason), 'source_artifact_hash' => $input['artifact_hash'],
            'excluded_audit_findings' => $excluded, 'accepted_material_findings' => $accepted];
        if (strlen(CanonicalPipelineJson::encode($selection)) > 61440) {
            throw new InvalidArgumentException('evaluation_work_scope_invalid');
        }

        return $selection;
    }

    public function current(array $draft, mixed $selection): bool
    {
        if (! is_array($selection) || ! is_array($selection['selected_items'] ?? null)
            || $selection['selected_items'] === [] || ! array_is_list($selection['selected_items'])) {
            return false;
        }
        $items = $this->items($draft);
        $seen = [];
        foreach ($selection['selected_items'] as $binding) {
            $key = is_array($binding) ? ($binding['key'] ?? null) : null;
            if (! is_string($key) || isset($seen[$key]) || ! isset($items[$key])
                || CanonicalPipelineJson::encode($items[$key]['binding']) !== CanonicalPipelineJson::encode($binding)) {
                return false;
            }
            $seen[$key] = true;
        }

        return true;
    }

    public function items(array $draft): array
    {
        $items = [];
        foreach ($draft['local_estimates'] ?? [] as $estimate) {
            foreach ($estimate['sections'] ?? [] as $section) {
                foreach ($section['work_items'] ?? [] as $item) {
                    if (in_array($item['item_type'] ?? 'priced_work', ['operation', 'review_note', 'resource_note', 'quantity_review'], true)) {
                        continue;
                    }
                    $key = $item['key'];
                    if (isset($items[$key]) || count($items) >= 10000) {
                        throw new InvalidArgumentException('evaluation_work_scope_invalid');
                    }
                    $binding = ['key' => $key, 'package_key' => $estimate['key'], 'section_key' => $section['key'] ?? null,
                        'work_key' => $item['work_key'] ?? $item['metadata']['composition_work_key'] ?? $key,
                        'entity_id' => $item['metadata']['scope_entity_id'] ?? $item['quantity_basis_details']['formula_inputs']['entity_id']
                            ?? $item['metadata']['entity_id'] ?? null];
                    $items[$key] = ['binding' => $binding, 'package_key' => $estimate['key'], 'item' => $item];
                }
            }
        }

        return $items;
    }

    public function findingHash(array $finding): string
    {
        return hash('sha256', CanonicalPipelineJson::encode($finding));
    }

    public function materialBinding(array $item): ?array
    {
        $scenario = $item['specialization_scenario'] ?? $item['metadata']['specialization_scenario'] ?? null;
        $assumption = $item['metadata']['material_assumption'] ?? null;
        if (! is_array($scenario) || ! is_array($assumption)
            || ($assumption['code'] ?? null) !== ($scenario['assumption_code'] ?? null)
            || ! is_string($scenario['signature'] ?? null)
            || ($item['normative_match']['code'] ?? null) !== ($scenario['normative_rate_code'] ?? null)
            || ($item['normative_match']['selected_by_user'] ?? null) !== true
            || ($item['normative_match']['hard_gate_passed'] ?? null) !== true) {
            return null;
        }

        return ['norm_id' => $item['normative_match']['norm_id'], 'scenario_signature' => $scenario['signature'],
            'assumption_code' => $assumption['code']];
    }

    private function findings(array $draft, mixed $ids, array $selected, bool $material, array $items): array
    {
        if (! is_array($ids) || ! array_is_list($ids) || count($ids) > 128
            || count(array_filter($ids, 'is_string')) !== count($ids) || count(array_unique($ids)) !== count($ids)) {
            throw new InvalidArgumentException('evaluation_work_scope_invalid');
        }
        $findings = array_column($draft['audit_review_items'] ?? [], null, 'finding_id');
        sort($ids, SORT_STRING);
        $result = [];
        foreach ($ids as $id) {
            $finding = $findings[$id] ?? null;
            $key = $finding['item_key'] ?? null;
            if ($finding === null || ! is_string($key) || $key === '' || in_array($key, $selected, true) !== $material) {
                throw new InvalidArgumentException('evaluation_work_scope_invalid');
            }
            $entry = ['finding_id' => $id, 'item_key' => $key, 'fingerprint' => $this->findingHash($finding)];
            if ($material) {
                $binding = isset($items[$key]) ? $this->materialBinding($items[$key]['item']) : null;
                if (($finding['type'] ?? null) !== 'unjustified_expense' || $binding === null) {
                    throw new InvalidArgumentException('evaluation_work_scope_invalid');
                }
                $entry['material_binding'] = $binding;
            }
            $result[] = $entry;
        }

        return $result;
    }
}
