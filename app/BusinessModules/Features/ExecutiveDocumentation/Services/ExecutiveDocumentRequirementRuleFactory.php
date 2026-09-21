<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Services;

final class ExecutiveDocumentRequirementRuleFactory
{
    private const ROLE_RULES = [
        'hidden_work_act' => 'direct_work_executor',
        'axis_layout_act' => 'axis_layout_executor',
        'geodetic_base_acceptance_act' => 'geodetic_base_executor',
        'responsible_structure_act' => 'structure_executor',
        'engineering_network_section_act' => 'network_executor',
    ];

    public function snapshot(array $profile, array $conditions): array
    {
        $type = (string) ($profile['type'] ?? '');
        if (! isset(self::ROLE_RULES[$type])) {
            if (array_diff(array_keys($conditions), ['required_relations']) !== []) {
                throw new \InvalidArgumentException('Unsupported conditions for this document profile.');
            }

            return [
                'profile' => $profile,
                'required_signatories' => [],
                'required_relations' => $this->requiredRelations($profile, $conditions['required_relations'] ?? null),
                'conditions' => [],
                'unresolved_conditions' => [],
                'normative_source' => $profile['regulatory_basis'] ?? [],
            ];
        }

        $allowedConditions = ['designer_supervision', 'separate_executor', 'required_relations'];
        foreach (array_keys($conditions) as $key) {
            if (! in_array((string) $key, $allowedConditions, true)) {
                throw new \InvalidArgumentException("Unsupported signatory condition [{$key}].");
            }
        }

        $resolvedConditions = [
            'designer_supervision' => $this->booleanCondition($conditions, 'designer_supervision'),
            'separate_executor' => $this->booleanCondition($conditions, 'separate_executor'),
        ];
        $unresolved = [];
        foreach (array_keys($resolvedConditions) as $key) {
            if ($resolvedConditions[$key] === null) {
                $unresolved[] = $key;
            }
        }

        $requiredSignatories = [
            'developer_control_representative' => ['authority_required' => true],
            'construction_representative' => ['authority_required' => true],
            'contractor_control_representative' => ['authority_required' => true],
        ];
        if ($resolvedConditions['designer_supervision'] === true) {
            $requiredSignatories['designer_representative'] = ['authority_required' => true];
        }
        if ($resolvedConditions['separate_executor'] === true) {
            $requiredSignatories[self::ROLE_RULES[$type]] = ['authority_required' => true];
        }
        if ($type === 'engineering_network_section_act' && $resolvedConditions['separate_executor'] === true) {
            $requiredSignatories['operating_company_representative'] = ['authority_required' => true];
        }

        return [
            'profile' => $profile,
            'required_signatories' => $requiredSignatories,
            'required_relations' => $this->requiredRelations($profile, $conditions['required_relations'] ?? null),
            'conditions' => $resolvedConditions,
            'unresolved_conditions' => $unresolved,
            'normative_source' => $profile['regulatory_basis'] ?? [],
        ];
    }

    private function booleanCondition(array $conditions, string $key): ?bool
    {
        if (! array_key_exists($key, $conditions)) {
            return null;
        }
        if (! is_bool($conditions[$key])) {
            throw new \InvalidArgumentException("Condition [{$key}] must be boolean.");
        }

        return $conditions[$key];
    }

    private function requiredRelations(array $profile, mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        if (! is_array($raw)) {
            throw new \InvalidArgumentException('required_relations must be an array.');
        }
        $defined = [];
        foreach (($profile['relations'] ?? []) as $relation) {
            if (is_array($relation) && is_string($relation['key'] ?? null)) {
                $defined[(string) $relation['key']] = true;
            }
        }
        $result = [];
        foreach ($raw as $key) {
            if (! is_string($key) || ! isset($defined[$key])) {
                throw new \InvalidArgumentException('required_relations contains an unknown profile relation.');
            }
            $result[] = $key;
        }

        return array_values(array_unique($result));
    }
}
