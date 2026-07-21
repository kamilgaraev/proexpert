<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter;

final class ArbiterReviewContextFactory
{
    /** @return array<string, mixed> */
    public function make(array $draft, ?ArbiterOperationContext $operation = null): array
    {
        $scopes = [];
        $scopeKeys = [];
        $packageKeys = [];
        $evidenceRefs = [];

        foreach (array_values((array) ($draft['completeness']['scopes'] ?? [])) as $scope) {
            if (! is_array($scope) || ! $this->isReference($scope['key'] ?? null)) {
                continue;
            }
            $key = (string) $scope['key'];
            $scopeKeys[] = $key;
            $packageKeys[] = $key;
            $references = $this->references($scope['evidence_refs'] ?? []);
            $evidenceRefs = [...$evidenceRefs, ...$references];
            $scopes[] = [
                'key' => $key,
                'state' => in_array($scope['state'] ?? null, ['covered', 'excluded', 'unresolved'], true)
                    ? $scope['state']
                    : 'unresolved',
                'required_items' => $this->references($scope['required_items'] ?? []),
                'covered_items' => $this->references($scope['covered_items'] ?? []),
                'missing_items' => $this->references($scope['missing_items'] ?? []),
                'evidence_refs' => $references,
            ];
        }
        $packages = [];
        foreach ((array) ($draft['local_estimates'] ?? []) as $estimate) {
            if (! is_array($estimate) || ! $this->isReference($estimate['key'] ?? null)) {
                continue;
            }
            $key = (string) $estimate['key'];
            $packageKeys[] = $key;
            $workKeys = [];
            foreach ((array) ($estimate['sections'] ?? []) as $section) {
                foreach (is_array($section) ? (array) ($section['work_items'] ?? []) : [] as $workItem) {
                    if (! is_array($workItem)) {
                        continue;
                    }
                    $metadata = is_array($workItem['metadata'] ?? null) ? $workItem['metadata'] : [];
                    foreach (['composition_work_key', 'material_scenario_work_key', 'quantity_key'] as $workKey) {
                        if ($this->isReference($metadata[$workKey] ?? null)) {
                            $workKeys[] = $metadata[$workKey];
                        }
                    }
                    $quantityEvidence = is_array($workItem['quantity_evidence'] ?? null) ? $workItem['quantity_evidence'] : [];
                    $evidenceRefs = [...$evidenceRefs, ...$this->references($quantityEvidence['evidence_ids'] ?? [])];
                }
            }
            $packages[] = ['key' => $key, 'work_keys' => array_values(array_unique($workKeys))];
        }
        $payload = [
            'source_input_version' => $this->isReference($draft['source_input_version'] ?? null)
                ? $draft['source_input_version']
                : null,
            'completeness_status' => in_array($draft['completeness']['status'] ?? null, [
                'full_confirmed_scope', 'confirmed_scope_only', 'review_required',
            ], true) ? $draft['completeness']['status'] : 'review_required',
            'scopes' => $scopes,
            'packages' => $packages,
            'budget_scope' => is_array($draft['budget_scope'] ?? null) ? $draft['budget_scope'] : [],
            'scope_keys' => array_values(array_unique($scopeKeys)),
            'package_keys' => array_values(array_unique($packageKeys)),
            'evidence_refs' => array_values(array_unique($evidenceRefs)),
        ];

        return [
            ...$payload,
            'input_hash' => 'sha256:'.hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            'schema_version' => 'completeness-arbiter:v1',
            ...($operation instanceof ArbiterOperationContext ? ['operation' => $operation] : []),
        ];
    }

    /** @return list<string> */
    private function references(mixed $values): array
    {
        $result = [];
        foreach ((array) $values as $value) {
            if (is_int($value) && $value > 0) {
                $result[] = (string) $value;

                continue;
            }
            if ($this->isReference($value)) {
                $result[] = (string) $value;
            }
        }

        return array_values(array_unique($result));
    }

    private function isReference(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9:._-]{1,120}$/', $value) === 1;
    }
}
